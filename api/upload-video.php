<?php
/**
 * Subida/cambio del video de la sección "Quiénes somos" (POST multipart: file).
 * Guarda el mp4 en assets/videos/ con un nombre único y registra el nombre en
 * los overrides (paginas.quienes-somos.video). Requiere sesión; el rol Pacientes
 * queda bloqueado antes de llegar aquí por la ruta /api/content/*.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/content.php';

requireAuthJson();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método no permitido'], 405);
}
if (!csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
}

const VIDEO_MAX_SIZE = 60 * 1024 * 1024; // 60 MB

$file = $_FILES['file'] ?? null;
$err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
if (!$file || $err !== UPLOAD_ERR_OK) {
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        json_response(['error' => 'El video supera el tamaño permitido por el servidor.'], 400);
    }
    json_response(['error' => 'No se recibió el archivo.'], 400);
}

$ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
$mime = mime_content_type($file['tmp_name']) ?: '';
$mimeOk = $mime === 'video/mp4' || (str_starts_with($mime, 'video/') && $ext === 'mp4');
if ($ext !== 'mp4' || !$mimeOk) {
    json_response(['error' => 'Formato no admitido. Sube un video MP4.'], 400);
}
if (($file['size'] ?? 0) > VIDEO_MAX_SIZE) {
    json_response(['error' => 'El video no puede pesar más de 60 MB.'], 400);
}

$dir = ASSETS_DIR . '/videos';
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}

$nombre = 'quienes-somos-' . date('Ymd-His') . '.mp4';
$dest = $dir . '/' . $nombre;
if (!@move_uploaded_file($file['tmp_name'], $dest)) {
    json_response(['error' => 'No se pudo guardar el video en el servidor.'], 500);
}

// Registra el nuevo video en los overrides.
$overrides = readOverrides();
$anterior = $overrides['paginas']['quienes-somos']['video'] ?? null;
$overrides['paginas'] = $overrides['paginas'] ?? [];
$overrides['paginas']['quienes-somos'] = ($overrides['paginas']['quienes-somos'] ?? []);
$overrides['paginas']['quienes-somos']['video'] = $nombre;
if (!writeOverrides($overrides)) {
    @unlink($dest);
    json_response(['error' => 'No se pudo guardar la configuración del video.'], 500);
}

// Limpia la subida anterior (solo las subidas, nunca los originales del repo).
if (is_string($anterior) && $anterior !== $nombre && str_starts_with($anterior, 'quienes-somos-') && is_file($dir . '/' . $anterior)) {
    @unlink($dir . '/' . $anterior);
}

json_response(['ok' => true, 'src' => asset('videos/' . $nombre)]);
