<?php

declare(strict_types=1);

/**
 * Recolhe as provas organizadas pela Runporto (Maratona e Meia Maratona do
 * Porto, São Silvestres do Porto e de Braga, Meia Maratona de Famalicão...).
 *
 * O calendário é uma página de texto por ano (/pt/novidades/calendario-de-provas-2026/):
 * um <h2> com o nome da prova seguido de um <li> "8 de novembro de 2026 - Porto - 42km/10km/6km".
 * Lê-se o calendário do ano corrente e o do seguinte (quando já existir). O
 * link de cada prova vem do menu de eventos no topo do site, emparelhado pelo nome.
 */
final class RunportoScraper implements ScraperInterface, FonteOrganizadora
{
    private const BASE_URL = 'https://www.runporto.com';
    private const CALENDARIO_URL = self::BASE_URL . '/pt/novidades/calendario-de-provas-%d/';

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'Runporto';
    }

    public function scrape(): array
    {
        $ano = (int) date('Y');
        $hoje = date('Y-m-d');
        $corridas = [];
        $calendariosLidos = 0;

        foreach ([$ano, $ano + 1] as $anoCalendario) {
            $url = sprintf(self::CALENDARIO_URL, $anoCalendario);
            try {
                $xpath = $this->criarXPath($this->http->get($url));
            } catch (RuntimeException $e) {
                if ($anoCalendario === $ano) {
                    throw $e;
                }
                continue; // o calendário do ano seguinte ainda não foi publicado
            }

            $titulos = $xpath->query('//div[@id="main-content"]//h2');
            if ($titulos->length === 0) {
                continue;
            }
            $calendariosLidos++;
            $eventos = $this->eventosDoMenu($xpath);

            foreach ($titulos as $titulo) {
                $nome = trim(preg_replace('/\s+/u', ' ', $titulo->textContent));
                $linha = trim(preg_replace('/\s+/u', ' ', $xpath->query('following::li[1]', $titulo)->item(0)?->textContent ?? ''));

                // "8 de novembro de 2026 - Porto - 42km/10km/6km"
                if ($nome === '' || NomeProvaHelper::eOutraModalidade($nome)
                    || !preg_match('/^(.+?\d{4})\s*-\s*(.+?)(?:\s*-\s*(.+))?$/u', $linha, $m)
                ) {
                    continue;
                }

                $dataProva = DateHelper::parseTextoLivre($m[1]);
                if ($dataProva === null || $dataProva < $hoje) {
                    continue;
                }

                $distancias = isset($m[3]) && preg_match('/\d\s*k/i', $m[3])
                    ? implode(', ', array_map('trim', explode('/', $m[3])))
                    : null;

                $corridas[] = new Corrida(
                    nome: $nome,
                    dataProva: $dataProva,
                    local: $m[2],
                    distancias: $distancias,
                    tipo: null,
                    regiao: null,
                    urlEvento: $this->linkDoEvento($nome, $eventos) ?? $url,
                    fonte: $this->nome(),
                    urlFonte: $url,
                    organizador: 'Runporto',
                    organizadorUrl: self::BASE_URL,
                );
            }
        }

        if ($calendariosLidos === 0) {
            throw new RuntimeException('Estrutura inesperada no calendário da Runporto (provas não encontradas)');
        }

        return $corridas;
    }

    /**
     * Menu "eventos" do topo: nome da prova => página da edição atual.
     *
     * @return array<string,string>
     */
    private function eventosDoMenu(DOMXPath $xpath): array
    {
        $eventos = [];
        foreach ($xpath->query('//div[@id="events-submenu"]//a[@href]') as $link) {
            $nome = trim($link->textContent);
            $href = $link->getAttribute('href');
            if ($nome !== '' && $href !== '') {
                $eventos[$nome] = str_starts_with($href, 'http') ? $href : self::BASE_URL . $href;
            }
        }

        return $eventos;
    }

    /**
     * O nome no calendário traz o patrocinador ("EDP Maratona do Porto") e o do
     * menu não ("Maratona do Porto"): fica o evento do menu com mais palavras em comum.
     *
     * @param array<string,string> $eventos
     */
    private function linkDoEvento(string $nome, array $eventos): ?string
    {
        $palavras = NomeProvaHelper::palavras($nome);
        $melhor = null;
        $melhorComuns = 0;

        foreach ($eventos as $nomeMenu => $url) {
            if (!NomeProvaHelper::mesmaProva($nome, $nomeMenu)) {
                continue;
            }
            $comuns = count(array_intersect($palavras, NomeProvaHelper::palavras($nomeMenu)));
            if ($comuns > $melhorComuns) {
                $melhor = $url;
                $melhorComuns = $comuns;
            }
        }

        return $melhor;
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
