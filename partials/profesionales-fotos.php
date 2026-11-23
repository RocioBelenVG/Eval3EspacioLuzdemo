<?php
/**
 * Fotos de profesionales (subidas desde Panel → Profesionales).
 * Se guardan como assets/images/professionals/prof-{id}.{jpg|png|webp}.
 * Complementa a lib/profesionales.php. Vive en partials/ porque lib/ no admite
 * archivos nuevos por FTP (permisos del hosting).
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/profesionales.php';
require_once APP_ROOT . '/lib/images.php';

const PROF_FOTO_DIR = IMAGES_DIR . '/professionals';
const PROF_FOTO_EXTS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

/** Archivo de la foto subida desde el panel (prof-{id}.ext) o null. */
function profesionalFotoArchivo(int $id): ?string
{
    foreach (PROF_FOTO_EXTS as $ext) {
        $f = PROF_FOTO_DIR . "/prof-{$id}.{$ext}";
        if (is_file($f)) {
            return $f;
        }
    }
    return null;
}

/**
 * URL pública de la foto de un profesional (con cache-busting) o null.
 * Prioriza la subida desde el panel; si no hay, usa la foto antigua (columna `foto`).
 */
function profesionalFotoUrl(array $prof): ?string
{
    $file = profesionalFotoArchivo((int) $prof['id']);
    if ($file === null && !empty($prof['foto']) && professionalPhotoExists($prof['foto'])) {
        $file = PROF_FOTO_DIR . '/' . basename($prof['foto']);
    }
    if ($file === null) {
        return null;
    }
    return asset('images/professionals/' . basename($file)) . '?v=' . filemtime($file);
}

/** Guarda la foto subida ($_FILES) de un profesional. Devuelve ['ok'=>bool,'url'|'error']. */
function guardarFotoProfesional(int $id, array $file): array
{
    if (getProfesional($id) === null) {
        return ['ok' => false, 'error' => 'Profesional no encontrado.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'No se recibió la imagen.'];
    }
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'La imagen no puede pesar más de 8 MB.'];
    }
    $mime = mime_content_type($file['tmp_name']) ?: '';
    if (!isset(PROF_FOTO_EXTS[$mime])) {
        return ['ok' => false, 'error' => 'La foto debe ser JPG, PNG o WEBP.'];
    }
    if (!is_dir(PROF_FOTO_DIR) && !@mkdir(PROF_FOTO_DIR, 0775, true)) {
        return ['ok' => false, 'error' => 'No se pudo guardar la imagen.'];
    }
    borrarFotoProfesional($id);
    $dest = PROF_FOTO_DIR . "/prof-{$id}." . PROF_FOTO_EXTS[$mime];
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'No se pudo guardar la imagen en el servidor.'];
    }
    return ['ok' => true, 'url' => profesionalFotoUrl(getProfesional($id))];
}

/** Borra la foto subida desde el panel (la foto antigua, si existe, vuelve a mostrarse). */
function borrarFotoProfesional(int $id): void
{
    foreach (PROF_FOTO_EXTS as $ext) {
        $f = PROF_FOTO_DIR . "/prof-{$id}.{$ext}";
        if (is_file($f)) {
            @unlink($f);
        }
    }
}
