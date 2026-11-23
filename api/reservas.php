<?php
/**
 * API de reservas de boxes. Persistencia en MySQL (lib/reservas.php).
 * GET  ?box=&fecha=  -> horas ocupadas
 * POST (JSON)        -> crea una reserva
 */
declare(strict_types=1);

$HORAS_VALIDAS = [
    '08:00', '09:00', '10:00', '11:00', '12:00', '13:00',
    '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00',
];

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $boxSlug = $_GET['box'] ?? null;
    $fecha = $_GET['fecha'] ?? null;
    if (!$boxSlug || !$fecha) {
        json_response(['error' => 'Faltan parámetros'], 400);
    }
    require_once APP_ROOT . '/lib/feriados.php';
    $cerrado = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fecha) ? diaCerradoMotivo((string) $fecha) : null;
    json_response(['occupiedHours' => reservaHorasOcupadas($boxSlug, $fecha),
        'cerrado' => $cerrado !== null ? (esDomingo((string) $fecha) ? 'Los domingos no atendemos. Elige otro día.' : 'Ese día es feriado y no atendemos. Elige otro día.') : null]);
}

if ($method === 'POST') {
    $body = read_json_body();
    if ($body === null) {
        json_response(['error' => 'Solicitud inválida'], 400);
    }

    $boxSlug = $body['boxSlug'] ?? null;
    $modalidadLabel = $body['modalidadLabel'] ?? null;
    $precio = $body['precio'] ?? null;
    $fecha = $body['fecha'] ?? null;
    $hora = $body['hora'] ?? null;
    $nombre = $body['nombre'] ?? null;
    $email = $body['email'] ?? null;
    $telefono = $body['telefono'] ?? null;

    if (!$boxSlug || !$modalidadLabel || !$precio || !$fecha || !$nombre || !$email || !$telefono) {
        json_response(['error' => 'Faltan datos obligatorios'], 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['error' => 'El correo no es válido'], 400);
    }

    $box = getBoxBySlug($GLOBALS['BOXES'], $boxSlug);
    if ($box === null) {
        json_response(['error' => 'Espacio no encontrado'], 400);
    }

    $modalidadValida = false;
    foreach ($box['tarifas'] as $t) {
        if ($t['label'] === $modalidadLabel && $t['precio'] === $precio) {
            $modalidadValida = true;
            break;
        }
    }
    if (!$modalidadValida) {
        json_response(['error' => 'Modalidad inválida'], 400);
    }

    if ($fecha < date('Y-m-d')) {
        json_response(['error' => 'La fecha no puede ser en el pasado'], 400);
    }

    if ($hora && !in_array($hora, $HORAS_VALIDAS, true)) {
        json_response(['error' => 'Horario inválido'], 400);
    }

    require_once APP_ROOT . '/lib/feriados.php';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fecha)) {
        json_response(['error' => 'Fecha inválida'], 400);
    }
    if (diaCerradoMotivo((string) $fecha) !== null) {
        json_response(['error' => esDomingo((string) $fecha) ? 'Los domingos no atendemos. Elige otro día.' : (feriadoDe((string) $fecha) ? 'Ese día es feriado y no atendemos. Elige otro día.' : 'Ese día no atendemos. Elige otro día.')], 409);
    }
    if ($hora && horaFueraHorarioMotivo((string) $fecha, (string) $hora) !== null) {
        json_response(['error' => 'Ese horario está fuera de nuestro horario de atención. Elige otra hora.'], 409);
    }

    $res = crearReserva([
        'boxSlug' => $boxSlug,
        'modalidadLabel' => $modalidadLabel,
        'modalidadSub' => $body['modalidadSub'] ?? null,
        'precio' => $precio,
        'fecha' => $fecha,
        'hora' => $hora,
        'nombre' => $nombre,
        'email' => $email,
        'telefono' => $telefono,
    ]);

    if (!$res['ok']) {
        json_response(['error' => $res['error']], $res['status']);
    }
    json_response(['ok' => true, 'id' => $res['id']]);
}

json_response(['error' => 'Método no permitido'], 405);
