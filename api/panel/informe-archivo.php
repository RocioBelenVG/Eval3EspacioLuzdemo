<?php
/**
 * Panel · Archivo de un informe clínico (solo con sesión).
 *   /admin/pacientes/{id}/informes/{informeId}          -> descarga
 *   /admin/pacientes/{id}/informes/{informeId}?ver=1    -> se abre en el navegador (PDF o imagen)
 * El router define $pacienteId e $informeId.
 */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/informes.php';

$inf = getInforme((int) ($informeId ?? 0));
$ruta = $inf ? informeRuta($inf) : null;
if (!$inf || (int) $inf['paciente_id'] !== (int) ($pacienteId ?? 0) || !$ruta) {
    http_response_code(404);
    echo 'Informe no encontrado.';
    return;
}

$ext = strtolower(pathinfo((string) $inf['archivo'], PATHINFO_EXTENSION));
$nombre = (string) $inf['nombre_original'];
if (strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== $ext && !($ext === 'jpg' && preg_match('/\.jpe?g$/i', $nombre))) {
    $nombre .= '.' . $ext;
}
$ascii = preg_replace('/[^A-Za-z0-9 ._()-]/', '', strtr($nombre, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','ñ'=>'n','Ñ'=>'N','ü'=>'u','Ü'=>'U'])) ?: 'informe.' . $ext;
$disp = (($_GET['ver'] ?? '') === '1' && informeVisible($inf)) ? 'inline' : 'attachment';

header('Content-Type: ' . (INFORME_MIME_SALIDA[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: ' . $disp . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
header('Content-Length: ' . filesize($ruta));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
readfile($ruta);
