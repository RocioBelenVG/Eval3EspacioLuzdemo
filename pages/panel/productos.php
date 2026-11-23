<?php
/** Panel · Productos / servicios por empresa (solo admin). */
declare(strict_types=1);

requireAdminPage();
require_once APP_ROOT . '/lib/productos.php';
require_once APP_ROOT . '/lib/empresas.php';
require_once APP_ROOT . '/lib/panel.php';

$empresas = empresasLista(true);
$socias = profesionalesSocias();
$filtroEmpresa = (int) ($_GET['empresa'] ?? 0);
$filtroProf = (int) ($_GET['profesional'] ?? 0);
$productos = productosLista($filtroEmpresa, true);
if ($filtroProf > 0) {
    $productos = array_values(array_filter($productos, fn($p) => (int) ($p['profesional_id'] ?? 0) === $filtroProf));
}
$sinAsignar = count(array_filter(productosLista(0, true), fn($p) => empty($p['profesional_id'])));

$panelTitle = 'Productos';
$panelActive = 'productos';
include APP_ROOT . '/partials/panel/top.php';
?>
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <form method="get" action="<?= e(base_path('/admin/productos')) ?>" class="flex items-center gap-2">
        <select name="empresa" onchange="this.form.submit()" class="rounded-full border border-cafe/20 bg-crema-light px-4 py-2.5 font-body text-sm text-cafe outline-none focus:border-terracota">
            <option value="0">Todas las empresas</option>
            <?php foreach ($empresas as $emp): ?>
                <option value="<?= (int) $emp['id'] ?>" <?= $filtroEmpresa === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['razon_social']) ?> (<?= $emp['tipo_documento'] === 'factura' ? 'Facturas' : 'Boletas' ?>)</option>
            <?php endforeach; ?>
        </select>
        <select name="profesional" onchange="this.form.submit()" class="rounded-full border border-cafe/20 bg-crema-light px-4 py-2.5 font-body text-sm text-cafe outline-none focus:border-terracota">
            <option value="0">Todas las profesionales</option>
            <?php foreach ($socias as $sid => $snom): ?>
                <option value="<?= (int) $sid ?>" <?= $filtroProf === (int) $sid ? 'selected' : '' ?>><?= e($snom) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <button type="button" id="prod-nuevo-btn" class="inline-flex items-center gap-2 rounded-full bg-terracota px-5 py-2.5 font-body text-sm font-semibold text-crema-light shadow-card hover:bg-terracota-dark">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg> Nuevo producto
    </button>
</div>

<?php if ($sinAsignar > 0): ?>
    <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 font-body text-sm text-amber-900"><strong><?= (int) $sinAsignar ?> producto<?= $sinAsignar === 1 ? '' : 's' ?> sin profesional.</strong> Edítalo<?= $sinAsignar === 1 ? '' : 's' ?> y elige si es de <?= e(sociasTexto()) ?>: así cada una identifica sus productos al emitir.</p>
<?php endif; ?>
<?php if (!$empresas): ?>
    <div class="rounded-2xl border border-dashed border-cafe/20 bg-crema-light p-8 text-center font-body text-sm text-cafe/55">Primero crea una empresa en <a href="<?= e(base_path('/admin/empresas')) ?>" class="text-terracota-dark">Empresas</a>.</div>
