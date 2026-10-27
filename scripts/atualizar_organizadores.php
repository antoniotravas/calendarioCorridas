<?php

declare(strict_types=1);

/**
 * Recalcula o radar dos organizadores (tabela organizadores, página
 * /organizadores.php) sem correr o scraper — o scraper já o faz no fim de
 * cada execução. Mostra os candidatos a nova fonte: sites sem scraper com
 * pelo menos RadarOrganizadores::MINIMO_PROVAS provas no último ano.
 *
 * Uso: php scripts/atualizar_organizadores.php
 */

require __DIR__ . '/lib/Corrida.php';
require __DIR__ . '/lib/Database.php';
require __DIR__ . '/lib/RadarOrganizadores.php';

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Falta o ficheiro scripts/config.php.\n");
    exit(1);
}

$db = new Database(require $configPath);
$total = RadarOrganizadores::atualizar($db);
$candidatos = array_filter($db->listarOrganizadores(), fn (array $o) => RadarOrganizadores::eCandidato($o));

printf("Radar dos organizadores: %d sites, %d candidatos a fonte (sem scraper, %d ou mais provas no último ano).\n\n",
    $total, count($candidatos), RadarOrganizadores::MINIMO_PROVAS);
foreach ($candidatos as $o) {
    printf("%3d provas (%d futuras)  %-32s %s\n", $o['provas_ano'], $o['provas_futuras'], $o['dominio'], $o['nome'] ?? '');
}
