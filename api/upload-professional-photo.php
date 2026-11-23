<?php
/**
 * Subida/cambio de la foto de un profesional (edición inline).
 * POST multipart: id + file. Requiere sesión + CSRF.
 * Guarda un archivo nuevo prof-<id>-<rand>.<ext>, actualiza profesionales.foto
 * y borra la foto anterior solo si era una subida propia.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/profesionales.php';

requireAuthJson();
if (!csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método no permitido'], 405);
}

const MAX_SIZE = 8 * 1024 * 1024; // 8 MB
$EXT_BY_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

$id = (int) ($_POST['id'] ?? 0);
$file = $_FILES['file'] ?? null;

$prof = $id > 0 ? getProfesional($id) : null;
if (!$prof) {
    json_response(['error' => 'Profesional no encontrado'], 404);
}
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['error' => 'Solicitud incompleta'], 400);
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

$dir = IMAGES_DIR . '/professionals';
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    json_response(['error' => 'No se pudo guardar la imagen'], 500);
}
$fname = 'prof-' . $id . '-' . bin2hex(random_bytes(4)) . '.' . $EXT_BY_MIME[$mime];
if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $fname)) {
    json_response(['error' => 'No se pudo guardar la imagen'], 500);
}
@chmod($dir . '/' . $fname, 0644);

$old = basename((string) ($prof['foto'] ?? ''));
if (!actualizarFotoProfesional($id, $fname)) {
    @unlink($dir . '/' . $fname);
    json_response(['error' => 'No se pudo actualizar la foto'], 500);
}
// Borra la foto anterior solo si era una subida propia (no toca las de catálogo).
if ($old !== '' && preg_match('/^prof-\d+-[0-9a-f]{8}\.(jpg|png|webp)$/', $old)) {
    @unlink($dir . '/' . $old);
}

json_response(['ok' => true]);
