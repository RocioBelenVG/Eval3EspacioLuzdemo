<?php
/**
 * Firma y timbre de cada profesional (una sola imagen).
 *
 * Al subirla se normaliza para que TODAS se vean iguales en los documentos:
 *  1) Si la imagen no trae transparencia (foto/escaneo sobre papel), el color
 *     del papel se vuelve transparente conservando la tinta y el timbre.
 *  2) Se recortan los márgenes vacíos.
 *  3) Se centra a lo ancho y se APOYA EN LA BASE de un lienzo fijo de
 *     FIRMA_W × FIRMA_H (PNG transparente), para que todas queden justo sobre
 *     la línea de firma del documento, a la misma distancia.
 *
 * Los archivos viven en storage/firmas (sin acceso web): se muestran en el
 * panel por /admin/firmas/<archivo> (con sesión) y se incrustan en los PDF.
 * Nunca se sobrescriben: cada subida crea un archivo nuevo, así los
 * certificados ya emitidos conservan la firma con la que se emitieron.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';

// Lienzo fijo de alta resolución, proporción 5:3 (la de una firma con su timbre/datos).
// En el certificado se dibuja en un recuadro fijo de 400×240 px (≈ 10,6 × 6,4 cm): cada firma
// se escala hasta llenarlo, así TODAS salen del mismo tamaño y a ~470 ppp al imprimir.
const FIRMA_W = 2000;
const FIRMA_H = 1200;
const FIRMA_PAD = 24;
const FIRMA_MAX_BYTES = 10 * 1024 * 1024;
const FIRMA_MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

function firmaDir(): string
{
    return APP_ROOT . '/storage/firmas';
}

/** Ruta absoluta de un archivo de firma válido y existente, o null. */
function firmaRuta(?string $archivo): ?string
{
    $archivo = (string) $archivo;
    if (!preg_match('/^prof-\d+-[0-9a-f]{12}\.png$/', $archivo)) {
        return null;
    }
    $p = firmaDir() . '/' . $archivo;
    return is_file($p) ? $p : null;
}

/** URL del panel que sirve la imagen (requiere sesión). */
function firmaUrl(?string $archivo): ?string
{
    return firmaRuta($archivo) ? base_path('/admin/firmas/' . $archivo) : null;
}

/** Imagen como data URI (para dompdf). */
function firmaDataUri(?string $archivo): string
{
    $p = firmaRuta($archivo);
    $d = $p ? @file_get_contents($p) : false;
    return $d === false || $d === null ? '' : 'data:image/png;base64,' . base64_encode($d);
}

/** Carga la imagen subida como truecolor con canal alfa. */
function firmaCargar(string $path, string $mime)
{
    $im = match ($mime) {
        'image/png' => @imagecreatefrompng($path),
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        'image/gif' => @imagecreatefromgif($path),
        default => false,
    };
    if (!$im) {
        return false;
    }
    // Orientación EXIF de fotos de celular.
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $o = (int) ($exif['Orientation'] ?? 1);
        if ($o === 3) $im = imagerotate($im, 180, 0);
        elseif ($o === 6) $im = imagerotate($im, -90, 0);
        elseif ($o === 8) $im = imagerotate($im, 90, 0);
    }
    $w = imagesx($im);
    $h = imagesy($im);
    // Trabajar a un tamaño razonable (máx. 1200 px de lado) para que sea rápido.
    $esc = min(1, 1200 / max($w, $h));
    $nw = max(1, (int) round($w * $esc));
    $nh = max(1, (int) round($h * $esc));
    $tc = imagecreatetruecolor($nw, $nh);
    imagealphablending($tc, false);
    imagesavealpha($tc, true);
    imagefill($tc, 0, 0, imagecolorallocatealpha($tc, 255, 255, 255, 127));
    imagecopyresampled($tc, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($im);
    return $tc;
}

/** ¿La imagen ya trae transparencia real? (>3 % de píxeles transparentes). */
function firmaTieneTransparencia($im): bool
{
    $w = imagesx($im); $h = imagesy($im);
    $paso = max(1, (int) floor(sqrt($w * $h / 40000)));
    $tot = 0; $tr = 0;
    for ($y = 0; $y < $h; $y += $paso) {
        for ($x = 0; $x < $w; $x += $paso) {
            $tot++;
            if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) >= 64) $tr++;
        }
    }
    return $tot > 0 && $tr / $tot > 0.03;
}

