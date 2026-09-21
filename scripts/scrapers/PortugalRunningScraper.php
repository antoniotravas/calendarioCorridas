<?php

declare(strict_types=1);

/**
 * Recolhe provas de estrada listadas em portugalrunning.com.
 *
 * A página só mostra os eventos do mês corrente e do seguinte (calendário
 * "Modern Events Calendar"); para cobrir mais meses seria necessário navegar
 * o calendário por mês, o que fica fora do âmbito desta primeira fase.
 *
 * O tipo, a região e as distâncias são inferidos a partir das classes CSS de
 * categorização do site (ex: "evo_corrida-10-km", "evo_algarve-e-sul"), pelo
 * que podem ficar vazios para eventos com etiquetagem atípica.
 */
final class PortugalRunningScraper implements ScraperInterface
{
    private const URL = 'https://www.portugalrunning.com/calendario-de-corridas-de-estrada/';

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
        $xpath = $this->criarXPath($html);

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

    private function criarXPath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
