<?php

declare(strict_types=1);

require __DIR__ . '/lib/Corrida.php';
require __DIR__ . '/lib/DateHelper.php';
require __DIR__ . '/lib/DistanciaHelper.php';
require __DIR__ . '/lib/NomeProvaHelper.php';
require __DIR__ . '/lib/ImagemEventoHelper.php';
require __DIR__ . '/lib/DetalhesProvaHelper.php';
require __DIR__ . '/lib/PdfTextoHelper.php';
require __DIR__ . '/lib/PremiosHelper.php';
require __DIR__ . '/lib/ProcuraPremios.php';
require __DIR__ . '/lib/InscricaoHelper.php';
require __DIR__ . '/lib/ProcuraInscricoes.php';
require __DIR__ . '/lib/DistritoHelper.php';
require __DIR__ . '/lib/Geocodificador.php';
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
require __DIR__ . '/scrapers/RecordePessoalScraper.php';
require __DIR__ . '/scrapers/RunportoScraper.php';
require __DIR__ . '/scrapers/ProEventsScraper.php';

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
    new RecordePessoalScraper($http),
    new RunportoScraper($http),
    new ProEventsScraper($http),
];

// As fontes organizadoras de todas as fontes, mesmo quando só se corre uma (--fonte).
$organizadoras = array_map(
    fn (ScraperInterface $s) => $s->nome(),
    array_values(array_filter($scrapers, fn (ScraperInterface $s) => $s instanceof FonteOrganizadora))
);

// --fonte=FPA: só as fontes cujo nome contém o texto, para ver uma fonte sem
// esperar pelas outras. Não lê as páginas das provas (imagens, detalhes,
// prémios, inscrições...): isso fica para a execução completa.
$soFonte = null;
foreach (array_slice($argv, 1) as $argumento) {
    if (preg_match('/^--fonte=(.+)$/u', $argumento, $m)) {
        $soFonte = $m[1];
    }
}
if ($soFonte !== null) {
    $scrapers = array_values(array_filter($scrapers, fn (ScraperInterface $s) => mb_stripos($s->nome(), $soFonte) !== false));
    if ($scrapers === []) {
        fwrite(STDERR, "Nenhuma fonte tem \"{$soFonte}\" no nome.\n");
        exit(1);
    }
}

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

if ($soFonte !== null) {
    $db->aplicarLinksOficiais();
    $linksOrganizador = $db->aplicarLinksOrganizadores($organizadoras);
    $db->atualizarCategorias();
    printf(
        "\nTotal: %d novas, %d atualizadas, %d juntas a provas de outras fontes, %d repetidas antigas consolidadas, "
        . "%d links do organizador (re)aplicados.\n(Só %s: as páginas das provas são lidas na execução completa.)\n",
        $totalInseridas,
        $totalAtualizadas,
        $totalJuntadas,
        $consolidadas,
        $linksOrganizador,
        implode(', ', array_map(fn (ScraperInterface $s) => $s->nome(), $scrapers))
    );
    foreach ($avisos as $aviso) {
        fwrite(STDERR, "  - {$aviso}\n");
    }
    exit(0);
}

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

// Link oficial das provas dos calendários (All4Running, Portugal Running): a
// listagem só tem a página da prova no calendário; o site oficial (ou a página
// de inscrições) está nessa página. Cada página é lida uma vez (tabela
// links_oficiais), até 50 por fonte e por execução; o link da prova passa
// depois a ser o oficial. Aproveita-se a mesma página para a imagem da prova.
// A Portugal Running não recusa pedidos seguidos: vai de 2 em 2 segundos, com
// mais tempo por pedido (as páginas têm ~330 KB e são lentas).
$fontesComLinkOficial = [
    'All4Running' => [All4RunningScraper::PREFIXO_PAGINA_PROVA, All4RunningScraper::extrairLinkOficial(...), $httpPaginas],
    'Portugal Running' => [PortugalRunningScraper::PREFIXO_PAGINA_PROVA, PortugalRunningScraper::extrairLinkOficial(...), new HttpClient(25, false, 2000)],
];
foreach ($fontesComLinkOficial as $nomeFonte => [$prefixo, $extrairLink, $httpFonte]) {
    $paginasLidas = 0;
    $linksOficiaisEncontrados = 0;
    $jaEsperou = false;
    $recusada = false;
    foreach ($db->paginasSemLinkOficial($prefixo, 50) as $pagina) {
        try {
            $html = $httpFonte->get($pagina);
        } catch (Throwable $e) {
            if ($e->getCode() !== HttpClient::DEMASIADOS_PEDIDOS) {
                continue; // página com problemas: fica para a próxima execução
            }
            if ($jaEsperou) {
                $recusou($e, $pagina);
                $recusada = true;
                break; // voltou a recusar: o resto fica para a próxima execução
            }
            $jaEsperou = true;
            sleep(30); // a primeira recusa costuma ser passageira
            continue;
        }
        $linkOficial = $extrairLink($html);
        $db->gravarLinkOficial($pagina, $linkOficial);
        $imagem = ImagemEventoHelper::extrair($html, $pagina);
        if ($imagem !== null) {
            $db->gravarImagemDaPagina($pagina, $imagem);
        }
        $paginasLidas++;
        $linksOficiaisEncontrados += $linkOficial !== null ? 1 : 0;
    }
    printf(
        "\nLinks oficiais (%s): %d páginas lidas, %d com link oficial%s.\n",
        $nomeFonte,
        $paginasLidas,
        $linksOficiaisEncontrados,
        $recusada ? ' (o site recusou mais pedidos; continua na próxima execução)' : ''
    );
}
$db->aplicarLinksOficiais();

