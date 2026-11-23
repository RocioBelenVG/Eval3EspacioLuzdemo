<?php
/**
 * Tipografías del sitio (seleccionables desde el panel → Apariencia).
 * Las fuentes ya están tokenizadas como variables CSS (--font-heading/body/script),
 * así que cambiar la elección cambia TODO el sitio. Aquí vive el catálogo curado,
 * el guardado/lectura en la tabla `configuracion` (clave 'fonts') y el helper que
 * genera el <link> de Google Fonts + las variables para el <head>.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';

/** Catálogo curado para TÍTULOS (serif/display). key => [label, css, google]. */
const EZ_FONTS_HEADING = [
    'playfair'        => ['label' => 'Playfair Display',    'css' => "'Playfair Display', Georgia, serif",   'google' => 'Playfair+Display:wght@500;600;700'],
    'cormorant'       => ['label' => 'Cormorant Garamond',  'css' => "'Cormorant Garamond', Georgia, serif", 'google' => 'Cormorant+Garamond:wght@500;600;700'],
    'lora'            => ['label' => 'Lora',                 'css' => "'Lora', Georgia, serif",               'google' => 'Lora:wght@500;600;700'],
    'fraunces'        => ['label' => 'Fraunces',             'css' => "'Fraunces', Georgia, serif",           'google' => 'Fraunces:wght@500;600;700'],
    'marcellus'       => ['label' => 'Marcellus',           'css' => "'Marcellus', Georgia, serif",          'google' => 'Marcellus'],
    'librebaskerville'=> ['label' => 'Libre Baskerville',   'css' => "'Libre Baskerville', Georgia, serif",  'google' => 'Libre+Baskerville:wght@400;700'],
    'dmserif'         => ['label' => 'DM Serif Display',     'css' => "'DM Serif Display', Georgia, serif",   'google' => 'DM+Serif+Display'],
];

/** Catálogo curado para TEXTO y botones (sans). */
const EZ_FONTS_BODY = [
    'nunito'   => ['label' => 'Nunito',     'css' => "'Nunito', system-ui, sans-serif",     'google' => 'Nunito:wght@400;500;600;700;800'],
    'inter'    => ['label' => 'Inter',      'css' => "'Inter', system-ui, sans-serif",      'google' => 'Inter:wght@400;500;600;700'],
    'poppins'  => ['label' => 'Poppins',    'css' => "'Poppins', system-ui, sans-serif",    'google' => 'Poppins:wght@400;500;600;700'],
    'lato'     => ['label' => 'Lato',       'css' => "'Lato', system-ui, sans-serif",       'google' => 'Lato:wght@400;700'],
    'worksans' => ['label' => 'Work Sans',  'css' => "'Work Sans', system-ui, sans-serif",  'google' => 'Work+Sans:wght@400;500;600;700'],
    'mulish'   => ['label' => 'Mulish',     'css' => "'Mulish', system-ui, sans-serif",     'google' => 'Mulish:wght@400;500;600;700;800'],
    'quicksand'=> ['label' => 'Quicksand',  'css' => "'Quicksand', system-ui, sans-serif",  'google' => 'Quicksand:wght@400;500;600;700'],
];

/** Devuelve las claves elegidas ['heading'=>key,'body'=>key] con valores por defecto. */
function ezFontsSaved(): array
{
    $d = [];
    try {
        $stmt = db()->prepare("SELECT valor FROM configuracion WHERE clave = 'fonts' LIMIT 1");
        $stmt->execute();
        $raw = $stmt->fetchColumn();
        if ($raw) {
            $tmp = json_decode((string) $raw, true);
            if (is_array($tmp)) {
                $d = $tmp;
            }
        }
    } catch (Throwable $e) {
        $d = [];
    }
    $h = (isset($d['heading']) && isset(EZ_FONTS_HEADING[$d['heading']])) ? $d['heading'] : 'playfair';
    $b = (isset($d['body']) && isset(EZ_FONTS_BODY[$d['body']])) ? $d['body'] : 'nunito';
    return ['heading' => $h, 'body' => $b];
}

/** Guarda la elección. Devuelve true si las claves son válidas y se pudo guardar. */
function ezFontsSave(string $heading, string $body): bool
{
    if (!isset(EZ_FONTS_HEADING[$heading]) || !isset(EZ_FONTS_BODY[$body])) {
        return false;
    }
    $json = json_encode(['heading' => $heading, 'body' => $body]);
    try {
        db()->prepare(
            "INSERT INTO configuracion (clave, valor) VALUES ('fonts', :v)
             ON DUPLICATE KEY UPDATE valor = :v2, actualizado_en = NOW()"
        )->execute([':v' => $json, ':v2' => $json]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * HTML para el <head>: preconnect + <link> de Google Fonts de las fuentes elegidas
 * (más Alex Brush para las iniciales) + <style> con las variables CSS.
 */
function ezFontsHead(): string
{
    $f = ezFontsSaved();
    $h = EZ_FONTS_HEADING[$f['heading']];
    $b = EZ_FONTS_BODY[$f['body']];
    $specs = array_values(array_unique([$h['google'], $b['google'], 'Alex+Brush']));
    $href = 'https://fonts.googleapis.com/css2?family=' . implode('&family=', $specs) . '&display=swap';
    $css = ":root{--font-heading:{$h['css']};--font-body:{$b['css']};--font-script:'Alex Brush',cursive;}";
    return '<link rel="preconnect" href="https://fonts.googleapis.com">'
         . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
         . '<link href="' . e($href) . '" rel="stylesheet">'
         . '<style>' . $css . '</style>';
}
