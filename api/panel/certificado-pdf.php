<?php
/**
 * Panel · Descarga del certificado de atención en PDF.
 *   /admin/certificados/{id}/pdf        -> descarga ("Cer.Atencion Nombre.pdf")
 *   /admin/certificados/{id}/pdf?ver=1  -> se abre en el navegador
 * El router define $certId.
 */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/certificados.php';

$c = certGet((int) ($certId ?? 0));
if (!$c) {
    http_response_code(404);
    echo 'Certificado no encontrado.';
    return;
}

try {
    $pdf = certPdfBytes($c);
} catch (Throwable $e) {
    error_log('[cert-pdf] ' . $e->getMessage());
    http_response_code(500);
    echo 'No se pudo generar el PDF.';
    return;
}

$nombre = $pdf['filename'];
// Respaldo ASCII para navegadores antiguos + nombre exacto (con tildes) en UTF-8.
$ascii = preg_replace('/[^A-Za-z0-9 ._()-]/', '', strtr($nombre, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','ñ'=>'n','Ñ'=>'N','ü'=>'u','Ü'=>'U']));
$disp = (($_GET['ver'] ?? '') === '1') ? 'inline' : 'attachment';
header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disp . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
header('Content-Length: ' . strlen($pdf['bytes']));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $pdf['bytes'];
