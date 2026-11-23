<?php
/**
 * Informes clínicos del paciente: listado con filtro por tipo, ver/descargar,
 * adjuntar (con área, profesional y fecha), editar datos y eliminar (oculta).
 * Requiere $paciente, $cid, $fichas, $profs, $informes (desde pages/panel/paciente-ver.php).
 */
declare(strict_types=1);
require_once APP_ROOT . '/lib/informes.php';

$infColor = [
    'evaluacion' => 'bg-terracota/12 text-terracota-dark', 'avance' => 'bg-salvia/20 text-salvia-dark',
    'orientacion_familiar' => 'bg-amber-100 text-amber-800', 'alta' => 'bg-green-100 text-green-700',
    'derivacion' => 'bg-cafe/10 text-cafe', 'externo' => 'bg-cafe/10 text-cafe', 'otro' => 'bg-cafe/10 text-cafe/80',
];
$infPorTipo = array_count_values(array_column($informes, 'tipo'));
$infINP = 'rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota';
$infLBL = 'font-body text-xs font-semibold uppercase tracking-wide text-cafe/60';
$infIco = static fn(string $d) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
?>
<!-- Informes clínicos (archivos adjuntos) -->
<div class="mt-6 ez-tabla-card" id="informes">
    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
        <div class="min-w-0">
            <h3 class="font-heading text-lg font-semibold text-cafe">Informes clínicos (<?= count($informes) ?>)</h3>
            <p class="font-body text-xs text-cafe/50">Informes entregados al paciente o recibidos de otros especialistas. Se pueden ver y descargar cuando se necesiten.</p>
        </div>
        <button type="button" data-inf-nuevo class="ez-row-btn"><?= $infIco('<path d="M21.4 11.1l-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/>') ?> Adjuntar informe</button>
    </div>
    <?php if (count($infPorTipo) > 1): ?>
        <div class="flex flex-wrap gap-1.5 px-5 pb-3" id="inf-filtros">
            <button type="button" data-inf-filtro="" class="rounded-full bg-cafe px-3 py-1 font-body text-xs font-semibold text-crema-light">Todos (<?= count($informes) ?>)</button>
            <?php foreach (INFORME_TIPOS as $tk => $tl): if (empty($infPorTipo[$tk])) continue; ?>
                <button type="button" data-inf-filtro="<?= e($tk) ?>" class="rounded-full border border-cafe/15 bg-white px-3 py-1 font-body text-xs font-semibold text-cafe/70 hover:border-terracota"><?= e($tl) ?> (<?= (int) $infPorTipo[$tk] ?>)</button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="overflow-x-auto">
        <table class="ez-tabla font-body text-sm">
            <thead><tr><th class="px-4">Fecha</th><th class="px-4">Tipo</th><th class="px-4">Informe</th><th class="px-4">Área</th><th class="px-4">Profesional</th><th class="px-4"></th></tr></thead>
            <tbody class="text-cafe/85">
                <?php if (!$informes): ?>
                    <tr><td colspan="6" class="px-4 py-8 text-center text-cafe/45">Aún no hay informes adjuntos. Usa «Adjuntar informe» para subir el primero (por ejemplo, el de evaluación y diagnóstico).</td></tr>
                <?php else: foreach ($informes as $inf):
                    $ext = strtoupper(pathinfo((string) $inf['archivo'], PATHINFO_EXTENSION));
                    $infJson = e(json_encode(['id' => (int) $inf['id'], 'tipo' => $inf['tipo'], 'titulo' => $inf['titulo'], 'fecha_informe' => $inf['fecha_informe'],
                        'ficha_id' => $inf['ficha_id'] !== null ? (int) $inf['ficha_id'] : '', 'profesional_id' => $inf['profesional_id'] !== null ? (int) $inf['profesional_id'] : '',
                        'descripcion' => (string) $inf['descripcion'], 'archivo' => $inf['nombre_original']], JSON_UNESCAPED_UNICODE)); ?>
                    <tr data-inf-tipo="<?= e($inf['tipo']) ?>">
                        <td class="px-4 py-3 font-semibold text-cafe whitespace-nowrap"><?= e(date('d-m-Y', strtotime((string) $inf['fecha_informe']))) ?></td>
                        <td class="max-w-[11rem] px-4 py-3"><span class="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-semibold leading-tight <?= $infColor[$inf['tipo']] ?? 'bg-cafe/10 text-cafe' ?>"><?= e(INFORME_TIPOS[$inf['tipo']] ?? $inf['tipo']) ?></span></td>
                        <td class="min-w-[12rem] max-w-[18rem] px-4 py-3">
                            <div class="font-semibold text-cafe"><?= e($inf['titulo']) ?></div>
                            <div class="truncate text-xs text-cafe/50" title="<?= e($inf['nombre_original']) ?>"><?= e($ext) ?> · <?= e(informeTamanoTexto((int) $inf['tamano'])) ?> · <?= e($inf['nombre_original']) ?></div>
                            <?php if ($inf['descripcion']): ?><div class="mt-0.5 whitespace-pre-line text-xs text-cafe/65"><?= e($inf['descripcion']) ?></div><?php endif; ?>
                        </td>
                        <td class="px-4 py-3"><?= $inf['especialidad_nombre'] ? e($inf['especialidad_nombre']) : '<span class="text-cafe/40">General</span>' ?></td>
                        <td class="px-4 py-3"><?= $inf['profesional_nombre'] ? e($inf['profesional_nombre']) : '<span class="text-cafe/40">—</span>' ?></td>
                        <td class="px-3 py-3"><div class="flex items-center justify-end gap-1">
                            <?php if (informeVisible($inf)): ?><a href="<?= e(informeUrl($inf, true)) ?>" target="_blank" rel="noopener" class="mr-1 font-body text-xs font-semibold text-cafe/60 hover:text-terracota">Ver</a><?php endif; ?>
                            <a href="<?= e(informeUrl($inf)) ?>" class="ez-row-btn"><?= $infIco('<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>') ?> Descargar</a>
                            <button type="button" data-inf-edit='<?= $infJson ?>' class="rounded-md p-1.5 text-cafe/50 hover:bg-cafe/5 hover:text-terracota-dark" title="Editar datos del informe" aria-label="Editar datos del informe"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></button>
                            <button type="button" data-inf-del="<?= (int) $inf['id'] ?>" data-inf-nombre="<?= e($inf['titulo']) ?>" class="rounded-md p-1.5 text-cafe/50 hover:bg-red-50 hover:text-red-600" title="Eliminar informe" aria-label="Eliminar informe"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/></svg></button>
                        </div></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal adjuntar / editar informe -->
