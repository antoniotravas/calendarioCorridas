<?php

declare(strict_types=1);

/**
 * Recolhe provas da plataforma de inscrições e cronometragem da Associação de
 * Atletismo do Algarve (crono.aaalgarve.org).
 *
 * A página /eventos tem os próximos eventos, cada um num div.evento com a
 * data (div.dia + "dez<br>2026"), o título, a categoria ("Trail",
 * "Meia Maratona", "Corrida"...), a localidade e o cartaz; a inscrição é em
 * /inscrever/{slug}. O site principal (aaalgarve.org, Joomla) só tem os
 * regulamentos em PDF e ligações para esta plataforma.
 */
final class AAAlgarveScraper implements ScraperInterface, FonteOrganizadora
{
    private const BASE_URL = 'https://www.crono.aaalgarve.org';
    private const LISTA_URL = self::BASE_URL . '/eventos';

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'AA Algarve';
    }

    public function scrape(): array
    {
        $xpath = $this->criarXPath($this->http->get(self::LISTA_URL));
        $eventos = $xpath->query('//div[contains(@class,"eventoContainer")]/div[@class="evento"]');
        if ($eventos->length === 0) {
            throw new RuntimeException('Estrutura inesperada em ' . self::LISTA_URL . ' (eventos não encontrados)');
        }

        $corridas = [];
        foreach ($eventos as $evento) {
            $nome = $this->texto($xpath, './/div[@class="titulo"]', $evento);
            $categoria = $this->texto($xpath, './/div[@class="categoria"]', $evento);
            if ($nome === '' || NomeProvaHelper::eOutraModalidade($nome) || NomeProvaHelper::eOutraModalidade($categoria)) {
                continue;
            }

            // <div class="data"><div class="dia">13</div>dez<br />2026</div>
            $dia = (int) $this->texto($xpath, './/div[@class="dia"]', $evento);
            $resto = $xpath->query('.//div[@class="data"]/text()', $evento);
            $partes = array_values(array_filter(array_map(fn (DOMNode $n) => trim($n->textContent), iterator_to_array($resto))));
            $dataProva = count($partes) >= 2 ? DateHelper::toIso($dia, $partes[0], (int) $partes[1]) : null;

            $link = $xpath->query('./a/@href', $evento)->item(0)?->nodeValue ?? '';
            $imagem = $xpath->query('.//div[@class="imagem"]/img/@src', $evento)->item(0)?->nodeValue;
            $local = $this->texto($xpath, './/div[@class="local"]', $evento);

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $dataProva,
                local: $local !== '' ? $local : null,
                distancias: null,
                tipo: $categoria !== '' ? $categoria : null,
                regiao: 'Algarve',
                urlEvento: $link !== '' ? self::BASE_URL . $link : null,
                fonte: $this->nome(),
                urlFonte: self::LISTA_URL,
                imagemUrl: $imagem !== null ? self::BASE_URL . $imagem : null,
                organizador: 'Associação de Atletismo do Algarve',
                urlInscricao: $link !== '' ? self::BASE_URL . str_replace('/eventos/', '/inscrever/', $link) : null,
            );
        }

        return $corridas;
    }

    private function texto(DOMXPath $xpath, string $consulta, DOMNode $contexto): string
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
