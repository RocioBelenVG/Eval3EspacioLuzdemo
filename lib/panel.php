<?php
/**
 * Utilidades del panel administrativo: formato de moneda, rangos de fecha y
 * consultas agregadas (KPIs) para el dashboard. Todo con degradación elegante:
 * si la base de datos falla, devuelve valores neutros y el panel no se rompe.
 */

declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';
require_once APP_ROOT . '/lib/feriados.php';

/** Formatea un entero en pesos chilenos: 8000 -> "$8.000". */
function clp(?int $monto): string
{
    return '$' . number_format((int) $monto, 0, ',', '.');
}

/** "Septiembre 2026" a partir de un Y-m-d. */
function mesAnioLabel(string $ymd): string
{
    $meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $t = strtotime($ymd);
    return ($meses[(int) date('n', $t)] ?? '') . ' ' . date('Y', $t);
}

/** Devuelve [inicioSemana, finSemana] (lunes..domingo) de la semana de $ref. */
function rangoSemana(?string $ref = null): array
{
    $hoy = new DateTimeImmutable($ref ?? 'today');
    $ini = $hoy->modify(('1' === $hoy->format('N')) ? 'today' : 'last monday');
    $fin = $ini->modify('+6 days');
    return [$ini->format('Y-m-d'), $fin->format('Y-m-d')];
}

/**
 * Ingresos de un rango (inclusive): SOLO boletas y facturas emitidas (no anuladas),
 * menos las notas de crédito y más las notas de débito emitidas en ese rango.
 */
