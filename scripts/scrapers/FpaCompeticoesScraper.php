<?php

declare(strict_types=1);

/**
 * Recolhe provas de estrada, trail, corta-mato e pista da plataforma FPA Competições (Federação
 * Portuguesa de Atletismo), beta.fpacompeticoes.pt. As associações distritais registam aqui as
 * suas competições (campo "owner": AAB, ADAL, AAVR...), por isso é também a principal fonte de
 * provas de pista das associações sem calendário próprio legível.
 *
 * Ao contrário das outras fontes, esta é uma app Remix renderizada no servidor:
 * o HTML de cada página inclui os dados já prontos em "window.__remixContext",
 * pelo que não é preciso DOMDocument/DOMXPath, só extrair e desserializar esse
 * JSON. A listagem em /calendar não mostra a distância de cada prova, por isso
 * fazemos um pedido extra por prova a /competition/{id}, que redireciona para
 * /inscriptions/{id} (inscrições abertas) ou para /competition/{id}/schedule
 * (fechadas). As duas trazem o regulamento, o cartaz e o horário (hora, nome e
 * escalões de cada prova), de onde se tiram a hora de partida, o programa e a
 * distância real.
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
            // Pista: "Pista Ar Livre", "Pista Coberta"... "Outro" mistura convívios, formação, etc.
            $tipo = (string) ($item['tipo'] ?? '');
            $ePista = str_starts_with($tipo, 'Pista');
            if (!$ePista && !in_array($tipo, ['Estrada', 'Trail', 'Corta-Mato'], true)) {
                continue;
            }

            $id = (int) ($item['id'] ?? 0);
            $dataProva = null;
            if (isset($item['data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $item['data'])) {
                $dataProva = $item['data'];
            }

            $detalhes = $id > 0 ? $this->detalhesProva($id) : self::SEM_DETALHES;

            $corridas[] = new Corrida(
                nome: (string) ($item['nome'] ?? ''),
                dataProva: $dataProva,
                local: !empty($item['local']) ? (string) $item['local'] : null,
                // Nas provas de pista, os metros dos escalões ("60m", "1000m") não são uma distância de corrida.
                distancias: $ePista ? null : $detalhes['distancias'],
                tipo: $tipo,
                regiao: null,
                urlEvento: $detalhes['urlEvento'],
                fonte: $this->nome(),
                urlFonte: self::CALENDAR_URL,
                imagemUrl: $detalhes['imagem'],
                horaInicio: $detalhes['hora'],
                programa: $detalhes['programa'],
            );
        }

        return $corridas;
    }

    private const SEM_DETALHES = ['distancias' => null, 'urlEvento' => null, 'imagem' => null, 'hora' => null, 'programa' => null];

    /**
     * @return array{distancias: ?string, urlEvento: ?string, imagem: ?string, hora: ?string,
     *               programa: array<int,array{hora: ?string, prova: string, escaloes: ?string}>|null}
     */
    private function detalhesProva(int $id): array
    {
        try {
            $html = $this->http->get(self::BASE_URL . "/competition/{$id}");
            $dados = $this->extrairRemixContext($html);
        } catch (RuntimeException) {
            return self::SEM_DETALHES;
        }

        $loaderData = $dados['state']['loaderData'] ?? [];
        // Inscrições abertas: routes/inscriptions.$compId(._index); fechadas: routes/competition.$compId(.schedule).
        $compData = $loaderData['routes/inscriptions.$compId']['compData']
            ?? $loaderData['routes/competition.$compId']['compData']
            ?? [];
        $porDia = $loaderData['routes/inscriptions.$compId._index']['data']
            ?? $loaderData['routes/competition.$compId.schedule']['data']
            ?? [];

        $regulamento = $compData['regulamento'] ?? null;
        $urlEvento = !empty($regulamento) ? (string) $regulamento : self::BASE_URL . "/competition/{$id}";
        $imagem = !empty($compData['imagem']) && preg_match('#^https?://#', (string) $compData['imagem']) ? (string) $compData['imagem'] : null;

        $nomesEscaloes = [];
        $programa = [];
        if (is_array($porDia)) {
            foreach ($porDia as $escaloes) {
                if (!is_array($escaloes)) {
                    continue;
                }
                foreach ($escaloes as $escalao) {
                    if (!isset($escalao['nome'])) {
                        continue;
                    }
                    $nomesEscaloes[] = (string) $escalao['nome'];
                    $hora = preg_match('/^(\d{1,2}):(\d{2})/', (string) ($escalao['hora'] ?? ''), $h) ? sprintf('%02d:%s', $h[1], $h[2]) : null;
                    $grupos = trim((string) ($escalao['escaloes'] ?? ''));
                    $programa[] = [
                        'hora' => $hora,
                        'prova' => trim(preg_replace('/\s+/u', ' ', (string) $escalao['nome'])),
                        // "*" = todos os escalões.
                        'escaloes' => match ($grupos) { '' => null, '*' => 'Todos', default => str_replace(' a *', ' e seguintes', $grupos) },
                    ];
                }
                // Provas de vários dias: o programa fica pelo primeiro.
                if ($programa !== []) {
                    break;
                }
            }
        }
        $programa = array_values(array_unique($programa, SORT_REGULAR));
        usort($programa, fn (array $a, array $b) => strcmp((string) $a['hora'], (string) $b['hora']));
        $horas = array_filter(array_column($programa, 'hora'));

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

        return [
            'distancias' => $distancias,
            'urlEvento' => $urlEvento,
            'imagem' => $imagem,
            'hora' => $horas !== [] ? min($horas) : null,
            'programa' => $programa !== [] ? $programa : null,
        ];
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
