<?php

declare(strict_types=1);

/**
 * Wrapper simples sobre cURL para pedidos HTTP (GET/POST) a páginas HTML e APIs JSON.
 */
final class HttpClient
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 '
        . 'CalendarioCorridasBot/1.0';

    /** Código das exceções lançadas quando o site recusa por excesso de pedidos (HTTP 429). */
    public const DEMASIADOS_PEDIDOS = 429;

    /** Ficheiro temporário onde o cURL guarda os cookies entre pedidos (só com $guardarCookies). */
    private ?string $ficheiroCookies = null;

    /** @var array<string,float> site => momento (microtime) do último pedido */
    private array $ultimoPedidoPorSite = [];

    /**
     * @param bool $guardarCookies     mantém os cookies entre pedidos (sessão), para sites
     *                                 que só respondem a pedidos feitos na mesma sessão.
     * @param int  $intervaloPorSiteMs espera mínima entre dois pedidos ao mesmo site, para
     *                                 sites que recusam pedidos seguidos (ex. All4Running).
     */
    public function __construct(
        private readonly int $timeoutSegundos = 20,
        bool $guardarCookies = false,
        private readonly int $intervaloPorSiteMs = 0,
    ) {
        if ($guardarCookies) {
            $this->ficheiroCookies = tempnam(sys_get_temp_dir(), 'cookies') ?: null;
        }
    }

    public function __destruct()
    {
        if ($this->ficheiroCookies !== null && is_file($this->ficheiroCookies)) {
            unlink($this->ficheiroCookies);
        }
    }

    /**
     * @throws RuntimeException se o pedido falhar ou o servidor não devolver 200.
     */
    public function get(string $url, array $cabecalhos = []): string
    {
        return $this->executar($url, 'GET', null, $cabecalhos);
    }

    /**
     * POST com corpo "application/x-www-form-urlencoded" (usado nas trocas OAuth).
     *
     * @param array<string,string> $campos
     */
    public function post(string $url, array $campos, array $cabecalhos = []): string
    {
        return $this->executar($url, 'POST', http_build_query($campos), $cabecalhos);
    }

    /**
     * POST com corpo JSON (usado para falar com componentes Livewire).
     */
    public function postJson(string $url, array $dados, array $cabecalhos = []): string
    {
        $corpo = json_encode($dados, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->executar($url, 'POST', $corpo, array_merge(['Content-Type: application/json'], $cabecalhos));
    }

    /**
     * @param array<string,string> $cabecalhos
     */
    private function executar(string $url, string $metodo, ?string $corpo, array $cabecalhos): string
    {
        $this->esperarVez($url);
        $ch = curl_init($url);

        $opcoes = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->timeoutSegundos,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => array_merge(['Accept-Language: pt-PT,pt;q=0.9'], $cabecalhos),
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        if ($this->ficheiroCookies !== null) {
            $opcoes[CURLOPT_COOKIEFILE] = $this->ficheiroCookies;
            $opcoes[CURLOPT_COOKIEJAR] = $this->ficheiroCookies;
        }

        if ($metodo === 'POST') {
            $opcoes[CURLOPT_POST] = true;
            $opcoes[CURLOPT_POSTFIELDS] = $corpo;
        }

        curl_setopt_array($ch, $opcoes);

        $body = curl_exec($ch);
        $erro = curl_error($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Falha ao pedir {$url}: {$erro}");
        }

        if ($codigo < 200 || $codigo >= 300) {
            throw new RuntimeException(
                "Resposta HTTP {$codigo} ao pedir {$url}: " . mb_substr($body, 0, 300),
                $codigo === self::DEMASIADOS_PEDIDOS ? self::DEMASIADOS_PEDIDOS : 0
            );
        }

        return $body;
    }

    /**
     * Com $intervaloPorSiteMs, espera o necessário para não pedir duas páginas
     * do mesmo site mais depressa do que esse intervalo.
     */
    private function esperarVez(string $url): void
    {
        if ($this->intervaloPorSiteMs <= 0) {
            return;
        }

        $site = (string) parse_url($url, PHP_URL_HOST);
        $ultimo = $this->ultimoPedidoPorSite[$site] ?? null;
        if ($ultimo !== null) {
            $faltaMs = $this->intervaloPorSiteMs - (microtime(true) - $ultimo) * 1000;
            if ($faltaMs > 0) {
                usleep((int) ($faltaMs * 1000));
            }
        }
        $this->ultimoPedidoPorSite[$site] = microtime(true);
    }
}
