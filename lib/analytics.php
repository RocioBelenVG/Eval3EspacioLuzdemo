<?php
/**
 * Analítica del Dashboard. Consultas agregadas sobre `reservas` y `boletas`
 * para un rango de fechas arbitrario. Todo con degradación elegante: si la
 * base de datos falla, se devuelven valores neutros y el panel no se rompe.
 *
 * Convención de día de semana en todo el archivo: 1=Lun … 7=Dom (ISO-8601 / N).
 * El centro atiende de lunes a sábado; el domingo y los feriados no trabajados
 * no son días hábiles (ver lib/feriados.php).
 */

declare(strict_types=1);

require_once APP_ROOT . '/lib/panel.php';

/** Valida y normaliza un Y-m-d; devuelve null si no es una fecha válida. */
function anFecha(?string $s): ?string
{
    if (!$s) {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}

/** Rango [desde, hasta] tomado de $_GET (desde/hasta). Default: mes actual. */
function analyticsRango(): array
{
    $desde = anFecha($_GET['desde'] ?? null);
    $hasta = anFecha($_GET['hasta'] ?? null);
    if (!$desde || !$hasta) {
        $desde = date('Y-m-01');
        $hasta = date('Y-m-t');
    }
    if ($desde > $hasta) {
        [$desde, $hasta] = [$hasta, $desde];
    }
    return [$desde, $hasta];
}

/** Horas operativas de la agenda, como enteros 8..20. */
function anHoras(): array
{
    return range(8, 20);
}

/** Etiquetas cortas de día de semana indexadas 1..7 (Lun..Dom). */
function anDiasLabel(): array
{
    return [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
}

/** KPIs base del rango (reservas por estado, ingresos, boletas, ticket). */
function anKpis(string $desde, string $hasta): array
{
    $out = [
        'reservas' => 0, 'activas' => 0, 'canceladas' => 0, 'no_show' => 0,
        'realizadas' => 0, 'pendientes' => 0, 'confirmadas' => 0,
        // Ingresos: SOLO documentos emitidos (boletas + facturas), sin anuladas.
        'ingresos' => 0, 'documentos' => 0, 'boletas' => 0, 'facturas' => 0, 'ticket' => 0, 'dias_rango' => 0,
    ];
    try {
        $st = db()->prepare("SELECT estado, COUNT(*) n FROM reservas WHERE fecha BETWEEN ? AND ? GROUP BY estado");
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll() as $r) {
            $n = (int) $r['n'];
            $out['reservas'] += $n;
            switch ($r['estado']) {
                case 'cancelada':   $out['canceladas'] = $n;  break;
                case 'no_show':     $out['no_show'] = $n;     break;
                case 'realizada':   $out['realizadas'] = $n;  break;
                case 'pendiente':   $out['pendientes'] = $n;  break;
                case 'confirmada':  $out['confirmadas'] = $n; break;
            }
        }
        $out['activas'] = $out['reservas'] - $out['canceladas'];
    } catch (Throwable $e) {
        // neutros
    }

    $out['ingresos'] = ingresosEntre($desde, $hasta);
    try {
        $st = db()->prepare("SELECT tipo, COUNT(*) n FROM boletas WHERE estado='emitida' AND DATE(fecha_emision) BETWEEN ? AND ? GROUP BY tipo");
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll() as $r) {
            if ($r['tipo'] === 'factura') {
                $out['facturas'] = (int) $r['n'];
            } else {
                $out['boletas'] += (int) $r['n'];
            }
        }
    } catch (Throwable $e) {
        $out['boletas'] = 0;
        $out['facturas'] = 0;
    }
    $out['documentos'] = $out['boletas'] + $out['facturas'];
    $out['ticket'] = $out['documentos'] > 0 ? (int) round($out['ingresos'] / $out['documentos']) : 0;

    try {
        $d0 = new DateTimeImmutable($desde);
        $d1 = new DateTimeImmutable($hasta);
        $out['dias_rango'] = (int) $d0->diff($d1)->days + 1;
    } catch (Throwable $e) {
        $out['dias_rango'] = 0;
    }
    return $out;
}

/** Reservas activas por fecha [Y-m-d => n]. */
function anPorFecha(string $desde, string $hasta): array
{
    $out = [];
    try {
        $st = db()->prepare("SELECT fecha, COUNT(*) n FROM reservas WHERE estado <> 'cancelada' AND fecha BETWEEN ? AND ? GROUP BY fecha");
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll() as $r) {
            $out[$r['fecha']] = (int) $r['n'];
        }
    } catch (Throwable $e) {
        // vacío
    }
    return $out;
}

/** Serie diaria completa del rango (incluye días en cero). */
function anSerieDiaria(string $desde, string $hasta, array $porFecha): array
{
    $out = [];
    try {
        $cur = new DateTimeImmutable($desde);
        $fin = new DateTimeImmutable($hasta);
    } catch (Throwable $e) {
        return $out;
    }
    while ($cur <= $fin) {
        $f = $cur->format('Y-m-d');
        $out[] = ['fecha' => $f, 'n' => $porFecha[$f] ?? 0, 'dow' => (int) $cur->format('N')];
        $cur = $cur->modify('+1 day');
    }
    return $out;
}

/** Reservas activas agrupadas por día de semana [1..7 => n]. */
function anPorDiaSemana(array $serie): array
{
    $out = array_fill(1, 7, 0);
    foreach ($serie as $d) {
        $out[$d['dow']] = ($out[$d['dow']] ?? 0) + $d['n'];
    }
    return $out;
}

/** Reservas activas por hora [8..20 => n]. */
function anPorHora(string $desde, string $hasta): array
{
    $out = array_fill_keys(anHoras(), 0);
    try {
        $st = db()->prepare("SELECT HOUR(hora) h, COUNT(*) n FROM reservas WHERE estado <> 'cancelada' AND hora IS NOT NULL AND fecha BETWEEN ? AND ? GROUP BY h");
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll() as $r) {
            $h = (int) $r['h'];
            if (array_key_exists($h, $out)) {
                $out[$h] = (int) $r['n'];
            }
        }
    } catch (Throwable $e) {
        // vacío
    }
    return $out;
}

