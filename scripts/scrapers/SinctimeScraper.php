<?php

declare(strict_types=1);

/**
 * Recolhe provas de corrida da plataforma de inscrições e cronometragem
 * Sinctime (sobretudo Minho: São Silvestres de Famalicão, Viana do Castelo,
 * trails de Guimarães...).
 *
 * A listagem /proximos-eventos aceita a categoria na query string
 * (categoria=Estrada — "Atletismo" no site — e categoria=Trail) e pagina
 * com pagina=N (8 eventos por página, link "Seg" para a seguinte). Cada
 * evento tem o título, a data ("31 out 2026") e o cartaz; a localidade não
 * aparece em lado nenhum, só a latitude e a longitude na página do evento,
 * que se convertem numa localidade pelo OpenStreetMap
 * (Geocodificador::localidade). Caminhadas excluídas pelo nome.
 */
final class SinctimeScraper implements ScraperInterface, FonteOrganizadora
{
    private const BASE_URL = 'https://www.sinctime.com';
    /** @var array<string,?string> categoria do site => tipo de prova */
    private const CATEGORIAS = ['Estrada' => null, 'Trail' => 'Trail'];
    /** Páginas por categoria, para não ficar preso se a paginação mudar. */
    private const MAX_PAGINAS = 10;

    private ?Geocodificador $geocodificador = null;

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'Sinctime';
    }

    public function scrape(): array
    {
        $corridas = [];
        $vistos = [];

        foreach (self::CATEGORIAS as $categoria => $tipo) {
            for ($pagina = 1; $pagina <= self::MAX_PAGINAS; $pagina++) {
                $url = self::BASE_URL . '/proximos-eventos?' . http_build_query(['pagina' => $pagina, 'categoria' => $categoria]);
                $xpath = $this->criarXPath($this->http->get($url));

                if ($xpath->query('//form[@id="filterForm"]')->length === 0) {
                    throw new RuntimeException("Estrutura inesperada em {$url} (filtro dos eventos não encontrado)");
                }

                foreach ($xpath->query('//div[contains(@class,"thumbnailContent")]') as $evento) {
                    $link = $xpath->query('.//h3/a/@href', $evento)->item(0)?->nodeValue ?? '';
                    $nome = $this->texto($xpath, './/h3/a', $evento);
                    $dataProva = DateHelper::parseTextoLivre($this->texto($xpath, './/ul/li[1]', $evento));

                    if ($link === '' || isset($vistos[$link]) || $nome === '' || NomeProvaHelper::eOutraModalidade($nome)) {
                        continue;
                    }
                    $vistos[$link] = true;
                    $urlEvento = self::BASE_URL . $link;
                    $imagem = $xpath->query('.//div[@class="event-image"]/img/@src', $evento)->item(0)?->nodeValue;

                    $corridas[] = new Corrida(
                        nome: $nome,
                        dataProva: $dataProva,
                        local: $this->localidade($urlEvento),
                        distancias: null,
                        tipo: $tipo,
                        regiao: null,
                        urlEvento: $urlEvento,
                        fonte: $this->nome(),
                        urlFonte: self::BASE_URL . '/proximos-eventos?categoria=' . $categoria,
                        imagemUrl: $imagem !== null ? self::BASE_URL . $imagem : null,
                    );
                }

                if ($xpath->query('//ul[@class="pager"]/li[@class="next"]')->length === 0) {
                    break;
                }
            }
        }

        return $corridas;
    }

    /** Localidade a partir das coordenadas da página do evento; null se não der. */
    private function localidade(string $urlEvento): ?string
    {
        try {
            $texto = $this->texto($this->criarXPath($this->http->get($urlEvento)), '//body', null);
        } catch (RuntimeException) {
            return null;
        }
        if (!preg_match('/Latitude:\s*(-?\d+\.\d+)\s*Longitude:\s*(-?\d+\.\d+)/', $texto, $m)) {
            return null;
        }
        $this->geocodificador ??= new Geocodificador();
        try {
            return $this->geocodificador->localidade((float) $m[1], (float) $m[2]);
        } catch (RuntimeException) {
            return null; // o OpenStreetMap recusou: fica sem localidade (outra fonte pode dá-la)
        }
    }

    private function texto(DOMXPath $xpath, string $consulta, ?DOMNode $contexto): string
    {
        $texto = $xpath->query($consulta, $contexto)->item(0)?->textContent ?? '';

        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $texto));
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