/** Vuelve transparente el color del papel (estimado en los bordes). */
function firmaQuitarFondo($im): void
{
    $w = imagesx($im); $h = imagesy($im);
    // Color del papel: mediana de los píxeles del borde.
    $rs = []; $gs = []; $bs = [];
    $muestra = function ($x, $y) use ($im, &$rs, &$gs, &$bs) {
        $c = imagecolorat($im, $x, $y);
        $rs[] = ($c >> 16) & 255; $gs[] = ($c >> 8) & 255; $bs[] = $c & 255;
    };
    $px = max(1, (int) ($w / 60)); $py = max(1, (int) ($h / 60));
    for ($x = 0; $x < $w; $x += $px) { $muestra($x, 0); $muestra($x, $h - 1); $muestra($x, (int) min($h - 1, 3)); }
    for ($y = 0; $y < $h; $y += $py) { $muestra(0, $y); $muestra($w - 1, $y); $muestra((int) min($w - 1, 3), $y); }
    sort($rs); sort($gs); sort($bs);
    $m = (int) (count($rs) / 2);
    [$br, $bg, $bb] = [$rs[$m], $gs[$m], $bs[$m]];
    $bLum = 0.299 * $br + 0.587 * $bg + 0.114 * $bb;

    imagealphablending($im, false);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
            $d = sqrt(($r - $br) ** 2 + ($g - $bg) ** 2 + ($b - $bb) ** 2);
            $lum = 0.299 * $r + 0.587 * $g + 0.114 * $b;
            $sat = max($r, $g, $b) - min($r, $g, $b);
            // Papel con sombras: claro, poco saturado y cercano al tono del fondo.
            $esPapel = $sat < 28 && $lum > max(150, $bLum - 45);
            if ($d < 38 || $esPapel) {
                $a = 127;
            } elseif ($d < 90) {
                $a = (int) round(127 * (1 - ($d - 38) / 52)); // borde suave
            } else {
                $a = 0;
            }
            if ($a > 0) {
                imagesetpixel($im, $x, $y, imagecolorallocatealpha($im, $r, $g, $b, $a));
            }
        }
    }
}

/** Caja que contiene la tinta (píxeles casi opacos), o null si está vacía. */
function firmaCajaContenido($im): ?array
{
    $w = imagesx($im); $h = imagesy($im);
    $x0 = $w; $y0 = $h; $x1 = -1; $y1 = -1;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) < 90) {
                if ($x < $x0) $x0 = $x;
                if ($x > $x1) $x1 = $x;
                if ($y < $y0) $y0 = $y;
                if ($y > $y1) $y1 = $y;
            }
        }
    }
    if ($x1 < 0) return null;
    return [$x0, $y0, $x1 - $x0 + 1, $y1 - $y0 + 1];
}

/**
 * Normaliza una imagen subida. Devuelve los bytes PNG del lienzo fijo.
 * @return array{ok:bool, png?:string, error?:string}
 */
function firmaProcesar(string $path, string $mime): array
{
    $im = firmaCargar($path, $mime);
    if (!$im) {
        return ['ok' => false, 'error' => 'No se pudo leer la imagen. Usa PNG, JPG o WEBP.'];
    }
    if (!firmaTieneTransparencia($im)) {
        firmaQuitarFondo($im);
    }
    $caja = firmaCajaContenido($im);
    if (!$caja || $caja[2] < 12 || $caja[3] < 8) {
        imagedestroy($im);
        return ['ok' => false, 'error' => 'No se distingue la firma en la imagen. Sube una foto nítida sobre fondo claro (ideal PNG con fondo transparente).'];
    }
    [$cx, $cy, $cw, $ch] = $caja;
    $maxW = FIRMA_W - 2 * FIRMA_PAD;
    $maxH = FIRMA_H - 2 * FIRMA_PAD;
    $esc = min($maxW / $cw, $maxH / $ch, 4.0);
    $dw = max(1, (int) round($cw * $esc));
    $dh = max(1, (int) round($ch * $esc));

    $lienzo = imagecreatetruecolor(FIRMA_W, FIRMA_H);
    imagealphablending($lienzo, false);
    imagesavealpha($lienzo, true);
    imagefill($lienzo, 0, 0, imagecolorallocatealpha($lienzo, 255, 255, 255, 127));
    imagecopyresampled($lienzo, $im, (int) ((FIRMA_W - $dw) / 2), FIRMA_H - FIRMA_PAD - $dh, $cx, $cy, $dw, $dh, $cw, $ch);
    imagedestroy($im);

    ob_start();
    imagepng($lienzo, null, 6);
    $png = (string) ob_get_clean();
    imagedestroy($lienzo);
    return ['ok' => true, 'png' => $png];
}

