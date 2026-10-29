<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/*
 * Gestão de utilizadores (só administradores): quem é administrador e
 * desativar contas. Os prémios monetários são visíveis a todos os utilizadores
 * com sessão iniciada, e o ranking dos prémios só a administradores. O primeiro
 * administrador define-se com scripts/definir_admin.php. Ninguém se pode
 * retirar de administrador nem desativar a própria conta (para não ficar
 * sem acesso a esta página).
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
    $valor = ($_POST['valor'] ?? '') === '1';
    $acao = (string) ($_POST['acao'] ?? '');
    $proprio = $alvo !== null && (int) $alvo['id'] === (int) $auth->utilizadorAtual()['id'];

    if ($alvo !== null && $proprio && in_array($acao, ['admin', 'ativo'], true)) {
        $_SESSION['gestao_aviso'] = $acao === 'admin'
            ? 'Não podes deixar de ser administrador por ti próprio.'
            : 'Não podes desativar a tua própria conta.';
    } elseif ($alvo !== null && $acao === 'admin') {
        $db->definirAdminPorId((int) $alvo['id'], $valor);
        $_SESSION['gestao_aviso'] = $alvo['nome'] . ($valor ? ' passou a administrador.' : ' deixou de ser administrador.');
    } elseif ($alvo !== null && $acao === 'ativo') {
        $db->definirAtivo((int) $alvo['id'], $valor);
        $_SESSION['gestao_aviso'] = 'Conta de ' . $alvo['nome'] . ($valor ? ' reativada.' : ' desativada: já não consegue entrar.');
    }
    header('Location: ' . $aqui);
    exit;
}

$aviso = $_SESSION['gestao_aviso'] ?? null;
unset($_SESSION['gestao_aviso']);

$utilizadores = $db->listarUtilizadores($procura);
$desativados = count(array_filter($utilizadores, fn (array $u) => !$u['ativo']));
$euId = (int) $auth->utilizadorAtual()['id'];

/** Botão de uma ação sobre um utilizador (um formulário por botão). */
$botao = function (array $u, string $acao, bool $valor, string $texto, string $classe) use ($auth): string {
    return '<form method="post" class="acesso-form">'
        . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($auth->tokenCsrf()) . '">'
        . '<input type="hidden" name="acao" value="' . $acao . '">'
        . '<input type="hidden" name="utilizador_id" value="' . (int) $u['id'] . '">'
        . '<input type="hidden" name="valor" value="' . ($valor ? '1' : '0') . '">'
        . '<button type="submit" class="acesso-botao ' . $classe . '">' . htmlspecialchars($texto) . '</button>'
        . '</form>';
};

$tituloDocumento = 'Gestão de utilizadores — Corridas';

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
        Os <strong>prémios monetários</strong> são visíveis a todos os utilizadores com sessão iniciada.
        Os <strong>administradores</strong> gerem esta página, vêem o ranking dos prémios (link «Ranking €»
        no topo) e as fontes. Uma conta <strong>desativada</strong> deixa de conseguir entrar (com email
        ou Google) e o link do seu calendário deixa de funcionar.
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
        <?= $desativados > 0 ? ($desativados === 1 ? '1 desativado' : $desativados . ' desativados') : 'nenhum desativado' ?>
        <?php if ($procura !== null): ?><a href="/gestao-utilizadores.php">Ver todos</a><?php endif; ?>
    </p>

    <?php if ($utilizadores === []): ?>
        <p class="vazio">Nenhum utilizador encontrado.</p>
    <?php else: ?>
        <ul class="utilizadores">
            <?php foreach ($utilizadores as $u):
                $entrada = array_filter([$u['tem_password'] ? 'email e password' : null, $u['tem_google'] ? 'Google' : null]);
                ?>
                <li class="utilizador<?= $u['ativo'] ? '' : ' desativado' ?>">
                    <div class="utilizador-dados">
                        <p class="utilizador-nome">
                            <?= htmlspecialchars($u['nome']) ?>
                            <?php if ($u['e_admin']): ?><span class="etiqueta-admin">Administrador</span><?php endif; ?>
                            <?php if (!$u['ativo']): ?><span class="etiqueta-desativado">Desativado</span><?php endif; ?>
                            <?php if ((int) $u['id'] === $euId): ?><span class="etiqueta-eu">(tu)</span><?php endif; ?>
                        </p>
                        <p class="utilizador-email"><?= htmlspecialchars($u['email']) ?></p>
                        <p class="utilizador-info">
                            Conta criada a <?= date('d/m/Y', (int) strtotime((string) $u['criado_em'])) ?>
                            <?= $entrada !== [] ? '· entra com ' . htmlspecialchars(implode(' e ', $entrada)) : '' ?>
                            · <?= (int) $u['provas_guardadas'] === 1 ? '1 prova guardada' : (int) $u['provas_guardadas'] . ' provas guardadas' ?>
                        </p>
                    </div>

                    <div class="utilizador-acoes">
                        <?php if ((int) $u['id'] !== $euId): ?>
                            <?= $u['e_admin']
                                ? $botao($u, 'admin', false, 'Retirar administrador', 'retirar')
                                : $botao($u, 'admin', true, 'Tornar administrador', 'secundario') ?>
                            <?= $u['ativo']
                                ? $botao($u, 'ativo', false, 'Desativar', 'retirar')
                                : $botao($u, 'ativo', true, 'Reativar', 'secundario') ?>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>

</body>
</html>
