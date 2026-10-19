<?php

declare(strict_types=1);

/**
 * Lê o valor da inscrição numa página de prova ou num regulamento (texto já
 * extraído, uma linha por bloco — PremiosHelper::textoHtml / PdfTextoHelper).
 *
 * Dois formatos:
 *  - blocos de preços, como as plataformas os mostram: uma linha "Preços",
 *    "Inscrição", "Quanto?" ou "Período de inscrição" seguida de linhas com
 *    valores, cada um com o nome da prova na mesma linha ("Trail Longo – 20€")
 *    ou numa linha anterior ("TRAIL 25KM" / "€ 13,00"; "corrida" / "10.00€");
 *  - frases: "A inscrição tem um custo de 12 €", "Inscrição Geral: 10.00 €",
 *    "A Corrida de 10 km tem o valor de 7 € (até 11/10) e 10 € (até 18/10)".
 *
 * Ficam de fora os preços da caminhada, das refeições, dos extras (t-shirt,
 * roupeiro, meias...), das provas jovens, penalizações e taxas, e os prémios.
 * Devolve o valor mais baixo e o mais alto (as inscrições sobem por fases) ou
 * 0 quando a inscrição é gratuita.
 */
final class InscricaoHelper
{
    private const ACENTOS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c', 'º' => 'o', 'ª' => 'a',
    ];

    /** "12€", "10.00€", "12,50 €", "€ 13,00", "15 euros". */
    private const PADRAO_VALOR = '/(?<![\d.,])(\d{1,3}(?:[.,]\d{1,2})?)\s?(?:€|euros?\b|eur\b)|(?:€|\beur)\s?(\d{1,3}(?:[.,]\d{1,2})?)(?![\d.,]*\d)/u';

    /** Linha que abre um bloco de preços. */
    private const PADRAO_INICIO_BLOCO = '/^(?:precos?|valor(?:es)?(?: d[ae]s?)? inscric\w*|inscric(?:ao|oes)|quanto\??|taxas? de inscric\w*|custos?(?: d[ae]s?)? inscric\w*|periodo de inscric\w*|tabela de precos|fases? de precos?|precos? (?:e|d[ae]s?) inscric\w*)\b[^\d]{0,40}$/';

    /** Frase que fala do preço da inscrição. */
    private const PADRAO_FRASE_PRECO = '/inscric|\bprecos?\b|\bcusto\b|\bcusta\b|taxa de participac|valor de participac/';

    /** Nomes de provas de corrida (para "a Corrida de 10 km tem o valor de 7 €"). */
    private const PADRAO_CORRIDA = '/corrida|trail|\bkm\b|\d\s?k\b|maratona|milha|legua|\bprova\b/';

    /** Preços que não são a inscrição numa corrida. */
    private const PADRAO_EXCLUIR = '/caminhad|marcha|\bwalk|passeio|almoc|jantar|refeic|lanche|acompanhant|hoodie|camisol|t-?shirt|sweat|meias|'
        . 'opcional|roupeiro|bengaleiro|guarda.?roupa|penaliz|administrativ|devoluc|reembols|seguro|transporte|autocarro|'
        . 'estacion|premi|classificad|\blugar|vencedor|trofeu|bolsa|kids|crianc|infant|benjamin|jovens|juvenis|desconto|'
        . 'multa|\bchip|aluguer|troca|alterac|transfer|licenca|massag|\bcaes?\b|canicross|caominhada|acresc|adicional|'
        . 'suplement|portes|envio|donativo|parque|merchandis|fotografia|medalha personaliz|pacote|pretendo|hotel|estadia|'
        . 'alojament|passagem|jove[mn]|juvenil|infantil|iniciad|menores|mini corrida|adaptad|no dia d[ao] (?:evento|prova)|'
        . 'entrega de dorsal|levantamento|custo associado|no local da partida|lanterna|\bextra\b/';

    /** Exclusões que são outra prova (e não outra despesa): numa frase com corrida e caminhada, só estas se separam. */
    private const PADRAO_OUTRA_PROVA = '/caminhad|marcha|\bwalk|passeio|kids|crianc|infant|benjamin|jove[mn]|juvenil|iniciad|menores|mini corrida|adaptad|caominhada|canicross/';

    /** Rótulos que são a resposta a uma opção ("sim: 3,00 €"), não uma prova. */
    private const PADRAO_ROTULO_OPCAO = '/^(?:sim|nao)\W*$/';

    /** Linha que só diz o período, a fase ou o tipo de inscrição (não muda a prova a que os valores se referem). */
    private const PADRAO_QUALIFICADOR = '/^(?:\*|\(|ate\b|de \d|desde|a partir|\d{1,2}[\s\/.-]|\d{1,2} de|fase|\d\.?a fase|periodo|preco|valor|inscric|geral|socio|'
        . 'federad|nao federad|individual|equipa|esc\.|senior|veterano|masculino|feminino|early|last|normal|ultim|primeir|segund|terceir|'
        . 'dias?\b|janeiro|fevereiro|marco|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)/';

    /** "A inscrição é gratuita", "Inscrições gratuitas", "Inscrição Gratuita: Gratuito". */
    private const PADRAO_GRATUITA = '/(?:inscric(?:ao|oes)|participacao)\b[^.;\n]{0,30}?(?:gratuit|gratis)/';
    private const PADRAO_GRATUITA_NAO = '/jovens|kids|crianc|infant|benjamin|caminhad|equipa|imagem|incondicional|newsletter|por cada|socios?\b|acompanhant|'
        . 'premi|classificad|benefici|sorteio|oferta|\b\d+ inscric/';

    /** Faixas do filtro "Inscrição" da página pública (pelo valor mais baixo). */
    public const FAIXAS = [
        'gratuita' => ['label' => 'Gratuita', 'min' => 0, 'max' => 0],
        'ate-10' => ['label' => 'Até 10 €', 'min' => 0.01, 'max' => 10],
        '10-20' => ['label' => '10 a 20 €', 'min' => 10.01, 'max' => 20],
        '20-30' => ['label' => '20 a 30 €', 'min' => 20.01, 'max' => 30],
        '30+' => ['label' => 'Mais de 30 €', 'min' => 30.01, 'max' => 9999],
    ];

    /** @return array{0:float,1:float}|null */
    public static function intervaloPorChave(?string $chave): ?array
    {
        return $chave !== null && isset(self::FAIXAS[$chave])
            ? [(float) self::FAIXAS[$chave]['min'], (float) self::FAIXAS[$chave]['max']]
            : null;
    }

    /** Fora destes limites não é o valor de uma inscrição. */
    private const MINIMO = 2.0;
    private const MAXIMO = 250.0;

    /**
     * @return array{min: float, max: float, excerto: string}|null null quando
     *         o texto não diz quanto custa a inscrição
     */
    public static function extrair(string $texto): ?array
    {
        $linhas = array_values(array_filter(
            array_map(fn (string $l) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), explode("\n", $texto)),
            'strlen'
        ));

        $valores = [];
        $excerto = null;
        $blocoAte = -1;      // índice da última linha do bloco de preços aberto
        $contexto = '';      // última linha que diz a que prova se referem os valores
        $semValor = 0;       // linhas seguidas sem valores dentro do bloco

        foreach ($linhas as $i => $linha) {
            $normal = self::normalizar($linha);
            $naLinha = self::valores($linha);

            if (mb_strlen($linha) <= 70 && preg_match(self::PADRAO_INICIO_BLOCO, $normal)) {
                // A linha antes do bloco costuma ser o nome da prova ("Caminhada de 6 km" / "Período de inscrição").
                $anterior = $i > 0 ? self::normalizar($linhas[$i - 1]) : '';
                $contexto = mb_strlen($anterior) <= 60 && self::valores($anterior) === [] && !preg_match(self::PADRAO_QUALIFICADOR, $anterior)
                    ? $anterior : ($i <= $blocoAte ? $contexto : '');
                $blocoAte = $i + 40;
                $semValor = 0;
                continue;
            }

            if ($i <= $blocoAte && mb_strlen($linha) <= 120) {
                if ($naLinha === []) {
                    if (++$semValor > 8) {
                        $blocoAte = -1;
                    } elseif (!preg_match(self::PADRAO_QUALIFICADOR, $normal)) {
                        $contexto = $normal;
                    }
                    continue;
                }
                $semValor = 0;
                // O nome da prova vem antes do valor, na mesma linha, ou numa linha anterior.
                $antes = self::normalizar(preg_split(self::PADRAO_VALOR, $linha, 2)[0]);
                $rotulo = preg_match('/[a-z]{3}/', $antes) && !preg_match(self::PADRAO_QUALIFICADOR, $antes) ? $antes : $contexto;
                // "16.00€* Juvenil F., Junior F., Senior F." (Lap2Go): a lista de escalões não exclui o valor.
                $semEscaloes = preg_replace('/\*.*$/', '', $normal);
                if (!preg_match(self::PADRAO_EXCLUIR, $semEscaloes) && !preg_match(self::PADRAO_EXCLUIR, $rotulo)
                    && !preg_match(self::PADRAO_ROTULO_OPCAO, $rotulo)
                ) {
                    $valores = [...$valores, ...$naLinha];
                    $excerto ??= ($rotulo !== '' && $rotulo === $contexto ? $contexto . ': ' : '') . $linha;
                }
                continue;
            }

            if ($naLinha !== []) {
                foreach (self::frases($linha) as $frase) {
                    $doFrase = self::valoresDaFrase($frase);
                    if ($doFrase !== []) {
                        $valores = [...$valores, ...$doFrase];
                        $excerto ??= $frase;
                    }
                }
            }
        }

        $valores = self::semValoresIsolados(array_values(array_filter($valores, fn (float $v) => $v >= self::MINIMO && $v <= self::MAXIMO)));
        if ($valores !== []) {
            return ['min' => min($valores), 'max' => max($valores), 'excerto' => self::cortar((string) $excerto)];
        }

        foreach ($linhas as $linha) {
            foreach (self::frases($linha) as $frase) {
                $normal = self::normalizar($frase);
                if (preg_match(self::PADRAO_GRATUITA, $normal) && !preg_match(self::PADRAO_GRATUITA_NAO, $normal)) {
                    return ['min' => 0.0, 'max' => 0.0, 'excerto' => self::cortar($frase)];
                }
            }
        }

        return null;
    }

    /**
     * Tira os valores que quase sempre são outra coisa que escapou aos
     * filtros: abaixo de 5 € quando há valores de 10 € ou mais (taxa de não
     * federado, provas jovens) e acima do triplo do valor do meio (pacotes
     * com hotel). Distâncias diferentes (10 km a 18 €, maratona a 65 €) ficam.
     *
     * @param float[] $valores
     * @return float[]
     */
    private static function semValoresIsolados(array $valores): array
    {
        if ($valores === []) {
            return [];
        }
        if (max($valores) >= 10) {
            $valores = array_values(array_filter($valores, fn (float $v) => $v >= 5));
        }
        if (count($valores) >= 3) {
            $ordenados = $valores;
            sort($ordenados);
            $meio = $ordenados[intdiv(count($ordenados), 2)];
            $valores = array_values(array_filter($valores, fn (float $v) => $v <= $meio * 3));
        }

        return $valores;
    }

    /** "10 €", "10 € a 25 €" ou "Gratuita". */
    public static function formatar(float $min, float $max): string
    {
        if ($max <= 0) {
            return 'Gratuita';
        }
        $euros = fn (float $v) => number_format($v, floor($v) == $v ? 0 : 2, ',', '.') . ' €';

        return $min === $max ? $euros($min) : $euros($min) . ' a ' . $euros($max);
    }

    /**
     * Valores de uma frase que fala da inscrição. Quando junta a corrida e a
     * caminhada ("10 € a corrida, 5 € a caminhada"), conta só as partes sem
     * caminhada.
     *
     * @return float[]
     */
    private static function valoresDaFrase(string $frase): array
    {
        // O que vem entre parênteses ou depois de "inclui" diz o que está incluído
        // ("12 € (seguro, dorsal, t-shirt)") e não é outro preço.
        $normal = self::normalizar(preg_replace('/\([^)]*\)|\b(?:inclui|incluindo|que inclui|da direito)\b.*$/iu', ' ', $frase));
        $eCorrida = (bool) preg_match(self::PADRAO_CORRIDA, $normal);
        if (!preg_match(self::PADRAO_FRASE_PRECO, $normal)
            && !($eCorrida && preg_match('/\bvalor\b/', $normal) && !preg_match('/respe(?:c)?tivamente/', $normal))
        ) {
            return [];
        }
        if (!preg_match(self::PADRAO_EXCLUIR, $normal)) {
            return self::valores($frase);
        }
        // Outra despesa (transporte, jantar, penalização...): a frase não é sobre a inscrição.
        if (!$eCorrida || preg_match(self::PADRAO_EXCLUIR, preg_replace(self::PADRAO_OUTRA_PROVA, ' ', $normal))) {
            return [];
        }
        // Corrida e caminhada na mesma frase: cada valor conta para a última prova nomeada.
        $valores = [];
        $eDaCorrida = null;
        foreach (preg_split('/[,;]|\be\b/u', $frase) as $parte) {
            $parteNormal = self::normalizar($parte);
            if (preg_match(self::PADRAO_EXCLUIR, $parteNormal)) {
                $eDaCorrida = false;
            } elseif (preg_match(self::PADRAO_CORRIDA, $parteNormal)) {
                $eDaCorrida = true;
            }
            if ($eDaCorrida) {
                $valores = [...$valores, ...self::valores($parte)];
            }
        }

        return $valores;
    }

    /** @return float[] */
    private static function valores(string $texto): array
    {
        if (!preg_match_all(self::PADRAO_VALOR, $texto, $m, PREG_SET_ORDER)) {
            return [];
        }

        return array_map(fn (array $v) => (float) str_replace(',', '.', $v[1] !== '' ? $v[1] : $v[2]), $m);
    }

    /** @return string[] */
    private static function frases(string $linha): array
    {
        return array_filter(array_map('trim', preg_split('/(?<=[.;!?])\s+(?=[A-ZÀ-Ú0-9«"(])/u', $linha)), 'strlen');
    }

    private static function normalizar(string $texto): string
    {
        return strtr(mb_strtolower($texto), self::ACENTOS);
    }

    private static function cortar(string $texto): string
    {
        return mb_strlen($texto) > 200 ? mb_substr($texto, 0, 197) . '…' : $texto;
    }
}
