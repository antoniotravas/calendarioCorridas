<?php

declare(strict_types=1);

/**
 * Recolhe provas de estrada do calendário da Associação Distrital de Atletismo
 * de Leiria (ADAL).
 *
 * A página lista só os eventos futuros, sem tipo de prova nem local: o tipo é
 * deduzido do nome (NomeProvaHelper::pareceEstrada) para excluir trails,
 * marcha, convívios de formação, etc. A data completa vem no bloco "Data"
 * ("03 de Outubro de 2026", às vezes seguida de uma segunda data que se ignora),
 * e o link "Programa" aponta para o PDF do regulamento da prova.
 */
final class AdalLeiriaScraper implements ScraperInterface
{
    private const BASE_URL = 'https://www.adal.pt/';
    private const CALENDARIO_URL = self::BASE_URL . 'calendario.php';

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'ADA Leiria';
    }

    public function scrape(): array
    {
        $html = $this->http->get(self::CALENDARIO_URL);
        $xpath = $this->criarXPath($html);

        $itens = $xpath->query('//div[contains(@class,"event-listing")]//li[.//div[contains(@class,"experience-main")]]');
        if ($itens->length === 0) {
            throw new RuntimeException('Estrutura inesperada em ' . self::CALENDARIO_URL . ' (nenhum evento encontrado)');
        }

        $hoje = date('Y-m-d');
        $corridas = [];

        foreach ($itens as $item) {
            $nome = trim((string) preg_replace(
                '/\s+/u',
                ' ',
                $xpath->query('.//div[contains(@class,"news-title")]', $item)->item(0)?->textContent ?? ''
            ));
            if ($nome === '' || !NomeProvaHelper::pareceEstrada($nome)) {
                continue;
            }

            $textoData = $xpath->query('.//p[contains(@class,"event-text")]', $item)->item(0)?->textContent ?? '';
            $dataProva = DateHelper::parseTextoLivre($textoData);
            if ($dataProva !== null && $dataProva < $hoje) {
                continue;
            }

            $programa = $xpath->query('.//a[contains(normalize-space(.),"Programa")]', $item)->item(0);
            $urlEvento = $programa !== null ? $this->urlAbsoluta(trim($programa->getAttribute('href'))) : null;

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $dataProva,
                local: null,
                distancias: null,
                tipo: 'Estrada',
                regiao: 'Leiria',
                urlEvento: $urlEvento,
                fonte: $this->nome(),
                urlFonte: self::CALENDARIO_URL,
            );
        }

        return $corridas;
    }

    /**
     * Os links dos PDFs são relativos e têm espaços/acentos por codificar.
     */
    private function urlAbsoluta(string $href): ?string
    {
        if ($href === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return str_replace(' ', '%20', $href);
        }

        $caminho = implode('/', array_map('rawurlencode', explode('/', ltrim($href, '/'))));

        return self::BASE_URL . $caminho;
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
