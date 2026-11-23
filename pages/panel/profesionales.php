<?php
/** Panel · Profesionales (solo admin). */
declare(strict_types=1);

requireAdminPage();
require_once APP_ROOT . '/lib/profesionales.php';
require_once APP_ROOT . '/partials/profesionales-fotos.php';
require_once APP_ROOT . '/lib/firmas.php';

$profs = profesionalesTodos(true);
$esps = especialidadesLista();

$panelTitle = 'Profesionales';
$panelActive = 'profesionales';
include APP_ROOT . '/partials/panel/top.php';
?>
<div class="ez-tabla-card">
        <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-4">
            <h2 class="font-heading text-lg font-semibold text-cafe">Equipo (<?= count($profs) ?>)</h2>
            <p class="font-body text-xs text-cafe/50">Para agregar un profesional, hazlo desde el sitio: <a href="<?= e(base_path('/#profesionales')) ?>" class="font-semibold text-terracota hover:underline">Ver el sitio</a> → modo edición → “Agregar profesional”.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="ez-tabla">
                <thead><tr>
                    <th class="px-5 py-3.5">Profesional</th><th class="px-4 py-3.5">RUT</th><th class="px-4 py-3.5">Área</th><th class="px-4 py-3.5 text-center">Firma y timbre</th><th class="px-4 py-3.5 text-center">Sitio</th><th class="px-4 py-3.5 text-center">Estado</th><th class="px-5 py-3.5 text-right">Acciones</th>
                </tr></thead>
                <tbody class="font-body text-base text-cafe/80">
                    <?php if (!$profs): ?>
                        <tr><td colspan="7" class="px-5 py-10 text-center text-cafe/45">Aún no hay profesionales.</td></tr>
                    <?php else: foreach ($profs as $p): $fotoUrl = profesionalFotoUrl($p); $firmaUrl = firmaUrl($p['firma_archivo'] ?? null); ?>
                        <?php $ff = !empty($p['firma_actualizada']) && $firmaUrl ? date('d-m-Y H:i', strtotime((string) $p['firma_actualizada'])) : ''; ?>
                        <tr data-id="<?= (int) $p['id'] ?>" data-area="<?= e((string) ($p['especialidad_nombre'] ?? $p['especialidad_slug'])) ?>" data-firma-fecha="<?= e($ff) ?>"
                            data-json='<?= e(json_encode(['nombre'=>$p['nombre'],'especialidad_slug'=>$p['especialidad_slug'],'descripcion'=>$p['descripcion'],'enfoque'=>$p['enfoque'],'duracion_texto'=>$p['duracion_texto'],'publico'=>(int)$p['publico'],'foto'=>$fotoUrl,'rut'=>$p['rut'] ?? ''], JSON_UNESCAPED_UNICODE)) ?>'>
                            <td class="px-5 py-5">
                                <div class="flex items-center gap-4">
                                    <?php if ($fotoUrl): ?>
                                        <img src="<?= e($fotoUrl) ?>" alt="" class="h-16 w-16 shrink-0 rounded-2xl object-cover shadow-sm ring-1 ring-cafe/10">
                                    <?php else: ?>
                                        <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-cafe/10 text-base font-semibold text-cafe/60"><?= e(getIniciales($p['nombre'])) ?></span>
                                    <?php endif; ?>
                                    <div><div class="text-base font-semibold text-cafe"><?= e($p['nombre']) ?></div><div class="text-sm text-cafe/55"><?= e($p['descripcion'] ?? '') ?></div>
                                        <?php if ((int) ($p['socia'] ?? 0) === 1): ?><span class="mt-1 inline-block rounded-full bg-salvia/20 px-2.5 py-0.5 text-[11px] font-bold text-salvia-dark" title="Emite boletas y facturas; no paga arriendo de box">Socia · emite boletas y facturas · sin arriendo</span>
                                        <?php else: ?><span class="mt-1 inline-block rounded-full bg-cafe/8 px-2.5 py-0.5 text-[11px] font-semibold text-cafe/55">Arrienda box · se le factura</span><?php endif; ?></div>
                                </div>
                            </td>
                            <td class="px-4 py-5 whitespace-nowrap"><?= !empty($p['rut']) ? e($p['rut']) : '<span class="text-sm text-amber-700">Falta RUT</span>' ?></td>
                            <td class="px-4 py-5"><?= e($p['especialidad_nombre'] ?? $p['especialidad_slug']) ?></td>
                            <td class="px-4 py-5">
                                <div class="flex flex-col items-center gap-1.5">
                                    <div class="firma-box relative flex h-[56px] w-[140px] items-center justify-center overflow-hidden rounded-lg border border-dashed border-cafe/25 bg-white">
                                        <span class="fv-lupa pointer-events-none absolute right-1 top-1 rounded-full bg-cafe/80 p-1 text-crema-light" aria-hidden="true"><svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3M11 8v6M8 11h6"/></svg></span>
                                        <img class="firma-img h-full w-full object-contain <?= $firmaUrl ? '' : 'hidden' ?>" src="<?= e((string) $firmaUrl) ?>" alt="Firma y timbre">
                                        <span class="firma-vacia font-body text-[11px] text-cafe/40 <?= $firmaUrl ? 'hidden' : '' ?>">Sin firma</span>
                                    </div>
                                    <div class="flex items-center gap-2 font-body text-xs font-semibold">
                                        <label class="cursor-pointer text-terracota-dark hover:text-terracota"><span class="firma-lbl"><?= $firmaUrl ? 'Cambiar' : 'Subir firma' ?></span><input type="file" class="firma-file hidden" accept="image/png,image/jpeg,image/webp"></label>
                                        <button type="button" class="firma-quitar text-cafe/45 hover:text-red-600 <?= $firmaUrl ? '' : 'hidden' ?>">Quitar</button>
                                    </div>
                                    <span class="firma-err hidden max-w-[180px] text-center font-body text-[11px] font-medium text-red-600"></span>
                                </div>
                            </td>
                            <td class="px-4 py-5 text-center"><?= $p['publico'] ? '<span class="rounded-full bg-salvia/20 px-3 py-1 text-xs font-semibold text-salvia-dark">Sí</span>' : '<span class="rounded-full bg-cafe/10 px-3 py-1 text-xs text-cafe/60">No</span>' ?></td>
                            <td class="px-4 py-5 text-center"><?= $p['activo'] ? '<span class="rounded-full bg-salvia/20 px-3 py-1 text-xs font-semibold text-salvia-dark">Activo</span>' : '<span class="rounded-full bg-cafe/15 px-3 py-1 text-xs text-cafe/70">Inactivo</span>' ?></td>
                            <td class="px-5 py-5">
                                <div class="flex flex-wrap items-center justify-end gap-1.5">
                                    <button type="button" class="p-edit rounded-lg border border-cafe/15 px-3.5 py-2 text-sm font-semibold text-cafe hover:border-terracota hover:text-terracota">Editar</button>
                                    <button type="button" class="p-activo rounded-lg border border-cafe/15 px-3.5 py-2 text-sm font-semibold text-cafe hover:border-terracota hover:text-terracota" data-activo="<?= (int) $p['activo'] ?>"><?= $p['activo'] ? 'Desactivar' : 'Activar' ?></button>
                                    <button type="button" class="p-socia rounded-lg border border-cafe/15 px-3.5 py-2 text-sm font-semibold text-cafe hover:border-terracota hover:text-terracota" data-socia="<?= (int) ($p['socia'] ?? 0) ?>"><?= (int) ($p['socia'] ?? 0) === 1 ? 'Quitar socia' : 'Marcar socia' ?></button>
                                    <button type="button" class="p-del rounded-lg border border-red-200 px-3.5 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">Eliminar</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<!-- Modal editar -->
