<?php
/**
 * Slots de imágenes editables y galería de fotos de cada box.
 * Combina lib/image-slots.ts y lib/box-gallery.ts.
 *
 * Cada slot['path'] es relativo a assets/images (sin barra inicial).
 */

declare(strict_types=1);

const IMAGE_SLOTS = [
    'hero-bg' => [
        'path' => 'hero/hero-bg.jpg',
        'recommendedSize' => '1920×1200 px',
    ],
    'especialidad-fonoaudiologia' => [
        'path' => 'specialties/fonoaudiologia.jpg',
        'recommendedSize' => '960×400 px',
    ],
    'especialidad-terapia-ocupacional' => [
        'path' => 'specialties/terapia-ocupacional.jpg',
        'recommendedSize' => '960×400 px',
    ],
    'especialidad-psicologia' => [
        'path' => 'specialties/psicologia.jpg',
        'recommendedSize' => '960×400 px',
    ],
    'profesional-1' => [
        'path' => 'professionals/maria-gonzalez.jpg',
        'recommendedSize' => '960×1200 px',
    ],
    'profesional-2' => [
        'path' => 'professionals/carlos-lopez.jpg',
        'recommendedSize' => '960×1200 px',
    ],
    'profesional-3' => [
        'path' => 'professionals/ana-martinez.jpg',
        'recommendedSize' => '960×1200 px',
    ],
];

/**
 * URL pública de un slot con cache-busting por fecha de modificación.
 * Equivale a getImageSrc de lib/image-slots.ts.
 */
function getImageSrc(string $slotKey): string
{
    if (!isset(IMAGE_SLOTS[$slotKey])) {
        return '';
    }
    $rel = IMAGE_SLOTS[$slotKey]['path'];
    $full = IMAGES_DIR . '/' . $rel;
    $url = asset('images/' . $rel);
    if (is_file($full)) {
        return $url . '?v=' . round(filemtime($full) * 1000);
    }
    return $url;
}

/** URL de una imagen del catálogo (box.imagen viene como /images/...). */
function imageUrl(string $siteRelative): string
{
    // Los datos guardan rutas como "/images/boxes/preview/loica.jpg".
    $clean = ltrim($siteRelative, '/');            // images/boxes/preview/loica.jpg
    return asset($clean);
}

/** ¿Existe físicamente la foto de un profesional? */
function professionalPhotoExists(string $foto): bool
{
    $basename = basename($foto);
    return $basename !== '' && is_file(IMAGES_DIR . '/professionals/' . $basename);
}

/**
 * URL pública de la foto de un profesional, tomada de la columna `foto`
 * (fuente única), con cache-busting. Devuelve '' si no hay archivo.
 * Reemplaza el uso de getImageSrc('profesional-N') (slots fijos 1-3).
 */
function professionalPhotoUrl(array $prof): string
{
    $foto = trim((string) ($prof['foto'] ?? ''));
    if ($foto === '') {
        return '';
    }
    $base = basename($foto);
    $full = IMAGES_DIR . '/professionals/' . $base;
    if (!is_file($full)) {
        return '';
    }
    return asset('images/professionals/' . $base) . '?v=' . round(filemtime($full) * 1000);
}

/** Carpeta física de la galería de un box. */
function galleryDir(string $slug): string
{
    return IMAGES_DIR . '/boxes/gallery/' . $slug;
}

/** Prefijo público (relativo al sitio) de la galería de un box. */
function galleryPublicPrefix(string $slug): string
{
    return '/images/boxes/gallery/' . $slug . '/';
}

/**
 * Lista las fotos adicionales de un box con cache-busting.
 * Devuelve rutas relativas al sitio ("/images/boxes/gallery/slug/x.jpg?v=..").
 * Equivale a listGalleryImages de lib/box-gallery.ts.
 */
function listGalleryImages(string $slug): array
{
    $dir = galleryDir($slug);
    if (!is_dir($dir)) {
        return [];
    }
    $files = scandir($dir) ?: [];
    $files = array_values(array_filter($files, function ($f) {
        return preg_match('/\.(jpe?g|png|webp|gif)$/i', $f) === 1;
    }));
    $files = ordenarGaleria($slug, $files);
    $prefix = galleryPublicPrefix($slug);
    return array_map(function ($f) use ($dir, $prefix) {
        $version = round(filemtime($dir . '/' . $f) * 1000);
        return $prefix . $f . '?v=' . $version;
    }, $files);
}

