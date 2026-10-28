<?php

declare(strict_types=1);

/**
 * Recolhe provas da Cyclones Sports (cyclonessports.com), organizador e
 * plataforma de inscrições do Alto Minho (Meia Maratona Manuela Machado,
 * Léguas da Eurocidade, São Silvestres de Caminha e Cerveira...).
 *
 * Site Joomla: os próximos eventos estão no menu "Próximos Eventos"
 * (a.mod-articles-category-title, um artigo por evento — a página da
 * categoria não os lista). Cada artigo tem os dados em classes próprias:
 * span.cyc-data ("19 de dezembro de 2026"), span.cyc-local, ul.cyc-ativ
 * (atividades: "Caminhada 5Km", "Corrida 10Km"), ul.cyc-organ (organização),
 * o cartaz e os botões "Inscrever" (provas/{pasta}/inscrever.php) e
 * "Regulamento". A página inicial não serve: é uma lista de notícias
 * (ver sql/migracao_015_paginas_gerais.sql).
 */
final class CyclonesSportsScraper implements ScraperInterface, FonteOrganizadora
{
    private const BASE_URL = 'https://www.cyclonessports.com';
    private const LISTA_URL = self::BASE_URL . '/index.php/2015-06-03-23-51-55/proximos-eventos';

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'Cyclones Sports';
    }

    public function scrape(): array
    {
        $xpath = $this->criarXPath($this->http->get(self::LISTA_URL));
        $links = $xpath->query('//li[a[contains(@href,"proximos-eventos")]]//a[contains(@class,"mod-articles-category-title")]/@href');
        if ($links->length === 0) {
            throw new RuntimeException('Estrutura inesperada em ' . self::LISTA_URL . ' (menu dos próximos eventos não encontrado)');
        }

        $corridas = [];
        $hoje = date('Y-m-d');
        foreach (array_unique(array_map(fn (DOMNode $n) => $n->nodeValue, iterator_to_array($links))) as $link) {
            $urlEvento = self::BASE_URL . '/' . ltrim($link, '/');
            try {
                $artigo = $this->criarXPath($this->http->get($urlEvento));
            } catch (RuntimeException) {
                continue; // página em baixo: fica para a próxima execução
            }

            $nome = $this->texto($artigo, '//h1[contains(@class,"article-title")]');
            $dataProva = DateHelper::parseTextoLivre($this->texto($artigo, '//span[@class="cyc-data"]'));
            $atividades = array_map(
                fn (DOMNode $n) => trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $n->textContent)),
                iterator_to_array($artigo->query('//ul[contains(@class,"cyc-ativ")]/li'))
            );
            $corridasDoEvento = array_values(array_filter(
                $atividades,
                fn (string $a) => !NomeProvaHelper::eOutraModalidade($a)
                    && (NomeProvaHelper::pareceCorrida($a) || DistanciaHelper::extrairKm($a) !== [])
            ));

            // A "Escolinha Nem o Céu é o Limite" é um circuito para crianças (as atividades são escalões).
            if ($nome === '' || ($dataProva !== null && $dataProva < $hoje) || NomeProvaHelper::eOutraModalidade($nome)
                || preg_match('/escolinha/i', $nome) || ($atividades !== [] && $corridasDoEvento === [])) {
                continue;
            }

            $local = $this->texto($artigo, '//span[@class="cyc-local"]');
            $organizador = $this->texto($artigo, '//ul[contains(@class,"cyc-organ")]/li[1]');
            $imagem = $artigo->query('//p[@class="cyc-imagem"]//img/@src')->item(0)?->nodeValue;
            $inscricao = $artigo->query('//a[contains(@href,"inscrever")]/@href')->item(0)?->nodeValue;

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $dataProva,
                // "Alvelos –Barcelos": fica "Alvelos, Barcelos".
                local: $local !== '' ? preg_replace('/\s*[–-]\s*/u', ', ', $local) : null,
                // "Corrida 10Km", "Meia Maratona 21 097,5 metros" (DistanciaHelper lê "Meia Maratona").
                distancias: $corridasDoEvento !== [] ? implode(', ', $corridasDoEvento) : null,
                tipo: null,
                regiao: null,
                urlEvento: $urlEvento,
                fonte: $this->nome(),
                urlFonte: self::LISTA_URL,
                imagemUrl: $imagem !== null ? self::BASE_URL . '/' . ltrim($imagem, '/') : null,
                organizador: $organizador !== '' ? $organizador : null,
                urlInscricao: $inscricao !== null && str_starts_with($inscricao, 'http') ? $inscricao : null,
            );
        }

        return $corridas;
    }

    private function texto(DOMXPath $xpath, string $consulta): string
    {
        $texto = $xpath->query($consulta)->item(0)?->textContent ?? '';

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
