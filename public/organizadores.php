<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/../scripts/lib/RadarOrganizadores.php';

/*
 * Radar dos organizadores (só administradores): os sites para onde apontam as
 * provas (site do organizador, link da prova, links das fontes), com quantas
 * provas cada um tem. Os que têm RadarOrganizadores::MINIMO_PROVAS ou mais
 * provas no último ano e de onde o scraper ainda não lê provas são candidatos
 * a nova fonte. A tabela é recalculada no fim de cada execução do scraper
 * (ou com php scripts/atualizar_organizadores.php).
 */
$auth->exigirAdmin('/organizadores.php');

$ver = in_array($_GET['ver'] ?? '', ['candidatos', 'todos'], true) ? $_GET['ver'] : 'sem-scraper';
$todos = $db->listarOrganizadores();
$candidatos = count(array_filter($todos, fn (array $o) => RadarOrganizadores::eCandidato($o)));
$organizadores = match ($ver) {
    'candidatos' => array_filter($todos, fn (array $o) => RadarOrganizadores::eCandidato($o)),
    'sem-scraper' => array_filter($todos, fn (array $o) => !$o['tem_scraper']),
    default => $todos,
};
$atualizado = $todos !== [] ? max(array_column($todos, 'atualizado_em')) : null;
$separadores = ['sem-scraper' => 'Sem scraper', 'candidatos' => 'Candidatos', 'todos' => 'Todos'];

$tituloDocumento = 'Organizadores — Corridas';

?><!DOCTYPE html>
<html lang="pt-PT">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body>

<?php require __DIR__ . '/partials/cabecalho.php'; ?>

<main class="gestao">
    <div class="cabeca-pagina">
        <h1 class="pagina-titulo">Organizadores</h1>
    </div>

    <p class="gestao-explicacao">
        Sites para onde apontam as provas (site do organizador, da prova ou das inscrições), com quantas provas
        cada um tem. <strong>Candidatos</strong> a nova fonte: sites com <?= RadarOrganizadores::MINIMO_PROVAS ?> ou mais
        provas no último ano de onde o scraper ainda não lê provas.
    </p>

    <nav class="radar-ver" aria-label="Mostrar">
        <?php foreach ($separadores as $chave => $texto): ?>
            <a href="/organizadores.php<?= $chave === 'sem-scraper' ? '' : '?ver=' . $chave ?>"<?= $ver === $chave ? ' aria-current="page"' : '' ?>><?= $texto ?></a>
        <?php endforeach; ?>
    </nav>

    <p class="resumo">
        <?= count($organizadores) === 1 ? '1 site' : count($organizadores) . ' sites' ?> ·
        <?= $candidatos === 1 ? '1 candidato' : $candidatos . ' candidatos' ?> a nova fonte
        <?php if ($atualizado !== null): ?>· atualizado a <?= date('d/m/Y H:i', (int) strtotime((string) $atualizado)) ?><?php endif; ?>
    </p>

    <?php if ($organizadores === []): ?>
        <p class="vazio">
            <?= $todos === [] ? 'O radar ainda não foi calculado: corre o scraper ou php scripts/atualizar_organizadores.php.' : 'Nenhum site nesta lista.' ?>
        </p>
    <?php else: ?>
        <div class="tabela-rolar">
            <table class="tabela-prova tabela-ranking tabela-radar">
                <thead>
                    <tr>
                        <th scope="col">Site</th>
                        <th scope="col" title="Desde há um ano, incluindo as futuras">Provas</th>
                        <th scope="col">Futuras</th>
                        <th scope="col" class="radar-fontes">Encontradas em</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($organizadores as $o): ?>
                        <tr class="<?= RadarOrganizadores::eCandidato($o) ? 'radar-candidato' : '' ?>">
                            <td>
                                <a href="<?= htmlspecialchars($o['site_url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($o['dominio']) ?></a>
                                <?php if ($o['tem_scraper']): ?><span class="etiqueta-admin">Fonte</span><?php elseif (RadarOrganizadores::eCandidato($o)): ?><span class="etiqueta-premios">Candidato</span><?php endif; ?>
                                <span class="ranking-info">
                                    <?= $o['nome'] !== null ? htmlspecialchars($o['nome']) . ' · ' : '' ?>ex.
                                    <a class="radar-exemplo" href="/prova.php?id=<?= (int) $o['exemplo_corrida_id'] ?>"><?= htmlspecialchars((string) $o['exemplo_nome']) ?></a>
                                </span>
                                <span class="ranking-info radar-fontes-movel"><?= htmlspecialchars((string) $o['fontes']) ?></span>
                            </td>
                            <td class="tabela-valor"><?= (int) $o['provas_ano'] ?></td>
                            <td class="tabela-valor radar-futuras"><?= (int) $o['provas_futuras'] ?></td>
                            <td class="radar-fontes ranking-info"><?= htmlspecialchars((string) $o['fontes']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>

</body>
</html>
