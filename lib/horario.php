<?php
/**
 * Días y horarios de atención («Días y horarios» en el panel).
 *
 *  - Horario del centro (tabla `horario_centro`): para cada día de lunes a sábado,
 *    si el centro atiende y desde/hasta qué hora. Fuera de ese horario no se
 *    pueden tomar horas (límite duro, igual que un domingo o un feriado no trabajado).
 *    El domingo el centro no atiende nunca (también lo exige la base de datos).
 *  - Horario de cada profesional (tabla `disponibilidad`): qué días y en qué franjas
 *    atiende. Agendarla fuera de su horario no se bloquea: el sistema avisa y pide
 *    confirmación (para excepciones puntuales). Una profesional sin horario definido
 *    puede agendarse en todo el horario del centro.
 *
 * Las horas de agenda son bloques de 1 hora (horasAgenda(): 08:00 a 20:00, el último
 * termina a las 21:00). El horario del centro solo puede ACOTAR esos bloques.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';

const HORARIO_INI_MIN = '08:00';
const HORARIO_FIN_MAX = '21:00';
const HORARIO_DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
const HORARIO_DIAS_PLURAL = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábados', 7 => 'domingos'];
const HORARIO_DIAS_CORTO = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];

/** Bloques de 1 hora posibles (08:00 … 20:00). */
function horarioBloques(): array
{
    return ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00'];
}

/** Día de la semana ISO (1 = lunes … 7 = domingo) de una fecha Y-m-d. */
function horarioDow(string $fecha): int
{
    $t = strtotime($fecha . ' 12:00:00');
    return $t === false ? 0 : (int) date('N', $t);
}

/**
 * Horario del centro: [1..7 => ['abierto' => bool, 'ini' => 'HH:MM', 'fin' => 'HH:MM']].
 * Si la tabla no existe o falta un día, se usa el horario histórico (lun–sáb 08:00–21:00).
 */
function horarioCentro(bool $refrescar = false): array
{
    static $cache = null;
    if ($cache !== null && !$refrescar) {
        return $cache;
    }
    $out = [];
    for ($d = 1; $d <= 7; $d++) {
        $out[$d] = ['abierto' => $d <= 6, 'ini' => HORARIO_INI_MIN, 'fin' => HORARIO_FIN_MAX];
    }
    try {
        foreach (db()->query("SELECT dia_semana, abierto, TIME_FORMAT(hora_inicio,'%H:%i') AS ini, TIME_FORMAT(hora_fin,'%H:%i') AS fin FROM horario_centro") as $r) {
            $d = (int) $r['dia_semana'];
            if ($d >= 1 && $d <= 6) {
                $out[$d] = ['abierto' => (int) $r['abierto'] === 1, 'ini' => (string) $r['ini'], 'fin' => (string) $r['fin']];
            }
        }
    } catch (Throwable $e) {
        // tabla aún no creada: horario histórico
    }
    $out[7]['abierto'] = false;   // domingo: siempre cerrado
    return $cache = $out;
}

/** ¿El centro atiende ese día de la semana? */
function horarioDiaAbierto(int $dow): bool
{
    return (bool) (horarioCentro()[$dow]['abierto'] ?? false);
}

/** Bloques de 1 hora en que atiende el centro ese día de la semana ([] si está cerrado). */
function horarioBloquesDow(int $dow): array
{
    $h = horarioCentro()[$dow] ?? null;
    if (!$h || !$h['abierto']) {
        return [];
    }
    return array_values(array_filter(horarioBloques(), static fn($b) => $b >= $h['ini'] && $b < $h['fin']));
}

/** Bloques de 1 hora en que atiende el centro en una fecha (sin considerar feriados). */
function horasDelDia(string $fecha): array
{
    return horarioBloquesDow(horarioDow($fecha));
}

/** «08:00 a 21:00» del día, o null si está cerrado. */
function horarioTextoDia(int $dow): ?string
{
    $h = horarioCentro()[$dow] ?? null;
    return $h && $h['abierto'] ? $h['ini'] . ' a ' . $h['fin'] : null;
}

