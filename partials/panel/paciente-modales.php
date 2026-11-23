<?php
/**
 * Modales + JS del detalle de paciente. Espera en scope: $cid, $esps, $profs,
 * $cliJson, $espJson, $profJson.
 */
declare(strict_types=1);
$INP = 'rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota';
$LBL = 'font-body text-xs font-semibold uppercase tracking-wide text-cafe/60';
$TXT = $INP . ' resize-none';
?>
<!-- Modal editar paciente -->
<?php $cliSel = pacienteSelectsHtml($INP . ' min-w-0 disabled:opacity-50'); ?>
<div id="cli-modal" class="fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto bg-cafe/50 p-4 backdrop-blur-sm">
    <div class="my-auto max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-crema-light p-6 shadow-warm">
        <h3 class="mb-3 font-heading text-xl font-semibold text-cafe">Editar datos del paciente</h3>
        <form id="cli-form" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="flex flex-col gap-1 sm:col-span-2"><span class="<?= $LBL ?>">Nombre *</span><input name="nombre" required class="<?= $INP ?>"></label>
            <div class="flex flex-col gap-1" data-campo><span class="<?= $LBL ?>" data-doc-label>RUT</span><div class="flex gap-2"><select name="documento_tipo" class="<?= $INP ?> min-w-0" style="flex:none;width:7.6rem" aria-label="Tipo de documento"><?php foreach (PACIENTE_DOCUMENTOS as $dk => $dl): ?><option value="<?= e($dk) ?>"><?= e($dl) ?></option><?php endforeach; ?></select><input name="rut" class="<?= $INP ?> min-w-0" style="flex:1" placeholder="12.345.678-9" aria-label="Número de documento"></div></div>
            <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Nacimiento</span><input name="fecha_nacimiento" type="date" class="<?= $INP ?>"></label>
            <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Sexo</span><?= $cliSel['sexo'] ?></label>
            <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Teléfono</span><input name="telefono" class="<?= $INP ?>"></label>
            <label class="flex flex-col gap-1 sm:col-span-2"><span class="<?= $LBL ?>">Correo</span><input name="email" type="email" class="<?= $INP ?>"></label>
            <label class="flex flex-col gap-1 sm:col-span-2"><span class="<?= $LBL ?>">Dirección</span><input name="direccion" class="<?= $INP ?>"></label>
            <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Región</span><?= $cliSel['region'] ?></label>
            <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Comuna</span><?= $cliSel['comuna'] ?></label>
            <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Previsión</span><?= $cliSel['prevision'] ?></label>
            <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Código de prestación</span><input name="codigo_prestacion" class="<?= $INP ?>" placeholder="Ej: FONO-100 / PSI-300"></label>
            <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Tutor / apoderado</span><input name="apoderado" class="<?= $INP ?>" placeholder="Nombre y relación (o «No aplica»)"></label>
            <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">RUT del apoderado</span><input name="apoderado_rut" class="<?= $INP ?>" placeholder="12.345.678-9"></label>
            <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Teléfono del tutor</span><input name="apoderado_telefono" class="<?= $INP ?>" placeholder="+56 9 ..."></label>
            <label class="flex flex-col gap-1 sm:col-span-2"><span class="<?= $LBL ?>">Giro (solo si se emite factura)</span><input name="giro" maxlength="160" class="<?= $INP ?>" placeholder="Ej: Servicios educacionales"></label>
            <label class="flex flex-col gap-1 sm:col-span-2"><span class="<?= $LBL ?>">Contacto de emergencia</span><input name="contacto_emergencia" class="<?= $INP ?>"></label>
            <label class="flex items-center gap-2 sm:col-span-2"><input type="checkbox" name="consentimiento" class="h-4 w-4 accent-[#D97757]"><span class="font-body text-xs text-cafe/70">Consentimiento de datos otorgado (Ley 19.628).</span></label>
            <p id="cli-err" class="hidden font-body text-sm font-medium text-red-600 sm:col-span-2"></p>
            <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" data-close class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota">Cancelar</button><button type="submit" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Guardar</button></div>
        </form>
    </div>
