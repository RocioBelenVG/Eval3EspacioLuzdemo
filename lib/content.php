<?php
/**
 * Sistema de contenidos: fusiona los datos base (data.php) con las ediciones
 * guardadas por el panel de administración (content/overrides.json).
 * Equivale a lib/content.ts.
 */

declare(strict_types=1);

require_once APP_ROOT . '/data.php';
require_once APP_ROOT . '/lib/db.php';

/**
 * Lee las ediciones guardadas desde la tabla `configuracion` (clave 'overrides').
 * Devuelve [] si no hay nada o si la base de datos no está disponible (en ese
 * caso el sitio muestra el contenido base sin romperse).
 */
function readOverrides(): array
{
    try {
        $stmt = db()->prepare("SELECT valor FROM configuracion WHERE clave = 'overrides' LIMIT 1");
        $stmt->execute();
        $raw = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return [];
    }
    if ($raw === false || $raw === null || $raw === '') {
        return [];
    }
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

/** Guarda las ediciones en la tabla `configuracion` (clave 'overrides'). */
function writeOverrides(array $overrides): bool
{
    $json = json_encode($overrides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    try {
        $stmt = db()->prepare(
            "INSERT INTO configuracion (clave, valor)
                  VALUES ('overrides', :valor)
             ON DUPLICATE KEY UPDATE valor = :valor2, actualizado_en = NOW()"
        );
        $stmt->execute([':valor' => $json, ':valor2' => $json]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Devuelve el contenido fusionado: centro, especialidades, profesionales,
 * boxes y secciones, aplicando los overrides sobre los datos base.
 * Equivale a getContent() de lib/content.ts.
 */
function getContent(): array
{
    global $CENTRO, $ESPECIALIDADES, $PROFESIONALES, $BOXES, $SECCIONES, $ATENCIONES, $PAGINAS;
    $ov = readOverrides();

    $centro = array_merge($CENTRO, $ov['centro'] ?? []);

    $especialidades = array_map(function (array $esp) use ($ov) {
        return array_merge($esp, $ov['especialidades'][$esp['slug']] ?? []);
    }, $ESPECIALIDADES);

    $profesionales = array_map(function (array $prof) use ($ov) {
        return array_merge($prof, $ov['profesionales'][(string) $prof['id']] ?? []);
    }, $PROFESIONALES);

    $boxes = array_map(function (array $box) use ($ov) {
        return array_merge($box, $ov['boxes'][$box['slug']] ?? []);
    }, $BOXES);

    $secciones = [];
    foreach ($SECCIONES as $id => $seccion) {
        $secciones[$id] = array_merge($seccion, $ov['secciones'][$id] ?? []);
    }

    $atenciones = [];
    foreach (($ATENCIONES ?? []) as $i => $a) {
        $atenciones[$i] = array_merge($a, $ov['atenciones'][(string) $i] ?? []);
    }

    $paginas = [];
    foreach (($PAGINAS ?? []) as $key => $pag) {
        $paginas[$key] = array_merge($pag, $ov['paginas'][$key] ?? []);
    }

    return compact('centro', 'especialidades', 'profesionales', 'boxes', 'secciones', 'atenciones', 'paginas');
}
