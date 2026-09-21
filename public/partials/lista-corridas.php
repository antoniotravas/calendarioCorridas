<?php

declare(strict_types=1);

/**
 * Espera: $grupos (DateHelper::agruparPorMes), $auth (Auth).
 */

function badgeTipo(?string $tipo): string
{
    $t = mb_strtolower((string) $tipo);
    return match (true) {
        str_contains($t, 'trail') => 'tag--trail',
        str_contains($t, 'caminhada') && !str_contains($t, 'corrida') => 'tag--caminhada',
        str_contains($t, 'hyrox') => 'tag--hyrox',
        default => 'tag--corrida',
    };
}

$autenticado = $auth->autenticado();
$voltar = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/');

if ($grupos === []): ?>
    <p class="vazio">Nenhuma prova encontrada com estes filtros.</p>
<?php else: ?>
    <?php foreach ($grupos as $grupo): ?>
        <h2 class="mes"><?= htmlspecialchars($grupo['titulo']) ?></h2>
        <ul class="lista">
            <?php foreach ($grupo['corridas'] as $c): ?>
                <li class="corrida">
                    <div class="data-badge">
                        <?php if ($c['data_prova'] !== null): $ts = strtotime($c['data_prova']); ?>
                            <span class="dia"><?= date('d', $ts) ?></span>
                            <span class="sem"><?= mb_substr(DateHelper::nomeMes((int) date('n', $ts)), 0, 3) ?></span>
                        <?php else: ?>
                            <span class="dia">?</span>
                        <?php endif; ?>
                    </div>
                    <div class="detalhe">
                        <div class="detalhe-topo">
                            <h3>
                                <?php if ($c['url_evento']): ?>
                                    <a href="<?= htmlspecialchars($c['url_evento']) ?>" target="_blank" rel="noopener">
                                        <?= htmlspecialchars($c['nome']) ?>
                                    </a>
                                <?php else: ?>
                                    <?= htmlspecialchars($c['nome']) ?>
                                <?php endif; ?>
                            </h3>
                            <?php if ($autenticado): $ativo = !empty($c['e_favorito']); ?>
                                <form class="favorito" method="post" action="/favorito.php">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($auth->tokenCsrf()) ?>">
                                    <input type="hidden" name="corrida_id" value="<?= (int) $c['id'] ?>">
                                    <input type="hidden" name="voltar" value="<?= $voltar ?>">
                                    <button type="submit" class="favorito-btn <?= $ativo ? 'ativo' : '' ?>">
                                        <?= $ativo ? '✓ No meu calendário' : '+ Adicionar' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php if ($c['local']): ?>
                            <p class="local">📍 <?= htmlspecialchars($c['local']) ?></p>
                        <?php endif; ?>
                        <?php if ($c['tipo'] || $c['distancias']): ?>
                            <div class="tags">
                                <?php if ($c['tipo']): ?>
                                    <span class="tag <?= badgeTipo($c['tipo']) ?>"><?= htmlspecialchars($c['tipo']) ?></span>
                                <?php endif; ?>
                                <?php if ($c['distancias']): ?>
                                    <span class="tag"><?= htmlspecialchars($c['distancias']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <p class="fonte">
                            Fonte:
                            <?php if ($c['url_fonte']): ?>
                                <a href="<?= htmlspecialchars($c['url_fonte']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($c['fonte']) ?></a>
                            <?php else: ?>
                                <?= htmlspecialchars($c['fonte']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
<?php endif; ?>
