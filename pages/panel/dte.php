<?php
/** Panel · Facturación electrónica (DTE). Estado de credenciales por empresa. Solo admin. */
declare(strict_types=1);

requireAdminPage();
require_once APP_ROOT . '/lib/empresas.php';

$empresas = empresasLista(true);
[$conex, $cfg] = require APP_ROOT . '/dte/bootstrap.php';
require_once APP_ROOT . '/lib/dte_integra.php';
require_once APP_ROOT . '/lib/clientes.php';
$empFactura = facturaEmpresa();
$empFacturaId = $empFactura ? (int) $empFactura['id'] : 0;
// Prueba de conexión con Trivoxa API DTE (no emite nada ni consume folios).
$prueba = isset($_GET['probar']) ? dteProbarConexion() : null;

$panelTitle = 'Facturación DTE';
$panelActive = 'dte';
include APP_ROOT . '/partials/panel/top.php';
?>
<div class="mb-5 space-y-3">
    <p class="max-w-3xl font-body text-sm text-cafe/60">Emisión de boletas, facturas y notas de crédito/débito electrónicas al SII vía <strong>Trivoxa API DTE</strong> (la misma API y el mismo motor con que emite Trivoxa-DTE). Cada empresa emite con su <strong>propio certificado y sus propios folios (CAF)</strong>; las emisiones nunca se combinan entre empresas. Carga aquí las credenciales de cada una.</p>
    <div class="flex max-w-3xl flex-wrap items-center gap-3 rounded-xl border border-cafe/10 bg-white/70 px-4 py-3 font-body text-xs text-cafe/70">
        <span><strong class="text-cafe">Conexión:</strong> <?= e((string) ($cfg['base_url'] ?? '')) ?> · API key <?= dteApiKeyValida($cfg) ? '<strong class="text-salvia-dark">configurada</strong> (' . e(substr(trim((string) $cfg['api_key']), 0, 10)) . '…)' : '<strong class="text-amber-700">pendiente</strong> (TRVX-… de producción)' ?></span>
        <a href="<?= e(base_path('/admin/dte?probar=1')) ?>" class="rounded-full border border-cafe/15 px-3 py-1 font-semibold text-cafe hover:border-terracota hover:text-terracota">Probar conexión</a>
        <?php if ($prueba): ?>
            <span class="w-full rounded-lg px-3 py-2 <?= $prueba['ok'] ? 'bg-salvia/15 text-salvia-dark' : 'bg-amber-50 text-amber-900' ?>"><?= $prueba['ok'] ? '✓ ' : '○ ' ?><?= e($prueba['detalle']) ?></span>
        <?php endif; ?>
    </div>
    <div class="max-w-3xl rounded-xl border border-cafe/10 bg-white/70 px-4 py-3 font-body text-xs leading-relaxed text-cafe/70">
        <strong class="text-cafe">Cómo se activa el SII:</strong> mientras una empresa no tenga certificado y folios (CAF), sus documentos se emiten como <strong>internos</strong> (pendientes de timbrar).
        Apenas se cargan el certificado y el CAF de un tipo, el sistema revisa la lista de abajo: si todo está en regla, ese tipo empieza a enviarse al SII <strong>solo</strong>; si falta algo, no emite y avisa qué completar (así no se pierden folios ni hay rechazos).
        Los documentos internos llevan su propia numeración y no chocan con los folios del SII.
    </div>
</div>

