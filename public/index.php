<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/** Provas por página: as seguintes carregam à medida que se desce (assets/app.js). */
const PROVAS_POR_PAGINA = 20;

/** Filtro "Tipo": valor de corridas.categoria => texto. */
const CATEGORIAS = ['estrada' => 'Estrada', 'trail' => 'Trail', 'corta-mato' => 'Corta-mato', 'pista' => 'Pista'];

/** "Perto de mim": provas a menos de RAIO_PERTO_KM, no período escolhido. */
const RAIO_PERTO_KM = 50;
/** Períodos do "Perto de mim": valor de ?periodo= => [texto, até quando (strtotime), null = sempre]. O primeiro é o inicial. */
const PERIODOS_PERTO = [
    '3d' => ['Próximos 3 dias', '+2 days'],
    '1m' => ['Próximo mês', '+1 month'],
    '2m' => ['Próximos 2 meses', '+2 months'],
    '3m' => ['Próximos 3 meses', '+3 months'],
    'sempre' => ['Sempre', null],
];

/** Filtro "Prémios": valor do parâmetro => [texto da opção, corridas.premios_monetarios]. */
const FILTRO_PREMIOS = ['sim' => ['Com prémios monetários', true], 'nao' => ['Sem prémios monetários', false]];

$mes = isset($_GET['mes']) && $_GET['mes'] !== '' ? (int) $_GET['mes'] : null;
// O filtro "Fonte" só existe para administradores.
$podeFiltrarFonte = $auth->eAdmin();
$fonte = $podeFiltrarFonte && isset($_GET['fonte']) && $_GET['fonte'] !== '' ? (string) $_GET['fonte'] : null;
$procura = isset($_GET['procura']) && trim((string) $_GET['procura']) !== '' ? trim((string) $_GET['procura']) : null;
$distanciaChave = isset($_GET['distancia']) && $_GET['distancia'] !== '' ? (string) $_GET['distancia'] : null;
$distanciaFaixa = DistanciaHelper::intervaloPorChave($distanciaChave);
// Valor da inscrição (para todos): faixas em InscricaoHelper::FAIXAS, pelo valor mais baixo.
$inscricaoChave = isset($_GET['inscricao']) && isset(InscricaoHelper::FAIXAS[$_GET['inscricao']]) ? (string) $_GET['inscricao'] : null;
$inscricaoFaixa = InscricaoHelper::intervaloPorChave($inscricaoChave);
$categoria = isset($_GET['tipo']) && isset(CATEGORIAS[$_GET['tipo']]) ? (string) $_GET['tipo'] : null;
// Os prémios monetários só são visíveis para quem tem acesso (ver Auth::podeVerPremios).
$podeVerPremios = $auth->podeVerPremios();
$premiosChave = $podeVerPremios && isset($_GET['premios']) && isset(FILTRO_PREMIOS[$_GET['premios']]) ? (string) $_GET['premios'] : null;
$premios = $premiosChave !== null ? FILTRO_PREMIOS[$premiosChave][1] : null;
// ?perto=41.15,-8.61 (posição do browser, arredondada a ~1 km pelo assets/app.js; não é guardada).
$perto = null;
$periodoChave = isset($_GET['periodo']) && isset(PERIODOS_PERTO[$_GET['periodo']]) ? (string) $_GET['periodo'] : array_key_first(PERIODOS_PERTO);
if (isset($_GET['perto']) && preg_match('/^(-?\d{1,2}(?:\.\d{1,4})?),(-?\d{1,3}(?:\.\d{1,4})?)$/', (string) $_GET['perto'], $m)) {
    $ate = PERIODOS_PERTO[$periodoChave][1];
    $perto = ['lat' => (float) $m[1], 'lon' => (float) $m[2], 'raio' => RAIO_PERTO_KM, 'ate' => $ate !== null ? date('Y-m-d', strtotime($ate)) : null];
}
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
// ?parcial=1: só a lista desta página, para o JavaScript a acrescentar à que já está à vista.
$parcial = isset($_GET['parcial']);

$utilizadorAtual = $auth->utilizadorAtual();
$total = $db->contarCorridas($mes, $fonte, $procura, $distanciaFaixa, $categoria, $premios, $inscricaoFaixa, $perto);
$corridas = $db->listarCorridas(
    $mes,
    $fonte,
    $procura,
    $distanciaFaixa,
    $utilizadorAtual['id'] ?? null,
    PROVAS_POR_PAGINA,
    ($pagina - 1) * PROVAS_POR_PAGINA,
    $categoria,
    $premios,
    $inscricaoFaixa,
    $perto
);
$grupos = DateHelper::agruparPorMes($corridas);

