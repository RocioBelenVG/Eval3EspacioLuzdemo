<?php
/**
 * Panel · Emitir factura a un cliente (solo admin).
 * Emisor fijo: la empresa autorizada (Espacio Luz). Receptor: SIEMPRE la ficha del cliente.
 * El detalle se arma con: horas del Calendario de Boxes del profesional vinculado,
 * tarifas de los boxes, servicios guardados o conceptos libres.
 * ?cliente=ID preselecciona el cliente; &mes=YYYY-MM además agrega sus horas de ese mes.
 */
declare(strict_types=1);

requireAdminPage();
require_once APP_ROOT . '/lib/clientes.php';
require_once APP_ROOT . '/lib/boletas.php';
require_once APP_ROOT . '/lib/productos.php';

$emp = facturaEmpresa();
$boxes = boxesFacturables();

$clientesJs = [];
foreach (clientesLista() as $c) {
    if ((int) $c['activo'] !== 1) {
        continue;
    }
    $clientesJs[] = [
        'id' => (int) $c['id'], 'razon_social' => $c['razon_social'], 'nombre_fantasia' => $c['nombre_fantasia'] ?? '',
        'rut' => $c['rut'], 'giro' => $c['giro'], 'direccion' => $c['direccion'], 'comuna' => $c['comuna'],
        'region' => $c['region'] ?? '', 'ciudad' => $c['ciudad'] ?? '', 'email' => $c['email'] ?? '', 'telefono' => $c['telefono'] ?? '',
        'contacto' => $c['contacto'] ?? '', 'notas' => $c['notas'] ?? '', 'activo' => (int) $c['activo'],
        'medio_pago' => $c['medio_pago'], 'prof_ids' => array_values(array_filter(array_map('intval', explode(',', (string) ($c['prof_ids_csv'] ?? ''))))),
        'profesionales_nombres' => $c['profesionales_nombres'] ?? '', 'box_slug' => $c['box_slug'] ?? '', 'tarifa' => $c['tarifa'] ?? '',
        'faltan' => clienteFaltantes($c),
        'pend' => !empty($c['prof_ids_csv']) ? horasSinFacturarPorMes(explode(',', (string) $c['prof_ids_csv'])) : [],
    ];
}
$preCli = (int) ($_GET['cliente'] ?? 0);
$preMes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_GET['mes'] ?? '')) ? (string) $_GET['mes'] : '';
$productos = $emp ? (productosPorEmpresa(false)[(int) $emp['id']] ?? []) : []; // los de «Ambas empresas» son para boletas

// ¿La factura irá al SII o quedará interna? (certificado + CAF de factura)
$motivo33 = 'No está configurada la empresa emisora.';
$soloExenta = false;
if ($emp) {
    try {
        require_once APP_ROOT . '/lib/dte_integra.php';
        // Espacio Luz está certificada para factura EXENTA (34): todas sus facturas van sin IVA.
        $soloExenta = dteFacturaSoloExenta($emp);
        $motivo33 = dteMotivoNoEmite((int) $emp['id'], $soloExenta ? 34 : 33);
    } catch (Throwable $e) {
        $motivo33 = 'No se pudo revisar el certificado y los folios.';
    }
}
$alSii = $emp && $motivo33 === '';

$panelTitle = 'Emitir factura';
$panelActive = 'clientes';
include APP_ROOT . '/partials/panel/top.php';

$inp = 'w-full min-w-0 rounded-lg border border-cafe/20 bg-white px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota focus:ring-2 focus:ring-terracota/15';
?>
<?php if (!$emp): ?>
    <div class="rounded-2xl border border-dashed border-cafe/20 bg-crema-light p-8 text-center font-body text-sm text-cafe/60">
        No se encontró la empresa que emite las facturas (Espacio Luz). Revísala en <a href="<?= e(base_path('/admin/empresas')) ?>" class="font-semibold text-terracota-dark">Empresas</a>.
    </div>
