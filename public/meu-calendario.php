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
    <section class="exportar">
        <h2>Levar para o calendário do telemóvel</h2>
        <p>
            Subscreve o teu calendário de corridas no Google Calendar, iPhone ou Outlook.
            As provas que adicionares ou retirares aqui passam a aparecer lá sozinhas.
        </p>
        <div class="exportar-botoes">
            <a class="botao-exportar" href="<?= htmlspecialchars($urlGoogle) ?>" target="_blank" rel="noopener">Google Calendar</a>
            <a class="botao-exportar" href="<?= htmlspecialchars($urlWebcal) ?>">iPhone, Mac ou Outlook</a>
            <a class="botao-exportar secundario" href="/meu-calendario-ics.php">Descarregar ficheiro .ics</a>
        </div>
        <p class="exportar-nota">
            O Google Calendar só volta a ler o calendário de tempos a tempos — uma alteração pode demorar
            até um dia a aparecer lá. O ficheiro .ics é uma cópia do momento: não se atualiza sozinho.
        </p>

        <?php if ($linkRenovado): ?>
            <p class="aviso-ok">Link renovado. O link antigo deixou de funcionar — volta a subscrever com o novo.</p>
        <?php endif; ?>

        <details class="exportar-link" <?= $linkRenovado ? 'open' : '' ?>>
            <summary>Ver o link de subscrição (para outras apps)</summary>
            <input type="text" readonly value="<?= htmlspecialchars($urlSubscricao) ?>" onclick="this.select()">
            <p class="exportar-nota">
                Este link é pessoal: quem o tiver consegue ver as provas do teu calendário.
                Se o partilhaste por engano, gera um novo.
            </p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($auth->tokenCsrf()) ?>">
                <input type="hidden" name="acao" value="renovar-link">
                <button type="submit" class="link-botao">Gerar novo link</button>
            </form>
        </details>
    </section>

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