/** Mapa de calor [diaSemana 1..7][hora 8..20] => n (reservas activas). */
function anHeatmap(string $desde, string $hasta): array
{
    $out = [];
    for ($d = 1; $d <= 7; $d++) {
        $out[$d] = array_fill_keys(anHoras(), 0);
    }
    try {
        $st = db()->prepare("SELECT WEEKDAY(fecha) wd, HOUR(hora) h, COUNT(*) n FROM reservas WHERE estado <> 'cancelada' AND hora IS NOT NULL AND fecha BETWEEN ? AND ? GROUP BY wd, h");
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll() as $r) {
            $d = ((int) $r['wd']) + 1; // WEEKDAY: 0=Lun..6=Dom -> 1..7
            $h = (int) $r['h'];
            if (isset($out[$d]) && array_key_exists($h, $out[$d])) {
                $out[$d][$h] = (int) $r['n'];
            }
        }
    } catch (Throwable $e) {
        // vacío
    }
    return $out;
}

/** Reservas activas por box [slug => n], ordenado desc. */
function anPorBox(string $desde, string $hasta): array
{
    $out = [];
    try {
        $st = db()->prepare("SELECT box_slug, COUNT(*) n FROM reservas WHERE estado <> 'cancelada' AND fecha BETWEEN ? AND ? GROUP BY box_slug");
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll() as $r) {
            $out[$r['box_slug']] = (int) $r['n'];
        }
    } catch (Throwable $e) {
        // vacío
    }
    arsort($out);
    return $out;
}

/** Distribución por estado [estado => n]. */
function anEstados(string $desde, string $hasta): array
{
    $out = [];
    try {
        $st = db()->prepare("SELECT estado, COUNT(*) n FROM reservas WHERE fecha BETWEEN ? AND ? GROUP BY estado");
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll() as $r) {
            $out[$r['estado']] = (int) $r['n'];
        }
    } catch (Throwable $e) {
        // vacío
    }
    return $out;
}

/** Ingresos emitidos por fecha [Y-m-d => total_clp]. */
function anIngresosPorFecha(string $desde, string $hasta): array
{
    $out = [];
    try {
        $st = db()->prepare("SELECT DATE(fecha_emision) f, COALESCE(SUM(total_clp),0) t FROM boletas WHERE estado='emitida' AND DATE(fecha_emision) BETWEEN ? AND ? GROUP BY f");
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll() as $r) {
            $out[$r['f']] = (int) $r['t'];
        }
        // Notas de crédito: restan el día en que se emitieron.
        $st = db()->prepare("SELECT DATE(n.fecha_emision) f, COALESCE(SUM(n.total_clp),0) t FROM notas_credito n JOIN boletas b ON b.id = n.documento_id
                              WHERE n.estado='emitida' AND b.estado='emitida' AND DATE(n.fecha_emision) BETWEEN ? AND ? GROUP BY f");
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll() as $r) {
            $out[$r['f']] = ($out[$r['f']] ?? 0) - (int) $r['t'];
        }
        // Notas de débito: suman el día en que se emitieron.
        try {
            $st = db()->prepare("SELECT DATE(n.fecha_emision) f, COALESCE(SUM(n.total_clp),0) t FROM notas_debito n JOIN boletas b ON b.id = n.documento_id
                                  WHERE n.estado='emitida' AND b.estado='emitida' AND DATE(n.fecha_emision) BETWEEN ? AND ? GROUP BY f");
            $st->execute([$desde, $hasta]);
            foreach ($st->fetchAll() as $r) {
                $out[$r['f']] = ($out[$r['f']] ?? 0) + (int) $r['t'];
            }
        } catch (Throwable $e) {
        }
    } catch (Throwable $e) {
        // vacío
    }
    return $out;
}

/**
 * Tasa de ocupación estimada: reservas activas / capacidad teórica.
 * Capacidad = nBoxes × nHoras × díasOperativos (días del rango que no son domingo).
 */
function anOcupacion(string $desde, string $hasta, int $activas): array
{
    $nBoxes = count($GLOBALS['BOXES'] ?? []) ?: 4;
    $nHoras = count(anHoras());
    $diasOp = 0;
    try {
        $cur = new DateTimeImmutable($desde);
        $fin = new DateTimeImmutable($hasta);
        while ($cur <= $fin) {
            if ((int) $cur->format('N') !== 7) {
                $diasOp++;
            }
            $cur = $cur->modify('+1 day');
        }
    } catch (Throwable $e) {
        $diasOp = 0;
    }
    $cap = $nBoxes * $nHoras * $diasOp;
    $pct = $cap > 0 ? round($activas / $cap * 100, 1) : 0.0;
    return ['capacidad' => $cap, 'pct' => $pct, 'dias_op' => $diasOp, 'boxes' => $nBoxes, 'horas' => $nHoras];
}

/**
 * Días operativos (no domingo) del rango sin ninguna reserva activa.
 * Devuelve ['fechas' => [Y-m-d, ...], 'total' => n].
 */
function anDiasSinReservas(array $serie): array
{
    $fechas = [];
    foreach ($serie as $d) {
        if ($d['dow'] !== 7 && $d['n'] === 0) {
            $fechas[] = $d['fecha'];
        }
    }
    return ['fechas' => $fechas, 'total' => count($fechas)];
}

/**
 * Horarios con menor demanda en el rango. Devuelve la lista ordenada asc por
 * cantidad: ['hora' => 8, 'n' => 0], ... y cuántas franjas quedaron en cero.
 */
function anHorariosBajos(array $porHora): array
{
    $items = [];
    foreach ($porHora as $h => $n) {
        $items[] = ['hora' => (int) $h, 'n' => (int) $n];
    }
    usort($items, static fn($a, $b) => $a['n'] <=> $b['n'] ?: $a['hora'] <=> $b['hora']);
    $cero = array_values(array_filter($items, static fn($i) => $i['n'] === 0));
    return ['items' => $items, 'cero' => $cero, 'total_cero' => count($cero)];
}

