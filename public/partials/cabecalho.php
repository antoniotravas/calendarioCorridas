<?php
/**
 * Topo de todas as páginas: nome do site e conta. Espera: $auth (Auth).
 */
$utilizador = $auth->utilizadorAtual();
?>
<header class="topo">
    <div class="topo-linha">
        <a class="marca" href="/">Calendário de corridas</a>
        <nav class="conta" aria-label="Conta">
            <?php if ($utilizador !== null): ?>
                <?php /* No telemóvel só aparecem os ícones (classe conta-rotulo escondida em estilo.css). */ ?>
                <?php if ($auth->eAdmin()): ?>
                    <a href="/gestao-utilizadores.php" title="Gestão" aria-label="Gestão">
                        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/></svg>
                        <span class="conta-rotulo">Gestão</span>
                    </a>
                    <a href="/organizadores.php" title="Organizadores" aria-label="Organizadores">
                        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2M10 21v-3h4v3"/></svg>
                        <span class="conta-rotulo">Organizadores</span>
                    </a>
                <?php endif; ?>
                <?php if ($auth->podeVerRanking()): ?>
                    <a href="/ranking-premios.php" title="Ranking €" aria-label="Ranking €">
                        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0zM7 6H4v1a3 3 0 0 0 3 3M17 6h3v1a3 3 0 0 1-3 3"/></svg>
                        <span class="conta-rotulo">Ranking €</span>
                    </a>
                <?php endif; ?>
                <a href="/meu-calendario.php" title="O meu calendário" aria-label="O meu calendário">
                    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
                    <span class="conta-rotulo">O meu calendário</span>
                </a>
                <form method="post" action="/logout.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($auth->tokenCsrf()) ?>">
                    <button type="submit" class="link" title="Sair (<?= htmlspecialchars($utilizador['nome']) ?>)" aria-label="Sair">
                        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                        <span class="conta-rotulo">Sair</span>
                    </button>
                </form>
            <?php else: ?>
                <a href="/login.php">Entrar</a>
                <a class="conta-destaque" href="/registo.php">Criar conta</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
