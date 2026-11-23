<?php
/**
 * Panel · Documentos emitidos (boletas y facturas).
 * Filtros por empresa, tipo y profesional que se aplican AL INSTANTE (sin recargar)
 * y KPIs que se recalculan según lo filtrado, incluido un resumen por profesional.
 */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/boletas.php';
require_once APP_ROOT . '/lib/empresas.php';
require_once APP_ROOT . '/lib/cobros.php';
$filtroPago = in_array($_GET['pago'] ?? '', ['por_cobrar', 'vencida', 'pagado', 'anulada', 'a_favor'], true) ? $_GET['pago'] : '';

$empresas = empresasLista(true);
$filtroEmpresa = (int) ($_GET['empresa'] ?? 0);
$filtroTipo = in_array($_GET['tipo'] ?? '', ['boleta', 'factura'], true) ? $_GET['tipo'] : '';
$filtroProf = (string) ($_GET['prof'] ?? '');
if (!in_array($filtroProf, ['sin', 'cli'], true) && !ctype_digit($filtroProf)) {
    $filtroProf = '';
}
$filtroCliente = (string) (int) ($_GET['cliente'] ?? 0);
// Se cargan todos los documentos y se filtran en el navegador (respuesta inmediata).
$boletas = listBoletas(2000);
$esAdmin = isAdmin();
$mesActual = date('Y-m');

// Profesionales para el filtro: activos + cualquiera que figure en algún documento.
$profOpts = [];
try {
    foreach (db()->query("SELECT id, nombre FROM profesionales WHERE activo = 1 ORDER BY nombre") as $p) {
        $profOpts[(string) $p['id']] = $p['nombre'];
    }
} catch (Throwable $e) {
}
foreach ($boletas as $b) {
    if (!empty($b['profesional_id']) && !isset($profOpts[(string) $b['profesional_id']])) {
        $profOpts[(string) $b['profesional_id']] = $b['profesional_nombre'] ?: ('Profesional #' . $b['profesional_id']);
    }
}
asort($profOpts, SORT_FLAG_CASE | SORT_STRING);

// Clientes con facturas (filtro por cliente). Las facturas se agrupan aparte del resumen por profesional.
$cliOpts = [];
$hayFacturasCli = false;
foreach ($boletas as $b) {
    if (!empty($b['cliente_id'])) {
        $cliOpts[(string) (int) $b['cliente_id']] = $b['receptor_nombre'];
        $hayFacturasCli = true;
    }
}
asort($cliOpts, SORT_FLAG_CASE | SORT_STRING);

$selCls = 'rounded-full border border-cafe/20 bg-crema-light px-4 py-2 font-body text-sm text-cafe outline-none focus:border-terracota';

