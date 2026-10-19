<?php

declare(strict_types=1);

/**
 * Recolhe provas de corrida da plataforma de inscrições e cronometragem
 * Recorde Pessoal (sobretudo distritos de Leiria e Santarém).
 *
 * A listagem /eventos aceita a categoria na query string (cat=atletismo ou
 * cat=trail) e pagina=-1 para mostrar todos os próximos eventos de uma vez.
 * Cada evento é um <div class="evento" data-evento="/evento/..."> com a data
 * ("24 Outubro 2026"), o título e a localidade. Na categoria Trail aparecem
 * também caminhadas ("Caminhada...", "Halloween Walk4Fun"), excluídas pelo nome.
 */
final class RecordePessoalScraper implements ScraperInterface, FonteOrganizadora
{
    private const BASE_URL = 'https://www.recordepessoal.pt';
    private const LISTA_URL = self::BASE_URL . '/eventos?pagina=-1&pesquisa=&cat=';
    /** @var array<string,?string> categoria do site => tipo de prova */
    private const CATEGORIAS = ['atletismo' => null, 'trail' => 'Trail'];

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'Recorde Pessoal';
    }

    public function scrape(): array
    {
        $corridas = [];

        foreach (self::CATEGORIAS as $categoria => $tipo) {
            $url = self::LISTA_URL . $categoria;
            $xpath = $this->criarXPath($this->http->get($url));

            if ($xpath->query('//div[contains(@class,"eventosContainer")]')->length === 0) {
                throw new RuntimeException("Estrutura inesperada em {$url} (lista de eventos não encontrada)");
            }

            foreach ($xpath->query('//div[@class="evento" and @data-evento]') as $evento) {
                // O primeiro bloco "texto" (o outro repete-o no efeito ao passar o rato).
                $nome = $this->texto($xpath, './div[@class="texto"]/div[@class="titulo"]', $evento);
                $dataTexto = $this->texto($xpath, './div[@class="texto"]/div[@class="data"]', $evento);
                $local = $this->texto($xpath, './div[@class="texto"]/div[@class="local"]', $evento);

                if ($nome === '' || NomeProvaHelper::eOutraModalidade($nome) || preg_match('/\bwalk/i', $nome)) {
                    continue;
                }

                $imagem = null;
                $estilo = $xpath->query('./div[@class="imagem"]/@style', $evento)->item(0)?->nodeValue ?? '';
                if (preg_match("/url\\('([^']+)'\\)/", $estilo, $m)) {
                    $imagem = self::BASE_URL . $m[1];
                }

                $corridas[] = new Corrida(
                    nome: $nome,
                    dataProva: DateHelper::parseTextoLivre($dataTexto),
                    local: $local !== '' ? $local : null,
                    distancias: null,
                    tipo: $tipo,
                    regiao: null,
                    urlEvento: self::BASE_URL . $evento->getAttribute('data-evento'),
                    fonte: $this->nome(),
                    urlFonte: $url,
                    imagemUrl: $imagem,
                );
            }
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
