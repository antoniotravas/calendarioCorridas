<?php

declare(strict_types=1);

/**
 * Recolhe provas de atletismo da plataforma de inscrições Stop and Go.
 *
 * A listagem /events aceita filtros na query string: status=S (próximos),
 * modality=1 (Atletismo) e country=178 (Portugal). Cada evento é um
 * <a href="/events/..."> com nome, modalidade, local ("Arouca, Portugal") e a
 * data por extenso ("17 de outubro 2026" ou "16 - 18 de outubro 2026"). Mesmo
 * em "Atletismo" aparecem caminhadas e ultras, excluídas pelo nome.
 */
final class StopAndGoScraper implements ScraperInterface
{
    private const LISTA_URL = 'https://stopandgo.net/events?status=S&modality=1&country=178';
    private const MAX_PAGINAS = 10;

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'Stop and Go';
    }

    public function scrape(): array
    {
        $corridas = [];
        $totalPaginas = 1;

        for ($pagina = 1; $pagina <= min($totalPaginas, self::MAX_PAGINAS); $pagina++) {
            $url = self::LISTA_URL . ($pagina > 1 ? "&page={$pagina}" : '');
            $html = $this->http->get($url);

            if ($pagina === 1 && preg_match_all('/[?&;]page=(\d+)/', $html, $m)) {
                $totalPaginas = max(array_map('intval', $m[1]));
            }

            $corridas = [...$corridas, ...$this->extrairCorridas($html, $url)];
        }

        return $corridas;
    }

    /**
     * @return Corrida[]
     */
    private function extrairCorridas(string $html, string $urlFonte): array
    {
        $xpath = $this->criarXPath($html);
        $corridas = [];

        foreach ($xpath->query('//a[contains(@href,"/events/") and contains(@class,"group")]') as $cartao) {
            $nome = trim($xpath->query('.//div[contains(@class,"line-clamp-2")]', $cartao)->item(0)?->textContent ?? '');
            $local = trim($xpath->query('.//span[@class="inline-block"]', $cartao)->item(0)?->textContent ?? '');
            // Data na etiqueta visível em telemóvel: "17 de outubro 2026" ou "16 - 18 de outubro 2026".
            $dataTexto = trim($xpath->query('.//div[contains(@class,"md:hidden")]', $cartao)->item(0)?->textContent ?? '');

            if ($nome === '' || NomeProvaHelper::eOutraModalidade($nome)) {
                continue;
            }

            $dataProva = DateHelper::parseTextoLivre(preg_replace('/^(\d{1,2})\s*-\s*\d{1,2}\b/', '$1', $dataTexto));
            $local = preg_replace('/,\s*Portugal$/u', '', $local);

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $dataProva,
                local: $local !== '' ? $local : null,
                distancias: null,
                tipo: null,
                regiao: null,
                urlEvento: $cartao->getAttribute('href'),
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