$filtrosAtivos = array_filter(
    ['tipo' => $categoria, 'mes' => $mes, 'distancia' => $distanciaChave, 'inscricao' => $inscricaoChave, 'fonte' => $fonte, 'premios' => $premiosChave, 'procura' => $procura,
     'perto' => $perto !== null ? $_GET['perto'] : null,
     'periodo' => $perto !== null && $periodoChave !== array_key_first(PERIODOS_PERTO) ? $periodoChave : null],
    static fn ($v) => $v !== null
);
$voltar = '/' . ($filtrosAtivos !== [] ? '?' . http_build_query($filtrosAtivos) : '');
$haMais = $pagina * PROVAS_POR_PAGINA < $total;
$urlSeguinte = '/?' . http_build_query($filtrosAtivos + ['pagina' => $pagina + 1]);

$blocoMais = static function () use ($haMais, $urlSeguinte): void {
    if ($haMais): ?>
        <a class="mais" href="<?= htmlspecialchars($urlSeguinte) ?>"
           data-parcial="<?= htmlspecialchars($urlSeguinte . '&parcial=1') ?>">Mostrar mais provas</a>
    <?php endif;
};

if ($parcial) {
    require __DIR__ . '/partials/lista-corridas.php';
    $blocoMais();
    exit;
}

$fontesDisponiveis = $podeFiltrarFonte ? $db->listarFontes() : [];
// Número de listas de filtros, para a grelha (ver form.filtros no CSS).
$numeroFiltros = 4 + ($podeFiltrarFonte ? 1 : 0) + ($podeVerPremios ? 1 : 0);
$tituloDocumento = 'Calendário de corridas em Portugal — estrada, trail, corta-mato e pista';

/**
 * Frase do total, de acordo com os filtros escolhidos, ex.:
 * "12 provas de trail em novembro, de 5 a 10 km, encontradas em All4Running".
 */
$resumo = ($total === 1 ? '1 prova' : $total . ' provas')
    . ($categoria !== null ? ' de ' . mb_strtolower(CATEGORIAS[$categoria]) : '')
    . match (true) {
        $perto !== null && $perto['ate'] === null => ' até ' . RAIO_PERTO_KM . ' km de ti, a partir de hoje',
        $perto !== null => ' até ' . RAIO_PERTO_KM . ' km de ti, de hoje a ' . mb_strtolower(DateHelper::formatarDiaMes($perto['ate'])),
        $mes !== null => ' em ' . DateHelper::nomeMes($mes),
        default => ' a partir de hoje',
    };
$detalhes = [];
if ($distanciaChave !== null && isset(DistanciaHelper::FAIXAS[$distanciaChave])) {
    $faixa = mb_strtolower(trim(preg_replace('/\(.*\)/', '', DistanciaHelper::FAIXAS[$distanciaChave]['label'])));
    $detalhes[] = match (true) {
        str_starts_with($faixa, 'mais') => 'com ' . $faixa,
        ctype_digit($faixa[0]) => 'de ' . $faixa,
        default => $faixa,
    };
}
if ($inscricaoChave !== null) {
    $detalhes[] = $inscricaoChave === 'gratuita'
        ? 'com inscrição gratuita'
        : 'com inscrição ' . mb_strtolower(InscricaoHelper::FAIXAS[$inscricaoChave]['label']);
}
if ($premiosChave !== null) {
    $detalhes[] = mb_strtolower(FILTRO_PREMIOS[$premiosChave][0]);
}
if ($fonte !== null) {
    $detalhes[] = ($total === 1 ? 'encontrada em ' : 'encontradas em ') . $fonte;
}
if ($procura !== null) {
    $detalhes[] = 'com «' . $procura . '»';
}
if ($detalhes !== []) {
    $resumo .= ', ' . implode(', ', $detalhes);
}

?><!DOCTYPE html>
<html lang="pt-PT">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body>

<?php require __DIR__ . '/partials/cabecalho.php'; ?>

