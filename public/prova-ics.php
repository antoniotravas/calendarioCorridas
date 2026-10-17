<?php

declare(strict_types=1);

/**
 * Uma prova em formato iCalendar (.ics), para a adicionar ao calendário do
 * telemóvel a partir da página da prova (/prova.php). Não precisa de sessão.
 */

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/lib/CalendarioIcs.php';

$c = $db->obterProva((int) ($_GET['id'] ?? 0));

if ($c === null || empty($c['data_prova'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    die('Prova não encontrada ou sem data.');
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="prova-' . (int) $c['id'] . '.ics"');

echo CalendarioIcs::gerar([$c], (string) $c['nome']);
