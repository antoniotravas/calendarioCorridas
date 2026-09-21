<?php
// Copia este ficheiro para "config.php" e ajusta as credenciais locais (XAMPP/WAMP).
// config.php não deve ser versionado se contiver credenciais reais de produção.

return [
    'db_host'    => 'localhost',
    'db_name'    => 'calendario_corridas',
    'db_user'    => 'root',
    'db_pass'    => '',
    'db_charset' => 'utf8mb4',

    // Login com Google (opcional). Deixa vazio para desativar o botão "Continuar
    // com o Google" — o login por email/password continua a funcionar sem isto.
    // Ver docs/MANUAL.md para instruções de como criar estas credenciais.
    'google_client_id'     => '',
    'google_client_secret' => '',
    'google_redirect_uri'  => 'http://localhost:8000/auth/google-callback.php',
];
