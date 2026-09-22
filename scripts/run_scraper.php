<?php

declare(strict_types=1);

require __DIR__ . '/lib/Corrida.php';
require __DIR__ . '/lib/DateHelper.php';
require __DIR__ . '/lib/DistanciaHelper.php';
require __DIR__ . '/lib/HttpClient.php';
require __DIR__ . '/lib/Database.php';
require __DIR__ . '/scrapers/ScraperInterface.php';
require __DIR__ . '/scrapers/All4RunningScraper.php';
require __DIR__ . '/scrapers/PortugalRunningScraper.php';
require __DIR__ . '/scrapers/AAAPortoScraper.php';
require __DIR__ . '/scrapers/FpaCompeticoesScraper.php';

$configPath = __DIR__ . '/config.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "Falta o ficheiro scripts/config.php.\n");
    fwrite(STDERR, "Copia scripts/config.example.php para scripts/config.php e ajusta as credenciais.\n");
    exit(1);
}

$config = require $configPath;

try {
    $db = new Database($config);
} catch (PDOException $e) {
    fwrite(STDERR, "Não foi possível ligar à base de dados: {$e->getMessage()}\n");
    exit(1);
}

$http = new HttpClient();

$scrapers = [
    new All4RunningScraper($http),
    new PortugalRunningScraper($http),
    new AAAPortoScraper($http),
    new FpaCompeticoesScraper($http),
];

$totalInseridas = 0;
$totalAtualizadas = 0;

foreach ($scrapers as $scraper) {
    echo "== {$scraper->nome()} ==\n";

    try {
        $corridas = $scraper->scrape();
    } catch (Throwable $e) {
        fwrite(STDERR, "  Falhou a recolha em {$scraper->nome()}: {$e->getMessage()}\n");
        continue;
    }

    $inseridas = 0;
    $atualizadas = 0;

    foreach ($corridas as $corrida) {
        $resultado = $db->upsertCorrida($corrida);
        if ($resultado === 'inserted') {
            $inseridas++;
        } else {
            $atualizadas++;
        }
    }

    $totalInseridas += $inseridas;
    $totalAtualizadas += $atualizadas;

    printf("  %d provas encontradas (%d novas, %d atualizadas)\n", count($corridas), $inseridas, $atualizadas);
}

printf("\nTotal: %d novas, %d atualizadas.\n", $totalInseridas, $totalAtualizadas);
