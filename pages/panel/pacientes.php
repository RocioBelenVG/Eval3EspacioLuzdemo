<?php
/** Panel · Pacientes (lista + búsqueda). */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/pacientes.php';

$q = trim((string) ($_GET['q'] ?? ''));
$pacientes = pacientesLista($q);

$panelTitle = 'Pacientes';
$panelActive = 'pacientes';
include APP_ROOT . '/partials/panel/top.php';
?>
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <form method="get" action="<?= e(base_path('/admin/pacientes')) ?>" class="flex items-center gap-2">
        <input name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre, RUT o teléfono…" class="w-72 max-w-full rounded-full border border-cafe/20 bg-crema-light px-4 py-2.5 font-body text-sm text-cafe outline-none focus:border-terracota">
        <button class="rounded-full bg-cafe px-4 py-2.5 font-body text-sm font-semibold text-crema-light hover:bg-cafe-light">Buscar</button>
    </form>
    <button type="button" id="c-nuevo-btn" class="inline-flex items-center gap-2 rounded-full bg-terracota px-5 py-2.5 font-body text-sm font-semibold text-crema-light shadow-card hover:bg-terracota-dark">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg> Nuevo paciente
    </button>
</div>

<div class="mb-2 flex items-center gap-4 font-body text-xs text-cafe/55">
    <span class="inline-flex items-center gap-1.5"><span class="h-3 w-1 rounded-full bg-salvia"></span>Paciente</span>
    <span class="inline-flex items-center gap-1.5"><span class="h-3 w-1 rounded-full bg-terracota/75"></span>Con tutor / apoderado</span>
</div>
<div class="ez-tabla-card">
    <div class="overflow-x-auto">
        <table class="ez-tabla">
            <thead><tr>
                <th class="px-4">Paciente</th><th class="px-4">RUT</th><th class="px-4">Teléfono</th><th class="px-4">Tutor / apoderado</th><th class="px-4">Dirección</th><th class="px-4">Cód. prestación</th><th class="px-4"></th>
            </tr></thead>
            <tbody class="font-body text-sm text-cafe/85">
                <?php if (!$pacientes): ?>
                    <tr><td colspan="7" class="px-5 py-12 text-center text-cafe/45"><?= $q !== '' ? 'Sin resultados para «' . e($q) . '».' : 'Aún no hay pacientes. Crea el primero con «Nuevo paciente».' ?></td></tr>
                <?php else: foreach ($pacientes as $c):
                    $dir = trim(((string) ($c['direccion'] ?? '')) . (($c['comuna'] ?? '') !== '' ? ', ' . $c['comuna'] : ''));
                    $urlFicha = base_path('/admin/pacientes/' . $c['id']);
                    $menor = ($c['apoderado'] ?? '') !== '' && !preg_match('/^no aplica/i', (string) $c['apoderado']); ?>
                    <tr class="cursor-pointer <?= $menor ? 'acc-terracota' : '' ?>" onclick="location.href='<?= e($urlFicha) ?>'">
                        <td class="px-4 py-3 font-semibold text-cafe"><?= e($c['nombre']) ?></td>
                        <td class="px-4 py-3 whitespace-nowrap"><?= e(pacienteDocumento($c) ?: '—') ?></td>
                        <td class="px-4 py-3 whitespace-nowrap"><?= e($c['telefono'] ?? '—') ?></td>
                        <td class="px-4 py-3"><?= e(($c['apoderado'] ?? '') !== '' ? $c['apoderado'] : '—') ?></td>
                        <td class="px-4 py-3"><?= e($dir !== '' ? $dir : '—') ?></td>
                        <td class="px-4 py-3 whitespace-nowrap"><?php $cp = trim((string) ($c['codigo_prestacion'] ?? '')); ?><?= $cp !== '' ? '<span class="inline-block rounded-full bg-salvia/20 px-2.5 py-0.5 font-body text-xs font-semibold text-cafe">' . e($cp) . '</span>' : '—' ?></td>
                        <td class="px-4 py-3 text-right">
                            <a href="<?= e($urlFicha) ?>" class="ez-row-btn" onclick="event.stopPropagation()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/></svg>
                                Ver ficha
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal nuevo paciente -->
<?php $cSel = pacienteSelectsHtml('cinp'); ?>
<div id="c-modal" class="fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto bg-cafe/50 p-4 backdrop-blur-sm">
    <div class="my-auto max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-crema-light p-6 shadow-warm">
        <h3 class="mb-3 font-heading text-xl font-semibold text-cafe">Nuevo paciente</h3>
        <form id="c-nuevo" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="flex flex-col gap-1 sm:col-span-2"><span class="clbl">Nombre completo *</span><input name="nombre" required class="cinp"></label>
            <div class="flex flex-col gap-1" data-campo><span class="clbl" data-doc-label>RUT</span><div class="flex gap-2"><select name="documento_tipo" class="cinp" style="flex:none;width:7.6rem" aria-label="Tipo de documento"><?php foreach (PACIENTE_DOCUMENTOS as $dk => $dl): ?><option value="<?= e($dk) ?>"><?= e($dl) ?></option><?php endforeach; ?></select><input name="rut" class="cinp" style="flex:1" placeholder="12.345.678-9" aria-label="Número de documento"></div></div>
            <label class="flex flex-col gap-1"><span class="clbl">Fecha de nacimiento</span><input name="fecha_nacimiento" type="date" class="cinp"></label>
            <label class="flex flex-col gap-1"><span class="clbl">Sexo</span><?= $cSel['sexo'] ?></label>
            <label class="flex flex-col gap-1"><span class="clbl">Teléfono</span><input name="telefono" class="cinp" placeholder="+56 9 ..."></label>
            <label class="flex flex-col gap-1 sm:col-span-2"><span class="clbl">Correo</span><input name="email" type="email" class="cinp"></label>
            <label class="flex flex-col gap-1"><span class="clbl">Tutor / apoderado</span><input name="apoderado" class="cinp" placeholder="Nombre y relación (o «No aplica»)"></label>
            <label class="flex flex-col gap-1"><span class="clbl">RUT del apoderado</span><input name="apoderado_rut" class="cinp" placeholder="12.345.678-9"></label>
            <label class="flex flex-col gap-1"><span class="clbl">Teléfono del tutor</span><input name="apoderado_telefono" class="cinp" placeholder="+56 9 ..."></label>
            <label class="flex flex-col gap-1 sm:col-span-2"><span class="clbl">Dirección</span><input name="direccion" class="cinp" placeholder="Calle, número, sector"></label>
            <label class="flex flex-col gap-1"><span class="clbl">Región</span><?= $cSel['region'] ?></label>
            <label class="flex flex-col gap-1"><span class="clbl">Comuna</span><?= $cSel['comuna'] ?></label>
            <label class="flex flex-col gap-1"><span class="clbl">Previsión</span><?= $cSel['prevision'] ?></label>
            <label class="flex flex-col gap-1"><span class="clbl">Código de prestación</span><input name="codigo_prestacion" class="cinp" placeholder="Ej: FONO-100 / PSI-300"></label>
            <label class="flex items-center gap-2 sm:col-span-2"><input type="checkbox" name="consentimiento" class="h-4 w-4 accent-[#D97757]"><span class="font-body text-xs text-cafe/70">El paciente/apoderado autoriza el tratamiento de sus datos (Ley 19.628).</span></label>
            <p id="c-err" class="hidden font-body text-sm font-medium text-red-600 sm:col-span-2"></p>
            <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" id="c-cancel" class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota">Cancelar</button><button type="submit" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Crear paciente</button></div>
        </form>
    </div>