<div id="inf-modal" class="fixed inset-0 z-[75] hidden items-center justify-center overflow-y-auto bg-cafe/50 p-4 backdrop-blur-sm">
    <div class="my-auto w-full max-w-lg rounded-2xl bg-crema-light p-6 shadow-warm">
        <h3 id="inf-titulo-modal" class="font-heading text-xl font-semibold text-cafe">Adjuntar informe</h3>
        <p class="mb-3 font-body text-xs text-cafe/55"><?= e($paciente['nombre']) ?></p>
        <form id="inf-form" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <input type="hidden" name="id">
            <label class="flex flex-col gap-1 sm:col-span-2"><span class="<?= $infLBL ?>">Tipo de informe *</span>
                <select name="tipo" required class="<?= $infINP ?>"><option value="">— Elegir —</option><?php foreach (INFORME_TIPOS as $tk => $tl): ?><option value="<?= e($tk) ?>"><?= e($tl) ?></option><?php endforeach; ?></select></label>
            <label class="flex flex-col gap-1"><span class="<?= $infLBL ?>">Fecha del informe *</span><input name="fecha_informe" type="date" required max="<?= date('Y-m-d') ?>" class="<?= $infINP ?>"></label>
            <label class="flex flex-col gap-1"><span class="<?= $infLBL ?>">Área (ficha)</span>
                <select name="ficha_id" class="<?= $infINP ?>"><option value="">General (sin área)</option><?php foreach ($fichas as $f): ?><option value="<?= (int) $f['id'] ?>" data-prof="<?= (int) ($f['profesional_responsable_id'] ?? 0) ?>"><?= e((string) ($f['especialidad_nombre'] ?? $f['especialidad_slug'])) ?></option><?php endforeach; ?></select></label>
            <label class="flex flex-col gap-1 sm:col-span-2"><span class="<?= $infLBL ?>">Profesional que lo emitió</span>
                <select name="profesional_id" class="<?= $infINP ?>"><option value="">— Externo o sin indicar —</option><?php foreach ($profs as $pid => $pn): ?><option value="<?= (int) $pid ?>"><?= e($pn) ?></option><?php endforeach; ?></select></label>
            <label class="flex flex-col gap-1 sm:col-span-2"><span class="<?= $infLBL ?>">Título</span><input name="titulo" maxlength="200" class="<?= $infINP ?>" placeholder="Si lo dejas vacío se usa el tipo de informe"></label>
            <label class="flex flex-col gap-1 sm:col-span-2"><span class="<?= $infLBL ?>">Observación</span><textarea name="descripcion" rows="2" maxlength="2000" class="<?= $infINP ?> resize-none" placeholder="Opcional: a quién se entregó, para qué se emitió…"></textarea></label>
            <div class="sm:col-span-2" id="inf-archivo-wrap">
                <span class="<?= $infLBL ?>">Archivo *</span>
                <label id="inf-drop" class="mt-1 flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-cafe/20 bg-white/70 px-4 py-5 text-center hover:border-terracota">
                    <svg class="h-6 w-6 text-terracota" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4M7 9l5-5 5 5M5 20h14"/></svg>
                    <span id="inf-drop-txt" class="font-body text-sm font-semibold text-cafe">Arrastra el archivo aquí o haz clic para elegirlo</span>
                    <span class="font-body text-xs text-cafe/50">PDF, Word (DOC/DOCX) o imagen (JPG, PNG, WEBP) · máximo 20 MB</span>
                    <input type="file" name="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp,application/pdf,image/*" class="sr-only">
                </label>
            </div>
            <p id="inf-archivo-fijo" class="hidden rounded-lg bg-white/70 px-3 py-2 font-body text-xs text-cafe/60 sm:col-span-2"></p>
            <div id="inf-progreso" class="hidden h-1.5 overflow-hidden rounded-full bg-cafe/10 sm:col-span-2"><div class="h-full w-0 bg-terracota transition-all"></div></div>
            <p id="inf-err" class="hidden font-body text-sm font-medium text-red-600 sm:col-span-2"></p>
            <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" data-close class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota">Cancelar</button><button type="submit" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark disabled:opacity-50">Guardar informe</button></div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var P = window.__PANEL__ || {}, PID = <?= (int) $cid ?>, MAX = <?= INFORME_MAX_BYTES ?>;
    var EXT = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp'];
    var M = document.getElementById('inf-modal'), F = document.getElementById('inf-form'), err = document.getElementById('inf-err');
    var btn = F.querySelector('button[type=submit]'), drop = document.getElementById('inf-drop'), dropTxt = document.getElementById('inf-drop-txt');
    var barra = document.getElementById('inf-progreso'), archivo = null;
    function hoy() { return new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10); }
    function show() { M.classList.remove('hidden'); M.classList.add('flex'); }
    function hide() { M.classList.add('hidden'); M.classList.remove('flex'); }
    function error(t) { err.textContent = t; err.classList.remove('hidden'); }
    function tam(b) { return b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
    function recargar() { var f = window.__fichaAbierta && window.__fichaAbierta(); location.hash = f ? 'ver-ficha-' + f : 'informes'; location.reload(); }
    M.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', hide); });
    M.addEventListener('click', function (e) { if (e.target === M) hide(); });

    function elegir(f) {
        err.classList.add('hidden'); archivo = null; dropTxt.textContent = 'Arrastra el archivo aquí o haz clic para elegirlo';
        if (!f) return;
        var ext = (f.name.split('.').pop() || '').toLowerCase();
        if (EXT.indexOf(ext) === -1) { error('Formato no admitido. Sube el informe en PDF, Word (DOC/DOCX) o imagen (JPG, PNG, WEBP).'); return; }
        if (f.size > MAX) { error('El archivo pesa ' + tam(f.size) + ' y el máximo es 20 MB.'); return; }
        archivo = f; dropTxt.textContent = f.name + ' · ' + tam(f.size);
    }
    F.file.addEventListener('change', function () { elegir(F.file.files[0]); });
    ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('border-terracota'); }); });
    ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.remove('border-terracota'); }); });
    drop.addEventListener('drop', function (e) { if (e.dataTransfer.files.length) elegir(e.dataTransfer.files[0]); });
    // Al elegir el área se propone su profesional responsable (si aún no se eligió otro).
    F.ficha_id.addEventListener('change', function () { var o = F.ficha_id.selectedOptions[0]; if (o && o.dataset.prof && o.dataset.prof !== '0' && !F.profesional_id.value) F.profesional_id.value = o.dataset.prof; });

    function abrir(d) {
        F.reset(); err.classList.add('hidden'); archivo = null; barra.classList.add('hidden'); barra.firstElementChild.style.width = '0';
        dropTxt.textContent = 'Arrastra el archivo aquí o haz clic para elegirlo';
        var editar = !!d.id;
        F.id.value = d.id || ''; F.tipo.value = d.tipo || ''; F.fecha_informe.value = d.fecha_informe || hoy();
        F.ficha_id.value = d.ficha_id ? String(d.ficha_id) : ''; F.profesional_id.value = d.profesional_id ? String(d.profesional_id) : '';
        if (!editar && d.ficha_id) F.ficha_id.dispatchEvent(new Event('change'));
        F.titulo.value = d.titulo || ''; F.descripcion.value = d.descripcion || '';
        document.getElementById('inf-titulo-modal').textContent = editar ? 'Editar datos del informe' : 'Adjuntar informe';
        document.getElementById('inf-archivo-wrap').classList.toggle('hidden', editar);
        var fijo = document.getElementById('inf-archivo-fijo'); fijo.classList.toggle('hidden', !editar);
        fijo.textContent = editar ? 'Archivo: ' + d.archivo + '. Para cambiar el archivo, adjunta un informe nuevo y elimina este.' : '';
        btn.textContent = editar ? 'Guardar cambios' : 'Guardar informe'; btn.disabled = false;
        show(); F.tipo.focus();
    }
    // Delegado: los botones también existen en las fichas y en «Ver ficha completa».
    document.addEventListener('click', function (e) {
        var n = e.target.closest('[data-inf-nuevo]');
        if (n) { abrir({ ficha_id: n.dataset.ficha || '' }); return; }
        var ed = e.target.closest('[data-inf-edit]');
        if (ed) { abrir(JSON.parse(ed.dataset.infEdit)); return; }
        var del = e.target.closest('[data-inf-del]');
        if (!del) return;
        ezConfirm('¿Eliminar el informe «' + del.dataset.infNombre + '»? Dejará de verse en la ficha. Por la Ley 20.584 el archivo se conserva oculto (con fecha y responsable de la eliminación).', { title: 'Eliminar informe', okText: 'Eliminar', danger: true }).then(function (ok) {
            if (!ok) return;
            fetch(P.base + '/api/panel/pacientes', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf }, body: JSON.stringify({ action: 'informe_eliminar', id: parseInt(del.dataset.infDel, 10) }) })
                .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'No se pudo eliminar.'); recargar(); }); })
                .catch(function (x) { ezAlert(x.message || 'No se pudo eliminar.'); });
        });
    });

    F.addEventListener('submit', function (e) {
        e.preventDefault(); err.classList.add('hidden');
        if (!F.tipo.value) { error('Elige el tipo de informe.'); return; }
        if (!F.fecha_informe.value) { error('Indica la fecha del informe.'); return; }
        var datos = { tipo: F.tipo.value, fecha_informe: F.fecha_informe.value, ficha_id: F.ficha_id.value, profesional_id: F.profesional_id.value, titulo: F.titulo.value, descripcion: F.descripcion.value };
        btn.disabled = true;
        if (F.id.value) {
            datos.action = 'informe_actualizar'; datos.id = parseInt(F.id.value, 10);
            fetch(P.base + '/api/panel/pacientes', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf }, body: JSON.stringify(datos) })
                .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'No se pudo guardar.'); recargar(); }); })
                .catch(function (x) { btn.disabled = false; error(x.message || 'No se pudo guardar.'); });
            return;
        }
        if (!archivo) { btn.disabled = false; error('Adjunta el archivo del informe.'); return; }
        var fd = new FormData(); fd.append('action', 'informe_subir'); fd.append('paciente_id', PID);
        Object.keys(datos).forEach(function (k) { fd.append(k, datos[k]); }); fd.append('file', archivo);
        var xhr = new XMLHttpRequest(); xhr.open('POST', P.base + '/api/panel/pacientes'); xhr.setRequestHeader('X-CSRF-Token', P.csrf);
        barra.classList.remove('hidden');
        xhr.upload.onprogress = function (ev) { if (ev.lengthComputable) barra.firstElementChild.style.width = Math.round(ev.loaded / ev.total * 100) + '%'; };
        xhr.onload = function () {
            var j = {}; try { j = JSON.parse(xhr.responseText); } catch (x) {}
            if (xhr.status === 200 && j.ok) { recargar(); return; }
            btn.disabled = false; barra.classList.add('hidden');
            error(j.error || (xhr.status === 413 ? 'El archivo es demasiado grande.' : 'No se pudo subir el informe. Inténtalo de nuevo.'));
        };
        xhr.onerror = function () { btn.disabled = false; barra.classList.add('hidden'); error('Sin conexión. Inténtalo de nuevo.'); };
        xhr.send(fd);
    });

    // Filtro por tipo
    document.querySelectorAll('[data-inf-filtro]').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = b.dataset.infFiltro;
            document.querySelectorAll('[data-inf-filtro]').forEach(function (x) {
                var on = x === b;
                x.className = 'rounded-full px-3 py-1 font-body text-xs font-semibold ' + (on ? 'bg-cafe text-crema-light' : 'border border-cafe/15 bg-white text-cafe/70 hover:border-terracota');
            });
            document.querySelectorAll('tr[data-inf-tipo]').forEach(function (r) { r.classList.toggle('hidden', t !== '' && r.dataset.infTipo !== t); });
        });
    });
});
</script>
