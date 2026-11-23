<?php
/**
 * API del panel · certificados de atención.
 * POST action=guardar  {id?, paciente_id, ...}  -> crea o actualiza. Devuelve {ok,id}.
 * POST action=eliminar {id}                    -> elimina (solo admin).
 * Requiere sesión + CSRF (header X-CSRF-Token).
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/certificados.php';

requireAuthJson();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['error' => 'Método no permitido'], 405);
}
if (!csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
}

$body = read_json_body() ?? [];
$action = $body['action'] ?? '';

if ($action === 'guardar') {
    $r = certGuardar($body, (int) ($body['id'] ?? 0));
    json_response($r['ok'] ? ['ok' => true, 'id' => $r['id']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
}

if ($action === 'eliminar') {
    if (!isAdmin()) {
        json_response(['error' => 'Solo un administrador puede eliminar certificados.'], 403);
    }
    $c = certGet((int) ($body['id'] ?? 0));
    if (!$c) {
        json_response(['error' => 'Certificado no encontrado.'], 404);
    }
    if (!certEliminar((int) $c['id'])) {
        json_response(['error' => 'No se pudo eliminar.'], 500);
    }
    json_response(['ok' => true, 'paciente_id' => (int) $c['paciente_id']]);
}

json_response(['error' => 'Acción inválida'], 400);
