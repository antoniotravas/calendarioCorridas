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
                // "Taxa: 2% sobre o total dos prémios monetários" (tabelas de taxas das associações).
                if (preg_match('/taxa|%|percentagem/', mb_substr($antes, -40))) {
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

    /** "1.º lugar – 100,00 €", "2º- 50€", "3.º classificado: 75 euros", "1º Troféu + 150 €". */
    private const PADRAO_LUGAR_VALOR = '/(?<![\d,.])(\d{1,2})\s?\.?\s?(?:º|ª|°|o\b|a\b)([^\d€]{0,30}?)'
        . '(\d{1,3}(?:[ .]\d{3})*(?:,\d{1,2})?)\s?(?:€|euros?\b)/u';

    /** Palavras que podem estar entre o lugar e o valor ("1.º lugar – 100 €"). */
    private const PADRAO_ENTRE_PERMITIDO = '/\b(?:lugar(?:es)?|classificad[oa]s?|premios?|trofeus?|medalhas?|geral|t)\b/';

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
        if (!preg_match_all(self::PADRAO_LUGAR_VALOR, $normal, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $grupos = [];
        $atual = null;
        $ultimoLugar = null;
        $fimAnterior = 0;
        foreach ($m as $par) {
            $lugar = (int) $par[1][0];
            $valor = self::montante($par[3][0]);
            $entre = (string) preg_replace(self::PADRAO_ENTRE_PERMITIDO, '', $par[2][0]);
            // "1.ª fase 15 €", "2.º escalão 10 euros": não é um prémio.
            if ($lugar < 1 || $lugar > 20 || $valor < 10 || preg_match('/[a-z]{2,}/', $entre)) {
                continue;
            }
            $inicio = $par[0][1];
            // Colunas lado a lado lidas linha a linha ("1.º 150 € 1.º 150 € 2.º 100 €..."):
            // o mesmo lugar logo a seguir é a outra coluna — fica a primeira.
            if ($atual !== null && $lugar === $ultimoLugar && $inicio - $fimAnterior < 60) {
                $fimAnterior = $inicio + strlen($par[0][0]);
                continue;
            }
            if ($atual === null || isset($atual['linhas'][$lugar]) || $inicio - $fimAnterior > 400) {
                if ($atual !== null) {
                    $grupos[] = $atual;
                }
                $antes = substr($normal, max($fimAnterior, $inicio - 160), $inicio - max($fimAnterior, $inicio - 160));
                $atual = ['titulo' => self::etiquetaTabela($antes), 'linhas' => []];
            }
            $atual['linhas'][$lugar] = $valor;
            $ultimoLugar = $lugar;
            $fimAnterior = $inicio + strlen($par[0][0]);
        }
        $grupos[] = $atual;

        $tabelas = [];
        foreach ($grupos as $grupo) {
            if (!isset($grupo['linhas'][1]) || count($grupo['linhas']) < 2) {
                continue;
            }
            ksort($grupo['linhas']);
            $chave = json_encode($grupo['linhas']);
            if (isset($tabelas[$chave])) {
                // Mesmos valores (ex. masculinos e femininos): junta as etiquetas.
                $titulos = array_filter([$tabelas[$chave]['titulo'], $grupo['titulo']]);
                $tabelas[$chave]['titulo'] = $titulos !== [] ? implode(' / ', array_unique($titulos)) : null;
                continue;
            }
            $tabelas[$chave] = $grupo;
        }

        $resultado = [];
        foreach ($tabelas as $tabela) {
            $linhas = [];
            foreach ($tabela['linhas'] as $lugar => $valor) {
                $casas = floor($valor) == $valor ? 0 : 2;
                $linhas[] = [$lugar, number_format($valor, $casas, ',', '.') . ' €'];
            }
            $resultado[] = ['titulo' => $tabela['titulo'], 'linhas' => $linhas];
        }

        return array_slice($resultado, 0, 6);
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
    private static function montante(string $valor): float
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
