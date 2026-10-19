<?php

declare(strict_types=1);

/**
 * Página individual de uma prova (/prova.php?id=N), aberta ao clicar num
 * cartão do calendário: no topo, tipo e distâncias, título, data e hora,
 * local (com distrito e região) e organização (com o link para o
 * organizador, em destaque, e o link direto para as inscrições quando se
 * conhece); depois o programa com os escalões e os prémios monetários numa
 * tabela com o total (só para quem tem acesso).
 */

require __DIR__ . '/_bootstrap.php';

$corridaId = (int) ($_GET['id'] ?? 0);
$utilizadorAtual = $auth->utilizadorAtual();
$c = $corridaId > 0 ? $db->obterProva($corridaId, $utilizadorAtual['id'] ?? null) : null;

if ($c === null) {
    http_response_code(404);
    $tituloDocumento = 'Prova não encontrada — Calendário de corridas';
    ?><!DOCTYPE html>
<html lang="pt-PT">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php require __DIR__ . '/partials/cabecalho.php'; ?>
<main>
    <p class="vazio">
        Esta prova não existe ou foi removida do calendário.
        <a href="/">Voltar ao calendário</a>
    </p>
</main>
</body>
</html>
    <?php
    exit;
}

$nomesCategoria = ['estrada' => 'Estrada', 'trail' => 'Trail', 'corta-mato' => 'Corta-mato', 'pista' => 'Pista'];
$categoria = $c['categoria'] ?? 'estrada';
$distancias = array_values(array_filter(array_map('trim', explode(',', (string) $c['distancias'])), 'strlen'));

$data = $c['data_prova'] ?? null;
$ts = $data !== null ? strtotime($data) : null;
$jaPassou = $data !== null && $data < date('Y-m-d');
$hora = !empty($c['hora_inicio']) ? substr((string) $c['hora_inicio'], 0, 5) : null;
$horaTexto = $hora !== null ? str_replace(':', 'h', $hora) : null; // "09h30"

['distrito' => $distrito, 'regiao' => $regiao] = DistritoHelper::localizar($c['local'] ?? null, $c['regiao'] ?? null);
// "Distrito de Braga · Norte", "Região Autónoma dos Açores" — ao lado do local.
$zona = match (true) {
    $distrito === null => null,
    in_array($distrito, ['Açores', 'Madeira'], true) && str_contains((string) $c['local'], $distrito) => null,
    $distrito === 'Açores' => 'Região Autónoma dos Açores',
    $distrito === 'Madeira' => 'Região Autónoma da Madeira',
    default => 'Distrito de ' . $distrito . ($regiao !== null ? ' · ' . $regiao : ''),
};
// Subtipo lido na fonte ("Ultra Trail", "Pista coberta"), quando diz mais do que a categoria.
$subtipo = !empty($c['tipo']) && mb_strtolower((string) $c['tipo']) !== mb_strtolower($nomesCategoria[$categoria] ?? '') ? (string) $c['tipo'] : null;

$organizador = Organizador::de($c);
// Link direto para as inscrições, só quando se conhece (senão não aparece).
$urlInscricao = Organizador::urlInscricao($c);
// O link do organizador já é a página das inscrições: um só botão.
$organizadorEInscricao = $urlInscricao !== null && $urlInscricao === ($organizador['url'] ?? null);

$programa = json_decode((string) ($c['programa'] ?? ''), true) ?: [];
$programaTemEscaloes = array_filter(array_column($programa, 'escaloes')) !== [];

$podeVerPremios = $auth->podeVerPremios();
$premios = isset($c['premios_monetarios']) ? (int) $c['premios_monetarios'] === 1 : null;
$tabelasPremios = $premios ? PremiosHelper::tabelasDaProva($c['premios_tabela'] ?? null, $c['premios_excerto'] ?? null) : [];
$totalPremios = PremiosHelper::total($tabelasPremios);
// Só o total anunciado no regulamento ("prize money global de 5.000 €"), sem tabela.
$soTotalAnunciado = $tabelasPremios !== [] && array_filter($tabelasPremios, fn (array $t) => $t['linhas'] !== []) === [];
$tabelasPremios = array_values(array_filter($tabelasPremios, fn (array $t) => $t['linhas'] !== []));
// Uma só tabela: uma linha por lugar, uma coluna por classificação.
$lugaresPremios = [];
foreach ($tabelasPremios as $i => $tabela) {
    foreach ($tabela['linhas'] as [$lugar, $valor]) {
        $lugaresPremios[(int) $lugar][$i] = $valor;
    }
}
ksort($lugaresPremios);

