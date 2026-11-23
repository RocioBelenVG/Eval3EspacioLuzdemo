<?php
/** API del panel · empresas emisoras (solo admin, con CSRF). */
declare(strict_types=1);

require_once APP_ROOT . '/lib/empresas.php';

requireAdminJson();
if (!csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
}

$body = read_json_body() ?? [];
$action = $body['action'] ?? '';

if ($action === 'crear') {
    $r = crearEmpresa($body);
    json_response($r['ok'] ? ['ok' => true, 'id' => $r['id']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
}
if ($action === 'actualizar') {
    $r = actualizarEmpresa((int) ($body['id'] ?? 0), $body);
    json_response($r['ok'] ? ['ok' => true] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
}
if ($action === 'activo') {
    setEmpresaActivo((int) ($body['id'] ?? 0), !empty($body['activo']));
    json_response(['ok' => true]);
}
if ($action === 'eliminar') {
    $r = eliminarEmpresa((int) ($body['id'] ?? 0));
    json_response($r['ok'] ? ['ok' => true] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
}
json_response(['error' => 'Acción inválida'], 400);