<?php else: ?>
<div class="ez-tabla-card">
    <div class="overflow-x-auto">
        <table class="ez-tabla">
            <thead><tr>
                <th class="px-5 py-3">Producto / servicio</th><th class="px-5 py-3">Empresa</th><th class="px-5 py-3">Profesional</th><th class="px-5 py-3 text-right">Precio neto</th><th class="px-5 py-3 text-center">IVA</th><th class="px-5 py-3 text-center">Estado</th><th class="px-5 py-3"></th>
            </tr></thead>
            <tbody class="font-body text-sm text-cafe/80">
                <?php if (!$productos): ?>
                    <tr><td colspan="7" class="px-5 py-12 text-center text-cafe/45">Aún no hay productos<?= $filtroEmpresa ? ' para esta empresa' : '' ?>. Crea el primero con «Nuevo producto».</td></tr>
                <?php else: foreach ($productos as $p): ?>
                    <tr class="<?= (int) $p['activo'] === 1 ? '' : 'opacity-55 acc-off' ?>">
                        <td class="px-5 py-3 font-semibold text-cafe"><?= e($p['nombre']) ?><?php if (!empty($p['descripcion'])): ?><span class="block font-body text-xs font-normal text-cafe/50"><?= e($p['descripcion']) ?></span><?php endif; ?></td>
                        <td class="px-5 py-3"><?php if ($p['empresa_id'] === null): ?><span class="rounded-full bg-terracota/12 px-2.5 py-0.5 text-xs font-semibold text-terracota-dark" title="Aparece al emitir boletas de todas las empresas">Ambas empresas</span> <span class="text-cafe/40">(Boletas)</span><?php else: ?><?= e($p['empresa_nombre']) ?> <span class="text-cafe/40">(<?= $p['empresa_tipo'] === 'factura' ? 'Factura' : 'Boleta' ?>)</span><?php endif; ?></td>
                        <td class="px-5 py-3 whitespace-nowrap"><?= !empty($p['profesional_nombre']) ? '<span class="rounded-full bg-salvia/15 px-2.5 py-0.5 text-xs font-semibold text-salvia-dark">' . e($p['profesional_nombre']) . '</span>' : '<span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">Sin asignar</span>' ?></td>
                        <td class="px-5 py-3 text-right font-semibold text-cafe"><?= e(clp((int) $p['precio_neto'])) ?></td>
                        <td class="px-5 py-3 text-center"><?= (int) $p['afecto'] === 1 ? '<span class="rounded-full bg-terracota/12 px-2 py-0.5 text-xs font-semibold text-terracota-dark">Afecto</span>' : '<span class="rounded-full bg-cafe/10 px-2 py-0.5 text-xs font-semibold text-cafe/60">Exento</span>' ?></td>
                        <td class="px-5 py-3 text-center"><?= (int) $p['activo'] === 1 ? '<span class="rounded-full bg-salvia/20 px-2 py-0.5 text-xs font-semibold text-salvia-dark">Activo</span>' : '<span class="rounded-full bg-cafe/10 px-2 py-0.5 text-xs font-semibold text-cafe/60">Inactivo</span>' ?></td>
                        <td class="px-5 py-3 text-right whitespace-nowrap">
                            <button type="button" class="prod-edit font-body text-xs font-semibold text-terracota-dark hover:text-terracota" data-json='<?= e(json_encode($p, JSON_UNESCAPED_UNICODE)) ?>'>Editar</button>
                            <button type="button" class="prod-del ml-3 font-body text-xs font-semibold text-red-500 hover:text-red-700" data-id="<?= (int) $p['id'] ?>">Eliminar</button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Modal producto -->
