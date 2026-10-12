<?php

declare(strict_types=1);

/**
 * Recolhe provas de estrada e de trail listadas em all4running.pt (uma
 * listagem por tipo, com a mesma estrutura).
 *
 * A listagem é paginada (cerca de 10 provas por página); a primeira página
 * é usada para descobrir quantas páginas existem no total.
 *
 * A listagem só tem o link para a página da prova na própria All4Running. O
 * link oficial (botão "Visitar o site oficial") só está nessa página: no fim
 * de cada execução, o run_scraper.php lê as páginas ainda não lidas
 * (PREFIXO_PAGINA_PROVA + extrairLinkOficial) e o link da prova passa a ser o
 * oficial. A página da All4Running fica como link da fonte ("Encontrada em").
 */
final class All4RunningScraper implements ScraperInterface
{
    /** Início do endereço das páginas de prova (ex. https://www.all4running.pt/prova/volta-a-silgueiros/). */
    public const PREFIXO_PAGINA_PROVA = 'https://www.all4running.pt/prova/';

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
        $xpath = self::criarXPath($html);
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
                urlFonte: $urlEvento !== '' ? $urlEvento : $urlFonte,
            );
        }

        return $corridas;
    }

    /**
     * Link oficial da prova na página da All4Running: o site oficial; senão, o
     * link do botão de inscrição (o do topo, "Inscrições abertas", ou o
     * "Fazer inscrição"/"Inscrever" mais abaixo); senão, o site da entidade
     * organizadora.
     */
    public static function extrairLinkOficial(string $html): ?string
    {
        $xpath = self::criarXPath($html);

        foreach ([
            '//a[@title="Site oficial da prova"]/@href',
            '//a[starts-with(@title,"Fazer Inscri") or contains(@onclick,"click_inscricao")]/@href',
            '//a[starts-with(normalize-space(.),"Fazer inscri") or starts-with(normalize-space(.),"Inscrever")]/@href',
            '//a[contains(@class,"org-contact-link")]/@href',
        ] as $consulta) {
            foreach ($xpath->query($consulta) as $atributo) {
                $url = trim(html_entity_decode($atributo->value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (preg_match('#^https?://#i', $url) && !preg_match('#^https?://(www\.)?all4running\.pt#i', $url)
                    && strlen($url) <= 500
                ) {
                    return $url;
                }
            }
        }

        return null;
    }

    private static function criarXPath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
