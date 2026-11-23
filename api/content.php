<?php
/**
 * API para guardar ediciones de contenido. Reemplaza a app/api/content/route.ts.
 * POST (JSON) { target, value } -> escribe en content/overrides.json (requiere sesión).
 */
declare(strict_types=1);

requireAuthJson();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método no permitido'], 405);
}
if (!csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
}

const MAX_LENGTH = 2000;
$CENTRO_FIELDS = ['nombre', 'heroTitulo', 'heroTexto', 'heroDescripcionExtra', 'footerTexto', 'ctaAgendar', 'ctaBoxes', 'boxElEspacioTitulo', 'boxEquipamientoTitulo', 'descripcion', 'ubicacion', 'telefono', 'email', 'instagram'];
$ATENCION_FIELDS = ['tag', 'titulo', 'desc'];
$ESPECIALIDAD_FIELDS = ['nombre', 'descripcion'];
$PROFESIONAL_FIELDS = ['nombre', 'descripcion', 'enfoque'];
$BOX_FIELDS = ['nombre', 'descripcion', 'publicoObjetivo', 'ubicacion', 'elEspacioDescripcion'];
$SECCION_FIELDS = ['eyebrow', 'titulo', 'descripcion', 'nota'];

$body = read_json_body();
if ($body === null) {
    json_response(['error' => 'JSON inválido'], 400);
}

// Tipografías del sitio (panel → Apariencia): solo admin. Envía { heading, body }.
if (($body['target']['type'] ?? '') === 'fonts') {
    requireAdminJson();
    require_once APP_ROOT . '/partials/fonts.php';
    $ok = ezFontsSave((string) ($body['heading'] ?? ''), (string) ($body['body'] ?? ''));
    json_response($ok ? ['ok' => true] : ['error' => 'Fuente inválida.'], $ok ? 200 : 400);
}

// Listas del perfil de un profesional (chips/areas/formacion/atiende): envían
// la lista completa en `items` (no un texto en `value`).
if (($body['target']['type'] ?? '') === 'profesional-perfil-lista') {
    require_once APP_ROOT . '/lib/profesionales.php';
    $items = $body['items'] ?? [];
    if (!is_array($items)) {
        $items = [];
    }
    $r = actualizarPerfilListaProfesional((int) ($body['target']['id'] ?? 0), (string) ($body['target']['key'] ?? ''), $items);
    if (!$r['ok']) {
        json_response(['error' => $r['error']], 400);
    }
    json_response(['ok' => true]);
}

if (empty($body['target']) || !is_array($body['target']) || !isset($body['value']) || !is_string($body['value'])) {
    json_response(['error' => 'Solicitud incompleta'], 400);
}

$value = mb_substr(trim($body['value']), 0, MAX_LENGTH, 'UTF-8');
if ($value === '') {
    json_response(['error' => 'El texto no puede quedar vacío'], 400);
}

$overrides = readOverrides();
$target = $body['target'];
$type = $target['type'] ?? '';

