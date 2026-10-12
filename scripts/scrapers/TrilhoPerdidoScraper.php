<?php

declare(strict_types=1);

/**
 * Recolhe as próximas provas da Trilho Perdido (organização de eventos e
 * cronometragem, sobretudo trails na região de Lisboa e Vale do Tejo).
 *
 * A página /eventos aceita filtros na query string: cat=5 (Trail Running) ou
 * 6 (Atletismo) e pag=N (16 eventos por página). Cada evento é um
 * div.evento com o link (/evento/...), o cartaz, a data em três linhas
 * ("18" / "out" / "2026"), o nome, a categoria e a localidade. As restantes
 * categorias (BTT, TT, Caminhadas) não interessam; caminhadas que apareçam
 * nestas duas são excluídas pelo nome.
 */
final class TrilhoPerdidoScraper implements ScraperInterface, FonteOrganizadora
{
    private const SITE = 'https://www.trilhoperdido.com';
    private const LISTA_URL = self::SITE . '/eventos?cat=';
    /** @var array<int,string> categoria do site => tipo de prova */
    private const CATEGORIAS = [5 => 'Trail', 6 => 'Atletismo'];
    private const MAX_PAGINAS = 10;

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'Trilho Perdido';
    }

    public function scrape(): array
    {
        $corridas = [];

        foreach (self::CATEGORIAS as $categoria => $tipo) {
            $totalPaginas = 1;

            for ($pagina = 1; $pagina <= min($totalPaginas, self::MAX_PAGINAS); $pagina++) {
                $url = self::LISTA_URL . $categoria . ($pagina > 1 ? "&pag={$pagina}" : '');
                $html = $this->http->get($url);

                if ($pagina === 1 && preg_match_all('/data-pagina="(\d+)"/', $html, $m)) {
                    $totalPaginas = max(array_map('intval', $m[1]));
                }

                $corridas = [...$corridas, ...$this->extrairCorridas($html, $url, $tipo)];
            }
        }

        return $corridas;
    }

    /**
     * @return Corrida[]
     */
    private function extrairCorridas(string $html, string $urlFonte, string $tipo): array
    {
        $xpath = $this->criarXPath($html);
        $corridas = [];

        foreach ($xpath->query('//div[contains(@class,"eventos")]//div[contains(concat(" ",@class," ")," evento ")]/a[starts-with(@href,"/evento/")]') as $cartao) {
            $nome = trim($xpath->query('.//div[@class="descricao-texto"]/div[@class="titulo"]', $cartao)->item(0)?->textContent ?? '');
            $local = trim($xpath->query('.//span[@class="localidade"]', $cartao)->item(0)?->textContent ?? '');

            if ($nome === '' || NomeProvaHelper::eOutraModalidade($nome)) {
                continue;
            }

            // Data em três divs: dia, mês abreviado ("out") e ano.
            $partesData = [];
            foreach ($xpath->query('.//div[@class="data"]/div', $cartao) as $parte) {
                $partesData[] = trim($parte->textContent);
            }
            $dataProva = count($partesData) === 3
                ? DateHelper::toIso((int) $partesData[0], $partesData[1], (int) $partesData[2])
                : null;

            $imagem = $xpath->query('./img', $cartao)->item(0)?->getAttribute('src') ?? '';

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $dataProva,
                local: $local !== '' ? $local : null,
                distancias: null,
                tipo: $tipo === 'Trail' ? 'Trail' : null,
                regiao: null,
                urlEvento: self::SITE . $cartao->getAttribute('href'),
                fonte: $this->nome(),
                urlFonte: $urlFonte,
                imagemUrl: str_starts_with($imagem, '/') ? self::SITE . $imagem : null,
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
