<?php

declare(strict_types=1);

/**
 * Recolhe as provas organizadas pela ProEvents (região do Porto: Corrida do
 * Carnaval, Corrida da Liberdade, São Silvestre de Vila do Conde, Sousões Trail...).
 *
 * O site é uma aplicação Angular sem as provas no HTML: a lista está escrita
 * num dos ficheiros JavaScript carregados pela página inicial, como objetos
 * {id:"...",slug:"...",name:"...",date:"2026-12-19",location:"...",
 * distances:["Corrida 10 km","Caminhada 5 km"],category:"running",status:"upcoming",...}.
 * As inscrições de cada prova são numa página própria da Status Marathon
 * (mapa slug => URL no mesmo ficheiro), que é o link usado para a prova.
 * Os nomes dos ficheiros mudam a cada atualização do site, por isso
 * procuram-se a partir do HTML da página inicial.
 */
final class ProEventsScraper implements ScraperInterface, FonteOrganizadora
{
    private const BASE_URL = 'https://www.proevents.pt';
    /** @var array<string,?string> categoria do site => tipo de prova */
    private const CATEGORIAS = ['running' => null, 'trail' => 'Trail'];

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'ProEvents';
    }

    public function scrape(): array
    {
        $js = $this->ficheiroComEventos();
        $linksInscricao = [];
        if (preg_match_all('#"([a-z0-9-]+)":"(https://[a-z0-9.-]+\.statusmarathon\.[a-z]+/?)"#', $js, $m, PREG_SET_ORDER)) {
            foreach ($m as $par) {
                $linksInscricao[$par[1]] = $par[2];
            }
        }

        $hoje = date('Y-m-d');
        $corridas = [];

        // Cada evento começa em {id:"..." e vai até ao seguinte.
        $blocos = preg_split('/(?=\{id:")/', $js);
        foreach (array_slice($blocos, 1) as $bloco) {
            $campo = fn (string $nome): ?string => preg_match('/\b' . $nome . ':"((?:[^"\\\\]|\\\\.)*)"/', $bloco, $c)
                ? self::textoJs($c[1]) : null;

            $categoria = $campo('category');
            $nome = $campo('name');
            $dataProva = $campo('date');
            $slug = $campo('slug');

            if ($nome === null || $slug === null || !array_key_exists((string) $categoria, self::CATEGORIAS)
                || $campo('status') === 'finished' || $dataProva === null || $dataProva < $hoje
                || NomeProvaHelper::eOutraModalidade($nome)
            ) {
                continue;
            }

            // Distâncias sem as caminhadas: ["Trail 31 km","Trail 17 km","Caminhada 11 km"] => "31 km, 17 km".
            $distancias = [];
            if (preg_match('/\bdistances:\[([^\]]*)\]/', $bloco, $c) && preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $c[1], $itens)) {
                foreach ($itens[1] as $item) {
                    $item = self::textoJs($item);
                    if (!preg_match('/caminhada|marcha|walk/i', $item) && preg_match('/(\d+(?:[.,]\d+)?\s*km)/i', $item, $km)) {
                        $distancias[] = $km[1];
                    }
                }
            }

            $hora = $campo('startTime');
            $imagem = $campo('image');
            $local = $campo('location');

            // "São Silvestre 2026" (de Vila do Conde): sem o local no nome não se
            // juntaria à mesma prova encontrada noutras fontes.
            if ($local !== null && preg_match('/^(s[aã]o|s\.)\s+silvestre(\s+\d{4})?$/iu', $nome, $c)) {
                $nome = preg_replace('/\s+\d{4}$/', '', $nome) . ' de ' . preg_replace('#\s*/.*$#', '', $local) . ($c[2] ?? '');
            }

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $dataProva,
                local: $local,
                distancias: $distancias !== [] ? implode(', ', array_unique($distancias)) : null,
                tipo: self::CATEGORIAS[$categoria],
                regiao: null,
                urlEvento: $linksInscricao[$slug] ?? self::BASE_URL . '/eventos/' . $slug,
                fonte: $this->nome(),
                urlFonte: self::BASE_URL . '/eventos/' . $slug,
                imagemUrl: $imagem !== null ? self::BASE_URL . $imagem : null,
                horaInicio: $hora !== null && preg_match('/^\d{2}:\d{2}$/', $hora) ? $hora : null,
                organizador: 'ProEvents',
                organizadorUrl: self::BASE_URL,
            );
        }

        return $corridas;
    }

    /** Ficheiro JavaScript (dos carregados pela página inicial) que tem a lista de eventos. */
    private function ficheiroComEventos(): string
    {
        $html = $this->http->get(self::BASE_URL . '/');
        preg_match_all('#"/((?:main|chunk)-[A-Z0-9]+\.js)"#', $html, $m);

        foreach (array_unique($m[1]) as $ficheiro) {
            $js = $this->http->get(self::BASE_URL . '/' . $ficheiro);
            if (str_contains($js, 'category:"running"')) {
                return $js;
            }
        }

        throw new RuntimeException('Estrutura inesperada em ' . self::BASE_URL . ' (lista de eventos não encontrada nos ficheiros JavaScript)');
    }

    /** Texto de uma string JavaScript: "S\xE3o Silvestre" => "São Silvestre". */
    private static function textoJs(string $texto): string
    {
        $texto = preg_replace('/\\\\x([0-9A-Fa-f]{2})/', '\\u00$1', $texto);

        return trim((string) json_decode('"' . $texto . '"'));
    }
}