<div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
    <?php foreach ($empresas as $emp):
        $est = dte_empresa_estado($conex, (int) $emp['id']);
        $tieneCert = $est['tiene_cert'];
        $tipos = $est['tipos_activos'] ?? [];
        $listo = $tieneCert && count($tipos) > 0;
        $cert = $est['cert'] ?? null;
        $vencido = $cert && !empty($cert['cert_vence']) && $cert['cert_vence'] < date('Y-m-d');
    ?>
        <div class="flex flex-col rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card <?= (int) $emp['activo'] === 1 ? '' : 'opacity-60' ?>">
            <div class="flex items-start justify-between gap-2">
                <h3 class="font-heading text-lg font-semibold text-cafe"><?= e($emp['razon_social']) ?></h3>
                <?php if ($listo && !$vencido): ?>
                    <span class="shrink-0 rounded-full bg-salvia/15 px-2.5 py-1 font-body text-xs font-bold text-salvia-dark">Lista</span>
                <?php elseif ($vencido): ?>
                    <span class="shrink-0 rounded-full bg-red-100 px-2.5 py-1 font-body text-xs font-bold text-red-600">Cert. vencido</span>
                <?php else: ?>
                    <span class="shrink-0 rounded-full bg-terracota/12 px-2.5 py-1 font-body text-xs font-bold text-terracota-dark">Pendiente</span>
                <?php endif; ?>
            </div>
            <p class="mt-1 font-body text-xs text-cafe/50">RUT <?= e($emp['rut'] ?: '—') ?> · <?= e(tipoDocLabel($emp['tipo_documento'], true)) ?><?php $cE = dteCertificados($emp); if ($cE !== null): ?> · certificada SII: <strong class="text-cafe/70"><?= e(implode(', ', $cE)) ?></strong><?php endif; ?></p>

            <ul class="mt-4 space-y-1.5 font-body text-sm">
                <li class="flex items-center gap-2 <?= $tieneCert ? 'text-cafe/80' : 'text-cafe/45' ?>"><?= $tieneCert ? '✅' : '⬜' ?> Certificado <?= $tieneCert ? 'cargado' : 'pendiente' ?></li>
                <li class="flex items-center gap-2 <?= count($tipos) ? 'text-cafe/80' : 'text-cafe/45' ?>"><?= count($tipos) ? '✅' : '⬜' ?> <?= count($tipos) ? (count($tipos) . ' tipo(s) con folios') : 'Sin CAF con folios' ?></li>
            </ul>
            <?php if ($tipos): ?>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <?php foreach ($tipos as $t): ?><span class="rounded bg-cafe/8 px-2 py-0.5 font-body text-xs font-semibold text-cafe/70"><?= e(dte_nombre((int) $t)) ?></span><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php
            // Lista de verificación por tipo de documento que emite esta empresa.
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
            foreach ($chkTipos as $chkT => $chkL):
                $chk = dteChequeoEmision($emp, $chkT);
                $nFalt = count($chk['faltan']); ?>
                <details class="mt-3 rounded-xl border <?= $chk['listo'] ? 'border-salvia/40 bg-salvia/8' : 'border-cafe/10 bg-white/70' ?>" <?= $chk['listo'] ? '' : 'open' ?>>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-3 py-2 font-body text-sm font-semibold text-cafe">
                        <span><?= e($chkL) ?> <span class="font-normal text-cafe/50">(<?= (int) $chkT ?>)</span></span>
                        <?php if ($chk['listo']): ?><span class="rounded-full bg-salvia/20 px-2 py-0.5 text-[11px] font-bold text-salvia-dark">Listo para el SII</span>
                        <?php else: ?><span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-800"><?= $nFalt ?> pendiente<?= $nFalt === 1 ? '' : 's' ?></span><?php endif; ?>
                    </summary>
                    <ul class="space-y-1.5 border-t border-cafe/8 px-3 py-2.5">
                        <?php foreach ($chk['items'] as $it): ?>
                            <li class="flex gap-2 font-body text-xs leading-snug">
                                <span class="mt-px shrink-0 font-bold <?= $it['ok'] ? 'text-salvia-dark' : 'text-amber-700' ?>"><?= $it['ok'] ? '✓' : '○' ?></span>
                                <span class="min-w-0"><span class="font-semibold <?= $it['ok'] ? 'text-cafe/80' : 'text-cafe' ?>"><?= e($it['label']) ?></span><?php if ($it['detalle'] !== ''): ?><span class="block text-cafe/55"><?= e($it['detalle']) ?></span><?php endif; ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endforeach; ?>

            <a href="<?= e(base_path('/admin/dte/' . (int) $emp['id'])) ?>" class="mt-5 inline-flex items-center justify-center gap-2 rounded-full border border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota hover:text-terracota">
                Gestionar credenciales
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </a>
        </div>
    <?php endforeach; ?>
    <?php if (!$empresas): ?>
        <div class="col-span-full rounded-2xl border border-dashed border-cafe/20 p-8 text-center font-body text-cafe/50">
            No hay empresas. Crea una en <a class="text-terracota-dark underline" href="<?= e(base_path('/admin/empresas')) ?>">Empresas</a> primero.
        </div>
    <?php endif; ?>
</div>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