<div id="prod-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-cafe/50 p-4 backdrop-blur-sm">
    <div class="w-full max-w-lg rounded-2xl bg-crema-light p-6 shadow-warm">
        <h3 id="prod-title" class="mb-3 font-heading text-xl font-semibold text-cafe">Nuevo producto</h3>
        <form id="prod-form" class="grid grid-cols-2 gap-3">
            <input type="hidden" name="id">
            <label class="col-span-2 flex flex-col gap-1"><span class="plbl">Empresa *</span>
                <select name="empresa_id" class="pinp">
                    <?php require_once APP_ROOT . '/lib/dte_integra.php'; foreach ($empresas as $emp): ?><option value="<?= (int) $emp['id'] ?>" data-solo-exento="<?= dteEmpresaSoloExenta($emp) ? '1' : '0' ?>" <?= $filtroEmpresa === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['razon_social']) ?> (<?= $emp['tipo_documento'] === 'factura' ? 'Facturas' : 'Boletas' ?>)</option><?php endforeach; ?>
                    <option value="ambas" data-solo-exento="<?= productoForzarExento(null) ? '1' : '0' ?>">Ambas empresas (Boletas)</option>
                </select>
                <span id="prod-ambas-ayuda" class="hidden font-body text-[11px] text-cafe/55">Aparecerá al emitir boletas de todas las empresas (no en facturas a clientes).</span>
            </label>
            <label class="col-span-2 flex flex-col gap-1"><span class="plbl">Profesional *</span>
                <select name="profesional_id" class="pinp" required>
                    <option value="">— ¿De quién es este producto? —</option>
                    <?php foreach ($socias as $sid => $snom): ?><option value="<?= (int) $sid ?>"><?= e($snom) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="col-span-2 flex flex-col gap-1"><span class="plbl">Nombre del producto / servicio *</span><input name="nombre" required class="pinp" placeholder="Ej: Sesión de fonoaudiología"></label>
            <label class="col-span-2 flex flex-col gap-1"><span class="plbl">Descripción (opcional)</span><input name="descripcion" class="pinp"></label>
            <label class="flex flex-col gap-1"><span class="plbl">Precio neto (CLP)</span><input name="precio_neto" type="number" min="0" step="1" value="0" class="pinp"></label>
            <label class="flex flex-col gap-1"><span class="plbl">Afecto a IVA</span>
                <select name="afecto" class="pinp"><option value="0">Exento (prestación de salud)</option><option value="1">Afecto (+19% IVA)</option></select>
            </label>
            <label class="col-span-2 flex items-center gap-2"><input type="checkbox" name="activo" class="h-4 w-4 accent-[#D97757]" checked><span class="font-body text-xs text-cafe/70">Producto activo (aparece al emitir).</span></label>
            <p id="prod-err" class="col-span-2 hidden font-body text-sm font-medium text-red-600"></p>
            <div class="col-span-2 flex justify-end gap-2"><button type="button" id="prod-cancel" class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota">Cancelar</button><button type="submit" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Guardar</button></div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.pinp').forEach(function (el) { el.className = 'pinp rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota'; });
    document.querySelectorAll('.plbl').forEach(function (el) { el.className = 'plbl font-body text-xs font-semibold uppercase tracking-wide text-cafe/60'; });
    var P = window.__PANEL__ || {}, modal = document.getElementById('prod-modal'), f = document.getElementById('prod-form');
    // Empresa que solo emite documentos exentos (ej. Espacio Luz Spa: 34/41): el producto queda Exento.
    function soloExento() {
        var o = f.empresa_id && f.empresa_id.options[f.empresa_id.selectedIndex], si = !!o && o.getAttribute('data-solo-exento') === '1';
        if (si) f.afecto.value = '0';
        f.afecto.disabled = si;
        f.afecto.title = si ? 'Esta empresa está autorizada ante el SII solo para documentos exentos.' : '';
        document.getElementById('prod-ambas-ayuda').classList.toggle('hidden', !f.empresa_id || f.empresa_id.value !== 'ambas');
    }
    if (f.empresa_id) f.empresa_id.addEventListener('change', soloExento);
    function show() { modal.classList.remove('hidden'); modal.classList.add('flex'); }
    function hide() { modal.classList.add('hidden'); modal.classList.remove('flex'); }
    function post(d) { return fetch(P.base + '/api/panel/productos', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf }, body: JSON.stringify(d) }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, d: j }; }); }); }

    var nb = document.getElementById('prod-nuevo-btn');
    if (nb) nb.addEventListener('click', function () {
        f.reset(); f.id.value = ''; f.activo.checked = true; if (f.empresa_id) f.empresa_id.disabled = false;
        soloExento(); document.getElementById('prod-title').textContent = 'Nuevo producto'; document.getElementById('prod-err').classList.add('hidden'); show();
    });
    document.querySelectorAll('.prod-edit').forEach(function (b) { b.addEventListener('click', function () {
        var d = JSON.parse(b.dataset.json); f.reset();
        f.id.value = d.id; f.empresa_id.value = d.empresa_id === null ? 'ambas' : String(d.empresa_id); f.empresa_id.disabled = false;
        f.profesional_id.value = d.profesional_id ? String(d.profesional_id) : '';
        f.nombre.value = d.nombre || ''; f.descripcion.value = d.descripcion || ''; f.precio_neto.value = d.precio_neto || 0;
        f.afecto.value = String(d.afecto) === '1' ? '1' : '0'; f.activo.checked = String(d.activo) === '1'; soloExento();
        document.getElementById('prod-title').textContent = 'Editar producto'; document.getElementById('prod-err').classList.add('hidden'); show();
    }); });
    document.querySelectorAll('.prod-del').forEach(function (b) { b.addEventListener('click', function () {
        ezConfirm('¿Eliminar este producto?', { title: 'Eliminar producto', okText: 'Eliminar', danger: true }).then(function (ok) {
            if (!ok) return;
            post({ action: 'eliminar', id: b.dataset.id }).then(function () { location.reload(); });
        });
    }); });
    document.getElementById('prod-cancel').addEventListener('click', hide);
    modal.addEventListener('click', function (e) { if (e.target === modal) hide(); });
    f.addEventListener('submit', function (e) { e.preventDefault(); var err = document.getElementById('prod-err'); err.classList.add('hidden');
        var d = { action: f.id.value ? 'actualizar' : 'crear' }; new FormData(f).forEach(function (v, k) { d[k] = v; }); d.activo = f.activo.checked ? 1 : 0;
        if (!f.profesional_id.value) { err.textContent = 'Elige de qué profesional es el producto.'; err.classList.remove('hidden'); return; }
        if (f.empresa_id.disabled) d.empresa_id = f.empresa_id.value;
        post(d).then(function (x) { if (!x.ok) { err.textContent = x.d.error || 'Error'; err.classList.remove('hidden'); return; } location.reload(); });
    });
});
</script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
