<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$auth->exigirLogin('/meu-calendario.php');

$utilizador = $auth->utilizadorAtual();
$utilizadorId = (int) $utilizador['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'renovar-link') {
    if (!$auth->validarCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        die('Pedido inválido (csrf).');
    }
    $db->renovarTokenIcs($utilizadorId);
    header('Location: /meu-calendario.php?link-renovado=1');
    exit;
}
$linkRenovado = isset($_GET['link-renovado']);

$corridas = $db->listarCorridasFavoritas($utilizadorId);
$grupos = DateHelper::agruparPorMes($corridas);

// Links de exportação/subscrição do calendário (.ics).
$tokenIcs = $db->obterOuCriarTokenIcs($utilizadorId);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$enderecoIcs = ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/meu-calendario-ics.php?token=' . $tokenIcs;
$urlSubscricao = ($https ? 'https://' : 'http://') . $enderecoIcs;
$urlWebcal = 'webcal://' . $enderecoIcs;
$urlGoogle = 'https://calendar.google.com/calendar/render?cid=' . rawurlencode($urlWebcal);

$tituloPagina = 'O Meu Calendário';
$subtituloPagina = 'Provas que guardaste, ' . $utilizador['nome'] . '.';

?><!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($tituloPagina) ?> — Calendário de Corridas</title>
<link rel="stylesheet" href="/assets/estilo.css">
</head>
<body>

<?php require __DIR__ . '/partials/cabecalho.php'; ?>

<main>
    <?php /* Exportar para o calendário do telemóvel: só ícones; a explicação
             fica no title/aria-label de cada botão. */ ?>
    <nav class="exportar" aria-label="Exportar o meu calendário">
        <a class="icone-botao" href="<?= htmlspecialchars($urlGoogle) ?>" target="_blank" rel="noopener"
           title="Adicionar ao Google Calendar (atualiza-se sozinho)" aria-label="Adicionar ao Google Calendar">
            <svg viewBox="0 0 48 48" aria-hidden="true">
                <path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3c-1.6 4.7-6.1 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 8 3l5.7-5.7C34 6.1 29.3 4 24 4 13 4 4 13 4 24s9 20 20 20 20-9 20-20c0-1.3-.1-2.6-.4-3.9z"/>
                <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 8 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
                <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2c-2 1.5-4.5 2.4-7.2 2.4-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/>
                <path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.6-.4-3.9z"/>
            </svg>
        </a>
        <a class="icone-botao" href="<?= htmlspecialchars($urlWebcal) ?>"
           title="Subscrever no iPhone, Mac ou Outlook (atualiza-se sozinho)" aria-label="Subscrever no iPhone, Mac ou Outlook">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path fill="currentColor" d="M12.15 6.9c-.95 0-2.42-1.08-3.96-1.04-2.04.03-3.91 1.18-4.96 3.01-2.12 3.68-.55 9.1 1.52 12.09 1.01 1.45 2.2 3.09 3.79 3.04 1.52-.07 2.09-.99 3.94-.99 1.83 0 2.35.99 3.96.95 1.64-.03 2.68-1.48 3.68-2.95 1.16-1.69 1.64-3.33 1.66-3.42-.04-.01-3.18-1.22-3.22-4.86-.03-3.04 2.48-4.49 2.6-4.56-1.43-2.09-3.62-2.32-4.39-2.38-2-.16-3.68 1.09-4.61 1.09zm3.38-3.07c.84-1.01 1.4-2.43 1.25-3.83-1.21.05-2.66.81-3.53 1.82-.78.9-1.45 2.34-1.27 3.71 1.34.1 2.71-.69 3.56-1.7z"/>
            </svg>
        </a>
        <a class="icone-botao" href="/meu-calendario-ics.php"
           title="Descarregar ficheiro .ics (cópia do momento, não se atualiza)" aria-label="Descarregar ficheiro .ics">
            <svg viewBox="0 0 24 24" aria-hidden="true" class="traco">
                <path d="M12 4v11M7 10.5l5 5 5-5M5 20h14"/>
            </svg>
        </a>
        <details class="exportar-link" <?= $linkRenovado ? 'open' : '' ?>>
            <summary class="icone-botao"
                     title="Link pessoal de subscrição, para outras apps" aria-label="Mostrar o link pessoal de subscrição">
                <svg viewBox="0 0 24 24" aria-hidden="true" class="traco">
                    <path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/>
                </svg>
            </summary>
            <div class="exportar-painel">
                <input type="text" readonly id="link-ics" value="<?= htmlspecialchars($urlSubscricao) ?>"
                       aria-label="Link pessoal de subscrição" onclick="this.select()">
                <button type="button" class="icone-botao" id="copiar-link-ics" title="Copiar link" aria-label="Copiar link">
                    <svg viewBox="0 0 24 24" aria-hidden="true" class="traco icone-copiar">
                        <rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>
                    </svg>
                    <svg viewBox="0 0 24 24" aria-hidden="true" class="traco icone-copiado">
                        <path d="M5 12.5l4.5 4.5L19 7.5"/>
                    </svg>
                </button>
                <form method="post" onsubmit="return confirm('Criar um link novo? O link atual deixa de funcionar e terás de voltar a subscrever.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($auth->tokenCsrf()) ?>">
                    <input type="hidden" name="acao" value="renovar-link">
                    <button type="submit" class="icone-botao icone-perigo"
                            title="Criar um link novo (o atual deixa de funcionar)" aria-label="Criar um link novo">
                        <svg viewBox="0 0 24 24" aria-hidden="true" class="traco">
                            <path d="M20 11a8 8 0 0 0-14.3-4.9L4 8M4 4v4h4M4 13a8 8 0 0 0 14.3 4.9L20 16M20 20v-4h-4"/>
                        </svg>
                    </button>
                </form>
                <?php if ($linkRenovado): ?>
                    <span class="link-renovado" role="status" title="Link novo criado — volta a subscrever">✓</span>
                <?php endif; ?>
            </div>
        </details>
    </nav>
    <script>
        document.getElementById('copiar-link-ics').addEventListener('click', function () {
            var botao = this;
            navigator.clipboard.writeText(document.getElementById('link-ics').value).then(function () {
                botao.classList.add('copiado');
                setTimeout(function () { botao.classList.remove('copiado'); }, 1600);
            });
        });
    </script>

    <?php if ($corridas === []): ?>
        <p class="vazio">
            Ainda não guardaste nenhuma prova. Vai ao <a href="/">calendário</a> e clica em
            "+ Adicionar" nas provas que queres seguir.
        </p>
    <?php else: ?>
        <?php require __DIR__ . '/partials/lista-corridas.php'; ?>
    <?php endif; ?>
</main>

<footer>
    <?= count($corridas) ?> provas no teu calendário · CalendarioCorridas
</footer>

</body>
</html>
