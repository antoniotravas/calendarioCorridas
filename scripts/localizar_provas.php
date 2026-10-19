<?php

declare(strict_types=1);

/**
 * Procura as coordenadas (botão "Perto de mim") de todas as provas futuras
 * que ainda não as têm, sem correr o resto do scraper. O scraper diário só
 * localiza 150 por execução; isto serve para a primeira vez.
 *
 * Uso:
 *   php scripts/localizar_provas.php
 *
 * Um pedido por segundo ao OpenStreetMap (regra do serviço): ~10 minutos por
 * 600 localidades diferentes. Pode ser interrompido (Ctrl+C) e voltar a correr.
 */

require __DIR__ . '/lib/HttpClient.php';
require __DIR__ . '/lib/Database.php';
require __DIR__ . '/lib/DistritoHelper.php';
require __DIR__ . '/lib/Geocodificador.php';

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Falta o ficheiro scripts/config.php.\n");
    exit(1);
}
$db = new Database(require $configPath);

echo "A localizar as provas sem coordenadas...\n";
$resultado = (new Geocodificador())->localizarProvas($db, 5000, function (int $id, ?array $ponto): void {
    static $n = 0;
    $n++;
    echo sprintf("  %3d. prova %d — %s\n", $n, $id, $ponto !== null ? sprintf('%.4f, %.4f', ...$ponto) : 'não encontrada');
});

printf("\nFeito: %d provas vistas, %d localizadas.\n", $resultado['vistas'], $resultado['encontradas']);
if ($resultado['recusado'] !== null) {
    echo "O OpenStreetMap recusou pedidos ({$resultado['recusado']}): tenta outra vez mais tarde.\n";
}
