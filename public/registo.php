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
<?php $tituloDocumento = 'Criar conta — Corridas'; require __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php require __DIR__ . '/partials/cabecalho.php'; ?>
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
        <a class="botao-google" href="<?= htmlspecialchars($googleUrl) ?>"><svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>Continuar com o Google</a>

        <p class="rodape-auth">Já tens conta? <a href="/login.php">Entrar</a></p>
    </div>
</main>
</body>
</html>
