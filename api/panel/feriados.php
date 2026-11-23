<?php
/**
 * API del panel · feriados. Requiere sesión + CSRF (header X-CSRF-Token).
 * GET  ?desde=&hasta=            -> feriados del rango con sus reservas vigentes
 * POST action=marcar {fecha, se_trabaja, force?}
 *        -> marca un feriado como trabajado (1) o no trabajado (0). Si se cierra un
 *           día que ya tiene horas, primero avisa (needs_confirm) y no cambia nada.
 * POST action=agregar {fecha, nombre, force?} -> agrega un feriado extra (regional, elecciones, etc.)
 * POST action=quitar {fecha}     -> quita un feriado agregado a mano (los legales no se borran)
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/panel.php';

requireAuthJson();

$method = $_SERVER['REQUEST_METHOD'];

$validaFecha = static function (string $f): bool {
    $d = DateTime::createFromFormat('!Y-m-d', $f);
    return $d !== false && $d->format('Y-m-d') === $f;
};

if ($method === 'GET') {
    $desde = trim((string) ($_GET['desde'] ?? date('Y-m-d')));
    $hasta = trim((string) ($_GET['hasta'] ?? date('Y-m-d', strtotime('+18 months'))));
    if (!$validaFecha($desde) || !$validaFecha($hasta) || $hasta < $desde) {
        json_response(['error' => 'Rango inválido'], 400);
    }
    $out = [];
    foreach (feriadosEntre($desde, $hasta) as $f) {
        $f['domingo'] = esDomingo($f['fecha']);
        $f['fecha_larga'] = fechaLarga($f['fecha']);
        $f['reservas'] = reservasVigentesEnFecha($f['fecha']);
        $out[] = $f;
    }
    json_response(['ok' => true, 'feriados' => $out]);
}

if ($method !== 'POST') {
    json_response(['error' => 'Método no permitido'], 405);
}
if (!csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
}
$body = read_json_body() ?? [];
$action = (string) ($body['action'] ?? '');
$fecha = trim((string) ($body['fecha'] ?? ''));
$force = !empty($body['force']);
$usuario = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;

if (!$validaFecha($fecha)) {
    json_response(['error' => 'Fecha inválida'], 400);
}

/** Aviso cuando se cierra un día que ya tiene horas tomadas. */
$avisoCierre = static function (string $fecha, int $n): array {
    return ['needs_confirm' => true, 'reservas' => $n,
        'mensaje' => 'El ' . fechaLarga($fecha) . ' ya tiene ' . $n . ' hora' . ($n === 1 ? '' : 's') . ' tomada' . ($n === 1 ? '' : 's') . '. '
            . 'Si lo marcas como no trabajado, esas horas NO se borran: quedan en el calendario para que las reagendes o canceles, '
            . 'pero no se podrán tomar horas nuevas ese día.'];
};

if ($action === 'marcar') {
    $f = feriadoDe($fecha);
    if (!$f) {
        json_response(['error' => 'Esa fecha no está registrada como feriado.'], 404);
    }
    if (esDomingo($fecha)) {
        json_response(['error' => 'Ese feriado cae en domingo: el centro está cerrado igual.'], 400);
    }
    $trabaja = !empty($body['se_trabaja']) ? 1 : 0;
    if ($trabaja === 0 && !$force && ($n = reservasVigentesEnFecha($fecha)) > 0) {
        json_response($avisoCierre($fecha, $n));
    }
    try {
        db()->prepare("UPDATE feriados SET se_trabaja = ?, actualizado_por = ? WHERE fecha = ?")->execute([$trabaja, $usuario, $fecha]);
    } catch (Throwable $e) {
        json_response(['error' => 'No se pudo guardar el feriado.'], 500);
    }
    json_response(['ok' => true, 'fecha' => $fecha, 'se_trabaja' => (bool) $trabaja]);
}

if ($action === 'agregar') {
    $nombre = trim((string) ($body['nombre'] ?? ''));
    if ($nombre === '') {
        json_response(['error' => 'Escribe el nombre del feriado.'], 400);
    }
    if (feriadoDe($fecha)) {
        json_response(['error' => 'Esa fecha ya está registrada como feriado.'], 409);
    }
    if (esDomingo($fecha)) {
        json_response(['error' => 'Ese día es domingo: el centro ya está cerrado.'], 400);
    }
    if (!$force && ($n = reservasVigentesEnFecha($fecha)) > 0) {
        json_response($avisoCierre($fecha, $n));
    }
    try {
        db()->prepare("INSERT INTO feriados (fecha, nombre, irrenunciable, se_trabaja, origen, actualizado_por) VALUES (?, ?, 0, 0, 'manual', ?)")
            ->execute([$fecha, mb_substr($nombre, 0, 120, 'UTF-8'), $usuario]);
    } catch (Throwable $e) {
        json_response(['error' => 'No se pudo agregar el feriado.'], 500);
    }
    json_response(['ok' => true]);
}

if ($action === 'quitar') {
    $f = feriadoDe($fecha);
    if (!$f) {
        json_response(['error' => 'Esa fecha no está registrada como feriado.'], 404);
    }
    if ($f['origen'] !== 'manual') {
        json_response(['error' => 'Los feriados legales no se borran: márcalo como trabajado si el centro atiende ese día.'], 400);
    }
    try {
        db()->prepare("DELETE FROM feriados WHERE fecha = ? AND origen = 'manual'")->execute([$fecha]);
    } catch (Throwable $e) {
        json_response(['error' => 'No se pudo quitar el feriado.'], 500);
    }
    json_response(['ok' => true]);
}

json_response(['error' => 'Acción inválida'], 400);