</div>
<?php include APP_ROOT . '/partials/panel/paciente-geo.php'; ?>
<?php include APP_ROOT . '/partials/panel/paciente-validacion.php'; ?>

<!-- Modal ficha -->
<div id="ficha-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-cafe/50 p-4 backdrop-blur-sm">
    <div class="w-full max-w-lg rounded-2xl bg-crema-light p-6 shadow-warm">
        <h3 id="ficha-title" class="mb-3 font-heading text-xl font-semibold text-cafe">Nueva ficha</h3>
        <form id="ficha-form" class="space-y-3">
            <input type="hidden" name="id">
            <div class="grid grid-cols-2 gap-3">
                <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Área / especialidad</span><select name="especialidad_slug" class="<?= $INP ?>"><?php foreach ($esps as $slug => $nom): ?><option value="<?= e($slug) ?>"><?= e($nom) ?></option><?php endforeach; ?></select></label>
                <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Profesional responsable</span><select name="profesional_responsable_id" class="<?= $INP ?>"><option value="">— Sin asignar —</option><?php foreach ($profs as $pid => $pn): ?><option value="<?= (int) $pid ?>"><?= e($pn) ?></option><?php endforeach; ?></select></label>
            </div>
            <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Motivo de consulta</span><textarea name="motivo_consulta" rows="2" class="<?= $TXT ?>"></textarea></label>
            <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Antecedentes</span><textarea name="antecedentes" rows="2" class="<?= $TXT ?>"></textarea></label>
            <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Impresión / diagnóstico</span><textarea name="diagnostico" rows="2" class="<?= $TXT ?>"></textarea></label>
            <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Objetivos terapéuticos</span><textarea name="objetivos" rows="2" class="<?= $TXT ?>"></textarea></label>
            <label class="flex flex-col gap-1" data-estado><span class="<?= $LBL ?>">Estado</span><select name="estado" class="<?= $INP ?>"><option value="activa">Activa</option><option value="cerrada">Cerrada</option></select></label>
            <p id="ficha-err" class="hidden font-body text-sm font-medium text-red-600"></p>
            <div class="flex justify-end gap-2"><button type="button" data-close class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota">Cancelar</button><button type="submit" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Guardar ficha</button></div>
        </form>
    </div>
</div>

