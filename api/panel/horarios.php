<?php
/**
 * API del panel · Días y horarios (solo admin, con CSRF).
 *   action=centro      dias={1..6:{abierto,ini,fin}}            [force=1]
 *   action=profesional id, franjas={1..6:[{ini,fin},...]}       [force=1]
 * Cambiar un horario NO cancela horas ya tomadas: si alguna queda fuera, se avisa
 * y se pide confirmación (needs_confirm). Esas horas siguen vigentes.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/panel.php';
require_once APP_ROOT . '/lib/horario.php';

requireAdminJson();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['error' => 'Método no permitido'], 405);
}
if (!csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
}

$body = read_json_body() ?? [];
$action = (string) ($body['action'] ?? '');
$force = !empty($body['force']);
$usuario = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;

if ($action === 'centro') {
    $dias = is_array($body['dias'] ?? null) ? $body['dias'] : [];
    if (!$force) {
        $af = horarioCentroReservasAfectadas($dias);
        if ($af['n'] > 0) {
            json_response(['needs_confirm' => true,
                'mensaje' => 'Con este horario, ' . $af['n'] . ($af['n'] === 1 ? ' hora ya tomada queda' : ' horas ya tomadas quedan') . ' fuera del horario de atención:'
                    . "\n• " . implode("\n• ", $af['muestra']) . ($af['n'] > count($af['muestra']) ? "\n• …" : '')
                    . "\n\nNo se cancelan: siguen vigentes y conviene reagendarlas o avisar a los pacientes. ¿Guardar igual?"]);
        }
    }
    $r = horarioCentroGuardar($dias, $usuario);
    json_response($r['ok'] ? ['ok' => true, 'resumen' => horarioCentroResumen()] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
}

if ($action === 'profesional') {
    $pid = (int) ($body['id'] ?? 0);
    $franjas = is_array($body['franjas'] ?? null) ? $body['franjas'] : [];
    if (!$force) {
        $n = horarioProfesionalReservasAfectadas($pid, $franjas);
        if ($n > 0) {
            json_response(['needs_confirm' => true,
                'mensaje' => nombreProfesional($pid) . ' tiene ' . $n . ($n === 1 ? ' hora ya agendada' : ' horas ya agendadas') . ' desde hoy que quedarían fuera de este horario. No se cancelan: siguen vigentes. ¿Guardar igual?']);
        }
    }
    $r = horarioProfesionalGuardar($pid, $franjas);
    json_response($r['ok'] ? ['ok' => true, 'resumen' => horarioProfesionalResumen($pid)] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
}

json_response(['error' => 'Acción inválida'], 400);
