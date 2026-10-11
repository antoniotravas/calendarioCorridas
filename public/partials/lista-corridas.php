<?php

declare(strict_types=1);

/**
 * Lista de provas agrupadas por mês. Espera: $grupos (DateHelper::agruparPorMes),
 * $auth (Auth) e, opcionalmente, $voltar (caminho para onde voltar depois de
 * guardar uma prova sem JavaScript).
 *
 * Cada mês é um <section data-mes="AAAA-MM">: ao carregar mais provas, o
 * JavaScript junta as do mesmo mês à secção que já está na página.
 */

$autenticado = $auth->autenticado();
$voltar ??= $_SERVER['REQUEST_URI'] ?? '/';
$anoAtual = date('Y');

/** Distâncias como etiquetas: "10K, 5K, Caminhada" + tipo quando não é corrida de estrada (trail, Hyrox). */
$etiquetasProva = static function (array $c): array {
    $etiquetas = array_filter(array_map('trim', explode(',', (string) $c['distancias'])), 'strlen');
    foreach (array_map('trim', explode(',', (string) $c['tipo'])) as $tipo) {
        if ($tipo !== '' && !in_array(mb_strtolower($tipo), ['estrada', 'corrida'], true)
            && !in_array(mb_strtolower($tipo), array_map('mb_strtolower', $etiquetas), true)
        ) {
            $etiquetas[] = $tipo;
        }
    }

    return array_values($etiquetas);
};

/** "A", "A e B", "A, B e C". */
$juntarFontes = static function (array $fontes): string {
    $links = array_map(
        static fn (array $f) => $f['url_fonte']
            ? '<a href="' . htmlspecialchars($f['url_fonte']) . '" target="_blank" rel="noopener">' . htmlspecialchars($f['fonte']) . '</a>'
            : htmlspecialchars($f['fonte']),
        $fontes
    );
    $ultimo = array_pop($links);

    return $links === [] ? (string) $ultimo : implode(', ', $links) . ' e ' . $ultimo;
};

foreach ($grupos as $chaveMes => $grupo):
    $tituloMes = $grupo['titulo'];
    if ($chaveMes !== 'sem-data' && str_starts_with($chaveMes, $anoAtual)) {
        $tituloMes = ucfirst(DateHelper::nomeMes((int) substr($chaveMes, 5, 2)));
    }
    ?>
    <section class="mes" data-mes="<?= htmlspecialchars($chaveMes) ?>">
        <h2><?= htmlspecialchars($tituloMes) ?></h2>
        <ul class="lista">
            <?php foreach ($grupo['corridas'] as $c):
                $ts = $c['data_prova'] !== null ? strtotime($c['data_prova']) : null;
                $etiquetas = $etiquetasProva($c);
                ?>
                <li class="prova">
                    <?php /* Miniatura: o cartaz da prova; por baixo, um dorsal com o dia,
                             que fica à vista se não houver imagem ou se ela falhar. */ ?>
                    <a class="miniatura" <?= $c['url_evento'] ? 'href="' . htmlspecialchars($c['url_evento']) . '" target="_blank" rel="noopener"' : '' ?>
                       tabindex="-1" aria-hidden="true">
                        <span class="dorsal">
                            <span class="dorsal-mes"><?= $ts !== null ? mb_substr(DateHelper::nomeMes((int) date('n', $ts)), 0, 3) : '' ?></span>
                            <span class="dorsal-dia"><?= $ts !== null ? date('j', $ts) : '?' ?></span>
                        </span>
                        <?php if (!empty($c['imagem_url'])): ?>
                            <img src="<?= htmlspecialchars($c['imagem_url']) ?>" alt="" width="96" height="96"
                                 loading="lazy" decoding="async" referrerpolicy="no-referrer"
                                 onerror="this.remove()">
                        <?php endif; ?>
                    </a>

                    <div class="prova-corpo">
                        <?php if ($ts !== null): ?>
                            <time class="prova-data" datetime="<?= htmlspecialchars($c['data_prova']) ?>">
                                <?= htmlspecialchars(DateHelper::formatarDiaMes($c['data_prova'])) ?>
                            </time>
                        <?php endif; ?>
                        <h3>
                            <?php if ($c['url_evento']): ?>
                                <a href="<?= htmlspecialchars($c['url_evento']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($c['nome']) ?></a>
                            <?php else: ?>
                                <?= htmlspecialchars($c['nome']) ?>
                            <?php endif; ?>
                        </h3>
                        <?php if ($c['local']): ?>
                            <p class="prova-local">
                                <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M12 21s-6.5-6.1-6.5-11a6.5 6.5 0 0 1 13 0c0 4.9-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/></svg>
                                <?= htmlspecialchars($c['local']) ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($etiquetas !== []): ?>
                            <ul class="etiquetas" aria-label="Distâncias">
                                <?php foreach ($etiquetas as $etiqueta): ?>
                                    <li><?= htmlspecialchars($etiqueta) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <p class="prova-fontes">Encontrada em <?= $juntarFontes($c['fontes']) ?></p>
                    </div>

                    <?php if ($autenticado): $ativo = !empty($c['e_favorito']); ?>
                        <form class="favorito" method="post" action="/favorito.php">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($auth->tokenCsrf()) ?>">
                            <input type="hidden" name="corrida_id" value="<?= (int) $c['id'] ?>">
                            <input type="hidden" name="voltar" value="<?= htmlspecialchars($voltar) ?>">
                            <button type="submit" class="favorito-btn<?= $ativo ? ' ativo' : '' ?>" aria-pressed="<?= $ativo ? 'true' : 'false' ?>"
                                    title="<?= $ativo ? 'Tirar do meu calendário' : 'Guardar no meu calendário' ?>">
                                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M7 3.5h10a1 1 0 0 1 1 1v16l-6-4-6 4v-16a1 1 0 0 1 1-1z"/></svg>
                                <span class="rotulo"><?= $ativo ? 'Guardada' : 'Guardar' ?></span>
                            </button>
                        </form>
                    <?php else: ?>
                        <a class="favorito-btn" href="/login.php?next=<?= urlencode($voltar) ?>"
                           title="Entra para guardar provas no teu calendário">
                            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M7 3.5h10a1 1 0 0 1 1 1v16l-6-4-6 4v-16a1 1 0 0 1 1-1z"/></svg>
                            <span class="rotulo">Guardar</span>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
