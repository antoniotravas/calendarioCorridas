<?php

declare(strict_types=1);

/**
 * "O meu calendário" em formato iCalendar (.ics).
 *
 *  - /meu-calendario-ics.php?token=...  → link de subscrição (Google Calendar,
 *    iPhone, Outlook). As apps pedem-no sem sessão iniciada, por isso o
 *    utilizador é identificado pelo código secreto em utilizadores.ics_token.
 *  - /meu-calendario-ics.php            → descarga do ficheiro, com sessão iniciada.
 */

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/lib/CalendarioIcs.php';

$token = isset($_GET['token']) ? (string) $_GET['token'] : null;

if ($token !== null) {
    $utilizador = preg_match('/^[0-9a-f]{32}$/', $token) ? $db->encontrarUtilizadorPorTokenIcs($token) : null;
    if ($utilizador === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        die('Link de calendário inválido ou renovado. Obtém o link atual em "O meu calendário".');
    }
    $descarregar = false;
} else {
    $auth->exigirLogin('/meu-calendario.php');
    $utilizador = $auth->utilizadorAtual();
    $descarregar = true;
}

$corridas = $db->listarCorridasFavoritas((int) $utilizador['id']);
$ics = CalendarioIcs::gerar($corridas, 'Corridas — ' . $utilizador['nome']);

header('Content-Type: text/calendar; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
if ($descarregar) {
    header('Content-Disposition: attachment; filename="meu-calendario-corridas.ics"');
}

echo $ics;
