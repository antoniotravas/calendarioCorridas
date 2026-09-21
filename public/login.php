<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if ($auth->autenticado()) {
    header('Location: /');
    exit;
}

$next = Auth::caminhoSeguro($_GET['next'] ?? ($_POST['next'] ?? null));
$erro = null;
$emailSubmetido = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validarCsrf($_POST['csrf_token'] ?? null)) {
        $erro = 'Pedido inválido, tenta novamente.';
    } else {
        $emailSubmetido = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $utilizador = $db->encontrarUtilizadorPorEmail($emailSubmetido);

        if ($utilizador !== null && $utilizador['password_hash'] !== null
            && password_verify($password, $utilizador['password_hash'])
        ) {
            $auth->iniciarSessao((int) $utilizador['id']);
            header('Location: ' . $next);
            exit;
        }

        $erro = 'Email ou password incorretos.';
    }
}

$googleUrl = '/auth/google-iniciar.php?next=' . urlencode($next);

?><!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar — Calendário de Corridas</title>
<link rel="stylesheet" href="/assets/estilo.css">
</head>
<body>
<main>
    <div class="auth-wrap">
        <h1>Entrar</h1>

        <?php if ($erro !== null): ?>
            <div class="aviso-erro"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($auth->tokenCsrf()) ?>">
            <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

            <div class="campo">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($emailSubmetido) ?>" required>
            </div>
            <div class="campo">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" class="botao-principal">Entrar</button>
        </form>

        <div class="separador">ou</div>
        <a class="botao-google" href="<?= htmlspecialchars($googleUrl) ?>">Continuar com o Google</a>

        <p class="rodape-auth">Ainda não tens conta? <a href="/registo.php">Criar conta</a></p>
    </div>
</main>
</body>
</html>
