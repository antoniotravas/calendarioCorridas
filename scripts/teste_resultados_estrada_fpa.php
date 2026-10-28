<?php

declare(strict_types=1);

/**
 * Teste: lê os resultados de provas de estrada da FPA (beta.fpacompeticoes.pt/rankings).
 * Não grava na base de dados. Só lista os eventos de estrada e imprime/grava as marcas.
 *
 * Uso: php scripts/teste_resultados_estrada_fpa.php [ano_inicio] [ano_fim] [ficheiro_json]
 * Ex.: php scripts/teste_resultados_estrada_fpa.php 2024 2025 C:\...\scratchpad\fpa_estrada.json
 */

const FPA_RANKINGS_URL = 'https://beta.fpacompeticoes.pt/rankings?_data=routes%2Frankings';

// Nomes de eventos que são de estrada (Maratona, Meia, 10 km, 5 km...). Pista, barreiras,
// lançamentos, estafetas e marcha ficam de fora.
// A distância não pode ser parte de um peso decimal ("7.26 K" é um martelo, não 26 km).
const PADRAO_ESTRADA = '/maratona|meia|(?<![\d.,])\d{1,2}\s*k(m)?\b|estrada/iu';

function pedirJson(string $url, ?string $corpo = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    }
    $resposta = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($resposta === false || $codigo !== 200) {
        throw new RuntimeException("Pedido falhou ({$codigo}): {$url}");
    }
    $dados = json_decode((string) $resposta, true);
    if (!is_array($dados)) {
        throw new RuntimeException('Resposta que não é JSON: ' . substr((string) $resposta, 0, 200));
    }
    return $dados;
}

function eventosEstrada(): array
{
    $dados = pedirJson(FPA_RANKINGS_URL);
    $eventos = $dados['data']['events'] ?? [];
    $estrada = [];
    foreach ($eventos as $e) {
        if (preg_match(PADRAO_ESTRADA, (string) $e['name'])) {
            $estrada[] = $e;
        }
    }
    return $estrada;
}

function marcasDoEvento(int $eventoId, int $ano): array
{
    $corpo = http_build_query([
        'event' => $eventoId,
        'year' => $ano,
        'assocs' => 'TODAS',
        'echelons' => 'TODOS',
        'gender' => '',
    ]);
    $dados = pedirJson(FPA_RANKINGS_URL, $corpo);
    $linhas = $dados['data'] ?? [];

    $marcas = [];
    foreach ($linhas as $l) {
        $marcas[] = [
            'atleta' => (string) ($l['fk_atleta__nome'] ?? ''),
            'atleta_id' => (int) ($l['fk_atleta__id'] ?? 0),
            'escalao' => (string) ($l['escalao'] ?? ''),
            'clube' => (string) ($l['clube'] ?? ''),
            'marca' => (string) ($l['marca'] ?? ''),
            'posicao' => $l['posicao'] ?? null,
            'local' => (string) ($l['local'] ?? ''),
            'data' => (string) ($l['data'] ?? ''),
        ];
    }
    return $marcas;
}

// --- Execução ---
$anoInicio = (int) ($argv[1] ?? date('Y') - 1);
$anoFim = (int) ($argv[2] ?? date('Y'));
$ficheiro = $argv[3] ?? null;

$eventos = eventosEstrada();
echo 'Eventos de estrada encontrados: ' . count($eventos) . PHP_EOL;
foreach ($eventos as $e) {
    echo "  {$e['id']}  {$e['name']}" . PHP_EOL;
}

$resultado = [];
foreach ($eventos as $e) {
    for ($ano = $anoInicio; $ano <= $anoFim; $ano++) {
        $marcas = marcasDoEvento((int) $e['id'], $ano);
        echo "{$e['name']} {$ano}: " . count($marcas) . ' registos' . PHP_EOL;
        if ($marcas !== []) {
            $resultado[] = ['evento' => $e['name'], 'evento_id' => (int) $e['id'], 'ano' => $ano, 'marcas' => $marcas];
        }
    }
}

if ($ficheiro !== null) {
    file_put_contents($ficheiro, json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "Gravado em {$ficheiro}" . PHP_EOL;
}
