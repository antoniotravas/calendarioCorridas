<?php

declare(strict_types=1);

/**
 * Recolhe as próximas provas organizadas pela Xistarca (região de Lisboa).
 *
 * A página /eventos tem uma lista simples (li.event-feature) com data
 * ("18 de Outubro - / 09:30", sem ano), nome, local e o link para a página do
 * evento (no atributo onclick). A Xistarca organiza sobretudo corridas de
 * estrada, por isso só se excluem as provas que pelo nome são outra modalidade.
 */
final class XistarcaScraper implements ScraperInterface, FonteOrganizadora
{
    private const LISTA_URL = 'https://xistarca.pt/eventos/';

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'Xistarca';
    }

    public function scrape(): array
    {
        $xpath = $this->criarXPath($this->http->get(self::LISTA_URL));
        $itens = $xpath->query('//div[contains(@class,"event-list")]//li[contains(@class,"event-feature")]');

        if ($itens->length === 0) {
            throw new RuntimeException('Estrutura inesperada em ' . self::LISTA_URL . ' (lista de eventos não encontrada)');
        }

        $corridas = [];

        foreach ($itens as $item) {
            $nome = trim($xpath->query('.//div[@class="event-title"]', $item)->item(0)?->textContent ?? '');
            $dataTexto = trim($xpath->query('.//div[@class="event-date"]', $item)->item(0)?->textContent ?? '');
            $local = trim($xpath->query('.//div[@class="event-location"]', $item)->item(0)?->textContent ?? '');

            if ($nome === '' || NomeProvaHelper::eOutraModalidade($nome)) {
                continue;
            }

            $dataProva = null;
            if (preg_match('/(\d{1,2})\s+de\s+([a-zA-Zà-úÀ-Ú]+)/u', $dataTexto, $m)) {
                $dataProva = DateHelper::proximaOcorrencia((int) $m[1], $m[2]);
            }

            $urlEvento = null;
            if (preg_match("/window\\.location='([^']+)'/", $item->getAttribute('onclick'), $m)) {
                $urlEvento = $m[1];
            }

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $dataProva,
                local: $local !== '' ? $local : null,
                distancias: null,
                tipo: 'Estrada',
                regiao: null,
                urlEvento: $urlEvento,
                fonte: $this->nome(),
                urlFonte: self::LISTA_URL,
            );
        }

        return $corridas;
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
