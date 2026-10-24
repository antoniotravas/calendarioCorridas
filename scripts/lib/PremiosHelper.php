<?php

declare(strict_types=1);

/**
 * Prémios monetários: procura, no regulamento ou na página de uma prova, se
 * os atletas mais bem classificados recebem dinheiro.
 *
 * O texto de um regulamento tem muitos valores em euros que não são prémios
 * (inscrições, seguro, caução do chip, coimas...). Por isso só contam:
 *  - frases explícitas ("prémios monetários", "prémio pecuniário", "prize
 *    money"...) que não estejam negadas ("não serão atribuídos prémios
 *    monetários");
 *  - valores em euros numa tabela/frase de prémios: com uma palavra de
 *    prémio ou de classificação ("1.º lugar", "classificado", "geral") pouco
 *    antes e sem palavras de inscrição, seguro, vales de compras, etc.
 * Se o texto fala de prémios (troféus, medalhas...) mas não de dinheiro, a
 * resposta é "não"; se nem fala de prémios, fica por saber (null).
 */
final class PremiosHelper
{
    private const ACENTOS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c',
    ];

    /** Frases que dizem diretamente que há dinheiro para os premiados. */
    private const PADRAO_EXPLICITO = '/premios? (?:monetarios?|pecuniarios?|em dinheiro|em numerario)|prize ?money|'
        . 'premios? de (?:presenca|classificacao )?em dinheiro|bolsa de premios|'
        . 'premio monetario|premiacao monetaria|premios? em (?:valor|euros)/';

    /** Negação logo antes da frase explícita. */
    private const PADRAO_NEGACAO = '/\b(?:nao|sem|nenhum|nem)\b[^.;:]{0,60}$/';

    /** Valores em euros: "100€", "100 €", "100,00 euros", "€ 100", "EUR 100". */
    private const PADRAO_VALOR = '/(?:\d{1,3}(?:[ .]\d{3})*(?:,\d{1,2})?\s?(?:€|euros?\b|eur\b))|(?:(?:€|eur)\s?\d{1,3}(?:[ .]\d{3})*(?:,\d{1,2})?)/';

    /** Contexto de prémio/classificação antes de um valor. */
    private const PADRAO_CONTEXTO_PREMIO = '/premi|classificad|\blugar\b|\b[1-9]\.?\s?(?:º|ª|°)|\b[1-9]\.?\s?[oa] (?:lugar|classificad)|vencedor|recorde|trofeu|medalha/';

    /**
     * Tabelas de preços de inscrição ("1.ª fase 15 € 18 €", "Público geral /
     * Sócios", "Trail 32 km 18 €"): perto de um valor, mostram que é um preço.
     */
    private const PADRAO_TABELA_PRECOS = '/inscri|\bfases?\b|modalidade|socios|publico geral|vagas|federad|periodo|\bdias\b|\bate \d|\d\s?km\b|\d\s?k\b/';

    /** Prémios ainda por anunciar: não dá para dizer que não há dinheiro. */
    private const PADRAO_POR_ANUNCIAR = '/premi[^.]{0,80}(?:a anunciar|a divulgar|anunciad[oa]s?|divulgad[oa]s?) ?(?:oportunamente|posteriormente|mais tarde|brevemente|em breve)?/';

    /** Valores em euros que não são prémios. */
    private const PADRAO_NAO_PREMIO = '/inscri|taxa|preco|pagamento|pagar|custo|seguro|apolice|morte|invalidez|funeral|despesas|'
        . 'indemniza|caucao|chip|dorsal|acompanhante|almoco|jantar|refeic|t-?shirt|camisola|kit|transferencia|iban|'
        . 'multa|coima|reclama|reembolso|devolu|portes|estacionamento|desconto|promoc|vales?\b|voucher|cartao|'
        . 'em compras|cheque.?prenda|produtos|material|orcamento|apoio|patrocin|sorteio|donativo|solidari/';

    /**
     * O texto descreve os prémios dos mais bem classificados ("troféu aos 3
     * primeiros", "prémios para os 1.º classificados") — só então, sem
     * dinheiro, a resposta é "não". "Entrega de prémios" sozinho não chega.
     */
    private const PADRAO_DESCREVE_PREMIOS = '/(?:premio|trofeu|medalha|lembranca)[^.;]{0,120}?(?:primeir|classificad|vencedor|\b[1-9]\s?\.?\s?(?:º|ª|°|o\b|a\b))'
        . '|(?:primeir|classificad|vencedor)[^.;]{0,120}?(?:premio|trofeu|medalha)/';

    /**
     * @return array{0: bool, 1: string, 2: bool}|null [há prémios monetários?,
     *         excerto que o mostra, a resposta é explícita?], ou null se o texto
     *         não falar de prémios. Um "não" é explícito quando o texto diz que
     *         não há dinheiro ou que os prémios são troféus, medalhas...; "prémios
     *         aos 3 primeiros" sem dizer quais não é.
     */
    public static function analisar(string $texto): ?array
    {
        $original = trim((string) preg_replace('/\s+/u', ' ', $texto));
        if ($original === '') {
            return null;
        }
        // Minúsculas e sem acentos com o mesmo número de caracteres, para as
        // posições encontradas servirem também no texto original (excertos).
        $normal = strtr(mb_strtolower($original), self::ACENTOS);

        $negado = null;
        if (preg_match_all(self::PADRAO_EXPLICITO, $normal, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$frase, $pos]) {
                $antes = mb_substr(substr($normal, 0, $pos), -80);
                // "Taxa: 2% sobre o total dos prémios monetários", "Provas c/ Prémios Monetários - 2%
                // sobre total" (tabelas de taxas das associações).
                $depois = substr($normal, $pos + strlen($frase), 40);
                if (preg_match('/taxa|%|percentagem/', mb_substr($antes, -40)) || preg_match('/^\W{0,5}\d+(?:,\d+)?\s?%/u', $depois)) {
                    continue;
                }
                if (!preg_match(self::PADRAO_NEGACAO, $antes)) {
                    return [true, self::excerto($original, $normal, $pos), true];
                }
                $negado ??= $pos;
            }
        }

        if (preg_match_all(self::PADRAO_VALOR, $normal, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$valor, $pos]) {
                $antes = mb_substr(substr($normal, 0, $pos), -90);
                // Só o que está depois do último ponto final/linha: uma tabela de
                // prémios ("1.º 100 € 2.º 75 €") ou "o vencedor recebe 200 euros".
                $antes = (string) preg_replace('/^.*[.;!?](?=\s+[a-z])/s', '', $antes);
                $vizinhanca = mb_substr(substr($normal, 0, $pos), -150) . mb_substr(substr($normal, $pos), 0, 60);
                if (preg_match(self::PADRAO_CONTEXTO_PREMIO, $antes)
                    && !preg_match(self::PADRAO_NAO_PREMIO, $antes)
                    && !preg_match(self::PADRAO_TABELA_PRECOS, $vizinhanca)
                    && !preg_match(self::PADRAO_NAO_PREMIO, mb_substr(substr($normal, $pos), 0, 40))
                    && self::montante($valor) >= 10
                ) {
                    return [true, self::excerto($original, $normal, $pos), true];
                }
            }
        }

        // "Não serão atribuídos prémios monetários."
        if ($negado !== null) {
            return [false, self::excerto($original, $normal, $negado), true];
        }

        if (preg_match(self::PADRAO_POR_ANUNCIAR, $normal)) {
            return null;
        }

        if (preg_match(self::PADRAO_DESCREVE_PREMIOS, $normal, $m, PREG_OFFSET_CAPTURE)) {
            $explicito = (bool) preg_match('/trofeu|medalha|lembranca|simbolic|\btaca|brinde|especie|diploma/', $m[0][0]);

            return [false, self::excerto($original, $normal, $m[0][1]), $explicito];
        }

        return null;
    }

    /** Um montante: "100", "1.500", "1 500", "100,00". */
    private const NUMERO = '\d{1,3}(?:[ .]\d{3})*(?:,\d{1,2})?';

    /**
     * "1.º lugar – 100,00 €", "2º- 50€", "3.º classificado: 75 euros", "1º Troféu + 150 €",
     * "1º €100 + Troféu", "1.º Classificado Geral Masculino – (200 €)", "1º Class. 400,00€",
     * "1. Geral Masculino / Feminino – 100€".
     */
    private const PADRAO_LUGAR_VALOR = '/(?<![\d,.])(\d{1,2})(?:\s?\.?\s?(?:º|ª|°|o\b|a\b)|\.(?=\s))([^\d€]{0,45}?)'
        . '(?:(?:€|eur\b)\s?(' . self::NUMERO . ')|(' . self::NUMERO . ')\s?(?:€|euros?\b))/u';

    /** Lugares por extenso: "o vencedor recebe 125 €, o segundo 100 € e o terceiro 75 €". */
    private const PADRAO_PALAVRA_VALOR = '/\b(vencedor(?:es|as?)?|primeir[oa]s?|segund[oa]s?|terceir[oa]s?|quart[oa]s?|quint[oa]s?)\b'
        . '([^\d€.;]{0,60}?)(?:(?:€|eur\b)\s?(' . self::NUMERO . ')|(' . self::NUMERO . ')\s?(?:€|euros?\b))/u';

    /** O valor antes do lugar por extenso: "250 € para o segundo e 150 € para o terceiro". */
    private const PADRAO_VALOR_PALAVRA = '/(?<![\d,.])(' . self::NUMERO . ')\s?(?:€|euros?\b),?\s(?:para (?:o|a)|ao|a) '
        . '(vencedor(?:es|as?)?|primeir[oa]s?|segund[oa]s?|terceir[oa]s?|quart[oa]s?|quint[oa]s?)\b/u';

    /** Uma lista de valores antes dos lugares: "50 €, 30 € e 20 € para 1.º, 2.º e 3.º", "40 €, 25 € e 15 € para os três primeiros". */
    private const PADRAO_LISTA_ANTES = '/((?:' . self::NUMERO . '\s?(?:€|euros?\b)?\s?(?:,|;|\be\b)\s?){1,9}' . self::NUMERO . '\s?(?:€|euros?\b)),?\s'
        . '(?:para|aos?|as?) (?:os |as )?(?:1\.?\s?(?:º|o\b)|(?:dois|duas|tres|quatro|cinco|seis|sete|oito|nove|dez|\d{1,2}) primeir)/u';

    /** Lugar de cada palavra de PADRAO_PALAVRA_VALOR (pelas primeiras letras). */
    private const LUGAR_POR_EXTENSO = ['venc' => 1, 'prim' => 1, 'segu' => 2, 'terc' => 3, 'quar' => 4, 'quin' => 5];

    /** Entre a palavra e o valor, coisas que mostram que não é um prémio ("primeira fase 15 €"). */
    private const PADRAO_PALAVRA_NAO_PREMIO = '/fase|inscri|escal[aã]o|periodo|\bate\b|\bkm\b|abastec|dia|semana|equipa/';

    /** Só o total: "prize money global de 5.000 euros", "prémios monetários no valor de 2650,00 €". */
    private const PADRAO_TOTAL_ANUNCIADO = '/(?:prize money|premios (?:monetarios|pecuniarios|em dinheiro)|bolsa de premios)'
        . '[^.;€\d]{0,40}?(?:global|total|anunciad[oa]|valor|montante)[^.;€\d]{0,15}?(?:de|:)\s?(?:€\s?)?(' . self::NUMERO . ')\s?(?:€|euros?\b)?/u';

    /** O valor antes do lugar: "350 € para o 1.º", "150 euros ao 2.º". */
    private const PADRAO_VALOR_LUGAR = '/(?<![\d,.])(' . self::NUMERO . ')\s?(?:€|euros?\b),?\s(?:para (?:o|a)|ao|a) (\d{1,2})\s?\.?\s?(?:º|ª|°|o\b|a\b)/u';

    /** Uma lista de valores por ordem: "recebem, respetivamente, 50 €, 25 € e 15 €", "no valor de 100, 50 e 25 euros". */
    private const PADRAO_LISTA_VALORES = '/(?:respe(?:c)?tivamente|no valor de|nos valores de)[\s:,]*'
        . '((?:' . self::NUMERO . '\s?(?:€|euros?\b)?\s?(?:,|;|\/|\be\b)\s?){1,9}' . self::NUMERO . '\s?(?:€|euros?\b))/u';

    /** Valores sem lugar logo a seguir a um "lugar → valor": as outras colunas da mesma linha. */
    private const PADRAO_COLUNAS_SEGUINTES = '/\G(?:\s?[|;\/–-]?\s?(?:€\s?)?' . self::NUMERO . '\s?(?:€|euros?\b))+/u';

    /** Palavras que podem estar entre o lugar e o valor ("1.º lugar – 100 €"). */
    private const PADRAO_ENTRE_PERMITIDO = '/\b(?:lugar(?:es)?|class(?:ificad[oa]s?)?|premios?|trofeus?|medalhas?|geral|t|'
        . 'masculin[oa]s?|feminin[oa]s?|absolut[oa]s?|individual|m|f)\b/';

    /** Etiquetas das tabelas, lidas no texto logo antes: padrão => texto (null: o que foi encontrado). */
    private const ETIQUETAS_TABELA = [
        '/\bgeral\b|absolut/' => 'Geral',
        '/masculin|\bmasc\b|\bhomens\b/' => 'Masculinos',
        '/feminin|\bfem\b|senhoras/' => 'Femininos',
        '/\bm\s?\/\s?f\b/' => 'M/F',
        '/equipas|coletiv|colectiv/' => 'Equipas',
        '/\b(?:juniores|seniores|juvenis|sub.?\d{2}|vet(?:eranos)?\.?\s?\d{2}|[mf]\d{2}|veteranos)\b/' => null,
        '/\b\d+(?:,\d+)?\s?km\b/' => null,
    ];

    /**
     * Tabelas de prémios monetários de um regulamento ou página: grupos de
     * "lugar → valor" que começam no 1.º lugar ("1.º 300 €, 2.º 200 €..."),
     * com uma etiqueta lida no texto logo antes ("Geral", "Femininos",
     * "Vet 40"...). Os PDFs chegam muitas vezes com as colunas baralhadas,
     * por isso fica só o que dá para ler com segurança; tabelas iguais (ex.
     * masculinos e femininos com os mesmos valores) aparecem uma vez.
     *
     * @return array<int,array{titulo: ?string, linhas: array<int,array{0:int,1:string}>}> no máximo 6
     */
    public static function tabelas(string $texto): array
    {
        $normal = strtr(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $texto))), self::ACENTOS);
        // Algarismos separados pelo PDF ("12 5 €", "20 0 €", "3 00€" → 125, 200, 300).
        $normal = (string) preg_replace_callback(
            '/(?<![\d.,])(\d{1,2}) (\d{1,2})(?=\s?€)/u',
            fn (array $a) => strlen($a[1] . $a[2]) <= 3 ? $a[1] . $a[2] : $a[0],
            $normal
        );

        // Pares [lugar, valor, início, fim, valores das outras colunas] (posições em bytes), pela ordem do texto.
        $pares = [];
        // Posições dos valores já usados (o mesmo valor não conta duas vezes).
        $valoresUsados = [];
        // "350 € para o 1.º, 150 € para o 2.º": o valor vem antes do lugar.
        preg_match_all(self::PADRAO_VALOR_LUGAR, $normal, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $par) {
            $pares[] = [(int) $par[2][0], self::montante($par[1][0]), $par[0][1], $par[0][1] + strlen($par[0][0]), []];
            $valoresUsados[$par[1][1]] = true;
        }
        preg_match_all(self::PADRAO_VALOR_PALAVRA, $normal, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $par) {
            $pares[] = [self::LUGAR_POR_EXTENSO[substr($par[2][0], 0, 4)], self::montante($par[1][0]), $par[0][1], $par[0][1] + strlen($par[0][0]), []];
            $valoresUsados[$par[1][1]] = true;
        }
        // "50 €, 30 € e 20 € para 1.º, 2.º e 3.º": 1.º, 2.º, 3.º...
        preg_match_all(self::PADRAO_LISTA_ANTES, $normal, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $lista) {
            preg_match_all('/' . self::NUMERO . '/', $lista[1][0], $valores, PREG_OFFSET_CAPTURE);
            foreach ($valores[0] as $i => [$valor, $pos]) {
                $inicio = $lista[1][1] + $pos;
                $pares[] = [$i + 1, self::montante($valor), $inicio, $inicio + strlen($valor), []];
                $valoresUsados[$inicio] = true;
            }
        }
        preg_match_all(self::PADRAO_LUGAR_VALOR, $normal, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $par) {
            $entre = (string) preg_replace(self::PADRAO_ENTRE_PERMITIDO, '', $par[2][0]);
            $posValor = ($par[3][0] ?? '') !== '' ? $par[3][1] : $par[4][1];
            // "1.ª fase 15 €", "2.º escalão 10 euros": não é um prémio; em "1.º, 150 € para
            // o 2.º" o valor é do lugar seguinte.
            if (!preg_match('/[a-z]{2,}/', $entre) && !isset($valoresUsados[$posValor])) {
                $valor = ($par[3][0] ?? '') !== '' ? $par[3][0] : $par[4][0];
                $fim = $par[0][1] + strlen($par[0][0]);
                // Outras colunas na mesma linha ("1º Classificado 50€ 50€ 50€..."): valores logo a seguir.
                $extras = [];
                if (preg_match(self::PADRAO_COLUNAS_SEGUINTES, $normal, $e, 0, $fim)) {
                    preg_match_all('/' . self::NUMERO . '/', $e[0], $numeros);
                    $extras = array_map([self::class, 'montante'], $numeros[0]);
                    $fim += strlen($e[0]);
                }
                $pares[] = [(int) $par[1][0], self::montante($valor), $par[0][1], $fim, $extras];
                $valoresUsados[$posValor] = true;
            }
        }
        // "Respetivamente, 50 €, 25 € e 15 €": 1.º, 2.º, 3.º...
        preg_match_all(self::PADRAO_LISTA_VALORES, $normal, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $lista) {
            preg_match_all('/' . self::NUMERO . '/', $lista[1][0], $valores, PREG_OFFSET_CAPTURE);
            foreach ($valores[0] as $i => [$valor, $pos]) {
                $inicio = $lista[1][1] + $pos;
                if (!isset($valoresUsados[$inicio])) {
                    $pares[] = [$i + 1, self::montante($valor), $inicio, $inicio + strlen($valor), []];
                    $valoresUsados[$inicio] = true;
                }
            }
        }
        // "O vencedor recebe 125 €, o segundo 100 € e o terceiro 75 €".
        preg_match_all(self::PADRAO_PALAVRA_VALOR, $normal, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $par) {
            $posValor = ($par[3][0] ?? '') !== '' ? $par[3][1] : $par[4][1];
            if (isset($valoresUsados[$posValor]) || preg_match(self::PADRAO_PALAVRA_NAO_PREMIO, $par[2][0])) {
                continue;
            }
            $valor = ($par[3][0] ?? '') !== '' ? $par[3][0] : $par[4][0];
            $pares[] = [self::LUGAR_POR_EXTENSO[substr($par[1][0], 0, 4)], self::montante($valor), $par[0][1], $par[0][1] + strlen($par[0][0]), []];
            $valoresUsados[$posValor] = true;
        }
        if ($pares === []) {
            return self::totalAnunciado($normal);
        }
        usort($pares, fn (array $a, array $b) => $a[2] <=> $b[2]);

        $grupos = [];
        $atual = null;
        $ultimoLugar = null;
        $fimAnterior = 0;
        foreach ($pares as [$lugar, $valor, $inicio, $fim, $extras]) {
            if ($lugar < 1 || $lugar > 20 || $valor < 10) {
                continue;
            }
            // Colunas lado a lado lidas linha a linha ("1.º 150 € 1.º 150 € 2.º 100 €..."):
            // o mesmo lugar logo a seguir é a outra coluna — mostra-se a primeira,
            // as outras contam para o total.
            if ($atual !== null && $lugar === $ultimoLugar && $inicio - $fimAnterior < 60) {
                array_push($atual['extras'][$lugar], $valor, ...$extras);
                $fimAnterior = $fim;
                continue;
            }
            if ($atual === null || isset($atual['linhas'][$lugar]) || $inicio - $fimAnterior > 400) {
                if ($atual !== null) {
                    $grupos[] = $atual;
                }
                // Etiqueta: o texto logo antes, o da própria linha ("1. Geral Masculino / Feminino – 100€")
                // e o que vem a seguir até ao próximo valor ("500 € para o 1.º classificado da Geral
                // masculina e feminina, 250 €...").
                $desde = max($fimAnterior, $inicio - 160);
                preg_match('/\G[^\d€]{0,60}/u', $normal, $depois, 0, $fim);
                $atual = ['titulo' => self::etiquetaTabela(substr($normal, $desde, $fim - $desde) . ($depois[0] ?? '')), 'linhas' => [], 'extras' => []];
            }
            $atual['linhas'][$lugar] = $valor;
            $atual['extras'][$lugar] = $extras;
            $ultimoLugar = $lugar;
            $fimAnterior = $fim;
        }
        if ($atual !== null) {
            $grupos[] = $atual;
        }

        $tabelas = [];
        foreach ($grupos as $grupo) {
            if (!isset($grupo['linhas'][1]) || count($grupo['linhas']) < 2) {
                continue;
            }
            ksort($grupo['linhas']);
            $grupo['colunas'] = 1 + count($grupo['extras'][1] ?? []);
            $grupo['soma'] = array_sum($grupo['linhas']) + array_sum(array_map('array_sum', $grupo['extras']));
            $chave = json_encode([$grupo['linhas'], $grupo['colunas']]);
            if (isset($tabelas[$chave])) {
                // Mesmos valores (ex. masculinos e femininos, ou vários escalões): mostra-se
                // uma vez, com as etiquetas juntas, e o total conta todas.
                $titulos = array_filter([$tabelas[$chave]['titulo'], $grupo['titulo']]);
                $tabelas[$chave]['titulo'] = $titulos !== [] ? implode(' / ', array_unique($titulos)) : null;
                $tabelas[$chave]['soma'] += $grupo['soma'];
                $tabelas[$chave]['repeticoes']++;
                continue;
            }
            $tabelas[$chave] = $grupo + ['repeticoes' => 1];
        }

        $resultado = [];
        foreach ($tabelas as $tabela) {
            $linhas = [];
            foreach ($tabela['linhas'] as $lugar => $valor) {
                $linhas[] = [$lugar, self::formatarEuros($valor)];
            }
            $resultado[] = ['titulo' => $tabela['titulo'], 'linhas' => $linhas]
                + ($tabela['colunas'] > 1 || $tabela['repeticoes'] > 1
                    ? ['colunas' => $tabela['colunas'], 'repeticoes' => $tabela['repeticoes'], 'soma' => $tabela['soma']]
                    : []);
        }

        return $resultado !== [] ? array_slice($resultado, 0, 6) : self::totalAnunciado($normal);
    }

    /**
     * Sem tabela, o total que o regulamento anuncia ("prize money global de
     * 5.000 euros"): uma "tabela" sem linhas, só com a soma.
     *
     * @return array<int,array{titulo: string, linhas: array{}, soma: float, anunciado: true}>
     */
    private static function totalAnunciado(string $normal): array
    {
        if (!preg_match(self::PADRAO_TOTAL_ANUNCIADO, $normal, $m)) {
            return [];
        }
        $total = self::montante($m[1]);

        return $total >= 100 ? [['titulo' => 'Total anunciado', 'linhas' => [], 'soma' => $total, 'anunciado' => true]] : [];
    }

    /** "Geral · Femininos", "Vet 40", "10 km · Geral"... ou null. */
    private static function etiquetaTabela(string $antes): ?string
    {
        $encontradas = [];
        foreach (self::ETIQUETAS_TABELA as $padrao => $texto) {
            if (preg_match_all($padrao, $antes, $m, PREG_OFFSET_CAPTURE)) {
                $ultima = end($m[0]);
                $etiqueta = $texto ?? ucfirst(trim((string) preg_replace('/\s+/', ' ', $ultima[0])));
                $encontradas[$etiqueta] = $ultima[1];
            }
        }
        asort($encontradas);

        return $encontradas !== [] ? implode(' · ', array_keys($encontradas)) : null;
    }

    /**
     * Tabelas dos prémios de uma prova, para o site: as gravadas pelo scraper
     * (coluna premios_tabela, maior 1.º prémio primeiro) ou, se não as tiver,
     * as que dá para ler no excerto ("1.º Lugar 100 € 2.º Lugar 75 €...").
     *
     * @return array<int,array{titulo: ?string, linhas: array<int,array{0:int,1:string}>}>
     */
    public static function tabelasDaProva(?string $tabelasJson, ?string $excerto): array
    {
        $tabelas = json_decode((string) $tabelasJson, true) ?: [];
        if ($tabelas === [] && $excerto !== null && $excerto !== '') {
            $tabelas = self::tabelas($excerto);
        }
        usort($tabelas, fn (array $a, array $b) => self::montante($b['linhas'][0][1] ?? '0') <=> self::montante($a['linhas'][0][1] ?? '0'));

        return $tabelas;
    }

    /**
     * Quantas vezes uma tabela é paga: 2 quando vale para masculinos e
     * femininos ("Masculinos / Femininos", "Geral M/F"), senão 1.
     */
    public static function vezesPaga(?string $titulo): int
    {
        $titulo = mb_strtolower((string) $titulo);

        return (str_contains($titulo, 'masculin') && str_contains($titulo, 'feminin')) || str_contains($titulo, 'm/f') ? 2 : 1;
    }

    /**
     * Título curto de uma tabela para o site: "Masculinos · Geral · Femininos"
     * → "Geral (M e F)", "Masculinos · Femininos" → "Classificação (M e F)";
     * com várias colunas (uma por escalão) → "Por escalão (12) (M e F)".
     *
     * @param array{titulo: ?string, colunas?: int} $tabela
     */
    public static function rotulo(array $tabela): string
    {
        $titulo = $tabela['titulo'] ?? null;
        $colunas = (int) ($tabela['colunas'] ?? 1);
        $partes = array_filter(
            explode(' · ', str_replace(' / ', ' · ', (string) $titulo)),
            fn (string $p) => $p !== '' && !in_array($p, ['Masculinos', 'Femininos', 'M/F'], true)
        );
        $repeticoes = (int) ($tabela['repeticoes'] ?? 1);
        $rotulo = match (true) {
            $colunas > 2 => "Por escalão ({$colunas})",
            $repeticoes > 2 => "Por escalão ({$repeticoes})",
            $partes !== [] => implode(' · ', array_unique($partes)),
            default => 'Classificação',
        };

        return $rotulo . (self::vezesPaga($titulo) > 1 ? ' (M e F)' : '');
    }

    /**
     * Total de uma tabela: a soma de todas as colunas (se o PDF tiver várias,
     * ex. uma por escalão) e de todas as tabelas iguais juntadas nela, a
     * dobrar quando vale para os dois sexos — exceto quando as colunas já são
     * "Femininos" e "Masculinos" ou a soma já junta as tabelas de cada sexo.
     *
     * @param array{titulo: ?string, linhas: array<int,array{0:int,1:string}>, colunas?: int, repeticoes?: int, soma?: float} $tabela
     */
    public static function totalTabela(array $tabela): float
    {
        $titulo = mb_strtolower((string) ($tabela['titulo'] ?? ''));
        $soma = isset($tabela['soma'])
            ? (float) $tabela['soma']
            : array_sum(array_map(fn (array $linha) => self::montante($linha[1]), $tabela['linhas']));
        $sexosJaContados = ($tabela['repeticoes'] ?? 1) > 1
            || (($tabela['colunas'] ?? 1) > 1 && str_contains($titulo, 'masculin') && str_contains($titulo, 'feminin'));

        return $soma * ($sexosJaContados ? 1 : self::vezesPaga($titulo));
    }

    /**
     * Total distribuído em prémios (prize money total): a soma dos totais de
     * todas as tabelas (self::totalTabela).
     *
     * @param array<int,array{titulo: ?string, linhas: array<int,array{0:int,1:string}>}> $tabelas
     */
    public static function total(array $tabelas): float
    {
        return array_sum(array_map([self::class, 'totalTabela'], $tabelas));
    }

    /** "1.500 €", "150,50 €" para mostrar. */
    public static function formatarEuros(float $valor): string
    {
        return number_format($valor, floor($valor) == $valor ? 0 : 2, ',', '.') . ' €';
    }

    /** Documentos lidos por prova, no máximo (páginas + regulamentos). */
    private const MAX_DOCUMENTOS = 5;

    /**
     * Procura prémios monetários nas páginas de uma prova e nos regulamentos
     * para que elas apontam (PDF ou página; na Stop and Go, a página /rules
     * aponta por sua vez para o PDF). Pára no primeiro "sim"; um "não" só
     * fica se nenhum outro documento disser que sim.
     *
     * @param string[] $paginas
     * @param callable(string): ?string $obter devolve o conteúdo do endereço,
     *        ou null se o pedido falhou
     * @return array{0: ?bool, 1: ?string, 2: ?string, 3: bool, 4: array} [prémios
     *         monetários, excerto, endereço onde foi lido, algum documento respondeu?,
     *         tabelas dos valores (self::tabelas) — do mesmo documento ou, se esse
     *         não as tiver, do primeiro dos restantes que as tenha]
     */
    public static function procurar(array $paginas, callable $obter): array
    {
        $fila = array_map(fn (string $url) => [$url, 0], $paginas);
        $vistos = [];
        $nao = null;
        $sim = null;
        $respondeu = false;

        while ($fila !== [] && count($vistos) < self::MAX_DOCUMENTOS) {
            [$url, $nivel] = array_shift($fila);
            if (isset($vistos[$url])) {
                continue;
            }
            $vistos[$url] = true;
            if (self::ePaginaGeral($url, true)) {
                continue;
            }

            $conteudo = $obter($url);
            if ($conteudo === null) {
                continue;
            }
            $respondeu = true;

            $ePdf = str_starts_with(ltrim(substr($conteudo, 0, 1024)), '%PDF');
            if ($ePdf) {
                $texto = PdfTextoHelper::extrair($conteudo);
            } else {
                $texto = self::textoHtml($conteudo);
                if ($nivel < 2) {
                    foreach (self::linksRegulamento($conteudo, $url) as $link) {
                        // Os regulamentos passam à frente das restantes páginas da prova.
                        array_unshift($fila, [$link, $nivel + 1]);
                    }
                }
            }

            // Já se sabe que há prémios, mas onde se leu não tem os valores:
            // procura-se a tabela nos documentos que faltam (ex. o regulamento).
            if ($sim !== null) {
                $tabelas = self::tabelas($texto);
                if ($tabelas !== []) {
                    $sim[4] = $tabelas;
                    return $sim;
                }
                continue;
            }

            $resultado = self::analisar($texto);
            if ($resultado === null) {
                continue;
            }
            if ($resultado[0]) {
                $sim = [true, $resultado[1], $url, true, self::tabelas($texto)];
                if ($sim[4] !== []) {
                    return $sim;
                }
                continue;
            }
            // Numa página do evento, "prémios aos 3 primeiros" sem dizer quais não
            // chega para "não"; no regulamento chega (quem dá dinheiro diz quanto).
            $eRegulamento = $ePdf || $nivel > 0 || preg_match('/regulament|rules/i', $url);
            if (!$resultado[2] && !$eRegulamento) {
                continue;
            }
            // Um "não" lido num regulamento vale mais do que numa página.
            if ($nao === null || $eRegulamento) {
                $nao = [false, $resultado[1], $url, true, []];
            }
        }

        return $sim ?? $nao ?? [null, null, null, $respondeu, []];
    }

    /** Valor em euros de "1.500,00 €" / "€ 100" (para ignorar "1 €" soltos). */
    public static function montante(string $valor): float
    {
        $numero = (string) preg_replace('/[^\d,]/', '', $valor);

        return (float) str_replace(',', '.', $numero);
    }

    /** ~200 caracteres do texto original à volta de uma posição (em bytes do texto normalizado). */
    private static function excerto(string $original, string $normal, int $posBytes): string
    {
        $posCaracteres = mb_strlen(substr($normal, 0, $posBytes));
        $inicio = max(0, $posCaracteres - 80);
        $excerto = mb_substr($original, $inicio, 200);

        return ($inicio > 0 ? '…' : '') . trim($excerto) . '…';
    }

    /**
     * Página inicial de um site ("https://site.pt/", "/index.php") ou, com
     * $soListagens, só as listagens ("/index.php?limit=6&start=0"): a página
     * inicial pode ser o site da própria prova, uma listagem nunca é — os
     * regulamentos e preços que lá estejam são de outras provas (ex. a FPA dá
     * a página de notícias do organizador como regulamento do corta-mato).
     */
    public static function ePaginaGeral(string $url, bool $soListagens = false): bool
    {
        $caminho = (string) parse_url($url, PHP_URL_PATH);
        if (!preg_match('#^/?(?:index\.(?:php|html?|aspx?))?$#i', $caminho)) {
            return false;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parametros);
        $chaves = array_keys($parametros);
        $paginacao = ['limit', 'start', 'page', 'pagina'];

        // Só parâmetros de paginação/idioma; "?id=..."/"?option=..." já podem ser a prova.
        return array_diff($chaves, [...$paginacao, 'lang']) === []
            && (!$soListagens || array_intersect($chaves, $paginacao) !== []);
    }

    /**
     * Links para o regulamento numa página de prova: PDFs e páginas com
     * "regulamento"/"rules" no endereço ou no texto do link; na Lap2Go o
     * regulamento vem num campo JSON ("Regulamento":"https://...pdf").
     *
     * @return string[] endereços absolutos, sem repetidos, no máximo 3
     */
    public static function linksRegulamento(string $html, string $urlPagina): array
    {
        $links = [];

        if (preg_match_all('/"Regulamento"\s*:\s*"(https?:[^"]+)"/i', $html, $m)) {
            foreach ($m[1] as $url) {
                $links[] = stripslashes($url);
            }
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        foreach ((new DOMXPath($dom))->query('//a[@href]') as $a) {
            $href = trim($a->getAttribute('href'));
            $texto = strtr(mb_strtolower(trim($a->textContent)), self::ACENTOS);
            $hrefNormal = strtr(mb_strtolower(rawurldecode($href)), self::ACENTOS);
            $eRegulamento = str_contains($hrefNormal, 'regulament') || str_contains($texto, 'regulament')
                || preg_match('#/rules/?$#', $hrefNormal);
            if ($eRegulamento && !str_starts_with($hrefNormal, 'mailto:') && !str_starts_with($hrefNormal, 'javascript:')) {
                $absoluto = self::absoluto($href, $urlPagina);
                if ($absoluto !== null) {
                    $links[] = $absoluto;
                }
            }
        }

        // Regulamentos gerais da federação/associação não dizem nada sobre a prova;
        // links de "adicionar ao calendário"/partilhar trazem o texto da prova no endereço.
        $links = array_filter(
            $links,
            fn (string $url) => !preg_match('/regulamento.?geral|regulamento.?de.?competic|normas.?gerais|privacidade|cookies|rgpd|\/documentos\/?$/i', rawurldecode($url))
                && !preg_match('#^https?://(?:www\.)?(?:google\.|facebook\.|twitter\.|x\.com|wa\.me|api\.whatsapp|linkedin\.|calendar\.)#i', $url)
                && $url !== $urlPagina
        );
        // Espaços e acentos nos nomes dos ficheiros (ex. Stop and Go) têm de ir codificados.
        $links = array_map(
            fn (string $url) => (string) preg_replace_callback('/[^\x21-\x7E]/', fn (array $c) => rawurlencode($c[0]), $url),
            $links
        );

        return array_slice(array_values(array_unique($links)), 0, 3);
    }

    /** Texto visível de uma página HTML (sem scripts, estilos nem menus). */
    public static function textoHtml(string $html): string
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        foreach ($xpath->query('//script|//style|//noscript|//nav|//header|//footer|//form|//select') as $no) {
            $no->parentNode?->removeChild($no);
        }
        // Separa blocos (células, parágrafos) para não colar palavras.
        foreach ($xpath->query('//p|//div|//li|//td|//th|//tr|//br|//h1|//h2|//h3|//h4|//h5|//h6') as $no) {
            $no->appendChild($dom->createTextNode("\n"));
        }

        $corpo = $xpath->query('//body')->item(0);

        return html_entity_decode((string) ($corpo?->textContent ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function absoluto(string $href, string $base): ?string
    {
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $partes = parse_url($base);
        if (!isset($partes['scheme'], $partes['host'])) {
            return null;
        }
        $raiz = $partes['scheme'] . '://' . $partes['host'];
        if (str_starts_with($href, '//')) {
            return $partes['scheme'] . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $raiz . $href;
        }
        $pasta = preg_replace('#/[^/]*$#', '/', $partes['path'] ?? '/');

        return $raiz . $pasta . $href;
    }
}
