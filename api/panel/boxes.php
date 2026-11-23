<?php
/**
 * API del panel · Boxes (solo admin). Requiere sesión + CSRF.
 * POST action=crear | actualizar | publico | activo | eliminar | restaurar
 * La foto principal se sube con /api/content/upload-gallery-image (main=1), igual que en el sitio.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/boxes.php';

requireAuthJson();
if (!isAdmin()) {
    json_response(['error' => 'Solo un administrador puede gestionar los boxes.'], 403);
}
if (!csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
}
$body = read_json_body() ?? [];
$id = (int) ($body['id'] ?? 0);

switch ((string) ($body['action'] ?? '')) {
    case 'crear':
        $r = crearBox($body);
        break;
    case 'actualizar':
        $r = actualizarBox($id, $body);
        break;
    case 'publico':
        $r = boxSetFlag($id, 'publico', !empty($body['valor']));
        break;
    case 'activo':
        $r = boxSetFlag($id, 'activo', !empty($body['valor']));
        break;
    case 'eliminar':
        $r = eliminarBox($id);
        break;
    case 'restaurar':
        $r = restaurarBox($id);
        break;
    default:
        json_response(['error' => 'Acción no válida.'], 400);
}
json_response($r['ok'] ? $r : ['error' => $r['error']], $r['ok'] ? 200 : 400);
