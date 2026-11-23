<?php
/**
 * Subida de la imagen de un slot. Reemplaza a app/api/content/upload-image/route.ts.
 * POST multipart: slot + file (requiere sesión).
 */
declare(strict_types=1);

requireAuthJson();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método no permitido'], 405);
}
if (!csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
}

const MAX_SIZE = 8 * 1024 * 1024; // 8 MB
const MIMES_OK = ['image/jpeg', 'image/png', 'image/webp'];

$slotKey = $_POST['slot'] ?? null;
$file = $_FILES['file'] ?? null;

if (!is_string($slotKey) || !$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['error' => 'Solicitud incompleta'], 400);
}
if (!isset(IMAGE_SLOTS[$slotKey])) {
    json_response(['error' => 'Imagen no reconocida'], 400);
}

$mime = mime_content_type($file['tmp_name']) ?: '';
if (!in_array($mime, MIMES_OK, true)) {
    json_response(['error' => 'Formato no admitido. Usa una imagen JPG, PNG o WebP.'], 400);
}
// Confirmar que es una imagen real (rechaza SVG y archivos disfrazados).
if (@getimagesize($file['tmp_name']) === false) {
    json_response(['error' => 'El archivo no es una imagen válida.'], 400);
}
if (($file['size'] ?? 0) > MAX_SIZE) {
    json_response(['error' => 'La imagen no puede pesar más de 8 MB'], 400);
}

$dest = IMAGES_DIR . '/' . IMAGE_SLOTS[$slotKey]['path'];
if (!@move_uploaded_file($file['tmp_name'], $dest)) {
    json_response(['error' => 'No se pudo guardar la imagen en el servidor'], 500);
}

json_response(['ok' => true]);
