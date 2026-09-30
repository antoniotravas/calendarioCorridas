<?php

declare(strict_types=1);

/**
 * Heurísticas sobre o nome de uma prova: reconhecer a mesma prova publicada com
 * nomes ligeiramente diferentes em fontes diferentes, e distinguir provas de
 * estrada de outras (trail, pista, corta-mato...) quando a fonte não diz o tipo.
 */
final class NomeProvaHelper
{
    private const ACENTOS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'ª' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'º' => 'o', '°' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ];

    /** Palavras sem significado para distinguir provas. */
    private const IGNORADAS = [
        'de', 'da', 'do', 'das', 'dos', 'e', 'a', 'o', 'em', 'na', 'no', 'nas', 'nos', 'the', 'of', 'by',
        'edicao', 'ed',
    ];

    /**
     * Palavras que descrevem o tipo de prova e não qual é a prova — "São
     * Silvestre de Vizela" e "São Silvestre de Amares" partilham "sao silvestre"
     * mas são provas diferentes, por isso só as restantes palavras contam.
     */
    private const GENERICAS = [
        'corrida', 'corridas', 'meia', 'maratona', 'milha', 'legua', 'dupla', 'sao', 'silvestre',
        'grande', 'premio', 'atletismo', 'trail', 'km', 'run', 'running', 'cidade', 'vila',
        'internacional', 'nacional', 'regional', 'distrital', 'prova', 'provas', 'estrada',
        'caminhada', 'marcha', 'solidaria', 'solidario', 'noturna', 'night', 'race', 'popular',
    ];

    /**
     * Palavras que indicam uma distância/formato: se as duas provas as tiverem
     * e forem diferentes (ex. "Maratona do Porto" vs "Meia Maratona do Porto"),
     * não são a mesma prova.
     */
    private const FORMATOS = ['meia', 'maratona', 'milha', 'legua', 'silvestre', 'trail', 'caminhada', 'marcha'];

    /**
     * Decide se dois nomes (da mesma data) se referem à mesma prova.
     */
    public static function mesmaProva(string $nomeA, string $nomeB): bool
    {
        $a = self::palavras($nomeA);
        $b = self::palavras($nomeB);

        if ($a === [] || $b === []) {
            return false;
        }

        $formatoA = array_values(array_intersect($a, self::FORMATOS));
        $formatoB = array_values(array_intersect($b, self::FORMATOS));
        sort($formatoA);
        sort($formatoB);
        if ($formatoA !== [] && $formatoB !== [] && $formatoA !== $formatoB) {
            return false;
        }

        $distintivasA = array_values(array_diff($a, self::GENERICAS));
        $distintivasB = array_values(array_diff($b, self::GENERICAS));

        // Nomes só com palavras genéricas ("Meia Maratona"): só iguais se forem iguais.
        if ($distintivasA === [] || $distintivasB === []) {
            return $distintivasA === $distintivasB && $a === $b;
        }

        $comuns = count(array_intersect($distintivasA, $distintivasB));

        return $comuns / max(count($distintivasA), count($distintivasB)) >= 0.5;
    }

    /**
     * Palavras normalizadas e sem repetições do nome: minúsculas, sem acentos,
     * sem números/ordinais/anos ("36ª", "2026") nem distâncias ("10K").
     *
     * @return string[]
     */
    public static function palavras(string $nome): array
    {
        $texto = strtr(mb_strtolower($nome), self::ACENTOS);
        $texto = preg_replace('/\bgp\b/', 'grande premio', $texto);
        $texto = preg_replace('/\bs\.\s*/', 'sao ', $texto);
        $texto = preg_replace('/[^a-z0-9]+/', ' ', $texto);

        $palavras = [];
        foreach (explode(' ', trim($texto)) as $palavra) {
            if ($palavra === ''
                || in_array($palavra, self::IGNORADAS, true)
                || preg_match('/^\d+(a|o|k|km|m)?$/', $palavra)
            ) {
                continue;
            }
            $palavras[$palavra] = true;
        }

        $lista = array_keys($palavras);
        sort($lista);

        return $lista;
    }

    /**
     * Para fontes que não indicam o tipo de prova: diz se o nome sugere uma
     * corrida de estrada (e não trail, pista, corta-mato, formação...).
     */
    public static function pareceEstrada(string $nome): bool
    {
        $texto = strtr(mb_strtolower($nome), self::ACENTOS);

        if (preg_match(
            '/trail|trilho|marcha|corta.?mato|\bcross\b|conviv|kids|benjamins|torneio|lancamentos|pista|'
            . 'triatlo|pentatlo|jornadas|formacao|estagio|exames|meeting|concentracao|juiz|juizes/',
            $texto
        )) {
            return false;
        }

        return (bool) preg_match(
            '/corrida|maratona|milha|legua|silvestre|grande premio|\bgp\b|\bestrada\b|\brun\b|\d+\s?km?\b/',
            $texto
        );
    }
}
