<?php

declare(strict_types=1);

/**
 * Recolhe as provas listadas em portugalrunning.com (apesar do endereço, a página inclui trails, corta-mato e outras modalidades).
 *
 * A página só mostra os eventos do mês corrente e do seguinte (calendário
 * "Modern Events Calendar"); para cobrir mais meses seria necessário navegar
 * o calendário por mês, o que fica fora do âmbito desta primeira fase.
 *
 * O tipo, a região e as distâncias são inferidos a partir das classes CSS de
 * categorização do site (ex: "evo_corrida-10-km", "evo_algarve-e-sul"), pelo
 * que podem ficar vazios para eventos com etiquetagem atípica.
 *
 * A listagem só tem a página da prova na Portugal Running (um calendário,
 * não o organizador): o link oficial está nessa página (PREFIXO_PAGINA_PROVA
 * + extrairLinkOficial) e o link da prova passa a ser o oficial, como na
 * All4Running (ver run_scraper.php).
 */
final class PortugalRunningScraper implements ScraperInterface
{
    private const URL = 'https://www.portugalrunning.com/calendario-de-corridas-de-estrada/';

    /** Endereço das páginas de cada prova no site. */
    public const PREFIXO_PAGINA_PROVA = 'https://www.portugalrunning.com/eventos/';

    /** @var array<string,string> classes evo_* que identificam o tipo de prova */
    private const TIPOS = [
        'corrida' => 'Corrida',
        'caminhada' => 'Caminhada',
        'trail' => 'Trail',
        'hyrox' => 'Hyrox',
    ];

    /** classes evo_* que nunca são região (tipo, distância ou marcador genérico) */
    private const EXCLUIR_DE_REGIAO = ['corrida', 'caminhada', 'trail', 'hyrox', 'portugal'];

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'Portugal Running';
    }

    public function scrape(): array
    {
        $html = $this->http->get(self::URL);
        $xpath = self::criarXPath($html);

        $cards = $xpath->query('//a[contains(@class,"desc_trig") and contains(@class,"evcal_list_a")]');

        $corridas = [];

        foreach ($cards as $card) {
            $nomeNode = $xpath->query('.//span[contains(@class,"evoet_title")]', $card)->item(0);
            if ($nomeNode === null) {
                continue;
            }
            $nome = trim($nomeNode->textContent);

            $urlEvento = trim($card->getAttribute('href'));

            $dayblock = $xpath->query('.//span[contains(@class,"evoet_dayblock")]', $card)->item(0);
            $diaNode = $xpath->query('.//em[@class="date"]', $card)->item(0);

            $dataProva = null;
            if ($dayblock !== null && $diaNode !== null) {
                $mes = $dayblock->getAttribute('data-smon');
                $ano = $dayblock->getAttribute('data-syr');
                $dia = (int) trim($diaNode->textContent);
                if ($mes !== '' && $ano !== '' && $dia > 0) {
                    $dataProva = DateHelper::toIso($dia, $mes, (int) $ano);
                }
            }

            $localNode = $xpath->query('.//span[contains(@class,"event_location_attrs")]', $card)->item(0);
            $local = $localNode?->getAttribute('data-location_address');

            [$tipo, $regiao, $distancias] = $this->interpretarClasses($card->getAttribute('class'));

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $dataProva,
                local: $local !== null && $local !== '' ? $local : null,
                distancias: $distancias,
                tipo: $tipo,
                regiao: $regiao,
                urlEvento: $urlEvento !== '' ? $urlEvento : null,
                fonte: $this->nome(),
                urlFonte: self::URL,
            );
        }

        return $corridas;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [tipo, regiao, distancias]
     */
    private function interpretarClasses(string $classAttr): array
    {
        preg_match_all('/\bevo_([a-z0-9-]+)/', $classAttr, $matches);
        $tags = $matches[1] ?? [];

        $tipos = [];
        $regioes = [];

        foreach ($tags as $tag) {
            if (isset(self::TIPOS[$tag])) {
                $tipos[self::TIPOS[$tag]] = true;
                continue;
            }

            if (str_starts_with($tag, 'corrida-') || str_starts_with($tag, 'corridas-')) {
                continue;
            }

            if (in_array($tag, self::EXCLUIR_DE_REGIAO, true)) {
                continue;
            }

            $regioes[] = ucwords(str_replace('-', ' ', $tag));
        }

        $distancias = [];
        if (preg_match_all('/evo_corrida-([0-9]+(?:-[0-9]+)?)-km/', $classAttr, $mDist)) {
            foreach ($mDist[1] as $valor) {
                $distancias[] = str_replace('-', ' a ', $valor) . ' km';
            }
        }

        return [
            $tipos !== [] ? implode(', ', array_keys($tipos)) : null,
            $regioes !== [] ? implode(', ', array_unique($regioes)) : null,
            $distancias !== [] ? implode(', ', array_unique($distancias)) : null,
        ];
    }

    /**
     * Link oficial da prova na página da Portugal Running: o botão "saber mais"
     * do calendário (EventON, a.evo_clik_row — o site da prova ou a página de
     * inscrições); senão, o "Regulamento Oficial".
     */
    public static function extrairLinkOficial(string $html): ?string
    {
        $xpath = self::criarXPath($html);

        foreach ([
            '//a[contains(@class,"evo_clik_row")]/@href',
            '//strong[contains(normalize-space(.),"Regulamento")]/following-sibling::span[1]//a/@href',
        ] as $consulta) {
            foreach ($xpath->query($consulta) as $atributo) {
                $url = trim(html_entity_decode($atributo->value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (preg_match('#^https?://#i', $url) && !preg_match('#^https?://(www\.)?portugalrunning\.com#i', $url)
                    && strlen($url) <= 500
                ) {
                    return $url;
                }
            }
        }

        return null;
    }

    /** "Entidade Organizadora" na página da prova (ex. "Futebol Clube do Porto"), ou null. */
    public static function extrairOrganizador(string $html): ?string
    {
        $no = self::criarXPath($html)
            ->query('//strong[normalize-space(.)="Entidade Organizadora"]/following-sibling::span[1]')
            ->item(0);
        $nome = $no !== null ? trim((string) preg_replace('/\s+/u', ' ', $no->textContent)) : '';

        return $nome !== '' && mb_strlen($nome) <= 200 ? $nome : null;
    }

    private static function criarXPath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
