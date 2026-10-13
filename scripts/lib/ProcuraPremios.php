<?php

declare(strict_types=1);

/**
 * Percorre as provas futuras por verificar e procura prémios monetários em
 * cada uma (PremiosHelper::procurar), até acabar o tempo dado. Usada no fim
 * de run_scraper.php e pelo comando scripts/procurar_premios.php (primeira
 * volta completa, ou para pôr tudo em dia).
 *
 * Cada prova tentada fica marcada (premios_procurado_em), mesmo quando
 * nenhum site respondeu — assim a fila avança sempre e a execução seguinte
 * passa às provas seguintes, em vez de repetir as mesmas (ver
 * Database::provasParaProcurarPremios para quando voltam a ser vistas).
 */
final class ProcuraPremios
{
    /** @var array<string,true> sites que recusaram pedidos (HTTP 429) nesta execução */
    private array $sitesRecusados = [];

    /**
     * @param string[] $prefixosPaginaProva ver Database::provasParaProcurarPremios
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
     * @param callable(int, ?bool, array): void|null $aoVerProva chamada após cada
     *        prova (id, resultado, páginas), para mostrar o progresso
     * @return array{vistas: int, sim: int, nao: int, por_saber: int, faltam: int}
     */
    public function executar(int $minutos, ?callable $aoVerProva = null): array
    {
        $ate = microtime(true) + $minutos * 60;
        $contagem = ['vistas' => 0, 'sim' => 0, 'nao' => 0, 'por_saber' => 0, 'faltam' => 0];

        $obter = function (string $url): ?string {
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
        };

        $provas = $this->db->provasParaProcurarPremios(2000, $this->prefixosPaginaProva);
        foreach ($provas as $corridaId => $paginas) {
            if (microtime(true) > $ate) {
                $contagem['faltam'] = count($provas) - $contagem['vistas'];
                break;
            }
            $contagem['vistas']++;

            [$monetarios, $excerto, $url, $respondeu] = PremiosHelper::procurar($paginas, $obter);
            if ($respondeu || $paginas === []) {
                $this->db->gravarPremios($corridaId, $monetarios, $excerto, $url);
            } else {
                // Nenhum site respondeu: fica marcada como tentada, sem perder o
                // que já se sabia, e volta a ser vista daqui a uns dias.
                $this->db->marcarPremiosTentados($corridaId);
            }

            $contagem[match ($monetarios) { true => 'sim', false => 'nao', null => 'por_saber' }]++;
            if ($aoVerProva !== null) {
                $aoVerProva($corridaId, $monetarios, $paginas);
            }
        }

        return $contagem;
    }
}
