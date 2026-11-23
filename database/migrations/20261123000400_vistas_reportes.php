<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * V5 · Vistas para los reportes y el dashboard (una sola definición de cada cifra).
 *
 * - v_atenciones: cada atención (reserva) con profesional, especialidad, box, paciente
 *   y documento de cobro → reporte operativo y filtros del dashboard.
 * - v_documentos: boletas y facturas con sus notas, saldo y estado de pago → reporte
 *   de documentos y cobranza (rango de fechas).
 * - v_ingresos_diarios: ingreso neto por día (documentos emitidos − NC + ND), igual
 *   que ingresosEntre() del panel.
 * - v_kpi_mensual: atenciones por estado, pacientes distintos e ingresos por mes →
 *   reporte gerencial e indicadores.
 */
final class VistasReportes extends AbstractMigration
{
    public function up(): void
    {
        // Misma intercalación que las tablas: las vistas y disparadores guardan la de la conexión
        // (MariaDB 11.8 usa utf8mb4_uca1400_ai_ci por defecto y provocaría «Illegal mix of collations»).
        $this->execute('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->execute(<<<'SQL'
            CREATE OR REPLACE VIEW v_atenciones AS
            SELECT r.id, r.publico_id, r.fecha, r.hora, COALESCE(r.duracion_min, 60) AS duracion_min,
                   r.estado, r.origen, r.tipo_atencion, r.modalidad_label,
                   COALESCE(r.precio_clp, 0) AS precio_clp,
                   r.box_slug, b.nombre AS box,
                   r.profesional_id, p.nombre AS profesional, p.socia,
                   p.especialidad_slug, e.nombre AS especialidad,
                   r.paciente_id, pa.nombre AS paciente,
                   CASE WHEN r.paciente_id IS NOT NULL THEN 'sesion' ELSE 'arriendo' END AS uso,
                   r.boleta_id, r.factura_id,
                   (r.boleta_id IS NOT NULL OR r.factura_id IS NOT NULL) AS cobrada,
                   r.creado_en,
                   DATEDIFF(r.fecha, DATE(r.creado_en)) AS dias_anticipacion
            FROM reservas r
            LEFT JOIN boxes b          ON b.slug = r.box_slug
            LEFT JOIN profesionales p  ON p.id = r.profesional_id
            LEFT JOIN especialidades e ON e.slug = p.especialidad_slug
            LEFT JOIN pacientes pa     ON pa.id = r.paciente_id
            SQL);

        $this->execute(<<<'SQL'
            CREATE OR REPLACE VIEW v_documentos AS
            SELECT d.id, d.tipo, d.serie, d.folio, d.fecha_emision, DATE(d.fecha_emision) AS fecha,
                   d.empresa_id, em.razon_social AS empresa,
                   d.receptor_nombre, d.receptor_rut, d.receptor_tipo,
                   d.paciente_id, d.cliente_id, d.profesional_nombre,
                   d.medio_pago, d.neto_clp, d.iva_clp, d.exento_clp, d.total_clp,
                   d.nc_clp, d.nd_clp,
                   (d.total_clp - d.nc_clp + d.nd_clp) AS total_ajustado,
                   d.pagado_clp,
                   GREATEST(d.total_clp - d.nc_clp + d.nd_clp - d.pagado_clp, 0) AS saldo_clp,
                   d.pago_estado, d.vence_en, d.pagado_en,
                   CASE WHEN d.pagado_en IS NOT NULL THEN DATEDIFF(d.pagado_en, d.fecha_emision) END AS dias_pago,
                   d.estado, d.sii_estado, d.sii_track_id
            FROM boletas d
            LEFT JOIN empresas em ON em.id = d.empresa_id
            SQL);

        $this->execute(<<<'SQL'
            CREATE OR REPLACE VIEW v_ingresos_diarios AS
            SELECT x.fecha, SUM(x.monto) AS ingresos, SUM(x.docs) AS documentos
            FROM (
                SELECT DATE(fecha_emision) AS fecha, total_clp AS monto, 1 AS docs
                  FROM boletas WHERE estado = 'emitida'
                UNION ALL
                SELECT DATE(n.fecha_emision), -n.total_clp, 0
                  FROM notas_credito n JOIN boletas b ON b.id = n.documento_id
                 WHERE n.estado = 'emitida' AND b.estado = 'emitida'
                UNION ALL
                SELECT DATE(n.fecha_emision), n.total_clp, 0
                  FROM notas_debito n JOIN boletas b ON b.id = n.documento_id
                 WHERE n.estado = 'emitida' AND b.estado = 'emitida'
            ) x
            GROUP BY x.fecha
            SQL);

        $this->execute(<<<'SQL'
            CREATE OR REPLACE VIEW v_kpi_mensual AS
            SELECT a.mes, a.agendadas, a.realizadas, a.canceladas, a.no_show, a.pendientes, a.confirmadas,
                   a.sesiones, a.arriendos, a.horas_box, a.pacientes_distintos,
                   COALESCE(i.ingresos, 0) AS ingresos, COALESCE(i.documentos, 0) AS documentos
            FROM (
                SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes,
                       COUNT(*) AS agendadas,
                       SUM(estado = 'realizada') AS realizadas,
                       SUM(estado = 'cancelada') AS canceladas,
                       SUM(estado = 'no_show') AS no_show,
                       SUM(estado = 'pendiente') AS pendientes,
                       SUM(estado = 'confirmada') AS confirmadas,
                       SUM(estado <> 'cancelada' AND paciente_id IS NOT NULL) AS sesiones,
                       SUM(estado <> 'cancelada' AND paciente_id IS NULL) AS arriendos,
                       ROUND(SUM(CASE WHEN estado <> 'cancelada' THEN COALESCE(duracion_min, 60) ELSE 0 END) / 60, 1) AS horas_box,
                       COUNT(DISTINCT CASE WHEN estado = 'realizada' THEN paciente_id END) AS pacientes_distintos
                FROM reservas
                GROUP BY DATE_FORMAT(fecha, '%Y-%m')
            ) a
            LEFT JOIN (
                SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes, SUM(ingresos) AS ingresos, SUM(documentos) AS documentos
                FROM v_ingresos_diarios GROUP BY DATE_FORMAT(fecha, '%Y-%m')
            ) i ON i.mes = a.mes
            SQL);
    }

    public function down(): void
    {
        foreach (['v_kpi_mensual', 'v_ingresos_diarios', 'v_documentos', 'v_atenciones'] as $v) {
            $this->execute("DROP VIEW IF EXISTS $v");
        }
    }
}
