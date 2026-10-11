<?php

declare(strict_types=1);

/**
 * Recolhe provas de corrida da plataforma de inscrições e cronometragem Lap2Go.
 *
 * A página /pt/event/list traz todos os eventos (passados e futuros) como
 * <a class="event-item" data-day="25-10-2026" data-name="..." data-city="...">,
 * com ícones de modalidade (type-run, type-walk, type-bike...). O ícone de
 * corrida também marca trails, provas híbridas e de obstáculos, por isso ficam
 * só as provas futuras com esse ícone cujo nome parece de estrada.
 */
final class Lap2GoScraper implements ScraperInterface
{
    private const BASE_URL = 'https://lap2go.com';
    private const LISTA_URL = self::BASE_URL . '/pt/event/list';

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'Lap2Go';
    }

    public function scrape(): array
    {
        $xpath = $this->criarXPath($this->http->get(self::LISTA_URL));
        $itens = $xpath->query('//a[contains(concat(" ", @class, " "), " event-item ")]');

        if ($itens->length === 0) {
            throw new RuntimeException('Estrutura inesperada em ' . self::LISTA_URL . ' (lista de eventos não encontrada)');
        }

        $hoje = date('Y-m-d');
        $corridas = [];

        foreach ($itens as $item) {
            if (!preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $item->getAttribute('data-day'), $m)) {
                continue;
            }
            $dataProva = "{$m[3]}-{$m[2]}-{$m[1]}";

            $nome = trim($item->getAttribute('data-name'));
            $eCorrida = $xpath->query('.//div[contains(@class,"type-run")]', $item)->length > 0;

            if ($dataProva < $hoje || $nome === '' || !$eCorrida || !NomeProvaHelper::pareceEstrada($nome)) {
                continue;
            }

            $local = trim($item->getAttribute('data-city'));
            $href = $item->getAttribute('href');

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $dataProva,
                local: $local !== '' ? $local : null,
                distancias: null,
                tipo: null,
                regiao: null,
                urlEvento: $href !== '' ? self::BASE_URL . $href : null,
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
