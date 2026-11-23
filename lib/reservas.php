<?php
/**
 * Reservas de boxes. Ahora persistidas en MySQL (tabla `reservas`) en lugar de
 * un archivo JSON. El UNIQUE(box_slug, fecha, hora) del motor impide el doble
 * agendamiento de forma atómica (resuelve la condición de carrera anterior).
 */

declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';

/** Convierte un precio con formato chileno ("$8.000") a entero (8000) o null. */
function parseClp($valor): ?int
{
    if ($valor === null) {
        return null;
    }
    $n = preg_replace('/\D/', '', (string) $valor);
    return $n === '' ? null : (int) $n;
}

/** Horas ya ocupadas (HH:MM) de un box en una fecha (ignora las canceladas). */
function reservaHorasOcupadas(string $boxSlug, string $fecha): array
{
    try {
        $st = db()->prepare(
            "SELECT TIME_FORMAT(hora, '%H:%i') AS h
               FROM reservas
              WHERE box_slug = ? AND fecha = ? AND hora IS NOT NULL AND estado <> 'cancelada'"
        );
        $st->execute([$boxSlug, $fecha]);
        return array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Crea una reserva. $r debe traer: boxSlug, modalidadLabel, modalidadSub,
 * precio, fecha, hora (o null), nombre, email, telefono.
 * Devuelve ['ok'=>true,'id'=>publicoId] o ['ok'=>false,'error'=>..,'status'=>..].
 */
function crearReserva(array $r): array
{
    $pub = substr(bin2hex(random_bytes(8)), 0, 16);
    try {
        $st = db()->prepare(
            "INSERT INTO reservas
                (publico_id, box_slug, modalidad_label, modalidad_sub, precio_clp, fecha, hora, nombre, email, telefono, origen)
             VALUES (?,?,?,?,?,?,?,?,?,?, 'web')"
        );
        $st->execute([
            $pub,
            $r['boxSlug'],
            $r['modalidadLabel'],
            is_string($r['modalidadSub'] ?? null) ? $r['modalidadSub'] : null,
            parseClp($r['precio'] ?? null),
            $r['fecha'],
            !empty($r['hora']) ? $r['hora'] : null,
            mb_substr(trim((string) $r['nombre']), 0, 200, 'UTF-8'),
            mb_substr(trim((string) $r['email']), 0, 200, 'UTF-8'),
            mb_substr(trim((string) $r['telefono']), 0, 60, 'UTF-8'),
        ]);
        return ['ok' => true, 'id' => $pub];
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'error' => 'Ese horario ya fue reservado. Elige otro.', 'status' => 409];
        }
        if (($e->errorInfo[1] ?? 0) === 1644 && str_contains((string) $e->getMessage(), 'DIA_CERRADO')) {
            return ['ok' => false, 'error' => 'Ese día no atendemos. Elige otro día.', 'status' => 409];
        }
        if (($e->errorInfo[1] ?? 0) === 1644 && str_contains((string) $e->getMessage(), 'BOX_SIN_CUPO')) {
            return ['ok' => false, 'error' => 'Ese horario ya fue reservado. Elige otro.', 'status' => 409];
        }
        return ['ok' => false, 'error' => 'No se pudo guardar la reserva en el servidor.', 'status' => 500];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo guardar la reserva en el servidor.', 'status' => 500];
    }
}