/** Resumen del horario del centro: «Lun a Vie 08:00 a 21:00 · Sáb 09:00 a 14:00». */
function horarioCentroResumen(): string
{
    $grupos = [];
    foreach (horarioCentro() as $d => $h) {
        $t = $h['abierto'] ? $h['ini'] . ' a ' . $h['fin'] : null;
        if ($t === null) continue;
        $u = count($grupos) - 1;
        if ($u >= 0 && $grupos[$u]['t'] === $t && $grupos[$u]['hasta'] === $d - 1) {
            $grupos[$u]['hasta'] = $d;
        } else {
            $grupos[] = ['desde' => $d, 'hasta' => $d, 't' => $t];
        }
    }
    if (!$grupos) return 'Sin días de atención';
    return implode(' · ', array_map(static fn($g) => HORARIO_DIAS_CORTO[$g['desde']] . ($g['hasta'] > $g['desde'] ? ($g['hasta'] === $g['desde'] + 1 ? ' y ' : ' a ') . HORARIO_DIAS_CORTO[$g['hasta']] : '') . ' ' . $g['t'], $grupos));
}

/**
 * Motivo si el centro NO atiende esa hora por su horario (día abierto pero fuera del rango);
 * null si la hora está dentro del horario. No revisa domingos ni feriados (ver diaCerradoMotivo).
 */
function horaFueraHorarioMotivo(string $fecha, string $hora): ?string
{
    $dow = horarioDow($fecha);
    if (!horarioDiaAbierto($dow)) {
        return null;   // lo informa diaCerradoMotivo()
    }
    if (in_array(substr($hora, 0, 5), horarioBloquesDow($dow), true)) {
        return null;
    }
    return 'Los ' . HORARIO_DIAS_PLURAL[$dow] . ' el centro atiende de ' . horarioTextoDia($dow) . ': no se pueden tomar horas a las ' . substr($hora, 0, 5) . ' h.';
}

/**
 * Guarda el horario del centro (lunes a sábado).
 * $dias = [1..6 => ['abierto' => 0|1, 'ini' => 'HH:MM', 'fin' => 'HH:MM']]
 */
