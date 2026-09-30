<?php

declare(strict_types=1);

require __DIR__ . '/lib/Corrida.php';
require __DIR__ . '/lib/DateHelper.php';
require __DIR__ . '/lib/DistanciaHelper.php';
require __DIR__ . '/lib/NomeProvaHelper.php';
require __DIR__ . '/lib/HttpClient.php';
require __DIR__ . '/lib/Database.php';
require __DIR__ . '/scrapers/ScraperInterface.php';
require __DIR__ . '/scrapers/All4RunningScraper.php';
require __DIR__ . '/scrapers/PortugalRunningScraper.php';
require __DIR__ . '/scrapers/AAAPortoScraper.php';
require __DIR__ . '/scrapers/FpaCompeticoesScraper.php';
require __DIR__ . '/scrapers/AABragaScraper.php';
require __DIR__ . '/scrapers/AdalLeiriaScraper.php';
require __DIR__ . '/scrapers/TheEventsCalendarScraper.php';

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
    new AABragaScraper($http),
    new AdalLeiriaScraper($http),
    new TheEventsCalendarScraper($http, 'AA São Miguel', 'https://aatletismosmiguel.pt', 'Açores'),
    new TheEventsCalendarScraper($http, 'AA Madeira', 'http://atletismodamadeira.pt', 'Madeira'),
    new TheEventsCalendarScraper($http, 'AA Santarém', 'https://www.aasantarem.pt', 'Santarém'),
];

$totalInseridas = 0;
$totalAtualizadas = 0;
$totalJuntadas = 0;
$avisos = [];

foreach ($scrapers as $scraper) {
    echo "== {$scraper->nome()} ==\n";

    try {
        $corridas = $scraper->scrape();
    } catch (Throwable $e) {
        fwrite(STDERR, "  Falhou a recolha em {$scraper->nome()}: {$e->getMessage()}\n");
        $avisos[] = "{$scraper->nome()}: a recolha falhou ({$e->getMessage()})";
        continue;
    }

    // Uma fonte que tinha provas futuras e agora não devolve nenhuma quase
    // sempre quer dizer que o site mudou e o scraper precisa de ser revisto.
    if ($corridas === []) {
        $anteriores = $db->contarProvasFuturasPorFonte($scraper->nome());
        if ($anteriores > 0) {
            $avisos[] = "{$scraper->nome()}: 0 provas encontradas, mas havia {$anteriores} provas futuras desta fonte "
                . '— o site pode ter mudado.';
        }
    }

    $inseridas = 0;
    $atualizadas = 0;
    $juntadas = 0;

    foreach ($corridas as $corrida) {
        try {
            $resultado = $db->upsertCorrida($corrida);
        } catch (Throwable $e) {
            fwrite(STDERR, "  Não foi possível gravar \"{$corrida->nome}\": {$e->getMessage()}\n");
            continue;
        }

        match ($resultado) {
            'inserted' => $inseridas++,
            'merged' => $juntadas++,
            default => $atualizadas++,
        };
    }

    $totalInseridas += $inseridas;
    $totalAtualizadas += $atualizadas;
    $totalJuntadas += $juntadas;

    printf(
        "  %d provas encontradas (%d novas, %d atualizadas, %d já existiam noutra fonte)\n",
        count($corridas),
        $inseridas,
        $atualizadas,
        $juntadas
    );
}

$consolidadas = $db->consolidarRepetidas();

printf(
    "\nTotal: %d novas, %d atualizadas, %d juntas a provas de outras fontes, %d repetidas antigas consolidadas.\n",
    $totalInseridas,
    $totalAtualizadas,
    $totalJuntadas,
    $consolidadas
);

if ($avisos !== []) {
    fwrite(STDERR, "\nATENÇÃO — fontes com problemas:\n");
    foreach ($avisos as $aviso) {
        fwrite(STDERR, "  - {$aviso}\n");
    }
}
