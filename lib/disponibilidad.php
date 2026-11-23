<?php
/**
 * Agenda online: disponibilidad por profesional y creación de reservas web.
 * Solo los profesionales con agenda_online=1 (Fernanda y Javiera) son
 * reservables desde el sitio público. La disponibilidad = horario semanal
 * (tabla `disponibilidad`) menos las horas ya tomadas por ese profesional o
 * por su box. El pago se integrará como último paso más adelante; por ahora la
 * reserva queda en estado 'pendiente'.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';
require_once APP_ROOT . '/lib/profesionales.php';

const AGENDA_DUR_MIN = 45;

/** Profesionales reservables online (activos, públicos, con agenda_online=1). */
function profesionalesAgendables(): array
{
    try {
        $st = db()->query(
            "SELECT p.id, p.nombre, p.slug, p.especialidad_slug, p.foto, p.box_slug, p.titulo, p.empresa_id,
                    e.nombre AS especialidad_nombre
               FROM profesionales p
          LEFT JOIN especialidades e ON e.slug = p.especialidad_slug
              WHERE p.agenda_online = 1 AND p.activo = 1 AND p.publico = 1
           ORDER BY p.orden, p.id"
        );
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Un profesional agendable por su slug (o null). */
function profesionalAgendablePorSlug(string $slug): ?array
{
    foreach (profesionalesAgendables() as $p) {
        if ($p['slug'] === $slug) {
            return $p;
        }
    }
    return null;
}

/** Un profesional agendable por id (o null). */
function profesionalAgendablePorId(int $id): ?array
{
    foreach (profesionalesAgendables() as $p) {
        if ((int) $p['id'] === $id) {
            return $p;
        }
    }
    return null;
}

/** Horario semanal configurado de un profesional. */
function disponibilidadDe(int $profId): array
{
    try {
        $st = db()->prepare("SELECT dia_semana, TIME_FORMAT(hora_inicio,'%H:%i') AS ini, TIME_FORMAT(hora_fin,'%H:%i') AS fin FROM disponibilidad WHERE profesional_id = ? AND activo = 1 ORDER BY dia_semana, hora_inicio");
        $st->execute([$profId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Horas disponibles (HH:MM) de un profesional en una fecha concreta.
 * Resta las horas ya tomadas por el profesional o por su box (no canceladas)
 * y, si la fecha es hoy, las horas ya pasadas.
 */
function agendaSlotsDisponibles(array $prof, string $fecha): array
{
    $profId = (int) $prof['id'];
    $boxSlug = (string) ($prof['box_slug'] ?? '');

    // Validar fecha
    $hoy = new DateTimeImmutable('today');
    try {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    } catch (Throwable $e) {
        return [];
    }
    if (!$d || $d < $hoy) {
        return [];
    }
    $dow = (int) $d->format('N'); // 1=Lun .. 7=Dom
    // Domingo o feriado no trabajado: sin horas.
    require_once APP_ROOT . '/lib/feriados.php';
    if (diaCerradoMotivo($d->format('Y-m-d')) !== null) {
        return [];
    }

    // Franjas del día
    $franjas = array_filter(disponibilidadDe($profId), fn($r) => (int) $r['dia_semana'] === $dow);
    if (!$franjas) {
        return [];
    }

    // Horas no disponibles: las que ya tiene el profesional, y las del box que
    // alcanzaron su cupo máximo (configuracion.box_cupo_max).
    $tomadas = [];
    try {
        $st = db()->prepare(
            "SELECT TIME_FORMAT(hora,'%H:%i') AS h FROM reservas
              WHERE fecha = ? AND hora IS NOT NULL AND estado <> 'cancelada' AND profesional_id = ?"
        );
        $st->execute([$fecha, $profId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $h) {
            $tomadas[$h] = true;
        }
        require_once APP_ROOT . '/lib/panel.php';
        $st = db()->prepare(
            "SELECT TIME_FORMAT(hora,'%H:%i') AS h FROM reservas
              WHERE fecha = ? AND hora IS NOT NULL AND estado <> 'cancelada' AND box_slug = ?
              GROUP BY hora HAVING COUNT(*) >= ?"
        );
        $st->execute([$fecha, $boxSlug, boxCupoMax()]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $h) {
            $tomadas[$h] = true;
        }
    } catch (Throwable $e) {
    }

    $ahora = new DateTimeImmutable('now');
    $esHoy = $d->format('Y-m-d') === $hoy->format('Y-m-d');

    $slots = [];
    foreach ($franjas as $f) {
        $ini = DateTimeImmutable::createFromFormat('!H:i', $f['ini']);
        $fin = DateTimeImmutable::createFromFormat('!H:i', $f['fin']);
        if (!$ini || !$fin) {
            continue;
        }
        for ($t = $ini; $t->modify('+' . AGENDA_DUR_MIN . ' minutes') <= $fin; $t = $t->modify('+' . AGENDA_DUR_MIN . ' minutes')) {
            $hhmm = $t->format('H:i');
            if (isset($tomadas[$hhmm])) {
                continue;
            }
            if ($esHoy) {
                // comparar con la hora actual del día
                $slotHoy = $d->setTime((int) $t->format('H'), (int) $t->format('i'));
                if ($slotHoy <= $ahora) {
                    continue;
                }
            }
            $slots[$hhmm] = true;
        }
    }
    $out = array_keys($slots);
    // Solo dentro del horario de atención del centro («Días y horarios»).
    if (function_exists('horasDelDia')) {
        $hc = horarioCentro()[(int) $d->format('N')] ?? null;
        $out = $hc && $hc['abierto'] ? array_values(array_filter($out, static fn($h) => $h >= $hc['ini'] && $h < $hc['fin'])) : [];
    }
    sort($out);
    return $out;
}

/**
 * Crea una reserva desde la agenda online. Valida que el profesional sea
 * agendable, que la hora siga disponible y los datos de contacto.
 * Devuelve ['ok'=>true,'codigo'=>..,'reserva'=>[..]] o ['ok'=>false,'error'=>..,'status'=>..].
 */
function agendaCrearReserva(array $d): array
{
    $prof = profesionalAgendablePorId((int) ($d['profesional_id'] ?? 0));
    if (!$prof) {
        return ['ok' => false, 'error' => 'Profesional no disponible para agenda online.', 'status' => 400];
    }
    $fecha = trim((string) ($d['fecha'] ?? ''));
    $hora = trim((string) ($d['hora'] ?? ''));
    $nombre = trim((string) ($d['nombre'] ?? ''));
    $email = trim((string) ($d['email'] ?? ''));
    $telefono = trim((string) ($d['telefono'] ?? ''));

    if ($nombre === '' || $telefono === '') {
        return ['ok' => false, 'error' => 'Completa tu nombre y teléfono.', 'status' => 400];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Ingresa un correo válido para enviarte el comprobante.', 'status' => 400];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !preg_match('/^\d{2}:\d{2}$/', $hora)) {
        return ['ok' => false, 'error' => 'Elige una fecha y hora válidas.', 'status' => 400];
    }
    // La hora debe seguir disponible (revalida contra el horario y lo ya tomado)
    if (!in_array($hora, agendaSlotsDisponibles($prof, $fecha), true)) {
        return ['ok' => false, 'error' => 'Esa hora ya no está disponible. Elige otra.', 'status' => 409];
    }

    // Servicio elegido (producto de la empresa de la profesional). El precio se
    // toma del producto EN EL SERVIDOR (no se confía en el valor que envía el navegador).
    require_once APP_ROOT . '/lib/productos.php';
    $servicio = getProducto((int) ($d['producto_id'] ?? 0));
    if (!$servicio || (int) ($servicio['empresa_id'] ?? 0) !== (int) ($prof['empresa_id'] ?? 0) || (int) ($servicio['activo'] ?? 0) !== 1
        || (!empty($servicio['profesional_id']) && (int) $servicio['profesional_id'] !== (int) $prof['id'])) {
        return ['ok' => false, 'error' => 'Elige un servicio válido para tu reserva.', 'status' => 400];
    }
    $modalidad = (string) $servicio['nombre'];
    $precio = (int) $servicio['precio_neto'];

    $pub = substr(bin2hex(random_bytes(8)), 0, 16);
    try {
        $st = db()->prepare(
            "INSERT INTO reservas
                (publico_id, box_slug, profesional_id, modalidad_label, modalidad_sub, precio_clp, fecha, hora, duracion_min, nombre, email, telefono, origen)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'web-agenda')"
        );
        $st->execute([
            $pub,
            $prof['box_slug'],
            (int) $prof['id'],
            $modalidad,
            'Con ' . $prof['nombre'],
            $precio,
            $fecha,
            $hora,
            AGENDA_DUR_MIN,
            mb_substr($nombre, 0, 200, 'UTF-8'),
            mb_substr($email, 0, 200, 'UTF-8'),
            mb_substr($telefono, 0, 60, 'UTF-8'),
        ]);
        $reservaId = (int) db()->lastInsertId();
    } catch (PDOException $e) {
        // 1062: el profesional ya tiene esa hora; 1644: el box llegó a su cupo (trigger).
        if (in_array((int) ($e->errorInfo[1] ?? 0), [1062, 1644], true)) {
            return ['ok' => false, 'error' => 'Esa hora acaba de ser tomada. Elige otra.', 'status' => 409];
        }
        return ['ok' => false, 'error' => 'No se pudo registrar la reserva. Intenta de nuevo.', 'status' => 500];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo registrar la reserva. Intenta de nuevo.', 'status' => 500];
    }

    return ['ok' => true, 'codigo' => $pub, 'reserva' => [
        'profesional' => $prof['nombre'],
        'especialidad' => $prof['especialidad_nombre'],
        'empresa_id' => (int) ($prof['empresa_id'] ?? 0),
        'producto_id' => (int) ($d['producto_id'] ?? 0),
        'servicio' => $modalidad,
        'precio' => $precio,
        'fecha' => $fecha,
        'hora' => $hora,
        'duracion' => AGENDA_DUR_MIN,
        'nombre' => $nombre,
        'email' => $email,
        'telefono' => $telefono,
        'reserva_id' => $reservaId ?? 0,
        'reserva_publico' => $pub,
    ]];
}