switch ($type) {
    case 'centro':
        if (!in_array($target['field'] ?? '', $CENTRO_FIELDS, true)) {
            json_response(['error' => 'Campo inválido'], 400);
        }
        $overrides['centro'] = ($overrides['centro'] ?? []);
        $overrides['centro'][$target['field']] = $value;
        break;

    case 'especialidad':
        $exists = getBoxBySlug($GLOBALS['ESPECIALIDADES'], $target['slug'] ?? '') !== null;
        if (!$exists || !in_array($target['field'] ?? '', $ESPECIALIDAD_FIELDS, true)) {
            json_response(['error' => 'Especialidad o campo inválido'], 400);
        }
        $overrides['especialidades'] = $overrides['especialidades'] ?? [];
        $overrides['especialidades'][$target['slug']] = ($overrides['especialidades'][$target['slug']] ?? []);
        $overrides['especialidades'][$target['slug']][$target['field']] = $value;
        break;

    case 'especialidad-beneficio':
        $esp = getBoxBySlug($GLOBALS['ESPECIALIDADES'], $target['slug'] ?? '');
        $idx = $target['index'] ?? -1;
        if ($esp === null || !is_int($idx) || $idx < 0 || $idx >= count($esp['beneficios'])) {
            json_response(['error' => 'Beneficio inválido'], 400);
        }
        $overrides['especialidades'] = $overrides['especialidades'] ?? [];
        $current = $overrides['especialidades'][$target['slug']]['beneficios'] ?? $esp['beneficios'];
        $current[$idx] = $value;
        $overrides['especialidades'][$target['slug']] = ($overrides['especialidades'][$target['slug']] ?? []);
        $overrides['especialidades'][$target['slug']]['beneficios'] = array_values($current);
        break;

    case 'profesional':
        // Edición inline conectada a la BD (fuente única). No usa overrides.
        require_once APP_ROOT . '/lib/profesionales.php';
        $r = actualizarCampoProfesional((int) ($target['id'] ?? 0), (string) ($target['field'] ?? ''), $value);
        if (!$r['ok']) {
            json_response(['error' => $r['error']], 400);
        }
        json_response(['ok' => true, 'value' => $value]);

    case 'profesional-perfil':
        require_once APP_ROOT . '/lib/profesionales.php';
        $r = actualizarPerfilCampoProfesional((int) ($target['id'] ?? 0), (string) ($target['key'] ?? ''), $value);
        if (!$r['ok']) {
            json_response(['error' => $r['error']], 400);
        }
        json_response(['ok' => true, 'value' => $value]);

    // Boxes: se guardan en la tabla `boxes` (la misma que edita el módulo Boxes del panel).
    case 'box':
        $exists = getBoxBySlug($GLOBALS['BOXES'], $target['slug'] ?? '') !== null;
        if (!$exists || !in_array($target['field'] ?? '', $BOX_FIELDS, true)) {
            json_response(['error' => 'Box o campo inválido'], 400);
        }
        require_once APP_ROOT . '/lib/boxes.php';
        $r = boxActualizarCampoSitio((string) $target['slug'], (string) $target['field'], $value);
        json_response($r['ok'] ? ['ok' => true, 'value' => $value] : ['error' => $r['error']], $r['ok'] ? 200 : 400);

    case 'box-equipamiento':
        $idx = $target['index'] ?? -1;
        if (getBoxBySlug($GLOBALS['BOXES'], $target['slug'] ?? '') === null || !is_int($idx)) {
            json_response(['error' => 'Ítem de equipamiento inválido'], 400);
        }
        require_once APP_ROOT . '/lib/boxes.php';
        $r = boxActualizarEquipamientoSitio((string) $target['slug'], $idx, $value);
        json_response($r['ok'] ? ['ok' => true, 'value' => $value] : ['error' => $r['error']], $r['ok'] ? 200 : 400);

    case 'seccion':
        $id = $target['id'] ?? '';
        if (!isset($GLOBALS['SECCIONES'][$id]) || !in_array($target['field'] ?? '', $SECCION_FIELDS, true)) {
            json_response(['error' => 'Sección o campo inválido'], 400);
        }
        $overrides['secciones'] = $overrides['secciones'] ?? [];
        $overrides['secciones'][$id] = ($overrides['secciones'][$id] ?? []);
        $overrides['secciones'][$id][$target['field']] = $value;
        break;

    case 'atencion':
        $idx = $target['index'] ?? -1;
        $total = is_array($GLOBALS['ATENCIONES'] ?? null) ? count($GLOBALS['ATENCIONES']) : 0;
        if (!is_int($idx) || $idx < 0 || $idx >= $total || !in_array($target['field'] ?? '', $ATENCION_FIELDS, true)) {
            json_response(['error' => 'Atención o campo inválido'], 400);
        }
        $overrides['atenciones'] = $overrides['atenciones'] ?? [];
        $overrides['atenciones'][(string) $idx] = ($overrides['atenciones'][(string) $idx] ?? []);
        $overrides['atenciones'][(string) $idx][$target['field']] = $value;
        break;

    case 'pagina':
        $key = (string) ($target['key'] ?? '');
        $field = (string) ($target['field'] ?? '');
        $pags = $GLOBALS['PAGINAS'] ?? [];
        if (!isset($pags[$key]) || !array_key_exists($field, $pags[$key])) {
            json_response(['error' => 'Página o campo inválido'], 400);
        }
        $overrides['paginas'] = $overrides['paginas'] ?? [];
        $overrides['paginas'][$key] = ($overrides['paginas'][$key] ?? []);
        $overrides['paginas'][$key][$field] = $value;
        break;

    default:
        json_response(['error' => 'Tipo de contenido inválido'], 400);
}

if (!writeOverrides($overrides)) {
    json_response(['error' => 'No se pudo guardar en el servidor (disco de solo lectura o sin permisos).'], 500);
}

json_response(['ok' => true, 'value' => $value]);
