<?php
/**
 * Página principal. Reemplaza a app/page.tsx.
 */
declare(strict_types=1);

$content = getContent();
$hasLogo = hasLogo();

include APP_ROOT . '/partials/head.php';
include APP_ROOT . '/partials/header.php';
?>
<main>
    <?php include APP_ROOT . '/partials/hero-cine.php'; ?>
    <?php include APP_ROOT . '/partials/quienes-home.php'; ?>
    <?php include APP_ROOT . '/partials/atenciones.php'; ?>
    <?php include APP_ROOT . '/partials/profesionales.php'; ?>
    <?php include APP_ROOT . '/partials/boxes.php'; ?>
    <?php include APP_ROOT . '/partials/ubicacion.php'; ?>
</main>
<?php
include APP_ROOT . '/partials/footer.php';
include APP_ROOT . '/partials/foot.php';
