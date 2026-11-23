<?php
/** API del panel · profesionales (solo admin, con CSRF). */
declare(strict_types=1);

require_once APP_ROOT . '/lib/profesionales.php';
require_once APP_ROOT . '/partials/profesionales-fotos.php';

requireAdminJson();
if (!csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
}

// Subida de foto (multipart/form-data: id + file).
if (($_POST['action'] ?? '') === 'foto') {
    $r = guardarFotoProfesional((int) ($_POST['id'] ?? 0), $_FILES['file'] ?? []);
    json_response($r['ok'] ? ['ok' => true, 'url' => $r['url']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
}

// Firma y timbre (multipart/form-data: id + file). Se normaliza a un lienzo fijo.
if (($_POST['action'] ?? '') === 'firma') {
    require_once APP_ROOT . '/lib/firmas.php';
    $r = firmaGuardarProfesional((int) ($_POST['id'] ?? 0), $_FILES['file'] ?? []);
    json_response($r['ok'] ? ['ok' => true, 'url' => $r['url']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
}

$body = read_json_body() ?? [];
$action = $body['action'] ?? '';

if ($action === 'quitar-firma') {
    require_once APP_ROOT . '/lib/firmas.php';
    $id = (int) ($body['id'] ?? 0);
    if (getProfesional($id) === null) {
        json_response(['error' => 'Profesional no encontrado.'], 400);
    }
    json_response(firmaQuitarProfesional($id) ? ['ok' => true] : ['error' => 'No se pudo quitar la firma.'], 200);
}

// Borrar la foto subida de un profesional (vuelve al avatar/foto anterior).
if ($action === 'borrar-foto') {
    $id = (int) ($body['id'] ?? 0);
    if (getProfesional($id) === null) {
        json_response(['error' => 'Profesional no encontrado.'], 400);
    }
    borrarFotoProfesional($id);
    json_response(['ok' => true]);
}

if ($action === 'crear') {
    $r = crearProfesional($body);
    json_response($r['ok'] ? ['ok' => true, 'id' => $r['id'], 'slug' => $r['slug'] ?? null] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
}
if ($action === 'actualizar') {
    $r = actualizarProfesional((int) ($body['id'] ?? 0), $body);
    json_response($r['ok'] ? ['ok' => true] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
}
if ($action === 'publico') {
    db()->prepare("UPDATE profesionales SET publico=? WHERE id=?")->execute([!empty($body['publico']) ? 1 : 0, (int) ($body['id'] ?? 0)]);
    json_response(['ok' => true]);
}
// Socia: emite boletas y facturas y NO paga arriendo de box (deja de estar vinculada a Clientes).
if ($action === 'socia') {
    if (!isAdmin()) {
        json_response(['error' => 'Solo un administrador puede cambiarlo.'], 403);
    }
    $pid = (int) ($body['id'] ?? 0);
    $on = !empty($body['socia']) ? 1 : 0;
    db()->prepare("UPDATE profesionales SET socia = ? WHERE id = ?")->execute([$on, $pid]);
    if ($on) {
        db()->prepare("DELETE FROM clientes_profesionales WHERE profesional_id = ?")->execute([$pid]);
        db()->prepare("UPDATE clientes SET profesional_id = NULL WHERE profesional_id = ?")->execute([$pid]);
    }
    json_response(['ok' => true]);
}
if ($action === 'activo') {
    setProfesionalActivo((int) ($body['id'] ?? 0), !empty($body['activo']));
    json_response(['ok' => true]);
}
if ($action === 'eliminar') {
    $r = eliminarProfesional((int) ($body['id'] ?? 0));
    if ($r['ok'] && $r['modo'] === 'eliminado') {
        borrarFotoProfesional((int) ($body['id'] ?? 0));
    }
    json_response($r['ok'] ? ['ok' => true, 'modo' => $r['modo']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
}
json_response(['error' => 'Acción inválida'], 400);
