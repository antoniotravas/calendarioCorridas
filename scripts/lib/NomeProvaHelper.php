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
        // "Campeonato Regional Corta-Mato Longo" (Lourinhã) não é o "1º Corta-mato da AASM" (Açores).
        'corta', 'mato', 'cross',
        // Pista: "Torneio de Abertura", "Campeonato Regional de Pista Coberta"...
        'torneio', 'meeting', 'campeonato', 'campeonatos', 'pista', 'coberta', 'curta', 'ar', 'livre',
        'abertura', 'encerramento', 'jovem', 'jovens', 'absolutos', 'masters', 'veteranos', 'sub',
    ];

    /**
     * Palavras que indicam uma distância/formato: se as duas provas as tiverem
     * e forem diferentes (ex. "Maratona do Porto" vs "Meia Maratona do Porto"),
     * não são a mesma prova.
     */
    private const FORMATOS = ['meia', 'maratona', 'milha', 'legua', 'silvestre', 'trail', 'caminhada', 'marcha'];

    /**
     * Decide se dois nomes (da mesma data) se referem à mesma prova. Com os
     * locais, nomes só com palavras genéricas ("Torneio de Abertura", comum a
     * várias associações no mesmo fim de semana) só são a mesma prova se os
     * locais tiverem alguma palavra em comum.
     */
    public static function mesmaProva(string $nomeA, string $nomeB, ?string $localA = null, ?string $localB = null): bool
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
            if ($distintivasA !== $distintivasB || $a !== $b) {
                return false;
            }
            $palavrasLocalA = self::palavras((string) $localA);
            $palavrasLocalB = self::palavras((string) $localB);

            return $palavrasLocalA === [] || $palavrasLocalB === []
                || array_intersect($palavrasLocalA, $palavrasLocalB) !== [];
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
        // Palavras coladas: "36ªMEIA-MARATONA" (ordinal) e "TechRun" (maiúscula a meio).
        $nome = preg_replace(['/(\d\s*[ªº°])(?=\p{L})/u', '/(?<=\p{Ll})(?=\p{Lu})/u'], ['$1 ', ' '], $nome);
        $texto = strtr(mb_strtolower($nome), self::ACENTOS);
        $texto = preg_replace('/\bgp\b/', 'grande premio', $texto);
        $texto = preg_replace('/\bs\.\s*/', 'sao ', $texto);
        $texto = preg_replace(['/\bsto\.?\s+/', '/\bsta\.?\s+/'], ['santo ', 'santa '], $texto);
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

    /** Trail, ultra trail e provas de montanha. */
    private const PADRAO_TRAIL = '/trail|trilho|\bultra\b|skyrun|km vertical|montanha/';

    /** Corta-mato (cross country). */
    private const PADRAO_CORTA_MATO = '/corta\s*.?\s*mato|\bcross\b|\bxc\b/';

    /**
     * Para fontes que não indicam o tipo de prova: diz se o nome sugere uma
     * corrida — de estrada, trail ou corta-mato (e não pista, caminhada,
     * formação...).
     */
    public static function pareceCorrida(string $nome): bool
    {
        if (self::eOutraModalidade($nome)) {
            return false;
        }

        $texto = strtr(mb_strtolower($nome), self::ACENTOS);

        return preg_match(self::PADRAO_TRAIL, $texto) || preg_match(self::PADRAO_CORTA_MATO, $texto)
            || preg_match(
                '/corrida|\bcorre\b|maratona|milha|legua|silvestre|grande premio|\bgpa?\b|\bestrada\b|\brun\b|\broad\b|\d+\s?(k|km|kms)\b/',
                $texto
            );
    }

    /**
     * Para fontes que já dizem que a prova é de corrida/atletismo, mas misturam
     * pista, caminhadas, provas de obstáculos...: diz se o nome mostra que não é
     * uma corrida (estrada, trail ou corta-mato).
     */
    public static function eOutraModalidade(string $nome): bool
    {
        $texto = strtr(mb_strtolower($nome), self::ACENTOS);

        if (preg_match(
            '/marcha|conviv|kids|benjamins|torneio|lancamentos|pista|'
            . 'triatlo|pentatlo|duatlo|jornadas|formacao|estagio|exames|meeting|concentracao|juiz|juizes|'
            . 'hybrid|hibrid|obstac|\bbtt\b|peddy/',
            $texto
        )) {
            return true;
        }

        // "Caminhada X" sozinha não é corrida; "Corrida e Caminhada X" ou "Trail e Caminhada X" é.
        return str_contains($texto, 'caminhada')
            && !preg_match('/corrida|\brun\b|maratona|silvestre|trail|trilho|corta\s*.?\s*mato/', $texto);
    }

    /**
     * Tipo de prova, para o filtro e as etiquetas do site: "trail" (inclui
     * ultra trail e montanha), "corta-mato", "pista" (ar livre, coberta e
     * pista curta) ou "estrada". Decide pelo nome e pelo tipo que a fonte
     * indicar (ex. "Caminhada, Trail", "Corta-Mato", "Pista Ar Livre"); o
     * nome só faz uma prova de pista quando a fonte não diz que é de estrada
     * (o "Torneio de Rua de Santo Tirso" é estrada).
     */
    public static function categoria(string $nome, ?string $tipo): string
    {
        $texto = strtr(mb_strtolower($nome . ' ' . $tipo), self::ACENTOS);
        $textoTipo = strtr(mb_strtolower((string) $tipo), self::ACENTOS);

        return match (true) {
            (bool) preg_match(self::PADRAO_CORTA_MATO, $texto) => 'corta-mato',
            (bool) preg_match(self::PADRAO_TRAIL, $texto) => 'trail',
            (bool) preg_match('/pista|pavilhao/', $textoTipo) => 'pista',
            str_contains($textoTipo, 'estrada') => 'estrada',
            self::parecePista($nome) => 'pista',
            default => 'estrada',
        };
    }

    /**
     * Competições de pista (ar livre, coberta, pista curta): torneios,
     * meetings, campeonatos de pista, distâncias em metros ("10 000 m"),
     * saltos, lançamentos, provas combinadas...
     */
    private const PADRAO_PISTA = '/\bpista\b|pavilhao|meeting|torneio|lancamentos?\b|\bsaltos?\b|provas combinadas|'
        . 'atleta completo|mega ?sprint|km jovem|barreiras|heptatlo|decatlo|ar livre|'
        . '\b\d{1,2}[ .]?\d{3}\s?m\b|\b\d{2,3}\s?m\b/';

    /**
     * Para fontes que não indicam o tipo de prova (associações que publicam
     * todas as competições): diz se o nome sugere uma competição de pista.
     * Ficam de fora as de crianças (convívios, Kids Athletics), formação,
     * estágios e concentrações, e o que pelo nome é estrada, trail ou
     * corta-mato ("Torneio de Rua", "Torneio de Corta-mato").
     */
    public static function parecePista(string $nome): bool
    {
        $texto = strtr(mb_strtolower($nome), self::ACENTOS);

        return preg_match(self::PADRAO_PISTA, $texto)
            && !preg_match('/conviv|kids|benjamins|formacao|juiz|juizes|estagio|exames|jornadas|concentracao|cerimonia|gala\b/', $texto)
            && !preg_match('/\brua\b|estrada|corrida/', $texto)
            && !preg_match(self::PADRAO_TRAIL, $texto)
            && !preg_match(self::PADRAO_CORTA_MATO, $texto);
    }
}