$panelTitle = 'Boletas y Facturas';
$panelActive = 'boletas';
include APP_ROOT . '/partials/panel/top.php';
?>
<!-- Filtros + acción -->
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <form id="doc-filtros" method="get" action="<?= e(base_path('/admin/boletas')) ?>" class="flex flex-wrap items-center gap-2" onsubmit="return false;">
        <select name="empresa" class="<?= $selCls ?>" aria-label="Empresa">
            <option value="0">Todas las empresas</option>
            <?php foreach ($empresas as $emp): ?>
                <option value="<?= (int) $emp['id'] ?>" <?= $filtroEmpresa === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['razon_social']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="pago" class="<?= $selCls ?>" aria-label="Estado de pago">
            <option value="">Todos los pagos</option>
            <option value="por_cobrar" <?= $filtroPago === 'por_cobrar' ? 'selected' : '' ?>>Por cobrar</option>
            <option value="vencida" <?= $filtroPago === 'vencida' ? 'selected' : '' ?>>Vencidas</option>
            <option value="pagado" <?= $filtroPago === 'pagado' ? 'selected' : '' ?>>Pagadas</option>
            <option value="a_favor" <?= $filtroPago === 'a_favor' ? 'selected' : '' ?>>Con devolución pendiente</option>
            <option value="anulada" <?= $filtroPago === 'anulada' ? 'selected' : '' ?>>Anuladas</option>
        </select>
        <select name="tipo" class="<?= $selCls ?>" aria-label="Tipo de documento">
            <option value="">Boletas y facturas</option>
            <option value="boleta" <?= $filtroTipo === 'boleta' ? 'selected' : '' ?>>Solo boletas</option>
            <option value="factura" <?= $filtroTipo === 'factura' ? 'selected' : '' ?>>Solo facturas</option>
        </select>
        <select name="prof" class="<?= $selCls ?>" aria-label="Profesional">
            <option value="">Todos los profesionales</option>
            <?php foreach ($profOpts as $pid => $pnom): ?>
                <option value="<?= e((string) $pid) ?>" <?= $filtroProf === (string) $pid ? 'selected' : '' ?>><?= e($pnom) ?></option>
            <?php endforeach; ?>
            <option value="sin" <?= $filtroProf === 'sin' ? 'selected' : '' ?>>Sin profesional asignado</option>
            <?php if ($hayFacturasCli): ?><option value="cli" <?= $filtroProf === 'cli' ? 'selected' : '' ?>>Facturas a clientes</option><?php endif; ?>
        </select>
        <select name="cliente" class="<?= $selCls ?>" aria-label="Cliente">
            <option value="0">Todos los clientes</option>
            <?php foreach ($cliOpts as $cidOpt => $cnom): ?>
                <option value="<?= e((string) $cidOpt) ?>" <?= $filtroCliente === (string) $cidOpt ? 'selected' : '' ?>><?= e((string) $cnom) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" id="doc-limpiar" class="hidden rounded-full px-3 py-2 font-body text-xs font-semibold text-cafe/55 hover:text-terracota">Limpiar filtros ✕</button>
    </form>
    <?php if ($esAdmin): ?>
        <div class="flex flex-wrap items-center gap-2">
            <a href="<?= e(base_path('/admin/facturas/nueva')) ?>" class="inline-flex items-center gap-2 rounded-full border-2 border-terracota/45 px-4 py-2 font-body text-sm font-semibold text-terracota-dark transition-colors hover:bg-terracota/10" title="Facturas: solo para Clientes">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Factura a cliente
            </a>
            <a id="doc-emitir" href="<?= e(base_path('/admin/boletas/nueva' . ($filtroEmpresa ? '?empresa=' . $filtroEmpresa : ''))) ?>" class="inline-flex items-center gap-2 rounded-full bg-terracota px-5 py-2.5 font-body text-sm font-semibold text-crema-light shadow-card transition-colors hover:bg-terracota-dark" title="Boletas: para Pacientes">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Boleta a paciente
            </a>
        </div>
    <?php endif; ?>
</div>

<p class="mb-4 font-body text-xs text-cafe/60">Las boletas y facturas las emiten solo las socias: <strong class="text-cafe"><?= e(sociasTexto()) ?></strong>. A ellas no se les cobra arriendo de box.</p>
<!-- KPIs (se recalculan según los filtros) -->
<div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
    <div class="rounded-2xl border border-cafe/12 bg-white px-5 py-4 shadow-card">
        <div class="font-body text-[11px] font-semibold uppercase tracking-wide text-cafe/55">Emitido este mes</div>
        <div id="kpi-mes" class="mt-1 font-heading text-2xl font-semibold text-cafe">$0</div>
        <div id="kpi-mes-n" class="font-body text-xs text-cafe/50"></div>
    </div>
    <div class="rounded-2xl border border-cafe/12 bg-white px-5 py-4 shadow-card">
        <div class="font-body text-[11px] font-semibold uppercase tracking-wide text-cafe/55">Total en la selección</div>
        <div id="kpi-total" class="mt-1 font-heading text-2xl font-semibold text-cafe">$0</div>
        <div id="kpi-total-n" class="font-body text-xs text-cafe/50"></div>
    </div>
    <div class="rounded-2xl border border-terracota/30 bg-[#FBF3EC] px-5 py-4 shadow-card">
        <div class="font-body text-[11px] font-semibold uppercase tracking-wide text-terracota-dark">Por cobrar</div>
        <div id="kpi-cobrar" class="mt-1 font-heading text-2xl font-semibold text-cafe">$0</div>
        <div id="kpi-cobrar-n" class="font-body text-xs text-cafe/50"></div>
    </div>
    <div class="rounded-2xl border border-cafe/12 bg-white px-5 py-4 shadow-card">
        <div class="font-body text-[11px] font-semibold uppercase tracking-wide text-cafe/55">Viendo</div>
        <div id="kpi-filtro" class="mt-1 font-body text-sm font-semibold leading-snug text-cafe">Todos los documentos</div>
        <div id="kpi-anuladas" class="font-body text-xs text-cafe/50"></div>
    </div>
</div>

