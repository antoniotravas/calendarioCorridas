<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';

$clientId = (string) ($config['google_client_id'] ?? '');
$clientSecret = (string) ($config['google_client_secret'] ?? '');
$redirectUri = (string) ($config['google_redirect_uri'] ?? '');
$next = Auth::caminhoSeguro($_SESSION['google_oauth_next'] ?? null);

if ($clientId === '' || $clientSecret === '') {
    http_response_code(500);
    die('Login com Google não está configurado.');
}

$state = $_GET['state'] ?? null;
if ($state === null || !isset($_SESSION['google_oauth_state']) || !hash_equals($_SESSION['google_oauth_state'], $state)) {
    http_response_code(400);
    die('Pedido de login inválido, tenta novamente a partir da página de login.');
}
unset($_SESSION['google_oauth_state']);

$code = $_GET['code'] ?? null;
if ($code === null) {
    header('Location: /login.php');
    exit;
}

$http = new HttpClient();

try {
    $respostaToken = $http->post('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ]);
    $tokens = json_decode($respostaToken, true, 512, JSON_THROW_ON_ERROR);

    // Pede o perfil diretamente à Google com o access_token — evita ter de
    // validar a assinatura do id_token manualmente.
    $respostaPerfil = $http->get(
        'https://www.googleapis.com/oauth2/v3/userinfo',
        ['Authorization: Bearer ' . $tokens['access_token']]
    );
    $perfil = json_decode($respostaPerfil, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(502);
    die('Não foi possível validar o login com a Google.');
}

if (empty($perfil['sub']) || empty($perfil['email'])) {
    http_response_code(502);
    die('Resposta inesperada da Google.');
}

$utilizador = $db->obterOuCriarUtilizadorGoogle(
    (string) $perfil['sub'],
    (string) $perfil['email'],
    (string) ($perfil['name'] ?? $perfil['email'])
);

$auth->iniciarSessao((int) $utilizador['id']);
header('Location: ' . $next);
