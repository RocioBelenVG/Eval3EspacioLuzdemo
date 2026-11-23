<?php
/**
 * Panel · PDF de un documento en 3 formatos (carta / 80mm / 58mm). Solo admin.
 *   /admin/dte/pdf?boleta=<id boletas>&f=carta|80|58
 *   /admin/dte/pdf?doc=<id dte_documentos>&f=...
 * Si el documento tiene DTE real asociado -> PDF con Timbre PDF417; si es
 * interno (MVP) -> mismo diseño sin timbre y con la nota de pendiente.
 * Añade &dl=1 para forzar la descarga.
 */
declare(strict_types=1);

requireAdminPage();

[$conex, $cfg] = require APP_ROOT . '/dte/bootstrap.php';
require_once APP_ROOT . '/dte/lib/dte_pdf.php';

// Formato de impresión.
$fmap = ['carta' => 'carta', '80' => '80mm', '80mm' => '80mm', '58' => '58mm', '58mm' => '58mm'];
$formato = $fmap[(string) ($_GET['f'] ?? 'carta')] ?? 'carta';

$docId    = (int) ($_GET['doc'] ?? 0);
$boletaId = (int) ($_GET['boleta'] ?? 0);

// Si viene una boleta, intenta resolver su DTE real asociado.
if ($docId <= 0 && $boletaId > 0) {
    $st = $conex->prepare("SELECT id FROM dte_documentos WHERE boleta_id = ? ORDER BY id DESC LIMIT 1");
    $st->bind_param('i', $boletaId); $st->execute();
    $docId = (int) ($st->get_result()->fetch_assoc()['id'] ?? 0); $st->close();
}

try {
    $pdf = null;
    if ($docId > 0) {
        // DTE real (con timbre).
        $pdf = dte_pdf_bytes($conex, $docId, $formato);
    } elseif ($boletaId > 0) {
        // Documento interno MVP (sin timbre).
        require_once APP_ROOT . '/lib/boleta_pdf.php';
        $b = getBoleta($boletaId);
        if ($b) $pdf = boleta_pdf_bytes($b, $formato);
    }
} catch (Throwable $e) {
    error_log('[dte-pdf] ' . $e->getMessage());
    http_response_code(500);
    echo 'No se pudo generar el PDF.';
    return;
}

if (!$pdf) {
    http_response_code(404);
    echo 'Documento no encontrado.';
    return;
}

$disp = (($_GET['dl'] ?? '') === '1') ? 'attachment' : 'inline';
header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disp . '; filename="' . $pdf['filename'] . '"');
header('Content-Length: ' . strlen($pdf['bytes']));
header('Cache-Control: private, no-store');
echo $pdf['bytes'];
