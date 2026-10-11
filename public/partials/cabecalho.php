<?php
/**
 * Topo de todas as páginas: nome do site e conta. Espera: $auth (Auth).
 */
$utilizador = $auth->utilizadorAtual();
?>
<header class="topo">
    <div class="topo-linha">
        <a class="marca" href="/">Corridas de estrada</a>
        <nav class="conta" aria-label="Conta">
            <?php if ($utilizador !== null): ?>
                <a href="/meu-calendario.php">O meu calendário</a>
                <form method="post" action="/logout.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($auth->tokenCsrf()) ?>">
                    <button type="submit" class="link" title="Sair (<?= htmlspecialchars($utilizador['nome']) ?>)">Sair</button>
                </form>
            <?php else: ?>
                <a href="/login.php">Entrar</a>
                <a class="conta-destaque" href="/registo.php">Criar conta</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
