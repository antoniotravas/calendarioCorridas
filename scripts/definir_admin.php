<?php

declare(strict_types=1);

/**
 * Torna um utilizador administrador (ou deixa de ser, com --retirar).
 * O administrador gere os utilizadores em /gestao-utilizadores.php e vê
 * sempre os prémios monetários. O utilizador já tem de ter conta no site.
 *
 * Uso:
 *   php scripts/definir_admin.php email@exemplo.pt
 *   php scripts/definir_admin.php email@exemplo.pt --retirar
 *
 * O email não fica escrito no código (o repositório é público): fica só na
 * base de dados.
 */

require __DIR__ . '/lib/Corrida.php';
require __DIR__ . '/lib/Database.php';

$email = $argv[1] ?? '';
$retirar = in_array('--retirar', $argv, true);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php scripts/definir_admin.php email@exemplo.pt [--retirar]\n");
    exit(1);
}

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Falta o ficheiro scripts/config.php.\n");
    exit(1);
}

try {
    $db = new Database(require $configPath);
    $encontrado = $db->definirAdmin($email, !$retirar);
} catch (PDOException $e) {
    fwrite(STDERR, "Erro na base de dados: {$e->getMessage()}\n");
    if (str_contains($e->getMessage(), 'e_admin')) {
        fwrite(STDERR, "Falta aplicar a migração sql/migracao_008_permissoes.sql.\n");
    }
    exit(1);
}

if (!$encontrado) {
    fwrite(STDERR, "Não há nenhum utilizador com o email {$email}. Cria primeiro a conta no site (ou entra com o Google).\n");
    exit(1);
}

echo $retirar
    ? "{$email} deixou de ser administrador.\n"
    : "{$email} é agora administrador. Entra no site e usa o link «Gestão» no topo.\n";
