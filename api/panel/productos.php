<?php
/** API del panel · productos por empresa (solo admin, con CSRF). */
declare(strict_types=1);

require_once APP_ROOT . '/lib/productos.php';

requireAdminJson();
if (!csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
}

$body = read_json_body() ?? [];
$action = $body['action'] ?? '';

if ($action === 'crear') {
    $r = crearProducto($body);
    json_response($r['ok'] ? ['ok' => true, 'id' => $r['id']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
}
if ($action === 'actualizar') {
    $r = actualizarProducto((int) ($body['id'] ?? 0), $body);
    json_response($r['ok'] ? ['ok' => true] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
}
if ($action === 'activo') {
    $st = db()->prepare("UPDATE productos SET activo=? WHERE id=?");
    $st->execute([!empty($body['activo']) ? 1 : 0, (int) ($body['id'] ?? 0)]);
    json_response(['ok' => true]);
}
if ($action === 'eliminar') {
    json_response(['ok' => eliminarProducto((int) ($body['id'] ?? 0))]);
}
json_response(['error' => 'Acción inválida'], 400);