// O link das fontes organizadoras é a página oficial da prova: tem prioridade
// sobre o de qualquer outra fonte (inclui as que falharam nesta execução, pelos
// links gravados em execuções anteriores).
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

// Hora, programa e organizador: lidos nas páginas das provas nas plataformas
// de inscrições (Lap2Go, Stop and Go, Acorrer) e na Xistarca —
// DetalhesProvaHelper. Cada prova é lida uma vez por semana, até 120 por execução.
$provasDetalhes = $db->provasParaProcurarDetalhes(120, DetalhesProvaHelper::SITES);
$httpDetalhes = new HttpClient(15, false, 2000); // de 2 em 2 segundos ao mesmo site
$detalhesLidos = 0;
foreach ($provasDetalhes as $corridaId => $paginas) {
    $detalhes = [];
    $algumaRespondeu = false;
    foreach ($paginas as $pagina) {
        if ($siteRecusado($pagina)) {
            continue;
        }
        try {
            $html = $httpDetalhes->get($pagina);
        } catch (Throwable $e) {
            $recusou($e, $pagina);
            continue;
        }
        $algumaRespondeu = true;
        // A primeira página com cada dado fica com ele.
        $detalhes += array_filter(DetalhesProvaHelper::extrair($html, $pagina), fn ($v) => $v !== null);
    }
    if ($algumaRespondeu) {
        $db->gravarDetalhes($corridaId, $detalhes);
        $detalhesLidos += $detalhes !== [] ? 1 : 0;
    }
}
printf("
Detalhes (hora, organizador): %d páginas de provas lidas, %d com dados.
", count($provasDetalhes), $detalhesLidos);

// Prémios monetários: lê a página de cada prova e o regulamento (PDF ou
// página) para que aponta — ProcuraPremios. Sem número fixo de provas: vê
// as que couberem em 10 minutos, primeiro as nunca vistas (pela data da
// prova); a seguinte execução continua onde esta parou. Para pôr tudo em dia
// de uma vez: php scripts/procurar_premios.php
// Pedidos de 2 em 2 segundos ao mesmo site, com mais tempo para os PDFs.
$procuraPremios = new ProcuraPremios(
    $db,
    new HttpClient(25, false, 2000),
    [All4RunningScraper::PREFIXO_PAGINA_PROVA],
    ['all4running.pt' => $httpPaginas] // o mesmo de 5 em 5 s das fases anteriores
);
foreach (array_keys($sitesRecusados) as $site) {
    $procuraPremios->marcarSiteRecusado($site);
}
$premios = $procuraPremios->executar(10);
printf(
    "\nPrémios monetários: procurados em %d provas — %d com, %d sem, %d por saber%s.\n",
    $premios['vistas'],
    $premios['sim'],
    $premios['nao'],
    $premios['por_saber'],
    $premios['faltam'] > 0 ? "; faltam {$premios['faltam']} (continua na próxima execução)" : ''
);

// Valor das inscrições: mesmas páginas, mas primeiro as das plataformas (os
// preços estão em tabelas) e só depois os regulamentos — ProcuraInscricoes.
// Até 8 minutos por execução; para pôr tudo em dia: php scripts/procurar_inscricoes.php
$procuraInscricoes = new ProcuraInscricoes(
    $db,
    new HttpClient(25, false, 2000),
    [All4RunningScraper::PREFIXO_PAGINA_PROVA],
    ['all4running.pt' => $httpPaginas]
);
foreach (array_keys($sitesRecusados) as $site) {
    $procuraInscricoes->marcarSiteRecusado($site);
}
$inscricoes = $procuraInscricoes->executar(8);
printf(
    "\nInscrições: procuradas em %d provas — %d com valor, %d gratuitas, %d por saber%s.\n",
    $inscricoes['vistas'],
    $inscricoes['com_valor'],
    $inscricoes['gratuitas'],
    $inscricoes['por_saber'],
    $inscricoes['faltam'] > 0 ? "; faltam {$inscricoes['faltam']} (continua na próxima execução)" : ''
);

// Coordenadas das localidades (botão "Perto de mim"): Nominatim/OpenStreetMap,
// um pedido por segundo; reaproveita as de outra prova com o mesmo local.
// Até 150 provas por execução (as mais próximas primeiro).
// Para localizar todas de uma vez: php scripts/localizar_provas.php
$coordenadas = (new Geocodificador())->localizarProvas($db, 150);
if ($coordenadas['recusado'] !== null) {
    $avisos[] = "Coordenadas: o OpenStreetMap recusou pedidos ({$coordenadas['recusado']}); continua na próxima execução.";
}
printf("\nCoordenadas: %d provas vistas, %d localizadas.\n", $coordenadas['vistas'], $coordenadas['encontradas']);

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
