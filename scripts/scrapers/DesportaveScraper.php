<?php

declare(strict_types=1);

/**
 * Recolhe os próximos eventos da Desportave (desportave.pt, Associação Desportiva de
 * Ancede), cronometragem e dorsais do Vale do Sousa e Baião.
 *
 * A página events.next.php tem um bloco por evento: o nome (dentro do link
 * events.file.php?cid=N, que é o regulamento ou o cartaz), e as linhas "Local:" e
 * "Data:" (AAAA-MM-DD). Não tem horas nem distâncias.
 */
final class DesportaveScraper implements ScraperInterface, FonteOrganizadora
{
    private const BASE_URL = 'http://www.desportave.pt';
    private const LISTA_URL = self::BASE_URL . '/events.next.php';

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'Desportave';
    }

    public function scrape(): array
    {
        $html = $this->http->get(self::LISTA_URL);

        preg_match_all(
            "#<a href='(events\.file\.php\?cid=\d+)'>(.*?)</a>.*?Local:</b></font>\s*(.*?)<br>.*?Data:</b></font>\s*(\d{4}-\d{2}-\d{2})#s",
            $html,
            $eventos,
            PREG_SET_ORDER
        );
        if ($eventos === []) {
            throw new RuntimeException('Estrutura inesperada em ' . self::LISTA_URL . ' (eventos não encontrados)');
        }

        $corridas = [];
        foreach ($eventos as [, $link, $nomeHtml, $localHtml, $data]) {
            $nome = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($nomeHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $local = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($localHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($nome === '') {
                continue;
            }

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $data,
                local: $local !== '' ? $local : null,
                distancias: null,
                tipo: null,
                regiao: null,
                urlEvento: self::BASE_URL . '/' . $link,
                fonte: $this->nome(),
                urlFonte: self::LISTA_URL,
                organizador: 'Desportave',
                organizadorUrl: self::BASE_URL,
            );
        }

        return $corridas;
    }
}
