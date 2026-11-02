<?php

declare(strict_types=1);

require_once __DIR__ . '/RobotsTxt.php';

/**
 * Wrapper simples sobre cURL para pedidos HTTP (GET/POST) a páginas HTML e APIs JSON.
 */
final class HttpClient
{
    /**
     * Identifica-nos com nome e página própria, sem fingir ser um browser.
     * Os sites podem ver quem os visita e bloquear-nos se assim o quiserem.
     */
    private const USER_AGENT = 'CalendarioCorridas/1.0 (+https://corridas.eu)';

    /** Nome do nosso robô no robots.txt (grupo "User-agent" que se procura primeiro). */
    private const AGENTE_ROBOTS = 'CalendarioCorridas';

    /** Espera mínima entre dois pedidos ao mesmo site, mesmo que o site não indique nada. */
    private const INTERVALO_MINIMO_MS = 1500;

    /** Código das exceções lançadas quando o site recusa por excesso de pedidos (HTTP 429). */
    public const DEMASIADOS_PEDIDOS = 429;

    /** Ficheiro temporário onde o cURL guarda os cookies entre pedidos (só com $guardarCookies). */
    private ?string $ficheiroCookies = null;

    /** @var array<string,float> site => momento (microtime) do último pedido */
    private array $ultimoPedidoPorSite = [];

    /** @var array<string,RobotsTxt> site => regras do robots.txt (guardadas durante a execução) */
    private array $robotsPorSite = [];

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
        $this->verificarRobots($url);
        $this->esperarVez($url);
        $ch = curl_init($url);

        $opcoes = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->timeoutSegundos,
            // Ligações penduradas: o CURLOPT_TIMEOUT sozinho chegou a deixar um
            // pedido parado mais de uma hora. Corta-se também a ligação lenta a
            // estabelecer e a transferência parada (< 1 KB/s durante 15 s).
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSegundos),
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 15,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => array_merge(['Accept-Language: pt-PT,pt;q=0.9'], $cabecalhos),
            CURLOPT_SSL_VERIFYPEER => true,
            // Descomprime gzip/deflate: a Runporto envia gzip mesmo sem o pedir.
            CURLOPT_ENCODING       => '',
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
     * Não pede um caminho que o robots.txt do site proíba.
     *
     * @throws RuntimeException se o caminho estiver proibido, ou se o robots.txt não
     *                          puder ser lido por motivo temporário (5xx, falha de rede).
     */
    private function verificarRobots(string $url): void
    {
        $site = $this->siteDe($url);
        if (!isset($this->robotsPorSite[$site])) {
            $this->robotsPorSite[$site] = $this->lerRobots($site);
        }

        $caminho = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $caminho .= '?' . $query;
        }

        if (!$this->robotsPorSite[$site]->permite($caminho)) {
            throw new RuntimeException("Proibido pelo robots.txt de {$site}: {$caminho}");
        }
    }

    /**
     * Lê o robots.txt de um site. Sem ficheiro (404/410) não há restrições.
     *
     * @throws RuntimeException se o robots.txt estiver indisponível por motivo temporário.
     */
    private function lerRobots(string $site): RobotsTxt
    {
        $ch = curl_init("{$site}/robots.txt");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $corpo = curl_exec($ch);
        $erro = curl_error($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($corpo === false) {
            throw new RuntimeException("Não foi possível ler {$site}/robots.txt: {$erro}");
        }
        if ($codigo === 200) {
            return new RobotsTxt($corpo, self::AGENTE_ROBOTS);
        }
        if ($codigo >= 400 && $codigo < 500 && $codigo !== 429) {
            return new RobotsTxt('', self::AGENTE_ROBOTS);
        }

        throw new RuntimeException("robots.txt de {$site} indisponível (HTTP {$codigo}); pedido não feito.");
    }

    /**
     * Espera o necessário para não pedir duas páginas do mesmo site mais depressa
     * do que o intervalo permitido: o maior entre o mínimo geral, o pedido pelo
     * scraper e o "Crawl-delay" do robots.txt.
     */
    private function esperarVez(string $url): void
    {
        $site = $this->siteDe($url);
        $intervaloMs = max(self::INTERVALO_MINIMO_MS, $this->intervaloPorSiteMs);
        $crawl = $this->robotsPorSite[$site]?->crawlDelay();
        if ($crawl !== null) {
            $intervaloMs = max($intervaloMs, (int) ($crawl * 1000));
        }

        $ultimo = $this->ultimoPedidoPorSite[$site] ?? null;
        if ($ultimo !== null) {
            $faltaMs = $intervaloMs - (microtime(true) - $ultimo) * 1000;
            if ($faltaMs > 0) {
                usleep((int) ($faltaMs * 1000));
            }
        }
        $this->ultimoPedidoPorSite[$site] = microtime(true);
    }

    /** "https://www.exemplo.pt" (esquema e domínio, sem caminho). */
    private function siteDe(string $url): string
    {
        $partes = parse_url($url);
        $esquema = $partes['scheme'] ?? 'https';
        $host = $partes['host'] ?? '';
        $porta = isset($partes['port']) ? ':' . $partes['port'] : '';

        return "{$esquema}://{$host}{$porta}";
    }
}