// Adicionar ao calendário: com hora, um evento de 2 horas; sem hora, o dia inteiro.
$urlGoogleCalendar = null;
if ($ts !== null) {
    $datas = $hora !== null
        ? date('Ymd\THis', strtotime("{$data} {$hora}")) . '/' . date('Ymd\THis', strtotime("{$data} {$hora} +2 hours"))
        : date('Ymd', $ts) . '/' . date('Ymd', strtotime($data . ' +1 day'));
    $urlGoogleCalendar = 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action' => 'TEMPLATE',
        'text' => $c['nome'],
        'dates' => $datas,
        'ctz' => 'Europe/Lisbon',
        'location' => (string) $c['local'],
        'details' => $c['url_evento'] ? 'Site da prova: ' . $c['url_evento'] : '',
    ]);
}
$urlMapa = $c['local']
    ? 'https://www.google.com/maps/search/?' . http_build_query(['api' => 1, 'query' => $c['local'] . ', Portugal'])
    : null;

$tituloDocumento = $c['nome'] . ($ts !== null ? ' — ' . date('j/n/Y', $ts) : '') . ' — Calendário de corridas';
$voltar = '/prova.php?id=' . (int) $c['id'];
$iconeGuardar = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M7 3.5h10a1 1 0 0 1 1 1v16l-6-4-6 4v-16a1 1 0 0 1 1-1z"/></svg>';
$iconeExterno = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M14 5h5v5M19 5l-8 8M17 14v5H5V7h5"/></svg>';

?><!DOCTYPE html>
<html lang="pt-PT">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body>

<?php require __DIR__ . '/partials/cabecalho.php'; ?>

