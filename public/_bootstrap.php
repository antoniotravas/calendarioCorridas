<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/../scripts/lib/Corrida.php';
require __DIR__ . '/../scripts/lib/DateHelper.php';
require __DIR__ . '/../scripts/lib/DistanciaHelper.php';
require __DIR__ . '/../scripts/lib/HttpClient.php';
require __DIR__ . '/../scripts/lib/Database.php';
require __DIR__ . '/lib/Auth.php';

$configPath = __DIR__ . '/../scripts/config.php';

if (!is_file($configPath)) {
    http_response_code(500);
    die('Falta scripts/config.php — copia scripts/config.example.php e ajusta as credenciais da base de dados.');
}

$config = require $configPath;
$db = new Database($config);
$auth = new Auth($db);