/* =====================================================================
 * Ocupación profesional de boxes, días hábiles y demanda por especialidad.
 *
 * Días hábiles: los días en que atiende el centro según «Días y horarios» (lib/horario.php),
 * sin feriados marcados como no trabajados (lib/feriados.php) y desde el inicio de los registros.
 * Ocupación de boxes = horas de box ocupadas ÷ horas de box disponibles.
 *   - Disponibles = boxes activos × bloques de 1 hora del horario del centro de cada día hábil.
 *   - Ocupadas = combinaciones DISTINTAS de box + fecha + hora con al menos una
 *     reserva vigente (pendiente, confirmada, realizada o inasistencia). Dos
 *     reservas en el mismo box y hora ocupan una sola hora de box.
 *   - Efectiva: igual, pero sin contar las horas en que todas fueron inasistencias.
 * ===================================================================== */

/** Primer día con datos en el sistema (Y-m-d) o null si aún no hay reservas. */
function anInicioRegistros(): ?string
{
    static $ini = false;
    if ($ini === false) {
        $ini = null;
        try {
            $r = db()->query("SELECT LEAST(MIN(fecha), MIN(DATE(creado_en))) FROM reservas")->fetchColumn();
            $ini = $r ? (string) $r : null;
        } catch (Throwable $e) {
        }
    }
    return $ini;
}

/** Días hábiles del rango que cuentan para los indicadores (desde el inicio de registros). */
function anDiasHabiles(string $desde, string $hasta): array
{
    $ini = anInicioRegistros();
    $desdeEf = ($ini !== null && $ini > $desde) ? $ini : $desde;
    $previos = $desdeEf > $desde ? count(diasHabilesEntre($desde, date('Y-m-d', strtotime($desdeEf . ' -1 day')))) : 0;
    $habiles = $desdeEf <= $hasta ? diasHabilesEntre($desdeEf, $hasta) : [];
    $cerrados = [];
    foreach (feriadosEntre($desde, $hasta) as $f) {
        if (!$f['se_trabaja'] && !esDomingo($f['fecha'])) {
            $cerrados[] = $f;
        }
    }
    return ['dias' => $habiles, 'n' => count($habiles), 'desde_efectivo' => $desdeEf, 'previos_excluidos' => $previos,
        'inicio' => $ini, 'feriados_cerrados' => $cerrados];
}

/** Boxes activos [slug => nombre]. */
function anBoxesActivos(): array
{
    $out = [];
    foreach ($GLOBALS['BOXES'] ?? [] as $b) {
        $out[(string) $b['slug']] = (string) $b['nombre'];
    }
    return $out;
}

/**
 * Ocupación de boxes en horas de box (ver cabecera de la sección).
 * Devuelve totales, detalle por box, mapa de calor en % (día 1..6 × hora) y
 * cuántas reservas quedaron fuera del horario de atención.
 */
function anOcupacionBoxes(string $desde, string $hasta): array
{
    $hab = anDiasHabiles($desde, $hasta);
    $boxes = anBoxesActivos();
    $slots = horasAgenda();
    $diasSet = array_flip($hab['dias']);
    // Bloques de 1 hora en que atiende el centro cada día de la semana («Días y horarios»).
    $slotsDow = [];
    for ($d = 1; $d <= 7; $d++) {
        $slotsDow[$d] = array_flip(horarioBloquesDow($d));
    }
    $horasBox = 0;   // horas disponibles de UN box en el rango
    $porDia = [];
    foreach ($hab['dias'] as $f) {
        $n = count($slotsDow[(int) date('N', strtotime($f))]);
        $horasBox += $n;
        $porDia[$n] = true;
    }
    $uniforme = count($porDia) <= 1;
    $nSlots = $porDia ? max(array_keys($porDia)) : count($slots);

    // Días hábiles por día de semana (para el mapa de calor en %).
    $diasPorDow = array_fill(1, 6, 0);
    foreach ($hab['dias'] as $f) {
        $diasPorDow[(int) date('N', strtotime($f))]++;
    }

    $porBox = [];
    foreach ($boxes as $slug => $nombre) {
        $porBox[$slug] = ['nombre' => $nombre, 'ocupadas' => 0, 'efectivas' => 0, 'capacidad' => $horasBox, 'pct' => 0.0];
    }
    $heat = [];
    for ($d = 1; $d <= 6; $d++) {
        $heat[$d] = array_fill_keys($slots, 0);
    }
    $ocupadas = 0; $efectivas = 0; $fuera = 0; $fueraDias = [];

    try {
        $st = db()->prepare(
            "SELECT box_slug, fecha, TIME_FORMAT(hora, '%H:%i') AS h, COUNT(*) AS n, SUM(estado <> 'no_show') AS asistidas
               FROM reservas
              WHERE estado <> 'cancelada' AND hora IS NOT NULL AND fecha BETWEEN ? AND ?
              GROUP BY box_slug, fecha, hora"
        );
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll() as $r) {
            $slug = (string) $r['box_slug'];
            $f = (string) $r['fecha'];
            if (!isset($diasSet[$f]) || !isset($slotsDow[(int) date('N', strtotime($f))][$r['h']])) {
                // Día sin atención, feriado no trabajado u hora fuera del horario del centro: no entra al %.
                $fuera += (int) $r['n'];
                $fueraDias[$f] = true;
                continue;
            }
            if (!isset($porBox[$slug])) {
                continue; // box archivado o inactivo: no es capacidad vigente
            }
            $ocupadas++;
            $porBox[$slug]['ocupadas']++;
            if ((int) $r['asistidas'] > 0) {
                $efectivas++;
                $porBox[$slug]['efectivas']++;
            }
            $heat[(int) date('N', strtotime($f))][$r['h']]++;
        }
    } catch (Throwable $e) {
        // neutros
    }

    $capacidad = count($boxes) * $horasBox;
    foreach ($porBox as &$b) {
        $b['pct'] = $b['capacidad'] > 0 ? round($b['ocupadas'] / $b['capacidad'] * 100, 1) : 0.0;
    }
    unset($b);
    uasort($porBox, static fn($a, $b) => $b['pct'] <=> $a['pct'] ?: strcmp($a['nombre'], $b['nombre']));

    // Mapa de calor: % de boxes ocupados en ese día de semana y hora.
    $heatPct = [];
    foreach ($heat as $d => $fila) {
        foreach ($fila as $h => $n) {
            $cap = isset($slotsDow[$d][$h]) ? count($boxes) * $diasPorDow[$d] : 0;
            $heatPct[$d][$h] = ['n' => $n, 'cap' => $cap, 'pct' => $cap > 0 ? round($n / $cap * 100) : null];
        }
    }

    return [
        'pct' => $capacidad > 0 ? round($ocupadas / $capacidad * 100, 1) : 0.0,
        'pct_efectiva' => $capacidad > 0 ? round($efectivas / $capacidad * 100, 1) : 0.0,
        'ocupadas' => $ocupadas, 'efectivas' => $efectivas, 'capacidad' => $capacidad, 'libres' => max(0, $capacidad - $ocupadas),
        'boxes' => count($boxes), 'horas' => $nSlots, 'horas_box' => $horasBox, 'uniforme' => $uniforme, 'dias' => $hab['n'], 'habiles' => $hab,
        'por_box' => $porBox, 'heat' => $heatPct, 'fuera' => $fuera, 'fuera_dias' => count($fueraDias),
    ];
}

