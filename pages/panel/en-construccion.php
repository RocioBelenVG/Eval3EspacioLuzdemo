<?php
/**
 * Placeholder de módulos del panel aún en construcción.
 * El router define $panelTitle, $panelActive y (opcional) $panelDesc antes de incluir.
 */
declare(strict_types=1);

requireAuthPage();
$panelDesc = $panelDesc ?? 'Este módulo se está construyendo y estará disponible muy pronto.';
include APP_ROOT . '/partials/panel/top.php';
?>
<div class="mx-auto max-w-xl rounded-2xl border border-dashed border-cafe/20 bg-crema-light p-10 text-center shadow-card">
    <span class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-terracota/12 text-terracota-dark">
        <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4M12 18v4M4.9 4.9l2.8 2.8M16.3 16.3l2.8 2.8M2 12h4M18 12h4M4.9 19.1l2.8-2.8M16.3 7.7l2.8-2.8"/></svg>
    </span>
    <h2 class="font-heading text-2xl font-semibold text-cafe"><?= e($panelTitle) ?></h2>
    <p class="mt-2 font-body text-sm text-cafe/60"><?= e($panelDesc) ?></p>
    <a href="<?= e(base_path('/admin')) ?>" class="mt-6 inline-flex items-center gap-2 rounded-full border-2 border-cafe/15 px-5 py-2.5 font-body text-sm font-semibold text-cafe transition-colors hover:border-terracota hover:text-terracota">← Volver al dashboard</a>
</div>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