<!-- Modal evolución -->
<div id="evo-modal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-cafe/50 p-4 backdrop-blur-sm">
    <div class="w-full max-w-md rounded-2xl bg-crema-light p-6 shadow-warm">
        <h3 id="evo-titulo" class="mb-3 font-heading text-xl font-semibold text-cafe">Nueva evolución</h3>
        <form id="evo-form" class="space-y-3">
            <input type="hidden" name="ficha_id"><input type="hidden" name="evo_id">
            <div class="grid grid-cols-2 gap-3">
                <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Fecha</span><input name="fecha" type="date" value="<?= date('Y-m-d') ?>" class="<?= $INP ?>"></label>
                <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Profesional</span><select name="profesional_id" class="<?= $INP ?>"><option value="">— —</option><?php foreach ($profs as $pid => $pn): ?><option value="<?= (int) $pid ?>"><?= e($pn) ?></option><?php endforeach; ?></select></label>
            </div>
            <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Nota de progreso *</span><textarea name="nota_progreso" rows="4" required class="<?= $TXT ?>" placeholder="Objetivos trabajados, avances, observaciones…"></textarea></label>
            <label class="flex flex-col gap-1"><span class="<?= $LBL ?>">Indicaciones / próxima sesión</span><textarea name="proxima_accion" rows="2" class="<?= $TXT ?>"></textarea></label>
            <p id="evo-err" class="hidden font-body text-sm font-medium text-red-600"></p>
            <div class="flex justify-end gap-2"><button type="button" data-close class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota">Cancelar</button><button type="submit" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Guardar evolución</button></div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var P = window.__PANEL__ || {};
    var CLI = <?= $cliJson ?>;
    function post(d) { return fetch(P.base + '/api/panel/pacientes', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf }, body: JSON.stringify(d) }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, d: j }; }); }); }
    function bindClose(modal) { modal.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', function () { hide(modal); }); }); modal.addEventListener('click', function (e) { if (e.target === modal) hide(modal); }); }
    function show(m) { m.classList.remove('hidden'); m.classList.add('flex'); } function hide(m) { m.classList.add('hidden'); m.classList.remove('flex'); }

    // Editar paciente
    var cliM = document.getElementById('cli-modal'), cliF = document.getElementById('cli-form'); bindClose(cliM);
    window.ezGeo.bind(cliF);
    window.ezPac.bind(cliF);
    document.getElementById('cli-edit').addEventListener('click', function () {
        ['nombre','rut','email','telefono','fecha_nacimiento','direccion','apoderado','apoderado_rut','apoderado_telefono','contacto_emergencia','codigo_prestacion','giro'].forEach(function (k) { if (cliF[k]) cliF[k].value = CLI[k] || ''; });
        // Selects: región -> comuna (dependiente), sexo y previsión.
        window.ezGeo.set(cliF, CLI.region || '', CLI.comuna || '');
        window.ezGeo.setSelect(cliF.sexo, CLI.sexo || '');
        window.ezGeo.setSelect(cliF.prevision, CLI.prevision || '');
        cliF.consentimiento.checked = !!CLI.consentimiento;
        window.ezPac.setDocumento(cliF, CLI.documento_tipo || 'rut', (CLI.documento_tipo || 'rut') === 'rut' ? (CLI.rut || '') : (CLI.documento_numero || ''));
        window.ezPac.refresh(cliF); show(cliM);
    });
    cliF.addEventListener('submit', function (e) { e.preventDefault(); var err = document.getElementById('cli-err'); err.classList.add('hidden');
        var d = { action: 'actualizar', id: CLI.id }; new FormData(cliF).forEach(function (v, k) { d[k] = v; }); d.consentimiento = cliF.consentimiento.checked ? 1 : 0;
        post(d).then(function (x) { if (!x.ok) { err.textContent = x.d.error || 'Error'; err.classList.remove('hidden'); return; } location.reload(); }); });

    // Ficha (nueva / editar)
    var fM = document.getElementById('ficha-modal'), fF = document.getElementById('ficha-form'); bindClose(fM);
    document.getElementById('ficha-nueva').addEventListener('click', function () {
        fF.reset(); fF.id.value = ''; document.getElementById('ficha-title').textContent = 'Nueva ficha'; fF.querySelector('[data-estado]').style.display = 'none';
        fF.especialidad_slug.disabled = false; document.getElementById('ficha-err').classList.add('hidden'); show(fM);
    });
    document.querySelectorAll('.ficha-edit').forEach(function (b) { b.addEventListener('click', function () {
        var d = JSON.parse(b.dataset.json); fF.reset(); document.getElementById('ficha-title').textContent = 'Editar ficha';
        fF.id.value = d.id; fF.especialidad_slug.value = d.especialidad_slug; fF.especialidad_slug.disabled = true;
        fF.profesional_responsable_id.value = d.profesional_responsable_id || ''; fF.motivo_consulta.value = d.motivo_consulta || ''; fF.antecedentes.value = d.antecedentes || ''; fF.diagnostico.value = d.diagnostico || ''; fF.objetivos.value = d.objetivos || ''; fF.estado.value = d.estado || 'activa';
        fF.querySelector('[data-estado]').style.display = ''; document.getElementById('ficha-err').classList.add('hidden'); show(fM);
    }); });
    fF.addEventListener('submit', function (e) { e.preventDefault(); var err = document.getElementById('ficha-err'); err.classList.add('hidden');
        var editar = fF.id.value !== '';
        var d = { action: editar ? 'ficha_actualizar' : 'ficha_crear', id: fF.id.value, paciente_id: CLI.id, especialidad_slug: fF.especialidad_slug.value, profesional_responsable_id: fF.profesional_responsable_id.value, motivo_consulta: fF.motivo_consulta.value, antecedentes: fF.antecedentes.value, diagnostico: fF.diagnostico.value, objetivos: fF.objetivos.value, estado: fF.estado.value };
        post(d).then(function (x) { if (!x.ok) { err.textContent = x.d.error || 'Error'; err.classList.remove('hidden'); return; } location.reload(); }); });

    // Evolución: crear, editar y eliminar. Tras guardar se recarga y, si la ficha
    // completa estaba abierta, se vuelve a abrir (#ver-ficha-ID).
    var eM = document.getElementById('evo-modal'), eF = document.getElementById('evo-form'); bindClose(eM);
    var eBtn = eF.querySelector('button[type=submit]');
    function recargar(fichaId) { var f = window.__fichaAbierta && window.__fichaAbierta(); location.hash = f ? 'ver-ficha-' + f : 'ficha-' + fichaId; location.reload(); }
    function abrirEvo(datos) {
        eF.reset(); document.getElementById('evo-err').classList.add('hidden');
        eF.ficha_id.value = datos.ficha_id; eF.evo_id.value = datos.id || '';
        eF.fecha.value = datos.fecha || new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
        eF.profesional_id.value = datos.profesional_id ? String(datos.profesional_id) : '';
        eF.nota_progreso.value = datos.nota_progreso || ''; eF.proxima_accion.value = datos.proxima_accion || '';
        document.getElementById('evo-titulo').textContent = datos.id ? 'Editar evolución' : 'Nueva evolución';
        eBtn.textContent = datos.id ? 'Guardar cambios' : 'Guardar evolución';
        show(eM); eF.nota_progreso.focus();
    }
    document.querySelectorAll('.evo-nueva').forEach(function (b) { b.addEventListener('click', function () { abrirEvo({ ficha_id: b.dataset.ficha, profesional_id: b.dataset.prof !== '0' ? b.dataset.prof : '' }); }); });
    // Delegado: los botones también existen dentro de «Ver ficha completa».
    document.addEventListener('click', function (e) {
        var ed = e.target.closest('[data-evo-edit]');
        if (ed) { abrirEvo(JSON.parse(ed.dataset.evoEdit)); return; }
        var del = e.target.closest('[data-evo-del]');
        if (!del) return;
        ezConfirm('¿Eliminar la evolución del ' + del.dataset.evoFecha + '? Dejará de verse en la ficha y en el PDF. Por la Ley 20.584 el registro se conserva oculto (con fecha y responsable de la eliminación).', { title: 'Eliminar evolución', okText: 'Eliminar', danger: true }).then(function (ok) {
            if (!ok) return;
            post({ action: 'evolucion_eliminar', id: parseInt(del.dataset.evoDel, 10) }).then(function (x) { if (!x.ok) { ezAlert(x.d.error || 'No se pudo eliminar.'); return; } recargar(del.dataset.ficha); });
        });
    });
    eF.addEventListener('submit', function (e) { e.preventDefault(); var err = document.getElementById('evo-err'); err.classList.add('hidden');
        var editar = eF.evo_id.value !== '';
        var d = { action: editar ? 'evolucion_actualizar' : 'evolucion_crear', id: eF.evo_id.value, ficha_id: eF.ficha_id.value, fecha: eF.fecha.value, profesional_id: eF.profesional_id.value, nota_progreso: eF.nota_progreso.value, proxima_accion: eF.proxima_accion.value };
        if (!d.nota_progreso.trim()) { err.textContent = 'La nota es obligatoria.'; err.classList.remove('hidden'); return; }
        eBtn.disabled = true;
        post(d).then(function (x) { eBtn.disabled = false; if (!x.ok) { err.textContent = x.d.error || 'Error'; err.classList.remove('hidden'); return; } recargar(d.ficha_id); }); });
});
</script>
