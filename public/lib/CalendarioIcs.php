<?php

declare(strict_types=1);

/**
 * Gera um calendário iCalendar (.ics, RFC 5545) a partir de uma lista de
 * provas, para importar ou subscrever no Google Calendar, iPhone, Outlook...
 *
 * Cada prova é um evento de dia inteiro (as fontes não indicam a hora de
 * partida de forma fiável). Provas sem data ficam de fora. O UID de cada
 * evento é fixo por prova, para que ao atualizar a subscrição as apps
 * atualizem o evento em vez de o duplicar.
 */
final class CalendarioIcs
{
    /**
     * @param array<int,array<string,mixed>> $corridas linhas de Database::listarCorridasFavoritas()
     */
    public static function gerar(array $corridas, string $nomeCalendario): string
    {
        $agora = gmdate('Ymd\THis\Z');

        $linhas = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//CalendarioCorridas//Meu calendario//PT',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::escapar($nomeCalendario),
            'X-WR-TIMEZONE:Europe/Lisbon',
            // Sugestão às apps de quanto em quanto tempo voltar a pedir o calendário.
            'REFRESH-INTERVAL;VALUE=DURATION:PT12H',
            'X-PUBLISHED-TTL:PT12H',
        ];

        foreach ($corridas as $c) {
            if (empty($c['data_prova'])) {
                continue;
            }

            $inicio = str_replace('-', '', (string) $c['data_prova']);
            $fim = date('Ymd', strtotime($c['data_prova'] . ' +1 day'));

            $linhas[] = 'BEGIN:VEVENT';
            $linhas[] = 'UID:corrida-' . (int) $c['id'] . '@calendariocorridas';
            $linhas[] = 'DTSTAMP:' . $agora;
            $linhas[] = 'DTSTART;VALUE=DATE:' . $inicio;
            $linhas[] = 'DTEND;VALUE=DATE:' . $fim;
            $linhas[] = 'SUMMARY:' . self::escapar((string) $c['nome']);
            if (!empty($c['local'])) {
                $linhas[] = 'LOCATION:' . self::escapar((string) $c['local']);
            }
            if (!empty($c['url_evento'])) {
                $linhas[] = 'URL:' . $c['url_evento'];
            }
            $linhas[] = 'DESCRIPTION:' . self::escapar(self::descricao($c));
            $linhas[] = 'TRANSP:TRANSPARENT';
            $linhas[] = 'END:VEVENT';
        }

        $linhas[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'dobrar'], $linhas)) . "\r\n";
    }

    /**
     * @param array<string,mixed> $c
     */
    private static function descricao(array $c): string
    {
        $partes = [];

        if (!empty($c['distancias'])) {
            $partes[] = 'Distâncias: ' . $c['distancias'];
        }
        if (!empty($c['url_evento'])) {
            $partes[] = 'Site da prova: ' . $c['url_evento'];
        }

        $fontes = array_map(fn (array $f) => $f['fonte'], $c['fontes'] ?? []);
        if ($fontes !== []) {
            $partes[] = (count($fontes) > 1 ? 'Fontes: ' : 'Fonte: ') . implode(', ', $fontes);
        }

        $partes[] = 'Confirma sempre a data e o horário no site da prova.';

        return implode("\n", $partes);
    }

    /**
     * Escapa texto segundo o RFC 5545 (barra, ponto e vírgula, vírgula, mudança de linha).
     */
    private static function escapar(string $texto): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $texto
        );
    }

    /**
     * Linhas com mais de 75 octetos são partidas, continuando com um espaço
     * no início da linha seguinte — sem partir caracteres UTF-8 a meio.
     */
    private static function dobrar(string $linha): string
    {
        if (strlen($linha) <= 75) {
            return $linha;
        }

        $partes = [];
        $atual = '';
        $limite = 75;

        foreach (mb_str_split($linha) as $caracter) {
            if (strlen($atual) + strlen($caracter) > $limite) {
                $partes[] = $atual;
                $atual = '';
                $limite = 74; // as linhas de continuação começam com um espaço
            }
            $atual .= $caracter;
        }
        $partes[] = $atual;

        return implode("\r\n ", $partes);
    }
}
