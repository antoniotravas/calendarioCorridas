<?php

declare(strict_types=1);

/**
 * Recolhe provas de estrada e de trail listadas em all4running.pt (uma
 * listagem por tipo, com a mesma estrutura).
 *
 * A listagem é paginada (cerca de 10 provas por página); a primeira página
 * é usada para descobrir quantas páginas existem no total.
 */
final class All4RunningScraper implements ScraperInterface
{
    private const LISTAGENS = [
        'https://www.all4running.pt/provas/provas-de-estrada/',
        'https://www.all4running.pt/provas/provas-de-trail/',
    ];

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'All4Running';
    }

    public function scrape(): array
    {
        $corridas = [];

        foreach (self::LISTAGENS as $listagem) {
            $primeiraPagina = $this->http->get($listagem);
            $totalPaginas = $this->descobrirTotalPaginas($primeiraPagina);

            $corridas = [...$corridas, ...$this->extrairCorridas($primeiraPagina, $listagem)];

            for ($pagina = 2; $pagina <= $totalPaginas; $pagina++) {
                $url = $listagem . "page/{$pagina}/";
                $html = $this->http->get($url);
                $corridas = [...$corridas, ...$this->extrairCorridas($html, $url)];
            }
        }

        return $corridas;
    }

    private function descobrirTotalPaginas(string $html): int
    {
        if (preg_match_all('#/provas-de-(?:estrada|trail)/page/(\d+)/#', $html, $matches)) {
            return max(array_map('intval', $matches[1]));
        }

        return 1;
    }

    /**
     * @return Corrida[]
     */
    private function extrairCorridas(string $html, string $urlFonte): array
    {
        $xpath = $this->criarXPath($html);
        $cards = $xpath->query('//div[@class="brxe-fieixw brxe-div"]');

        $corridas = [];

        foreach ($cards as $card) {
            $linkNode = $xpath->query('.//h3[contains(@class,"brxe-heading")]/a', $card)->item(0);

            if ($linkNode === null) {
                continue;
            }

            $nome = trim($linkNode->textContent);
            $urlEvento = trim($linkNode->getAttribute('href'));

            $dataTexto = trim($xpath->query('(.//div[@class="content"]/p)[1]', $card)->item(0)?->textContent ?? '');
            $local = trim($xpath->query('(.//div[@class="content"]/p)[2]', $card)->item(0)?->textContent ?? '');

            $tipoNode = $xpath->query(
                './/div[contains(@class,"brxe-text-basic") and not(contains(@class,"distancia-badge"))]',
                $card
            )->item(0);
            $tipo = $tipoNode !== null ? trim($tipoNode->textContent) : null;

            $distanciaNodes = $xpath->query('.//div[contains(@class,"distancia-badge")]', $card);
            $distancias = [];
            foreach ($distanciaNodes as $node) {
                $distancias[] = trim($node->textContent);
            }

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $dataTexto !== '' ? DateHelper::parseTextoLivre($dataTexto) : null,
                local: $local !== '' ? $local : null,
                distancias: $distancias !== [] ? implode(', ', $distancias) : null,
                tipo: $tipo,
                regiao: null,
                urlEvento: $urlEvento !== '' ? $urlEvento : null,
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
