<?php

declare(strict_types=1);

require __DIR__ . '/lib/Corrida.php';
require __DIR__ . '/lib/DateHelper.php';
require __DIR__ . '/lib/DistanciaHelper.php';
require __DIR__ . '/lib/NomeProvaHelper.php';
require __DIR__ . '/lib/ImagemEventoHelper.php';
require __DIR__ . '/lib/HttpClient.php';
require __DIR__ . '/lib/Database.php';
require __DIR__ . '/scrapers/ScraperInterface.php';
require __DIR__ . '/scrapers/FonteOrganizadora.php';
require __DIR__ . '/scrapers/All4RunningScraper.php';
require __DIR__ . '/scrapers/PortugalRunningScraper.php';
require __DIR__ . '/scrapers/AAAPortoScraper.php';
require __DIR__ . '/scrapers/FpaCompeticoesScraper.php';
require __DIR__ . '/scrapers/AABragaScraper.php';
require __DIR__ . '/scrapers/AdalLeiriaScraper.php';
require __DIR__ . '/scrapers/TheEventsCalendarScraper.php';
require __DIR__ . '/scrapers/Lap2GoScraper.php';
require __DIR__ . '/scrapers/XistarcaScraper.php';
require __DIR__ . '/scrapers/StopAndGoScraper.php';
require __DIR__ . '/scrapers/AcorrerScraper.php';

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
    new Lap2GoScraper($http),
    new XistarcaScraper($http),
    new StopAndGoScraper($http),
    // A Acorrer só devolve os eventos a pedidos feitos na mesma sessão (cookies).
    new AcorrerScraper(new HttpClient(guardarCookies: true)),
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

// O link das fontes organizadoras é a página oficial da prova: tem prioridade
// sobre o de qualquer outra fonte (inclui as que falharam nesta execução, pelos
// links gravados em execuções anteriores).
$organizadoras = array_map(
    fn (ScraperInterface $s) => $s->nome(),
    array_values(array_filter($scrapers, fn (ScraperInterface $s) => $s instanceof FonteOrganizadora))
);
$linksOrganizador = $db->aplicarLinksOrganizadores($organizadoras);

// Miniaturas: para as provas futuras ainda sem imagem, procura o cartaz nas
// páginas do evento de cada fonte. Cada prova só é procurada uma vez, e há um
// limite por execução para não fazer centenas de pedidos de seguida.
$httpImagens = new HttpClient(10);
$imagensEncontradas = 0;
$provasSemImagem = $db->provasParaProcurarImagem(80);
foreach ($provasSemImagem as $corridaId => $paginas) {
    $imagem = null;
    foreach ($paginas as $pagina) {
        try {
            $imagem = ImagemEventoHelper::extrair($httpImagens->get($pagina), $pagina);
        } catch (Throwable) {
            continue; // página em baixo ou a recusar o pedido: tenta a seguinte
        }
        if ($imagem !== null) {
            break;
        }
    }
    $db->gravarImagemProcurada($corridaId, $imagem);
    $imagensEncontradas += $imagem !== null ? 1 : 0;
}
$imagensDescartadas = $db->descartarImagensGenericas();
printf(
    "\nImagens: procuradas em %d provas, %d encontradas, %d descartadas por serem logótipos repetidos.\n",
    count($provasSemImagem),
    $imagensEncontradas,
    $imagensDescartadas
);

printf(
    "\nTotal: %d novas, %d atualizadas, %d juntas a provas de outras fontes, %d repetidas antigas consolidadas, "
    . "%d links do organizador (re)aplicados.\n",
    $totalInseridas,
    $totalAtualizadas,
    $totalJuntadas,
    $consolidadas,
    $linksOrganizador
);

if ($avisos !== []) {
    fwrite(STDERR, "\nATENÇÃO — fontes com problemas:\n");
    foreach ($avisos as $aviso) {
        fwrite(STDERR, "  - {$aviso}\n");
    }
}
