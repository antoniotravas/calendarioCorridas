<?php

declare(strict_types=1);

/**
 * Procura o valor da inscrição em todas as provas futuras por verificar, sem
 * correr o resto do scraper. Serve para a primeira volta completa (o scraper
 * diário só gasta 8 minutos nisto) ou para pôr tudo em dia de uma vez.
 *
 * Uso:
 *   php scripts/procurar_inscricoes.php              (até 60 minutos)
 *   php scripts/procurar_inscricoes.php --minutos=20
 *
 * Pode ser interrompido (Ctrl+C) e voltar a correr: continua nas provas que
 * ficaram por ver.
 */

require __DIR__ . '/lib/Corrida.php';
require __DIR__ . '/lib/DateHelper.php';
require __DIR__ . '/lib/DistanciaHelper.php';
require __DIR__ . '/lib/NomeProvaHelper.php';
require __DIR__ . '/lib/HttpClient.php';
require __DIR__ . '/lib/Database.php';
require __DIR__ . '/lib/PdfTextoHelper.php';
require __DIR__ . '/lib/PremiosHelper.php';
require __DIR__ . '/lib/InscricaoHelper.php';
require __DIR__ . '/lib/ProcuraInscricoes.php';
require __DIR__ . '/scrapers/ScraperInterface.php';
require __DIR__ . '/scrapers/All4RunningScraper.php';

$minutos = 60;
foreach ($argv as $argumento) {
    if (preg_match('/^--minutos=(\d+)$/', $argumento, $m)) {
        $minutos = max(1, (int) $m[1]);
    }
}

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Falta o ficheiro scripts/config.php.\n");
    exit(1);
}
$db = new Database(require $configPath);

$procura = new ProcuraInscricoes(
    $db,
    new HttpClient(25, false, 2000),
    [All4RunningScraper::PREFIXO_PAGINA_PROVA],
    ['all4running.pt' => new HttpClient(10, false, 5000)]
);

echo "A procurar o valor das inscrições (até {$minutos} minutos)...\n";
$resultado = $procura->executar($minutos, function (int $id, ?array $inscricao): void {
    static $n = 0;
    $n++;
    $estado = $inscricao === null ? 'por saber' : InscricaoHelper::formatar($inscricao['min'], $inscricao['max']);
    echo sprintf("  %3d. prova %d — %s\n", $n, $id, $estado);
});

printf(
    "\nFeito: %d provas vistas — %d com valor, %d gratuitas, %d por saber.%s\n",
    $resultado['vistas'],
    $resultado['com_valor'],
    $resultado['gratuitas'],
    $resultado['por_saber'],
    $resultado['faltam'] > 0
        ? " Faltam {$resultado['faltam']}: volta a correr o comando para continuar."
        : ' Não falta nenhuma.'
);
