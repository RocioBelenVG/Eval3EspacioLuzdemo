<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * V6 · Tablas de soporte de la API REST y de los reportes.
 *
 * - rate_limits: contador por ventana fija (login y uso de la API; OWASP API4).
 * - exportaciones: quién exportó qué reporte, en qué formato, con qué filtros y cuántas filas.
 * - ia_consultas: bitácora de uso del servicio de IA (sin datos personales: solo
 *   el tipo de consulta, el resultado y la latencia).
 * - Índices justificados por las consultas de reportes y dashboard.
 */
final class SoporteApi extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS rate_limits (
              clave   VARCHAR(190) NOT NULL,
              ventana BIGINT UNSIGNED NOT NULL,
              conteo  INT UNSIGNED NOT NULL DEFAULT 0,
              PRIMARY KEY (clave)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Límite de tasa por ventana fija (API y login)'
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS exportaciones (
              id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              usuario_id  INT UNSIGNED NOT NULL,
              reporte     VARCHAR(40) NOT NULL,
              formato     ENUM('csv','xlsx','pdf','json') NOT NULL,
              filtros     TEXT NULL CHECK (filtros IS NULL OR JSON_VALID(filtros)),
              filas       INT UNSIGNED NOT NULL DEFAULT 0,
              ip          VARCHAR(45) NULL,
              creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_exp_usuario (usuario_id, creado_en),
              CONSTRAINT fk_exp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Registro de exportaciones de reportes (quién las solicitó)'
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS ia_consultas (
              id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              usuario_id  INT UNSIGNED NOT NULL,
              tipo        VARCHAR(40) NOT NULL,
              exito       TINYINT(1) NOT NULL DEFAULT 0,
              latencia_ms INT UNSIGNED NULL,
              error       VARCHAR(200) NULL,
              creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_ia_usuario (usuario_id, creado_en),
              CONSTRAINT fk_ia_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Uso del servicio de IA externo'
            SQL);

        // Reporte operativo y dashboard: filtran por fecha + estado y agrupan por profesional / box.
        $this->execute('CREATE INDEX idx_res_fecha_prof_estado ON reservas (fecha, profesional_id, estado)');
        // Reporte de documentos: rango de fechas por tipo y estado de pago.
        $this->execute('CREATE INDEX idx_bol_fecha_tipo_pago ON boletas (fecha_emision, tipo, pago_estado)');
        // Listado de pacientes con búsqueda por RUT o nombre ordenado por fecha de creación.
        $this->execute('CREATE INDEX idx_pac_creado ON pacientes (creado_en)');
    }

    public function down(): void
    {
        $this->execute('DROP INDEX idx_pac_creado ON pacientes');
        $this->execute('DROP INDEX idx_bol_fecha_tipo_pago ON boletas');
        $this->execute('DROP INDEX idx_res_fecha_prof_estado ON reservas');
        $this->execute('DROP TABLE IF EXISTS ia_consultas');
        $this->execute('DROP TABLE IF EXISTS exportaciones');
        $this->execute('DROP TABLE IF EXISTS rate_limits');
    }
}
