<?php
/**
 * Espera: $auth (Auth), $tituloPagina, $subtituloPagina.
 */
$utilizador = $auth->utilizadorAtual();
?>
<header class="topo">
    <div class="topo-linha">
        <div class="titulo">
            <h1><a href="/" style="color:inherit;text-decoration:none;"><?= htmlspecialchars($tituloPagina ?? 'Calendário de Corridas de Estrada') ?></a></h1>
            <p><?= htmlspecialchars($subtituloPagina ?? 'Provas de atletismo em estrada em Portugal.') ?></p>
        </div>
        <nav class="conta">
            <?php if ($utilizador !== null): ?>
                <a href="/meu-calendario.php">O meu calendário</a>
                <span class="utilizador"><?= htmlspecialchars($utilizador['nome']) ?></span>
                <form method="post" action="/logout.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($auth->tokenCsrf()) ?>">
                    <button type="submit" class="link">Sair</button>
                </form>
            <?php else: ?>
                <a href="/login.php">Entrar</a>
                <a href="/registo.php">Registar</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
