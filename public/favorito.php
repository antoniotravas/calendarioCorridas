<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

// O JavaScript (assets/app.js) pede JSON para guardar a prova sem recarregar a
// página — e sem perder as provas já carregadas mais abaixo. Sem JavaScript, o
// formulário volta para a página de onde veio.
$querJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

$responderJson = static function (int $codigo, array $dados): never {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados);
    exit;
};

if ($querJson && !$auth->autenticado()) {
    $responderJson(401, ['erro' => 'É preciso entrar na conta.']);
}

$auth->exigirLogin('/');

if (!$auth->validarCsrf($_POST['csrf_token'] ?? null)) {
    if ($querJson) {
        $responderJson(400, ['erro' => 'Pedido inválido (csrf).']);
    }
    http_response_code(400);
    die('Pedido inválido (csrf).');
}

$corridaId = (int) ($_POST['corrida_id'] ?? 0);
$voltar = Auth::caminhoSeguro($_POST['voltar'] ?? null);

$utilizador = $auth->utilizadorAtual();

$ativo = null;
if ($corridaId > 0 && $utilizador !== null) {
    $ativo = $db->alternarFavorito((int) $utilizador['id'], $corridaId);
}

if ($querJson) {
    $ativo === null
        ? $responderJson(400, ['erro' => 'Prova inválida.'])
        : $responderJson(200, ['ativo' => $ativo]);
}

header('Location: ' . $voltar);