function horarioCentroGuardar(array $dias, ?int $usuarioId = null): array
{
    $filas = [];
    for ($d = 1; $d <= 6; $d++) {
        $x = $dias[$d] ?? $dias[(string) $d] ?? null;
        if (!is_array($x)) {
            return ['ok' => false, 'error' => 'Falta el horario del ' . mb_strtolower(HORARIO_DIAS[$d], 'UTF-8') . '.'];
        }
        $abierto = !empty($x['abierto']);
        $ini = (string) ($x['ini'] ?? '');
        $fin = (string) ($x['fin'] ?? '');
        if (!preg_match('/^\d{2}:00$/', $ini) || !preg_match('/^\d{2}:00$/', $fin) || $ini < HORARIO_INI_MIN || $fin > HORARIO_FIN_MAX || $fin <= $ini) {
            return ['ok' => false, 'error' => HORARIO_DIAS[$d] . ': el horario debe estar entre ' . HORARIO_INI_MIN . ' y ' . HORARIO_FIN_MAX . ' y la hora de término debe ser posterior a la de inicio.'];
        }
        $filas[$d] = [$abierto ? 1 : 0, $ini, $fin];
    }
    if (!array_filter($filas, static fn($f) => $f[0] === 1)) {
        return ['ok' => false, 'error' => 'El centro debe atender al menos un día de la semana.'];
    }
    $pdo = db();
    $propia = !$pdo->inTransaction();   // si ya hay una transacción (p. ej. pruebas), se usa esa
    try {
        if ($propia) $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT INTO horario_centro (dia_semana, abierto, hora_inicio, hora_fin, actualizado_por) VALUES (?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE abierto = VALUES(abierto), hora_inicio = VALUES(hora_inicio), hora_fin = VALUES(hora_fin), actualizado_por = VALUES(actualizado_por)');
        foreach ($filas as $d => [$a, $i, $f]) {
            $st->execute([$d, $a, $i . ':00', $f . ':00', $usuarioId]);
        }
        if ($propia) $pdo->commit();
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[horario] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo guardar el horario del centro.'];
    }
    horarioCentro(true);
    return ['ok' => true];
}

/**
 * Horas ya tomadas (pendientes o confirmadas, desde hoy) que quedarían fuera de un horario
 * del centro propuesto. No se tocan: solo se informan para que la administración decida.
 * @return array{n:int, muestra:string[]}
 */
function horarioCentroReservasAfectadas(array $dias): array
{
    $n = 0; $muestra = [];
    try {
        $st = db()->query("SELECT fecha, TIME_FORMAT(hora,'%H:%i') AS h, box_slug, nombre FROM reservas
                            WHERE estado IN ('pendiente','confirmada') AND hora IS NOT NULL AND fecha >= CURDATE() ORDER BY fecha, hora");
        foreach ($st->fetchAll() as $r) {
            $d = horarioDow((string) $r['fecha']);
            $x = $dias[$d] ?? $dias[(string) $d] ?? null;
            if ($d === 7 || !is_array($x)) continue;
            $fuera = empty($x['abierto']) || $r['h'] < (string) $x['ini'] || $r['h'] >= (string) $x['fin'];
            if ($fuera) {
                $n++;
                if (count($muestra) < 8) {
                    $muestra[] = date('d-m-Y', strtotime((string) $r['fecha'])) . ' ' . $r['h'] . ' h · ' . (function_exists('nombreBox') ? nombreBox((string) $r['box_slug']) : $r['box_slug']);
                }
            }
        }
    } catch (Throwable $e) {
    }
    return ['n' => $n, 'muestra' => $muestra];
}

/* ------------------------------------------------- Horario de cada profesional */

/** Franjas de todas las profesionales: [pid => [dow => [['ini'=>'HH:MM','fin'=>'HH:MM'], ...]]]. */
function horarioProfesionales(bool $refrescar = false): array
{
    static $cache = null;
    if ($cache !== null && !$refrescar) {
        return $cache;
    }
    $out = [];
    try {
        foreach (db()->query("SELECT profesional_id, dia_semana, TIME_FORMAT(hora_inicio,'%H:%i') AS ini, TIME_FORMAT(hora_fin,'%H:%i') AS fin
                                FROM disponibilidad WHERE activo = 1 ORDER BY profesional_id, dia_semana, hora_inicio") as $r) {
            $out[(int) $r['profesional_id']][(int) $r['dia_semana']][] = ['ini' => (string) $r['ini'], 'fin' => (string) $r['fin']];
        }
    } catch (Throwable $e) {
    }
    return $cache = $out;
}

/** Franjas de una profesional por día ([] = sin horario definido). */
function horarioProfesional(int $pid): array
{
    return horarioProfesionales()[$pid] ?? [];
}

/** ¿La profesional tiene horario definido? */
function horarioProfesionalDefinido(int $pid): bool
{
    return horarioProfesional($pid) !== [];
}

/** ¿La profesional atiende en esa fecha y bloque de 1 hora? (true si no tiene horario definido) */
function horarioProfesionalAtiende(int $pid, string $fecha, string $hora): bool
{
    $hp = horarioProfesional($pid);
    if (!$hp) {
        return true;
    }
    $h = substr($hora, 0, 5);
    $fin = sprintf('%02d:00', (int) substr($h, 0, 2) + 1);
    foreach ($hp[horarioDow($fecha)] ?? [] as $f) {
        if ($h >= $f['ini'] && $fin <= $f['fin']) {
            return true;
        }
    }
    return false;
}

/** Texto del horario de un día: «10:00 a 14:00 y 15:00 a 18:00». */
function horarioProfesionalTextoDia(int $pid, int $dow): ?string
{
    $fr = horarioProfesional($pid)[$dow] ?? [];
    if (!$fr) return null;
    $t = array_map(static fn($f) => $f['ini'] . ' a ' . $f['fin'], $fr);
    return count($t) > 1 ? implode(', ', array_slice($t, 0, -1)) . ' y ' . end($t) : $t[0];
}

/** Resumen: «Lun a Vie 10:00 a 18:00» o null si no tiene horario definido. */
function horarioProfesionalResumen(int $pid): ?string
{
    $hp = horarioProfesional($pid);
    if (!$hp) return null;
    $grupos = [];
    for ($d = 1; $d <= 7; $d++) {
        $t = horarioProfesionalTextoDia($pid, $d);
        if ($t === null) continue;
        $u = count($grupos) - 1;
        if ($u >= 0 && $grupos[$u]['t'] === $t && $grupos[$u]['hasta'] === $d - 1) {
            $grupos[$u]['hasta'] = $d;
        } else {
            $grupos[] = ['desde' => $d, 'hasta' => $d, 't' => $t];
        }
    }
    return implode(' · ', array_map(static fn($g) => HORARIO_DIAS_CORTO[$g['desde']] . ($g['hasta'] > $g['desde'] ? ($g['hasta'] === $g['desde'] + 1 ? ' y ' : ' a ') . HORARIO_DIAS_CORTO[$g['hasta']] : '') . ' ' . $g['t'], $grupos));
}

/** Aviso si se agenda a la profesional fuera de su horario (null si atiende o no tiene horario). */
function horarioProfesionalAviso(int $pid, string $fecha, string $hora): ?string
{
    if (horarioProfesionalAtiende($pid, $fecha, $hora)) {
        return null;
    }
    $dow = horarioDow($fecha);
    $nombre = function_exists('nombreProfesional') ? nombreProfesional($pid) : 'La profesional';
    $t = horarioProfesionalTextoDia($pid, $dow);
    return $t === null
        ? $nombre . ' no atiende los ' . HORARIO_DIAS_PLURAL[$dow] . ' (fuera de su horario).'
        : $nombre . ' atiende los ' . HORARIO_DIAS_PLURAL[$dow] . ' de ' . $t . ': las ' . substr($hora, 0, 5) . ' h está fuera de su horario.';
}

/**
 * Guarda el horario de una profesional. $franjas = [dow => [['ini'=>'HH:MM','fin'=>'HH:MM'], ...]] (lunes a sábado).
 * Un arreglo vacío deja a la profesional «sin horario definido».
 */
function horarioProfesionalGuardar(int $pid, array $franjas): array
{
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM profesionales WHERE id = ? AND eliminado_at IS NULL');
        $st->execute([$pid]);
        if (!(int) $st->fetchColumn()) {
            return ['ok' => false, 'error' => 'Profesional no encontrada.'];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Profesional no encontrada.'];
    }
    $filas = [];
    foreach ($franjas as $d => $lista) {
        $d = (int) $d;
        if ($d < 1 || $d > 6 || !is_array($lista)) {
            continue;
        }
        $previas = [];
        foreach (array_slice($lista, 0, 4) as $f) {
            $ini = (string) ($f['ini'] ?? '');
            $fin = (string) ($f['fin'] ?? '');
            if (!preg_match('/^\d{2}:\d{2}$/', $ini) || !preg_match('/^\d{2}:\d{2}$/', $fin) || $ini < HORARIO_INI_MIN || $fin > HORARIO_FIN_MAX || $fin <= $ini) {
                return ['ok' => false, 'error' => HORARIO_DIAS[$d] . ': cada franja debe estar entre ' . HORARIO_INI_MIN . ' y ' . HORARIO_FIN_MAX . ' y terminar después de empezar.'];
            }
            foreach ($previas as [$pi, $pf]) {
                if ($ini < $pf && $fin > $pi) {
                    return ['ok' => false, 'error' => HORARIO_DIAS[$d] . ': hay franjas que se superponen.'];
                }
            }
            $previas[] = [$ini, $fin];
            $filas[] = [$d, $ini, $fin];
        }
    }
    $pdo = db();
    $propia = !$pdo->inTransaction();
    try {
        if ($propia) $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM disponibilidad WHERE profesional_id = ?')->execute([$pid]);
        $ins = $pdo->prepare('INSERT INTO disponibilidad (profesional_id, dia_semana, hora_inicio, hora_fin, activo) VALUES (?,?,?,?,1)');
        foreach ($filas as [$d, $i, $f]) {
            $ins->execute([$pid, $d, $i . ':00', $f . ':00']);
        }
        if ($propia) $pdo->commit();
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[horario-prof] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo guardar el horario.'];
    }
    horarioProfesionales(true);
    return ['ok' => true];
}

/** Horas ya tomadas (desde hoy) de la profesional que quedarían fuera de un horario propuesto. */
function horarioProfesionalReservasAfectadas(int $pid, array $franjas): int
{
    if (!array_filter($franjas, static fn($l) => is_array($l) && $l)) {
        return 0;   // sin horario definido: no queda nada fuera
    }
    $n = 0;
    try {
        $st = db()->prepare("SELECT fecha, TIME_FORMAT(hora,'%H:%i') AS h FROM reservas
                              WHERE profesional_id = ? AND estado IN ('pendiente','confirmada') AND hora IS NOT NULL AND fecha >= CURDATE()");
        $st->execute([$pid]);
        foreach ($st->fetchAll() as $r) {
            $d = horarioDow((string) $r['fecha']);
            $fin = sprintf('%02d:00', (int) substr($r['h'], 0, 2) + 1);
            $ok = false;
            foreach ((array) ($franjas[$d] ?? $franjas[(string) $d] ?? []) as $f) {
                if ($r['h'] >= (string) ($f['ini'] ?? '') && $fin <= (string) ($f['fin'] ?? '')) { $ok = true; break; }
            }
            if (!$ok) $n++;
        }
    } catch (Throwable $e) {
    }
    return $n;
}
