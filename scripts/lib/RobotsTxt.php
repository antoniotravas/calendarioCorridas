<?php

declare(strict_types=1);

/**
 * Interpreta um ficheiro robots.txt (https://www.robotstxt.org/) para saber se o
 * scraper pode pedir um caminho e com que intervalo mínimo entre pedidos.
 *
 * Regras: usa o grupo "User-agent" do nosso robô se existir, senão o grupo "*".
 * Em caso de conflito ganha a regra mais longa (Allow ou Disallow), como manda o padrão.
 * Suporta os curingas "*" e "$".
 */
final class RobotsTxt
{
    /** @var array<int,array{permite: bool, padrao: string}> */
    private array $regras = [];

    private ?float $crawlDelay = null;

    /**
     * @param string $conteudo texto do robots.txt
     * @param string $agente   nome curto do nosso robô (ex.: "CalendarioCorridas")
     */
    public function __construct(string $conteudo, string $agente)
    {
        $this->interpretar($conteudo, $agente);
    }

    /** Pode o robô pedir este caminho (ex.: "/provas/2026")? */
    public function permite(string $caminho): bool
    {
        $caminho = $caminho === '' ? '/' : $caminho;
        $melhor = null;
        $tamanhoMelhor = -1;

        foreach ($this->regras as $regra) {
            if ($this->corresponde($regra['padrao'], $caminho)) {
                $tamanho = strlen($regra['padrao']);
                // Em empate, Allow ganha (comportamento usual dos crawlers).
                if ($tamanho > $tamanhoMelhor || ($tamanho === $tamanhoMelhor && $regra['permite'])) {
                    $melhor = $regra['permite'];
                    $tamanhoMelhor = $tamanho;
                }
            }
        }

        return $melhor ?? true;
    }

    /** Segundos mínimos entre pedidos indicados em "Crawl-delay", ou null. */
    public function crawlDelay(): ?float
    {
        return $this->crawlDelay;
    }

    private function interpretar(string $conteudo, string $agente): void
    {
        $agente = mb_strtolower($agente);
        /** @var array<int,array{agentes: string[], regras: array<int,array{permite: bool, padrao: string}>, crawl: ?float}> $grupos */
        $grupos = [];
        $aSecaoDeRegras = false;

        foreach (preg_split('/\R/u', $conteudo) ?: [] as $linha) {
            $linha = trim(preg_replace('/#.*$/', '', $linha) ?? '');
            if ($linha === '' || !str_contains($linha, ':')) {
                continue;
            }
            [$campo, $valor] = array_map('trim', explode(':', $linha, 2));
            $campo = mb_strtolower($campo);

            if ($campo === 'user-agent') {
                // "User-agent" seguidos (sem regras pelo meio) partilham o mesmo grupo.
                if ($grupos === [] || $aSecaoDeRegras) {
                    $grupos[] = ['agentes' => [], 'regras' => [], 'crawl' => null];
                    $aSecaoDeRegras = false;
                }
                $grupos[array_key_last($grupos)]['agentes'][] = mb_strtolower($valor);
                continue;
            }

            $aSecaoDeRegras = true;
            if ($grupos === []) {
                continue;
            }
            $indice = array_key_last($grupos);

            if ($campo === 'disallow' || $campo === 'allow') {
                if ($valor !== '') {
                    $grupos[$indice]['regras'][] = ['permite' => $campo === 'allow', 'padrao' => $valor];
                }
            } elseif ($campo === 'crawl-delay' && is_numeric($valor)) {
                $grupos[$indice]['crawl'] = (float) $valor;
            }
        }

        $grupo = null;
        foreach ([$agente, '*'] as $alvo) {
            foreach ($grupos as $g) {
                if (in_array($alvo, $g['agentes'], true)) {
                    $grupo = $g;
                    break 2;
                }
            }
        }

        if ($grupo !== null) {
            $this->regras = $grupo['regras'];
            $this->crawlDelay = $grupo['crawl'];
        }
    }

    /** Converte o padrão de robots.txt (com "*" e "$") numa expressão regular. */
    private function corresponde(string $padrao, string $caminho): bool
    {
        $fimExato = str_ends_with($padrao, '$');
        if ($fimExato) {
            $padrao = substr($padrao, 0, -1);
        }
        $regex = '#^' . str_replace('\*', '.*', preg_quote($padrao, '#')) . ($fimExato ? '$' : '') . '#';

        return preg_match($regex, $caminho) === 1;
    }
}
