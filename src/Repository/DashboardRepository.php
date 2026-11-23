<?php
declare(strict_types=1);

namespace EspacioLuz\Repository;

/**
 * Consultas de agregación del dashboard, los indicadores y el reporte gerencial.
 * Todas leen las vistas v_atenciones / v_documentos / v_ingresos_diarios, así una
 * cifra se calcula de una sola forma en la API, la interfaz y los reportes.
 *
 * Filtros comunes: desde, hasta (obligatorios), especialidad, box, profesional_id.
 */
final class DashboardRepository extends Repository
{
    /** Conteos por estado y uso en el rango. */
    public function estados(array $f): array
    {
        [$w, $p] = AtencionRepository::filtro($f);
        return $this->one("SELECT COUNT(*) AS agendadas,
                SUM(a.estado = 'realizada') AS realizadas, SUM(a.estado = 'cancelada') AS canceladas,
                SUM(a.estado = 'no_show') AS no_show, SUM(a.estado = 'pendiente') AS pendientes, SUM(a.estado = 'confirmada') AS confirmadas,
                SUM(a.uso = 'sesion' AND a.estado = 'realizada') AS sesiones_realizadas,
                SUM(a.uso = 'sesion' AND a.estado = 'no_show') AS sesiones_no_show,
                SUM(a.uso = 'sesion' AND a.estado = 'realizada' AND a.boleta_id IS NOT NULL) AS sesiones_cobradas,
                SUM(a.uso = 'arriendo' AND a.estado <> 'cancelada') AS horas_arriendo,
                COUNT(DISTINCT CASE WHEN a.estado = 'realizada' THEN a.paciente_id END) AS pacientes_atendidos,
                ROUND(AVG(CASE WHEN a.estado <> 'cancelada' THEN a.dias_anticipacion END), 1) AS anticipacion_promedio
              FROM v_atenciones a WHERE $w", $p) ?? [];
    }

    /** Slots box-hora distintos con al menos una reserva vigente (para la ocupación). */
    public function slotsOcupados(array $f): int
    {
        [$w, $p] = AtencionRepository::filtro($f);
        return (int) $this->scalar("SELECT COUNT(DISTINCT a.box_slug, a.fecha, a.hora) FROM v_atenciones a WHERE $w AND a.estado <> 'cancelada'", $p);
    }

    /** Ingresos netos (documentos − NC + ND) del rango; con filtro de profesional o especialidad, solo boletas de sesiones. */
    public function ingresos(array $f): int
    {
        if (!empty($f['profesional_id']) || !empty($f['especialidad']) || !empty($f['box'])) {
            [$w, $p] = AtencionRepository::filtro($f);
            return (int) $this->scalar("SELECT COALESCE(SUM(d.total_clp - d.nc_clp + d.nd_clp), 0) FROM boletas d
                                         WHERE d.estado = 'emitida' AND d.id IN (SELECT a.boleta_id FROM v_atenciones a WHERE $w AND a.boleta_id IS NOT NULL)", $p);
        }
        return (int) $this->scalar('SELECT COALESCE(SUM(ingresos), 0) FROM v_ingresos_diarios WHERE fecha BETWEEN ? AND ?', [$f['desde'], $f['hasta']]);
    }

    /** Ingresos de boletas a pacientes (para el ticket promedio por sesión). */
    public function ingresosSesiones(array $f): int
    {
        [$w, $p] = AtencionRepository::filtro($f);
        return (int) $this->scalar("SELECT COALESCE(SUM(d.total_clp - d.nc_clp), 0) FROM boletas d
                                     WHERE d.estado = 'emitida' AND d.id IN (SELECT a.boleta_id FROM v_atenciones a WHERE $w AND a.boleta_id IS NOT NULL)", $p);
    }

    /** Cuentas por cobrar a la fecha de corte y días promedio de pago de facturas emitidas en el rango. */
    public function cobranza(string $desde, string $hasta): array
    {
        return $this->one("SELECT COALESCE(SUM(CASE WHEN estado = 'emitida' THEN saldo_clp END), 0) AS por_cobrar,
                COALESCE(SUM(CASE WHEN estado = 'emitida' AND saldo_clp > 0 AND vence_en < CURDATE() THEN saldo_clp END), 0) AS vencido,
                ROUND(AVG(CASE WHEN tipo = 'factura' AND pago_estado = 'pagado' AND fecha BETWEEN ? AND ? THEN dias_pago END), 1) AS dias_pago_facturas,
                SUM(fecha BETWEEN ? AND ? AND estado = 'emitida') AS documentos
              FROM v_documentos WHERE fecha <= ?", [$desde, $hasta, $desde, $hasta, $hasta]) ?? [];
    }

    /** Pacientes cuya primera atención realizada cae en el rango (pacientes nuevos). */
    public function pacientesNuevos(array $f): int
    {
        [$w, $p] = AtencionRepository::filtro(array_diff_key($f, ['desde' => 1, 'hasta' => 1]));
        return (int) $this->scalar("SELECT COUNT(*) FROM (SELECT a.paciente_id, MIN(a.fecha) AS primera FROM v_atenciones a
                                     WHERE $w AND a.paciente_id IS NOT NULL AND a.estado = 'realizada' GROUP BY a.paciente_id) x
                                     WHERE x.primera BETWEEN ? AND ?", [...$p, $f['desde'], $f['hasta']]);
    }

    /** Pacientes atendidos en el período anterior que volvieron a atenderse en el actual (retención). */
    public function retencion(array $f, string $desdeAnt, string $hastaAnt): array
    {
        $base = array_diff_key($f, ['desde' => 1, 'hasta' => 1]);
        [$w, $p] = AtencionRepository::filtro($base);
        $sql = "SELECT COUNT(DISTINCT a.paciente_id) FROM v_atenciones a WHERE $w AND a.estado = 'realizada' AND a.paciente_id IS NOT NULL AND a.fecha BETWEEN ? AND ?";
        $anteriores = (int) $this->scalar($sql, [...$p, $desdeAnt, $hastaAnt]);
        $vuelven = (int) $this->scalar("SELECT COUNT(DISTINCT a.paciente_id) FROM v_atenciones a WHERE $w AND a.estado = 'realizada' AND a.fecha BETWEEN ? AND ?
                                         AND a.paciente_id IN (SELECT b.paciente_id FROM v_atenciones b WHERE b.estado = 'realizada' AND b.paciente_id IS NOT NULL AND b.fecha BETWEEN ? AND ?)",
            [...$p, $f['desde'], $f['hasta'], $desdeAnt, $hastaAnt]);
        return ['anteriores' => $anteriores, 'vuelven' => $vuelven];
    }

    /** Serie mensual: atenciones por estado, horas de box e ingresos. */
    public function mensual(array $f): array
    {
        [$w, $p] = AtencionRepository::filtro($f);
        $filas = $this->all("SELECT DATE_FORMAT(a.fecha, '%Y-%m') AS mes, COUNT(*) AS agendadas, SUM(a.estado = 'realizada') AS realizadas,
                SUM(a.estado = 'cancelada') AS canceladas, SUM(a.estado = 'no_show') AS no_show,
                SUM(a.uso = 'sesion' AND a.estado <> 'cancelada') AS sesiones, SUM(a.uso = 'arriendo' AND a.estado <> 'cancelada') AS arriendos,
                ROUND(SUM(CASE WHEN a.estado <> 'cancelada' THEN a.duracion_min ELSE 0 END) / 60, 1) AS horas_box,
                COUNT(DISTINCT CASE WHEN a.estado = 'realizada' THEN a.paciente_id END) AS pacientes
              FROM v_atenciones a WHERE $w GROUP BY DATE_FORMAT(a.fecha, '%Y-%m') ORDER BY mes", $p);
        $ing = [];
        foreach ($this->all("SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes, SUM(ingresos) AS ingresos, SUM(documentos) AS documentos
                               FROM v_ingresos_diarios WHERE fecha BETWEEN ? AND ? GROUP BY DATE_FORMAT(fecha, '%Y-%m')", [$f['desde'], $f['hasta']]) as $r) {
            $ing[$r['mes']] = $r;
        }
        foreach ($filas as &$r) {
            $r['ingresos'] = (int) ($ing[$r['mes']]['ingresos'] ?? 0);
            $r['documentos'] = (int) ($ing[$r['mes']]['documentos'] ?? 0);
            $asist = (int) $r['realizadas'] + (int) $r['no_show'];
            $r['tasa_asistencia'] = $asist > 0 ? round(100 * (int) $r['realizadas'] / $asist, 1) : null;
        }
        return $filas;
    }

    public function porEspecialidad(array $f): array
    {
        [$w, $p] = AtencionRepository::filtro($f);
        return $this->all("SELECT COALESCE(a.especialidad, 'Sin especialidad') AS especialidad, a.especialidad_slug AS slug,
                SUM(a.estado = 'realizada') AS realizadas, SUM(a.estado <> 'cancelada') AS agendadas,
                ROUND(SUM(CASE WHEN a.estado <> 'cancelada' THEN a.duracion_min ELSE 0 END) / 60, 1) AS horas
              FROM v_atenciones a WHERE $w GROUP BY a.especialidad, a.especialidad_slug ORDER BY horas DESC", $p);
    }

    /** Horas reservadas por box (distintas horas ocupadas y reservas totales). */
    public function porBox(array $f): array
    {
        [$w, $p] = AtencionRepository::filtro($f);
        return $this->all("SELECT a.box_slug AS slug, a.box, COUNT(DISTINCT a.fecha, a.hora) AS horas_ocupadas, COUNT(*) AS reservas,
                SUM(a.uso = 'sesion') AS sesiones, SUM(a.uso = 'arriendo') AS arriendos
              FROM v_atenciones a WHERE $w AND a.estado <> 'cancelada' GROUP BY a.box_slug, a.box ORDER BY horas_ocupadas DESC", $p);
    }

    /** Matriz día de la semana (1 = lunes) × hora con reservas vigentes. */
    public function diaHora(array $f): array
    {
        [$w, $p] = AtencionRepository::filtro($f);
        return $this->all("SELECT WEEKDAY(a.fecha) + 1 AS dia, HOUR(a.hora) AS hora, COUNT(*) AS reservas
              FROM v_atenciones a WHERE $w AND a.estado <> 'cancelada' AND a.hora IS NOT NULL GROUP BY dia, hora ORDER BY dia, hora", $p);
    }

    public function porProfesional(array $f): array
    {
        [$w, $p] = AtencionRepository::filtro($f);
        return $this->all("SELECT a.profesional_id AS id, a.profesional, a.especialidad, SUM(a.estado = 'realizada') AS realizadas,
                SUM(a.estado = 'no_show') AS no_show, SUM(a.estado = 'cancelada') AS canceladas,
                ROUND(SUM(CASE WHEN a.estado <> 'cancelada' THEN a.duracion_min ELSE 0 END) / 60, 1) AS horas
              FROM v_atenciones a WHERE $w GROUP BY a.profesional_id, a.profesional, a.especialidad ORDER BY horas DESC", $p);
    }

    public function totalBoxesActivos(?string $box): int
    {
        return $box ? 1 : (int) $this->scalar('SELECT COUNT(*) FROM boxes WHERE activo = 1 AND eliminado_at IS NULL');
    }

    /** Horas de apertura por día de la semana [1..6 => horas]. */
    public function horasApertura(): array
    {
        $out = [];
        foreach ($this->all('SELECT dia_semana, abierto, TIME_TO_SEC(TIMEDIFF(hora_fin, hora_inicio)) / 3600 AS horas FROM horario_centro') as $r) {
            $out[(int) $r['dia_semana']] = (int) $r['abierto'] ? (float) $r['horas'] : 0.0;
        }
        return $out;
    }
}
