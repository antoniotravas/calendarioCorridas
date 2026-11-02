<?php

declare(strict_types=1);

/**
 * Preenche o distrito das provas portuguesas que ainda não o têm (coluna corridas.distrito),
 * a partir do local, com DistritoHelper. As provas espanholas recebem a província do scraper.
 *
 * Uso (depois da migração 019):
 *   php scripts/preencher_distritos.php
 * Pode correr-se mais do que uma vez: só mexe nas provas sem distrito.
 */

require __DIR__ . '/lib/DistritoHelper.php';

$config = require __DIR__ . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'],
    $config['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$linhas = $pdo->query("SELECT id, local, regiao FROM corridas WHERE pais = 'PT' AND distrito IS NULL")->fetchAll();
$atualizar = $pdo->prepare('UPDATE corridas SET distrito = :distrito WHERE id = :id');

$preenchidas = 0;
$semDistrito = 0;
foreach ($linhas as $linha) {
    $distrito = DistritoHelper::localizar($linha['local'], $linha['regiao'])['distrito'];
    if ($distrito === null) {
        $semDistrito++;
        continue;
    }
    $atualizar->execute(['distrito' => $distrito, 'id' => $linha['id']]);
    $preenchidas++;
}

echo "Distritos preenchidos: {$preenchidas}\n";
echo "Sem distrito reconhecido (ficam de fora do filtro): {$semDistrito}\n";
