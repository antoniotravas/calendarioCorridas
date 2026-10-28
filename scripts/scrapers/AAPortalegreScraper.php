<?php

declare(strict_types=1);

/**
 * Recolhe provas da Associação de Atletismo do Distrito de Portalegre (aadp.pt).
 *
 * A página /calendario tem uma tabela com todos os eventos do ano: cada linha (tr)
 * traz data-dia, data-mes e data-ano, e as colunas por ordem: dia, mês, ano, nome
 * (com o link para a página da prova), local, concelho, organizador, tipo ("Pista",
 * "Corridas", "Trail"...), categoria, e os botões de inscrição (FPA Competições) e de
 * regulamento (PDF). Lê-se só o que é corrida, trail ou pista (pela categoria).
 */
final class AAPortalegreScraper implements ScraperInterface, FonteOrganizadora
{
    private const BASE_URL = 'https://aadp.pt';
    private const LISTA_URL = self::BASE_URL . '/calendario/';

    /** Valores da coluna "tipo" que se lêem (os restantes ficam de fora). */
    private const TIPOS = ['Corridas', 'Trail', 'Pista'];

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'AA Portalegre';
    }

    public function scrape(): array
    {
        $xpath = $this->criarXPath($this->http->get(self::LISTA_URL));
        $linhas = $xpath->query('//tr[@data-dia]');
        if ($linhas->length === 0) {
            throw new RuntimeException('Estrutura inesperada em ' . self::LISTA_URL . ' (linhas do calendário não encontradas)');
        }

        $corridas = [];
        foreach ($linhas as $linha) {
            $colunas = $xpath->query('./td', $linha);
            if ($colunas->length < 10) {
                continue;
            }

            // Coluna 9 = categoria (Corridas, Trail, Pista...); a 8 é o tipo (Competição, Formação...).
            $categoria = $this->texto($colunas->item(8));
            if (!in_array($categoria, self::TIPOS, true)) {
                continue;
            }

            $dia = (int) $linha->getAttribute('data-dia');
            $mes = (int) $linha->getAttribute('data-mes');
            $ano = (int) $linha->getAttribute('data-ano');
            $nome = $this->texto($colunas->item(3));
            if ($nome === '' || !checkdate($mes, $dia, $ano)) {
                continue;
            }

            $local = $this->texto($colunas->item(4));
            $concelho = $this->texto($colunas->item(5));
            $organizador = $this->texto($colunas->item(6));
            $detalhes = $xpath->query('.//a/@href', $colunas->item(3))->item(0)?->nodeValue;

            // Botão de inscrições (FPA Competições ou outra plataforma) e de regulamento.
            $inscricao = $xpath->query('.//a[contains(., "Inscri")]/@href', $linha)->item(0)?->nodeValue;
            $regulamento = $xpath->query('.//a[contains(@href, ".pdf")]/@href', $linha)->item(0)?->nodeValue;

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: sprintf('%04d-%02d-%02d', $ano, $mes, $dia),
                local: $local !== '' ? ($concelho !== '' && $concelho !== $local ? "{$local}, {$concelho}" : $local) : null,
                distancias: null,
                tipo: $categoria,
                regiao: 'Portalegre',
                urlEvento: $detalhes ?: null,
                fonte: $this->nome(),
                urlFonte: self::LISTA_URL,
                organizador: $organizador !== '' ? $organizador : 'Associação de Atletismo do Distrito de Portalegre',
                organizadorUrl: self::BASE_URL,
                urlInscricao: $inscricao ?: null,
            );
        }

        return $corridas;
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
