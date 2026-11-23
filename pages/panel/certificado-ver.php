<?php
/** Panel · Ver certificado de atención (vista previa + descarga PDF). El router define $certId. */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/certificados.php';

$c = certGet((int) ($certId ?? 0));
$paciente = $c ? getPaciente((int) $c['paciente_id']) : null;
if (!$c || !$paciente) {
    http_response_code(404);
    $panelTitle = 'Certificado no encontrado';
    $panelActive = 'pacientes';
    include APP_ROOT . '/partials/panel/top.php';
    echo '<div class="rounded-2xl border border-cafe/10 bg-crema-light p-8 text-center font-body text-cafe/60">Ese certificado no existe. <a href="' . e(base_path('/admin/pacientes')) . '" class="text-terracota-dark">Volver a pacientes</a></div>';
    include APP_ROOT . '/partials/panel/bottom.php';
    return;
}
$cid = (int) $paciente['id'];
$pdfUrl = base_path('/admin/certificados/' . (int) $c['id'] . '/pdf');

$panelTitle = 'Certificado de atención';
$panelActive = 'pacientes';
include APP_ROOT . '/partials/panel/top.php';
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <a href="<?= e(base_path('/admin/pacientes/' . $cid)) ?>" class="inline-flex items-center gap-1.5 font-body text-sm font-semibold text-cafe/60 hover:text-terracota">← Volver a <?= e($paciente['nombre']) ?></a>
    <div class="flex flex-wrap items-center gap-2">
        <?php if (isAdmin()): ?>
            <button type="button" id="ct-del" class="rounded-full px-4 py-2 font-body text-sm font-semibold text-red-600/80 hover:bg-red-50 hover:text-red-700">Eliminar</button>
        <?php endif; ?>
        <a href="<?= e(base_path('/admin/certificados/' . (int) $c['id'] . '/editar')) ?>" class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota hover:text-terracota">Editar</a>
        <a href="<?= e($pdfUrl . '?ver=1') ?>" target="_blank" rel="noopener" class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota hover:text-terracota">Abrir PDF</a>
        <a href="<?= e($pdfUrl) ?>" class="inline-flex items-center gap-2 rounded-full bg-terracota px-5 py-2.5 font-body text-sm font-semibold text-crema-light shadow-card hover:bg-terracota-dark">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>
            Descargar PDF
        </a>
    </div>
</div>
<p class="mb-4 font-body text-xs text-cafe/50">Se descarga como <strong><?= e(certNombreArchivo($c)) ?></strong></p>

<div class="rounded-2xl border border-cafe/10 bg-crema p-4 sm:p-8">
    <?= certDocHtml($c, false) ?>
</div>

<?php if (isAdmin()): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var P = window.__PANEL__ || {};
    document.getElementById('ct-del').addEventListener('click', function () {
        ezConfirm('Se eliminará este certificado de forma permanente. ¿Continuar?', { title: 'Eliminar certificado', okText: 'Eliminar', danger: true }).then(function (si) {
            if (!si) return;
            fetch(P.base + '/api/panel/certificados', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf }, body: JSON.stringify({ action: 'eliminar', id: <?= (int) $c['id'] ?> }) })
                .then(function (r) { return r.json(); })
                .then(function (j) { if (j && j.ok) location.href = P.base + '/admin/pacientes/<?= $cid ?>'; else ezAlert((j && j.error) || 'No se pudo eliminar.'); });
        });
    });
});
</script>
<?php endif; ?>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
