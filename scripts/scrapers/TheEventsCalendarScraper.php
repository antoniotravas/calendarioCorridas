<?php

declare(strict_types=1);

/**
 * Recolhe provas de estrada de sites WordPress com o plugin "The Events
 * Calendar", através da API REST pública do plugin
 * (/wp-json/tribe/events/v1/events). Serve várias associações distritais com
 * o mesmo plugin — cada instância recebe o nome da fonte e o endereço do site.
 *
 * Uma prova conta como estrada se alguma das suas categorias tiver "estrada"
 * (ex. "Estrada Local", "Estrada Nacional"); se não tiver categorias, decide-se
 * pelo nome (NomeProvaHelper::pareceEstrada).
 */
final class TheEventsCalendarScraper implements ScraperInterface
{
    private const POR_PAGINA = 50;
    private const MAX_PAGINAS = 10;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $nomeFonte,
        private readonly string $siteUrl,
        private readonly string $regiao,
    ) {
    }

    public function nome(): string
    {
        return $this->nomeFonte;
    }

    public function scrape(): array
    {
        $url = rtrim($this->siteUrl, '/') . '/wp-json/tribe/events/v1/events?' . http_build_query([
            'per_page' => self::POR_PAGINA,
            'start_date' => date('Y-m-d'),
        ]);
        $corridas = [];

        for ($pagina = 0; $pagina < self::MAX_PAGINAS && $url !== null; $pagina++) {
            // Alguns sites devolvem o JSON com BOM UTF-8 no início.
            $resposta = preg_replace('/^\xEF\xBB\xBF/', '', $this->http->get($url));
            $dados = json_decode($resposta, true, 512, JSON_THROW_ON_ERROR);

            if (!isset($dados['events']) || !is_array($dados['events'])) {
                throw new RuntimeException("Estrutura inesperada em {$url} (sem lista de eventos)");
            }

            foreach ($dados['events'] as $evento) {
                $corrida = $this->converter($evento);
                if ($corrida !== null) {
                    $corridas[] = $corrida;
                }
            }

            $url = $dados['next_rest_url'] ?? null;
        }

        return $corridas;
    }

    private function converter(array $evento): ?Corrida
    {
        $nome = trim(html_entity_decode((string) ($evento['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($nome === '') {
            return null;
        }

        $categorias = array_map(
            fn (array $c) => mb_strtolower((string) ($c['name'] ?? '')),
            is_array($evento['categories'] ?? null) ? $evento['categories'] : []
        );
        $eEstrada = $categorias === []
            ? NomeProvaHelper::pareceEstrada($nome)
            : array_filter($categorias, fn (string $c) => str_contains($c, 'estrada')) !== [];
        if (!$eEstrada) {
            return null;
        }

        $dataProva = null;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) ($evento['start_date'] ?? ''), $m)) {
            $dataProva = $m[1];
        }

        $local = null;
        $venue = $evento['venue'] ?? null;
        if (is_array($venue)) {
            $partes = array_filter([
                trim(html_entity_decode((string) ($venue['venue'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                trim((string) ($venue['city'] ?? '')),
            ]);
            $local = $partes !== [] ? implode(', ', array_unique($partes)) : null;
        }

        // Algumas versões do plugin devolvem o site já como <a href="...">.
        $site = trim((string) ($evento['website'] ?? ''));
        if (preg_match('/href="([^"]+)"/', $site, $m)) {
            $site = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $pagina = trim((string) ($evento['url'] ?? ''));

        return new Corrida(
            nome: $nome,
            dataProva: $dataProva,
            local: $local,
            distancias: null,
            tipo: 'Estrada',
            regiao: $this->regiao,
            urlEvento: $site !== '' ? $site : ($pagina !== '' ? $pagina : null),
            fonte: $this->nomeFonte,
            urlFonte: $pagina !== '' ? $pagina : $this->siteUrl,
        );
    }
}