/** Días hábiles (desde el inicio de registros) sin ninguna reserva vigente. */
function anDiasHabilesSinReservas(array $habiles, array $porFecha): array
{
    $fechas = array_values(array_filter($habiles['dias'], static fn($f) => empty($porFecha[$f])));
    return ['fechas' => $fechas, 'total' => count($fechas)];
}

/**
 * Boxes sin ninguna reserva vigente en cada día hábil del rango (desde el inicio de registros).
 * Un "día de box sin reservas" = un box activo en un día hábil sin reservas no canceladas.
 * Devuelve el detalle por box (fechas libres), por día (boxes libres), el total de
 * días de box libres, la capacidad (boxes × días hábiles) y los días con todos los boxes vacíos.
 */
function anBoxesSinReservas(string $desde, string $hasta, array $habiles): array
{
    $boxes = anBoxesActivos();
    $con = []; // "fecha|slug" => true cuando el box tuvo al menos una reserva vigente ese día
    try {
        $st = db()->prepare("SELECT DISTINCT fecha, box_slug FROM reservas WHERE estado <> 'cancelada' AND fecha BETWEEN ? AND ?");
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll() as $r) {
            $con[$r['fecha'] . '|' . $r['box_slug']] = true;
        }
    } catch (Throwable $e) {
        // sin datos: todos libres
    }
    $porBox = [];
    foreach ($boxes as $slug => $nombre) {
        $porBox[$slug] = ['slug' => $slug, 'nombre' => $nombre, 'fechas' => [], 'n' => 0];
    }
    $porDia = [];
    $todosLibres = 0;
    foreach ($habiles['dias'] as $f) {
        $libres = [];
        foreach ($boxes as $slug => $nombre) {
            if (!isset($con[$f . '|' . $slug])) {
                $libres[] = $slug;
                $porBox[$slug]['fechas'][] = $f;
                $porBox[$slug]['n']++;
            }
        }
        if ($libres) {
            $porDia[$f] = $libres;
        }
        if ($boxes && count($libres) === count($boxes)) {
            $todosLibres++;
        }
    }
    uasort($porBox, static fn($a, $b) => $b['n'] <=> $a['n'] ?: strcmp($a['nombre'], $b['nombre']));
    return [
        'por_box' => $porBox, 'por_dia' => $porDia, 'total' => array_sum(array_column($porBox, 'n')),
        'capacidad' => count($boxes) * $habiles['n'], 'dias' => $habiles['n'], 'boxes' => count($boxes),
        'dias_todos_libres' => $todosLibres,
    ];
}

/** Rango anterior de igual largo, inmediatamente antes de [desde, hasta]. */
function anRangoAnterior(string $desde, string $hasta): array
{
    $d0 = new DateTimeImmutable($desde);
    $n = (int) $d0->diff(new DateTimeImmutable($hasta))->days + 1;
    return [$d0->modify('-' . $n . ' days')->format('Y-m-d'), $d0->modify('-1 day')->format('Y-m-d')];
}

