<?php
/** Panel · Credenciales DTE de una empresa (certificado + CAF/folios). Solo admin. */
declare(strict_types=1);

requireAdminPage();
require_once APP_ROOT . '/lib/empresas.php';

$empresaId = (int) ($empresaId ?? 0);
$empresa = getEmpresa($empresaId);
if (!$empresa) {
    http_response_code(404);
    $panelTitle = 'Empresa no encontrada';
    $panelActive = 'empresas';
    include APP_ROOT . '/partials/panel/top.php';
    echo '<p class="font-body text-cafe/60">La empresa no existe. <a class="text-terracota-dark underline" href="' . e(base_path('/admin/empresas')) . '">Volver</a></p>';
    include APP_ROOT . '/partials/panel/bottom.php';
    return;
}

// Motor DTE (mysqli) para leer el estado real de credenciales/folios.
[$conex, $cfg] = require APP_ROOT . '/dte/bootstrap.php';
$estado = dte_empresa_estado($conex, $empresaId);
$cafs = [];
$st = $conex->prepare("SELECT id, tipo_dte, folio_desde, folio_hasta, folio_actual, activo, fecha_autorizacion, caf_nombre FROM caf_empresa WHERE empresa_id=? AND ambiente='produccion' ORDER BY tipo_dte, folio_desde");
$st->bind_param('i', $empresaId); $st->execute();
$rr = $st->get_result();
while ($x = $rr->fetch_assoc()) {
    $disp = max(0, (int) $x['folio_hasta'] - (int) $x['folio_actual'] + 1);
    if ((int) $x['folio_actual'] > (int) $x['folio_hasta']) $disp = 0;
    $x['disponibles'] = $disp;
    $cafs[] = $x;
}
$st->close();

$tieneCert = $estado['tiene_cert'];
$cert = $estado['cert'] ?? null;
$tiposConFolios = $estado['tipos_activos'] ?? [];
$listoEmitir = $tieneCert && count($tiposConFolios) > 0;

$hoy = date('Y-m-d');
$certVencido = $cert && !empty($cert['cert_vence']) && $cert['cert_vence'] < $hoy;
$certPorVencer = $cert && !empty($cert['cert_vence']) && !$certVencido && $cert['cert_vence'] <= date('Y-m-d', strtotime('+30 days'));

require_once APP_ROOT . '/dte/lib/dte_folios_sii.php';
$folios = dte_folios_resumen($conex, $empresaId);
$foliosHist = dte_folios_historial($conex, $empresaId, 12);
$foliosCuota = dte_folios_estado_cuota($conex);
$apiOk = (bool) preg_match('/^TRVX(-[A-Z0-9]{3,}){2,}$/i', trim((string) ($cfg['api_key'] ?? '')));

$panelTitle = 'DTE · ' . $empresa['razon_social'];
$panelActive = 'empresas';
include APP_ROOT . '/partials/panel/top.php';
?>
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <a href="<?= e(base_path('/admin/empresas')) ?>" class="inline-flex items-center gap-1.5 font-body text-sm font-semibold text-cafe/60 hover:text-terracota">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg> Volver a Empresas y SII
    </a>
    <?php if ($listoEmitir): ?>
        <span class="inline-flex items-center gap-2 rounded-full bg-salvia/15 px-4 py-1.5 font-body text-sm font-semibold text-salvia-dark"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Lista para emitir</span>
    <?php else: ?>
        <span class="inline-flex items-center gap-2 rounded-full bg-terracota/12 px-4 py-1.5 font-body text-sm font-semibold text-terracota-dark"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg> Falta configurar</span>
    <?php endif; ?>
</div>

