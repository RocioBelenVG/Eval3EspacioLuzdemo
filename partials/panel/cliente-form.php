<?php
/**
 * Ventana «Nuevo / editar cliente» (módulo Clientes). Pide TODO lo que exige una
 * factura electrónica, y el vínculo con el profesional y el box para automatizar.
 *
 * Uso: include este archivo y luego en JS:
 *   ClienteForm.abrir(null | datosCliente, { onGuardado: function (id, esNuevo) {...} })
 * Sin onGuardado: un cliente nuevo abre su ficha y una edición recarga la página.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/clientes.php';
require_once APP_ROOT . '/lib/pacientes.php'; // pacienteSelectsHtml(), catalogoRegiones()

$cfProfes = [];
try {
    foreach (db()->query("SELECT id, nombre, rut, socia FROM profesionales WHERE eliminado_at IS NULL ORDER BY activo DESC, nombre") as $p) {
        $cfProfes[] = ['id' => (int) $p['id'], 'nombre' => $p['nombre'], 'rut' => $p['rut'] ?? '', 'socia' => (int) ($p['socia'] ?? 0) === 1];
    }
} catch (Throwable $e) {
}
$cfBoxes = boxesFacturables();
$cfSel = pacienteSelectsHtml('cfinp');
?>
<style>
    .cf-sec { border-top: 1px solid rgba(92,64,51,.10); padding-top: 1rem; }
    .cf-sec:first-of-type { border-top: 0; padding-top: 0; }
    .cf-h { font-family: var(--font-body); font-size: 11px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: #B85C3E; }
    .cf-req::after { content: ' *'; color: #D97757; }
    .cfinp { min-width: 0; width: 100%; border-radius: .6rem; border: 1px solid rgba(92,64,51,.2); background: #FBF9F4; padding: .55rem .75rem; font-family: var(--font-body); font-size: .875rem; color: #5C4033; outline: none; }
    .cfinp:focus { border-color: #D97757; box-shadow: 0 0 0 3px rgba(217,119,87,.14); }
    .cfinp:disabled { opacity: .5; }
    .cfinp.cf-sii { border-color: #8B9E8F; background: rgba(139,158,143,.12); transition: background-color .6s ease, border-color .6s ease; }
    .cf-spin { display: inline-block; width: 11px; height: 11px; border-radius: 9999px; border: 2px solid rgba(92,64,51,.2); border-top-color: #D97757; animation: cf-rot .8s linear infinite; vertical-align: -1px; }
    @keyframes cf-rot { to { transform: rotate(360deg); } }
    #cf-sii-giros, #cf-sii-dirs { margin-top: .35rem; gap: .3rem; }
    .cfinp.cf-mal { border-color: #dc2626; background: #fef2f2; }
    .cflbl { font-family: var(--font-body); font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: rgba(92,64,51,.62); }
</style>

<div id="cf-modal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-cafe/55 p-3 backdrop-blur-sm sm:p-6" role="dialog" aria-modal="true" aria-labelledby="cf-titulo">
    <form id="cf-form" novalidate class="flex max-h-[94vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-crema-light shadow-warm">
        <div class="flex items-start justify-between gap-4 border-b border-cafe/10 px-5 py-4 sm:px-6">
            <div>
                <p class="cf-h">Clientes · datos de facturación</p>
                <h3 id="cf-titulo" class="font-heading text-xl font-semibold text-cafe">Nuevo cliente</h3>
            </div>
            <button type="button" data-cf-cerrar class="-mr-1 rounded-full p-2 text-cafe/50 hover:bg-cafe/5 hover:text-cafe" aria-label="Cerrar">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5 sm:px-6">
            <input type="hidden" name="id">

            <!-- Atajo: copiar desde un profesional (solo al crear) -->
            <div id="cf-copiar" class="rounded-xl border border-dashed border-salvia/60 bg-salvia/10 px-4 py-3">
                <label class="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:gap-3">
                    <span class="shrink-0 font-body text-sm font-semibold text-cafe">¿Es un profesional que arrienda box?</span>
                    <select id="cf-copiar-sel" class="cfinp sm:max-w-xs">
                        <option value="">Copiar sus datos desde…</option>
                        <?php foreach ($cfProfes as $p): if ($p['socia']) continue; ?><option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <p class="mt-1 font-body text-[11.5px] text-cafe/60">Rellena nombre y RUT y lo deja vinculado, para traer sus horas del Calendario de Boxes al facturar.</p>
            </div>

            <section class="cf-sec">
                <h4 class="cf-h mb-3">Datos tributarios</h4>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="flex flex-col gap-1"><span class="cflbl cf-req">RUT</span>
                        <input name="rut" class="cfinp" placeholder="76.123.456-7" autocomplete="off" inputmode="text">
                        <span id="cf-rut-msg" class="hidden font-body text-[11px]"></span></label>
                    <label class="flex flex-col gap-1"><span class="cflbl cf-req">Razón social</span><input name="razon_social" class="cfinp" placeholder="Nombre legal o de la persona"></label>
                    <label class="flex flex-col gap-1"><span class="cflbl">Nombre de fantasía</span><input name="nombre_fantasia" class="cfinp" placeholder="Opcional"></label>
                    <label class="flex flex-col gap-1"><span class="cflbl cf-req">Giro</span><input name="giro" list="cf-giros" class="cfinp" placeholder="Actividad que aparece en el SII"><span id="cf-sii-giros" class="hidden flex-wrap gap-1"></span>
                        <datalist id="cf-giros"><?php foreach (CLIENTE_GIROS_SUGERIDOS as $g): ?><option value="<?= e($g) ?>"><?php endforeach; ?></datalist></label>
                </div>
            </section>

            <section class="cf-sec">
                <h4 class="cf-h mb-3">Dirección tributaria</h4>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="flex flex-col gap-1 sm:col-span-2"><span class="cflbl cf-req">Dirección</span><input name="direccion" class="cfinp" placeholder="Calle, número, oficina"><span id="cf-sii-dirs" class="hidden flex-wrap gap-1"></span></label>
                    <label class="flex flex-col gap-1"><span class="cflbl">Región</span><?= $cfSel['region'] ?></label>
                    <label class="flex flex-col gap-1"><span class="cflbl cf-req">Comuna</span><?= $cfSel['comuna'] ?></label>
                    <label class="flex flex-col gap-1"><span class="cflbl">Ciudad</span><input name="ciudad" class="cfinp" placeholder="Opcional"></label>
                </div>
            </section>

            <section class="cf-sec">
                <h4 class="cf-h mb-3">Contacto</h4>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label class="flex flex-col gap-1"><span class="cflbl">Correo para facturas</span><input name="email" type="email" class="cfinp" placeholder="facturas@correo.cl"></label>
                    <label class="flex flex-col gap-1"><span class="cflbl">Teléfono</span><input name="telefono" class="cfinp" placeholder="+56 9 …"></label>
                    <label class="flex flex-col gap-1"><span class="cflbl">Persona de contacto</span><input name="contacto" class="cfinp" placeholder="Opcional"></label>
                </div>
            </section>

            <section class="cf-sec">
                <h4 class="cf-h mb-1">Arriendo de boxes</h4>
                <p class="mb-3 font-body text-[11.5px] text-cafe/55">Con esto la factura se arma sola: horas del calendario, box y tarifa ya elegidos.</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1 sm:col-span-2"><span class="cflbl">Profesionales vinculados</span>
                        <div id="cf-profs" class="grid max-h-40 grid-cols-1 gap-1 overflow-y-auto rounded-lg border border-cafe/15 bg-white p-2 sm:grid-cols-2">
                            <?php foreach ($cfProfes as $p): ?>
                                <?php if ($p['socia']): ?><span class="flex items-center gap-2 rounded px-1.5 py-1 font-body text-sm text-cafe/45" title="Socia: emite boletas y facturas, no paga arriendo de box"><input type="checkbox" disabled class="h-4 w-4"><?= e($p['nombre']) ?> <span class="rounded-full bg-salvia/20 px-1.5 text-[10px] font-bold text-salvia-dark">socia</span></span>
                                <?php else: ?><label class="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 font-body text-sm text-cafe hover:bg-crema/60"><input type="checkbox" name="profesional_ids" value="<?= (int) $p['id'] ?>" class="h-4 w-4 accent-[#D97757]"><?= e($p['nombre']) ?></label><?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <span class="font-body text-[11px] text-cafe/50">Sus horas del Calendario de Boxes se traen solas al facturar. Un centro o empresa puede tener varios. Las socias no pagan arriendo.</span></div>
                    <label class="flex flex-col gap-1"><span class="cflbl">Medio de pago habitual</span>
                        <select name="medio_pago" class="cfinp"><?php foreach (CLIENTE_MEDIOS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
                    <label class="flex flex-col gap-1"><span class="cflbl">Box habitual</span>
                        <select name="box_slug" class="cfinp"><option value="">— Sin box fijo —</option>
                            <?php foreach ($cfBoxes as $b): ?><option value="<?= e($b['slug']) ?>"><?= e($b['nombre']) ?></option><?php endforeach; ?>
                        </select></label>
                    <label class="flex flex-col gap-1"><span class="cflbl">Tarifa habitual</span>
                        <select name="tarifa" class="cfinp" disabled><option value="">— Elige primero el box —</option></select></label>
                    <label class="flex flex-col gap-1 sm:col-span-2"><span class="cflbl">Notas internas</span><textarea name="notas" rows="2" class="cfinp resize-none" placeholder="Opcional: acuerdos de pago, horarios, etc."></textarea></label>
                    <label id="cf-activo-wrap" class="flex items-center gap-2 sm:col-span-2"><input type="checkbox" name="activo" class="h-4 w-4 accent-[#D97757]" checked><span class="font-body text-sm text-cafe/75">Cliente activo (aparece al facturar)</span></label>
                </div>
            </section>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-cafe/10 bg-white/60 px-5 py-4 sm:px-6">
            <p id="cf-err" class="hidden min-w-0 flex-1 font-body text-sm font-medium text-red-600"></p>
            <p id="cf-ayuda" class="min-w-0 flex-1 font-body text-xs text-cafe/50"><span class="text-terracota">*</span> Obligatorio para facturar.</p>
            <div class="flex gap-2">
                <button type="button" data-cf-cerrar class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota">Cancelar</button>
                <button type="submit" id="cf-guardar" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Crear cliente</button>
            </div>
        </div>
    </form>
</div>
<?php include APP_ROOT . '/partials/panel/paciente-geo.php'; ?>

<script>
window.ClienteForm = (function () {
    var BASE = <?= json_encode(BASE_URL) ?>, CSRF = <?= json_encode(csrf_token()) ?>;
    var PROFES = <?= json_encode($cfProfes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var BOXES = <?= json_encode($cfBoxes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var modal = document.getElementById('cf-modal'), f = document.getElementById('cf-form');
    var err = document.getElementById('cf-err'), ayuda = document.getElementById('cf-ayuda'), btn = document.getElementById('cf-guardar');
    var rutMsg = document.getElementById('cf-rut-msg');
    var opciones = {}, foco = null;
    window.ezGeo.bind(f);

    function clp(n) { return '$' + (parseInt(n, 10) || 0).toLocaleString('es-CL'); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    // ---- RUT: dígito verificador + formato 12.345.678-9 ----
    function rutLimpio(r) { return String(r || '').toUpperCase().replace(/[^0-9K]/g, ''); }
    function rutDv(c) { var s = 0, m = 2; for (var i = c.length - 1; i >= 0; i--) { s += parseInt(c[i], 10) * m; m = m === 7 ? 2 : m + 1; } var r = 11 - (s % 11); return r === 11 ? '0' : r === 10 ? 'K' : String(r); }
    function rutValido(r) { var l = rutLimpio(r); if (!/^\d{7,8}[0-9K]$/.test(l)) return false; return rutDv(l.slice(0, -1)) === l.slice(-1); }
    function rutFormato(r) { var l = rutLimpio(r); if (l.length < 2) return r; var c = l.slice(0, -1), d = l.slice(-1); return c.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + '-' + d; }
    function revisarRut() {
        var v = f.rut.value.trim();
        if (!v) { rutMsg.classList.add('hidden'); f.rut.classList.remove('cf-mal'); return true; }
        var ok = rutValido(v);
        if (ok) f.rut.value = rutFormato(v);
        f.rut.classList.toggle('cf-mal', !ok);
        rutMsg.textContent = ok ? '✓ RUT válido' : 'RUT no válido: revisa el dígito verificador.';
        rutMsg.className = 'font-body text-[11px] ' + (ok ? 'text-salvia-dark' : 'text-red-600');
        // Si ya se consultó este RUT en el SII, se mantiene ese aviso (no se reemplaza por «RUT válido»).
        if (ok && siiAviso && siiAviso.k === rutLimpio(f.rut.value)) siiMsg(siiAviso.html, siiAviso.cls);
        return ok;
    }
    f.rut.addEventListener('blur', function () { if (revisarRut()) buscarSii(); });

    /* ---- Datos desde el SII al escribir el RUT (razón social, giro, dirección, comuna) ----
       Solo completa campos vacíos o que ya había completado el SII: nunca pisa lo que escribiste. */
    var siiUlt = '', siiN = 0, siiGiros = document.getElementById('cf-sii-giros'), siiDirs = document.getElementById('cf-sii-dirs');
    function siiMsg(html, cls) { rutMsg.innerHTML = html; rutMsg.className = 'font-body text-[11px] leading-snug ' + cls; }
    var siiAviso = null; // último resultado del SII: {k: rut, html, cls}
    function siiFinal(k, html, cls) { siiAviso = { k: k, html: html, cls: cls }; siiMsg(html, cls); }
    var GIRO_SUG = 'PARTICULAR';
    function siiPoner(el, v, forzar) {
        if (!el || !v) return;
        if (!forzar && el.value.trim() && el.dataset.sii !== '1') return;
        el.value = v; el.dataset.sii = '1'; el.classList.add('cf-sii');
        setTimeout(function () { el.classList.remove('cf-sii'); }, 1800);
    }
    function siiDireccion(d, forzar) {
        siiPoner(f.direccion, d.direccion, forzar);
        if (d.comuna && (forzar || !f.comuna.value || f.comuna.dataset.sii === '1')) {
            window.ezGeo.set(f, d.region || '', d.comuna); f.comuna.dataset.sii = '1';
            f.comuna.classList.add('cf-sii'); setTimeout(function () { f.comuna.classList.remove('cf-sii'); }, 1800);
        }
        siiPoner(f.ciudad, d.ciudad || d.comuna, forzar);
    }
    /* Opciones del SII (varios giros o direcciones): lista desplegable bajo el campo. */
    function siiChips(cont, lista, texto, alElegir, nombre) {
        cont.innerHTML = '';
        cont.classList.toggle('hidden', lista.length < 2); cont.classList.toggle('flex', lista.length >= 2);
        if (lista.length < 2) return;
        var sel = document.createElement('select'); sel.className = 'cfinp'; sel.setAttribute('aria-label', 'Elegir ' + nombre + ' del SII');
        lista.forEach(function (x, i) { var o = document.createElement('option'); o.value = String(i); o.textContent = texto(x); sel.appendChild(o); });
        sel.addEventListener('change', function () { alElegir(lista[parseInt(sel.value, 10)]); });
        cont.insertAdjacentHTML('beforeend', '<span class="w-full font-body text-[11px] text-cafe/55">Tiene ' + lista.length + ' ' + nombre + ' en el SII: elige el que corresponde.</span>');
        cont.appendChild(sel);
    }
    function siiLimpiar() { [siiGiros, siiDirs].forEach(function (c) { c.innerHTML = ''; c.classList.add('hidden'); c.classList.remove('flex'); }); }
    function buscarSii() {
        var v = f.rut.value.trim();
        if (!rutValido(v)) return;
        var k = rutLimpio(v);
        if (k === siiUlt) return;
        siiUlt = k;
        var n = ++siiN;
        siiMsg('<span class="cf-spin"></span> Buscando los datos en el SII…', 'text-cafe/60');
        fetch(BASE + '/api/panel/clientes?action=sii_rut&rut=' + encodeURIComponent(k), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (n !== siiN) return; // llegó una respuesta de un RUT anterior
                if (!d || !d.ok) {
                    if (d && d.no_encontrado) { siiFinal(k, '⚠ El SII no tiene datos de facturación para este RUT (por ejemplo, una persona sin inicio de actividades). Completa los datos a mano.', 'text-amber-700 font-semibold'); return; }
                    siiFinal(k, '✓ RUT válido · ' + esc((d && d.error) || 'No se pudo consultar el SII.'), 'text-cafe/60'); return;
                }
                siiPoner(f.razon_social, d.razon_social);
                var g = d.giros || [], di = d.direcciones || [];
                if (g.length) siiPoner(f.giro, g[0].glosa);
                if (di.length) siiDireccion(di[0]);
                siiChips(siiGiros, g, function (x) { return x.glosa; }, function (x) { siiPoner(f.giro, x.glosa, true); }, 'giros');
                siiChips(siiDirs, di, function (x) { return x.direccion + (x.comuna ? ', ' + x.comuna : ''); }, function (x) { siiDireccion(x, true); }, 'direcciones');
                if (!g.length) {
                    // Persona sin inicio de actividades: el SII no le registra giro, pero la factura lo exige.
                    if (!f.giro.value.trim()) { f.giro.classList.add('cf-mal'); f.giro.placeholder = 'Ej.: ' + GIRO_SUG + ' (no tiene giro en el SII)'; }
                    siiFinal(k, '✓ Datos traídos del SII · ⚠ El SII no registra giro para este RUT (no tiene inicio de actividades). La factura exige un giro: escribe uno, por ejemplo «' + GIRO_SUG + '» o su profesión.', 'text-amber-700 font-semibold');
                } else {
                    siiFinal(k, '✓ Datos traídos del SII. Revísalos antes de guardar.', 'text-salvia-dark font-semibold');
                }
            })
            .catch(function () { if (n === siiN) siiFinal(k, '✓ RUT válido · no se pudo consultar el SII ahora; completa los datos a mano.', 'text-cafe/60'); });
    }
    f.giro.addEventListener('input', function () { if (f.giro.value.trim()) f.giro.classList.remove('cf-mal'); });
    // Al terminar de escribir un RUT válido (con su dígito verificador) se consulta sin esperar a salir del campo.
    var siiT = null;
    f.rut.addEventListener('input', function () {
        clearTimeout(siiT);
        var v = f.rut.value.trim();
        if (rutValido(v) && rutLimpio(v).length >= 8) siiT = setTimeout(function () { if (revisarRut()) buscarSii(); }, 650);
    });

    // ---- Box -> tarifas ----
    function llenarTarifas(sel) {
        var b = BOXES.filter(function (x) { return x.slug === f.box_slug.value; })[0];
        var t = f.tarifa;
        t.innerHTML = '';
        if (!b) { t.innerHTML = '<option value="">— Elige primero el box —</option>'; t.disabled = true; return; }
        t.disabled = false;
        t.insertAdjacentHTML('beforeend', '<option value="">— Sin tarifa fija —</option>');
        b.tarifas.forEach(function (x) { t.insertAdjacentHTML('beforeend', '<option value="' + esc(x.key) + '">' + esc(x.key) + ' — ' + clp(x.precio) + '</option>'); });
        t.value = sel || '';
        if (sel && t.value !== sel) { t.insertAdjacentHTML('beforeend', '<option value="' + esc(sel) + '">' + esc(sel) + ' (ya no está en el sitio)</option>'); t.value = sel; }
    }
    f.box_slug.addEventListener('change', function () { llenarTarifas(''); });

    // ---- Copiar desde profesional ----
    document.getElementById('cf-copiar-sel').addEventListener('change', function (e) {
        var p = PROFES.filter(function (x) { return String(x.id) === e.target.value; })[0];
        if (!p) return;
        if (!f.razon_social.value.trim()) f.razon_social.value = p.nombre;
        if (!f.rut.value.trim() && p.rut) { f.rut.value = p.rut; revisarRut(); }
        var chk = f.querySelector('input[name="profesional_ids"][value="' + p.id + '"]'); if (chk) chk.checked = true;
        if (!f.giro.value.trim()) f.giro.focus(); else f.direccion.focus();
    });

    function cerrar() {
        modal.classList.add('hidden'); modal.classList.remove('flex'); document.body.style.overflow = '';
        if (foco && foco.focus) foco.focus();
    }
    modal.querySelectorAll('[data-cf-cerrar]').forEach(function (b) { b.addEventListener('click', cerrar); });
    modal.addEventListener('mousedown', function (e) { if (e.target === modal) cerrar(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.classList.contains('hidden')) cerrar(); });

    function abrir(d, opts) {
        opciones = opts || {}; foco = document.activeElement;
        f.reset(); err.classList.add('hidden'); ayuda.classList.remove('hidden');
        f.querySelectorAll('.cf-mal').forEach(function (x) { x.classList.remove('cf-mal'); });
        rutMsg.classList.add('hidden');
        siiLimpiar(); siiN++; siiAviso = null; f.giro.placeholder = 'Actividad que aparece en el SII';
        f.querySelectorAll('[data-sii]').forEach(function (x) { delete x.dataset.sii; });
        var nuevo = !d || !d.id;
        document.getElementById('cf-titulo').textContent = nuevo ? 'Nuevo cliente' : 'Editar cliente';
        btn.textContent = nuevo ? 'Crear cliente' : 'Guardar cambios';
        document.getElementById('cf-copiar').classList.toggle('hidden', !nuevo);
        document.getElementById('cf-copiar-sel').value = '';
        d = d || {};
        ['id', 'rut', 'razon_social', 'nombre_fantasia', 'giro', 'direccion', 'ciudad', 'email', 'telefono', 'contacto', 'notas'].forEach(function (k) { if (f[k]) f[k].value = d[k] || ''; });
        var ids = (d.prof_ids && d.prof_ids.length ? d.prof_ids : (d.profesional_id ? [d.profesional_id] : [])).map(String);
        f.querySelectorAll('input[name="profesional_ids"]').forEach(function (c) { c.checked = ids.indexOf(c.value) !== -1; });
        f.medio_pago.value = d.medio_pago || 'transferencia';
        window.ezGeo.setSelect(f.box_slug, d.box_slug || '');
        llenarTarifas(d.tarifa || '');
        window.ezGeo.set(f, d.region || '', d.comuna || '');
        f.activo.checked = nuevo ? true : String(d.activo) === '1';
        document.getElementById('cf-activo-wrap').classList.toggle('hidden', nuevo);
        if (opciones.prefill) Object.keys(opciones.prefill).forEach(function (k) { if (f[k] && !f[k].value) f[k].value = opciones.prefill[k]; });
        siiUlt = rutLimpio(f.rut.value); // el RUT con que se abre no se vuelve a consultar (al editar)
        modal.classList.remove('hidden'); modal.classList.add('flex'); document.body.style.overflow = 'hidden';
        setTimeout(function () { (nuevo ? f.rut : f.razon_social).focus(); }, 30);
    }

    f.addEventListener('submit', function (e) {
        e.preventDefault();
        err.classList.add('hidden'); ayuda.classList.remove('hidden');
        f.querySelectorAll('.cf-mal').forEach(function (x) { x.classList.remove('cf-mal'); });
        var req = { rut: 'RUT', razon_social: 'Razón social', giro: 'Giro', direccion: 'Dirección', comuna: 'Comuna' }, faltan = [], primero = null;
        Object.keys(req).forEach(function (k) { if (!String(f[k].value || '').trim()) { faltan.push(req[k]); f[k].classList.add('cf-mal'); primero = primero || f[k]; } });
        function falla(m, el) { err.textContent = m; err.classList.remove('hidden'); ayuda.classList.add('hidden'); if (el) el.focus(); }
        if (faltan.length) return falla('Para poder facturarle, completa: ' + faltan.join(', ') + '.', primero);
        if (!revisarRut()) return falla('El RUT no es válido: revisa el dígito verificador.', f.rut);
        if (f.email.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email.value.trim())) { f.email.classList.add('cf-mal'); return falla('El correo para facturas no es válido.', f.email); }
        var d = { action: f.id.value ? 'actualizar' : 'crear' };
        new FormData(f).forEach(function (v, k) { d[k] = typeof v === 'string' ? v.trim() : v; });
        d.comuna = f.comuna.value; d.tarifa = f.tarifa.disabled ? '' : f.tarifa.value;
        d.profesional_ids = Array.prototype.map.call(f.querySelectorAll('input[name="profesional_ids"]:checked'), function (c) { return parseInt(c.value, 10); });
        delete d.profesional_id;
        d.activo = f.activo.checked ? 1 : 0;
        btn.disabled = true; var txt = btn.textContent; btn.textContent = 'Guardando…';
        fetch(BASE + '/api/panel/clientes', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(d) })
            .then(function (r) { return r.text().then(function (t) { var j = {}; try { j = JSON.parse(t); } catch (x) { j = { error: 'El servidor respondió algo inesperado. Recarga la página e intenta de nuevo.' }; } return { ok: r.ok, d: j }; }); })
            .then(function (x) {
                btn.disabled = false; btn.textContent = txt;
                if (!x.ok || !x.d.ok) return falla(x.d.error || 'No se pudo guardar.');
                var esNuevo = d.action === 'crear';
                cerrar();
                if (typeof opciones.onGuardado === 'function') return opciones.onGuardado(x.d.id, esNuevo);
                if (esNuevo) location.href = BASE + '/admin/clientes/' + x.d.id; else location.reload();
            })
            .catch(function () { btn.disabled = false; btn.textContent = txt; falla('Sin conexión. Intenta de nuevo.'); });
    });

    return { abrir: abrir };
})();
</script>
