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

    public function __construct(private readonly int $timeoutSegundos = 20)
    {
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
     * @param array<string,string> $cabecalhos
     */
    private function executar(string $url, string $metodo, ?string $corpo, array $cabecalhos): string
    {
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
            throw new RuntimeException("Resposta HTTP {$codigo} ao pedir {$url}: {$body}");
        }

        return $body;
    }
}