<!-- Resumen por profesional -->
<div class="mb-5 rounded-2xl border border-cafe/12 bg-white p-4 shadow-card sm:p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="font-heading text-lg font-semibold text-cafe">Por profesional y clientes</h2>
        <span class="font-body text-xs text-cafe/50">Documentos emitidos según la empresa y el tipo elegidos. Toca uno para filtrar.</span>
    </div>
    <div id="kpi-prof" class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3"></div>
</div>

<!-- Tabla -->
<div class="ez-tabla-card">
    <div class="overflow-x-auto">
        <table class="ez-tabla">
            <thead>
                <tr>
                    <th class="px-3.5 py-3">Folio</th>
                    <th class="px-3.5 py-3">Tipo</th>
                    <th class="px-3.5 py-3">Empresa</th>
                    <th class="px-3.5 py-3">Fecha</th>
                    <th class="px-3.5 py-3">Paciente / cliente</th>
                    <th class="px-3.5 py-3">Profesional</th>
                    <th class="px-3.5 py-3">Pago</th>
                    <th class="px-3.5 py-3 text-right">Total</th>
                    <th class="px-3.5 py-3 text-center">Estado · SII</th>
                    <th class="px-3.5 py-3"></th>
                </tr>
            </thead>
            <tbody id="doc-tbody" class="font-body text-sm text-cafe/85">
                <?php foreach ($boletas as $b):
                    $esFactura = ($b['tipo'] ?? 'boleta') === 'factura';
                    $empNombre = $b['empresa_fantasia'] ?: ($b['empresa_razon'] ?: '—');
                    $sii = $b['sii_estado'] ?? 'no_enviada';
                    $anulada = ($b['estado'] ?? '') === 'anulada';
                    // Facturas a clientes (arriendo de boxes): grupo propio, no «sin profesional».
                    $profId = !empty($b['cliente_id']) ? 'cli' : (!empty($b['profesional_id']) ? (string) (int) $b['profesional_id'] : 'sin');
                    $profNom = !empty($b['cliente_id']) ? 'Facturas a clientes' : ($b['profesional_nombre'] ?? '');
                    $siiMap = [
                        'aceptada'  => ['SII aceptada',  'bg-emerald-100 text-emerald-700'],
                        'rechazada' => ['SII rechazada', 'bg-red-100 text-red-700'],
                        'pendiente' => ['SII pendiente', 'bg-amber-100 text-amber-700'],
                    ]; ?>
                    <tr class="doc-row <?= $esFactura ? 'acc-terracota' : '' ?>"
                        data-emp="<?= (int) $b['empresa_id'] ?>" data-tipo="<?= $esFactura ? 'factura' : 'boleta' ?>"
                        data-prof="<?= e($profId) ?>" data-prof-nombre="<?= e($profNom) ?>" data-cli="<?= (int) ($b['cliente_id'] ?? 0) ?>"
                        <?php $sd = docSaldo($b); [$pgTxt, $pgCls] = docPagoEtiqueta($b);
                              $pgClave = $anulada ? 'anulada' : ($sd['a_favor'] > 0 ? 'a_favor' : ($sd['saldo'] <= 0 ? 'pagado' : ($sd['vencida'] ? 'vencida' : 'por_cobrar'))); ?>
                        data-total="<?= $anulada ? 0 : $sd['a_pagar'] ?>" data-saldo="<?= $sd['saldo'] ?>" data-pago="<?= e($pgClave) ?>" data-ym="<?= e(date('Y-m', strtotime($b['fecha_emision']))) ?>"
                        data-anulada="<?= $anulada ? '1' : '0' ?>">
                        <td class="px-3.5 py-3 font-semibold text-cafe whitespace-nowrap">N° <?= e(str_pad((string) $b['folio'], 6, '0', STR_PAD_LEFT)) ?></td>
                        <td class="px-3.5 py-3"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $esFactura ? 'bg-terracota/15 text-terracota-dark' : 'bg-salvia/20 text-salvia-dark' ?>"><?= $esFactura ? ((int) $b['iva_clp'] === 0 && (int) $b['exento_clp'] > 0 ? 'Factura exenta' : 'Factura') : 'Boleta' ?></span></td>
                        <td class="px-3.5 py-3 leading-snug"><?= e($empNombre) ?></td>
                        <td class="px-3.5 py-3 whitespace-nowrap"><?= e(date('d-m-Y', strtotime($b['fecha_emision']))) ?></td>
                        <td class="px-3.5 py-3 leading-snug"><?php if (!empty($b['cliente_id'])): ?><a href="<?= e(base_path('/admin/clientes/' . (int) $b['cliente_id'])) ?>" class="hover:text-terracota-dark hover:underline"><?= e($b['receptor_nombre']) ?></a><div class="text-[11px] text-cafe/50">Cliente</div><?php else: ?><?= e($b['receptor_nombre']) ?><?php endif; ?></td>
                        <td class="px-3.5 py-3 leading-snug"><?= !empty($b['cliente_id']) ? '<span class="text-cafe/35">—</span>' : ($profNom !== '' ? e($profNom) : '<span class="text-cafe/35">—</span>') ?></td>
                        <td class="px-3.5 py-3 whitespace-nowrap"><span class="inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold <?= $pgCls ?>"><?= e($pgTxt) ?></span><div class="mt-0.5 text-[11px] text-cafe/50"><?= e(medioPagoLabel($b['medio_pago'])) ?></div></td>
                        <td class="px-3.5 py-3 text-right font-semibold text-cafe whitespace-nowrap"><?= e(clp((int) $b['total_clp'])) ?><?php if ($sd['nc'] > 0): ?><div class="text-[11px] font-normal text-cafe/55">NC − <?= e(clp($sd['nc'])) ?></div><?php endif; ?><?php if ($sd['nd'] > 0): ?><div class="text-[11px] font-normal text-cafe/55">ND + <?= e(clp($sd['nd'])) ?></div><?php endif; ?></td>
                        <td class="px-3.5 py-3 text-center whitespace-nowrap">
                            <div class="flex items-center justify-center gap-1">
                            <?php if ($anulada): ?>
                                <span class="rounded-full bg-red-100 px-2.5 py-0.5 font-body text-xs font-semibold text-red-700">Anulada</span>
                            <?php else: ?>
                                <span class="rounded-full bg-salvia/20 px-2.5 py-0.5 font-body text-xs font-semibold text-salvia-dark">Emitida</span>
                            <?php endif; ?>
                                <?php if (isset($siiMap[$sii])): [$txt, $cls] = $siiMap[$sii]; ?>
                                    <span class="rounded-full px-2 py-0.5 font-body text-[10px] font-bold <?= $cls ?>"<?= $b['sii_track_id'] ? ' title="TrackID ' . e($b['sii_track_id']) . '"' : '' ?>><?= e($txt) ?></span>
                                    <?php if ($esAdmin && $sii === 'pendiente'): ?>
                                        <button type="button" class="sii-recheck rounded border border-cafe/15 px-1 text-[10px] font-semibold text-cafe/55 hover:border-terracota hover:text-terracota" data-id="<?= (int) $b['id'] ?>" title="Re-consultar estado en el SII">↻</button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="rounded-full bg-cafe/8 px-2 py-0.5 font-body text-[10px] font-semibold text-cafe/50" title="Documento interno, no enviado al SII">interna</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-3.5 py-3 text-right whitespace-nowrap">
                            <?php $vb = base_path('/admin/boletas/' . $b['id']); ?>
                            <a href="<?= e($vb . '?f=carta') ?>" class="font-body text-xs font-semibold text-cafe/60 hover:text-terracota" title="Ver en formato carta">Carta</a>
                            <a href="<?= e($vb . '?f=80') ?>" class="font-body text-xs font-semibold text-cafe/60 hover:text-terracota">80</a>
                            <a href="<?= e($vb . '?f=58') ?>" class="font-body text-xs font-semibold text-cafe/60 hover:text-terracota">58</a>
                            <span class="text-cafe/25">·</span>
                            <a href="<?= e($vb) ?>" class="font-body text-xs font-semibold text-terracota-dark hover:text-terracota">Ver →</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr id="doc-vacio" class="<?= $boletas ? 'hidden' : '' ?>"><td colspan="10" class="px-5 py-12 text-center text-cafe/45">No hay documentos<span id="doc-vacio-filtro"> con ese filtro</span>. <?php if ($esAdmin): ?>Usa «Boleta a paciente» o «Factura a cliente».<?php endif; ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<style>
    .kpi-prof-card.activo { border-color: #D97757; box-shadow: 0 0 0 2px rgba(217,119,87,.25); background: #FBF3EC; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var P = window.__PANEL__ || {};
    var MES = <?= json_encode($mesActual) ?>;
    var BASE_EMITIR = <?= json_encode(base_path('/admin/boletas/nueva')) ?>;
    var form = document.getElementById('doc-filtros');
    var selEmp = form.querySelector('[name=empresa]');
    var selTipo = form.querySelector('[name=tipo]');
    var selPago = form.querySelector('[name=pago]');
    var selProf = form.querySelector('[name=prof]');
    var selCli = form.querySelector('[name=cliente]');
    var limpiar = document.getElementById('doc-limpiar');
    var rows = Array.prototype.slice.call(document.querySelectorAll('#doc-tbody tr.doc-row'));
    var vacio = document.getElementById('doc-vacio');
    var vacioFiltro = document.getElementById('doc-vacio-filtro');
    var emitir = document.getElementById('doc-emitir');

    function clp(n) { return '$' + (parseInt(n, 10) || 0).toLocaleString('es-CL'); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function txtSel(sel) { var o = sel.options[sel.selectedIndex]; return o ? o.textContent : ''; }
    function plural(n, s, p) { return n + ' ' + (n === 1 ? s : p); }

    // Coincide con empresa + tipo (sin mirar el profesional): base del resumen por profesional.
    function matchBase(tr) {
        var e = selEmp.value, t = selTipo.value;
        if (e !== '0' && e !== '' && tr.dataset.emp !== e) return false;
        if (t && tr.dataset.tipo !== t) return false;
        if (selCli.value !== '0' && tr.dataset.cli !== selCli.value) return false;
        return true;
    }
    function matchProf(tr) { var p = selProf.value; return !p || tr.dataset.prof === p; }
    function matchPago(tr) { var v = selPago.value; if (!v) return true; if (v === 'por_cobrar') return tr.dataset.pago === 'por_cobrar' || tr.dataset.pago === 'vencida'; return tr.dataset.pago === v; }

    function aplicar() {
        var visibles = 0, totSel = 0, nSel = 0, totMes = 0, nMes = 0, nAnul = 0, porCobrar = 0, nCobrar = 0, nVenc = 0;
        var porProf = {}; // id -> {nombre, n, total, mes}
        rows.forEach(function (tr) {
            var base = matchBase(tr);
            var ok = base && matchProf(tr) && matchPago(tr);
            tr.classList.toggle('hidden', !ok);
            var anul = tr.dataset.anulada === '1';
            var total = parseInt(tr.dataset.total, 10) || 0;
            if (base && !anul) {
                var k = tr.dataset.prof;
                if (!porProf[k]) porProf[k] = { nombre: k === 'sin' ? 'Sin profesional asignado' : (k === 'cli' ? 'Facturas a clientes' : (tr.dataset.profNombre || 'Profesional')), n: 0, total: 0, mes: 0 };
                porProf[k].n++; porProf[k].total += total;
                if (tr.dataset.ym === MES) porProf[k].mes += total;
            }
            if (!ok) return;
            visibles++;
            if (anul) { nAnul++; return; }
            nSel++; totSel += total;
            if (tr.dataset.ym === MES) { nMes++; totMes += total; }
            var sal = parseInt(tr.dataset.saldo, 10) || 0;
            if (sal > 0) { porCobrar += sal; nCobrar++; if (tr.dataset.pago === 'vencida') nVenc++; }
        });

        var hayFiltro = (selEmp.value !== '0' && selEmp.value !== '') || selTipo.value !== '' || selProf.value !== '' || selCli.value !== '0' || selPago.value !== '';
        vacio.classList.toggle('hidden', visibles > 0);
        vacioFiltro.classList.toggle('hidden', !hayFiltro);
        limpiar.classList.toggle('hidden', !hayFiltro);

        document.getElementById('kpi-mes').textContent = clp(totMes);
        document.getElementById('kpi-mes-n').textContent = plural(nMes, 'documento', 'documentos') + ' emitidos este mes';
        document.getElementById('kpi-total').textContent = clp(totSel);
        document.getElementById('kpi-total-n').textContent = plural(nSel, 'documento emitido', 'documentos emitidos');
        var partes = [];
        if (selEmp.value !== '0' && selEmp.value !== '') partes.push(txtSel(selEmp));
        if (selTipo.value) partes.push(txtSel(selTipo));
        if (selProf.value) partes.push(txtSel(selProf));
        if (selCli.value !== '0') partes.push(txtSel(selCli));
        if (selPago.value) partes.push(txtSel(selPago));
        document.getElementById('kpi-filtro').textContent = partes.length ? partes.join(' · ') : 'Todos los documentos';
        document.getElementById('kpi-cobrar').textContent = clp(porCobrar);
        document.getElementById('kpi-cobrar-n').textContent = nCobrar ? plural(nCobrar, 'documento con saldo', 'documentos con saldo') + (nVenc ? ' · ' + nVenc + ' vencido' + (nVenc === 1 ? '' : 's') : '') : 'Todo pagado';
        document.getElementById('kpi-anuladas').textContent = nAnul ? plural(nAnul, 'anulada (no suma)', 'anuladas (no suman)') : '';

        // Resumen por profesional (ordenado por monto).
        var lista = Object.keys(porProf).map(function (k) { return { id: k, d: porProf[k] }; })
            .sort(function (a, b) { if (a.id === 'sin') return 1; if (b.id === 'sin') return -1; if (a.id === 'cli') return 1; if (b.id === 'cli') return -1; return b.d.total - a.d.total; });
        var cont = document.getElementById('kpi-prof');
        if (!lista.length) {
            cont.innerHTML = '<p class="font-body text-sm text-cafe/45">No hay documentos emitidos con esa empresa y tipo.</p>';
        } else {
            cont.innerHTML = lista.map(function (x) {
                var activo = selProf.value === x.id;
                return '<button type="button" data-prof="' + esc(x.id) + '" class="kpi-prof-card flex items-center justify-between gap-3 rounded-xl border border-cafe/12 bg-crema/40 px-4 py-3 text-left transition-colors hover:border-terracota/60' + (activo ? ' activo' : '') + '">' +
                    '<span class="min-w-0"><span class="block truncate font-body text-sm font-semibold ' + (x.id === 'sin' ? 'text-cafe/55' : (x.id === 'cli' ? 'text-terracota-dark' : 'text-cafe')) + '">' + esc(x.d.nombre) + '</span>' +
                    '<span class="block font-body text-xs text-cafe/50">' + plural(x.d.n, 'documento', 'documentos') + ' · este mes ' + clp(x.d.mes) + '</span></span>' +
                    '<span class="shrink-0 font-heading text-lg font-semibold text-cafe">' + clp(x.d.total) + '</span></button>';
            }).join('');
            cont.querySelectorAll('[data-prof]').forEach(function (b) {
                b.addEventListener('click', function () {
                    selProf.value = (selProf.value === b.dataset.prof) ? '' : b.dataset.prof;
                    aplicar();
                });
            });
        }

        // URL compartible y botón «Emitir» con la empresa elegida.
        var qs = [];
        if (selEmp.value !== '0' && selEmp.value !== '') qs.push('empresa=' + encodeURIComponent(selEmp.value));
        if (selTipo.value) qs.push('tipo=' + encodeURIComponent(selTipo.value));
        if (selProf.value) qs.push('prof=' + encodeURIComponent(selProf.value));
        if (selCli.value !== '0') qs.push('cliente=' + encodeURIComponent(selCli.value));
        if (selPago.value) qs.push('pago=' + encodeURIComponent(selPago.value));
        try { history.replaceState(null, '', location.pathname + (qs.length ? '?' + qs.join('&') : '')); } catch (e) {}
        if (emitir) emitir.href = BASE_EMITIR + ((selEmp.value !== '0' && selEmp.value !== '') ? '?empresa=' + encodeURIComponent(selEmp.value) : '');
    }

    [selEmp, selTipo, selProf, selCli, selPago].forEach(function (s) { s.addEventListener('change', aplicar); });
    limpiar.addEventListener('click', function () { selEmp.value = '0'; selTipo.value = ''; selProf.value = ''; selCli.value = '0'; selPago.value = ''; aplicar(); });
    aplicar();

    <?php if ($esAdmin): ?>
    document.querySelectorAll('.sii-recheck').forEach(function (b) {
        b.addEventListener('click', function () {
            var old = b.textContent; b.textContent = '…'; b.disabled = true;
            fetch(P.base + '/api/panel/boletas', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf },
                body: JSON.stringify({ action: 'sii_recheck', id: b.dataset.id })
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (j && j.ok) { location.reload(); }
                else { ezAlert((j && j.error) || 'No se pudo consultar el SII.'); b.textContent = old; b.disabled = false; }
            }).catch(function () { b.textContent = old; b.disabled = false; });
        });
    });
    <?php endif; ?>
});
</script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
