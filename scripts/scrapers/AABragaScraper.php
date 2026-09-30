<?php

declare(strict_types=1);

/**
 * Recolhe provas de estrada do calendário da Associação de Atletismo de Braga.
 *
 * A página do calendário inclui todos os eventos (várias épocas, passadas e
 * futuras) num array JSON em "window.AABragaCalendarioEventos", usado pelo
 * calendário visual (FullCalendar). Cada evento tem título, datas e, em
 * "extendedProps", local, organização, âmbito e tipo ("Estrada", "Pista",
 * "Corta-mato"...). O tipo vem por vezes vazio — nesse caso decide-se pelo nome.
 */
final class AABragaScraper implements ScraperInterface
{
    private const CALENDARIO_URL = 'https://aabraga.pt/pt/calendario';

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'AA Braga';
    }

    public function scrape(): array
    {
        $html = $this->http->get(self::CALENDARIO_URL);

        if (!preg_match('/window\.AABragaCalendarioEventos\s*=\s*(\[.*?\]);\s*<\/script>/s', $html, $m)) {
            throw new RuntimeException('Estrutura inesperada em ' . self::CALENDARIO_URL . ' (AABragaCalendarioEventos não encontrado)');
        }

        $eventos = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        $hoje = date('Y-m-d');
        $corridas = [];

        foreach ($eventos as $evento) {
            $data = (string) ($evento['start'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $data < $hoje) {
                continue;
            }

            $nome = trim(html_entity_decode((string) ($evento['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $extra = $evento['extendedProps'] ?? [];
            $tipo = mb_strtolower(trim((string) ($extra['type'] ?? '')));

            // Provas no estrangeiro (ex. campeonatos europeus) aparecem com âmbito "Internacional".
            if (($extra['scope'] ?? '') === 'Internacional') {
                continue;
            }

            $eEstrada = $tipo === 'estrada' || ($tipo === '' && NomeProvaHelper::pareceEstrada($nome));
            if ($nome === '' || !$eEstrada) {
                continue;
            }

            $local = trim((string) ($extra['place'] ?? ''));

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $data,
                local: $local !== '' ? $local : null,
                distancias: null,
                tipo: 'Estrada',
                regiao: 'Braga',
                urlEvento: null,
                fonte: $this->nome(),
                urlFonte: self::CALENDARIO_URL,
            );
        }

        return $corridas;
    }
}
