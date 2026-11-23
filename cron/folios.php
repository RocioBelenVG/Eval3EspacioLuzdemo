<?php
/**
 * EspacioLuz · Solicitud AUTOMÁTICA de folios (CAF) de producción — SOLO por CLI (cron).
 *   php /home/u195628024/domains/trivoxa.cl/public_html/EspacioLuz/cron/folios.php
 *
 * Para cada empresa activa con certificado y cada tipo de DTE con «automático» activo:
 * si quedan folios <= umbral, pide un lote nuevo al SII (Trivoxa API DTE) y carga el CAF.
 * Reglas de seguridad (igual que Trivoxa-DTE):
 *  - Solo tipos que YA tuvieron un CAF cargado (el primero se pide a mano).
 *  - Solo tipos para los que la empresa está marcada como certificada.
 *  - No reintenta la misma empresa+tipo antes de 20 horas; respeta el tope mensual.
 * Deja el resultado en espacioluz_dte_priv/logs/cron_folios.log y en el historial del panel.
 */
declare(strict_types=1);
// EspacioLuz Demo: no se piden folios al SII (los documentos usan CAF de prueba).
if (PHP_SAPI === "cli") { echo "EspacioLuz Demo: solicitud de folios al SII deshabilitada.\n"; exit(0); }
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
@set_time_limit(1800);
define('EL_CRON', true);
chdir(dirname(__DIR__));
ob_start();
foreach (['config.php', 'lib/db.php', 'lib/helpers.php', 'lib/empresas.php', 'lib/dte_integra.php'] as $f) require_once dirname(__DIR__) . '/' . $f;
ob_end_clean();
date_default_timezone_set('America/Santiago');
[$conex, $cfg] = require dirname(__DIR__) . '/dte/bootstrap.php';
require_once dirname(__DIR__) . '/dte/lib/dte_folios_sii.php';

$log = function (string $l) use ($cfg) {
    $linea = '[' . date('Y-m-d H:i:s') . '] ' . $l . "\n";
    echo $linea;
    $dir = (string) ($cfg['debug_log_dir'] ?? '');
    if ($dir !== '' && is_dir($dir)) @file_put_contents($dir . '/cron_folios.log', $linea, FILE_APPEND);
};

if (!dteApiKeyValida($cfg)) { $log('Sin API key de Trivoxa API DTE: nada que hacer.'); exit; }
$cuota = dte_folios_estado_cuota($conex);
if ($cuota['restantes'] <= 0) { $log('Tope mensual de solicitudes alcanzado.'); exit; }

$hechos = 0; $errores = 0; $omitidos = 0;
foreach (empresasLista(false) as $emp) {
    $eid = (int) $emp['id'];
    if (!dte_empresa_tiene_cert($conex, $eid)) continue;
    foreach (dte_folios_resumen($conex, $eid) as $f) {
        $c = $f['config'];
        // Pide cuando quedan pocos, se agotaron, o el CAF vigente vence pronto / ya venció (33/56/61 vencen a los 6 meses).
        if ((int) $c['auto'] !== 1 || !$f['ya_tuvo_caf'] || !in_array($f['estado'], ['bajo', 'agotado', 'vencido', 'por_vencer'], true)) continue;
        if (!dteEmpresaCertificada($emp, (int) $f['tipo'])) continue;
        if (dte_folios_cooldown_activo($conex, $eid, (int) $f['tipo'])) { $omitidos++; continue; }
        $cant = (int) $c['cantidad'];
        if (!empty($c['maximo_sii']) && (int) $c['maximo_sii'] < $cant) $cant = (int) $c['maximo_sii'];
        $log("{$emp['razon_social']} · {$f['nombre']} ({$f['tipo']}): quedan {$f['disponibles']} vigentes (umbral {$c['umbral']})" . ($f['vence'] ? ", el CAF vence el {$f['vence']}" : '') . ($f['vencidos'] ? ", {$f['vencidos']} vencidos por anular" : '') . "; se piden {$cant}.");
        $r = dte_folios_solicitar_auto($conex, $cfg, $eid, (int) $f['tipo'], $cant, 'auto');
        if (!empty($r['ok'])) { $hechos++; $log('  OK: ' . $r['mensaje']); }
        else { $errores++; $log('  SII: ' . ($r['error'] ?? 'sin detalle')); }
    }
}
$log("Fin: {$hechos} con folios nuevos, {$errores} sin autorizar, {$omitidos} en espera (ya se intentó hace poco).");
