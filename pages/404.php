<?php
/**
 * Página 404. Reemplaza a notFound() de Next.
 */
declare(strict_types=1);

$content = $content ?? getContent();
$hasLogo = hasLogo();
$pageTitle = 'No encontrado — Espacio Luz';
http_response_code(404);
include APP_ROOT . '/partials/head.php';
?>
<div class="flex min-h-screen flex-col items-center justify-center bg-crema-light p-6 text-center">
    <?= render_logo(false) ?>
    <h1 class="mt-8 font-heading text-4xl font-semibold text-cafe">404</h1>
    <p class="mt-2 font-body text-cafe/70">La página que buscas no existe.</p>
    <a href="<?= e(base_path('/')) ?>" class="mt-6 inline-flex rounded-full bg-terracota px-6 py-3 font-body text-sm font-semibold text-crema-light transition-colors hover:bg-terracota-dark">
        Volver al inicio
    </a>
</div>
<?php include APP_ROOT . '/partials/foot.php'; ?>
