<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if ($auth->autenticado()) {
    header('Location: /');
    exit;
}

$next = Auth::caminhoSeguro($_GET['next'] ?? ($_POST['next'] ?? null));
$erro = null;
$nomeSubmetido = '';
$emailSubmetido = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validarCsrf($_POST['csrf_token'] ?? null)) {
        $erro = 'Pedido inválido, tenta novamente.';
    } else {
        $nomeSubmetido = trim((string) ($_POST['nome'] ?? ''));
        $emailSubmetido = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirmacao = (string) ($_POST['password_confirmacao'] ?? '');

        if ($nomeSubmetido === '') {
            $erro = 'Indica o teu nome.';
        } elseif (!filter_var($emailSubmetido, FILTER_VALIDATE_EMAIL)) {
            $erro = 'Indica um email válido.';
        } elseif (mb_strlen($password) < 8) {
            $erro = 'A password deve ter pelo menos 8 caracteres.';
        } elseif ($password !== $passwordConfirmacao) {
            $erro = 'As passwords não coincidem.';
        } elseif ($db->encontrarUtilizadorPorEmail($emailSubmetido) !== null) {
            $erro = 'Já existe uma conta com este email.';
        } else {
            $id = $db->criarUtilizadorLocal($nomeSubmetido, $emailSubmetido, password_hash($password, PASSWORD_DEFAULT));
            $auth->iniciarSessao($id);
            header('Location: ' . $next);
            exit;
        }
    }
}

$googleUrl = '/auth/google-iniciar.php?next=' . urlencode($next);

?><!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Criar conta — Calendário de Corridas</title>
<link rel="stylesheet" href="/assets/estilo.css">
</head>
<body>
<main>
    <div class="auth-wrap">
        <h1>Criar conta</h1>

        <?php if ($erro !== null): ?>
            <div class="aviso-erro"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($auth->tokenCsrf()) ?>">
            <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

            <div class="campo">
                <label for="nome">Nome</label>
                <input type="text" id="nome" name="nome" value="<?= htmlspecialchars($nomeSubmetido) ?>" required>
            </div>
            <div class="campo">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($emailSubmetido) ?>" required>
            </div>
            <div class="campo">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" minlength="8" required>
            </div>
            <div class="campo">
                <label for="password_confirmacao">Confirmar password</label>
                <input type="password" id="password_confirmacao" name="password_confirmacao" minlength="8" required>
            </div>

            <button type="submit" class="botao-principal">Criar conta</button>
        </form>

        <div class="separador">ou</div>
        <a class="botao-google" href="<?= htmlspecialchars($googleUrl) ?>">Continuar com o Google</a>

        <p class="rodape-auth">Já tens conta? <a href="/login.php">Entrar</a></p>
    </div>
</main>
</body>
</html>
