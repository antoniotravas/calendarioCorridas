<?php
/**
 * <head> comum a todas as páginas. Espera: $tituloDocumento, $urlEstilo, $urlScript.
 */
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#ffffff">
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg?v=1">
<title><?= htmlspecialchars($tituloDocumento) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Barlow:wght@400;500;600&display=swap">
<link rel="stylesheet" href="<?= htmlspecialchars($urlEstilo) ?>">
<script>
    // Os cartões começam invisíveis e aparecem com fade in (assets/app.js). Se o
    // app.js não chegar a correr, tira-se a classe para nada ficar escondido.
    document.documentElement.classList.add('js');
    setTimeout(function () { if (!window.calendarioPronto) { document.documentElement.classList.remove('js'); } }, 3000);
</script>
<script src="<?= htmlspecialchars($urlScript) ?>" defer></script>
