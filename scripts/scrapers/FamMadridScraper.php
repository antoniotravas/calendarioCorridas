<?php

declare(strict_types=1);

/**
 * Recolhe provas da Federação de Atletismo de Madrid (atletismomadrid.com), a
 * federação regional de Espanha com o calendário popular mais completo.
 *
 * A página do calendário é uma tabela (fam-calendar-table) com uma linha por prova:
 * data ("17.01.26 (S)"), nome (com o link para a página da federação), local,
 * documentos (Reglamento, Inscripciones, Resultados) e tipo (RU = ruta,
 * CT = campo a través, TR = trail/montaña).
 *
 * Só se lê a época que a página mostra por omissão. O link da prova é o
 * regulamento do organizador, quando existe (a página da prova é da federação).
 * País e região são sempre Espanha e Comunidad de Madrid.
 */
final class FamMadridScraper implements ScraperInterface
{
    private const BASE_URL = 'https://www.atletismomadrid.com';
    private const LISTA_URL = self::BASE_URL . '/index.php?option=com_content&view=article&id=4755&Itemid=185';

    /** Código da coluna "Tipo" => texto que o NomeProvaHelper reconhece. */
    private const TIPOS = [
        'RU' => 'Estrada',
        'CT' => 'Corta-mato',
        'TR' => 'Trail',
    ];

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'FAM Madrid';
    }

    public function scrape(): array
    {
        $xpath = $this->criarXPath($this->http->get(self::LISTA_URL));
        $linhas = $xpath->query('//tr[contains(@class, "fam-calendar-row")]');
        if ($linhas->length === 0) {
            throw new RuntimeException('Estrutura inesperada em ' . self::LISTA_URL . ' (linhas do calendário não encontradas)');
        }

        $corridas = [];
        foreach ($linhas as $linha) {
            $data = $this->dataDaLinha($this->texto($xpath->query('.//td[contains(@class, "__date")]', $linha)->item(0)));
            $linkProva = $xpath->query('.//a[contains(@class, "fam-calendar-title")]', $linha)->item(0);
            $nome = $this->texto($linkProva);
            if ($data === null || $nome === '') {
                continue;
            }

            $codigoTipo = $this->texto($xpath->query('.//td[contains(@class, "__type")]', $linha)->item(0));
            if (!isset(self::TIPOS[$codigoTipo])) {
                // Tipos que não são corrida (ex. cursos, formação) ficam de fora.
                continue;
            }

            $local = $this->texto($xpath->query('./td[@data-label="Lugar"]', $linha)->item(0));

            // Regulamento: o link externo é o do organizador; sem ele, fica a página da federação.
            $regulamento = $xpath->query('.//a[contains(@class, "--regulations")]/@href', $linha)->item(0)?->nodeValue;
            $paginaFederacao = $linkProva?->getAttribute('href') ?? '';
            $urlEvento = $regulamento ?: $this->absoluto($paginaFederacao);

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $data,
                local: $local !== '' ? $local : null,
                distancias: null,
                tipo: self::TIPOS[$codigoTipo],
                regiao: 'Comunidad de Madrid',
                urlEvento: $urlEvento !== '' ? $urlEvento : null,
                fonte: $this->nome(),
                urlFonte: self::LISTA_URL,
                organizador: null,
                organizadorUrl: null,
                pais: 'ES',
                distrito: 'Madrid',
            );
        }

        return $corridas;
    }

    /** "17.01.26 (S)" => "2026-01-17". */
    private function dataDaLinha(string $texto): ?string
    {
        if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{2})/', $texto, $m)) {
            return null;
        }
        [, $dia, $mes, $ano] = $m;
        $ano = 2000 + (int) $ano;
        if (!checkdate((int) $mes, (int) $dia, $ano)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $ano, (int) $mes, (int) $dia);
    }

    private function absoluto(string $caminho): string
    {
        if ($caminho === '' || str_starts_with($caminho, 'http')) {
            return $caminho;
        }

        return self::BASE_URL . '/' . ltrim($caminho, '/');
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