<div id="p-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-cafe/50 p-4 backdrop-blur-sm">
    <div class="w-full max-w-md rounded-2xl bg-crema-light p-6 shadow-warm">
        <h3 class="mb-3 font-heading text-xl font-semibold text-cafe">Editar profesional</h3>
        <!-- Foto: se sube al elegir el archivo (independiente de "Guardar") -->
        <div class="mb-4 flex items-center gap-4">
            <div class="h-20 w-16 shrink-0 overflow-hidden rounded-xl bg-cafe/10">
                <img id="pe-foto" alt="" class="hidden h-full w-full object-cover">
            </div>
            <div class="flex flex-col gap-1">
                <label class="inline-flex cursor-pointer items-center justify-center rounded-full border-2 border-cafe/15 px-4 py-1.5 font-body text-sm font-semibold text-cafe hover:border-terracota">
                    <span id="pe-foto-lbl">Subir foto</span>
                    <input type="file" id="pe-foto-file" accept="image/jpeg,image/png,image/webp" class="hidden">
                </label>
                <span class="font-body text-xs text-cafe/50">JPG, PNG o WEBP · vertical 4:5 (ej. 960×1200 px)</span>
                <span id="pe-foto-err" class="hidden font-body text-xs font-medium text-red-600"></span>
            </div>
        </div>
        <form id="p-editar" class="space-y-3">
            <input type="hidden" name="id">
            <label class="flex flex-col gap-1"><span class="plbl">Nombre</span><input name="nombre" required class="pinp"></label>
            <label class="flex flex-col gap-1"><span class="plbl">RUT</span><input name="rut" class="pinp" placeholder="12.345.678-9" autocomplete="off"><span class="font-body text-[11px] text-cafe/45">Se usa automáticamente en boletas, facturas y certificados de atención.</span></label>
            <label class="flex flex-col gap-1"><span class="plbl">Especialidad / área</span><select name="especialidad_slug" class="pinp"><?php foreach ($esps as $slug => $nom): ?><option value="<?= e($slug) ?>"><?= e($nom) ?></option><?php endforeach; ?></select></label>
            <label class="flex flex-col gap-1"><span class="plbl">Descripción</span><input name="descripcion" class="pinp"></label>
            <label class="flex flex-col gap-1"><span class="plbl">Enfoque</span><input name="enfoque" class="pinp"></label>
            <label class="flex flex-col gap-1"><span class="plbl">Duración de sesión</span><input name="duracion_texto" class="pinp"></label>
            <label class="flex items-center gap-2"><input type="checkbox" name="publico" class="h-4 w-4 accent-[#D97757]"><span class="font-body text-sm text-cafe/70">Mostrar en el sitio público</span></label>
            <p id="pe-err" class="hidden font-body text-sm font-medium text-red-600"></p>
            <div class="flex justify-end gap-2"><button type="button" id="pe-cancel" class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota">Cancelar</button><button type="submit" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Guardar</button></div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.pinp').forEach(function (el) { el.className = 'pinp rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota'; });
    document.querySelectorAll('.plbl').forEach(function (el) { el.className = 'plbl font-body text-xs font-semibold uppercase tracking-wide text-cafe/60'; });
    var P = window.__PANEL__ || {};
    function post(d) { return fetch(P.base + '/api/panel/profesionales', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf }, body: JSON.stringify(d) }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, d: j }; }); }); }
    var modal = document.getElementById('p-modal'), fE = document.getElementById('p-editar');
    function openM() { modal.classList.remove('hidden'); modal.classList.add('flex'); } function closeM() { modal.classList.add('hidden'); modal.classList.remove('flex'); }
    document.getElementById('pe-cancel').addEventListener('click', closeM);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeM(); });
    document.querySelectorAll('tr[data-id]').forEach(function (tr) {
        var id = parseInt(tr.dataset.id, 10);
        tr.querySelector('.p-edit').addEventListener('click', function () {
            var d = JSON.parse(tr.dataset.json); showFoto(d.foto); fE.id.value = id; fE.nombre.value = d.nombre || ''; fE.rut.value = d.rut || ''; fE.especialidad_slug.value = d.especialidad_slug || ''; fE.descripcion.value = d.descripcion || ''; fE.enfoque.value = d.enfoque || ''; fE.duracion_texto.value = d.duracion_texto || ''; fE.publico.checked = !!d.publico; document.getElementById('pe-err').classList.add('hidden'); openM();
        });
        tr.querySelector('.p-socia').addEventListener('click', function () {
            var nom = JSON.parse(tr.dataset.json).nombre || 'esta profesional', es = tr.querySelector('.p-socia').dataset.socia === '1';
            ezConfirm(es ? '¿Quitar a ' + nom + ' como socia? Dejará de emitir boletas y sus horas de box pasarán a cobrarse como arriendo.'
                         : '¿Marcar a ' + nom + ' como socia? Emitirá boletas y facturas y NO se le cobrará arriendo de box (se quita de Clientes).',
                { title: es ? 'Quitar socia' : 'Marcar socia', okText: es ? 'Quitar' : 'Marcar' }).then(function (ok) {
                if (ok) post({ action: 'socia', id: id, socia: es ? 0 : 1 }).then(function (x) { if (!x.ok) { ezAlert(x.d.error || 'No se pudo cambiar.'); return; } location.reload(); });
            });
        });
        tr.querySelector('.p-activo').addEventListener('click', function () { var a = tr.querySelector('.p-activo').dataset.activo === '1' ? 0 : 1; post({ action: 'activo', id: id, activo: a }).then(function () { location.reload(); }); });
        tr.querySelector('.p-del').addEventListener('click', function () { ezConfirm('¿Eliminar a ' + (JSON.parse(tr.dataset.json).nombre || 'este profesional') + '? Dejará de aparecer en el panel y en el sitio. Sus boletas, fichas y reservas pasadas se conservan en el historial.', { title: 'Eliminar profesional', okText: 'Eliminar', danger: true }).then(function (ok) { if (!ok) return; post({ action: 'eliminar', id: id }).then(function (x) { if (!x.ok) { ezAlert(x.d.error || 'No se pudo eliminar.', { title: 'No se pudo eliminar' }); return; } location.reload(); }).catch(function () { ezAlert('Error de conexión. Intenta nuevamente.'); }); }); });
    });
    // Foto del profesional: vista previa + subida inmediata
    var fotoImg = document.getElementById('pe-foto'), fotoIn = document.getElementById('pe-foto-file'),
        fotoLbl = document.getElementById('pe-foto-lbl'), fotoErr = document.getElementById('pe-foto-err'), fotoCambiada = false;
    function showFoto(url) {
        fotoErr.classList.add('hidden'); fotoLbl.textContent = url ? 'Cambiar foto' : 'Subir foto';
        if (url) { fotoImg.src = url; fotoImg.classList.remove('hidden'); } else { fotoImg.removeAttribute('src'); fotoImg.classList.add('hidden'); }
    }
    fotoIn.addEventListener('change', function () {
        var file = fotoIn.files[0]; if (!file) return;
        var fd = new FormData(); fd.append('action', 'foto'); fd.append('id', fE.id.value); fd.append('file', file);
        fotoErr.classList.add('hidden'); fotoLbl.textContent = 'Subiendo…';
        fetch(P.base + '/api/panel/profesionales', { method: 'POST', headers: { 'X-CSRF-Token': P.csrf }, body: fd })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, d: j }; }); })
            .then(function (x) {
                if (!x.ok) { fotoErr.textContent = x.d.error || 'Error al subir'; fotoErr.classList.remove('hidden'); fotoLbl.textContent = 'Subir foto'; return; }
                fotoCambiada = true; showFoto(x.d.url);
            })
            .catch(function () { fotoErr.textContent = 'Error de conexión'; fotoErr.classList.remove('hidden'); fotoLbl.textContent = 'Subir foto'; })
            .then(function () { fotoIn.value = ''; });
    });
    // Si se cambió la foto, al cerrar se recarga para ver la miniatura nueva
    document.getElementById('pe-cancel').addEventListener('click', function () { if (fotoCambiada) location.reload(); });
    modal.addEventListener('click', function (e) { if (e.target === modal && fotoCambiada) location.reload(); });

    // Firma y timbre: se sube al elegir el archivo; el servidor la normaliza al formato fijo.
    document.querySelectorAll('tr[data-id]').forEach(function (tr) {
        var id = tr.dataset.id, inp = tr.querySelector('.firma-file'); if (!inp) return;
        var img = tr.querySelector('.firma-img'), vac = tr.querySelector('.firma-vacia'), lbl = tr.querySelector('.firma-lbl'),
            quitar = tr.querySelector('.firma-quitar'), err = tr.querySelector('.firma-err');
        function estado(url) {
            tr.dataset.firmaFecha = url ? 'Recién actualizada' : '';
            if (url) { img.src = url + (url.indexOf('?') < 0 ? '?' : '&') + 't=' + Date.now(); img.classList.remove('hidden'); vac.classList.add('hidden'); quitar.classList.remove('hidden'); lbl.textContent = 'Cambiar'; }
            else { img.removeAttribute('src'); img.classList.add('hidden'); vac.classList.remove('hidden'); quitar.classList.add('hidden'); lbl.textContent = 'Subir firma'; }
        }
        inp.addEventListener('change', function () {
            var file = inp.files[0]; if (!file) return;
            var fd = new FormData(); fd.append('action', 'firma'); fd.append('id', id); fd.append('file', file);
            err.classList.add('hidden'); var antes = lbl.textContent; lbl.textContent = 'Procesando…';
            fetch(P.base + '/api/panel/profesionales', { method: 'POST', headers: { 'X-CSRF-Token': P.csrf }, body: fd })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, d: j }; }); })
                .then(function (x) { if (!x.ok) { err.textContent = x.d.error || 'No se pudo subir.'; err.classList.remove('hidden'); lbl.textContent = antes; return; } estado(x.d.url); })
                .catch(function () { err.textContent = 'Error de conexión.'; err.classList.remove('hidden'); lbl.textContent = antes; })
                .then(function () { inp.value = ''; });
        });
        quitar.addEventListener('click', function () {
            ezConfirm('Se quitará la firma de este profesional. Los certificados ya emitidos conservan la suya.', { title: 'Quitar firma', okText: 'Quitar', danger: true }).then(function (ok) {
                if (!ok) return;
                post({ action: 'quitar-firma', id: parseInt(id, 10) }).then(function (x) { if (!x.ok) { ezAlert(x.d.error || 'No se pudo.'); return; } estado(null); });
            });
        });
    });

    fE.addEventListener('submit', function (e) { e.preventDefault(); var err = document.getElementById('pe-err'); err.classList.add('hidden');
        post({ action: 'actualizar', id: parseInt(fE.id.value, 10), nombre: fE.nombre.value.trim(), rut: fE.rut.value.trim(), especialidad_slug: fE.especialidad_slug.value, descripcion: fE.descripcion.value.trim(), enfoque: fE.enfoque.value.trim(), duracion_texto: fE.duracion_texto.value.trim(), publico: fE.publico.checked ? 1 : 0 })
            .then(function (x) { if (!x.ok) { err.textContent = x.d.error || 'Error'; err.classList.remove('hidden'); return; } location.reload(); }); });
});
</script>
<?php include APP_ROOT . '/partials/panel/firma-visor.php'; ?>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
