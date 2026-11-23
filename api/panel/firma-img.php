<?php
/**
 * Panel · Imagen de firma y timbre de un profesional (solo con sesión).
 *   /admin/firmas/<archivo>.png   (el router define $firmaArchivo)
 * Las firmas viven en storage/firmas, que no es accesible por la web.
 */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/firmas.php';

$p = firmaRuta($firmaArchivo ?? '');
if (!$p) {
    http_response_code(404);
    echo 'Firma no encontrada.';
    return;
}
header('Content-Type: image/png');
header('Content-Length: ' . filesize($p));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($p);
