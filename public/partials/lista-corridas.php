<?php

declare(strict_types=1);

/**
 * Lista de provas agrupadas por mês e, dentro do mês, por dia: o dia aparece
 * uma vez (um dorsal com o dia da semana e o número) e ao lado ficam os
 * cartões das provas desse dia. Espera: $grupos (DateHelper::agruparPorMes),
 * $auth (Auth) e, opcionalmente, $voltar (caminho para onde voltar depois de
 * guardar uma prova sem JavaScript).
 *
 * Marcação usada pelo JavaScript ao carregar mais provas: <section data-mes>
 * e <div class="dia" data-dia> — provas de um mês/dia que já está na página
 * juntam-se a esse mês/dia em vez de o repetir.
 */

$autenticado = $auth->autenticado();
$voltar ??= $_SERVER['REQUEST_URI'] ?? '/';
$anoAtual = date('Y');

$nomesCategoria = ['trail' => 'Trail', 'corta-mato' => 'Corta-mato', 'pista' => 'Pista'];

/** Distâncias como etiquetas ("10K", "5K", "Caminhada") e o tipo, se não for estrada. */
$etiquetasProva = static function (array $c) use ($nomesCategoria): array {
    $etiquetas = array_values(array_filter(array_map('trim', explode(',', (string) $c['distancias'])), 'strlen'));
    $categoria = $nomesCategoria[$c['categoria'] ?? ''] ?? null;
    if ($categoria !== null && !in_array(mb_strtolower($categoria), array_map('mb_strtolower', $etiquetas), true)) {
        array_unshift($etiquetas, $categoria);
    }

    return $etiquetas;
};

/**
 * Ícone "€" dos prémios monetários (verde: há; cinzento riscado: o regulamento
 * diz que não há; nada: por saber). Liga ao documento onde foi lido, com o
 * excerto como dica.
 */
$iconePremios = static function (array $c): void {
    if (!isset($c['premios_monetarios'])) {
        return;
    }
    $sim = (int) $c['premios_monetarios'] === 1;
    $texto = $sim ? 'Com prémios monetários' : 'Sem prémios monetários';
    $dica = $texto . (!empty($c['premios_excerto']) ? ': ' . $c['premios_excerto'] : '');
    $classe = 'premios ' . ($sim ? 'premios-sim' : 'premios-nao');
    if (!empty($c['premios_url'])): ?>
        <a class="<?= $classe ?>" href="<?= htmlspecialchars($c['premios_url']) ?>" target="_blank" rel="noopener"
           title="<?= htmlspecialchars($dica) ?>" aria-label="<?= htmlspecialchars($texto) ?> (ver onde foi lido)">€</a>
    <?php else: ?>
        <span class="<?= $classe ?>" title="<?= htmlspecialchars($dica) ?>" role="img" aria-label="<?= htmlspecialchars($texto) ?>">€</span>
    <?php endif;
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

$iconeGuardar = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M7 3.5h10a1 1 0 0 1 1 1v16l-6-4-6 4v-16a1 1 0 0 1 1-1z"/></svg>';

foreach ($grupos as $chaveMes => $grupo):
    $tituloMes = $grupo['titulo'];
    if ($chaveMes !== 'sem-data' && str_starts_with($chaveMes, $anoAtual)) {
        $tituloMes = ucfirst(DateHelper::nomeMes((int) substr($chaveMes, 5, 2)));
    }

    $porDia = [];
    foreach ($grupo['corridas'] as $c) {
        $porDia[$c['data_prova'] ?? 'sem-data'][] = $c;
    }
    ?>
    <section class="mes" data-mes="<?= htmlspecialchars($chaveMes) ?>">
        <h2><?= htmlspecialchars($tituloMes) ?></h2>

        <?php foreach ($porDia as $dia => $provasDoDia):
            $ts = $dia !== 'sem-data' ? strtotime($dia) : null;
            ?>
            <div class="dia" data-dia="<?= htmlspecialchars($dia) ?>">
                <div class="dia-marca">
                    <span class="dorsal" aria-hidden="true">
                        <span class="dorsal-topo"><?= $ts !== null ? mb_substr(DateHelper::formatarDiaMes($dia), 0, 3) : '' ?></span>
                        <span class="dorsal-dia"><?= $ts !== null ? date('j', $ts) : '?' ?></span>
                    </span>
                    <h3 class="dia-titulo"><?= $ts !== null ? htmlspecialchars(DateHelper::formatarDiaMes($dia)) : 'Data por confirmar' ?></h3>
                </div>

                <ul class="provas">
                    <?php foreach ($provasDoDia as $c):
                        $etiquetas = $etiquetasProva($c);
                        ?>
                        <li class="prova<?= $c['url_evento'] ? ' com-link' : '' ?>">
                            <span class="miniatura" aria-hidden="true">
                                <?php if (!empty($c['imagem_url'])): ?>
                                    <img src="<?= htmlspecialchars($c['imagem_url']) ?>" alt="" width="88" height="88"
                                         loading="lazy" decoding="async" referrerpolicy="no-referrer"
                                         onerror="this.remove()">
                                <?php endif; ?>
                            </span>

                            <div class="prova-corpo">
                                <h4>
                                    <?php if ($c['url_evento']): ?>
                                        <?php /* O ::after deste link cobre o cartão todo (ver .com-link no CSS). */ ?>
                                        <a class="prova-link" href="<?= htmlspecialchars($c['url_evento']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($c['nome']) ?></a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($c['nome']) ?>
                                    <?php endif; ?>
                                    <?php $iconePremios($c); ?>
                                </h4>
                                <?php if ($c['local']): ?>
                                    <p class="prova-local">
                                        <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M12 21s-6.5-6.1-6.5-11a6.5 6.5 0 0 1 13 0c0 4.9-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/></svg>
                                        <?= htmlspecialchars($c['local']) ?>
                                    </p>
                                <?php endif; ?>
                                <?php if ($etiquetas !== []): ?>
                                    <ul class="etiquetas" aria-label="Tipo e distâncias">
                                        <?php foreach ($etiquetas as $etiqueta): ?>
                                            <?php $tipoEtiqueta = array_search($etiqueta, $nomesCategoria, true); ?>
                                            <li<?= $tipoEtiqueta !== false ? ' class="etiqueta-' . $tipoEtiqueta . '"' : '' ?>><?= htmlspecialchars($etiqueta) ?></li>
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
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </section>
<?php endforeach; ?>