/** Horas reservadas (vigentes) por especialidad en un rango: [slug => horas]. */
function anHorasPorEspecialidad(string $desde, string $hasta): array
{
    $out = [];
    try {
        $st = db()->prepare(
            "SELECT p.especialidad_slug AS esp, SUM(COALESCE(r.duracion_min, 60)) / 60 AS horas
               FROM reservas r JOIN profesionales p ON p.id = r.profesional_id
              WHERE r.estado <> 'cancelada' AND r.fecha BETWEEN ? AND ?
              GROUP BY p.especialidad_slug"
        );
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll() as $r) {
            $out[(string) $r['esp']] = round((float) $r['horas'], 1);
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/**
 * Demanda por especialidad en el rango (reservas vigentes: no canceladas).
 * Incluye todas las especialidades activas con al menos un profesional activo,
 * aunque no tengan reservas (para detectar la de menor demanda real).
 * Medida principal: horas reservadas (cada reserva dura su duración o 1 hora).
 */
function anEspecialidades(string $desde, string $hasta): array
{
    $filas = [];
    try {
        $rows = db()->query(
            "SELECT e.slug, e.nombre, COUNT(p.id) AS n_prof
               FROM especialidades e
               JOIN profesionales p ON p.especialidad_slug = e.slug AND p.activo = 1 AND p.eliminado_at IS NULL
              WHERE e.activo = 1
              GROUP BY e.slug, e.nombre"
        )->fetchAll();
        foreach ($rows as $r) {
            $filas[(string) $r['slug']] = ['slug' => (string) $r['slug'], 'nombre' => (string) $r['nombre'], 'profesionales' => (int) $r['n_prof'],
                'reservas' => 0, 'horas' => 0.0, 'prof_activos' => 0, 'pacientes' => 0, 'atenciones' => 0, 'arriendos' => 0,
                'realizadas' => 0, 'no_show' => 0, 'canceladas' => 0, 'horas_ant' => 0.0, 'var' => null, 'pct' => 0.0];
        }
        $st = db()->prepare(
            "SELECT p.especialidad_slug AS esp,
                    SUM(r.estado <> 'cancelada') AS reservas,
                    SUM(CASE WHEN r.estado <> 'cancelada' THEN COALESCE(r.duracion_min, 60) ELSE 0 END) / 60 AS horas,
                    COUNT(DISTINCT CASE WHEN r.estado <> 'cancelada' THEN r.profesional_id END) AS prof_activos,
                    COUNT(DISTINCT CASE WHEN r.estado <> 'cancelada' THEN r.paciente_id END) AS pacientes,
                    SUM(r.estado <> 'cancelada' AND p.socia = 1) AS atenciones,
                    SUM(r.estado <> 'cancelada' AND p.socia = 0) AS arriendos,
                    SUM(r.estado = 'realizada') AS realizadas,
                    SUM(r.estado = 'no_show') AS no_show,
                    SUM(r.estado = 'cancelada') AS canceladas
               FROM reservas r JOIN profesionales p ON p.id = r.profesional_id
              WHERE r.fecha BETWEEN ? AND ?
              GROUP BY p.especialidad_slug"
        );
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll() as $r) {
            $slug = (string) $r['esp'];
            if (!isset($filas[$slug])) {
                continue; // especialidad inactiva o sin profesionales activos
            }
            foreach (['reservas', 'prof_activos', 'pacientes', 'atenciones', 'arriendos', 'realizadas', 'no_show', 'canceladas'] as $k) {
                $filas[$slug][$k] = (int) $r[$k];
            }
            $filas[$slug]['horas'] = round((float) $r['horas'], 1);
        }
    } catch (Throwable $e) {
        return ['filas' => [], 'mayor' => [], 'menor' => [], 'total_horas' => 0.0, 'sin_profesional' => 0, 'anterior' => null];
    }

    // Variación contra el período anterior de igual largo.
    [$aDesde, $aHasta] = anRangoAnterior($desde, $hasta);
    $ant = anHorasPorEspecialidad($aDesde, $aHasta);
    $hayAnterior = array_sum($ant) > 0;
    $total = 0.0;
    foreach ($filas as &$f) {
        $f['horas_ant'] = $ant[$f['slug']] ?? 0.0;
        $f['var'] = ($hayAnterior && $f['horas_ant'] > 0) ? round(($f['horas'] - $f['horas_ant']) / $f['horas_ant'] * 100) : null;
        $total += $f['horas'];
    }
    unset($f);
    foreach ($filas as &$f) {
        $f['pct'] = $total > 0 ? round($f['horas'] / $total * 100, 1) : 0.0;
    }
    unset($f);
    uasort($filas, static fn($a, $b) => $b['horas'] <=> $a['horas'] ?: strcmp($a['nombre'], $b['nombre']));
    $filas = array_values($filas);

    // Mayor y menor demanda (con empates). Si nadie tiene horas, no hay ganador.
    $mayor = []; $menor = [];
    if ($filas && $total > 0) {
        $max = $filas[0]['horas'];
        $min = $filas[count($filas) - 1]['horas'];
        $mayor = array_values(array_filter($filas, static fn($f) => $f['horas'] === $max));
        $menor = $min < $max ? array_values(array_filter($filas, static fn($f) => $f['horas'] === $min)) : [];
    }

    $sinProf = 0;
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM reservas WHERE estado <> 'cancelada' AND profesional_id IS NULL AND fecha BETWEEN ? AND ?");
        $st->execute([$desde, $hasta]);
        $sinProf = (int) $st->fetchColumn();
    } catch (Throwable $e) {
    }

    return ['filas' => $filas, 'mayor' => $mayor, 'menor' => $menor, 'total_horas' => round($total, 1),
        'sin_profesional' => $sinProf, 'anterior' => $hayAnterior ? [$aDesde, $aHasta] : null];
}

/**
 * Ranking de profesionales en el rango: horas reservadas, pacientes distintos,
 * cancelaciones, inasistencias y, si tienen horario de atención configurado,
 * el uso de ese horario (horas reservadas dentro de su disponibilidad en días hábiles).
 */
