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
require __DIR__ . '/scrapers/AdacCoimbraScraper.php';
require __DIR__ . '/scrapers/AALisboaScraper.php';
require __DIR__ . '/scrapers/TheEventsCalendarScraper.php';
require __DIR__ . '/scrapers/Lap2GoScraper.php';
require __DIR__ . '/scrapers/XistarcaScraper.php';
require __DIR__ . '/scrapers/TrilhoPerdidoScraper.php';
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
    new AdacCoimbraScraper($http),
    // A plataforma da AA Lisboa (ASP.NET) só responde aos botões na mesma sessão (cookies).
    new AALisboaScraper(new HttpClient(guardarCookies: true)),
    new TheEventsCalendarScraper($http, 'AA São Miguel', 'https://aatletismosmiguel.pt', 'Açores'),
    new TheEventsCalendarScraper($http, 'AA Madeira', 'http://atletismodamadeira.pt', 'Madeira'),
    new TheEventsCalendarScraper($http, 'AA Santarém', 'https://www.aasantarem.pt', 'Santarém'),
    new Lap2GoScraper($http),
    new XistarcaScraper($http),
    new TrilhoPerdidoScraper($http),
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

// Pedidos às páginas das provas (links oficiais e imagens): no máximo um
// pedido de 5 em 5 segundos ao mesmo site — a All4Running recusa pedidos
// seguidos (HTTP 429). Um site que recuse deixa de ser contactado nesta
// execução; o que ficar por fazer fica para a próxima.
$httpPaginas = new HttpClient(10, false, 5000);
$sitesRecusados = [];
$recusou = static function (Throwable $e, string $url) use (&$sitesRecusados): void {
    if ($e->getCode() === HttpClient::DEMASIADOS_PEDIDOS) {
        $sitesRecusados[(string) parse_url($url, PHP_URL_HOST)] = true;
    }
};
$siteRecusado = static function (string $url) use (&$sitesRecusados): bool {
    return isset($sitesRecusados[(string) parse_url($url, PHP_URL_HOST)]);
};

// Link oficial das provas da All4Running: a listagem só tem a página da prova
// na All4Running; o site oficial está nessa página. Cada página é lida uma vez
// (tabela links_oficiais), até 50 por execução; o link da prova passa depois a
// ser o oficial. Aproveita-se a mesma página para a imagem da prova.
$paginasLidas = 0;
$linksOficiaisEncontrados = 0;
$jaEsperou = false;
foreach ($db->paginasSemLinkOficial(All4RunningScraper::PREFIXO_PAGINA_PROVA, 50) as $pagina) {
    try {
        $html = $httpPaginas->get($pagina);
    } catch (Throwable $e) {
        if ($e->getCode() !== HttpClient::DEMASIADOS_PEDIDOS) {
            continue; // página com problemas: fica para a próxima execução
        }
        if ($jaEsperou) {
            $recusou($e, $pagina);
            break; // voltou a recusar: o resto fica para a próxima execução
        }
        $jaEsperou = true;
        sleep(30); // a primeira recusa costuma ser passageira
        continue;
    }
    $linkOficial = All4RunningScraper::extrairLinkOficial($html);
    $db->gravarLinkOficial($pagina, $linkOficial);
    $imagem = ImagemEventoHelper::extrair($html, $pagina);
    if ($imagem !== null) {
        $db->gravarImagemDaPagina($pagina, $imagem);
    }
    $paginasLidas++;
    $linksOficiaisEncontrados += $linkOficial !== null ? 1 : 0;
}
$db->aplicarLinksOficiais();
printf(
    "\nLinks oficiais (All4Running): %d páginas lidas, %d com link oficial%s.\n",
    $paginasLidas,
    $linksOficiaisEncontrados,
    $sitesRecusados !== [] ? ' (o site recusou mais pedidos; continua na próxima execução)' : ''
);

// O link das fontes organizadoras é a página oficial da prova: tem prioridade
// sobre o de qualquer outra fonte (inclui as que falharam nesta execução, pelos
// links gravados em execuções anteriores).
$organizadoras = array_map(
    fn (ScraperInterface $s) => $s->nome(),
    array_values(array_filter($scrapers, fn (ScraperInterface $s) => $s instanceof FonteOrganizadora))
);
$linksOrganizador = $db->aplicarLinksOrganizadores($organizadoras);

// Estrada, trail, corta-mato ou pista, para o filtro "Tipo" do site.
$db->atualizarCategorias();

// Miniaturas: para as provas futuras ainda sem imagem, procura o cartaz nas
// páginas do evento de cada fonte. Cada prova só é procurada uma vez, e há um
// limite por execução para não fazer centenas de pedidos de seguida.
$imagensEncontradas = 0;
$provasSemImagem = $db->provasParaProcurarImagem(80);
foreach ($provasSemImagem as $corridaId => $paginas) {
    $imagem = null;
    $algumaRespondeu = $paginas === [];
    foreach ($paginas as $pagina) {
        if ($siteRecusado($pagina)) {
            continue;
        }
        try {
            $imagem = ImagemEventoHelper::extrair($httpPaginas->get($pagina), $pagina);
            $algumaRespondeu = true;
        } catch (Throwable $e) {
            $recusou($e, $pagina);
            continue; // página em baixo ou a recusar o pedido: tenta a seguinte
        }
        if ($imagem !== null) {
            break;
        }
    }
    // Só se dá a prova como "procurada" se alguma página respondeu; senão
    // (ex. todas recusaram), volta a tentar na próxima execução.
    if ($algumaRespondeu) {
        $db->gravarImagemProcurada($corridaId, $imagem);
    }
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