<main class="pagina-prova">
    <?php /* Com JavaScript, volta para trás (mantém filtros e as provas já carregadas). */ ?>
    <a class="voltar-calendario" href="/" data-voltar>
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
        Calendário
    </a>

    <article class="prova-detalhe">
        <div class="prova-cartaz<?= empty($c['imagem_url']) ? ' sem-imagem' : '' ?>">
            <?php if (!empty($c['imagem_url'])): ?>
                <img src="<?= htmlspecialchars($c['imagem_url']) ?>" alt="Cartaz da prova"
                     referrerpolicy="no-referrer" onerror="this.parentNode.classList.add('sem-imagem'); this.remove()">
            <?php endif; ?>
            <span class="dorsal dorsal-grande" aria-hidden="true">
                <span class="dorsal-topo"><?= $ts !== null ? mb_substr(DateHelper::formatarDiaMes($data), 0, 3) : '' ?></span>
                <span class="dorsal-dia"><?= $ts !== null ? date('j', $ts) : '?' ?></span>
            </span>
        </div>

        <div class="prova-cabeca">
            <ul class="etiquetas">
                <li class="etiqueta-<?= htmlspecialchars($categoria) ?>"><?= htmlspecialchars($subtipo ?? $nomesCategoria[$categoria] ?? 'Estrada') ?></li>
                <?php foreach ($distancias as $distancia): ?>
                    <li><?= htmlspecialchars($distancia) ?></li>
                <?php endforeach; ?>
            </ul>
            <h1><?= htmlspecialchars($c['nome']) ?></h1>
            <p class="prova-quando">
                <?= $ts !== null ? htmlspecialchars(DateHelper::formatarLongo($data)) : 'Data por confirmar' ?>
                <?php if ($horaTexto !== null): ?><span class="prova-hora">às <?= $horaTexto ?></span><?php elseif ($ts !== null): ?><span class="nota-inline">· hora por confirmar</span><?php endif; ?>
                <?php if ($jaPassou): ?><span class="ja-passou">Já se realizou</span><?php endif; ?>
            </p>
            <?php if ($c['local']): ?>
                <p class="prova-local">
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M12 21s-6.5-6.1-6.5-11a6.5 6.5 0 0 1 13 0c0 4.9-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/></svg>
                    <?= htmlspecialchars($c['local']) ?>
                    <?php if ($zona !== null): ?><span class="prova-zona"><?= htmlspecialchars($zona) ?></span><?php endif; ?>
                    <a href="<?= htmlspecialchars($urlMapa) ?>" target="_blank" rel="noopener">Ver no mapa</a>
                </p>
            <?php endif; ?>
            <?php if (isset($c['inscricao_min'], $c['inscricao_max'])): /* Lido na página da prova ou no regulamento (InscricaoHelper). */ ?>
                <p class="prova-inscricao" title="<?= htmlspecialchars((string) ($c['inscricao_excerto'] ?? '')) ?>">
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M4 7h16v3a2 2 0 0 0 0 4v3H4v-3a2 2 0 0 0 0-4z"/><path d="M14 7v10" stroke-dasharray="2 2"/></svg>
                    Inscrição <strong><?= htmlspecialchars(InscricaoHelper::formatar((float) $c['inscricao_min'], (float) $c['inscricao_max'])) ?></strong>
                    <?php if ((float) $c['inscricao_max'] > (float) $c['inscricao_min']): ?><span class="nota-inline">conforme a data e a distância</span><?php endif; ?>
                    <?php if (!empty($c['inscricao_url'])): ?><a href="<?= htmlspecialchars($c['inscricao_url']) ?>" target="_blank" rel="noopener">Ver preços</a><?php endif; ?>
                </p>
            <?php endif; ?>

            <?php /* O mais importante: a ligação ao organizador. */ ?>
            <div class="prova-organizacao">
                <?php if (!empty($c['organizador'])): ?>
                    <p class="prova-organizador"><span>Organização</span> <?= htmlspecialchars($organizador['nome']) ?></p>
                <?php endif; ?>
                <div class="prova-acoes">
                    <?php if ($organizador !== null && $organizador['url'] !== null): ?>
                        <a class="botao-organizador" href="<?= htmlspecialchars($organizador['url']) ?>" target="_blank" rel="noopener">
                            <?= match (true) {
                                $organizadorEInscricao => 'Site da prova e inscrições',
                                $organizador['e_site'] => 'Site do organizador',
                                $organizador['e_regulamento'] => 'Regulamento da prova',
                                default => 'Site da prova',
                            } ?>
                            <?= $iconeExterno ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($urlInscricao !== null && !$organizadorEInscricao): ?>
                        <a class="botao-secundario" href="<?= htmlspecialchars($urlInscricao) ?>" target="_blank" rel="noopener">
                            Inscrição
                            <?= $iconeExterno ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($auth->autenticado()): $ativo = !empty($c['e_favorito']); ?>
                        <form class="favorito" method="post" action="/favorito.php">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($auth->tokenCsrf()) ?>">
                            <input type="hidden" name="corrida_id" value="<?= (int) $c['id'] ?>">
                            <input type="hidden" name="voltar" value="<?= htmlspecialchars($voltar) ?>">
                            <button type="submit" class="favorito-btn<?= $ativo ? ' ativo' : '' ?>" aria-pressed="<?= $ativo ? 'true' : 'false' ?>"
                                    title="<?= $ativo ? 'Tirar do meu calendário' : 'Guardar no meu calendário' ?>">
                                <?= $iconeGuardar ?>
                                <span class="rotulo"><?= $ativo ? 'Guardada' : 'Guardar' ?></span>
                            </button>
                        </form>
                    <?php else: ?>
                        <a class="favorito-btn" href="/login.php?next=<?= urlencode($voltar) ?>"
                           title="Entra para guardar provas no teu calendário">
                            <?= $iconeGuardar ?>
                            <span class="rotulo">Guardar</span>
                        </a>
                    <?php endif; ?>
                </div>
                <?php if ($urlGoogleCalendar !== null): ?>
                    <p class="prova-links">
                        <span>Adicionar ao calendário:</span>
                        <a href="<?= htmlspecialchars($urlGoogleCalendar) ?>" target="_blank" rel="noopener">Google Calendar</a>
                        <a href="/prova-ics.php?id=<?= (int) $c['id'] ?>">iPhone, Outlook (.ics)</a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </article>

    <?php if ($programa !== []): ?>
        <section class="prova-seccao">
            <h2><?= $programaTemEscaloes ? 'Programa e escalões' : 'Programa' ?></h2>
            <div class="tabela-rolar">
                <table class="tabela-prova">
                    <thead>
                        <tr>
                            <th scope="col">Hora</th>
                            <th scope="col">Prova</th>
                            <?php if ($programaTemEscaloes): ?><th scope="col">Escalões</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($programa as $linha): ?>
                            <tr>
                                <td class="tabela-hora"><?= !empty($linha['hora']) ? htmlspecialchars(str_replace(':', 'h', $linha['hora'])) : '—' ?></td>
                                <td><?= htmlspecialchars((string) $linha['prova']) ?></td>
                                <?php if ($programaTemEscaloes): ?><td><?= htmlspecialchars((string) ($linha['escaloes'] ?? '—')) ?></td><?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php /* Só quando há prémios em dinheiro: "sem prémios" ou "por saber" não interessa mostrar. */ ?>
    <?php if ($podeVerPremios && $premios === true): ?>
        <section class="prova-seccao">
            <h2>
                Prémios monetários
                <span class="premios premios-sim" aria-hidden="true">€</span>
            </h2>
            <?php if ($soTotalAnunciado): ?>
                <p class="premios-total">Total anunciado: <strong><?= PremiosHelper::formatarEuros($totalPremios) ?></strong></p>
                <p class="nota">O regulamento só diz o total; a divisão pelos lugares não foi lida.</p>
            <?php elseif ($tabelasPremios === []): ?>
                <p>Há prémios em dinheiro, mas o texto lido não diz os valores. O que lá está:</p>
            <?php else: ?>
                <div class="tabela-rolar">
                    <table class="tabela-prova tabela-premios">
                        <thead>
                            <tr>
                                <th scope="col">Lugar</th>
                                <?php foreach ($tabelasPremios as $i => $tabela): ?>
                                    <th scope="col"><?= htmlspecialchars(PremiosHelper::rotulo($tabela)) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lugaresPremios as $lugar => $valores): ?>
                                <tr>
                                    <td><?= $lugar ?>.º</td>
                                    <?php foreach ($tabelasPremios as $i => $tabela): ?>
                                        <td class="tabela-valor"><?= htmlspecialchars($valores[$i] ?? '—') ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="row">Total</th>
                                <?php foreach ($tabelasPremios as $tabela):
                                    // "× 24": quantas vezes a coluna mostrada é paga (sexos, escalões).
                                    $somaColuna = array_sum(array_map(fn (array $l) => PremiosHelper::montante($l[1]), $tabela['linhas']));
                                    $totalTabela = PremiosHelper::totalTabela($tabela);
                                    $vezes = $somaColuna > 0 ? $totalTabela / $somaColuna : 1;
                                    ?>
                                    <td class="tabela-valor">
                                        <?= PremiosHelper::formatarEuros($totalTabela) ?><?= $vezes > 1 && floor($vezes) == $vezes ? ' <span class="nota-inline">(' . PremiosHelper::formatarEuros($somaColuna) . ' × ' . (int) $vezes . ')</span>' : '' ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="premios-total">Total a distribuir: <strong><?= PremiosHelper::formatarEuros($totalPremios) ?></strong></p>
            <?php endif; ?>
            <?php if (!empty($c['premios_excerto']) && $tabelasPremios === []): ?>
                <blockquote class="premios-excerto">«<?= htmlspecialchars($c['premios_excerto']) ?>»</blockquote>
            <?php endif; ?>
            <?php if (!empty($c['premios_url'])): ?>
                <p class="nota">
                    Lido automaticamente <a href="<?= htmlspecialchars($c['premios_url']) ?>" target="_blank" rel="noopener">neste documento</a>.
                    Confirma sempre os valores no regulamento.
                </p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

</main>

<footer>
    Dados recolhidos automaticamente. Confirma sempre a data, o horário e o regulamento no site da prova.
</footer>

</body>
</html>
