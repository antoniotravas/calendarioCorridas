<?php

declare(strict_types=1);

/**
 * Extrai o texto de um PDF, sem bibliotecas externas — suficiente para ler
 * regulamentos de provas (gerados quase sempre pelo Word, Canva ou Google
 * Docs). Não é um leitor completo de PDF:
 *  - lê os objetos do ficheiro, incluindo os guardados dentro de "object
 *    streams" (PDF 1.5+), e descomprime os streams FlateDecode;
 *  - em cada página, junta o texto dos operadores Tj, TJ, ' e " dos seus
 *    conteúdos, traduzindo os códigos de cada letra pelo mapa ToUnicode da
 *    letra (fonte) em uso, ou como Windows-1252 quando a fonte não o tem;
 *  - PDFs cifrados ou só com imagens (digitalizados) devolvem texto vazio.
 */
final class PdfTextoHelper
{
    /** @var array<int,string> número do objeto => conteúdo (sem "N 0 obj"/"endobj") */
    private array $objetos = [];

    /** @var array<int,array<string,string>|null> cache dos mapas ToUnicode por objeto */
    private array $mapas = [];

    /** @var array<int,int> cache: número do objeto => bytes por código (1 ou 2) */
    private array $bytesPorCodigo = [];

    private function __construct(private readonly string $pdf)
    {
    }

    public static function extrair(string $pdf): string
    {
        if (!str_starts_with(ltrim(substr($pdf, 0, 1024)), '%PDF')) {
            return '';
        }

        $leitor = new self($pdf);
        $leitor->lerObjetos();

        // Ficheiros cifrados: o texto dos streams é ilegível sem decifrar.
        if (preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $pdf)) {
            return '';
        }

        $texto = $leitor->textoDasPaginas();

