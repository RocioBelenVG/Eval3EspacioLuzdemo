<?php
/**
 * Panel · Calendario semanal en PDF (para imprimir).
 *   /admin/calendario/pdf?modo=box|prof&sel=<slug|id|todos>&desde=Y-m-d&hasta=Y-m-d[&pac=0][&ver=1]
 * ver=1 lo muestra en el navegador; sin ver se descarga.
 */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/calendario_pdf.php';

$o = calPdfOpciones($_GET);
if (!$o['ok']) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $o['error'];
    return;
}
try {
    $pdf = calPdfBytes($o);
} catch (Throwable $e) {
    error_log('[calendario-pdf] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se pudo generar el PDF del calendario.';
    return;
}

$nombre = $pdf['filename'];
$ascii = preg_replace('/[^A-Za-z0-9 ._()-]/', '', strtr($nombre, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','ñ'=>'n','Ñ'=>'N','ü'=>'u','Ü'=>'U']));
header('Content-Type: application/pdf');
header('Content-Disposition: ' . ((($_GET['ver'] ?? '') === '1') ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
header('Content-Length: ' . strlen($pdf['bytes']));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
echo $pdf['bytes'];
