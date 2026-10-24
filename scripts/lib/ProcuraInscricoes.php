<?php

declare(strict_types=1);

/**
 * Percorre as provas futuras por verificar e procura o valor da inscrição
 * (InscricaoHelper) até acabar o tempo dado. Usada no fim de run_scraper.php
 * e pelo comando scripts/procurar_inscricoes.php.
 *
 * Ao contrário dos prémios, as páginas das provas vêm primeiro (as
 * plataformas mostram os preços em tabelas, mais fáceis de ler do que os
 * regulamentos); só sem preço nelas se abrem os regulamentos para que
 * apontam. Na Stop and Go os preços estão numa página à parte (/prices).
 */
final class ProcuraInscricoes
{
    /** Documentos lidos por prova, no máximo (páginas + regulamentos). */
    private const MAX_DOCUMENTOS = 5;

    /** @var array<string,true> sites que recusaram pedidos (HTTP 429) nesta execução */
    private array $sitesRecusados = [];

    /**
     * @param string[] $prefixosPaginaProva ver Database::provasParaProcurarInscricoes
     * @param array<string,HttpClient> $clientesPorSite clientes próprios para sites
     *        que recusam pedidos seguidos (ex. All4Running, um de 5 em 5 s)
     */
    public function __construct(
        private readonly Database $db,
        private readonly HttpClient $http,
        private readonly array $prefixosPaginaProva = [],
        private readonly array $clientesPorSite = [],
    ) {
    }

    /** Sites que já recusaram pedidos noutra fase da execução: não se insiste. */
    public function marcarSiteRecusado(string $host): void
    {
        $this->sitesRecusados[$host] = true;
    }

    /**
     * @param callable(int, ?array): void|null $aoVerProva chamada após cada prova
     * @return array{vistas: int, com_valor: int, gratuitas: int, por_saber: int, faltam: int}
     */
    public function executar(int $minutos, ?callable $aoVerProva = null): array
    {
        $ate = microtime(true) + $minutos * 60;
        $contagem = ['vistas' => 0, 'com_valor' => 0, 'gratuitas' => 0, 'por_saber' => 0, 'faltam' => 0];

        $provas = $this->db->provasParaProcurarInscricoes(2000, $this->prefixosPaginaProva);
        foreach ($provas as $corridaId => $paginas) {
            if (microtime(true) > $ate) {
                $contagem['faltam'] = count($provas) - $contagem['vistas'];
                break;
            }
            $contagem['vistas']++;

            [$inscricao, $url] = $this->procurar($paginas);
            $this->db->gravarInscricao($corridaId, $inscricao, $url);

            $contagem[match (true) {
                $inscricao === null => 'por_saber',
                $inscricao['max'] <= 0 => 'gratuitas',
                default => 'com_valor',
            }]++;
            if ($aoVerProva !== null) {
                $aoVerProva($corridaId, $inscricao);
            }
        }

        return $contagem;
    }

    /**
     * @param string[] $paginas
     * @return array{0: ?array, 1: ?string} [InscricaoHelper::extrair, endereço onde foi lido]
     */
    private function procurar(array $paginas): array
    {
        $fila = [];
        foreach ($paginas as $url) {
            // Stop and Go: /events/{prova} → os preços estão em /events/{prova}/prices.
            if (preg_match('#^https?://(?:www\.)?stopandgo\.net/(?:[a-z]{2}/)?events/[^/?\#]+/?$#i', $url)) {
                $fila[] = [rtrim($url, '/') . '/prices', 0];
            }
            $fila[] = [$url, 0];
        }
        $regulamentos = [];
        $vistos = [];

        while (($fila !== [] || $regulamentos !== []) && count($vistos) < self::MAX_DOCUMENTOS) {
            [$url, $nivel] = $fila !== [] ? array_shift($fila) : array_shift($regulamentos);
            if (isset($vistos[$url])) {
                continue;
            }
            $vistos[$url] = true;
            if (PremiosHelper::ePaginaGeral($url, true)) {
                continue;
            }

            $conteudo = $this->obter($url);
            if ($conteudo === null) {
                continue;
            }
            $ePdf = str_starts_with(ltrim(substr($conteudo, 0, 1024)), '%PDF');
            if ($ePdf) {
                $texto = PdfTextoHelper::extrair($conteudo);
            } else {
                $texto = PremiosHelper::textoHtml($conteudo);
                if ($nivel < 2) {
                    foreach (PremiosHelper::linksRegulamento($conteudo, $url) as $link) {
                        $regulamentos[] = [$link, $nivel + 1];
                    }
                }
            }

            $inscricao = InscricaoHelper::extrair($texto);
            if ($inscricao !== null) {
                return [$inscricao, $url];
            }
        }

        return [null, null];
    }

    private function obter(string $url): ?string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (isset($this->sitesRecusados[$host])) {
            return null;
        }
        $http = $this->http;
        foreach ($this->clientesPorSite as $site => $cliente) {
            if ($host === $site || str_ends_with($host, '.' . $site)) {
                $http = $cliente;
            }
        }
        try {
            return $http->get($url);
        } catch (Throwable $e) {
            if ($e->getCode() === HttpClient::DEMASIADOS_PEDIDOS) {
                $this->sitesRecusados[$host] = true;
            }

            return null;
        }
    }
}
