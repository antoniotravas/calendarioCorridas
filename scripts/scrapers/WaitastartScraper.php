<?php

declare(strict_types=1);

/**
 * Recolhe provas de corrida da plataforma de inscrições e cronometragem
 * Waitastart (sobretudo Beira Interior: Fundão, Covilhã, Penamacor...).
 *
 * A página inicial lista os próximos eventos (WordPress), cada um num
 * <h5 class="service-title"> com o link e um título que junta tudo:
 * "08 Nov 2026 - TechRun - Lisboa" (data - nome - localidade; às vezes sem o
 * primeiro traço, "8 Nov 2026 BTT Chaparros - Santiago do Cacém", ou sem
 * localidade). Sem categoria: é corrida se o nome ou o endereço o parecerem
 * ("TechRun" só se percebe por /tech-run-2026/); BTT e outras modalidades
 * ficam de fora.
 */
final class WaitastartScraper implements ScraperInterface, FonteOrganizadora
{
    private const URL = 'https://waitastart.com/';

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'Waitastart';
    }

    public function scrape(): array
    {
        $xpath = $this->criarXPath($this->http->get(self::URL));
        $eventos = $xpath->query('//h5[contains(@class,"service-title")]/a');
        if ($eventos->length === 0) {
            throw new RuntimeException('Estrutura inesperada na página inicial da Waitastart (eventos não encontrados)');
        }

        $corridas = [];
        $hoje = date('Y-m-d');
        foreach ($eventos as $evento) {
            $titulo = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $evento->textContent));
            $urlEvento = $evento->getAttribute('href');
            if (!preg_match('/^(\d{1,2}\s+[a-zç]+\s+\d{4})\s*(?:-\s*)?(.+)$/iu', $titulo, $m)) {
                continue;
            }
            $dataProva = DateHelper::parseTextoLivre($m[1]);
            // A localidade é a última parte depois de " - " ("GPA Re-Viver Pêro Viseu - Fundão").
            $partes = explode(' - ', $m[2]);
            $local = count($partes) > 1 ? trim((string) array_pop($partes)) : null;
            $nome = trim(implode(' - ', $partes));
            $slug = str_replace('-', ' ', basename((string) parse_url($urlEvento, PHP_URL_PATH)));

            if ($nome === '' || ($dataProva !== null && $dataProva < $hoje) || NomeProvaHelper::eOutraModalidade($nome)
                || !(NomeProvaHelper::pareceCorrida($nome) || NomeProvaHelper::pareceCorrida($slug))) {
                continue;
            }

            $imagem = $xpath->query('ancestor::article[1]//img/@src', $evento)->item(0)?->nodeValue;

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $dataProva,
                local: $local,
                distancias: null,
                tipo: null,
                regiao: null,
                urlEvento: $urlEvento,
                fonte: $this->nome(),
                urlFonte: self::URL,
                imagemUrl: $imagem ?: null,
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