<div class="mb-6 rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
    <h2 class="font-heading text-xl font-semibold text-cafe"><?= e($empresa['razon_social']) ?></h2>
    <p class="mt-1 font-body text-sm text-cafe/60">RUT <span class="font-semibold text-cafe"><?= e($empresa['rut'] ?: '— (configúralo en Empresas)') ?></span> · <?= e(tipoDocLabel($empresa['tipo_documento'], true)) ?></p>
    <?php if (empty($empresa['rut'])): ?>
        <p class="mt-2 rounded-lg bg-terracota/10 px-3 py-2 font-body text-xs text-terracota-dark">⚠ Esta empresa no tiene RUT. El motor valida que el RUT del CAF coincida con el de la empresa, así que primero completa el RUT en <a class="underline" href="<?= e(base_path('/admin/empresas')) ?>">Empresas</a>.</p>
    <?php endif; ?>
</div>

<!-- Folios por tipo de documento: cuántos quedan, solicitud al SII y automático -->
<section id="folios" class="mb-6 rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="font-heading text-lg font-semibold text-cafe">Folios por tipo de documento</h3>
            <p class="max-w-3xl font-body text-xs leading-relaxed text-cafe/55">Cuántos folios de producción quedan de cada documento. «Solicitar folios» los pide al SII con el certificado de la empresa (vía Trivoxa API DTE, igual que el Timbraje electrónico de sii.cl) y carga el CAF solo; aquí ves la respuesta del SII. Con <strong class="text-cafe">automático</strong>, cuando queden pocos o el CAF está por vencer, el sistema pide más por su cuenta (una revisión cada 6 horas; el primer CAF de cada tipo siempre se pide a mano). Los CAF de factura afecta (33), nota de débito (56) y nota de crédito (61) <strong class="text-cafe">vencen a los 6 meses</strong>: el sistema no usa folios vencidos y te indica cuáles anular en sii.cl.</p>
        </div>
        <span class="rounded-full bg-cafe/8 px-3 py-1 font-body text-[11px] font-semibold text-cafe/60">Solicitudes este mes: <?= (int) $foliosCuota['usados'] ?> / <?= (int) $foliosCuota['limite'] ?></span>
    </div>
    <?php if (!$tieneCert || !$apiOk): ?>
        <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 font-body text-xs text-amber-900">Para solicitar folios falta: <?= e(implode(' y ', array_filter([!$tieneCert ? 'el certificado digital de la empresa' : '', !$apiOk ? 'la API key de Trivoxa API DTE' : '']))) ?>.</p>
    <?php endif; ?>
    <div class="mt-4 overflow-x-auto ez-tabla-card">
        <table class="ez-tabla">
            <thead><tr>
                <th class="px-3">Documento</th><th class="px-3 text-right">Quedan</th><th class="px-3">Rango vigente</th><th class="px-3">Vence</th><th class="px-3">Máximo SII</th><th class="px-3">Automático</th><th class="px-3">Última respuesta del SII</th><th class="px-3"></th>
            </tr></thead>
            <tbody class="font-body text-sm text-cafe/80">
                <?php foreach ($folios as $f):
                    $cf = $f['config'];
                    $chip = ['sin_caf' => ['Sin CAF', 'bg-cafe/10 text-cafe/60'], 'agotado' => ['Agotado', 'bg-red-100 text-red-700'], 'vencido' => ['Vencido', 'bg-red-100 text-red-700'], 'por_vencer' => ['Vence pronto', 'bg-amber-100 text-amber-800'], 'bajo' => ['Quedan pocos', 'bg-amber-100 text-amber-800'], 'ok' => ['Con folios', 'bg-salvia/20 text-salvia-dark']][$f['estado']];
                    $ul = $f['ultima']; ?>
                    <tr class="<?= $f['estado'] === 'agotado' ? 'acc-terracota' : '' ?>" data-tipo="<?= (int) $f['tipo'] ?>">
                        <td class="px-3 py-2.5"><span class="font-semibold text-cafe"><?= e($f['nombre']) ?></span> <span class="text-xs text-cafe/45">(<?= (int) $f['tipo'] ?>)</span><br><span class="mt-0.5 inline-block rounded-full px-2 py-0.5 text-[10px] font-bold <?= $chip[1] ?>"><?= e($chip[0]) ?></span></td>
                        <td class="px-3 py-2.5 text-right text-lg font-semibold tabular-nums <?= $f['disponibles'] <= 0 ? 'text-red-600' : 'text-cafe' ?>"><?= (int) $f['disponibles'] ?></td>
                        <td class="px-3 py-2.5 text-xs"><?= $f['actual'] !== null ? 'próximo N° ' . (int) $f['actual'] . '<br><span class="text-cafe/45">hasta ' . (int) $f['hasta'] . ($f['cafs'] > 1 ? ' · ' . (int) $f['cafs'] . ' CAF' : '') . '</span>' : '<span class="text-cafe/40">—</span>' ?></td>
                        <td class="px-3 py-2.5 text-xs"><?php if (!$f['vencen']): ?><span class="text-cafe/45">no vence</span><?php elseif ($f['vence']): ?><span class="<?= $f['vence_dias'] <= 15 ? 'font-semibold text-amber-800' : 'text-cafe' ?>"><?= e(date('d-m-Y', strtotime($f['vence']))) ?></span><br><span class="text-cafe/45">en <?= (int) $f['vence_dias'] ?> día(s)</span><?php else: ?><span class="text-cafe/40">—</span><?php endif; ?>
                            <?php if ($f['vencidos'] > 0): ?><span class="mt-1 block rounded bg-red-50 px-1.5 py-0.5 text-[10px] font-semibold text-red-700" title="Anúlalos en sii.cl: Factura electrónica → Sistema de facturación de mercado → Timbraje electrónico → Anular folios"><?= (int) $f['vencidos'] ?> vencido(s) por anular: <?= e(implode(', ', array_map(fn($x) => $x[0] . '–' . $x[1], $f['vencidos_rangos']))) ?></span><?php endif; ?></td>
                        <td class="px-3 py-2.5 text-xs"><?= !empty($cf['maximo_sii']) ? '<strong class="text-cafe">' . (int) $cf['maximo_sii'] . '</strong> folios<br><span class="text-cafe/45">informado ' . e(date('d-m-Y', strtotime((string) $cf['maximo_fecha']))) . '</span>' : '<span class="text-cafe/40">aún no informado</span>' ?></td>
                        <td class="px-3 py-2.5 text-xs">
                            <label class="flex items-center gap-1.5"><input type="checkbox" class="fol-auto h-4 w-4 accent-[#D97757]" <?= (int) $cf['auto'] === 1 ? 'checked' : '' ?>> <span>pedir solo</span></label>
                            <span class="mt-1 flex items-center gap-1 whitespace-nowrap text-cafe/60">si quedan ≤ <input type="number" min="1" max="5000" value="<?= (int) $cf['umbral'] ?>" class="fol-umbral w-14 rounded border border-cafe/20 bg-white px-1 py-0.5 text-right"> pedir <input type="number" min="1" max="10000" value="<?= (int) $cf['cantidad'] ?>" class="fol-cant w-16 rounded border border-cafe/20 bg-white px-1 py-0.5 text-right"></span>
                        </td>
                        <td class="max-w-xs px-3 py-2.5 text-xs leading-snug"><?php if ($ul): ?><span class="font-semibold <?= (int) $ul['ok'] === 1 ? 'text-salvia-dark' : 'text-red-700' ?>"><?= (int) $ul['ok'] === 1 ? '✓ Autorizado' : '✗ No autorizado' ?></span> · <?= e(date('d-m-Y H:i', strtotime((string) $ul['creado_en']))) ?><br><span class="text-cafe/65"><?= e(mb_strimwidth((string) $ul['mensaje'], 0, 180, '…', 'UTF-8')) ?></span><?php else: ?><span class="text-cafe/40">Sin solicitudes</span><?php endif; ?></td>
                        <td class="px-3 py-2.5 text-right"><button type="button" class="fol-pedir whitespace-nowrap rounded-full bg-cafe px-3 py-1.5 text-xs font-semibold text-crema-light hover:bg-cafe-light disabled:opacity-50" data-nombre="<?= e($f['nombre']) ?>" data-cant="<?= (int) $cf['cantidad'] ?>" data-max="<?= (int) ($cf['maximo_sii'] ?? 0) ?>" <?= (!$tieneCert || !$apiOk) ? 'disabled' : '' ?>>Solicitar folios</button></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$folios): ?><tr><td colspan="8" class="py-6 text-center text-cafe/45">Esta empresa aún no tiene tipos de documento configurados.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <p id="fol-msg" class="mt-3 hidden rounded-lg px-3 py-2 font-body text-sm"></p>
    <?php if ($foliosHist): ?>
        <details class="mt-4 rounded-xl border border-cafe/10 bg-white/60 px-4 py-2">
            <summary class="cursor-pointer font-body text-sm font-semibold text-cafe">Historial de solicitudes al SII (<?= count($foliosHist) ?>)</summary>
            <ul class="mt-2 divide-y divide-cafe/8 font-body text-xs">
                <?php foreach ($foliosHist as $h): ?>
                    <li class="py-2"><span class="font-semibold <?= (int) $h['ok'] === 1 ? 'text-salvia-dark' : 'text-red-700' ?>"><?= (int) $h['ok'] === 1 ? '✓' : '✗' ?> <?= e(dte_nombre((int) $h['tipo_dte'])) ?> · <?= (int) $h['cantidad_solicitada'] ?> folio(s)</span>
                        <span class="text-cafe/50">· <?= e(date('d-m-Y H:i', strtotime((string) $h['creado_en']))) ?> · <?= $h['origen'] === 'auto' ? 'automático' : e((string) ($h['usuario'] ?: 'manual')) ?></span>
                        <span class="block text-cafe/70"><?= e((string) $h['mensaje']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
</section>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    <!-- Certificado digital -->
    <div class="rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
        <div class="flex items-center gap-2">
            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-cafe/10 text-cafe"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h6M7 13h10M9 17h6"/><circle cx="17" cy="8" r="1.5"/></svg></span>
            <div><h3 class="font-heading text-lg font-semibold text-cafe">Certificado digital</h3><p class="font-body text-xs text-cafe/50">Archivo .pfx / .p12 del representante autorizado ante el SII</p></div>
        </div>
        <?php if ($tieneCert): ?>
            <div class="mt-4 rounded-xl border border-salvia/30 bg-salvia/8 p-4">
                <p class="font-body text-sm font-semibold text-cafe"><?= e($cert['cert_nombre'] ?: 'Certificado cargado') ?></p>
                <dl class="mt-2 space-y-1 font-body text-xs text-cafe/70">
                    <div class="flex justify-between gap-3"><dt class="text-cafe/45">RUT titular</dt><dd class="font-semibold text-cafe"><?= e($cert['cert_rut'] ?: '—') ?></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-cafe/45">Vence</dt><dd class="font-semibold <?= $certVencido ? 'text-red-600' : ($certPorVencer ? 'text-terracota-dark' : 'text-cafe') ?>"><?= e($cert['cert_vence'] ?: '—') ?><?= $certVencido ? ' (vencido)' : ($certPorVencer ? ' (por vencer)' : '') ?></dd></div>
                </dl>
            </div>
            <p class="mt-3 font-body text-xs text-cafe/50">Para reemplazarlo, sube uno nuevo:</p>
        <?php else: ?>
            <div class="mt-4 rounded-xl border border-terracota/25 bg-terracota/8 p-4 font-body text-sm text-terracota-dark">Aún no hay certificado cargado para esta empresa.</div>
        <?php endif; ?>
        <form id="cert-form" class="mt-3 space-y-3">
            <label class="flex flex-col gap-1"><span class="dlbl">Archivo del certificado (.pfx)</span><input type="file" name="cert" accept=".pfx,.p12" required class="dfile"></label>
            <label class="flex flex-col gap-1"><span class="dlbl">Clave del certificado</span><input type="password" name="cert_pass" autocomplete="off" required class="dinp" placeholder="Contraseña del .pfx"></label>
            <p class="dmsg hidden"></p>
            <button type="submit" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Guardar certificado</button>
        </form>
    </div>

    <!-- CAF / Folios -->
    <div class="rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
        <div class="flex items-center gap-2">
            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-terracota/12 text-terracota-dark"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 12h16M4 17h10"/><rect x="3" y="3" width="18" height="18" rx="2"/></svg></span>
            <div><h3 class="font-heading text-lg font-semibold text-cafe">Folios (CAF)</h3><p class="font-body text-xs text-cafe/50">Archivos CAF del SII por tipo de documento</p></div>
        </div>
        <div class="mt-4 overflow-x-auto ez-tabla-card">
            <table class="ez-tabla">
                <thead><tr>
                    <th class="px-3">Tipo</th><th class="px-3">Rango</th><th class="px-3 text-right">Disp.</th><th class="px-3"></th>
                </tr></thead>
                <tbody class="font-body text-sm text-cafe/80">
                    <?php if (!$cafs): ?>
                        <tr><td colspan="4" class="py-6 text-center text-cafe/45">Aún no hay CAF cargados.</td></tr>
                    <?php else: foreach ($cafs as $c): $agotado = $c['disponibles'] === 0; ?>
                        <tr class="<?= $agotado ? 'acc-off' : '' ?>">
                            <td class="px-3 py-2.5"><span class="font-semibold text-cafe"><?= e(dte_nombre((int) $c['tipo_dte'])) ?></span><br><span class="text-xs text-cafe/45"><?= (int) $c['tipo_dte'] ?></span></td>
                            <td class="px-3 py-2.5 text-xs"><?= (int) $c['folio_desde'] ?>–<?= (int) $c['folio_hasta'] ?><br><span class="text-cafe/45">actual: <?= (int) $c['folio_actual'] ?></span><?php $cv = dte_caf_vence((int) $c['tipo_dte'], $c['fecha_autorizacion'] ?? null); if ($cv): ?><br><span class="<?= $cv <= date('Y-m-d') ? 'font-semibold text-red-600' : 'text-cafe/45' ?>"><?= $cv <= date('Y-m-d') ? 'vencido el ' : 'vence el ' ?><?= e(date('d-m-Y', strtotime($cv))) ?></span><?php endif; ?></td>
                            <td class="px-3 py-2.5 text-right font-semibold <?= $agotado ? 'text-red-600' : 'text-cafe' ?>"><?= (int) $c['disponibles'] ?></td>
                            <td class="px-3 py-2.5 text-right"><button type="button" class="caf-del rounded-lg border border-cafe/15 px-2 py-1 text-xs font-semibold text-cafe/60 hover:border-red-400 hover:text-red-600" data-id="<?= (int) $c['id'] ?>" title="Eliminar CAF">✕</button></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <form id="caf-form" class="mt-4 space-y-3 border-t border-cafe/8 pt-4">
            <label class="flex flex-col gap-1"><span class="dlbl">Subir CAF (.xml del SII)</span><input type="file" name="caf" accept=".xml" required class="dfile"></label>
            <p class="dmsg hidden"></p>
            <button type="submit" class="rounded-full bg-cafe px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-cafe/90">Subir CAF</button>
        </form>
    </div>
</div>

<div class="mt-6 rounded-2xl border border-cafe/8 bg-crema/40 p-5">
    <h3 class="font-heading text-base font-semibold text-cafe">¿Cómo queda lista para emitir?</h3>
    <ol class="mt-2 space-y-1 font-body text-sm text-cafe/70">
        <li class="flex items-center gap-2"><?= $empresa['rut'] ? '✅' : '⬜' ?> RUT de la empresa configurado</li>
        <li class="flex items-center gap-2"><?= $tieneCert ? '✅' : '⬜' ?> Certificado digital cargado y vigente</li>
        <li class="flex items-center gap-2"><?= count($tiposConFolios) > 0 ? '✅' : '⬜' ?> Al menos un CAF con folios disponibles</li>
    </ol>
    <p class="mt-3 font-body text-xs text-cafe/50">El certificado y los CAF deben corresponder al RUT de esta empresa. Cada empresa emite con sus propias credenciales; nunca se combinan entre empresas.</p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.dinp,.dfile').forEach(function (el) { el.className = el.className.replace(/dinp|dfile/, m => m) + ' w-full rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota file:mr-3 file:rounded file:border-0 file:bg-cafe/10 file:px-3 file:py-1 file:text-cafe'; });
    document.querySelectorAll('.dlbl').forEach(function (el) { el.className = 'dlbl font-body text-xs font-semibold uppercase tracking-wide text-cafe/60'; });
    var P = window.__PANEL__ || {};
    var EMP = <?= (int) $empresaId ?>;

    function send(fd, btn) {
        var old = btn.textContent; btn.disabled = true; btn.textContent = 'Procesando…';
        return fetch(P.base + '/api/panel/dte', { method: 'POST', body: fd })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, d: j }; }); })
            .finally(function () { btn.disabled = false; btn.textContent = old; });
    }
    function msg(form, text, ok) {
        var m = form.querySelector('.dmsg');
        m.textContent = text; m.classList.remove('hidden');
        m.className = 'dmsg font-body text-sm font-medium ' + (ok ? 'text-salvia-dark' : 'text-red-600');
    }

    var cf = document.getElementById('cert-form');
    cf.addEventListener('submit', function (e) { e.preventDefault();
        var fd = new FormData(cf); fd.append('_csrf', P.csrf); fd.append('action', 'subir_cert'); fd.append('empresa_id', EMP);
        send(fd, cf.querySelector('button')).then(function (x) {
            if (!x.ok) { msg(cf, x.d.error || 'Error', false); return; }
            msg(cf, 'Certificado guardado. Recargando…', true); setTimeout(function () { location.reload(); }, 800);
        });
    });

    var qf = document.getElementById('caf-form');
    qf.addEventListener('submit', function (e) { e.preventDefault();
        var fd = new FormData(qf); fd.append('_csrf', P.csrf); fd.append('action', 'subir_caf'); fd.append('empresa_id', EMP);
        send(fd, qf.querySelector('button')).then(function (x) {
            if (!x.ok) { msg(qf, x.d.error || 'Error', false); return; }
            msg(qf, 'CAF cargado (tipo ' + (x.d.caf && x.d.caf.tipo) + ', folios ' + (x.d.caf && x.d.caf.desde) + '–' + (x.d.caf && x.d.caf.hasta) + '). Recargando…', true);
            setTimeout(function () { location.reload(); }, 1000);
        });
    });

    // ---- Folios: solicitar al SII y configuración automática ----
    var fmsg = document.getElementById('fol-msg');
    function folMsg(t, ok) { fmsg.textContent = t; fmsg.className = 'mt-3 rounded-lg px-3 py-2 font-body text-sm ' + (ok ? 'bg-salvia/15 text-salvia-dark' : 'bg-red-50 text-red-800'); fmsg.classList.remove('hidden'); fmsg.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    document.querySelectorAll('#folios tr[data-tipo]').forEach(function (tr) {
        var tipo = tr.dataset.tipo, t = null;
        function guardar() {
            clearTimeout(t);
            t = setTimeout(function () {
                var fd = new FormData(); fd.append('_csrf', P.csrf); fd.append('action', 'folios_config'); fd.append('empresa_id', EMP); fd.append('tipo', tipo);
                if (tr.querySelector('.fol-auto').checked) fd.append('auto', '1');
                fd.append('umbral', tr.querySelector('.fol-umbral').value); fd.append('cantidad', tr.querySelector('.fol-cant').value);
                fetch(P.base + '/api/panel/dte', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (j) {
                    if (j && j.ok) { tr.querySelector('.fol-pedir').dataset.cant = j.config.cantidad; folMsg('Configuración guardada para ' + tr.querySelector('.fol-pedir').dataset.nombre + '.', true); }
                    else folMsg((j && j.error) || 'No se pudo guardar.', false);
                });
            }, 500);
        }
        tr.querySelectorAll('.fol-auto, .fol-umbral, .fol-cant').forEach(function (i) { i.addEventListener('change', guardar); });
        var btn = tr.querySelector('.fol-pedir');
        btn.addEventListener('click', function () {
            var max = parseInt(btn.dataset.max, 10) || 0;
            ezPrompt('¿Cuántos folios de ' + btn.dataset.nombre + ' pedir al SII?' + (max ? ' (último máximo informado por el SII: ' + max + ')' : '') + '\nSe solicitan en producción con el certificado de la empresa; si el SII limita la cantidad, el sistema reintenta con lo permitido.',
                { title: 'Solicitar folios al SII', okText: 'Solicitar', value: String(max && max < parseInt(btn.dataset.cant, 10) ? max : btn.dataset.cant) }).then(function (v) {
                if (v === null) return;
                var n = parseInt(String(v).replace(/\D/g, ''), 10);
                if (!n || n < 1) { ezAlert('Indica una cantidad válida.'); return; }
                var fd = new FormData(); fd.append('_csrf', P.csrf); fd.append('action', 'folios_solicitar'); fd.append('empresa_id', EMP); fd.append('tipo', tipo); fd.append('cantidad', n);
                var old = btn.textContent; btn.disabled = true; btn.textContent = 'Consultando al SII…';
                folMsg('Solicitando ' + n + ' folio(s) de ' + btn.dataset.nombre + ' al SII… puede tardar hasta un par de minutos.', true);
                fetch(P.base + '/api/panel/dte', { method: 'POST', body: fd })
                    .then(function (r) { return r.text().then(function (t) { try { return JSON.parse(t); } catch (e) { return { ok: false, error: 'Respuesta inesperada del servidor.' }; } }); })
                    .then(function (j) {
                        btn.disabled = false; btn.textContent = old;
                        if (j.ok) { ezAlert('SII: ' + (j.mensaje || 'Folios autorizados.')).then(function () { location.hash = 'folios'; location.reload(); }); return; }
                        ezAlert('Respuesta del SII: ' + (j.error || 'no autorizó la solicitud.') + (j.maximo ? '\n\nMáximo de folios informado por el SII: ' + j.maximo + '.' : '') + (j.intentos > 1 ? '\n(Se probó ' + j.intentos + ' veces con cantidades menores.)' : ''), { title: 'Solicitud no autorizada' })
                            .then(function () { location.hash = 'folios'; location.reload(); });
                    })
                    .catch(function () { btn.disabled = false; btn.textContent = old; folMsg('Se cortó la conexión. Revisa el historial antes de volver a pedir (el SII pudo haber autorizado).', false); });
            });
        });
    });

    document.querySelectorAll('.caf-del').forEach(function (b) { b.addEventListener('click', function () {
        ezConfirm('¿Eliminar este CAF? Los folios no usados dejarán de estar disponibles.', { title: 'Eliminar CAF', okText: 'Eliminar', danger: true }).then(function (ok) {
            if (!ok) return;
            var fd = new FormData(); fd.append('_csrf', P.csrf); fd.append('action', 'borrar_caf'); fd.append('empresa_id', EMP); fd.append('caf_id', b.dataset.id);
            send(fd, b).then(function (x) { if (!x.ok) { ezAlert(x.d.error || 'No se pudo.'); return; } location.reload(); });
        });
    }); });
});
</script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
