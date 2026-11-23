<?php
/**
 * API del panel · Clientes (arriendo de boxes) y sus facturas. Requiere sesión + CSRF (POST).
 *   GET  ?action=horas&id=&mes=YYYY-MM   horas tomadas en los boxes por el profesional vinculado
 *   POST action=crear | actualizar | activo | eliminar
 *   POST action=facturar                 emite la factura (solo admin; emisor = Espacio Luz)
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/clientes.php';

requireAuthJson();
if (function_exists('esRolPacientes') && esRolPacientes()) {
    json_response(['error' => 'Tu rol no tiene acceso a Clientes.'], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $action = (string) ($_GET['action'] ?? '');
    if ($action === 'horas') {
        $cli = getCliente((int) ($_GET['id'] ?? 0));
        if (!$cli) {
            json_response(['error' => 'Cliente no encontrado.'], 404);
        }
        $r = clienteHorasBox($cli, (string) ($_GET['mes'] ?? ''));
        json_response($r, $r['ok'] ? 200 : 400);
    }
    // Datos del contribuyente desde el SII (razón social, giros, direcciones) por su RUT.
    if ($action === 'sii_rut') {
        require_once APP_ROOT . '/lib/sii_contribuyente.php';
        @set_time_limit(60);
        json_response(siiConsultarContribuyente((string) ($_GET['rut'] ?? '')));
    }
    json_response(['error' => 'Acción no válida.'], 400);
}

if (!csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
}

$body = read_json_body() ?? [];
$action = (string) ($body['action'] ?? '');
$id = (int) ($body['id'] ?? 0);

switch ($action) {
    case 'crear':
        $r = crearCliente($body);
        json_response($r['ok'] ? ['ok' => true, 'id' => $r['id']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);

    case 'actualizar':
        $r = actualizarCliente($id, $body);
        json_response($r['ok'] ? ['ok' => true, 'id' => $id] : ['error' => $r['error']], $r['ok'] ? 200 : 400);

    case 'activo':
        if (!getCliente($id)) {
            json_response(['error' => 'Cliente no encontrado.'], 404);
        }
        setClienteActivo($id, !empty($body['activo']));
        json_response(['ok' => true]);

    case 'eliminar':
        if (!isAdmin()) {
            json_response(['error' => 'Solo un administrador puede eliminar clientes.'], 403);
        }
        $r = eliminarCliente($id);
        json_response($r['ok'] ? ['ok' => true, 'modo' => $r['modo']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);

    // Notas del cliente (internas), igual que las de pacientes.
    case 'nota_crear':
        $r = crearNotaCliente((int) ($body['cliente_id'] ?? 0), (string) ($body['texto'] ?? ''));
        json_response($r['ok'] ? ['ok' => true, 'nota' => $r['nota']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
    case 'nota_actualizar':
        $r = actualizarNotaCliente($id, (string) ($body['texto'] ?? ''), !empty($body['borrador']));
        json_response($r['ok'] ? ['ok' => true, 'nota' => $r['nota']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
    case 'nota_marcar':
        $r = marcarNotaCliente($id, !empty($body['completada']));
        json_response($r['ok'] ? ['ok' => true, 'nota' => $r['nota']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
    case 'nota_eliminar':
        $r = eliminarNotaCliente($id);
        json_response($r['ok'] ? ['ok' => true] : ['error' => $r['error']], $r['ok'] ? 200 : 400);

    case 'facturar':
        if (!isAdmin()) {
            json_response(['error' => 'Solo un administrador puede emitir facturas.'], 403);
        }
        $r = emitirFacturaCliente($body);
        if (!$r['ok']) {
            json_response(['error' => $r['error'], 'sii' => $r['sii'] ?? false, 'glosa' => $r['glosa'] ?? null], 400);
        }
        json_response($r);
}

json_response(['error' => 'Acción no válida.'], 400);