function ingresosEntre(string $desde, string $hasta): int
{
    try {
        $st = db()->prepare(
            "SELECT COALESCE(SUM(total_clp),0) FROM boletas
              WHERE estado='emitida' AND DATE(fecha_emision) BETWEEN ? AND ?"
        );
        $st->execute([$desde, $hasta]);
        $bruto = (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
    try {
        $st = db()->prepare("SELECT COALESCE(SUM(n.total_clp),0) FROM notas_credito n JOIN boletas b ON b.id = n.documento_id
                              WHERE n.estado = 'emitida' AND b.estado = 'emitida' AND DATE(n.fecha_emision) BETWEEN ? AND ?");
        $st->execute([$desde, $hasta]);
        $bruto -= (int) $st->fetchColumn();
    } catch (Throwable $e) {
        // sin notas de crédito aún
    }
    try {
        $st = db()->prepare("SELECT COALESCE(SUM(n.total_clp),0) FROM notas_debito n JOIN boletas b ON b.id = n.documento_id
                              WHERE n.estado = 'emitida' AND b.estado = 'emitida' AND DATE(n.fecha_emision) BETWEEN ? AND ?");
        $st->execute([$desde, $hasta]);
        $bruto += (int) $st->fetchColumn();
    } catch (Throwable $e) {
        // sin notas de débito aún
    }
    return $bruto;
}

/** Cuenta reservas (no canceladas) en un rango de fechas. */
function reservasEntre(string $desde, string $hasta): int
{
    try {
        $st = db()->prepare(
            "SELECT COUNT(*) FROM reservas
              WHERE estado <> 'cancelada' AND fecha BETWEEN ? AND ?"
        );
        $st->execute([$desde, $hasta]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Métricas resumidas para las tarjetas del dashboard. */
function dashboardKpis(): array
{
    $hoy = date('Y-m-d');
    [$semIni, $semFin] = rangoSemana();
    $mesIni = date('Y-m-01');
    $mesFin = date('Y-m-t');

    $boletasMes = 0;
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM boletas WHERE estado='emitida' AND DATE(fecha_emision) BETWEEN ? AND ?");
        $st->execute([$mesIni, $mesFin]);
        $boletasMes = (int) $st->fetchColumn();
    } catch (Throwable $e) {
        $boletasMes = 0;
    }

    return [
        'ingresos_mes'      => ingresosEntre($mesIni, $mesFin),
        'ingresos_semana'   => ingresosEntre($semIni, $semFin),
        'ingresos_hoy'      => ingresosEntre($hoy, $hoy),
        'boxes_semana'      => reservasEntre($semIni, $semFin),
        'reservas_hoy'      => reservasEntre($hoy, $hoy),
        'boletas_mes'       => $boletasMes,
        'semana'            => [$semIni, $semFin],
    ];
}

/** Próximas reservas (desde hoy), para la tabla del dashboard. */
function proximasReservas(int $limite = 8): array
{
    try {
        $st = db()->prepare(
            "SELECT publico_id, box_slug, modalidad_label, precio_clp, fecha, hora, nombre, estado
               FROM reservas
              WHERE fecha >= CURDATE() AND estado <> 'cancelada'
              ORDER BY fecha ASC, hora ASC
              LIMIT ?"
        );
        $st->bindValue(1, $limite, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Ocupación por box en la semana actual (para una mini-tabla del dashboard). */
function ocupacionSemanaPorBox(): array
{
    [$ini, $fin] = rangoSemana();
    try {
        $st = db()->prepare(
            "SELECT box_slug, COUNT(*) AS n
               FROM reservas
              WHERE estado <> 'cancelada' AND fecha BETWEEN ? AND ?
              GROUP BY box_slug"
        );
        $st->execute([$ini, $fin]);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[$row['box_slug']] = (int) $row['n'];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** Nombre legible de un box por su slug (catálogo vigente; si fue archivado, desde la tabla boxes). */
function nombreBox(string $slug): string
{
    foreach ($GLOBALS['BOXES'] ?? [] as $b) {
        if (($b['slug'] ?? '') === $slug) {
            return $b['nombre'] ?? $slug;
        }
    }
    if (function_exists('boxNombrePorSlug') && ($n = boxNombrePorSlug($slug)) !== null) {
        return $n;
    }
    return $slug;
}

/** Lista de profesionales activos [id => nombre] desde la base de datos. */
function listProfesionales(): array
{
    try {
        $rows = db()->query("SELECT id, nombre FROM profesionales WHERE activo = 1 ORDER BY orden, id")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $r['nombre'];
        }
        if ($out) {
            return $out;
        }
    } catch (Throwable $e) {
        // cae al catálogo de data.php
    }
    $out = [];
    foreach ($GLOBALS['PROFESIONALES'] ?? [] as $p) {
        $out[(int) $p['id']] = $p['nombre'];
    }
    return $out;
}

/** Nombre de un profesional por id. */
function nombreProfesional(?int $id): string
{
    if ($id === null) {
        return '';
    }
    return listProfesionales()[$id] ?? ('Profesional #' . $id);
}

/** Horas válidas de agenda (columnas del calendario). */
/**
 * Tipos de atención de una reserva (columna reservas.tipo_atencion; opcional).
 * Son los servicios que el centro publica en su sitio. Permiten medir, por ejemplo,
 * cuántas evaluaciones se hicieron en el mes.
 */
const RESERVA_TIPOS_ATENCION = [
    'evaluacion'           => 'Evaluación y diagnóstico',
    'intervencion'         => 'Intervención terapéutica',
    'orientacion_familiar' => 'Orientación familiar',
    'etapas_vida'          => 'Atención por etapas de la vida',
    'otro'                 => 'Otra atención',
];

/** Valida un tipo de atención recibido ('' / null = sin especificar). */
function tipoAtencionValido($t): ?string
{
    $t = trim((string) ($t ?? ''));
    return isset(RESERVA_TIPOS_ATENCION[$t]) ? $t : null;
}

function horasAgenda(): array
{
    return ['08:00','09:00','10:00','11:00','12:00','13:00','14:00','15:00','16:00','17:00','18:00','19:00','20:00'];
}

/**
 * Reservas (no canceladas) en un rango, opcionalmente filtradas por box o
 * profesional. Como el panel permite sobre-agendar, puede haber VARIAS reservas
 * en el mismo box/fecha/hora: devuelve $out[fecha]['HH:MM'] = [fila, fila, ...].
 */
function reservasGrilla(string $ini, string $fin, ?string $boxSlug = null, ?int $profId = null): array
{
    $sql = "SELECT id, publico_id, box_slug, profesional_id, modalidad_label, precio_clp,
                   fecha, TIME_FORMAT(hora,'%H:%i') AS hhmm, nombre, email, telefono, estado
              FROM reservas
             WHERE fecha BETWEEN ? AND ? AND estado <> 'cancelada' AND hora IS NOT NULL";
    $params = [$ini, $fin];
    if ($boxSlug !== null) { $sql .= " AND box_slug = ?"; $params[] = $boxSlug; }
    if ($profId !== null) { $sql .= " AND profesional_id = ?"; $params[] = $profId; }
    $sql .= " ORDER BY id";
    $out = [];
    try {
        $st = db()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $r) {
            $out[$r['fecha']][$r['hhmm']][] = $r;
        }
    } catch (Throwable $e) {
        // vacío
    }
    return $out;
}

/**
 * Todas las reservas (no canceladas) de un box/fecha/hora, con nombre del
 * profesional y del paciente. Para el detalle de una hora que puede tener varias.
 */
function reservasEnSlot(string $boxSlug, string $fecha, string $hora): array
{
    try {
        $st = db()->prepare(
            "SELECT id, box_slug, profesional_id, paciente_id, modalidad_label, precio_clp,
                    fecha, TIME_FORMAT(hora,'%H:%i') AS hora, nombre, email, telefono, estado, origen
               FROM reservas
              WHERE box_slug = ? AND fecha = ? AND hora = ? AND estado <> 'cancelada'
              ORDER BY id"
        );
        $st->execute([$boxSlug, $fecha, $hora]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['profesional_nombre'] = $r['profesional_id'] !== null ? nombreProfesional((int) $r['profesional_id']) : '';
            $r['box_nombre'] = nombreBox($r['box_slug']);
            $r['precio_fmt'] = $r['precio_clp'] ? clp((int) $r['precio_clp']) : null;
            $r['paciente_nombre'] = null;
            if (!empty($r['paciente_id'])) {
                require_once APP_ROOT . '/lib/pacientes.php';
                $c = getPaciente((int) $r['paciente_id']);
                $r['paciente_nombre'] = $c['nombre'] ?? null;
            }
        }
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Máximo de reservas simultáneas (misma fecha y hora) en un mismo box.
 * Vive en la tabla `configuracion` (clave box_cupo_max); la base de datos lo
 * hace cumplir además con los triggers trg_reservas_cupo_*.
 */
function boxCupoMax(): int
{
    static $cupo = null;
    if ($cupo === null) {
        $cupo = 2;
        try {
            $v = db()->query("SELECT valor FROM configuracion WHERE clave = 'box_cupo_max' LIMIT 1")->fetchColumn();
            if ($v !== false && (int) $v > 0) {
                $cupo = (int) $v;
            }
        } catch (Throwable $e) {
        }
    }
    return $cupo;
}

/** Reservas activas (no canceladas) de un box en una fecha y hora. */
function boxOcupacion(string $boxSlug, string $fecha, string $hora): int
{
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM reservas WHERE box_slug = ? AND fecha = ? AND hora = ? AND estado <> 'cancelada'");
        $st->execute([$boxSlug, $fecha, $hora]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** ¿La excepción viene del trigger de cupo del box (BOX_SIN_CUPO)? */
function esErrorCupoBox(Throwable $e): bool
{
    return $e instanceof PDOException
        && (int) ($e->errorInfo[1] ?? 0) === 1644
        && str_contains((string) $e->getMessage(), 'BOX_SIN_CUPO');
}

/**
 * Reservas que se pueden cancelar o traspasar en bloque: futuras (desde ahora)
 * y vigentes (pendiente/confirmada). Filtros: serie_id, o profesional_id con
 * rango desde/hasta y, opcionalmente, box_slug, dias (1=Lun..7=Dom) y horas.
 * Devuelve ['ok'=>true,'filas'=>[...]] o ['ok'=>false,'error'=>...].
 */
function reservasParaMasivo(array $f): array
{
    $serie = trim((string) ($f['serie_id'] ?? ''));
    $profId = (int) ($f['profesional_id'] ?? 0);
    $where = ["estado IN ('pendiente','confirmada')", "hora IS NOT NULL",
              "(fecha > CURDATE() OR (fecha = CURDATE() AND hora >= CURTIME()))"];
    $params = [];
    if ($serie !== '') {
        if (!preg_match('/^[a-f0-9]{16}$/', $serie)) {
            return ['ok' => false, 'error' => 'Serie inválida.'];
        }
        $where[] = "serie_id = ?";
        $params[] = $serie;
    } else {
        if ($profId <= 0) {
            return ['ok' => false, 'error' => 'Elige el profesional cuyas horas quieres gestionar.'];
        }
        $desde = trim((string) ($f['desde'] ?? ''));
        $hasta = trim((string) ($f['hasta'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) || $hasta < $desde) {
            return ['ok' => false, 'error' => 'Elige un rango de fechas válido.'];
        }
        $where[] = "profesional_id = ?";
        $where[] = "fecha BETWEEN ? AND ?";
        array_push($params, $profId, $desde, $hasta);
        $box = trim((string) ($f['box_slug'] ?? ''));
        if ($box !== '') {
            $where[] = "box_slug = ?";
            $params[] = $box;
        }
        $dias = array_values(array_unique(array_filter(array_map('intval', (array) ($f['dias'] ?? [])), fn($d) => $d >= 1 && $d <= 7)));
        if ($dias && count($dias) < 7) {
            // WEEKDAY(): 0=Lunes .. 6=Domingo
            $where[] = "WEEKDAY(fecha) IN (" . implode(',', array_map(fn($d) => $d - 1, $dias)) . ")";
        }
        $horas = array_values(array_filter((array) ($f['horas'] ?? []), fn($h) => in_array($h, horasAgenda(), true)));
        if ($horas) {
            // Comparación directa contra la columna TIME (sin TIME_FORMAT: evita choque de collation y usa el índice).
            $where[] = "hora IN (" . implode(',', array_fill(0, count($horas), '?')) . ")";
            array_push($params, ...array_map(fn($h) => $h . ':00', $horas));
        }
    }
    try {
        $st = db()->prepare(
            "SELECT id, box_slug, profesional_id, fecha, TIME_FORMAT(hora,'%H:%i') AS hora, nombre, estado, serie_id
               FROM reservas WHERE " . implode(' AND ', $where) . " ORDER BY fecha, hora LIMIT 2000"
        );
        $st->execute($params);
        return ['ok' => true, 'filas' => $st->fetchAll()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudieron buscar las horas.'];
    }
}

/** Fila resumida de una reserva para listar en la gestión en bloque. */
function filaMasivo(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'fecha' => $r['fecha'],
        'hora' => $r['hora'],
        'box' => nombreBox((string) $r['box_slug']),
        'profesional' => $r['profesional_id'] !== null ? nombreProfesional((int) $r['profesional_id']) : '',
        'nombre' => $r['nombre'],
    ];
}

/**
 * Cancela o traspasa en bloque las horas que calzan con los filtros de
 * reservasParaMasivo(). Con $aplicar=false solo revisa (no modifica nada).
 * $f: filtros + accion ('cancelar'|'traspasar') + destino_id.
 * Devuelve ['status' => código HTTP, 'data' => respuesta].
 */
function reservasMasivo(array $f, bool $aplicar): array
{
    $accion = ($f['accion'] ?? '') === 'traspasar' ? 'traspasar' : 'cancelar';
    $sel = reservasParaMasivo($f);
    if (!$sel['ok']) {
        return ['status' => 400, 'data' => ['error' => $sel['error']]];
    }
    $filas = $sel['filas'];
    if (!$filas) {
        return ['status' => 404, 'data' => ['error' => 'No hay horas futuras vigentes que coincidan con lo elegido.']];
    }
    $pdo = db();

    // Traspaso: destino válido y sin choques con su propia agenda.
    $destino = null;
    $ocupadas = [];
    if ($accion === 'traspasar') {
        $destino = (int) ($f['destino_id'] ?? 0);
        if ($destino <= 0 || !isset(listProfesionales()[$destino])) {
            return ['status' => 400, 'data' => ['error' => 'Elige el profesional que recibirá las horas.']];
        }
        if ($aplicar) {
            $pdo->beginTransaction();
        }
        $fechas = array_column($filas, 'fecha');
        $q = $pdo->prepare("SELECT CONCAT(fecha,'|',TIME_FORMAT(hora,'%H:%i')) FROM reservas
                             WHERE profesional_id = ? AND estado <> 'cancelada' AND fecha BETWEEN ? AND ?"
                           . ($aplicar ? ' FOR UPDATE' : ''));
        $q->execute([$destino, min($fechas), max($fechas)]);
        $ocupadas = array_flip($q->fetchAll(PDO::FETCH_COLUMN));
    }

    $aplicables = []; $omitidas = [];
    foreach ($filas as $r) {
        if ($accion === 'traspasar') {
            $clave = $r['fecha'] . '|' . $r['hora'];
            if ((int) $r['profesional_id'] === $destino) {
                $omitidas[] = filaMasivo($r) + ['motivo' => 'ya es de ' . nombreProfesional($destino)];
                continue;
            }
            if (isset($ocupadas[$clave])) {
                $omitidas[] = filaMasivo($r) + ['motivo' => nombreProfesional($destino) . ' ya tiene hora'];
                continue;
            }
            $ocupadas[$clave] = true; // dos horas de la selección en el mismo horario
        }
        $aplicables[] = $r;
    }

    // Cobros afectados (boletas de sesiones y facturas de arriendo ya emitidas).
    require_once APP_ROOT . '/lib/cobros.php';
    $impacto = cobroImpacto(array_map(fn($r) => (int) $r['id'], $aplicables), $accion === 'traspasar' ? 'traspasar' : 'cancelar');

    if (!$aplicar) {
        return ['status' => 200, 'data' => ['ok' => true, 'accion' => $accion, 'total' => count($filas), 'cobro' => $impacto,
            'aplicables' => count($aplicables), 'omitidas' => count($omitidas),
            'omitidas_muestra' => array_slice($omitidas, 0, 15),
            'muestra' => array_map('filaMasivo', array_slice($aplicables, 0, 40)),
            'destino' => $destino ? nombreProfesional($destino) : null]];
    }

    if (!$aplicables) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['status' => 409, 'data' => ['error' => $accion === 'traspasar'
            ? 'Ninguna hora se puede traspasar: todas chocan con la agenda del destino.'
            : 'Ninguna hora se puede cancelar.']];
    }

    $cobroAccion = (string) ($f['cobro_accion'] ?? '');
    if ($impacto['requiere'] && $cobroAccion === '') {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['status' => 409, 'data' => ['error' => 'Algunas de estas horas ya están cobradas: elige qué hacer con el cobro.', 'requiere_cobro' => true, 'cobro' => $impacto]];
    }

    // Todo o nada; cada cambio queda registrado en reservas_movimientos con el mismo lote.
    $lote = substr(bin2hex(random_bytes(8)), 0, 16);
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
        // Primero la decisión de cobro (notas de crédito, cobro mantenido o cedido), en la misma transacción.
        if ($impacto['requiere']) {
            $rc = cobroAplicar(array_map(fn($r) => (int) $r['id'], $aplicables), $accion === 'traspasar' ? 'traspasar' : 'cancelar', $cobroAccion,
                (string) ($f['cobro_motivo'] ?? ''));
            if (!$rc['ok']) {
                throw new RuntimeException($rc['error'] ?? 'Cobro');
            }
        }
        if ($accion === 'cancelar') {
            $up = $pdo->prepare("UPDATE reservas SET estado = 'cancelada' WHERE id = ? AND estado IN ('pendiente','confirmada')");
            foreach ($aplicables as $r) {
                $up->execute([(int) $r['id']]);
                if ($up->rowCount() > 0) {
                    registrarMovimientoReserva((int) $r['id'], 'cancelacion', $r['profesional_id'] !== null ? (int) $r['profesional_id'] : null, null, $lote, 'Cancelación en bloque');
                }
            }
        } else {
            $nomDestino = nombreProfesional($destino);
            $up = $pdo->prepare("UPDATE reservas SET profesional_id = ?, nombre = ? WHERE id = ? AND estado IN ('pendiente','confirmada')");
            foreach ($aplicables as $r) {
                $origen = $r['profesional_id'] !== null ? (int) $r['profesional_id'] : null;
                // Si la reserva llevaba el nombre del profesional (arriendo de box), pasa al nuevo.
                $nombre = $r['nombre'];
                if ($origen !== null && mb_strtolower(trim((string) $nombre)) === mb_strtolower(nombreProfesional($origen))) {
                    $nombre = $nomDestino;
                }
                $up->execute([$destino, $nombre, (int) $r['id']]);
                if ($up->rowCount() > 0) {
                    registrarMovimientoReserva((int) $r['id'], 'traspaso', $origen, $destino, $lote,
                        'Traspaso en bloque: ' . ($origen !== null ? nombreProfesional($origen) : 'sin asignar') . ' → ' . $nomDestino);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[reservasMasivo] ' . $e->getMessage());
        return ['status' => 500, 'data' => ['error' => 'No se pudo completar la operación. No se modificó ninguna hora.']];
    }
    return ['status' => 200, 'data' => ['ok' => true, 'accion' => $accion, 'procesadas' => count($aplicables),
        'omitidas' => count($omitidas), 'omitidas_muestra' => array_slice($omitidas, 0, 15), 'lote' => $lote]];
}

/** Registra en la base de datos una cancelación o un traspaso de reserva. */
function registrarMovimientoReserva(int $reservaId, string $accion, ?int $profOrigen, ?int $profDestino, ?string $lote = null, string $detalle = ''): void
{
    try {
        $st = db()->prepare(
            "INSERT INTO reservas_movimientos (reserva_id, lote_id, accion, profesional_origen_id, profesional_destino_id, usuario_id, usuario_nombre, detalle)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        $st->execute([
            $reservaId, $lote, $accion, $profOrigen, $profDestino,
            !empty($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null,
            isset($_SESSION['admin_nombre']) ? mb_substr((string) $_SESSION['admin_nombre'], 0, 160, 'UTF-8') : null,
            $detalle !== '' ? mb_substr($detalle, 0, 300, 'UTF-8') : null,
        ]);
    } catch (Throwable $e) {
        error_log('[reservas_movimientos] ' . $e->getMessage());
    }
}

/**
 * Socias del centro (Fernanda y Javiera): EMITEN las boletas y facturas y NO pagan
 * arriendo de box. [id => nombre]. Se marcan en Profesionales («Socia»).
 */
function profesionalesSocias(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        foreach (db()->query("SELECT id, nombre FROM profesionales WHERE socia = 1 AND eliminado_at IS NULL ORDER BY nombre") as $r) {
            $cache[(int) $r['id']] = (string) $r['nombre'];
        }
    } catch (Throwable $e) {
        // columna aún no creada
    }
    return $cache;
}

function esSocia(?int $profesionalId): bool
{
    return $profesionalId !== null && isset(profesionalesSocias()[$profesionalId]);
}

/** «Fernanda Alarcón Ríos y Javiera Montes Lagos». */
function sociasTexto(): string
{
    $n = array_values(profesionalesSocias());
    if (count($n) <= 1) {
        return $n[0] ?? 'las socias';
    }
    return implode(', ', array_slice($n, 0, -1)) . ' y ' . $n[count($n) - 1];
}

/** Obtiene una reserva por id. */
function getReserva(int $id): ?array
{
    try {
        $st = db()->prepare("SELECT * FROM reservas WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (Throwable $e) {
        return null;
    }
}
