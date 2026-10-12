<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/*
 * Gestão de utilizadores (só administradores): decidir quem pode ver os
 * prémios monetários (ícone "€" e filtro "Prémios" do calendário). Os
 * administradores veem sempre; tornam-se administradores com
 * scripts/definir_admin.php.
 */
$auth->exigirAdmin('/gestao-utilizadores.php');

$procura = isset($_GET['procura']) && trim((string) $_GET['procura']) !== '' ? trim((string) $_GET['procura']) : null;
$aqui = '/gestao-utilizadores.php' . ($procura !== null ? '?' . http_build_query(['procura' => $procura]) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validarCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        die('Pedido inválido (csrf).');
    }
    $alvo = $db->encontrarUtilizadorPorId((int) ($_POST['utilizador_id'] ?? 0));
    if ($alvo !== null && ($_POST['acao'] ?? '') === 'premios') {
        $ver = ($_POST['ver'] ?? '') === '1';
        $db->definirVerPremios((int) $alvo['id'], $ver);
        $_SESSION['gestao_aviso'] = ($ver ? 'Acesso aos prémios dado a ' : 'Acesso aos prémios retirado a ') . $alvo['nome'] . '.';
    }
    header('Location: ' . $aqui);
    exit;
}

$aviso = $_SESSION['gestao_aviso'] ?? null;
unset($_SESSION['gestao_aviso']);

$utilizadores = $db->listarUtilizadores($procura);
$comAcesso = count(array_filter($utilizadores, fn (array $u) => $u['e_admin'] || $u['ver_premios']));

$tituloDocumento = 'Gestão de utilizadores — Calendário de corridas';

?><!DOCTYPE html>
<html lang="pt-PT">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body>

<?php require __DIR__ . '/partials/cabecalho.php'; ?>

<main class="gestao">
    <div class="cabeca-pagina">
        <h1 class="pagina-titulo">Gestão de utilizadores</h1>
    </div>

    <p class="gestao-explicacao">
        Escolhe quem pode ver os <strong>prémios monetários</strong> no calendário (o ícone
        <span class="premios premios-sim" aria-hidden="true">€</span> e o filtro «Prémios €»).
        Quem não tem acesso não vê nem o ícone nem o filtro. Os administradores veem sempre.
    </p>

    <form class="gestao-procura" method="get" action="/gestao-utilizadores.php" role="search">
        <label class="procura">
            <span class="sr-only">Procurar por nome ou email</span>
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
            <input type="search" name="procura" placeholder="Nome ou email" value="<?= htmlspecialchars($procura ?? '') ?>">
        </label>
        <button type="submit" class="botao-principal">Procurar</button>
    </form>

    <?php if ($aviso !== null): ?>
        <p class="gestao-aviso" role="status"><?= htmlspecialchars($aviso) ?></p>
    <?php endif; ?>

    <p class="resumo">
        <?= count($utilizadores) === 1 ? '1 utilizador' : count($utilizadores) . ' utilizadores' ?><?= $procura !== null ? ' com «' . htmlspecialchars($procura) . '»' : '' ?>,
        <?= $comAcesso ?> com acesso aos prémios
        <?php if ($procura !== null): ?><a href="/gestao-utilizadores.php">Ver todos</a><?php endif; ?>
    </p>

    <?php if ($utilizadores === []): ?>
        <p class="vazio">Nenhum utilizador encontrado.</p>
    <?php else: ?>
        <ul class="utilizadores">
            <?php foreach ($utilizadores as $u):
                $temAcesso = $u['e_admin'] || $u['ver_premios'];
                $entrada = array_filter([$u['tem_password'] ? 'email e password' : null, $u['tem_google'] ? 'Google' : null]);
                ?>
                <li class="utilizador<?= $temAcesso ? ' com-acesso' : '' ?>">
                    <div class="utilizador-dados">
                        <p class="utilizador-nome">
                            <?= htmlspecialchars($u['nome']) ?>
                            <?php if ($u['e_admin']): ?><span class="etiqueta-admin">Administrador</span><?php endif; ?>
                        </p>
                        <p class="utilizador-email"><?= htmlspecialchars($u['email']) ?></p>
                        <p class="utilizador-info">
                            Conta criada a <?= date('d/m/Y', (int) strtotime((string) $u['criado_em'])) ?>
                            <?= $entrada !== [] ? '· entra com ' . htmlspecialchars(implode(' e ', $entrada)) : '' ?>
                            · <?= (int) $u['provas_guardadas'] === 1 ? '1 prova guardada' : (int) $u['provas_guardadas'] . ' provas guardadas' ?>
                        </p>
                    </div>

                    <?php if ($u['e_admin']): ?>
                        <span class="acesso-estado">Vê sempre os prémios</span>
                    <?php else: ?>
                        <form method="post" class="acesso-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($auth->tokenCsrf()) ?>">
                            <input type="hidden" name="acao" value="premios">
                            <input type="hidden" name="utilizador_id" value="<?= (int) $u['id'] ?>">
                            <input type="hidden" name="ver" value="<?= $u['ver_premios'] ? '0' : '1' ?>">
                            <?php if ($u['ver_premios']): ?>
                                <span class="acesso-estado">Com acesso aos prémios</span>
                                <button type="submit" class="acesso-botao retirar">Retirar acesso</button>
                            <?php else: ?>
                                <button type="submit" class="acesso-botao dar">Dar acesso aos prémios</button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>

</body>
</html>
