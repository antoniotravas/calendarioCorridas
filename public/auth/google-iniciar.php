<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';

$next = Auth::caminhoSeguro($_GET['next'] ?? null);
$clientId = (string) ($config['google_client_id'] ?? '');
$redirectUri = (string) ($config['google_redirect_uri'] ?? '');

if ($clientId === '' || $redirectUri === '') {
    ?><!DOCTYPE html>
    <html lang="pt-PT">
    <head><meta charset="UTF-8"><title>Login com Google</title><link rel="stylesheet" href="/assets/estilo.css"></head>
    <body><main><div class="auth-wrap">
        <h1>Login com Google indisponível</h1>
        <p>Esta instalação ainda não tem as credenciais Google configuradas.
        Consulta <code>docs/MANUAL.md</code> para as criar e adicionar a <code>scripts/config.php</code>.</p>
        <p class="rodape-auth"><a href="/login.php">Voltar ao login</a></p>
    </div></main></body></html>
    <?php
    exit;
}

$_SESSION['google_oauth_state'] = bin2hex(random_bytes(16));
$_SESSION['google_oauth_next'] = $next;

$parametros = [
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $_SESSION['google_oauth_state'],
    'access_type' => 'online',
    'prompt' => 'select_account',
];

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($parametros));
