<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

$auth->exigirLogin('/');

if (!$auth->validarCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    die('Pedido inválido (csrf).');
}

$corridaId = (int) ($_POST['corrida_id'] ?? 0);
$voltar = Auth::caminhoSeguro($_POST['voltar'] ?? null);

$utilizador = $auth->utilizadorAtual();

if ($corridaId > 0 && $utilizador !== null) {
    $db->alternarFavorito((int) $utilizador['id'], $corridaId);
}

header('Location: ' . $voltar);
