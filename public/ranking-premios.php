<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/*
 * Ranking dos prémios monetários (só administradores, Auth::podeVerRanking):
 * as provas a partir
 * de hoje ordenadas pelo total que distribuem em prémios (prize money
 * total), do maior para o menor. O total soma todos os valores das tabelas
 * lidas no regulamento, a dobrar quando a tabela vale para masculinos e
 * femininos (PremiosHelper::total). As provas com prémios cujos valores não
 * foram lidos ficam numa lista à parte, no fim.
 */
$auth->exigirLogin('/ranking-premios.php');
if (!$auth->podeVerRanking()) {
    http_response_code(403);
    die('Não tens acesso ao ranking dos prémios.');
}

$ranking = [];
$semValores = [];
foreach ($db->listarProvasComPremios() as $prova) {
    $tabelas = PremiosHelper::tabelasDaProva($prova['premios_tabela'], $prova['premios_excerto']);
    $total = PremiosHelper::total($tabelas);
    if ($total <= 0) {
        $semValores[] = $prova;
        continue;
    }
    $prova['total'] = $total;
    // Maior 1.º prémio (null quando só se conhece o total anunciado).
    $primeiros = array_map(fn (array $t) => PremiosHelper::montante($t['linhas'][0][1]), array_filter($tabelas, fn (array $t) => $t['linhas'] !== []));
    $prova['primeiro'] = $primeiros !== [] ? max($primeiros) : null;
    $prova['anunciado'] = $primeiros === [];
    $ranking[] = $prova;
}
usort($ranking, fn (array $a, array $b) => [$b['total'], $a['data_prova']] <=> [$a['total'], $b['data_prova']]);
$totalGeral = array_sum(array_column($ranking, 'total'));

$nomesCategoria = ['estrada' => 'Estrada', 'trail' => 'Trail', 'corta-mato' => 'Corta-mato', 'pista' => 'Pista'];
$tituloDocumento = 'Ranking dos prémios — Corridas';

?><!DOCTYPE html>
<html lang="pt-PT">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body>

<?php require __DIR__ . '/partials/cabecalho.php'; ?>

<main class="gestao">
    <div class="cabeca-pagina">
        <h1 class="pagina-titulo">Ranking dos prémios</h1>
    </div>

    <p class="resumo">
        <?= count($ranking) === 1 ? '1 prova' : count($ranking) . ' provas' ?> ·
        <strong><?= PremiosHelper::formatarEuros($totalGeral) ?></strong> em prémios
    </p>

    <?php if ($ranking === []): ?>
        <p class="vazio">Ainda não há provas com os valores dos prémios lidos.</p>
    <?php else: ?>
        <div class="tabela-rolar">
            <table class="tabela-prova tabela-ranking">
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">Prova</th>
                        <th scope="col" class="ranking-data">Data</th>
                        <th scope="col" class="ranking-primeiro">1.º prémio</th>
                        <th scope="col">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ranking as $i => $p): ?>
                        <tr>
                            <td class="ranking-posicao"><?= $i + 1 ?></td>
                            <td>
                                <a href="/prova.php?id=<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['nome']) ?></a>
                                <span class="ranking-info">
                                    <span class="ranking-data-movel"><?= date('d/m/Y', (int) strtotime((string) $p['data_prova'])) ?> · </span><?= htmlspecialchars($nomesCategoria[$p['categoria'] ?? 'estrada'] ?? 'Estrada') ?><?= $p['local'] ? ' · ' . htmlspecialchars($p['local']) : '' ?><?= $p['distancias'] ? ' · ' . htmlspecialchars($p['distancias']) : '' ?>
                                </span>
                            </td>
                            <td class="ranking-data"><?= date('d/m/Y', (int) strtotime((string) $p['data_prova'])) ?></td>
                            <td class="tabela-valor ranking-primeiro"><?= $p['primeiro'] !== null ? PremiosHelper::formatarEuros($p['primeiro']) : '—' ?></td>
                            <td class="tabela-valor ranking-total">
                                <?= PremiosHelper::formatarEuros($p['total']) ?>
                                <?php if ($p['anunciado']): ?><span class="ranking-info">total anunciado</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($semValores !== []): ?>
        <section class="prova-seccao">
            <h2>Com prémios, valores por saber (<?= count($semValores) ?>)</h2>
            <p class="nota">O regulamento diz que há prémios em dinheiro, mas os valores não foram lidos.</p>
            <ul class="ranking-sem-valores">
                <?php foreach ($semValores as $p): ?>
                    <li>
                        <a href="/prova.php?id=<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['nome']) ?></a>
                        <span class="ranking-info"><?= date('d/m/Y', (int) strtotime((string) $p['data_prova'])) ?><?= $p['local'] ? ' · ' . htmlspecialchars($p['local']) : '' ?></span>
                        <?php if (!empty($p['premios_url'])): ?>
                            <a class="ranking-regulamento" href="<?= htmlspecialchars($p['premios_url']) ?>" target="_blank" rel="noopener">regulamento</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</main>

</body>
</html>
