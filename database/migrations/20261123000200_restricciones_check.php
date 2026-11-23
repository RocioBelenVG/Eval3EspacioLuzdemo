<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * V3 · Tercer nivel de validación: restricciones CHECK en la base de datos.
 *
 * Las mismas reglas se validan en el formulario (front end) y en la API
 * (Validation/Validator + servicios); aquí quedan como última barrera para que
 * ningún dato pueda saltarse un nivel (por ejemplo, un INSERT manual o un script).
 * Cada regla se comprobó contra los datos del sistema original antes de aplicarla.
 */
final class RestriccionesCheck extends AbstractMigration
{
    /** tabla => [nombre => expresión] */
    private const CHECKS = [
        'reservas' => [
            'chk_res_hora'      => "hora IS NULL OR hora BETWEEN '07:00:00' AND '22:00:00'",
            'chk_res_duracion'  => 'duracion_min IS NULL OR duracion_min BETWEEN 15 AND 480',
            'chk_res_precio'    => 'precio_clp IS NULL OR precio_clp <= 5000000',
            'chk_res_email'     => "email = '' OR email LIKE '%_@_%._%'",
            'chk_res_nombre'    => "CHAR_LENGTH(TRIM(nombre)) >= 2",
        ],
        'pacientes' => [
            'chk_pac_nombre'    => 'CHAR_LENGTH(TRIM(nombre)) >= 3',
            'chk_pac_email'     => "email IS NULL OR email = '' OR email LIKE '%_@_%._%'",
            'chk_pac_fnac'      => "fecha_nacimiento IS NULL OR fecha_nacimiento >= '1900-01-01'",
            'chk_pac_consent'   => 'consentimiento IN (0, 1) AND (consentimiento = 0 OR consentimiento_fecha IS NOT NULL)',
        ],
        'boletas' => [
            'chk_bol_total'     => 'total_clp = neto_clp + iva_clp + exento_clp',
            'chk_bol_folio'     => 'folio > 0',
            'chk_bol_tipo'      => "tipo IN ('boleta', 'factura')",
            'chk_bol_anulada'   => "estado <> 'anulada' OR anulada_en IS NOT NULL",
        ],
        'notas_credito' => [
            'chk_nc_total'      => 'total_clp = neto_clp + iva_clp + exento_clp',
            'chk_nc_motivo'     => 'CHAR_LENGTH(TRIM(motivo)) >= 3',
        ],
        'notas_debito' => [
            'chk_nd_total'      => 'total_clp = neto_clp + iva_clp + exento_clp',
            'chk_nd_motivo'     => 'CHAR_LENGTH(TRIM(motivo)) >= 3',
        ],
        'dte_documentos' => [
            'chk_dte_total'     => 'total = neto + iva + exento',
            'chk_dte_tipo'      => 'tipo_dte IN (33, 34, 39, 41, 52, 56, 61)',
        ],
        'documento_pagos' => [
            'chk_pago_monto'    => 'monto <> 0',
        ],
        'disponibilidad' => [
            'chk_disp_dia'      => 'dia_semana BETWEEN 1 AND 7',
            'chk_disp_horas'    => 'hora_fin > hora_inicio',
        ],
        'horario_centro' => [
            'chk_hor_dia'       => 'dia_semana BETWEEN 1 AND 6',
            'chk_hor_horas'     => 'hora_fin > hora_inicio',
        ],
        'fichas' => [
            'chk_ficha_cierre'  => 'cerrada_en IS NULL OR cerrada_en >= abierta_en',
        ],
        'caf_empresa' => [
            'chk_caf_rango'     => 'folio_hasta >= folio_desde AND folio_actual BETWEEN folio_desde AND folio_hasta + 1',
        ],
        'clientes' => [
            'chk_cli_rut'       => "CHAR_LENGTH(rut) >= 3",
            'chk_cli_email'     => "email IS NULL OR email = '' OR email LIKE '%_@_%._%'",
        ],
        'usuarios' => [
            'chk_usr_email'     => "email LIKE '%_@_%._%'",
            'chk_usr_nombre'    => 'CHAR_LENGTH(TRIM(nombre)) >= 2',
        ],
        'productos' => [
            'chk_prod_precio'   => 'precio_neto <= 10000000',
        ],
    ];

    public function up(): void
    {
        foreach (self::CHECKS as $tabla => $reglas) {
            foreach ($reglas as $nombre => $expr) {
                $this->execute("ALTER TABLE `$tabla` ADD CONSTRAINT `$nombre` CHECK ($expr)");
            }
        }
    }

    public function down(): void
    {
        foreach (self::CHECKS as $tabla => $reglas) {
            foreach (array_keys($reglas) as $nombre) {
                $this->execute("ALTER TABLE `$tabla` DROP CONSTRAINT IF EXISTS `$nombre`");
            }
        }
    }
}