/** ¿Algún certificado usa este archivo? (en ese caso no se borra). */
function firmaEnUso(string $archivo): bool
{
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM certificados_atencion WHERE firma_archivo = ?");
        $st->execute([$archivo]);
        return (int) $st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return true; // ante la duda, conservar
    }
}

/** Borra un archivo de firma si ya nadie lo usa. */
function firmaLimpiar(?string $archivo): void
{
    $p = firmaRuta($archivo);
    if ($p && !firmaEnUso((string) $archivo)) {
        @unlink($p);
    }
}

/**
 * Sube y asigna la firma de un profesional.
 * @return array{ok:bool, archivo?:string, url?:string, error?:string}
 */
function firmaGuardarProfesional(int $profId, array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        return ['ok' => false, 'error' => 'No llegó la imagen. Inténtalo de nuevo.'];
    }
    return firmaGuardarDesdeArchivo($profId, (string) $file['tmp_name']);
}

/**
 * Valida, normaliza y asigna una firma desde un archivo local ya recibido.
 * @return array{ok:bool, archivo?:string, url?:string, error?:string}
 */
function firmaGuardarDesdeArchivo(int $profId, string $tmp): array
{
    try {
        $st = db()->prepare("SELECT id, firma_archivo FROM profesionales WHERE id = ?");
        $st->execute([$profId]);
        $prof = $st->fetch();
    } catch (Throwable $e) {
        $prof = null;
    }
    if (!$prof) {
        return ['ok' => false, 'error' => 'Profesional no encontrado.'];
    }
    if (!is_file($tmp)) {
        return ['ok' => false, 'error' => 'No llegó la imagen. Inténtalo de nuevo.'];
    }
    if ((int) filesize($tmp) > FIRMA_MAX_BYTES) {
        return ['ok' => false, 'error' => 'La imagen supera los 10 MB.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    if (!in_array($mime, FIRMA_MIMES, true)) {
        return ['ok' => false, 'error' => 'Formato no válido. Sube un PNG (ideal, con fondo transparente), JPG o WEBP.'];
    }
    $file = ['tmp_name' => $tmp];
    $dim = @getimagesize($file['tmp_name']);
    if (!$dim || $dim[0] < 60 || $dim[1] < 30) {
        return ['ok' => false, 'error' => 'La imagen es demasiado pequeña.'];
    }
    if ($dim[0] * $dim[1] > 40000000) {
        return ['ok' => false, 'error' => 'La imagen es demasiado grande (más de 40 megapíxeles).'];
    }

    @set_time_limit(60);
    $r = firmaProcesar($file['tmp_name'], $mime);
    if (!$r['ok']) {
        return $r;
    }
    if (!is_dir(firmaDir()) && !@mkdir(firmaDir(), 0750, true)) {
        return ['ok' => false, 'error' => 'No se pudo preparar la carpeta de firmas.'];
    }
    $archivo = 'prof-' . $profId . '-' . bin2hex(random_bytes(6)) . '.png';
    if (@file_put_contents(firmaDir() . '/' . $archivo, $r['png']) === false) {
        return ['ok' => false, 'error' => 'No se pudo guardar la firma.'];
    }
    $anterior = $prof['firma_archivo'] ?? null;
    db()->prepare("UPDATE profesionales SET firma_archivo = ?, firma_actualizada = NOW() WHERE id = ?")->execute([$archivo, $profId]);
    if ($anterior && $anterior !== $archivo) {
        firmaLimpiar($anterior);
    }
    return ['ok' => true, 'archivo' => $archivo, 'url' => firmaUrl($archivo)];
}

/** Quita la firma de un profesional (los certificados ya emitidos la conservan). */
function firmaQuitarProfesional(int $profId): bool
{
    try {
        $st = db()->prepare("SELECT firma_archivo FROM profesionales WHERE id = ?");
        $st->execute([$profId]);
        $anterior = $st->fetchColumn();
        db()->prepare("UPDATE profesionales SET firma_archivo = NULL, firma_actualizada = NOW() WHERE id = ?")->execute([$profId]);
        if ($anterior) {
            firmaLimpiar((string) $anterior);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Firma vigente de un profesional (nombre de archivo) o null. */
function firmaDeProfesional(?int $profId): ?string
{
    if (!$profId) return null;
    try {
        $st = db()->prepare("SELECT firma_archivo FROM profesionales WHERE id = ?");
        $st->execute([$profId]);
        $a = $st->fetchColumn();
        return firmaRuta($a ?: null) ? (string) $a : null;
    } catch (Throwable $e) {
        return null;
    }
}
