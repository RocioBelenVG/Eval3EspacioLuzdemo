<?php
/**
 * API del panel · pacientes, fichas y evoluciones. Requiere sesión + CSRF (POST).
 * GET ?q=  -> búsqueda para autocompletar (agendar con paciente).
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/pacientes.php';

requireAuthJson();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $out = [];
    foreach (pacientesLista($q) as $c) {
        $out[] = ['id' => (int) $c['id'], 'nombre' => $c['nombre'], 'rut' => $c['rut'], 'documento' => pacienteDocumento($c), 'telefono' => $c['telefono'], 'email' => $c['email']];
    }
    json_response(['ok' => true, 'pacientes' => $out]);
}

if (!csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
}

// Subida de un informe clínico (multipart/form-data: paciente_id, tipo, fecha_informe, ficha_id, profesional_id, titulo, descripcion + file).
if (($_POST['action'] ?? '') === 'informe_subir') {
    require_once APP_ROOT . '/lib/informes.php';
    $r = informeSubir((int) ($_POST['paciente_id'] ?? 0), $_POST, $_FILES['file'] ?? []);
    json_response($r['ok'] ? ['ok' => true, 'id' => $r['id']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
}

$body = read_json_body() ?? [];
$action = $body['action'] ?? '';

switch ($action) {
    case 'crear':
        $r = crearPaciente($body);
        json_response($r['ok'] ? ['ok' => true, 'id' => $r['id']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
    case 'actualizar':
        $r = actualizarPaciente((int) ($body['id'] ?? 0), $body);
        json_response($r['ok'] ? ['ok' => true] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
    // Notas del paciente (internas, no clínicas)
    case 'nota_crear':
        $r = crearNotaPaciente((int) ($body['paciente_id'] ?? 0), (string) ($body['texto'] ?? ''));
        json_response($r['ok'] ? ['ok' => true, 'nota' => $r['nota']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
    case 'nota_actualizar':
        $r = actualizarNotaPaciente((int) ($body['id'] ?? 0), (string) ($body['texto'] ?? ''), !empty($body['borrador']));
        json_response($r['ok'] ? ['ok' => true, 'nota' => $r['nota']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
    case 'nota_marcar':
        $r = marcarNotaPaciente((int) ($body['id'] ?? 0), !empty($body['completada']));
        json_response($r['ok'] ? ['ok' => true, 'nota' => $r['nota']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
    case 'nota_eliminar':
        $r = eliminarNotaPaciente((int) ($body['id'] ?? 0));
        json_response($r['ok'] ? ['ok' => true] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
    case 'ficha_crear':
        $r = crearFicha($body);
        json_response($r['ok'] ? ['ok' => true, 'id' => $r['id']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
    case 'ficha_actualizar':
        $r = actualizarFicha((int) ($body['id'] ?? 0), $body);
        json_response($r['ok'] ? ['ok' => true] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
    case 'evolucion_actualizar':
        require_once APP_ROOT . '/lib/ficha_clinica.php';
        $r = actualizarEvolucion((int) ($body['id'] ?? 0), $body);
        json_response($r['ok'] ? ['ok' => true] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
    case 'evolucion_eliminar':
        require_once APP_ROOT . '/lib/ficha_clinica.php';
        $r = eliminarEvolucion((int) ($body['id'] ?? 0));
        json_response($r['ok'] ? ['ok' => true] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
    case 'informe_actualizar':
        require_once APP_ROOT . '/lib/informes.php';
        $r = informeActualizar((int) ($body['id'] ?? 0), $body);
        json_response($r['ok'] ? ['ok' => true] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
    case 'informe_eliminar':
        require_once APP_ROOT . '/lib/informes.php';
        $r = informeEliminar((int) ($body['id'] ?? 0));
        json_response($r['ok'] ? ['ok' => true] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
    case 'evolucion_crear':
        $r = crearEvolucion($body);
        json_response($r['ok'] ? ['ok' => true, 'id' => $r['id']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
}
json_response(['error' => 'Acción inválida'], 400);
