<?php

declare(strict_types=1);

/**
 * Recolhe provas de corrida e de pista do calendário da Associação Distrital
 * de Atletismo de Coimbra (ADAC).
 *
 * A página do calendário (WordPress, plugin "calendario-lite" com FullCalendar)
 * traz todos os eventos — várias épocas — num objeto JSON em
 * "var calendarioEvents = {"events": [...]}": título, início ("2026-11-08T09:00"),
 * link para a página do evento no site da ADAC e, em "extendedProps", o local.
 * Não há tipo de prova: decide-se pelo nome (NomeProvaHelper::pareceCorrida /
 * parecePista), o que deixa de fora formação, convívios, etc.
 */
final class AdacCoimbraScraper implements ScraperInterface
{
    private const CALENDARIO_URL = 'https://adac.pt/calendar/';

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'ADA Coimbra';
    }

    public function scrape(): array
    {
        $html = $this->http->get(self::CALENDARIO_URL);

        if (!preg_match('/var\s+calendarioEvents\s*=\s*(\{.*?\});\s*(?:\/\/|<\/script>)/s', $html, $m)) {
            throw new RuntimeException('Estrutura inesperada em ' . self::CALENDARIO_URL . ' (calendarioEvents não encontrado)');
        }

        $dados = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        if (!isset($dados['events']) || !is_array($dados['events'])) {
            throw new RuntimeException('Estrutura inesperada em ' . self::CALENDARIO_URL . ' (sem lista de eventos)');
        }

        $hoje = date('Y-m-d');
        $corridas = [];

        foreach ($dados['events'] as $evento) {
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) ($evento['start'] ?? ''), $d) || $d[1] < $hoje) {
                continue;
            }

            $nome = trim(html_entity_decode((string) ($evento['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($nome === '' || !(NomeProvaHelper::pareceCorrida($nome) || NomeProvaHelper::parecePista($nome))) {
                continue;
            }

            $local = trim(html_entity_decode((string) ($evento['extendedProps']['location'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $url = trim((string) ($evento['url'] ?? ''));

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $d[1],
                local: $local !== '' ? $local : null,
                distancias: null,
                tipo: null,
                regiao: 'Coimbra',
                urlEvento: $url !== '' ? $url : null,
                fonte: $this->nome(),
                urlFonte: self::CALENDARIO_URL,
            );
        }

        return $corridas;
    }
}
