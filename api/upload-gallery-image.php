<?php
/**
 * Galería de un box: subir o quitar fotos adicionales.
 * Reemplaza a app/api/content/upload-gallery-image/route.ts.
 * POST multipart: boxSlug + file  |  DELETE (JSON): { boxSlug, path }
 * (Requiere sesión.)
 */
declare(strict_types=1);

requireAuthJson();
if (!csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
}

const MAX_SIZE = 8 * 1024 * 1024; // 8 MB
$EXT_BY_MIME = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Guardar ORDEN de la galería (JSON: { action:'orden', boxSlug, orden:[nombres] }).
    $json = read_json_body();
    if (is_array($json) && ($json['action'] ?? '') === 'orden') {
        $slug = $json['boxSlug'] ?? '';
        if (!is_string($slug) || getBoxBySlug($GLOBALS['BOXES'], $slug) === null) {
            json_response(['error' => 'Espacio no encontrado'], 400);
        }
        $orden = is_array($json['orden'] ?? null) ? $json['orden'] : [];
        json_response(guardarOrdenGaleria($slug, $orden) ? ['ok' => true] : ['error' => 'No se pudo guardar el orden'], 200);
    }

    $boxSlug = $_POST['boxSlug'] ?? null;
    $file = $_FILES['file'] ?? null;

    if (!is_string($boxSlug) || !$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['error' => 'Solicitud incompleta'], 400);
    }
    if (getBoxBySlug($GLOBALS['BOXES'], $boxSlug) === null) {
        json_response(['error' => 'Espacio no encontrado'], 400);
    }
    // Foto PRINCIPAL del box (hero): se guarda aparte de la galería.
    if (($_POST['main'] ?? '') === '1') {
        $r = guardarFotoBoxPrincipal($boxSlug, $file);
        json_response($r['ok'] ? ['ok' => true] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
    }
    $mime = mime_content_type($file['tmp_name']) ?: '';
    if (!isset($EXT_BY_MIME[$mime])) {
        json_response(['error' => 'Formato no admitido. Usa una imagen JPG, PNG o WebP.'], 400);
    }
    if (@getimagesize($file['tmp_name']) === false) {
        json_response(['error' => 'El archivo no es una imagen válida.'], 400);
    }
    if (($file['size'] ?? 0) > MAX_SIZE) {
        json_response(['error' => 'La imagen no puede pesar más de 8 MB'], 400);
    }

    $ext = $EXT_BY_MIME[$mime] ?? 'jpg';
    $dir = galleryDir($boxSlug);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        json_response(['error' => 'No se pudo guardar la imagen'], 500);
    }
    $filename = round(microtime(true) * 1000) . '.' . $ext;
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        json_response(['error' => 'No se pudo guardar la imagen'], 500);
    }
    json_response(['ok' => true, 'path' => galleryPublicPrefix($boxSlug) . $filename]);
}

if ($method === 'DELETE') {
    $body = read_json_body();
    $boxSlug = $body['boxSlug'] ?? null;
    $imgPath = $body['path'] ?? null;

    // Borrar la foto PRINCIPAL (vuelve a la de por defecto).
    if (is_string($boxSlug) && !empty($body['main']) && getBoxBySlug($GLOBALS['BOXES'], $boxSlug) !== null) {
        borrarFotoBoxPrincipal($boxSlug);
        json_response(['ok' => true]);
    }

    if (!$boxSlug || !$imgPath || getBoxBySlug($GLOBALS['BOXES'], $boxSlug) === null) {
        json_response(['error' => 'Solicitud inválida'], 400);
    }

    // El nombre debe ser hijo directo de la carpeta de ese box (sin "/" ni "..").
    $cleanPath = explode('?', $imgPath)[0];
    $prefix = galleryPublicPrefix($boxSlug);
    if (!str_starts_with($cleanPath, $prefix)) {
        json_response(['error' => 'Ruta inválida'], 400);
    }
    $filename = substr($cleanPath, strlen($prefix));
    if ($filename === '' || str_contains($filename, '/') || str_contains($filename, '..')) {
        json_response(['error' => 'Ruta inválida'], 400);
    }
    if (!@unlink(galleryDir($boxSlug) . '/' . $filename)) {
        json_response(['error' => 'No se pudo eliminar la imagen'], 500);
    }
    json_response(['ok' => true]);
}

json_response(['error' => 'Método no permitido'], 405);