<?php else: ?>
<style>
    .fx-lbl { font-family: var(--font-body); font-size: 11px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: rgba(92,64,51,.5); }
    .fx-tool { display: inline-flex; align-items: center; gap: .4rem; border-radius: 9999px; border: 1.5px solid rgba(92,64,51,.15); background: #fff; padding: .45rem .85rem; font-family: var(--font-body); font-size: .8rem; font-weight: 700; color: #5C4033; transition: all .15s ease; }
    .fx-tool:hover:not(:disabled) { border-color: #D97757; color: #B85C3E; }
    .fx-tool[aria-expanded="true"] { background: #5C4033; border-color: #5C4033; color: #FBF9F4; }
    .fx-tool:disabled { opacity: .45; cursor: not-allowed; }
    .fx-tool svg { width: 15px; height: 15px; }
    .fx-chip { display: inline-block; border-radius: 9999px; padding: .1rem .5rem; font-family: var(--font-body); font-size: 10px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
    .fx-in { width: 100%; min-width: 0; height: 2.4rem; border-radius: .55rem; border: 1px solid rgba(92,64,51,.15); background: #fff; padding: .45rem .65rem; font-family: var(--font-body); font-size: .875rem; color: #5C4033; outline: none; }
    .fx-in:focus { border-color: #D97757; box-shadow: 0 0 0 3px rgba(217,119,87,.12); }
    .fx-in[readonly] { background: rgba(245,241,232,.6); }
    .fx-mini { font-family: var(--font-body); font-size: 10px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: rgba(92,64,51,.45); }
    .fx-num { font-variant-numeric: tabular-nums; }
</style>

<form id="fx-form" class="mx-auto max-w-6xl" novalidate>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <!-- Columna izquierda: emisor, cliente, pago -->
        <div class="min-w-0 space-y-5 lg:col-span-1">
            <!-- Emisor fijo -->
            <section class="rounded-2xl border border-cafe/10 bg-cafe p-5 text-crema-light shadow-card">
                <p class="font-body text-[11px] font-bold uppercase tracking-[0.14em] text-terracota-light">Emisor</p>
                <h2 class="mt-1 font-heading text-lg font-semibold leading-snug"><?= e($emp['razon_social']) ?></h2>
                <p class="font-body text-xs text-crema/70">RUT <?= e($emp['rut'] ?: '—') ?> · <?= e($emp['giro'] ?: '') ?></p>
                <p class="mt-2 font-body text-xs text-crema/85">Emiten: <strong class="text-crema"><?= e(sociasTexto()) ?></strong> (socias; no pagan arriendo de box).</p>
                <?php if ($alSii): ?>
                    <p class="mt-3 rounded-lg bg-emerald-500/15 px-3 py-2 font-body text-xs text-emerald-100">Certificado y folios cargados: la factura se envía al SII.</p>
                <?php else: ?>
                    <p class="mt-3 rounded-lg bg-crema/10 px-3 py-2 font-body text-[11.5px] leading-relaxed text-crema/80">Se emitirá como <strong class="text-crema">documento interno</strong> (pendiente de timbrar) hasta cargar el certificado y los folios (CAF) de factura en <a href="<?= e(base_path('/admin/empresas')) ?>" class="font-semibold text-terracota-light underline">Empresas y SII</a>.</p>
                <?php endif; ?>
            </section>

            <!-- Cliente -->
            <section class="rounded-2xl border border-cafe/10 bg-white p-5 shadow-card">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-heading text-lg font-semibold text-cafe">Cliente</h2>
                    <button type="button" id="fx-cli-nuevo" class="font-body text-xs font-bold text-terracota-dark hover:underline">+ Nuevo cliente</button>
                </div>
                <?php if (!$clientesJs): ?>
                    <p class="mt-3 rounded-xl border border-dashed border-cafe/20 px-4 py-6 text-center font-body text-sm text-cafe/55">Aún no hay clientes. Crea el primero con «+ Nuevo cliente».</p>
                <?php endif; ?>
                <input id="fx-cli-buscar" type="search" autocomplete="off" placeholder="Buscar por nombre o RUT…" class="mt-3 <?= $inp ?> <?= $clientesJs ? '' : 'hidden' ?>">
                <select id="fx-cli" class="mt-2 <?= $inp ?> <?= $clientesJs ? '' : 'hidden' ?>"></select>

                <div id="fx-cli-card" class="mt-3 hidden">
                    <div id="fx-cli-falta" class="mb-2 hidden rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 font-body text-xs leading-relaxed text-amber-900"></div>
                    <div class="rounded-xl border border-cafe/10 bg-crema/40 p-3.5">
                        <p class="fx-lbl">Se factura a</p>
                        <p id="fx-cli-nombre" class="mt-0.5 font-heading text-base font-semibold leading-snug text-cafe"></p>
                        <dl id="fx-cli-datos" class="mt-2 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 border-t border-cafe/10 pt-2 font-body text-xs"></dl>
                    </div>
                    <button type="button" id="fx-cli-editar" class="mt-2 font-body text-[11.5px] font-bold text-terracota-dark hover:underline">Editar datos del cliente</button>
                </div>
            </section>

            <!-- Pago -->
            <section class="space-y-3 rounded-2xl border border-cafe/10 bg-white p-5 shadow-card">
                <label class="flex flex-col gap-1"><span class="fx-lbl">Medio de pago</span>
                    <select name="medio_pago" class="<?= $inp ?>"><?php foreach (CLIENTE_MEDIOS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
                <div class="flex flex-col gap-1.5"><span class="fx-lbl">Pago de la factura</span>
                    <label class="flex flex-wrap items-center gap-2 rounded-lg border border-cafe/12 bg-crema/40 px-3 py-2 font-body text-sm text-cafe">
                        <input type="radio" name="fx-pago" value="pendiente" class="accent-[#D97757]" checked> Queda por cobrar · vence el
                        <input type="date" id="fx-vence" value="<?= e(date('Y-m-d', strtotime('+15 days'))) ?>" class="rounded-md border border-cafe/20 bg-white px-2 py-1 font-body text-sm text-cafe outline-none focus:border-terracota"></label>
                    <label class="flex items-center gap-2 rounded-lg border border-cafe/12 bg-crema/40 px-3 py-2 font-body text-sm text-cafe">
                        <input type="radio" name="fx-pago" value="pagada" class="accent-[#D97757]"> Ya está pagada (se registra el pago hoy)</label>
                </div>
                <label class="flex flex-col gap-1"><span class="fx-lbl">Correo para enviar la factura</span>
                    <input name="receptor_email" type="email" class="<?= $inp ?>" placeholder="facturas@correo.cl"></label>
                <label class="flex flex-col gap-1"><span class="fx-lbl">Observaciones</span>
                    <textarea name="glosa" rows="2" maxlength="300" class="resize-none <?= $inp ?>" placeholder="Ej.: Arriendo de boxes · noviembre 2026"></textarea></label>
            </section>
        </div>

        <!-- Columna derecha: detalle -->
        <section class="min-w-0 rounded-2xl border border-cafe/10 bg-white p-5 shadow-card lg:col-span-2 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-heading text-lg font-semibold text-cafe">Detalle</h2>
                    <p class="font-body text-xs text-cafe/50">Agrega lo que se cobra: se puede combinar y editar todo.</p>
                </div>
                <?php if ($soloExenta): ?>
                    <span class="inline-flex items-center gap-2 rounded-full bg-salvia/15 px-3 py-1.5 font-body text-xs font-semibold text-salvia-dark" title="La empresa está certificada ante el SII para factura exenta (34)">Factura exenta (34) · sin IVA</span>
                    <input type="checkbox" id="fx-iva-incl" class="hidden">
                <?php else: ?>
                <label class="inline-flex cursor-pointer items-center gap-2 rounded-full bg-crema/70 px-3 py-1.5 font-body text-xs font-semibold text-cafe">
                    <input type="checkbox" id="fx-iva-incl" class="h-4 w-4 accent-[#D97757]" checked> Precios con IVA incluido
                </label>
                <?php endif; ?>
            </div>

            <!-- Herramientas -->
            <div class="mt-4 flex flex-wrap gap-2" role="toolbar" aria-label="Agregar al detalle">
                <button type="button" class="fx-tool" data-panel="horas" aria-expanded="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4M8 14h3M8 17h6"/></svg> Horas del calendario
                </button>
                <button type="button" class="fx-tool" data-panel="tarifa" aria-expanded="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V8l7-5 7 5v13"/><path d="M10 21v-6h4v6"/></svg> Tarifa de box
                </button>
                <?php if ($productos): ?>
                <button type="button" class="fx-tool" data-panel="servicio" aria-expanded="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7l-8-4-8 4 8 4 8-4z"/><path d="M4 7v10l8 4 8-4V7"/></svg> Servicio guardado
                </button>
                <?php endif; ?>
                <button type="button" class="fx-tool" id="fx-libre">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg> Concepto libre
                </button>
            </div>

            <!-- Panel: horas del calendario -->
            <div data-fx-panel="horas" class="mt-3 hidden rounded-xl border border-cafe/10 bg-crema/40 p-4">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="min-w-0">
                        <p class="fx-lbl">Calendario de Boxes</p>
                        <p id="fx-h-prof" class="font-body text-sm text-cafe"></p>
                    </div>
                    <label class="flex items-center gap-2 font-body text-xs font-semibold text-cafe/70">Mes
                        <input type="month" id="fx-h-mes" class="rounded-lg border border-cafe/20 bg-white px-2.5 py-1.5 font-body text-sm text-cafe outline-none focus:border-terracota"></label>
                </div>
                <div id="fx-h-pend" class="mt-2 flex flex-wrap gap-1.5"></div>
                <div id="fx-h-alcance" class="mt-3 hidden flex-wrap items-center gap-2 font-body text-xs text-cafe/70">
                    <span class="font-semibold">Este mes aún no termina:</span>
                    <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-cafe/15 bg-white px-2.5 py-1"><input type="radio" name="fx-alcance" value="hoy" class="accent-[#D97757]" checked> Solo hasta hoy</label>
                    <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-cafe/15 bg-white px-2.5 py-1"><input type="radio" name="fx-alcance" value="mes" class="accent-[#D97757]"> Mes completo (incluye horas agendadas)</label>
                </div>
                <div id="fx-h-lista" class="mt-3 space-y-2"></div>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                    <p id="fx-h-nota" class="font-body text-[11.5px] text-cafe/55"></p>
                    <button type="button" id="fx-h-agregar" class="rounded-full bg-cafe px-4 py-2 font-body text-xs font-bold text-crema-light hover:bg-cafe-light disabled:opacity-40">Agregar al detalle</button>
                </div>
            </div>

            <!-- Panel: tarifa de box -->
            <div data-fx-panel="tarifa" class="mt-3 hidden rounded-xl border border-cafe/10 bg-crema/40 p-4">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_1.6fr_5rem_auto] sm:items-end">
                    <label class="flex flex-col gap-1"><span class="fx-lbl">Box</span><select id="fx-t-box" class="<?= $inp ?>"></select></label>
                    <label class="flex flex-col gap-1"><span class="fx-lbl">Tarifa</span><select id="fx-t-tarifa" class="<?= $inp ?>"></select></label>
                    <label class="flex flex-col gap-1"><span class="fx-lbl">Cant.</span><input id="fx-t-cant" type="number" min="1" value="1" class="<?= $inp ?> text-center"></label>
                    <button type="button" id="fx-t-agregar" class="rounded-full bg-cafe px-4 py-2 font-body text-xs font-bold text-crema-light hover:bg-cafe-light">Agregar</button>
                </div>
                <label id="fx-t-gc-wrap" class="mt-2 hidden items-center gap-2 font-body text-xs text-cafe/75"><input type="checkbox" id="fx-t-gc" class="h-4 w-4 accent-[#D97757]" checked> <span id="fx-t-gc-txt"></span></label>
                <div id="fx-t-plan-wrap" class="mt-2 hidden flex-wrap items-center gap-3 rounded-lg bg-white px-3 py-2 font-body text-xs text-cafe/75">
                    <label class="flex items-center gap-2 font-semibold">Mes del plan <input type="month" id="fx-t-mes" value="<?= e(date('Y-m')) ?>" class="rounded-md border border-cafe/20 bg-white px-2 py-1 text-sm text-cafe outline-none focus:border-terracota"></label>
                    <label class="flex items-center gap-2"><input type="checkbox" id="fx-t-plan" class="h-4 w-4 accent-[#D97757]" checked> Incluir en el plan las horas de este box en ese mes (no se cobran aparte)</label>
                </div>
            </div>

            <!-- Panel: servicio guardado -->
            <?php if ($productos): ?>
            <div data-fx-panel="servicio" class="mt-3 hidden rounded-xl border border-cafe/10 bg-crema/40 p-4">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_5rem_auto] sm:items-end">
                    <label class="flex flex-col gap-1"><span class="fx-lbl">Servicio</span><select id="fx-s-prod" class="<?= $inp ?>"></select></label>
                    <label class="flex flex-col gap-1"><span class="fx-lbl">Cant.</span><input id="fx-s-cant" type="number" min="1" value="1" class="<?= $inp ?> text-center"></label>
                    <button type="button" id="fx-s-agregar" class="rounded-full bg-cafe px-4 py-2 font-body text-xs font-bold text-crema-light hover:bg-cafe-light">Agregar</button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Líneas -->
            <div class="mt-5">
                <div id="fx-lineas" class="space-y-3"></div>
                <div id="fx-vacio" class="rounded-xl border border-dashed border-cafe/20 px-4 py-8 text-center font-body text-sm text-cafe/50">Elige el cliente y agrega lo que se cobra con los botones de arriba.</div>
            </div>

            <!-- Totales -->
            <div class="mt-5 flex flex-col-reverse gap-4 border-t border-cafe/10 pt-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="space-y-1">
                    <span id="fx-tipo" class="fx-chip bg-terracota/15 text-terracota-dark">Factura electrónica</span>
                    <p id="fx-iva-nota" class="max-w-sm font-body text-[11px] leading-relaxed text-cafe/50"></p>
                </div>
                <div class="w-full space-y-1.5 font-body text-sm sm:w-64">
                    <div class="flex justify-between text-cafe/60"><span>Neto</span><span id="fx-neto" class="fx-num">$0</span></div>
                    <div class="flex justify-between text-cafe/60"><span>IVA 19%</span><span id="fx-iva" class="fx-num">$0</span></div>
                    <div class="flex justify-between text-cafe/60"><span>Exento</span><span id="fx-exento" class="fx-num">$0</span></div>
                    <div class="mt-1 flex justify-between rounded-lg bg-cafe px-3 py-2 font-heading text-lg font-semibold text-crema-light"><span>Total</span><span id="fx-total" class="fx-num">$0</span></div>
                </div>
            </div>

            <p id="fx-err" class="mt-4 hidden rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 font-body text-sm font-medium text-red-700"></p>
            <div class="mt-4 flex items-center justify-end gap-3">
                <a href="<?= e(base_path('/admin/clientes')) ?>" class="font-body text-sm font-semibold text-cafe/60 hover:text-terracota">Cancelar</a>
                <button type="submit" id="fx-emitir" class="rounded-full bg-terracota px-6 py-2.5 font-body text-sm font-semibold text-crema-light shadow-card transition-colors hover:bg-terracota-dark disabled:opacity-50">Emitir factura</button>
            </div>
        </section>
    </div>
</form>

<?php include APP_ROOT . '/partials/panel/cliente-form.php'; ?>

<script>
(function () {
    var BASE = <?= json_encode(BASE_URL) ?>, CSRF = <?= json_encode(csrf_token()) ?>;
    var CLIENTES = <?= json_encode($clientesJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var BOXES = <?= json_encode($boxes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var PRODUCTOS = <?= json_encode($productos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var MEDIOS = <?= json_encode(CLIENTE_MEDIOS, JSON_UNESCAPED_UNICODE) ?>;
    var PRE_CLI = <?= (int) $preCli ?>, PRE_MES = <?= json_encode($preMes) ?>, HOY_MES = <?= json_encode(date('Y-m')) ?>;
    var SOLO_EXENTA = <?= $soloExenta ? 'true' : 'false' ?>; // empresa certificada solo para factura exenta (34)
    var MESES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];

    var form = document.getElementById('fx-form');
    var selCli = document.getElementById('fx-cli'), buscar = document.getElementById('fx-cli-buscar');
    var lineasEl = document.getElementById('fx-lineas'), vacio = document.getElementById('fx-vacio');
    var ivaIncl = document.getElementById('fx-iva-incl');
    var cli = null, lineas = [], seq = 0, horasData = null;

    function clp(n) { return '$' + (Math.round(n) || 0).toLocaleString('es-CL'); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
    function mesLargo(ym) { var p = String(ym).split('-'); return (MESES[parseInt(p[1], 10) - 1] || '') + ' ' + p[0]; }
    function boxPorSlug(s) { return BOXES.filter(function (b) { return b.slug === s; })[0] || null; }

    /* ------------------------------------------------------------ Cliente */
    function etiqueta(c) { return c.razon_social + ' — ' + c.rut + (c.faltan.length ? ' · faltan datos' : ''); }
    function llenarClientes() {
        var q = norm(buscar.value.trim()), qr = q.replace(/[.\-\s]/g, ''), actual = selCli.value;
        var lista = CLIENTES.filter(function (c) {
            return !q || norm(c.razon_social + ' ' + c.nombre_fantasia + ' ' + c.profesionales_nombres).indexOf(q) !== -1 || (qr && norm(c.rut).replace(/[.\-]/g, '').indexOf(qr) !== -1);
        });
        selCli.innerHTML = '<option value="">' + (lista.length ? '— Selecciona cliente (' + lista.length + ') —' : 'Sin resultados') + '</option>' +
            lista.map(function (c) { return '<option value="' + c.id + '">' + esc(etiqueta(c)) + '</option>'; }).join('');
        if (lista.some(function (c) { return String(c.id) === actual; })) selCli.value = actual;
        else if (q && lista.length === 1) { selCli.value = String(lista[0].id); onCliente(); }
    }
    function dato(k, v) { return '<dt class="font-semibold uppercase tracking-wide text-cafe/45">' + esc(k) + '</dt><dd class="min-w-0 break-words text-cafe/85">' + (v ? esc(v) : '<span class="font-semibold text-amber-700">Falta</span>') + '</dd>'; }
    function onCliente() {
        var c = CLIENTES.filter(function (x) { return String(x.id) === selCli.value; })[0] || null;
        var cambio = !cli || !c || cli.id !== c.id;
        cli = c;
        var card = document.getElementById('fx-cli-card');
        if (!c) { card.classList.add('hidden'); actualizarHerramientas(); return; }
        document.getElementById('fx-cli-nombre').textContent = c.razon_social + (c.nombre_fantasia ? ' (' + c.nombre_fantasia + ')' : '');
        document.getElementById('fx-cli-datos').innerHTML = dato('RUT', c.rut) + dato('Giro', c.giro) +
            dato('Dirección', [c.direccion, c.comuna].filter(Boolean).join(', ')) +
            (c.ciudad ? dato('Ciudad', c.ciudad) : '') + (c.profesionales_nombres ? dato(c.prof_ids.length > 1 ? 'Profesionales' : 'Profesional', c.profesionales_nombres) : '');
        var falta = document.getElementById('fx-cli-falta');
        falta.innerHTML = c.faltan.length ? 'Para facturarle falta: <strong>' + esc(c.faltan.join(', ')) + '</strong>. Usa «Editar datos del cliente».' : '';
        falta.classList.toggle('hidden', !c.faltan.length);
        card.classList.remove('hidden');
        if (cambio) {
            form.medio_pago.value = c.medio_pago || 'transferencia';
            form.receptor_email.value = c.email || '';
            // Cambió el cliente: las horas del calendario de otro cliente ya no aplican.
            lineas = lineas.filter(function (l) { return !(l.reserva_ids && l.reserva_ids.length); });
            renderLineas();
            autoArmar();
        }
        actualizarHerramientas();
    }
    buscar.addEventListener('input', llenarClientes);
    selCli.addEventListener('change', onCliente);

    // Arma solo lo obvio: horas pendientes del calendario y/o la tarifa habitual.
    function autoArmar() {
        if (!cli) return;
        if (cli.prof_ids.length) {
            var mes = PRE_MES || (cli.pend[0] ? cli.pend[0].mes : HOY_MES);
            document.getElementById('fx-h-mes').value = mes;
            abrirPanel('horas');
            cargarHoras(PRE_MES ? true : false);
            PRE_MES = '';
        } else if (cli.box_slug && cli.tarifa && !lineas.length) {
            var b = boxPorSlug(cli.box_slug), t = b && b.tarifas.filter(function (x) { return x.key === cli.tarifa; })[0];
            if (t) agregarTarifa(b, t, 1, true);
        }
    }

    document.getElementById('fx-cli-nuevo').addEventListener('click', function () {
        window.ClienteForm.abrir(null, { onGuardado: function (id) { location.href = BASE + '/admin/facturas/nueva?cliente=' + id; } });
    });
    document.getElementById('fx-cli-editar').addEventListener('click', function () {
        if (!cli) return;
        window.ClienteForm.abrir(cli, { onGuardado: function (id) { location.href = BASE + '/admin/facturas/nueva?cliente=' + id; } });
    });

    /* ------------------------------------------------------- Herramientas */
    var tools = Array.prototype.slice.call(document.querySelectorAll('.fx-tool[data-panel]'));
    function abrirPanel(nombre) {
        tools.forEach(function (b) { b.setAttribute('aria-expanded', b.dataset.panel === nombre ? 'true' : 'false'); });
        document.querySelectorAll('[data-fx-panel]').forEach(function (p) { p.classList.toggle('hidden', p.dataset.fxPanel !== nombre); });
    }
    tools.forEach(function (b) {
        b.addEventListener('click', function () {
            var abierto = b.getAttribute('aria-expanded') === 'true';
            abrirPanel(abierto ? '' : b.dataset.panel);
            if (!abierto && b.dataset.panel === 'horas') cargarHoras(false);
        });
    });
    function actualizarHerramientas() {
        var h = document.querySelector('.fx-tool[data-panel="horas"]');
        var sinProf = !cli || !cli.prof_ids.length;
        h.disabled = sinProf;
        h.title = !cli ? 'Primero elige el cliente' : (sinProf ? 'Este cliente no está vinculado a un profesional del calendario' : '');
        if (sinProf && h.getAttribute('aria-expanded') === 'true') abrirPanel('');
    }

    /* ---- Horas del calendario ---- */
    var hMes = document.getElementById('fx-h-mes'), hLista = document.getElementById('fx-h-lista'), hBtn = document.getElementById('fx-h-agregar');
    function idsEnDetalle() { var s = {}; lineas.forEach(function (l) { (l.reserva_ids || []).forEach(function (i) { s[i] = true; }); }); return s; }
    function cargarHoras(autoAgregar) {
        if (!cli || !cli.prof_ids.length) return;
        if (!hMes.value) hMes.value = cli.pend[0] ? cli.pend[0].mes : HOY_MES;
        document.getElementById('fx-h-prof').innerHTML = 'Horas de <strong>' + esc(cli.profesionales_nombres) + '</strong>';
        document.getElementById('fx-h-pend').innerHTML = cli.pend.map(function (p) {
            return '<button type="button" data-mes="' + p.mes + '" class="rounded-full border px-2.5 py-1 font-body text-[11px] font-bold transition-colors ' +
                (p.mes === hMes.value ? 'border-cafe bg-cafe text-crema-light' : 'border-terracota/40 bg-white text-terracota-dark hover:bg-terracota/10') + '">' +
                esc(mesLargo(p.mes)) + ' · ' + p.horas + ' h por facturar hasta hoy</button>';
        }).join('');
        document.querySelectorAll('#fx-h-pend [data-mes]').forEach(function (b) { b.addEventListener('click', function () { hMes.value = b.dataset.mes; cargarHoras(false); }); });
        hLista.innerHTML = '<p class="font-body text-sm text-cafe/50">Cargando horas…</p>';
        hBtn.disabled = true;
        var pedido = cli.id + '|' + hMes.value;
        fetch(BASE + '/api/panel/clientes?action=horas&id=' + cli.id + '&mes=' + encodeURIComponent(hMes.value), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.text().then(function (t) { try { return JSON.parse(t); } catch (e) { return { error: 'Respuesta inesperada del servidor.' }; } }); })
            .then(function (j) {
                if (!cli || pedido !== cli.id + '|' + hMes.value) return;
                if (!j.ok) { hLista.innerHTML = '<p class="font-body text-sm text-red-700">' + esc(j.error || 'No se pudieron cargar las horas.') + '</p>'; horasData = null; return; }
                horasData = j; pintarHoras();
                if (autoAgregar) agregarHoras();
            })
            .catch(function () { hLista.innerHTML = '<p class="font-body text-sm text-red-700">Sin conexión. Intenta de nuevo.</p>'; });
    }
    hMes.addEventListener('change', function () { cargarHoras(false); });
    function estadosTxt(e) {
        var n = { confirmada: 'confirmada', realizada: 'realizada', pendiente: 'pendiente', no_show: 'no asistió' }, out = [];
        Object.keys(e).forEach(function (k) { out.push(e[k] + ' ' + (n[k] || k) + (e[k] > 1 && k !== 'no_show' ? 's' : '')); });
        return out.join(' · ');
    }
    function alcance() { var r = document.querySelector('input[name="fx-alcance"]:checked'); return horasData && horasData.hay_futuras && r ? r.value : 'mes'; }
    // Ids de un grupo según el alcance elegido (solo hasta hoy = sin las horas futuras).
    function idsGrupo(g) { if (alcance() !== 'hoy') return g.ids; var f = {}; g.futuras.forEach(function (i) { f[i] = true; }); return g.ids.filter(function (i) { return !f[i]; }); }
    document.querySelectorAll('input[name="fx-alcance"]').forEach(function (r) { r.addEventListener('change', function () { if (horasData) pintarHoras(); }); });
    function pintarHoras() {
        var en = idsEnDetalle(), j = horasData, html = '';
        var alc = document.getElementById('fx-h-alcance');
        alc.classList.toggle('hidden', !j.hay_futuras); alc.classList.toggle('flex', !!j.hay_futuras);
        var visibles = j.grupos.filter(function (g) { return idsGrupo(g).length > 0; });
        if (!visibles.length) html = '<p class="rounded-lg bg-white px-3 py-3 font-body text-sm text-cafe/60">No hay horas sin facturar en ' + esc(j.mes_label) + (alcance() === 'hoy' && j.grupos.length ? ' hasta hoy. Elige «Mes completo» para incluir las agendadas.' : '.') + '</p>';
        j.grupos.forEach(function (g, i) {
            var ids = idsGrupo(g); if (!ids.length) return;
            var horas = ids.length, fut = g.futuras.length;
            var detalle = !fut ? esc(estadosTxt(g.estados))
                : (alcance() === 'mes' ? (horas - fut) + ' hasta hoy · ' + fut + ' agendadas' : 'hasta hoy · quedan fuera ' + fut + ' agendadas');
            var yaEsta = ids.every(function (id) { return en[id]; });
            html += '<div class="rounded-lg border border-cafe/10 bg-white px-3 py-2.5">' +
                '<label class="flex cursor-pointer items-start gap-3' + (yaEsta ? ' opacity-50' : '') + '">' +
                '<input type="checkbox" data-g="' + i + '" class="mt-1 h-4 w-4 accent-[#D97757]"' + (yaEsta ? ' disabled' : ' checked') + '>' +
                '<span class="min-w-0 flex-1"><span class="flex flex-wrap items-baseline justify-between gap-x-3">' +
                '<span class="font-body text-sm font-semibold text-cafe">' + (cli.prof_ids.length > 1 ? esc(g.profesional) + ' · ' : '') + 'Box ' + esc(g.box) + ' · <span class="fx-num">' + horas + ' h</span> × ' + clp(g.precio) + '</span>' +
                '<span class="fx-num font-body text-sm font-bold text-cafe">' + (yaEsta ? 'En el detalle' : clp(horas * g.precio)) + '</span></span>' +
                '<span class="block font-body text-[11px] text-cafe/55">' + detalle + ' · <button type="button" data-ver="' + i + '" class="font-semibold text-terracota-dark hover:underline">ver fechas</button></span>' +
                '<span data-fechas="' + i + '" class="mt-1 hidden font-body text-[11px] leading-relaxed text-cafe/60">' + esc(g.fechas.join(', ')) + '</span>' +
                '</span></label></div>';
        });
        j.facturadas.forEach(function (f) {
            html += '<p class="px-1 font-body text-[11px] text-cafe/50">✓ Box ' + esc(f.box) + ': ' + f.horas + ' h ya facturadas en la <a class="font-semibold text-terracota-dark hover:underline" href="' + BASE + '/admin/boletas/' + f.factura_id + '">factura N° ' + String(f.folio).padStart(6, '0') + '</a>.</p>';
        });
        hLista.innerHTML = html;
        hLista.querySelectorAll('[data-ver]').forEach(function (b) { b.addEventListener('click', function (e) { e.preventDefault(); hLista.querySelector('[data-fechas="' + b.dataset.ver + '"]').classList.toggle('hidden'); }); });
        hLista.querySelectorAll('input[data-g]').forEach(function (c) { c.addEventListener('change', notaHoras); });
        notaHoras();
    }
    function notaHoras() {
        var h = 0;
        hLista.querySelectorAll('input[data-g]:checked').forEach(function (c) { h += idsGrupo(horasData.grupos[c.dataset.g]).length; });
        hBtn.disabled = h === 0;
        hBtn.textContent = h ? 'Agregar ' + h + ' h al detalle' : 'Agregar al detalle';
        document.getElementById('fx-h-nota').textContent = 'Se excluyen las horas canceladas. Al emitir, estas horas quedan marcadas como facturadas.';
    }
    function agregarHoras() {
        if (!horasData) return;
        var n = 0;
        hLista.querySelectorAll('input[data-g]:checked').forEach(function (c) {
            var g = horasData.grupos[c.dataset.g], ids = idsGrupo(g);
            var hasta = alcance() === 'hoy' ? ' (hasta el ' + horasData.hoy.split('-').reverse().slice(0, 2).join('-') + ')' : '';
            lineas.push({ id: ++seq, origen: 'Calendario', descripcion: 'Arriendo box ' + g.box + (cli.prof_ids.length > 1 ? ' (' + g.profesional + ')' : '') + ' · ' + ids.length + ' h · ' + horasData.mes_label + hasta, cantidad: ids.length, precio: g.precio, afecto: true, reserva_ids: ids.slice() });
            n++;
        });
        if (!n) return;
        if (!form.glosa.value.trim()) form.glosa.value = 'Arriendo de boxes · ' + horasData.mes_label;
        renderLineas(); pintarHoras();
    }
    hBtn.addEventListener('click', agregarHoras);

    /* ---- Tarifa de box ---- */
    var tBox = document.getElementById('fx-t-box'), tTar = document.getElementById('fx-t-tarifa'), tCant = document.getElementById('fx-t-cant');
    var tGc = document.getElementById('fx-t-gc'), tGcWrap = document.getElementById('fx-t-gc-wrap');
    tBox.innerHTML = BOXES.map(function (b) { return '<option value="' + esc(b.slug) + '">' + esc(b.nombre) + '</option>'; }).join('');
    function llenarTarifas() {
        var b = boxPorSlug(tBox.value);
        tTar.innerHTML = (b ? b.tarifas : []).map(function (t, i) { return '<option value="' + i + '">' + esc(t.key) + ' — ' + clp(t.precio) + '</option>'; }).join('');
        if (cli && cli.box_slug === tBox.value && cli.tarifa) { var i = b.tarifas.findIndex(function (t) { return t.key === cli.tarifa; }); if (i >= 0) tTar.value = String(i); }
        verGc();
    }
    function verGc() {
        var b = boxPorSlug(tBox.value), t = b && b.tarifas[parseInt(tTar.value, 10)];
        var gc = t && t.gc > 0;
        tGcWrap.classList.toggle('hidden', !gc); tGcWrap.classList.toggle('flex', !!gc);
        if (gc) document.getElementById('fx-t-gc-txt').textContent = 'Incluir gastos comunes (' + clp(t.gc) + ' por mes)';
        var esPlan = t && t.label !== 'Por hora' && cli && cli.prof_ids.length;
        var pw = document.getElementById('fx-t-plan-wrap');
        pw.classList.toggle('hidden', !esPlan); pw.classList.toggle('flex', !!esPlan);
        tCant.closest('label').querySelector('.fx-lbl').textContent = t && t.label === 'Por hora' ? 'Horas' : 'Cant.';
    }
    tBox.addEventListener('change', llenarTarifas); tTar.addEventListener('change', verGc);
    function agregarTarifa(b, t, cant, conGc) {
        var pw = document.getElementById('fx-t-plan-wrap'), mesP = document.getElementById('fx-t-mes').value;
        var plan = !pw.classList.contains('hidden') && document.getElementById('fx-t-plan').checked && /^\d{4}-\d{2}$/.test(mesP) ? { box_slug: b.slug, mes: mesP } : null;
        lineas.push({ id: ++seq, origen: plan ? 'Plan' : 'Tarifa', descripcion: 'Arriendo box ' + b.nombre + ' — ' + t.key + (plan ? ' · ' + mesLargo(mesP) : ''), cantidad: cant, precio: t.precio, afecto: true, reserva_ids: [], plan: plan });
        if (conGc && t.gc > 0) lineas.push({ id: ++seq, origen: 'Tarifa', descripcion: 'Gastos comunes box ' + b.nombre, cantidad: cant, precio: t.gc, afecto: true, reserva_ids: [] });
        renderLineas();
    }
    document.getElementById('fx-t-agregar').addEventListener('click', function () {
        var b = boxPorSlug(tBox.value), t = b && b.tarifas[parseInt(tTar.value, 10)];
        if (!t) return;
        agregarTarifa(b, t, Math.max(1, parseInt(tCant.value, 10) || 1), !tGcWrap.classList.contains('hidden') && tGc.checked);
        tCant.value = 1;
    });
    document.querySelector('.fx-tool[data-panel="tarifa"]').addEventListener('click', function () {
        if (cli && cli.box_slug && boxPorSlug(cli.box_slug)) tBox.value = cli.box_slug;
        llenarTarifas();
    });
    llenarTarifas();

    /* ---- Servicio guardado ---- */
    var sProd = document.getElementById('fx-s-prod');
    if (sProd) {
        sProd.innerHTML = PRODUCTOS.map(function (p, i) { return '<option value="' + i + '">' + esc(p.nombre) + (p.profesional_nombre ? ' · ' + esc(p.profesional_nombre) : ' · sin profesional') + ' — ' + clp(p.precio_neto) + ' neto' + (parseInt(p.afecto, 10) ? '' : ' (exento)') + '</option>'; }).join('');
        document.getElementById('fx-s-agregar').addEventListener('click', function () {
            var p = PRODUCTOS[parseInt(sProd.value, 10)]; if (!p) return;
            var afecto = parseInt(p.afecto, 10) === 1;
            // Los servicios guardan precio NETO: se muestra en la misma base que la columna de precios.
            var precio = afecto && ivaIncl.checked ? Math.round(p.precio_neto * 1.19) : p.precio_neto;
            lineas.push({ id: ++seq, origen: 'Servicio', prof: p.profesional_nombre || '', producto_id: p.id, descripcion: p.nombre, cantidad: Math.max(1, parseInt(document.getElementById('fx-s-cant').value, 10) || 1), precio: precio, afecto: afecto, reserva_ids: [] });
            renderLineas();
        });
    }

    /* ---- Concepto libre ---- */
    document.getElementById('fx-libre').addEventListener('click', function () {
        abrirPanel('');
        lineas.push({ id: ++seq, origen: 'Libre', descripcion: '', cantidad: 1, precio: 0, afecto: true, reserva_ids: [] });
        renderLineas();
        var ult = lineasEl.querySelector('[data-id="' + seq + '"] .fx-desc'); if (ult) ult.focus();
    });

    /* ------------------------------------------------------------ Líneas */
    var CHIP = { Plan: 'bg-salvia/25 text-salvia-dark', Calendario: 'bg-salvia/25 text-salvia-dark', Tarifa: 'bg-terracota/15 text-terracota-dark', Servicio: 'bg-cafe/10 text-cafe/70', Libre: 'bg-crema-dark text-cafe/70' };
    function netoUnit(l) { return l.afecto && ivaIncl.checked ? Math.round(l.precio / 1.19) : l.precio; }
    function renderLineas() {
        vacio.classList.toggle('hidden', lineas.length > 0);
        if (SOLO_EXENTA) lineas.forEach(function (l) { l.afecto = false; });
        lineasEl.innerHTML = lineas.map(function (l) {
            var bloqueada = l.reserva_ids && l.reserva_ids.length;
            return '<div class="fx-line rounded-xl border border-cafe/10 bg-crema/25 p-3" data-id="' + l.id + '">' +
                '<div class="flex items-center justify-between gap-2"><span class="fx-chip ' + (CHIP[l.origen] || '') + '">' + esc(l.origen) + (l.prof ? ' · ' + esc(l.prof) : '') + (bloqueada ? ' · ' + l.reserva_ids.length + ' reservas' : '') + '</span>' +
                '<button type="button" data-del class="-mr-1 flex h-7 w-7 items-center justify-center rounded-full text-cafe/40 hover:bg-red-50 hover:text-red-600" aria-label="Quitar línea"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>' +
                '<input data-k="descripcion" value="' + esc(l.descripcion) + '" maxlength="300" placeholder="¿Qué se cobra? Ej.: Arriendo box Loica · jornada mañana" class="fx-in mt-1.5" aria-label="Descripción">' +
                '<div class="mt-2 flex flex-wrap items-end gap-2 sm:gap-3">' +
                '<label class="flex w-20 flex-col gap-0.5"><span class="fx-mini">Cant.</span><input data-k="cantidad" type="number" min="1" value="' + l.cantidad + '"' + (bloqueada ? ' readonly title="Sale de las horas del calendario"' : '') + ' class="fx-in fx-num text-center"></label>' +
                '<label class="flex w-32 flex-col gap-0.5"><span class="fx-mini" data-lbl-precio>Precio</span><input data-k="precio" type="number" min="0" step="1" value="' + l.precio + '" class="fx-in fx-num text-right"></label>' +
                (SOLO_EXENTA ? '<span class="fx-mini self-center rounded-full bg-salvia/15 px-2 py-1 text-salvia-dark">Exento</span>' : '<label class="flex w-28 flex-col gap-0.5"><span class="fx-mini">IVA</span><select data-k="afecto" class="fx-in"><option value="1"' + (l.afecto ? ' selected' : '') + '>Afecto</option><option value="0"' + (l.afecto ? '' : ' selected') + '>Exento</option></select></label>') +
                '<div class="ml-auto text-right"><span class="fx-mini block">Subtotal</span><span class="fx-num font-body text-base font-bold text-cafe" data-sub></span></div>' +
                '</div></div>';
        }).join('');
        lineasEl.querySelectorAll('.fx-line').forEach(function (row) {
            var l = lineas.filter(function (x) { return String(x.id) === row.dataset.id; })[0];
            row.querySelectorAll('[data-k]').forEach(function (inp) {
                inp.addEventListener('input', function () {
                    var k = inp.dataset.k;
                    if (k === 'descripcion') l.descripcion = inp.value;
                    else if (k === 'cantidad') l.cantidad = Math.max(1, parseInt(inp.value, 10) || 1);
                    else if (k === 'precio') l.precio = Math.max(0, parseInt(inp.value, 10) || 0);
                    else if (k === 'afecto') l.afecto = inp.value === '1';
                    totales();
                });
            });
            row.querySelector('[data-del]').addEventListener('click', function () {
                lineas = lineas.filter(function (x) { return x !== l; });
                renderLineas(); if (horasData) pintarHoras();
            });
        });
        totales();
    }
    function totales() {
        var neto = 0, exento = 0;
        if (SOLO_EXENTA) { ivaIncl.checked = false; lineas.forEach(function (l) { l.afecto = false; }); }
        lineas.forEach(function (l) {
            var row = lineasEl.querySelector('[data-id="' + l.id + '"] [data-sub]');
            if (row) row.textContent = clp(l.cantidad * l.precio);
            var sub = l.cantidad * netoUnit(l);
            if (l.precio > 0) { if (l.afecto) neto += sub; else exento += sub; }
        });
        var iva = Math.round(neto * 0.19);
        document.getElementById('fx-neto').textContent = clp(neto);
        document.getElementById('fx-iva').textContent = clp(iva);
        document.getElementById('fx-exento').textContent = clp(exento);
        document.getElementById('fx-total').textContent = clp(neto + iva + exento);
        var hayAfecto = lineas.some(function (l) { return l.afecto && l.precio > 0; });
        var chip = document.getElementById('fx-tipo');
        chip.textContent = SOLO_EXENTA ? 'Factura exenta electrónica (34)' : (hayAfecto || !lineas.length ? 'Factura electrónica (33)' : 'Factura exenta electrónica (34)');
        lineasEl.querySelectorAll('[data-lbl-precio]').forEach(function (s) { s.textContent = SOLO_EXENTA ? 'Precio' : (ivaIncl.checked ? 'Precio c/IVA' : 'Precio neto'); });
        document.getElementById('fx-iva-nota').textContent = SOLO_EXENTA
            ? 'Factura exenta (34): se cobra el precio escrito, sin IVA (la empresa está certificada ante el SII para factura exenta).'
            : (ivaIncl.checked
            ? 'Los precios escritos incluyen IVA: en la factura se informa el neto y se suma el 19% (puede variar $1 por redondeo).'
            : 'Los precios escritos son netos: a los ítems afectos se les suma el IVA 19%.');
        return { neto: neto, iva: iva, exento: exento, total: neto + iva + exento, tipo: chip.textContent };
    }
    ivaIncl.addEventListener('change', totales);

    /* ------------------------------------------------------------ Emitir */
    var errEl = document.getElementById('fx-err'), btn = document.getElementById('fx-emitir');
    function falla(m) { errEl.textContent = m; errEl.classList.remove('hidden'); errEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    form.addEventListener('submit', function (e) {
        e.preventDefault(); errEl.classList.add('hidden');
        if (!cli) return falla('Selecciona el cliente.');
        if (cli.faltan.length) return falla('Al cliente le falta: ' + cli.faltan.join(', ') + '. Complétalo con «Editar datos del cliente».');
        var items = [], malas = 0;
        lineas.forEach(function (l) {
            if (l.precio <= 0 && !l.descripcion.trim()) return;
            if (l.precio <= 0 || !l.descripcion.trim()) { malas++; return; }
            items.push({ descripcion: l.descripcion.trim(), cantidad: l.cantidad, precio_unit: netoUnit(l), afecto: l.afecto ? 1 : 0, reserva_ids: l.reserva_ids || [], plan: l.plan || null, producto_id: l.producto_id || 0 });
        });
        if (malas) return falla('Hay líneas sin descripción o sin precio: complétalas o quítalas.');
        if (!items.length) return falla('Agrega al menos un ítem con precio.');
        var mail = form.receptor_email.value.trim();
        if (mail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mail)) return falla('El correo para enviar la factura no es válido.');
        var t = totales();
        ezConfirm('Se emitirá una ' + t.tipo.replace(/ \(\d+\)$/, '').toLowerCase() + ' a ' + cli.razon_social + ' (RUT ' + cli.rut + ') por ' + clp(t.total) + '.', { title: 'Emitir factura', okText: 'Emitir factura' })
            .then(function (ok) {
                if (!ok) return;
                btn.disabled = true; var txt = btn.textContent; btn.textContent = 'Emitiendo…';
                var EZ = window.ezEmision;
                var esp = EZ ? EZ.enviando({ titulo: 'Emitiendo ' + t.tipo.replace(/ \(\d+\)$/, '').toLowerCase() + '…', texto: 'Preparando el documento y enviándolo al SII. No cierres esta página.' }) : null;
                function cerrarEsp() { if (esp) { esp.cerrar(); esp = null; } }
                fetch(BASE + '/api/panel/clientes', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                    body: JSON.stringify({ action: 'facturar', cliente_id: cli.id, items: items, medio_pago: form.medio_pago.value, receptor_email: mail, glosa: form.glosa.value.trim(),
                        pago: (form.querySelector('input[name="fx-pago"]:checked') || {}).value || 'pendiente', vence_en: document.getElementById('fx-vence').value })
                }).then(function (r) { return r.text().then(function (x) { try { return { ok: r.ok, d: JSON.parse(x) }; } catch (e) { return { ok: false, d: { error: 'Respuesta inesperada del servidor. Revisa en «Documentos» antes de reintentar.' } }; } }); })
                  .then(function (x) {
                      cerrarEsp();
                      if (!x.ok || !x.d.ok) { btn.disabled = false; btn.textContent = txt; falla(x.d.error || 'No se pudo emitir la factura.'); if (horasData) cargarHoras(false); return; }
                      var d = x.d, verUrl = BASE + '/admin/boletas/' + d.id;
                      if (!EZ) { location.href = verUrl; return; }
                      // Aviso en la misma pantalla: factura emitida y su estado en el SII (se actualiza solo).
                      EZ.listo({
                          titulo: EZ.nombre(d.tipo_dte) + ' N° ' + String(d.folio).padStart(6, '0') + ' emitida',
                          subtitulo: cli.razon_social + ' · ' + clp(d.total || t.total),
                          estado: d.interno ? 'interno' : (d.sii_estado || 'aceptada'),
                          trackid: d.trackid || '',
                          ver: { url: verUrl, texto: 'Ver documento' },
                          otro: { url: BASE + '/admin/facturas/nueva', texto: 'Emitir otra' },
                          documentos: BASE + '/admin/documentos',
                          revisar: function () {
                              return fetch(BASE + '/api/panel/boletas', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ action: 'sii_recheck', id: d.id, rapido: 1 }) })
                                  .then(function (r) { return r.json().then(function (j) { return r.ok && j.ok ? { estado: j.estado, mensaje: j.mensaje } : { estado: 'pendiente' }; }); });
                          }
                      });
                  })
                  .catch(function () { cerrarEsp(); btn.disabled = false; btn.textContent = txt; falla('Sin conexión. Revisa en «Documentos» si se emitió antes de reintentar.'); });
            });
    });

    /* ------------------------------------------------------------ Inicio */
    llenarClientes();
    renderLineas();
    actualizarHerramientas();
    if (PRE_CLI && CLIENTES.some(function (c) { return c.id === PRE_CLI; })) { selCli.value = String(PRE_CLI); onCliente(); }
})();
</script>
<?php endif; ?>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
