<?php

declare(strict_types=1);

/**
 * Coordenadas de uma localidade portuguesa, pelo Nominatim (pesquisa de
 * moradas do OpenStreetMap, gratuito). Regras de uso: no máximo 1 pedido por
 * segundo e um User-Agent que identifique a aplicação — por isso tem o seu
 * próprio HttpClient, de 1,1 s em 1,1 s.
 *
 * O local vem como as fontes o escrevem ("Vermoil, Leiria", "Rio Tinto /
 * Gondomar", "Bonfim / Porto"): tenta-se o texto todo e depois só a primeira
 * parte, sempre com o distrito (DistritoHelper) quando se sabe, para não
 * confundir localidades com o mesmo nome noutro distrito.
 */
final class Geocodificador
{
    private const URL = 'https://nominatim.openstreetmap.org/search';
    private const URL_INVERSA = 'https://nominatim.openstreetmap.org/reverse';
    private const USER_AGENT = 'User-Agent: CalendarioCorridas/1.0 (+https://corridas.eu)';

    private readonly HttpClient $http;

    public function __construct()
    {
        $this->http = new HttpClient(15, false, 1100);
    }

    /**
     * @return array{0: float, 1: float}|null [latitude, longitude]
     * @throws RuntimeException quando o Nominatim recusa pedidos (HTTP 429/403)
     */
    public function coordenadas(string $local, ?string $distrito): ?array
    {
        // Provas no estrangeiro ("Sevilha, Espanha"): a pesquisa só procura em
        // Portugal e acabaria por dar uma localidade portuguesa qualquer.
        if (preg_match('/\b(?:espanha|espa[nñ]a|spain|fran[cç]a|france|brasil|brazil|marrocos|it[aá]lia|alemanha|reino unido|inglaterra|'
            . 'irlanda|holanda|pa[ií]ses baixos|b[eé]lgica|luxemburgo|su[ií][cç]a|[aá]ustria|gr[eé]cia|turquia|pol[oó]nia|dinamarca|su[eé]cia|'
            . 'noruega|finl[aâ]ndia|isl[aâ]ndia|hungria|ch[eé]quia|estados unidos|eua|usa|canad[aá]|m[eé]xico|argentina|chile|jap[aã]o|china|'
            . 'qu[eé]nia|eti[oó]pia|emirados|dubai|[aá]frica do sul|andorra|cabo verde|angola|mo[cç]ambique)\b/iu', $local)) {
            return null;
        }
        foreach (self::tentativas($local, $distrito) as $pesquisa) {
            try {
                $resposta = $this->http->get(self::URL . '?' . http_build_query([
                    'q' => $pesquisa,
                    'countrycodes' => 'pt',
                    'format' => 'jsonv2',
                    'limit' => 1,
                ]), [self::USER_AGENT]);
            } catch (RuntimeException $e) {
                if (in_array($e->getCode(), [HttpClient::DEMASIADOS_PEDIDOS], true) || str_contains($e->getMessage(), 'HTTP 403')) {
                    throw $e;
                }
                continue;
            }
            $resultado = json_decode($resposta, true)[0] ?? null;
            if (isset($resultado['lat'], $resultado['lon'])) {
                return [(float) $resultado['lat'], (float) $resultado['lon']];
            }
        }

        return null;
    }

    /**
     * Localidade de umas coordenadas, para as fontes que só dão o ponto no
     * mapa (ex. Sinctime): "Silvares, Guimarães" (localidade e concelho) ou só
     * o concelho quando é a mesma. Null se não se encontrar.
     *
     * @throws RuntimeException quando o Nominatim recusa pedidos (HTTP 429/403)
     */
    public function localidade(float $latitude, float $longitude): ?string
    {
        try {
            $resposta = $this->http->get(self::URL_INVERSA . '?' . http_build_query([
                'lat' => $latitude,
                'lon' => $longitude,
                'zoom' => 14,
                'format' => 'jsonv2',
                'accept-language' => 'pt-PT',
            ]), [self::USER_AGENT]);
        } catch (RuntimeException $e) {
            if (in_array($e->getCode(), [HttpClient::DEMASIADOS_PEDIDOS], true) || str_contains($e->getMessage(), 'HTTP 403')) {
                throw $e;
            }

            return null;
        }
        $morada = json_decode($resposta, true)['address'] ?? [];
        $localidade = $morada['village'] ?? $morada['town'] ?? $morada['city'] ?? $morada['suburb'] ?? null;
        $concelho = $morada['municipality'] ?? $morada['city'] ?? $morada['town'] ?? null;
        if ($localidade === null) {
            return $concelho;
        }

        return $concelho !== null && $concelho !== $localidade ? "{$localidade}, {$concelho}" : $localidade;
    }

    /** @return string[] */
    private static function tentativas(string $local, ?string $distrito): array
    {
        $local = trim(preg_replace('/\s+/u', ' ', $local));
        $sufixo = ($distrito !== null ? ', ' . $distrito : '') . ', Portugal';
        $partes = array_values(array_filter(array_map('trim', preg_split('#\s*[/,(–]\s*|\s+-\s+#u', $local)), 'strlen'));

        $tentativas = [$local . $sufixo];
        // Primeiro a última parte, que costuma ser a localidade ("Parque Urbano da
        // Cidade, Ponta Delgada" → "Ponta Delgada"; "Quinta Maria Pires - Amora" →
        // "Amora"); depois a primeira ("Rio Tinto / Gondomar" → "Rio Tinto").
        foreach ([end($partes) ?: '', $partes[0] ?? ''] as $parte) {
            if ($parte !== '' && $parte !== $local) {
                $tentativas[] = $parte . $sufixo;
            }
        }

        return array_values(array_unique($tentativas));
    }

    /**
     * Localiza as provas futuras ainda sem coordenadas (Database::provasSemCoordenadas),
     * reaproveitando as de outra prova com o mesmo local. Pára se o Nominatim
     * recusar pedidos (continua na execução seguinte).
     *
     * @param callable(int, ?array): void|null $aoVerProva
     * @return array{vistas: int, encontradas: int, recusado: ?string}
     */
    public function localizarProvas(Database $db, int $limite, ?callable $aoVerProva = null): array
    {
        $contagem = ['vistas' => 0, 'encontradas' => 0, 'recusado' => null];
        foreach ($db->provasSemCoordenadas($limite) as $prova) {
            $ponto = $db->coordenadasDoLocal($prova['local']);
            if ($ponto === null) {
                try {
                    $distrito = DistritoHelper::localizar($prova['local'], $prova['regiao'])['distrito'];
                    $ponto = $this->coordenadas($prova['local'], $distrito);
                } catch (RuntimeException $e) {
                    $contagem['recusado'] = $e->getMessage();
                    break;
                }
            }
            $db->gravarCoordenadas((int) $prova['id'], $ponto);
            $contagem['vistas']++;
            $contagem['encontradas'] += $ponto !== null ? 1 : 0;
            if ($aoVerProva !== null) {
                $aoVerProva((int) $prova['id'], $ponto);
            }
        }

        return $contagem;
    }

    /** Distância em km entre dois pontos (fórmula de Haversine). */
    public static function distanciaKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($a)));
    }
}
