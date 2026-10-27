<?php

declare(strict_types=1);

/**
 * Recolhe provas de corrida da plataforma de bilhética e inscrições Quero ir
 * (queroir.pt), usada por pequenos organizadores (Lousada, Baião, Tomar,
 * Alvaiázere...).
 *
 * A página inicial tem todos os próximos eventos (um carrossel com o cartaz,
 * o título em b.titulo_evento_cartaz e a data "2026-11-22" ou
 * "2026-11-20 a 2026-11-21"), de todo o tipo: corridas, workshops, jantares,
 * batismos de surf... Sem categoria, lê-se a página de cada evento, que tem
 * "Quando", "Onde", "Organizador", "Website" e a tabela das inscrições: é
 * corrida se o nome ou os tipos de inscrição o parecerem ("Rota das Lagoas"
 * só se percebe por "Trail Sprint - 20K"). As distâncias vêm dos tipos de
 * inscrição, sem as caminhadas. O site está em ISO-8859-1.
 */
final class QueroirScraper implements ScraperInterface, FonteOrganizadora
{
    private const BASE_URL = 'https://www.queroir.pt';

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'Queroir';
    }

    public function scrape(): array
    {
        $xpath = $this->criarXPath($this->http->get(self::BASE_URL . '/'));
        $titulos = $xpath->query('//b[@class="titulo_evento_cartaz"]/parent::a');
        if ($titulos->length === 0) {
            throw new RuntimeException('Estrutura inesperada na página inicial da Quero ir (eventos não encontrados)');
        }

        $corridas = [];
        $vistos = [];
        $hoje = date('Y-m-d');
        foreach ($titulos as $titulo) {
            $urlEvento = self::BASE_URL . '/' . ltrim($titulo->getAttribute('href'), '/');
            $nome = $this->limpar($titulo->textContent);
            // A data está a seguir ao título, no mesmo bloco ("2026-11-20 a 2026-11-21": fica o 1.º dia).
            preg_match('/\d{4}-\d{2}-\d{2}/', $titulo->parentNode->textContent, $m);
            $dataProva = $m[0] ?? null;

            if (isset($vistos[$urlEvento]) || $nome === '' || NomeProvaHelper::eOutraModalidade($nome)
                || ($dataProva !== null && $dataProva < $hoje)) {
                continue;
            }
            $vistos[$urlEvento] = true;

            try {
                $evento = $this->criarXPath($this->http->get($urlEvento));
            } catch (RuntimeException) {
                continue; // página em baixo: fica para a próxima execução
            }
            $inscricoes = array_map(
                fn (DOMNode $n) => $this->limpar($n->textContent),
                iterator_to_array($evento->query('//table[contains(@class,"dc_table")]//td[1]/b'))
            );
            $inscricoes = array_values(array_filter($inscricoes));
            if (!NomeProvaHelper::pareceCorrida($nome)
                && array_filter($inscricoes, fn (string $i) => NomeProvaHelper::pareceCorrida($i)) === []) {
                continue;
            }

            $quando = $this->bloco($evento, 'Quando');
            if (preg_match('/(\d{2})-(\d{2})-(\d{4})/', (string) $quando, $d)) {
                $dataProva = "{$d[3]}-{$d[2]}-{$d[1]}";
            }
            $local = $this->bloco($evento, 'Onde');
            // "Alvaiázere, Portugal"; às vezes a morada ("Rua São Gonçalo, Macieira"): fica a localidade.
            $local = $local !== null ? preg_replace(['/,\s*Portugal$/i', '/^(?:R\.|Rua|Av\.|Avenida|Largo|Praça|Travessa)\s[^,]+,\s*/iu'], '', $local) : null;
            $organizador = $this->bloco($evento, 'Organizador');
            $organizador = $organizador !== null ? trim(preg_replace('/\S+@\S+/', '', $organizador)) : null;
            $website = $evento->query('//h4[normalize-space()="Website"]/following-sibling::div[1]//a/@href')->item(0)?->nodeValue;
            $imagem = $evento->query('//meta[@property="og:image"]/@content')->item(0)?->nodeValue;

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $dataProva,
                local: $local ?: null,
                distancias: $this->distancias($inscricoes),
                tipo: null,
                regiao: null,
                urlEvento: $urlEvento,
                fonte: $this->nome(),
                urlFonte: self::BASE_URL . '/',
                imagemUrl: $imagem ?: null,
                organizador: $organizador ?: null,
                organizadorUrl: $website !== null && preg_match('#^https?://#i', $website) ? $website : null,
            );
        }

        return $corridas;
    }

    /** Texto do bloco com o título dado ("Quando", "Onde", "Organizador"). */
    private function bloco(DOMXPath $xpath, string $titulo): ?string
    {
        $no = $xpath->query('//h4[normalize-space()="' . $titulo . '"]/following-sibling::div[1]')->item(0);
        $texto = $no !== null ? $this->limpar($no->textContent) : '';

        return $texto !== '' ? $texto : null;
    }

    /**
     * "20K, 12K" a partir dos tipos de inscrição ("Trail Sprint - 20K",
     * "Caminhada - 10K"), sem caminhadas.
     *
     * @param string[] $inscricoes
     */
    private function distancias(array $inscricoes): ?string
    {
        $distancias = [];
        foreach ($inscricoes as $inscricao) {
            if (stripos($inscricao, 'caminhada') !== false) {
                continue;
            }
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:k|km|kms)\b/i', $inscricao, $m)) {
                $distancias[strtoupper($m[1]) . 'K'] = true;
            }
        }

        return $distancias !== [] ? implode(', ', array_keys($distancias)) : null;
    }

    private function limpar(string $texto): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $texto));
    }

    private function criarXPath(string $html): DOMXPath
    {
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        }
        // O <meta charset="iso-8859-1"> sobrepunha-se à conversão.
        $html = preg_replace('/<meta[^>]+charset=[^>]*>/i', '', $html);
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
