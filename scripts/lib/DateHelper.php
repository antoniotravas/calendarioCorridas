<?php

declare(strict_types=1);

/**
 * Conversão de datas escritas em português para o formato ISO (Y-m-d).
 */
final class DateHelper
{
    /** @var array<string,int> nomes completos e abreviaturas de meses em PT-PT */
    private const MESES = [
        'janeiro' => 1, 'jan' => 1,
        'fevereiro' => 2, 'fev' => 2,
        'março' => 3, 'marco' => 3, 'mar' => 3,
        'abril' => 4, 'abr' => 4,
        'maio' => 5, 'mai' => 5,
        'junho' => 6, 'jun' => 6,
        'julho' => 7, 'jul' => 7,
        'agosto' => 8, 'ago' => 8,
        'setembro' => 9, 'set' => 9,
        'outubro' => 10, 'out' => 10,
        'novembro' => 11, 'nov' => 11,
        'dezembro' => 12, 'dez' => 12,
    ];

    /**
     * Constrói uma data ISO a partir de dia, mês (em português) e ano.
     */
    public static function toIso(int $dia, string $mesPt, int $ano): ?string
    {
        $chave = mb_strtolower(trim($mesPt));
        $mes = self::MESES[$chave] ?? null;

        if ($mes === null || $dia < 1 || $dia > 31) {
            return null;
        }

        if (!checkdate($mes, $dia, $ano)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
    }

    /**
     * Interpreta textos livres como "26 Setembro 2026" ou "4 de Setembro de 2026".
     */
    public static function parseTextoLivre(string $texto): ?string
    {
        $texto = trim($texto);

        if (!preg_match('/(\d{1,2})\s+(?:de\s+)?([a-zA-Zà-ú]+)\s+(?:de\s+)?(\d{4})/u', $texto, $m)) {
            return null;
        }

        return self::toIso((int) $m[1], $m[2], (int) $m[3]);
    }

    private const DIAS_SEMANA = [
        0 => 'domingo', 1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira',
        4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sábado',
    ];

    private const MESES_NOMES = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
        5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
        9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
    ];

    /**
     * Formata uma data "Y-m-d" por extenso em português, ex: "Sábado, 26 de setembro de 2026".
     */
    public static function formatarLongo(string $dataIso): string
    {
        $ts = strtotime($dataIso);
        if ($ts === false) {
            return $dataIso;
        }

        $semana = self::DIAS_SEMANA[(int) date('w', $ts)];
        $dia = (int) date('j', $ts);
        $mes = self::MESES_NOMES[(int) date('n', $ts)];
        $ano = date('Y', $ts);

        return ucfirst($semana) . ", {$dia} de {$mes} de {$ano}";
    }

    /**
     * Nome do mês em português a partir do número (1-12).
     */
    public static function nomeMes(int $mes): string
    {
        return self::MESES_NOMES[$mes] ?? (string) $mes;
    }

    /**
     * Agrupa uma lista de corridas (linhas da BD, com "data_prova") por mês/ano,
     * mantendo a ordem em que chegaram (assume-se já ordenadas por data).
     *
     * @param array<int,array<string,mixed>> $corridas
     * @return array<string,array{titulo:string,corridas:array<int,array<string,mixed>>}>
     */
    public static function agruparPorMes(array $corridas): array
    {
        $grupos = [];

        foreach ($corridas as $corrida) {
            if ($corrida['data_prova'] === null) {
                $chave = 'sem-data';
                $titulo = 'Data por confirmar';
            } else {
                $ts = strtotime($corrida['data_prova']);
                $chave = date('Y-m', $ts);
                $titulo = ucfirst(self::nomeMes((int) date('n', $ts))) . ' de ' . date('Y', $ts);
            }

            $grupos[$chave]['titulo'] = $titulo;
            $grupos[$chave]['corridas'][] = $corrida;
        }

        return $grupos;
    }
}
