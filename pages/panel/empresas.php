<?php
/**
 * Panel · Empresas y SII (solo admin). Un solo módulo con todo lo configurable de cada empresa:
 * datos fiscales, documentos certificados, estado ante el SII (certificado, folios, lista de
 * verificación por tipo) y la conexión con Trivoxa API DTE. Las credenciales (certificado y CAF)
 * se cargan en /admin/dte/{id}.
 */
declare(strict_types=1);

requireAdminPage();
require_once APP_ROOT . '/lib/empresas.php';

$empresas = empresasLista(true);
require_once APP_ROOT . '/lib/clientes.php';
$empFactura = facturaEmpresa();
$empFacturaId = $empFactura ? (int) $empFactura['id'] : 0;
[$conex, $cfg] = require APP_ROOT . '/dte/bootstrap.php';
require_once APP_ROOT . '/lib/dte_integra.php';
require_once APP_ROOT . '/dte/lib/dte_folios_sii.php';
// Prueba de conexión con Trivoxa API DTE (no emite nada ni consume folios).
$prueba = isset($_GET['probar']) ? dteProbarConexion() : null;

$panelTitle = 'Empresas y SII';
$panelActive = 'empresas';
include APP_ROOT . '/partials/panel/top.php';
?>
<div class="mb-5 space-y-3">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="max-w-3xl font-body text-sm text-cafe/60">Todo lo configurable de cada empresa en un solo lugar: <strong class="text-cafe">datos fiscales</strong>, documentos certificados y su <strong class="text-cafe">estado ante el SII</strong> (certificado digital, folios CAF y lo que falte para emitir). Cada empresa emite con su propio certificado y sus propios folios; nunca se combinan. Mientras falte algo, sus documentos quedan <strong class="text-cafe">internos</strong> (pendientes de timbrar).</p>
        <button type="button" id="emp-nuevo-btn" class="inline-flex shrink-0 items-center gap-2 rounded-full bg-terracota px-5 py-2.5 font-body text-sm font-semibold text-crema-light shadow-card hover:bg-terracota-dark">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg> Nueva empresa
        </button>
    </div>
    <div class="flex max-w-3xl flex-wrap items-center gap-3 rounded-xl border border-cafe/10 bg-white/70 px-4 py-3 font-body text-xs text-cafe/70">
        <span><strong class="text-cafe">Conexión SII:</strong> Trivoxa API DTE · API key <?= dteApiKeyValida($cfg) ? '<strong class="text-salvia-dark">configurada</strong> (' . e(substr(trim((string) $cfg['api_key']), 0, 10)) . '…)' : '<strong class="text-amber-700">pendiente</strong> (TRVX-… de producción)' ?></span>
        <a href="<?= e(base_path('/admin/empresas?probar=1')) ?>" class="rounded-full border border-cafe/15 px-3 py-1 font-semibold text-cafe hover:border-terracota hover:text-terracota">Probar conexión</a>
        <?php if ($prueba): ?>
            <span class="w-full rounded-lg px-3 py-2 <?= $prueba['ok'] ? 'bg-salvia/15 text-salvia-dark' : 'bg-amber-50 text-amber-900' ?>"><?= $prueba['ok'] ? '✓ ' : '○ ' ?><?= e($prueba['detalle']) ?></span>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
    <?php foreach ($empresas as $emp):
        $esFactura = $emp['tipo_documento'] === 'factura';
        $badge = $esFactura ? 'bg-terracota/15 text-terracota-dark' : 'bg-salvia/20 text-salvia-dark'; ?>
        <div class="flex flex-col rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card <?= (int) $emp['activo'] === 1 ? '' : 'opacity-60' ?>">
            <div class="flex items-start justify-between gap-2">
                <span class="flex flex-wrap gap-1.5"><span class="rounded-full px-3 py-1 font-body text-xs font-bold uppercase tracking-wide <?= $badge ?>"><?= $esFactura ? 'Facturas' : 'Boletas' ?></span>
                <?php if ((int) $emp['id'] === $empFacturaId && !$esFactura): ?><span class="rounded-full bg-terracota/15 px-3 py-1 font-body text-xs font-bold uppercase tracking-wide text-terracota-dark" title="Emite todas las facturas del módulo Clientes">+ Facturas a clientes</span><?php endif; ?></span>
                <?php if ((int) $emp['activo'] !== 1): ?><span class="rounded-full bg-cafe/10 px-2.5 py-1 font-body text-xs font-semibold text-cafe/60">Inactiva</span><?php endif; ?>
            </div>
            <h3 class="mt-3 font-heading text-lg font-semibold text-cafe"><?= e($emp['razon_social']) ?></h3>
            <?php if (!empty($emp['nombre_fantasia'])): ?><p class="font-body text-sm text-cafe/60"><?= e($emp['nombre_fantasia']) ?></p><?php endif; ?>
            <dl class="mt-3 space-y-1 font-body text-sm text-cafe/70">
                <div class="flex justify-between gap-3"><dt class="text-cafe/45">RUT</dt><dd class="text-right font-semibold text-cafe"><?= e($emp['rut'] ?: '—') ?></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-cafe/45">Giro</dt><dd class="text-right"><?= e($emp['giro'] ?: '—') ?></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-cafe/45">Dirección</dt><dd class="text-right"><?= e(trim(($emp['direccion'] ?: '—') . ($emp['comuna'] ? ', ' . $emp['comuna'] : ''))) ?></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-cafe/45">Correo</dt><dd class="text-right"><?= e($emp['email'] ?: '—') ?></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-cafe/45">Actividad SII</dt><dd class="text-right"><?= !empty($emp['acteco']) ? e((string) $emp['acteco']) : '<span class="text-amber-700">Falta</span>' ?></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-cafe/45">Certificada SII</dt><dd class="text-right"><?= !empty($emp['dte_certificados']) ? e(str_replace(',', ', ', (string) $emp['dte_certificados'])) : '<span class="text-cafe/40">sin restricción</span>' ?></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-cafe/45">Resolución SII</dt><dd class="text-right"><?= ($emp['sii_resolucion_numero'] ?? null) !== null && !empty($emp['sii_resolucion_fecha']) ? 'N° ' . (int) $emp['sii_resolucion_numero'] . ' · ' . e(date('d-m-Y', strtotime($emp['sii_resolucion_fecha']))) : '<span class="text-amber-700">Falta</span>' ?></dd></div>
            </dl>

            <?php
            // ---- Estado ante el SII (antes en «Facturación DTE») ----
            $est = dte_empresa_estado($conex, (int) $emp['id']);
            $tieneCert = $est['tiene_cert'];
            $tipos = $est['tipos_activos'] ?? [];
            $certR = $est['cert'] ?? null;
            $vencido = $certR && !empty($certR['cert_vence']) && $certR['cert_vence'] < date('Y-m-d');
            $chkTipos = [];
            $certEmp = dteCertificados($emp);
            if (($emp['tipo_documento'] ?? 'boleta') !== 'factura' && ($certEmp === null || in_array(39, $certEmp, true) || in_array(41, $certEmp, true))) {
                // Empresa autorizada solo para boleta exenta (41, ej. Espacio Luz Spa): se revisa la 41, nunca la 39.
                if (dteBoletaSoloExenta($emp)) $chkTipos[41] = 'Boletas exentas a pacientes';
                else $chkTipos[39] = 'Boletas a pacientes';
            }
            if (($emp['tipo_documento'] ?? '') === 'factura' || (int) $emp['id'] === $empFacturaId) {
                if (dteFacturaSoloExenta($emp)) $chkTipos[34] = 'Facturas exentas a clientes';
                else $chkTipos[33] = 'Facturas a clientes';
            }
            if ($certEmp !== null && in_array(61, $certEmp, true)) $chkTipos[61] = 'Notas de crédito';
            if ($certEmp !== null && in_array(56, $certEmp, true)) $chkTipos[56] = 'Notas de débito';
            ?>
            <div class="mt-4 rounded-xl border border-cafe/10 bg-white/70 p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="font-body text-[11px] font-bold uppercase tracking-[0.12em] text-cafe/55">Estado ante el SII</p>
                    <?php if ($tieneCert && $tipos && !$vencido): ?><span class="rounded-full bg-salvia/15 px-2.5 py-0.5 font-body text-[11px] font-bold text-salvia-dark">Credenciales cargadas</span>
                    <?php elseif ($vencido): ?><span class="rounded-full bg-red-100 px-2.5 py-0.5 font-body text-[11px] font-bold text-red-600">Certificado vencido</span>
                    <?php else: ?><span class="rounded-full bg-terracota/12 px-2.5 py-0.5 font-body text-[11px] font-bold text-terracota-dark">Pendiente</span><?php endif; ?>
                </div>
                <ul class="mt-2 space-y-1 font-body text-xs">
                    <li class="<?= $tieneCert ? 'text-cafe/80' : 'text-cafe/50' ?>"><?= $tieneCert ? '✅' : '⬜' ?> Certificado digital <?= $tieneCert ? 'cargado' . (!empty($certR['cert_vence']) ? ' · vence el ' . e(date('d-m-Y', strtotime($certR['cert_vence']))) : '') : 'pendiente' ?></li>
                </ul>
                <?php $fol = dte_folios_resumen($conex, (int) $emp['id']); if ($fol): ?>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <?php foreach ($fol as $f): $cl = ['sin_caf' => 'bg-cafe/8 text-cafe/55', 'agotado' => 'bg-red-100 text-red-700', 'vencido' => 'bg-red-100 text-red-700', 'por_vencer' => 'bg-amber-100 text-amber-800', 'bajo' => 'bg-amber-100 text-amber-800', 'ok' => 'bg-salvia/15 text-salvia-dark'][$f['estado']]; ?>
                            <a href="<?= e(base_path('/admin/dte/' . (int) $emp['id'] . '#folios')) ?>" class="rounded-lg px-2 py-1 font-body text-[11px] font-semibold <?= $cl ?>" title="<?= e($f['nombre'] . ($f['vence'] ? ' · vence el ' . date('d-m-Y', strtotime($f['vence'])) : '')) ?>"><?= (int) $f['tipo'] ?> · <?= $f['estado'] === 'sin_caf' ? 'sin CAF' : ($f['estado'] === 'vencido' ? 'vencido' : (int) $f['disponibles'] . ' folio' . ((int) $f['disponibles'] === 1 ? '' : 's') . ($f['estado'] === 'por_vencer' ? ' · vence pronto' : '')) ?><?= $f['vencidos'] > 0 ? ' · ' . (int) $f['vencidos'] . ' por anular' : '' ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php foreach ($chkTipos as $chkT => $chkL):
                    $chk = dteChequeoEmision($emp, $chkT);
                    $nFalt = count($chk['faltan']); ?>
                    <details class="mt-2 rounded-lg border <?= $chk['listo'] ? 'border-salvia/40 bg-salvia/8' : 'border-cafe/10 bg-crema/40' ?>">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-3 py-1.5 font-body text-xs font-semibold text-cafe">
                            <span><?= e($chkL) ?> <span class="font-normal text-cafe/50">(<?= (int) $chkT ?>)</span></span>
                            <?php if ($chk['listo']): ?><span class="rounded-full bg-salvia/20 px-2 py-0.5 text-[10px] font-bold text-salvia-dark">Listo para el SII</span>
                            <?php else: ?><span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800"><?= $nFalt ?> pendiente<?= $nFalt === 1 ? '' : 's' ?></span><?php endif; ?>
                        </summary>
                        <ul class="space-y-1 border-t border-cafe/8 px-3 py-2">
                            <?php foreach ($chk['items'] as $it): ?>
                                <li class="flex gap-2 font-body text-[11px] leading-snug">
                                    <span class="mt-px shrink-0 font-bold <?= $it['ok'] ? 'text-salvia-dark' : 'text-amber-700' ?>"><?= $it['ok'] ? '✓' : '○' ?></span>
                                    <span class="min-w-0"><span class="font-semibold <?= $it['ok'] ? 'text-cafe/80' : 'text-cafe' ?>"><?= e($it['label']) ?></span><?php if ($it['detalle'] !== ''): ?><span class="block text-cafe/55"><?= e($it['detalle']) ?></span><?php endif; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endforeach; ?>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-cafe/8 pt-3">
                <a href="<?= e(base_path('/admin/dte/' . (int) $emp['id'])) ?>" class="rounded-lg bg-cafe px-3 py-1.5 font-body text-xs font-semibold text-crema-light hover:bg-cafe-light">Certificado y folios (CAF)</a>
                <button type="button" class="emp-edit rounded-lg border border-cafe/15 px-3 py-1.5 font-body text-xs font-semibold text-cafe hover:border-terracota hover:text-terracota" data-json='<?= e(json_encode($emp, JSON_UNESCAPED_UNICODE)) ?>'>Editar datos</button>
                <button type="button" class="emp-activo rounded-lg border border-cafe/15 px-3 py-1.5 font-body text-xs font-semibold text-cafe hover:border-terracota hover:text-terracota" data-id="<?= (int) $emp['id'] ?>" data-activo="<?= (int) $emp['activo'] ?>"><?= (int) $emp['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button>
                <a href="<?= e(base_path('/admin/productos?empresa=' . $emp['id'])) ?>" class="ml-auto font-body text-xs font-semibold text-terracota-dark hover:text-terracota">Productos →</a>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Modal empresa -->
<div id="emp-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-cafe/50 p-4 backdrop-blur-sm">
    <div class="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-crema-light p-6 shadow-warm">
        <h3 id="emp-title" class="mb-3 font-heading text-xl font-semibold text-cafe">Nueva empresa</h3>
        <form id="emp-form" class="grid grid-cols-2 gap-3">
            <input type="hidden" name="id">
            <label class="col-span-2 flex flex-col gap-1"><span class="elbl">Razón social *</span><input name="razon_social" required class="einp"></label>
            <label class="flex flex-col gap-1"><span class="elbl">Nombre de fantasía</span><input name="nombre_fantasia" class="einp"></label>
            <label class="flex flex-col gap-1"><span class="elbl">RUT</span><input name="rut" class="einp" placeholder="76.123.456-7"></label>
            <label class="flex flex-col gap-1"><span class="elbl">Tipo de documento *</span><select name="tipo_documento" class="einp"><option value="boleta">Boletas</option><option value="factura">Facturas</option></select></label>
            <label class="flex flex-col gap-1"><span class="elbl">Actividad económica</span><input name="actividad_economica" class="einp" placeholder="Ej.: Otras actividades de atención de la salud humana"></label>
            <label class="col-span-2 flex flex-col gap-1"><span class="elbl">Giro</span><input name="giro" class="einp"></label>
            <label class="col-span-2 flex flex-col gap-1"><span class="elbl">Dirección</span><input name="direccion" class="einp"></label>
            <label class="flex flex-col gap-1"><span class="elbl">Comuna</span><input name="comuna" class="einp"></label>
            <label class="flex flex-col gap-1"><span class="elbl">Ciudad</span><input name="ciudad" class="einp" placeholder="Ej.: Los Ángeles"></label>
            <label class="flex flex-col gap-1"><span class="elbl">Teléfono</span><input name="telefono" class="einp"></label>
            <label class="flex flex-col gap-1"><span class="elbl">Correo</span><input name="email" type="email" class="einp"></label>
            <div class="col-span-2 mt-1 rounded-xl border border-terracota/25 bg-terracota/5 p-3">
                <p class="font-body text-xs font-bold uppercase tracking-wide text-terracota-dark">Datos para emitir al SII</p>
                <p class="mt-0.5 font-body text-[11px] leading-relaxed text-cafe/60">Aparecen en el SII, en los datos de la empresa autorizada como emisor electrónico. Sin ellos no se envían documentos al SII.</p>
                <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label class="flex flex-col gap-1"><span class="elbl">Código actividad SII</span><input name="acteco" inputmode="numeric" maxlength="6" class="einp" placeholder="869090"></label>
                    <label class="flex flex-col gap-1"><span class="elbl">N° resolución SII</span><input name="sii_resolucion_numero" inputmode="numeric" class="einp" placeholder="Ej.: 0 u 80"></label>
                    <label class="flex flex-col gap-1"><span class="elbl">Fecha resolución SII</span><input name="sii_resolucion_fecha" type="date" class="einp"></label>
                </div>
                <div class="mt-3">
                    <span class="elbl">Documentos certificados ante el SII</span>
                    <p class="font-body text-[11px] text-cafe/55">Marca solo los tipos que la empresa ya certificó. Si no marcas ninguno, no se restringe.</p>
                    <div class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1.5">
                        <?php foreach ([33 => 'Factura (33)', 34 => 'Factura exenta (34)', 39 => 'Boleta (39)', 41 => 'Boleta exenta (41)', 56 => 'Nota de débito (56)', 61 => 'Nota de crédito (61)'] as $tc => $tl): ?>
                            <label class="flex items-center gap-1.5 font-body text-xs text-cafe/80"><input type="checkbox" name="dte_cert_<?= $tc ?>" value="<?= $tc ?>" class="emp-cert h-4 w-4 accent-[#D97757]"> <?= e($tl) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <label class="col-span-2 flex items-center gap-2"><input type="checkbox" name="activo" class="h-4 w-4 accent-[#D97757]" checked><span class="font-body text-xs text-cafe/70">Empresa activa (puede emitir documentos).</span></label>
            <p id="emp-err" class="col-span-2 hidden font-body text-sm font-medium text-red-600"></p>
            <div class="col-span-2 flex justify-end gap-2"><button type="button" id="emp-cancel" class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota">Cancelar</button><button type="submit" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Guardar</button></div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.einp').forEach(function (el) { el.className = 'einp rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota'; });
    document.querySelectorAll('.elbl').forEach(function (el) { el.className = 'elbl font-body text-xs font-semibold uppercase tracking-wide text-cafe/60'; });
    var P = window.__PANEL__ || {}, modal = document.getElementById('emp-modal'), f = document.getElementById('emp-form');
    function show() { modal.classList.remove('hidden'); modal.classList.add('flex'); }
    function hide() { modal.classList.add('hidden'); modal.classList.remove('flex'); }
    function post(d) { return fetch(P.base + '/api/panel/empresas', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf }, body: JSON.stringify(d) }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, d: j }; }); }); }

    document.getElementById('emp-nuevo-btn').addEventListener('click', function () {
        f.reset(); f.id.value = ''; f.activo.checked = true; f.tipo_documento.disabled = false;
        document.getElementById('emp-title').textContent = 'Nueva empresa'; document.getElementById('emp-err').classList.add('hidden'); show();
    });
    document.querySelectorAll('.emp-edit').forEach(function (b) { b.addEventListener('click', function () {
        var d = JSON.parse(b.dataset.json); f.reset();
        ['id','razon_social','nombre_fantasia','rut','giro','actividad_economica','direccion','comuna','ciudad','email','telefono','tipo_documento','acteco','sii_resolucion_numero','sii_resolucion_fecha'].forEach(function (k) { if (f[k]) f[k].value = d[k] == null ? '' : d[k]; });
        f.tipo_documento.value = d.tipo_documento || 'boleta';
        var certs = String(d.dte_certificados || '').split(',');
        f.querySelectorAll('.emp-cert').forEach(function (c) { c.checked = certs.indexOf(c.value) !== -1; });
        f.activo.checked = String(d.activo) === '1';
        document.getElementById('emp-title').textContent = 'Editar empresa'; document.getElementById('emp-err').classList.add('hidden'); show();
    }); });
    document.querySelectorAll('.emp-activo').forEach(function (b) { b.addEventListener('click', function () {
        post({ action: 'activo', id: b.dataset.id, activo: b.dataset.activo === '1' ? 0 : 1 }).then(function () { location.reload(); });
    }); });
    document.getElementById('emp-cancel').addEventListener('click', hide);
    modal.addEventListener('click', function (e) { if (e.target === modal) hide(); });
    f.addEventListener('submit', function (e) { e.preventDefault(); var err = document.getElementById('emp-err'); err.classList.add('hidden');
        var d = { action: f.id.value ? 'actualizar' : 'crear' }; new FormData(f).forEach(function (v, k) { if (k.indexOf('dte_cert_') !== 0) d[k] = v; }); d.activo = f.activo.checked ? 1 : 0;
        d.dte_certificados = Array.prototype.filter.call(f.querySelectorAll('.emp-cert'), function (c) { return c.checked; }).map(function (c) { return c.value; }).join(',');
        post(d).then(function (x) { if (!x.ok) { err.textContent = x.d.error || 'Error'; err.classList.remove('hidden'); return; } location.reload(); });
    });
});
</script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
