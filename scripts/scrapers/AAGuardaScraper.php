<?php

declare(strict_types=1);

/**
 * Recolhe provas do calendário de competições da Associação de Atletismo da
 * Guarda (aag.pt, ASP clássico em ISO-8859-1).
 *
 * /agenda/agenda.asp é uma tabela: uma linha com o mês ("Novembro/2026")
 * seguida de uma linha por prova com o dia, o nome (link para
 * evento.asp?idevento=N) e a localidade. Sem tipo de prova: entram as que
 * parecem corrida ou pista pelo nome, ou que não são outra coisa (formação,
 * convívios...) — a associação só publica atletismo ("Challenge Passadiços
 * do Mondego" não diz que é corrida).
 */
final class AAGuardaScraper implements ScraperInterface
{
    private const BASE_URL = 'https://www.aag.pt/agenda/';
    private const LISTA_URL = self::BASE_URL . 'agenda.asp';

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'AA Guarda';
    }

    public function scrape(): array
    {
        $html = $this->http->get(self::LISTA_URL);
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        }
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . preg_replace('/<meta[^>]+charset=[^>]*>/i', '', $html), LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $linhas = $xpath->query('//div[@id="content-txt"]//table//tr');
        if ($linhas->length === 0) {
            throw new RuntimeException('Estrutura inesperada em ' . self::LISTA_URL . ' (tabela do calendário não encontrada)');
        }

        $corridas = [];
        $mes = null;
        $ano = null;
        foreach ($linhas as $linha) {
            $celulas = array_map(
                fn (DOMNode $c) => trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $c->textContent)),
                iterator_to_array($xpath->query('./td', $linha))
            );
            if (count($celulas) === 1 && preg_match('#^([a-zç]+)\s*/\s*(\d{4})$#iu', $celulas[0], $m)) {
                [$mes, $ano] = [$m[1], (int) $m[2]];
                continue;
            }
            $link = $xpath->query('./td/a/@href', $linha)->item(0)?->nodeValue;
            if (count($celulas) < 3 || $link === null || $mes === null || !ctype_digit($celulas[0])) {
                continue;
            }
            $nome = $celulas[1];
            if ($nome === '' || !(NomeProvaHelper::parecePista($nome) || !NomeProvaHelper::eOutraModalidade($nome))) {
                continue;
            }

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: DateHelper::toIso((int) $celulas[0], $mes, $ano),
                local: $celulas[2] !== '' ? $celulas[2] : null,
                distancias: null,
                tipo: null,
                regiao: null,
                urlEvento: self::BASE_URL . ltrim($link, '/'),
                fonte: $this->nome(),
                urlFonte: self::LISTA_URL,
            );
        }

        return $corridas;
    }
}
