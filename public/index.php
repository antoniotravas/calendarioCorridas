<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/** Provas por página: as seguintes carregam à medida que se desce (assets/app.js). */
const PROVAS_POR_PAGINA = 20;

/** Filtro "Tipo": valor de corridas.categoria => texto. */
const CATEGORIAS = ['estrada' => 'Estrada', 'trail' => 'Trail', 'corta-mato' => 'Corta-mato', 'pista' => 'Pista'];

/** Filtro "Prémios": valor do parâmetro => [texto da opção, corridas.premios_monetarios]. */
const FILTRO_PREMIOS = ['sim' => ['Com prémios monetários', true], 'nao' => ['Sem prémios monetários', false]];

$mes = isset($_GET['mes']) && $_GET['mes'] !== '' ? (int) $_GET['mes'] : null;
$fonte = isset($_GET['fonte']) && $_GET['fonte'] !== '' ? (string) $_GET['fonte'] : null;
$procura = isset($_GET['procura']) && trim((string) $_GET['procura']) !== '' ? trim((string) $_GET['procura']) : null;
$distanciaChave = isset($_GET['distancia']) && $_GET['distancia'] !== '' ? (string) $_GET['distancia'] : null;
$distanciaFaixa = DistanciaHelper::intervaloPorChave($distanciaChave);
$categoria = isset($_GET['tipo']) && isset(CATEGORIAS[$_GET['tipo']]) ? (string) $_GET['tipo'] : null;
$premiosChave = isset($_GET['premios']) && isset(FILTRO_PREMIOS[$_GET['premios']]) ? (string) $_GET['premios'] : null;
$premios = $premiosChave !== null ? FILTRO_PREMIOS[$premiosChave][1] : null;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
// ?parcial=1: só a lista desta página, para o JavaScript a acrescentar à que já está à vista.
$parcial = isset($_GET['parcial']);

$utilizadorAtual = $auth->utilizadorAtual();
$total = $db->contarCorridas($mes, $fonte, $procura, $distanciaFaixa, $categoria, $premios);
$corridas = $db->listarCorridas(
    $mes,
    $fonte,
    $procura,
    $distanciaFaixa,
    $utilizadorAtual['id'] ?? null,
    PROVAS_POR_PAGINA,
    ($pagina - 1) * PROVAS_POR_PAGINA,
    $categoria,
    $premios
);
$grupos = DateHelper::agruparPorMes($corridas);

$filtrosAtivos = array_filter(
    ['tipo' => $categoria, 'mes' => $mes, 'distancia' => $distanciaChave, 'fonte' => $fonte, 'premios' => $premiosChave, 'procura' => $procura],
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

$fontesDisponiveis = $db->listarFontes();
$tituloDocumento = 'Calendário de corridas em Portugal — estrada, trail, corta-mato e pista';

/**
 * Frase do total, de acordo com os filtros escolhidos, ex.:
 * "12 provas de trail em novembro, de 5 a 10 km, encontradas em All4Running".
 */
$resumo = ($total === 1 ? '1 prova' : $total . ' provas')
    . ($categoria !== null ? ' de ' . mb_strtolower(CATEGORIAS[$categoria]) : '')
    . ($mes !== null ? ' em ' . DateHelper::nomeMes($mes) : ' a partir de hoje');
$detalhes = [];
if ($distanciaChave !== null && isset(DistanciaHelper::FAIXAS[$distanciaChave])) {
    $faixa = mb_strtolower(trim(preg_replace('/\(.*\)/', '', DistanciaHelper::FAIXAS[$distanciaChave]['label'])));
    $detalhes[] = match (true) {
        str_starts_with($faixa, 'mais') => 'com ' . $faixa,
        ctype_digit($faixa[0]) => 'de ' . $faixa,
        default => $faixa,
    };
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
    <form class="filtros" method="get" action="/" role="search">
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
            <span class="sr-only">Fonte</span>
            <select name="fonte">
                <option value="">Fonte</option>
                <?php foreach ($fontesDisponiveis as $f): ?>
                    <option value="<?= htmlspecialchars($f) ?>" <?= $fonte === $f ? 'selected' : '' ?>><?= htmlspecialchars($f) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="filtro-premios">
            <span class="sr-only">Prémios monetários</span>
            <select name="premios">
                <option value="">Prémios €</option>
                <?php foreach (FILTRO_PREMIOS as $valor => [$nome]): ?>
                    <option value="<?= htmlspecialchars($valor) ?>" <?= $premiosChave === $valor ? 'selected' : '' ?>><?= htmlspecialchars($nome) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <button type="submit" class="filtrar">Procurar</button>
    </form>
</div>

<main>
    <h1 class="sr-only">Calendário de corridas em Portugal</h1>

    <p class="resumo" aria-live="polite">
        <?= htmlspecialchars($resumo) ?>
        <?php if ($filtrosAtivos !== []): ?>
            <a href="/">Limpar filtros</a>
        <?php endif; ?>
    </p>

    <?php if ($corridas === []): ?>
        <p class="vazio">Nenhuma prova com estes filtros.</p>
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