</div>
<?php include APP_ROOT . '/partials/panel/paciente-geo.php'; ?>
<?php include APP_ROOT . '/partials/panel/paciente-validacion.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.cinp').forEach(function (el) { el.className = 'cinp min-w-0 rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota disabled:opacity-50'; });
    document.querySelectorAll('.clbl').forEach(function (el) { el.className = 'clbl font-body text-xs font-semibold uppercase tracking-wide text-cafe/60'; });
    var P = window.__PANEL__ || {}, modal = document.getElementById('c-modal'), f = document.getElementById('c-nuevo');
    window.ezGeo.bind(f);
    window.ezPac.bind(f);
    document.getElementById('c-nuevo-btn').addEventListener('click', function () { modal.classList.remove('hidden'); modal.classList.add('flex'); });
    document.getElementById('c-cancel').addEventListener('click', function () { modal.classList.add('hidden'); modal.classList.remove('flex'); });
    modal.addEventListener('click', function (e) { if (e.target === modal) { modal.classList.add('hidden'); modal.classList.remove('flex'); } });
    f.addEventListener('submit', function (e) { e.preventDefault(); var err = document.getElementById('c-err'); err.classList.add('hidden');
        var d = { action: 'crear' }; new FormData(f).forEach(function (v, k) { d[k] = v; }); d.consentimiento = f.consentimiento.checked ? 1 : 0;
        fetch(P.base + '/api/panel/pacientes', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf }, body: JSON.stringify(d) })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, d: j }; }); })
            .then(function (x) { if (!x.ok) { err.textContent = x.d.error || 'Error'; err.classList.remove('hidden'); return; } location.href = P.base + '/admin/pacientes/' + x.d.id; });
    });
});
</script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
