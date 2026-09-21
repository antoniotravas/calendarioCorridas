<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $auth->validarCsrf($_POST['csrf_token'] ?? null)) {
    $auth->terminarSessao();
}

header('Location: /');
