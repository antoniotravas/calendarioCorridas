<?php

declare(strict_types=1);

/**
 * Recolhe provas da Federación Aragonesa de Atletismo (federacionaragonesadeatletismo.com).
 *
 * A página "Calendario y Resultados FAA" tem a lista de provas em HTML (ul.list):
 * cada item é um link para a página da prova, com a data (dd/mm/aaaa), o nome,
 * o tipo ("Ruta", "Campo a Través", "Montaña-Trail", "Pista Aire Libre"...) e o
 * local. Lê-se a lista da página, sem filtros por mês.
 *
 * Provas de marcha, "Otros" e provas sem data reconhecida ficam de fora.
 * Não se sabe a província (a página não a indica), por isso o distrito fica vazio.
 */
final class FaaAragonScraper implements ScraperInterface
{
    private const LISTA_URL = 'https://www.federacionaragonesadeatletismo.com/competiciones/calendario-y-resultados-faa/';

    /** Tipo da FAA => tipo que o NomeProvaHelper reconhece (null = decide pelo nome). */
    private const TIPOS = [
        'Ruta' => 'Estrada',
        'Campo a Través' => 'Corta-mato',
        'Montaña-Trail' => 'Trail',
        'Pista Aire Libre' => 'Pista',
        'Pista Cubierta' => 'Pista',
    ];

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'FAA Aragón';
    }

    public function scrape(): array
    {
        $xpath = $this->criarXPath($this->http->get(self::LISTA_URL));
        $itens = $xpath->query('//ul[contains(@class, "list")]/li/a[.//div[contains(@class, "entri")]]');
        if ($itens->length === 0) {
            throw new RuntimeException('Estrutura inesperada em ' . self::LISTA_URL . ' (lista de provas não encontrada)');
        }

        $corridas = [];
        foreach ($itens as $item) {
            $tipoTexto = $this->texto($xpath->query('.//span[contains(@class, "pruebad")]', $item)->item(0));
            if (!isset(self::TIPOS[$tipoTexto])) {
                continue;
            }

            $nome = $this->texto($xpath->query('.//span[contains(@class, "named")]', $item)->item(0));
            $data = $this->dataDoTexto($this->texto($xpath->query('.//div[contains(@class, "fecha")]', $item)->item(0)));
            $local = $this->texto($xpath->query('.//span[contains(@class, "lugard")]', $item)->item(0));
            $url = $item->getAttribute('href');
            if ($nome === '' || $data === null) {
                continue;
            }

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $data,
                local: $local !== '' ? $local : null,
                distancias: null,
                tipo: self::TIPOS[$tipoTexto],
                regiao: 'Aragón',
                urlEvento: $url !== '' ? $url : null,
                fonte: $this->nome(),
                urlFonte: self::LISTA_URL,
                organizador: null,
                organizadorUrl: null,
                pais: 'ES',
            );
        }

        return $corridas;
    }

    /** "01/11/2026" => "2026-11-01". */
    private function dataDoTexto(string $texto): ?string
    {
        if (!preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $texto, $m)) {
            return null;
        }
        [, $dia, $mes, $ano] = $m;
        if (!checkdate((int) $mes, (int) $dia, (int) $ano)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $ano, (int) $mes, (int) $dia);
    }

    private function texto(?DOMNode $no): string
    {
        $texto = $no?->textContent ?? '';

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
