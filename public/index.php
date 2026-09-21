<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$mes = isset($_GET['mes']) && $_GET['mes'] !== '' ? (int) $_GET['mes'] : null;
$fonte = isset($_GET['fonte']) && $_GET['fonte'] !== '' ? (string) $_GET['fonte'] : null;
$procura = isset($_GET['procura']) && trim((string) $_GET['procura']) !== '' ? trim((string) $_GET['procura']) : null;
$distanciaChave = isset($_GET['distancia']) && $_GET['distancia'] !== '' ? (string) $_GET['distancia'] : null;
$distanciaFaixa = DistanciaHelper::intervaloPorChave($distanciaChave);

$utilizadorAtual = $auth->utilizadorAtual();
$corridas = $db->listarCorridas(
    $mes,
    $fonte,
    $procura,
    $distanciaFaixa,
    $utilizadorAtual['id'] ?? null
);
$fontesDisponiveis = $db->listarFontes();
$grupos = DateHelper::agruparPorMes($corridas);

$tituloPagina = 'Calendário de Corridas de Estrada';
$subtituloPagina = 'Provas de atletismo em estrada em Portugal, recolhidas automaticamente de fontes portuguesas.';

?><!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($tituloPagina) ?></title>
<link rel="stylesheet" href="/assets/estilo.css">
</head>
<body>

<?php require __DIR__ . '/partials/cabecalho.php'; ?>

<main>
    <form class="filtros" method="get">
        <select name="mes">
            <option value="">Todos os meses</option>
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $mes === $m ? 'selected' : '' ?>>
                    <?= ucfirst(DateHelper::nomeMes($m)) ?>
                </option>
            <?php endfor; ?>
        </select>

        <select name="distancia">
            <option value="">Todas as distâncias</option>
            <?php foreach (DistanciaHelper::FAIXAS as $chave => $faixa): ?>
                <option value="<?= htmlspecialchars($chave) ?>" <?= $distanciaChave === $chave ? 'selected' : '' ?>>
                    <?= htmlspecialchars($faixa['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="fonte">
            <option value="">Todas as fontes</option>
            <?php foreach ($fontesDisponiveis as $f): ?>
                <option value="<?= htmlspecialchars($f) ?>" <?= $fonte === $f ? 'selected' : '' ?>>
                    <?= htmlspecialchars($f) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input type="text" name="procura" placeholder="Pesquisar por nome ou local..."
               value="<?= htmlspecialchars($procura ?? '') ?>">

        <button type="submit">Filtrar</button>
        <a class="limpar" href="?">Limpar filtros</a>
    </form>

    <?php require __DIR__ . '/partials/lista-corridas.php'; ?>
</main>

<footer>
    <?= count($corridas) ?> provas encontradas · CalendarioCorridas
</footer>

</body>
</html>
