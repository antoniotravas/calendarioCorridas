<?php

declare(strict_types=1);

/**
 * Recolhe provas de estrada listadas pela Associação de Atletismo do Porto.
 *
 * A listagem (~500 registos, 8 por página) não está ordenada por data — mistura
 * provas passadas e futuras por página, aparentemente pela ordem de inserção.
 * Por isso: percorremos um número limitado de páginas e descartamos qualquer
 * prova cuja data já passou, em vez de paginar até ao fim.
 */
final class AAAPortoScraper implements ScraperInterface
{
    private const BASE_URL = 'https://www.aaporto.com';
    private const LISTAGEM_URL = self::BASE_URL . '/index.php/competicoes/calendario-competitivo/estrada';
    private const TAMANHO_PAGINA = 8;
    private const MAX_PAGINAS = 15;

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'AAAPorto';
    }

    public function scrape(): array
    {
        $hoje = date('Y-m-d');
        $corridas = [];

        for ($pagina = 0; $pagina < self::MAX_PAGINAS; $pagina++) {
            $start = $pagina * self::TAMANHO_PAGINA;
            $url = $start === 0
                ? self::LISTAGEM_URL
                : self::LISTAGEM_URL . '?limit=' . self::TAMANHO_PAGINA . '&start=' . $start;

            $html = $this->http->get($url);
            $extraidas = $this->extrairCorridas($html, $url);

            if ($extraidas === []) {
                break;
            }

            foreach ($extraidas as $corrida) {
                if ($corrida->dataProva === null || $corrida->dataProva >= $hoje) {
                    $corridas[] = $corrida;
                }
            }
        }

        return $corridas;
    }

    /**
     * @return Corrida[]
     */
    private function extrairCorridas(string $html, string $urlFonte): array
    {
        $xpath = $this->criarXPath($html);
        $cards = $xpath->query('//div[@id="eb-events"]/div[contains(@class,"eb-event")]');

        $corridas = [];

        foreach ($cards as $card) {
            $linkNode = $xpath->query('.//a[contains(@class,"eb-event-title-link")]', $card)->item(0);
            if ($linkNode === null) {
                continue;
            }
            $nome = trim($linkNode->textContent);
            $urlDetalhes = self::BASE_URL . trim($linkNode->getAttribute('href'));

            // O 2º parágrafo da descrição é sempre o local; a formatação interna
            // varia entre provas (<strong>, <span><b>, texto simples), por isso
            // usa-se o texto do parágrafo e não de uma tag específica.
            $localNode = $xpath->query(
                '(.//div[contains(@class,"eb-description-details")]/p)[2]',
                $card
            )->item(0);
            $local = $localNode !== null ? trim($localNode->textContent) : null;
            if ($local !== null) {
                $local = trim((string) preg_replace('/^local\s*[:-]?\s*/i', '', $local));
            }

            $dataTexto = trim($xpath->query('.//td[@class="eb-event-property-value"]', $card)->item(0)?->textContent ?? '');
            $dataProva = null;
            if (preg_match('/(\d{2})-(\d{2})-(\d{4})/', $dataTexto, $m)) {
                [, $dia, $mes, $ano] = $m;
                if (checkdate((int) $mes, (int) $dia, (int) $ano)) {
                    $dataProva = sprintf('%04d-%02d-%02d', (int) $ano, (int) $mes, (int) $dia);
                }
            }

            $verSiteNode = $xpath->query(
                './/div[contains(@class,"eb-description-details")]//a[contains(text(),"Ver site")]',
                $card
            )->item(0);
            $urlEvento = $verSiteNode !== null ? trim($verSiteNode->getAttribute('href')) : $urlDetalhes;

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $dataProva,
                local: $local,
                distancias: null,
                tipo: 'Estrada',
                regiao: 'Porto',
                urlEvento: $urlEvento !== '' ? $urlEvento : $urlDetalhes,
                fonte: $this->nome(),
                urlFonte: $urlFonte,
            );
        }

        return $corridas;
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
