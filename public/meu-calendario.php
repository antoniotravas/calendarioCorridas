<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$auth->exigirLogin('/meu-calendario.php');

$utilizador = $auth->utilizadorAtual();
$corridas = $db->listarCorridasFavoritas((int) $utilizador['id']);
$grupos = DateHelper::agruparPorMes($corridas);

$tituloPagina = 'O Meu Calendário';
$subtituloPagina = 'Provas que guardaste, ' . $utilizador['nome'] . '.';

?><!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($tituloPagina) ?> — Calendário de Corridas</title>
<link rel="stylesheet" href="/assets/estilo.css">
</head>
<body>

<?php require __DIR__ . '/partials/cabecalho.php'; ?>

<main>
    <?php if ($corridas === []): ?>
        <p class="vazio">
            Ainda não guardaste nenhuma prova. Vai ao <a href="/">calendário</a> e clica em
            "+ Adicionar" nas provas que queres seguir.
        </p>
    <?php else: ?>
        <?php require __DIR__ . '/partials/lista-corridas.php'; ?>
    <?php endif; ?>
</main>

<footer>
    <?= count($corridas) ?> provas no teu calendário · CalendarioCorridas
</footer>

</body>
</html>