        return trim((string) preg_replace('/[ \t]+/u', ' ', $texto));
    }

    private function lerObjetos(): void
    {
        preg_match_all('/(?<![\d])(\d+)\s+(\d+)\s+obj\b(.*?)\bendobj\b/s', $this->pdf, $m, PREG_SET_ORDER);
        foreach ($m as $obj) {
            // Atualizações incrementais: a última versão do objeto é a que vale.
            $this->objetos[(int) $obj[1]] = $obj[3];
        }

        foreach ($this->objetos as $corpo) {
            if (preg_match('/\/Type\s*\/ObjStm\b/', $corpo)) {
                $this->lerObjectStream($corpo);
            }
        }
    }

    /** Object stream: "num1 desl1 num2 desl2 ..." seguido dos objetos. */
    private function lerObjectStream(string $corpo): void
    {
        $dados = $this->dadosStream($corpo);
        if ($dados === null
            || !preg_match('/\/N\s+(\d+)/', $corpo, $n)
            || !preg_match('/\/First\s+(\d+)/', $corpo, $primeiro)
        ) {
            return;
        }

        $cabecalho = preg_split('/\s+/', trim(substr($dados, 0, (int) $primeiro[1])));
        $pares = [];
        for ($i = 0; $i + 1 < count($cabecalho) && count($pares) < (int) $n[1]; $i += 2) {
            $pares[] = [(int) $cabecalho[$i], (int) $cabecalho[$i + 1]];
        }

        foreach ($pares as $i => [$numero, $desl]) {
            $inicio = (int) $primeiro[1] + $desl;
            $fim = isset($pares[$i + 1]) ? (int) $primeiro[1] + $pares[$i + 1][1] : strlen($dados);
            // Um objeto fora do object stream (atualização incremental) prevalece.
            $this->objetos[$numero] ??= substr($dados, $inicio, $fim - $inicio);
        }
    }

    /** Dados (já descomprimidos) do stream de um objeto, ou null se não for legível. */
    private function dadosStream(string $corpo): ?string
    {
        if (!preg_match('/^(.*?)\bstream\r?\n(.*)\bendstream\b/s', $corpo, $m)) {
            return null;
        }
        [$dicionario, $dados] = [$m[1], $m[2]];
        $dados = (string) preg_replace('/\r?\n$/', '', $dados);

        if (preg_match('/\/Filter\s*\[?\s*\/(\w+)/', $dicionario, $filtro)) {
            if ($filtro[1] !== 'FlateDecode') {
                return null; // imagens (DCTDecode...) e filtros raros
            }
            $descomprimido = @gzuncompress($dados);
            if ($descomprimido === false) {
                $descomprimido = @gzinflate(substr($dados, 2));
            }
            if ($descomprimido === false) {
                return null;
            }
            $dados = $descomprimido;

            if (preg_match('/\/Predictor\s+(\d+)/', $dicionario, $p) && (int) $p[1] >= 10) {
                return null; // só usado em xref streams e imagens, sem texto
            }
        }

        return $dados;
    }

    private function textoDasPaginas(): string
    {
        $paginas = [];
        foreach ($this->objetos as $numero => $corpo) {
            if (preg_match('/\/Type\s*\/Page\b(?!s)/', $corpo)) {
                $paginas[$numero] = $corpo;
            }
        }

        $partes = [];
        foreach ($paginas as $numero => $corpo) {
            $fontes = $this->fontesDaPagina($corpo);
            foreach ($this->refsConteudo($corpo) as $ref) {
                $dados = $this->dadosStream($this->objetos[$ref] ?? '');
                if ($dados !== null) {
                    $partes[] = $this->textoDoConteudo($dados, $fontes);
                }
            }
        }

        return implode("\n", $partes);
    }

    /** @return int[] objetos com os conteúdos da página (/Contents ref ou [refs]) */
    private function refsConteudo(string $pagina): array
    {
        if (preg_match('/\/Contents\s*\[([^\]]*)\]/', $pagina, $m)) {
            preg_match_all('/(\d+)\s+\d+\s+R/', $m[1], $refs);

            return array_map('intval', $refs[1]);
        }
        if (preg_match('/\/Contents\s+(\d+)\s+\d+\s+R/', $pagina, $m)) {
            // Pode ser um array indireto: "5 0 obj [6 0 R 7 0 R] endobj".
            $alvo = trim($this->objetos[(int) $m[1]] ?? '');
            if (str_starts_with($alvo, '[')) {
                preg_match_all('/(\d+)\s+\d+\s+R/', $alvo, $refs);

                return array_map('intval', $refs[1]);
            }

            return [(int) $m[1]];
        }

        return [];
    }

    /**
     * Fontes da página: nome do recurso ("F1") => [objeto do mapa ToUnicode
     * (null se a fonte não o tiver), codificação sem mapa ("Windows-1252" ou
     * "MACINTOSH", a dos PDFs feitos em Mac)]. Os recursos podem estar na própria
     * página, num objeto à parte ou herdados da árvore de páginas (/Parent).
     *
     * @return array<string,array{0: int|null, 1: string}>
     */
    private function fontesDaPagina(string $pagina): array
    {
        $no = $pagina;
        for ($nivel = 0; $nivel < 10; $nivel++) {
            $recursos = $this->dicionario($no, 'Resources');
            if ($recursos !== null) {
                $fontes = $this->dicionario($recursos, 'Font');
                if ($fontes === null) {
                    return [];
                }
                preg_match_all('/\/([^\s\/<>\[\]()]+)\s+(\d+)\s+\d+\s+R/', $fontes, $m, PREG_SET_ORDER);
                $resultado = [];
                foreach ($m as [, $nome, $ref]) {
                    $fonte = $this->objetos[(int) $ref] ?? '';
                    $codificacao = $fonte;
                    if (preg_match('/\/Encoding\s+(\d+)\s+\d+\s+R/', $fonte, $e)) {
                        $codificacao .= $this->objetos[(int) $e[1]] ?? '';
                    }
                    $resultado[$nome] = [
                        preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $fonte, $t) ? (int) $t[1] : null,
                        str_contains($codificacao, 'MacRomanEncoding') ? 'MACINTOSH' : 'Windows-1252',
                    ];
                }

                return $resultado;
            }
            if (!preg_match('/\/Parent\s+(\d+)\s+\d+\s+R/', $no, $pai)) {
                return [];
            }
            $no = $this->objetos[(int) $pai[1]] ?? '';
        }

        return [];
    }

    /** Valor de /Chave quando é um dicionário: diretamente "<<...>>" ou por referência "N 0 R". */
    private function dicionario(string $corpo, string $chave): ?string
    {
        $pos = strpos($corpo, '/' . $chave);
        if ($pos === false) {
            return null;
        }
        $resto = ltrim(substr($corpo, $pos + strlen($chave) + 1));

        if (preg_match('/^(\d+)\s+\d+\s+R/', $resto, $m)) {
            return $this->objetos[(int) $m[1]] ?? null;
        }
        if (!str_starts_with($resto, '<<')) {
            return null;
        }

        // Dicionário direto: até ao ">>" que fecha o "<<" inicial.
        $profundidade = 0;
        $tamanho = strlen($resto);
        for ($i = 0; $i < $tamanho - 1; $i++) {
            $par = $resto[$i] . $resto[$i + 1];
            if ($par === '<<') {
                $profundidade++;
                $i++;
            } elseif ($par === '>>') {
                $profundidade--;
                $i++;
                if ($profundidade === 0) {
                    return substr($resto, 0, $i + 1);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,array{0: int|null, 1: string}> $fontes
     */
    private function textoDoConteudo(string $conteudo, array $fontes): string
    {
        $texto = '';
        $mapa = null;
        $bytes = 1;
        $codificacao = 'Windows-1252';
        $operandos = [];
        $tamanho = strlen($conteudo);

        for ($i = 0; $i < $tamanho;) {
            $c = $conteudo[$i];

            if ($c === '(') {
                [$valor, $i] = $this->lerLiteral($conteudo, $i);
                $operandos[] = ['s', $valor];
            } elseif ($c === '<' && ($conteudo[$i + 1] ?? '') !== '<') {
                $fim = strpos($conteudo, '>', $i);
                $fim = $fim === false ? $tamanho : $fim;
                $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', substr($conteudo, $i + 1, $fim - $i - 1));
                $operandos[] = ['s', (string) hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex)];
                $i = $fim + 1;
            } elseif ($c === '[') {
                $operandos[] = ['['];
                $i++;
            } elseif ($c === ']') {
                $operandos[] = [']'];
                $i++;
            } elseif ($c === '%') {
                $fim = strcspn($conteudo, "\r\n", $i);
                $i += $fim;
            } elseif (ctype_space($c)) {
                $i++;
            } elseif ($c === '/') {
                $len = strcspn($conteudo, " \t\r\n/[]()<>{}%", $i + 1);
                $operandos[] = ['n', substr($conteudo, $i + 1, $len)];
                $i += $len + 1;
            } elseif ($c === '-' || $c === '+' || $c === '.' || ctype_digit($c)) {
                $len = strspn($conteudo, '+-.0123456789', $i);
                $operandos[] = ['#', (float) substr($conteudo, $i, $len)];
                $i += $len;
            } elseif ($c === '<' || $c === '>') {
                $i += 2; // "<<" / ">>" de dicionários inline (ex. BDC)
            } else {
                $len = strcspn($conteudo, " \t\r\n/[]()<>{}%", $i);
                $len = max($len, 1);
                $operador = substr($conteudo, $i, $len);
                $i += $len;

                if ($operador === 'BI') {
                    // Imagem inline: salta até "EI".
                    $fim = strpos($conteudo, 'EI', $i);
                    $i = $fim === false ? $tamanho : $fim + 2;
                } elseif ($operador === 'Tf') {
                    $nome = null;
                    foreach ($operandos as $op) {
                        if ($op[0] === 'n') {
                            $nome = $op[1];
                        }
                    }
                    [$ref, $codificacao] = $nome !== null ? ($fontes[$nome] ?? [null, 'Windows-1252']) : [null, 'Windows-1252'];
                    $mapa = $ref !== null ? $this->mapaToUnicode($ref) : null;
                    $bytes = $ref !== null ? ($this->bytesPorCodigo[$ref] ?? 1) : 1;
                } elseif ($operador === 'Tj' || $operador === "'" || $operador === '"') {
                    if ($operador !== 'Tj') {
                        $texto .= "\n";
                    }
                    foreach ($operandos as $op) {
                        if ($op[0] === 's') {
                            $texto .= $this->descodificar($op[1], $mapa, $bytes, $codificacao);
                        }
                    }
                } elseif ($operador === 'TJ') {
                    foreach ($operandos as $op) {
                        if ($op[0] === 's') {
                            $texto .= $this->descodificar($op[1], $mapa, $bytes, $codificacao);
                        } elseif ($op[0] === '#' && $op[1] < -180) {
                            $texto .= ' '; // espaço grande entre palavras
                        }
                    }
                } elseif ($operador === 'T*' || $operador === 'ET') {
                    $texto .= "\n";
                } elseif ($operador === 'Td' || $operador === 'TD' || $operador === 'Tm') {
                    // Mudança de linha (deslocamento vertical) ou só avanço na mesma linha.
                    $numeros = array_values(array_filter($operandos, fn (array $op) => $op[0] === '#'));
                    $dy = $operador === 'Tm' ? ($numeros[5][1] ?? 1) : ($numeros[1][1] ?? 1);
                    $texto .= ($operador !== 'Tm' && abs((float) $dy) < 0.01) ? ' ' : "\n";
                }

                $operandos = [];
            }
        }

        return $texto;
    }

    /** @return array{0: string, 1: int} string literal "(...)" e a posição a seguir */
    private function lerLiteral(string $s, int $i): array
    {
        $valor = '';
        $nivel = 1;
        $tamanho = strlen($s);
        $escapes = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f"];

        for ($i++; $i < $tamanho; $i++) {
            $c = $s[$i];
            if ($c === '\\') {
                $seguinte = $s[$i + 1] ?? '';
                if (isset($escapes[$seguinte])) {
                    $valor .= $escapes[$seguinte];
                    $i++;
                } elseif (ctype_digit($seguinte)) {
                    $octal = substr($s, $i + 1, 3);
                    $octal = substr($octal, 0, strspn($octal, '01234567'));
                    $valor .= chr(octdec($octal) & 0xFF);
                    $i += strlen($octal);
                } elseif ($seguinte === "\r" || $seguinte === "\n") {
                    $i += ($seguinte === "\r" && ($s[$i + 2] ?? '') === "\n") ? 2 : 1;
                } else {
                    $valor .= $seguinte;
                    $i++;
                }
            } elseif ($c === '(') {
                $nivel++;
                $valor .= $c;
            } elseif ($c === ')') {
                if (--$nivel === 0) {
                    return [$valor, $i + 1];
                }
                $valor .= $c;
            } else {
                $valor .= $c;
            }
        }

        return [$valor, $i];
    }

    /** Caracteres 0x80–0xFF da codificação Mac Roman (o mbstring não a conhece). */
    private const MAC_ROMAN = 'ÄÅÇÉÑÖÜáàâäãåçéèêëíìîïñóòôöõúùûü†°¢£§•¶ß®©™´¨≠ÆØ∞±≤≥¥µ∂∑∏π∫ªºΩæø'
        . '¿¡¬√ƒ≈∆«»… ÀÃÕŒœ–—“”‘’÷◊ÿŸ⁄€‹›ﬁﬂ‡·‚„‰ÂÊÁËÈÍÎÏÌÓÔ' . "\u{F8FF}" . 'ÒÚÛÙıˆ˜¯˘˙˚¸˝˛ˇ';

    /** @param array<string,string>|null $mapa código (hex maiúsculas) => texto UTF-8 */
    private function descodificar(string $bytes, ?array $mapa, int $tamanhoCodigo, string $codificacao = 'Windows-1252'): string
    {
        if ($mapa === null && $codificacao === 'MACINTOSH') {
            $texto = '';
            foreach (str_split($bytes) as $byte) {
                $texto .= ord($byte) < 0x80 ? $byte : mb_substr(self::MAC_ROMAN, ord($byte) - 0x80, 1);
            }

            return $texto;
        }
        if ($mapa === null) {
            return (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        }

        $texto = '';
        foreach (str_split($bytes, $tamanhoCodigo) as $codigo) {
            $texto .= $mapa[strtoupper(bin2hex($codigo))] ?? '';
        }

        return $texto;
    }

    /** @return array<string,string>|null */
    private function mapaToUnicode(int $ref): ?array
    {
        if (array_key_exists($ref, $this->mapas)) {
            return $this->mapas[$ref];
        }

        $cmap = $this->dadosStream($this->objetos[$ref] ?? '');
        if ($cmap === null) {
            return $this->mapas[$ref] = null;
        }

        $mapa = [];
        $bytes = 1;

        if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cmap, $m)) {
            $bytes = max(1, intdiv(strlen($m[1]), 2));
        }

        preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocos);
        foreach ($blocos[1] as $bloco) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $bloco, $pares, PREG_SET_ORDER);
            foreach ($pares as [, $codigo, $unicode]) {
                $mapa[strtoupper($codigo)] = self::utf16($unicode);
            }
        }

        preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocos);
        foreach ($blocos[1] as $bloco) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])/', $bloco, $linhas, PREG_SET_ORDER);
            foreach ($linhas as [, $inicio, $fim, $destino]) {
                $largura = strlen($inicio);
                $de = hexdec($inicio);
                $ate = min(hexdec($fim), $de + 0xFFFF);
                if (str_starts_with($destino, '[')) {
                    preg_match_all('/<([0-9A-Fa-f]*)>/', $destino, $lista);
                    foreach ($lista[1] as $k => $unicode) {
                        $mapa[strtoupper(str_pad(dechex($de + $k), $largura, '0', STR_PAD_LEFT))] = self::utf16($unicode);
                    }
                } else {
                    $base = hexdec(substr($destino, 1, -1));
                    for ($codigo = $de; $codigo <= $ate; $codigo++) {
                        $mapa[strtoupper(str_pad(dechex($codigo), $largura, '0', STR_PAD_LEFT))]
                            = self::utf16(dechex($base + $codigo - $de));
                    }
                }
            }
        }

        $this->bytesPorCodigo[$ref] = $bytes;

        return $this->mapas[$ref] = $mapa;
    }

    private static function utf16(string $hex): string
    {
        if ($hex === '') {
            return '';
        }
        $hex = str_pad($hex, (int) ceil(strlen($hex) / 4) * 4, '0', STR_PAD_LEFT);

        return (string) mb_convert_encoding((string) hex2bin($hex), 'UTF-8', 'UTF-16BE');
    }
}
