<?php

declare(strict_types=1);

/**
 * Página individual de uma prova (/prova.php?id=N), aberta ao clicar num
 * cartão do calendário: título, data e hora, local, distrito, região, tipo,
 * distâncias, organização (com o link para o organizador, em destaque),
 * programa com os escalões, prémios monetários (só para quem tem acesso) e
 * onde foi encontrada.
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

$organizador = Organizador::de($c);
// Link da prova (inscrições/página oficial), quando é diferente do site do organizador.
$urlPaginaProva = !empty($c['url_evento']) && $c['url_evento'] !== ($organizador['url'] ?? null) ? $c['url_evento'] : null;

$programa = json_decode((string) ($c['programa'] ?? ''), true) ?: [];
$programaTemEscaloes = array_filter(array_column($programa, 'escaloes')) !== [];

$podeVerPremios = $auth->podeVerPremios();
$premios = isset($c['premios_monetarios']) ? (int) $c['premios_monetarios'] === 1 : null;
$tabelasPremios = $premios ? (json_decode((string) ($c['premios_tabela'] ?? ''), true) ?: []) : [];
// A tabela com o maior 1.º prémio (normalmente a geral) primeiro.
usort($tabelasPremios, fn (array $a, array $b) => (float) strtr($b['linhas'][0][1], ['.' => '', ',' => '.']) <=> (float) strtr($a['linhas'][0][1], ['.' => '', ',' => '.']));

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
                <li class="etiqueta-<?= htmlspecialchars($categoria) ?>"><?= htmlspecialchars($nomesCategoria[$categoria] ?? 'Estrada') ?></li>
            </ul>
            <h1><?= htmlspecialchars($c['nome']) ?></h1>
            <p class="prova-quando">
                <?= $ts !== null ? htmlspecialchars(DateHelper::formatarLongo($data)) : 'Data por confirmar' ?>
                <?php if ($horaTexto !== null): ?><span class="prova-hora">às <?= $horaTexto ?></span><?php endif; ?>
                <?php if ($jaPassou): ?><span class="ja-passou">Já se realizou</span><?php endif; ?>
            </p>
            <?php if ($c['local']): ?>
                <p class="prova-local">
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M12 21s-6.5-6.1-6.5-11a6.5 6.5 0 0 1 13 0c0 4.9-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/></svg>
                    <?= htmlspecialchars($c['local']) ?>
                    <a href="<?= htmlspecialchars($urlMapa) ?>" target="_blank" rel="noopener">Ver no mapa</a>
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
                                $organizador['e_site'] => 'Site do organizador',
                                $organizador['e_regulamento'] => 'Regulamento da prova',
                                default => 'Site da prova',
                            } ?>
                            <?= $iconeExterno ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($urlPaginaProva !== null): ?>
                        <a class="botao-secundario" href="<?= htmlspecialchars($urlPaginaProva) ?>" target="_blank" rel="noopener">
                            <?= Organizador::eDocumento($urlPaginaProva) ? 'Regulamento' : 'Inscrições / página da prova' ?>
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
            </div>
        </div>
    </article>

    <dl class="prova-dados">
        <div>
            <dt>Dia e hora</dt>
            <dd>
                <?= ($ts !== null ? htmlspecialchars(DateHelper::formatarLongo($data)) : 'Por confirmar') . ($horaTexto !== null ? ', às ' . $horaTexto : '') ?>
                <?php if ($hora === null && $ts !== null): ?><span class="nota">Hora por confirmar no site da prova</span><?php endif; ?>
            </dd>
        </div>
        <div>
            <dt>Local</dt>
            <dd><?= $c['local'] ? htmlspecialchars($c['local']) : 'Por confirmar' ?></dd>
        </div>
        <?php if ($distrito !== null): ?>
            <div>
                <dt><?= in_array($distrito, ['Açores', 'Madeira'], true) ? 'Região autónoma' : 'Distrito' ?></dt>
                <dd><?= htmlspecialchars($distrito) ?></dd>
            </div>
            <?php if (!in_array($distrito, ['Açores', 'Madeira'], true)): ?>
                <div>
                    <dt>Região</dt>
                    <dd><?= htmlspecialchars($regiao) ?></dd>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <div>
            <dt>Tipo</dt>
            <dd><?= htmlspecialchars($nomesCategoria[$categoria] ?? 'Estrada') ?><?= !empty($c['tipo']) && mb_strtolower((string) $c['tipo']) !== mb_strtolower($nomesCategoria[$categoria] ?? '') ? ' <span class="nota-inline">(' . htmlspecialchars((string) $c['tipo']) . ')</span>' : '' ?></dd>
        </div>
        <?php if ($distancias !== []): ?>
            <div>
                <dt>Distâncias</dt>
                <dd>
                    <ul class="etiquetas">
                        <?php foreach ($distancias as $distancia): ?>
                            <li><?= htmlspecialchars($distancia) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </dd>
            </div>
        <?php endif; ?>
        <?php if ($urlGoogleCalendar !== null): ?>
            <div>
                <dt>Adicionar ao calendário</dt>
                <dd class="prova-links">
                    <a href="<?= htmlspecialchars($urlGoogleCalendar) ?>" target="_blank" rel="noopener">Google Calendar</a>
                    <a href="/prova-ics.php?id=<?= (int) $c['id'] ?>">iPhone, Outlook (.ics)</a>
                </dd>
            </div>
        <?php endif; ?>
    </dl>

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

    <?php if ($podeVerPremios): ?>
        <section class="prova-seccao">
            <h2>
                Prémios monetários
                <?php if ($premios !== null): ?>
                    <span class="premios <?= $premios ? 'premios-sim' : 'premios-nao' ?>" aria-hidden="true">€</span>
                <?php endif; ?>
            </h2>
            <?php if ($premios === null): ?>
                <p class="nota">Ainda por saber — o regulamento não foi lido ou não fala de prémios.</p>
            <?php elseif (!$premios): ?>
                <p>Sem prémios em dinheiro.</p>
            <?php elseif ($tabelasPremios === []): ?>
                <p>Há prémios em dinheiro, mas não foi possível ler os valores — consulta o regulamento.</p>
            <?php else: ?>
                <div class="tabelas-premios">
                    <?php foreach ($tabelasPremios as $tabela): ?>
                        <table class="tabela-prova tabela-premios">
                            <caption><?= htmlspecialchars($tabela['titulo'] ?? 'Classificação') ?></caption>
                            <thead><tr><th scope="col">Lugar</th><th scope="col">Prémio</th></tr></thead>
                            <tbody>
                                <?php foreach ($tabela['linhas'] as [$lugar, $valor]): ?>
                                    <tr><td><?= (int) $lugar ?>.º</td><td class="tabela-valor"><?= htmlspecialchars($valor) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($premios !== null && !empty($c['premios_excerto']) && $tabelasPremios === []): ?>
                <blockquote class="premios-excerto">«<?= htmlspecialchars($c['premios_excerto']) ?>»</blockquote>
            <?php endif; ?>
            <?php if ($premios !== null && !empty($c['premios_url'])): ?>
                <p class="nota">
                    Lido automaticamente <a href="<?= htmlspecialchars($c['premios_url']) ?>" target="_blank" rel="noopener">neste documento</a>.
                    Confirma sempre os valores no regulamento.
                </p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <p class="prova-encontrada">
        <?= count($c['fontes']) === 1 ? 'Encontrada em' : 'Encontrada em ' . count($c['fontes']) . ' sites:' ?>
        <?php foreach ($c['fontes'] as $i => $f): ?><?= $i > 0 ? ', ' : ' ' ?><?php if ($f['url_fonte']): ?><a href="<?= htmlspecialchars($f['url_fonte']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($f['fonte']) ?></a><?php else: ?><?= htmlspecialchars($f['fonte']) ?><?php endif; ?><?php endforeach; ?>
    </p>
</main>

<footer>
    Dados recolhidos automaticamente. Confirma sempre a data, o horário e o regulamento no site da prova.
</footer>

</body>
</html>