function anProfesionales(string $desde, string $hasta, array $habiles): array
{
    $out = [];
    try {
        $st = db()->prepare(
            "SELECT p.id, p.nombre, p.socia, e.nombre AS especialidad,
                    SUM(r.estado <> 'cancelada') AS reservas,
                    SUM(CASE WHEN r.estado <> 'cancelada' THEN COALESCE(r.duracion_min, 60) ELSE 0 END) / 60 AS horas,
                    COUNT(DISTINCT CASE WHEN r.estado <> 'cancelada' THEN r.paciente_id END) AS pacientes,
                    SUM(r.estado = 'realizada') AS realizadas,
                    SUM(r.estado = 'no_show') AS no_show,
                    SUM(r.estado = 'cancelada') AS canceladas
               FROM profesionales p
          LEFT JOIN especialidades e ON e.slug = p.especialidad_slug
               JOIN reservas r ON r.profesional_id = p.id AND r.fecha BETWEEN ? AND ?
              GROUP BY p.id, p.nombre, p.socia, e.nombre"
        );
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['id']] = ['id' => (int) $r['id'], 'nombre' => (string) $r['nombre'], 'socia' => (int) $r['socia'] === 1,
                'especialidad' => (string) ($r['especialidad'] ?? ''), 'reservas' => (int) $r['reservas'], 'horas' => round((float) $r['horas'], 1),
                'pacientes' => (int) $r['pacientes'], 'realizadas' => (int) $r['realizadas'], 'no_show' => (int) $r['no_show'],
                'canceladas' => (int) $r['canceladas'], 'disponibles' => null, 'uso' => null];
        }
    } catch (Throwable $e) {
        return [];
    }

    // Uso del horario configurado (tabla disponibilidad): horas reservadas DENTRO de sus
    // franjas de atención en los días hábiles ÷ horas de esas franjas en el rango.
    try {
        $disp = []; // pid => dow => [[iniSeg, finSeg], ...]
        foreach (db()->query("SELECT profesional_id, dia_semana, TIME_TO_SEC(hora_inicio) AS i, TIME_TO_SEC(hora_fin) AS f FROM disponibilidad WHERE activo = 1")->fetchAll() as $d) {
            $disp[(int) $d['profesional_id']][(int) $d['dia_semana']][] = [(int) $d['i'], (int) $d['f']];
        }
        $diasSet = array_flip($habiles['dias']);
        foreach ($out as $pid => &$p) {
            if (empty($disp[$pid])) {
                continue;
            }
            $hDisp = 0.0;
            foreach ($habiles['dias'] as $f) {
                foreach ($disp[$pid][(int) date('N', strtotime($f))] ?? [] as [$i, $fn]) {
                    $hDisp += max(0, $fn - $i) / 3600;
                }
            }
            if ($hDisp <= 0) {
                continue;
            }
            $usadas = 0.0;
            $st = db()->prepare("SELECT fecha, TIME_TO_SEC(hora) AS s, COALESCE(duracion_min, 60) AS m FROM reservas
                                  WHERE profesional_id = ? AND estado <> 'cancelada' AND hora IS NOT NULL AND fecha BETWEEN ? AND ?");
            $st->execute([$pid, $desde, $hasta]);
            foreach ($st->fetchAll() as $r) {
                if (!isset($diasSet[$r['fecha']])) {
                    continue;
                }
                $ri = (int) $r['s'];
                $rf = $ri + (int) $r['m'] * 60;
                foreach ($disp[$pid][(int) date('N', strtotime($r['fecha']))] ?? [] as [$i, $fn]) {
                    $usadas += max(0, min($rf, $fn) - max($ri, $i)) / 3600; // parte de la reserva dentro de la franja
                }
            }
            $p['disponibles'] = round($hDisp, 1);
            $p['uso'] = round(min($usadas, $hDisp) / $hDisp * 100, 1);
            $p['uso_horas'] = round($usadas, 1);
        }
        unset($p);
    } catch (Throwable $e) {
    }

    usort($out, static fn($a, $b) => $b['horas'] <=> $a['horas'] ?: strcmp($a['nombre'], $b['nombre']));
    return $out;
}