<div class="barra-filtros">
    <form class="filtros<?= $podeVerPremios ? '' : ' sem-premios' ?>" method="get" action="/" role="search"
          style="--filtros: <?= $numeroFiltros ?>; --colunas: <?= $numeroFiltros - ($podeVerPremios ? 1 : 0) ?>">
        <label class="procura">
            <span class="sr-only">Procurar prova ou localidade</span>
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
            <input type="search" name="procura" placeholder="Prova ou localidade"
                   value="<?= htmlspecialchars($procura ?? '') ?>" autocomplete="off">
        </label>

        <label>
            <span class="sr-only">Tipo de corrida</span>
            <select name="tipo">
                <option value="">Tipo</option>
                <?php foreach (CATEGORIAS as $valor => $nome): ?>
                    <option value="<?= htmlspecialchars($valor) ?>" <?= $categoria === $valor ? 'selected' : '' ?>><?= htmlspecialchars($nome) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            <span class="sr-only">Mês</span>
            <select name="mes">
                <option value="">Mês</option>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $mes === $m ? 'selected' : '' ?>><?= ucfirst(DateHelper::nomeMes($m)) ?></option>
                <?php endfor; ?>
            </select>
        </label>

        <label>
            <span class="sr-only">Distância</span>
            <select name="distancia">
                <option value="">Distância</option>
                <?php foreach (DistanciaHelper::FAIXAS as $chave => $faixa): ?>
                    <option value="<?= htmlspecialchars($chave) ?>" <?= $distanciaChave === $chave ? 'selected' : '' ?>>
                        <?= htmlspecialchars($faixa['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            <span class="sr-only">Valor da inscrição</span>
            <select name="inscricao">
                <option value="">Inscrição</option>
                <?php foreach (InscricaoHelper::FAIXAS as $chave => $faixa): ?>
                    <option value="<?= htmlspecialchars($chave) ?>" <?= $inscricaoChave === $chave ? 'selected' : '' ?>><?= htmlspecialchars($faixa['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <?php if ($podeFiltrarFonte): ?>
        <label>
            <span class="sr-only">Fonte</span>
            <select name="fonte">
                <option value="">Fonte</option>
                <?php foreach ($fontesDisponiveis as $f): ?>
                    <option value="<?= htmlspecialchars($f) ?>" <?= $fonte === $f ? 'selected' : '' ?>><?= htmlspecialchars($f) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>

        <?php if ($podeVerPremios): ?>
        <label class="filtro-premios">
            <span class="sr-only">Prémios monetários</span>
            <select name="premios">
                <option value="">Prémios €</option>
                <?php foreach (FILTRO_PREMIOS as $valor => [$nome]): ?>
                    <option value="<?= htmlspecialchars($valor) ?>" <?= $premiosChave === $valor ? 'selected' : '' ?>><?= htmlspecialchars($nome) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>

        <?php if ($perto !== null): ?>
            <input type="hidden" name="perto" value="<?= htmlspecialchars((string) $_GET['perto']) ?>">
            <?php if (isset($filtrosAtivos['periodo'])): ?><input type="hidden" name="periodo" value="<?= htmlspecialchars($periodoChave) ?>"><?php endif; ?>
        <?php endif; ?>
        <button type="submit" class="filtrar">Procurar</button>
    </form>
</div>

<main>
    <h1 class="sr-only">Calendário de corridas em Portugal</h1>

    <div class="resumo" aria-live="polite">
        <?php /* Pede a posição ao browser (assets/app.js); sem JavaScript fica escondido. Ativo: volta à lista sem ele e escolhe-se o período. */ ?>
        <?php if ($perto !== null):
            $semPerto = array_diff_key($filtrosAtivos, ['perto' => 1, 'periodo' => 1]);
            ?>
            <a class="perto-de-mim ativo" href="<?= htmlspecialchars('/' . ($semPerto !== [] ? '?' . http_build_query($semPerto) : '')) ?>"
               title="Deixar de filtrar por proximidade">Perto de mim ✕</a>
            <form class="periodo-perto" method="get" action="/">
                <?php foreach (array_diff_key($filtrosAtivos, ['periodo' => 1]) as $nome => $valor): ?>
                    <input type="hidden" name="<?= htmlspecialchars($nome) ?>" value="<?= htmlspecialchars((string) $valor) ?>">
                <?php endforeach; ?>
                <label>
                    <span class="sr-only">Período</span>
                    <select name="periodo">
                        <?php foreach (PERIODOS_PERTO as $chave => [$texto]): ?>
                            <option value="<?= htmlspecialchars($chave) ?>" <?= $periodoChave === $chave ? 'selected' : '' ?>><?= htmlspecialchars($texto) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <noscript><button type="submit">OK</button></noscript>
            </form>
        <?php else: ?>
            <button type="button" class="perto-de-mim" data-perto hidden
                    title="Provas até <?= RAIO_PERTO_KM ?> km de ti (escolhes depois o período)">Perto de mim</button>
        <?php endif; ?>
        <?= htmlspecialchars($resumo) ?>
        <?php if ($filtrosAtivos !== []): ?>
            <a href="/">Limpar filtros</a>
        <?php endif; ?>
    </div>

    <?php if ($corridas === []): ?>
        <p class="vazio"><?= $perto !== null
            ? 'Nenhuma prova até ' . RAIO_PERTO_KM . ' km de ti ' . ($perto['ate'] !== null ? 'até ' . mb_strtolower(DateHelper::formatarDiaMes($perto['ate'])) : 'a partir de hoje')
                . '. Experimenta um período maior.'
            : 'Nenhuma prova com estes filtros.' ?></p>
    <?php else: ?>
        <div id="lista-provas">
            <?php require __DIR__ . '/partials/lista-corridas.php'; ?>
        </div>
        <?php $blocoMais(); ?>
    <?php endif; ?>
</main>

<button type="button" class="voltar-topo" hidden aria-label="Voltar ao topo">
    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6"/></svg>
</button>

<footer>
    Provas recolhidas automaticamente de sites portugueses de atletismo. Confirma sempre no site da prova.
</footer>

</body>
</html>
