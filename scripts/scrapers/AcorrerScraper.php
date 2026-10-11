<?php

declare(strict_types=1);

/**
 * Recolhe provas de estrada da plataforma de inscrições Acorrer.
 *
 * A página /eventos é uma app Laravel Livewire: o HTML inicial vem sem
 * eventos e o browser pede-os logo a seguir, chamando o método "load" do
 * componente "provas-grid". O scraper faz o mesmo:
 *   1. GET /eventos (na mesma sessão de cookies) → token CSRF, endereço de
 *      atualização do Livewire e "snapshot" (estado) do componente;
 *   2. POST desse snapshot com a chamada "load" → JSON com o HTML da grelha.
 *
 * Cada cartão tem link, nome, data ("17 Out", sem ano) e local ("Chaves, Vila
 * Real"). O site não diz a modalidade e mistura trail, caminhadas, peddy
 * papers..., por isso só ficam as provas cujo nome parece de estrada.
 */
final class AcorrerScraper implements ScraperInterface
{
    private const LISTA_URL = 'https://acorrer.pt/eventos';

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'Acorrer';
    }

    public function scrape(): array
    {
        $html = $this->obterGrelha();
        $xpath = $this->criarXPath($html);
        $cartoes = $xpath->query('//a[contains(@href,"/eventos/") and contains(@class,"group")]');

        $corridas = [];

        foreach ($cartoes as $cartao) {
            $nome = $this->limpar($xpath->query('.//div[@title]', $cartao)->item(0)?->getAttribute('title'));
            $dataTexto = $this->limpar($xpath->query('.//div[@class="text-sm"]/span[1]', $cartao)->item(0)?->textContent);
            $local = $this->limpar($xpath->query('.//div[@class="text-sm"]/span[2]', $cartao)->item(0)?->textContent);

            if ($nome === '' || !NomeProvaHelper::pareceEstrada($nome)) {
                continue;
            }

            $dataProva = null;
            if (preg_match('/^(\d{1,2})\s+([a-zA-Zç]+)/u', $dataTexto, $m)) {
                $dataProva = DateHelper::proximaOcorrencia((int) $m[1], $m[2]);
            }

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $dataProva,
                local: $local !== '' ? $local : null,
                distancias: null,
                tipo: null,
                regiao: null,
                urlEvento: $cartao->getAttribute('href'),
                fonte: $this->nome(),
                urlFonte: self::LISTA_URL,
            );
        }

        return $corridas;
    }

    /**
     * Devolve o HTML da grelha de eventos, já carregada pelo Livewire.
     */
    private function obterGrelha(): string
    {
        $pagina = $this->http->get(self::LISTA_URL);

        if (!preg_match('/window\.livewireScriptConfig\s*=\s*(\{.*?\});/', $pagina, $m)) {
            throw new RuntimeException('Estrutura inesperada em ' . self::LISTA_URL . ' (configuração Livewire não encontrada)');
        }
        $config = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);

        if (!preg_match('/wire:snapshot="([^"]*provas-grid[^"]*)"/', $pagina, $m)) {
            throw new RuntimeException('Estrutura inesperada em ' . self::LISTA_URL . ' (componente provas-grid não encontrado)');
        }
        $snapshot = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $resposta = $this->http->postJson(
            (string) $config['uri'],
            [
                '_token' => $config['csrf'],
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => new stdClass(),
                    'calls' => [['path' => '', 'method' => 'load', 'params' => []]],
                ]],
            ],
            ['X-Livewire: 1', 'X-CSRF-TOKEN: ' . $config['csrf']]
        );

        $dados = json_decode($resposta, true, 512, JSON_THROW_ON_ERROR);
        $html = $dados['components'][0]['effects']['html'] ?? null;

        if (!is_string($html)) {
            throw new RuntimeException('Resposta Livewire inesperada de ' . self::LISTA_URL . ' (sem HTML)');
        }

        return $html;
    }

    /**
     * Junta espaços repetidos e tira os espaços antes de vírgulas ("Alcácer do Sal , Setúbal").
     */
    private function limpar(?string $texto): string
    {
        return trim(preg_replace(['/\s+/u', '/\s+,/u'], [' ', ','], $texto ?? ''));
    }

    private function criarXPath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
