<?php

declare(strict_types=1);

/**
 * Envia por email as notificações pendentes (provas guardadas que mudaram de data,
 * hora ou local). Lê a tabela notificacoes_pendentes (migração 018).
 *
 * Por omissão só MOSTRA o que enviaria. Para enviar a sério:
 *   php scripts/enviar_alertas.php --enviar
 *
 * Precisa de 'email_remetente' e 'url_publica' em scripts/config.php (ver config.example.php).
 * O envio usa a função mail() do PHP; se o alojamento não a suportar, trocar aqui por SMTP.
 */

$config = require __DIR__ . '/config.php';

/**
 * "Calendário de Corridas <noreply@x.pt>" -> nome codificado (acentos) + endereço em texto simples.
 */
function codificarRemetente(string $remetente): string
{
    if (preg_match('/^(.*?)\s*<([^>]+)>$/', $remetente, $m) !== 1) {
        return $remetente;
    }

    return '=?UTF-8?B?' . base64_encode($m[1]) . '?= <' . $m[2] . '>';
}
$enviar = in_array('--enviar', $argv, true);

$remetente = (string) ($config['email_remetente'] ?? '');
$urlPublica = rtrim((string) ($config['url_publica'] ?? ''), '/');
if ($enviar && ($remetente === '' || $urlPublica === '')) {
    fwrite(STDERR, "Falta 'email_remetente' ou 'url_publica' em scripts/config.php.\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset={$config['db_charset']}",
    $config['db_user'],
    $config['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pendentes = $pdo->query(
    'SELECT n.id, n.corrida_id, n.mensagem, u.nome, u.email, c.nome AS prova
       FROM notificacoes_pendentes n
       JOIN utilizadores u ON u.id = n.utilizador_id AND u.ativo = 1
       JOIN corridas c ON c.id = n.corrida_id
      WHERE n.enviado_em IS NULL
      ORDER BY n.id
      LIMIT 500'
)->fetchAll(PDO::FETCH_ASSOC);

echo count($pendentes) . ' notificação(ões) pendente(s).' . PHP_EOL;

$marcar = $pdo->prepare('UPDATE notificacoes_pendentes SET enviado_em = NOW() WHERE id = :id');

foreach ($pendentes as $n) {
    $link = $urlPublica . '/prova.php?id=' . (int) $n['corrida_id'];
    $assunto = 'Alteração numa prova que guardaste';
    $corpo = "Olá {$n['nome']},\n\n"
        . "Uma prova que guardaste no teu calendário foi alterada:\n"
        . "{$n['mensagem']}\n\n"
        . "Confirma os detalhes em {$link}\n\n"
        . "Calendário de Corridas\n";

    if (!$enviar) {
        echo "[simulação] Para {$n['email']}: {$assunto} — {$n['prova']}" . PHP_EOL;
        continue;
    }

    $cabecalhos = 'From: ' . codificarRemetente($remetente) . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n";

    if (mail($n['email'], '=?UTF-8?B?' . base64_encode($assunto) . '?=', $corpo, $cabecalhos)) {
        $marcar->execute(['id' => (int) $n['id']]);
        echo "Enviado para {$n['email']} ({$n['prova']})" . PHP_EOL;
    } else {
        echo "FALHOU para {$n['email']} ({$n['prova']}) — fica pendente" . PHP_EOL;
    }
}