/** Atenciones de las socias vs arriendo de box (profesionales externos), reservas vigentes. */
function anTipoUso(string $desde, string $hasta): array
{
    $out = ['atenciones' => 0, 'arriendos' => 0, 'sin_profesional' => 0, 'h_atenciones' => 0.0, 'h_arriendos' => 0.0];
    try {
        $st = db()->prepare(
            "SELECT CASE WHEN r.profesional_id IS NULL THEN 'sin' WHEN p.socia = 1 THEN 'socia' ELSE 'externo' END AS t,
                    COUNT(*) AS n, SUM(COALESCE(r.duracion_min, 60)) / 60 AS h
               FROM reservas r LEFT JOIN profesionales p ON p.id = r.profesional_id
              WHERE r.estado <> 'cancelada' AND r.fecha BETWEEN ? AND ?
              GROUP BY t"
        );
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll() as $r) {
            if ($r['t'] === 'socia') {
                $out['atenciones'] = (int) $r['n'];
                $out['h_atenciones'] = round((float) $r['h'], 1);
            } elseif ($r['t'] === 'externo') {
                $out['arriendos'] = (int) $r['n'];
                $out['h_arriendos'] = round((float) $r['h'], 1);
            } else {
                $out['sin_profesional'] = (int) $r['n'];
            }
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** Pacientes distintos (con ficha) con al menos una reserva vigente en el rango. */
function anPacientesDistintos(string $desde, string $hasta): int
{
    try {
        $st = db()->prepare("SELECT COUNT(DISTINCT paciente_id) FROM reservas WHERE estado <> 'cancelada' AND paciente_id IS NOT NULL AND fecha BETWEEN ? AND ?");
        $st->execute([$desde, $hasta]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Formato de horas: 158 → "158 h", 12.5 → "12,5 h". */
function anHorasFmt(float $h): string
{
    return (fmod($h, 1.0) == 0.0 ? number_format($h, 0, ',', '.') : number_format($h, 1, ',', '.')) . ' h';
}

/** Porcentaje con coma decimal: 14.1 → "14,1 %". */
function anPctFmt(float $p): string
{
    return number_format($p, fmod($p, 1.0) == 0.0 ? 0 : 1, ',', '.') . ' %';
}

/* =====================================================================
 * Indicadores para contar la historia del período (de lo general a lo particular):
 * tipo de atención, ingresos por especialidad, evolución mensual, día de la semana
 * con más y menos atención, y box más solicitado por cada especialidad.
 * ===================================================================== */

/** Reservas vigentes por tipo de atención (reservas.tipo_atencion; null = sin registrar). */
function anTiposAtencion(string $desde, string $hasta): array
{
    $filas = []; $con = 0; $sin = 0;
    foreach (RESERVA_TIPOS_ATENCION as $k => $n) {
        $filas[$k] = ['clave' => $k, 'nombre' => $n, 'n' => 0, 'pct' => 0.0];
    }
    try {
        $st = db()->prepare("SELECT tipo_atencion AS t, COUNT(*) AS n FROM reservas WHERE estado <> 'cancelada' AND fecha BETWEEN ? AND ? GROUP BY tipo_atencion");
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll() as $r) {
            $t = (string) ($r['t'] ?? '');
            if ($t !== '' && isset($filas[$t])) {
                $filas[$t]['n'] = (int) $r['n'];
                $con += (int) $r['n'];
            } else {
                $sin += (int) $r['n'];
            }
        }
    } catch (Throwable $e) {
    }
    foreach ($filas as &$f) {
        $f['pct'] = $con > 0 ? round($f['n'] / $con * 100, 1) : 0.0;
    }
    unset($f);
    return ['filas' => $filas, 'con_tipo' => $con, 'sin_tipo' => $sin, 'total' => $con + $sin];
}

/**
 * Reparte un monto entre claves según pesos, en pesos enteros y sin perder ni sumar
 * de más (el resto del redondeo va a la clave con mayor peso).
 */
function anRepartir(int $monto, array $pesos): array
{
    $suma = array_sum($pesos);
    if (!$pesos || $suma <= 0) {
        return [];
    }
    $out = []; $asignado = 0;
    foreach ($pesos as $k => $p) {
        $out[$k] = (int) floor($monto * $p / $suma);
        $asignado += $out[$k];
    }
    arsort($pesos);
    $out[array_key_first($pesos)] += $monto - $asignado;
    return $out;
}

/**
 * Ingresos del período por especialidad. Cada documento emitido se atribuye a la
 * especialidad del profesional de cada ítem (o del documento); las facturas a clientes
 * sin profesional son «Arriendo de boxes». Las notas de crédito/débito del período restan/suman
 * en la misma proporción que su documento. El total coincide con «Ingresos del período».
 */
function anIngresosPorEspecialidad(string $desde, string $hasta): array
{
    $espDe = []; $nombres = [];
    try {
        foreach (db()->query("SELECT p.id, p.especialidad_slug, e.nombre FROM profesionales p LEFT JOIN especialidades e ON e.slug = p.especialidad_slug") as $r) {
            $espDe[(int) $r['id']] = (string) ($r['especialidad_slug'] ?? '');
            if (!empty($r['especialidad_slug'])) $nombres['esp:' . $r['especialidad_slug']] = (string) ($r['nombre'] ?? $r['especialidad_slug']);
        }
    } catch (Throwable $e) {
    }
    $nombres['arriendo'] = 'Arriendo de boxes (facturas a clientes)';
    $nombres['sin'] = 'Sin profesional asignado';

    // Proporción de cada documento por clave (según sus ítems).
    $pesosDoc = static function (array $doc, array $items) use ($espDe): array {
        $clave = static function (?int $pid) use ($doc, $espDe): string {
            $pid = $pid ?: ($doc['profesional_id'] !== null ? (int) $doc['profesional_id'] : null);
            if ($pid && ($espDe[$pid] ?? '') !== '') return 'esp:' . $espDe[$pid];
            return ($doc['tipo'] === 'factura' || !empty($doc['cliente_id'])) ? 'arriendo' : 'sin';
        };
        $pesos = [];
        foreach ($items as $it) {
            $k = $clave($it['profesional_id'] !== null ? (int) $it['profesional_id'] : null);
            $pesos[$k] = ($pesos[$k] ?? 0) + max(0, (int) $it['total_clp']);
        }
        return array_sum($pesos) > 0 ? $pesos : [$clave(null) => 1];
    };
    $cacheDocs = [];
    $doc = static function (int $id) use (&$cacheDocs, $pesosDoc): ?array {
        if (!array_key_exists($id, $cacheDocs)) {
            $st = db()->prepare("SELECT id, tipo, total_clp, profesional_id, cliente_id FROM boletas WHERE id = ? AND estado = 'emitida'");
            $st->execute([$id]);
            $d = $st->fetch() ?: null;
            if ($d) {
                $si = db()->prepare("SELECT profesional_id, total_clp FROM boleta_items WHERE boleta_id = ?");
                $si->execute([$id]);
                $d['pesos'] = $pesosDoc($d, $si->fetchAll());
            }
            $cacheDocs[$id] = $d;
        }
        return $cacheDocs[$id];
    };

    $tot = []; $docs = [];
    try {
        $st = db()->prepare("SELECT id FROM boletas WHERE estado = 'emitida' AND DATE(fecha_emision) BETWEEN ? AND ?");
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $d = $doc((int) $id);
            if (!$d) continue;
            foreach (anRepartir((int) $d['total_clp'], $d['pesos']) as $k => $m) {
                $tot[$k] = ($tot[$k] ?? 0) + $m;
                $docs[$k][(int) $id] = true;
            }
        }
        foreach ([['notas_credito', -1], ['notas_debito', 1]] as [$tabla, $signo]) {
            try {
                $st = db()->prepare("SELECT n.documento_id, n.total_clp FROM $tabla n JOIN boletas b ON b.id = n.documento_id
                                      WHERE n.estado = 'emitida' AND b.estado = 'emitida' AND DATE(n.fecha_emision) BETWEEN ? AND ?");
                $st->execute([$desde, $hasta]);
                foreach ($st->fetchAll() as $n) {
                    $d = $doc((int) $n['documento_id']);
                    if (!$d) continue;
                    foreach (anRepartir((int) $n['total_clp'], $d['pesos']) as $k => $m) {
                        $tot[$k] = ($tot[$k] ?? 0) + $signo * $m;
                    }
                }
            } catch (Throwable $e) {
                // tabla de notas de débito aún no existe
            }
        }
    } catch (Throwable $e) {
        return ['filas' => [], 'total' => 0];
    }
    $total = array_sum($tot);
    $filas = [];
    foreach ($tot as $k => $m) {
        if ($m === 0) continue;   // p. ej. un documento anulado con nota de crédito en el mismo período
        $filas[] = ['clave' => $k, 'nombre' => $nombres[$k] ?? $k, 'total' => $m, 'docs' => count($docs[$k] ?? []),
            'pct' => $total > 0 ? round($m / $total * 100, 1) : 0.0];
    }
    usort($filas, static fn($a, $b) => $b['total'] <=> $a['total']);
    return ['filas' => $filas, 'total' => $total];
}

/**
 * Evolución mensual hasta el mes de $hasta (máximo $meses meses, sin meses anteriores al
 * inicio de los registros): reservas vigentes, evaluaciones, horas con profesional e ingresos.
 */
function anMensual(string $hasta, int $meses = 12): array
{
    $mesesN = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $mesesL = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $fin = new DateTimeImmutable(date('Y-m-01', strtotime($hasta)));
    $ini = $fin->modify('-' . ($meses - 1) . ' months');
    $inicio = anInicioRegistros();
    if ($inicio !== null) {
        $ini0 = new DateTimeImmutable(date('Y-m-01', strtotime($inicio)));
        if ($ini0 > $ini) $ini = $ini0;
    }
    if ($ini > $fin) $ini = $fin;
    $out = [];
    for ($m = $ini; $m <= $fin; $m = $m->modify('+1 month')) {
        $out[$m->format('Y-m')] = ['ym' => $m->format('Y-m'), 'label' => $mesesN[(int) $m->format('n')] . ' ' . $m->format('y'),
            'nombre' => $mesesL[(int) $m->format('n')] . ' de ' . $m->format('Y'), 'reservas' => 0, 'evaluaciones' => 0, 'horas' => 0.0,
            'ingresos' => ingresosEntre($m->format('Y-m-01'), $m->format('Y-m-t'))];
    }
    try {
        $st = db()->prepare("SELECT DATE_FORMAT(fecha, '%Y-%m') AS ym, COUNT(*) AS n, SUM(tipo_atencion = 'evaluacion') AS ev,
                                    SUM(CASE WHEN profesional_id IS NOT NULL THEN COALESCE(duracion_min, 60) ELSE 0 END) / 60 AS h
                               FROM reservas WHERE estado <> 'cancelada' AND fecha BETWEEN ? AND ? GROUP BY ym");
        $st->execute([$ini->format('Y-m-01'), $fin->format('Y-m-t')]);
        foreach ($st->fetchAll() as $r) {
            if (isset($out[$r['ym']])) {
                $out[$r['ym']]['reservas'] = (int) $r['n'];
                $out[$r['ym']]['evaluaciones'] = (int) $r['ev'];
                $out[$r['ym']]['horas'] = round((float) $r['h'], 1);
            }
        }
    } catch (Throwable $e) {
    }
    $lista = array_values($out);
    $mayor = null; $mayorEv = null;
    foreach ($lista as $x) {
        if ($x['reservas'] > 0 && ($mayor === null || $x['reservas'] > $mayor['reservas'])) $mayor = $x;
        if ($x['evaluaciones'] > 0 && ($mayorEv === null || $x['evaluaciones'] > $mayorEv['evaluaciones'])) $mayorEv = $x;
    }
    return ['meses' => $lista, 'mayor' => $mayor, 'mayor_eval' => $mayorEv,
        'con_datos' => count(array_filter($lista, static fn($x) => $x['reservas'] > 0)), 'desde' => $ini->format('Y-m'), 'hasta' => $fin->format('Y-m')];
}

/**
 * Promedio de reservas vigentes por día hábil de cada día de la semana (para comparar
 * días aunque el período tenga distinto número de lunes, martes, etc.). Indica el día con
 * más y con menos atención.
 */
function anDiaSemanaPromedio(array $porFecha, array $habiles): array
{
    $dias = []; $res = [];
    foreach ($habiles['dias'] as $f) {
        $d = (int) date('N', strtotime($f));
        $dias[$d] = ($dias[$d] ?? 0) + 1;
        $res[$d] = ($res[$d] ?? 0) + (int) ($porFecha[$f] ?? 0);
    }
    $filas = [];
    foreach ($dias as $d => $n) {
        $filas[$d] = ['dow' => $d, 'nombre' => HORARIO_DIAS[$d] ?? (string) $d, 'dias' => $n, 'reservas' => $res[$d] ?? 0, 'promedio' => round(($res[$d] ?? 0) / $n, 1)];
    }
    ksort($filas);
    $mayor = null; $menor = null;
    foreach ($filas as $x) {
        if ($mayor === null || $x['promedio'] > $mayor['promedio']) $mayor = $x;
        if ($menor === null || $x['promedio'] < $menor['promedio']) $menor = $x;
    }
    $iguales = $mayor && $menor && $mayor['promedio'] === $menor['promedio'];
    return ['filas' => $filas, 'mayor' => $iguales ? null : $mayor, 'menor' => $iguales ? null : $menor];
}

/** Box más solicitado por cada especialidad (horas reservadas no canceladas del período). */
function anBoxPorEspecialidad(string $desde, string $hasta): array
{
    $out = [];
    try {
        $st = db()->prepare(
            "SELECT p.especialidad_slug AS esp, COALESCE(e.nombre, p.especialidad_slug) AS esp_nombre, r.box_slug,
                    SUM(COALESCE(r.duracion_min, 60)) / 60 AS h
               FROM reservas r JOIN profesionales p ON p.id = r.profesional_id
          LEFT JOIN especialidades e ON e.slug = p.especialidad_slug
              WHERE r.estado <> 'cancelada' AND r.fecha BETWEEN ? AND ?
              GROUP BY p.especialidad_slug, e.nombre, r.box_slug"
        );
        $st->execute([$desde, $hasta]);
        foreach ($st->fetchAll() as $r) {
            $k = (string) $r['esp'];
            $out[$k]['nombre'] = (string) $r['esp_nombre'];
            $out[$k]['boxes'][] = ['slug' => (string) $r['box_slug'], 'nombre' => nombreBox((string) $r['box_slug']), 'horas' => round((float) $r['h'], 1)];
        }
    } catch (Throwable $e) {
        return [];
    }
    foreach ($out as &$x) {
        usort($x['boxes'], static fn($a, $b) => $b['horas'] <=> $a['horas']);
        $x['horas'] = round(array_sum(array_column($x['boxes'], 'horas')), 1);
        $x['top'] = $x['boxes'][0];
        $x['top']['pct'] = $x['horas'] > 0 ? round($x['top']['horas'] / $x['horas'] * 100) : 0;
    }
    unset($x);
    uasort($out, static fn($a, $b) => $b['horas'] <=> $a['horas']);
    return $out;
}