/** Ruta del manifiesto de orden de la galería de un box. */
function galleryOrderFile(string $slug): string
{
    return galleryDir($slug) . '/orden.json';
}

/**
 * Ordena los archivos de la galería según orden.json: primero los del
 * manifiesto (en ese orden) que aún existan, luego el resto alfabético.
 * Así el orden guardado manda y las fotos nuevas aparecen al final.
 */
function ordenarGaleria(string $slug, array $files): array
{
    $orden = [];
    $of = galleryOrderFile($slug);
    if (is_file($of)) {
        $data = json_decode((string) @file_get_contents($of), true);
        if (is_array($data)) {
            $orden = $data;
        }
    }
    $set = array_flip($files);
    $ordered = [];
    foreach ($orden as $f) {
        if (is_string($f) && isset($set[$f])) {
            $ordered[] = $f;
            unset($set[$f]);
        }
    }
    $rest = array_keys($set);
    sort($rest);
    return array_merge($ordered, $rest);
}

/** Guarda el orden de la galería (lista de nombres de archivo) en orden.json. */
function guardarOrdenGaleria(string $slug, array $orden): bool
{
    $dir = galleryDir($slug);
    if (!is_dir($dir)) {
        return false;
    }
    $limpio = [];
    foreach ($orden as $f) {
        $name = basename(explode('?', (string) $f)[0]);
        if ($name !== '' && !str_contains($name, '/') && !str_contains($name, '..')
            && preg_match('/\.(jpe?g|png|webp|gif)$/i', $name)) {
            $limpio[] = $name;
        }
    }
    return @file_put_contents($dir . '/orden.json', json_encode(array_values($limpio), JSON_UNESCAPED_SLASHES)) !== false;
}

// --- Foto PRINCIPAL del box (la del hero). Subible/eliminable, separada de la
//     galería. Si hay una subida se usa; si no, la imagen por defecto de data.php. ---

const BOX_MAIN_EXTS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

/** Carpeta física de las fotos principales subidas. */
function boxMainDir(): string
{
    return IMAGES_DIR . '/boxes/main';
}

/** Archivo de la foto principal subida de un box (slug.ext) o null. */
function boxMainFile(string $slug): ?string
{
    foreach (BOX_MAIN_EXTS as $ext) {
        $f = boxMainDir() . "/{$slug}.{$ext}";
        if (is_file($f)) {
            return $f;
        }
    }
    return null;
}

/**
 * URL pública de la foto principal SUBIDA del box, o '' si aún no tiene.
 * No hay foto de reemplazo: un box sin foto subida no muestra ninguna foto
 * (antes se usaba la de data.php/boxes.imagen, que podía ser la de otro box).
 */
function boxMainImage(array $box): string
{
    $file = boxMainFile($box['slug']);
    if ($file !== null) {
        return asset('images/boxes/main/' . basename($file)) . '?v=' . round(filemtime($file) * 1000);
    }
    return '';
}

/** ¿El box tiene una foto principal subida (distinta de la de por defecto)? */
function boxHasMainUpload(string $slug): bool
{
    return boxMainFile($slug) !== null;
}

/** Borra la foto principal subida (el box queda sin foto). */
function borrarFotoBoxPrincipal(string $slug): void
{
    foreach (BOX_MAIN_EXTS as $ext) {
        $f = boxMainDir() . "/{$slug}.{$ext}";
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

/** Guarda la foto principal subida ($_FILES) de un box. */
function guardarFotoBoxPrincipal(string $slug, array $file): array
{
    if (getBoxBySlug($GLOBALS['BOXES'], $slug) === null) {
        return ['ok' => false, 'error' => 'Espacio no encontrado.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'No se recibió la imagen.'];
    }
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'La imagen no puede pesar más de 8 MB.'];
    }
    $mime = mime_content_type($file['tmp_name']) ?: '';
    if (!isset(BOX_MAIN_EXTS[$mime])) {
        return ['ok' => false, 'error' => 'La foto debe ser JPG, PNG o WEBP.'];
    }
    if (@getimagesize($file['tmp_name']) === false) {
        return ['ok' => false, 'error' => 'El archivo no es una imagen válida.'];
    }
    $dir = boxMainDir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'No se pudo guardar la imagen.'];
    }
    borrarFotoBoxPrincipal($slug);
    $dest = $dir . "/{$slug}." . BOX_MAIN_EXTS[$mime];
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'No se pudo guardar la imagen en el servidor.'];
    }
    return ['ok' => true];
}
