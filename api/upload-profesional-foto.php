<?php
/**
 * Cambia la foto de un profesional desde el modo edición del sitio.
 * POST multipart: slug + file. Guarda en assets/images/professionals/{slug}.jpg
 * (que es de donde la lee la tarjeta). Requiere sesión.
 */
declare(strict_types=1);

requireAuthJson();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método no permitido'], 405);
}

const MAX_SIZE = 8 * 1024 * 1024; // 8 MB

$slug = $_POST['slug'] ?? '';
$file = $_FILES['file'] ?? null;

if (!is_string($slug) || $slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)
    || !$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['error' => 'Solicitud incompleta'], 400);
}

$mime = mime_content_type($file['tmp_name']) ?: '';
if (!str_starts_with($mime, 'image/')) {
    json_response(['error' => 'El archivo debe ser una imagen'], 400);
}
if (($file['size'] ?? 0) > MAX_SIZE) {
    json_response(['error' => 'La imagen no puede pesar más de 8 MB'], 400);
}

$dir = IMAGES_DIR . '/professionals';
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    json_response(['error' => 'No se pudo guardar la imagen'], 500);
}

$dest = $dir . '/' . $slug . '.jpg';
if (!@move_uploaded_file($file['tmp_name'], $dest)) {
    json_response(['error' => 'No se pudo guardar la imagen en el servidor'], 500);
}

json_response(['ok' => true]);
