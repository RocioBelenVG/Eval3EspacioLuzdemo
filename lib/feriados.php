<?php
/**
 * Días de atención del centro: lunes a sábado. Los domingos el centro está
 * cerrado siempre. Los feriados legales viven en la tabla `feriados` y la
 * administración decide en el calendario si cada uno se trabaja o no
 * (se_trabaja = 1 / 0). Un feriado no trabajado bloquea la toma de horas igual
 * que un domingo. La base de datos lo hace cumplir además con los triggers
 * trg_reservas_cupo_* (DIA_CERRADO_DOMINGO / DIA_CERRADO_FERIADO).
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';
require_once APP_ROOT . '/lib/horario.php';   // días y horarios del centro (panel «Días y horarios»)

/** ¿La fecha (Y-m-d) cae en domingo? */
function esDomingo(string $fecha): bool
{
    $t = strtotime($fecha . ' 12:00:00');
    return $t !== false && date('N', $t) === '7';
}

/** Fecha legible: "martes 8 de diciembre de 2026". */
function fechaLarga(string $fecha): string
{
    static $dias = [1 => 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
    static $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $t = strtotime($fecha . ' 12:00:00');
    if ($t === false) {
        return $fecha;
    }
    return $dias[(int) date('N', $t)] . ' ' . date('j', $t) . ' de ' . $meses[(int) date('n', $t)] . ' de ' . date('Y', $t);
}

/** Feriados de un rango (inclusive): [Y-m-d => ['fecha','nombre','irrenunciable','se_trabaja','origen']]. */
function feriadosEntre(string $ini, string $fin): array
{
    static $cache = [];
    $k = $ini . '|' . $fin;
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    $out = [];
    try {
        $st = db()->prepare("SELECT fecha, nombre, irrenunciable, se_trabaja, origen FROM feriados WHERE fecha BETWEEN ? AND ? ORDER BY fecha");
        $st->execute([$ini, $fin]);
        foreach ($st->fetchAll() as $r) {
            $out[$r['fecha']] = [
                'fecha' => $r['fecha'],
                'nombre' => (string) $r['nombre'],
                'irrenunciable' => (int) $r['irrenunciable'] === 1,
                'se_trabaja' => (int) $r['se_trabaja'] === 1,
                'origen' => (string) $r['origen'],
            ];
        }
    } catch (Throwable $e) {
        // sin tabla: sin feriados
    }
    return $cache[$k] = $out;
}

/** Feriado de una fecha o null. */
function feriadoDe(string $fecha): ?array
{
    return feriadosEntre($fecha, $fecha)[$fecha] ?? null;
}

/**
 * Si el centro NO atiende esa fecha, devuelve el motivo (texto para mostrar);
 * si atiende, null. Cerrado = domingo, o feriado marcado como no trabajado.
 */
function diaCerradoMotivo(string $fecha): ?string
{
    if (esDomingo($fecha)) {
        return 'Los domingos el centro no atiende: no se pueden tomar horas.';
    }
    $dow = horarioDow($fecha);
    if ($dow >= 1 && !horarioDiaAbierto($dow)) {
        return 'Los ' . HORARIO_DIAS_PLURAL[$dow] . ' el centro no atiende (según «Días y horarios»): no se pueden tomar horas.';
    }
    $f = feriadoDe($fecha);
    if ($f && !$f['se_trabaja']) {
        return 'El ' . fechaLarga($fecha) . ' es feriado (' . $f['nombre'] . ') y está marcado como no trabajado: no se pueden tomar horas.';
    }
    return null;
}

/** Aviso para un feriado que SÍ se trabaja (o null si no es feriado o está cerrado). */
function feriadoTrabajadoAviso(string $fecha): ?string
{
    $f = feriadoDe($fecha);
    if ($f && $f['se_trabaja'] && !esDomingo($fecha)) {
        return 'Ojo: el ' . fechaLarga($fecha) . ' es feriado (' . $f['nombre'] . '). Está marcado como día trabajado.';
    }
    return null;
}

/**
 * Días hábiles del rango: lunes a sábado que no son feriados marcados como
 * no trabajados. Devuelve la lista de fechas Y-m-d.
 */
function diasHabilesEntre(string $ini, string $fin): array
{
    $out = [];
    try {
        $cur = new DateTimeImmutable($ini);
        $end = new DateTimeImmutable($fin);
    } catch (Throwable $e) {
        return $out;
    }
    $fer = feriadosEntre($ini, $fin);
    while ($cur <= $end) {
        $f = $cur->format('Y-m-d');
        if ($cur->format('N') !== '7' && horarioDiaAbierto((int) $cur->format('N')) && !(isset($fer[$f]) && !$fer[$f]['se_trabaja'])) {
            $out[] = $f;
        }
        $cur = $cur->modify('+1 day');
    }
    return $out;
}

/** Reservas vigentes (no canceladas) de una fecha. */
function reservasVigentesEnFecha(string $fecha): int
{
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM reservas WHERE fecha = ? AND estado <> 'cancelada'");
        $st->execute([$fecha]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** ¿La excepción viene del trigger de día cerrado? Devuelve el mensaje legible o null. */
function errorDiaCerrado(Throwable $e, string $fecha = ''): ?string
{
    if (!($e instanceof PDOException) || (int) ($e->errorInfo[1] ?? 0) !== 1644) {
        return null;
    }
    $m = (string) $e->getMessage();
    if (str_contains($m, 'DIA_CERRADO_DOMINGO')) {
        return 'Los domingos el centro no atiende: no se pueden tomar horas.';
    }
    if (str_contains($m, 'DIA_CERRADO_FERIADO')) {
        return ($fecha !== '' ? diaCerradoMotivo($fecha) : null) ?? 'Ese día es feriado no trabajado: no se pueden tomar horas.';
    }
    return null;
}
