<?php

declare(strict_types=1);

/**
 * Recolhe provas de estrada da plataforma FPA Competições (Federação Portuguesa
 * de Atletismo), beta.fpacompeticoes.pt.
 *
 * Ao contrário das outras fontes, esta é uma app Remix renderizada no servidor:
 * o HTML de cada página inclui os dados já prontos em "window.__remixContext",
 * pelo que não é preciso DOMDocument/DOMXPath, só extrair e desserializar esse
 * JSON. A listagem em /calendar não mostra a distância de cada prova, por isso
 * fazemos um pedido extra por prova a /competition/{id} (que redireciona para
 * /inscriptions/{id}) para apurar a distância real a partir dos escalões de
 * inscrição.
 */
final class FpaCompeticoesScraper implements ScraperInterface
{
    private const BASE_URL = 'https://beta.fpacompeticoes.pt';
    private const CALENDAR_URL = self::BASE_URL . '/calendar';

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'FPA Competições';
    }

    public function scrape(): array
    {
        $html = $this->http->get(self::CALENDAR_URL);
        $dados = $this->extrairRemixContext($html);

        $resultados = $dados['state']['loaderData']['routes/calendar._index']['results'] ?? null;
        if (!is_array($resultados)) {
            throw new RuntimeException('Estrutura inesperada em ' . self::CALENDAR_URL . ' (routes/calendar._index.results não encontrado)');
        }

        $corridas = [];

        foreach ($resultados as $item) {
            if (($item['tipo'] ?? null) !== 'Estrada') {
                continue;
            }

            $id = (int) ($item['id'] ?? 0);
            $dataProva = null;
            if (isset($item['data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $item['data'])) {
                $dataProva = $item['data'];
            }

            $detalhes = $id > 0
                ? $this->detalhesProva($id)
                : ['distancias' => null, 'urlEvento' => null];

            $corridas[] = new Corrida(
                nome: (string) ($item['nome'] ?? ''),
                dataProva: $dataProva,
                local: !empty($item['local']) ? (string) $item['local'] : null,
                distancias: $detalhes['distancias'],
                tipo: 'Estrada',
                regiao: null,
                urlEvento: $detalhes['urlEvento'],
                fonte: $this->nome(),
                urlFonte: self::CALENDAR_URL,
            );
        }

        return $corridas;
    }

    /**
     * @return array{distancias: ?string, urlEvento: ?string}
     */
    private function detalhesProva(int $id): array
    {
        try {
            $html = $this->http->get(self::BASE_URL . "/competition/{$id}");
            $dados = $this->extrairRemixContext($html);
        } catch (RuntimeException) {
            return ['distancias' => null, 'urlEvento' => null];
        }

        $loaderData = $dados['state']['loaderData'] ?? [];

        $regulamento = $loaderData['routes/inscriptions.$compId']['compData']['regulamento'] ?? null;
        $urlEvento = !empty($regulamento) ? (string) $regulamento : self::BASE_URL . "/competition/{$id}";

        $porDia = $loaderData['routes/inscriptions.$compId._index']['data'] ?? [];
        $nomesEscaloes = [];
        if (is_array($porDia)) {
            foreach ($porDia as $escaloes) {
                if (!is_array($escaloes)) {
                    continue;
                }
                foreach ($escaloes as $escalao) {
                    if (isset($escalao['nome'])) {
                        $nomesEscaloes[] = (string) $escalao['nome'];
                    }
                }
            }
        }

        $distancias = null;
        if ($nomesEscaloes !== []) {
            $valoresKm = DistanciaHelper::extrairKm(implode(', ', $nomesEscaloes));
            if ($valoresKm !== []) {
                $textos = array_map(
                    static fn (float $km): string => rtrim(rtrim(number_format($km, 1, '.', ''), '0'), '.') . 'km',
                    $valoresKm
                );
                $distancias = implode(', ', $textos);
            }
        }

        return ['distancias' => $distancias, 'urlEvento' => $urlEvento];
    }

    /**
     * @return array<string,mixed>
     */
    private function extrairRemixContext(string $html): array
    {
        if (!preg_match('/window\.__remixContext\s*=\s*(\{.*?\});/s', $html, $m)) {
            throw new RuntimeException('Não foi possível encontrar window.__remixContext na página.');
        }

        try {
            $dados = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('JSON inválido em window.__remixContext: ' . $e->getMessage());
        }

        if (!is_array($dados)) {
            throw new RuntimeException('window.__remixContext não é um objeto JSON válido.');
        }

        return $dados;
    }
}
