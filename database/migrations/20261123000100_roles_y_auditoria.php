<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * V2 · Rol Gerencia y registro de auditoría.
 *
 * - usuarios.rol suma «gerencia» (solo lectura: dashboard y reportes) a los tres roles
 *   del sistema original: admin, editor (recepción) y pacientes (área clínica).
 * - auditoria: quién creó, modificó o eliminó qué registro, cuándo, desde dónde y con
 *   el antes y el después en JSON.
 * - Disparadores AFTER INSERT/UPDATE/DELETE en las entidades principales. Leen el
 *   usuario desde las variables de sesión @app_usuario_id / @app_usuario_nombre que
 *   fijan el panel (lib/db.php, dte/conexion.php) y la API (Infrastructure/Database).
 *   Así ningún camino (panel clásico, API REST o motor DTE) queda sin auditar.
 */
final class RolesYAuditoria extends AbstractMigration
{
    /** Entidades auditadas => columnas que NO se copian al registro (secretos o textos pesados). */
    public const ENTIDADES = [
        'reservas'              => ['slot_prof'],
        'pacientes'             => [],
        'fichas'                => [],
        'evoluciones'           => [],
        'paciente_informes'     => [],
        'certificados_atencion' => ['sesiones'],
        'boletas'               => [],
        'documento_pagos'       => [],
        'notas_credito'         => [],
        'notas_debito'          => [],
        'clientes'              => [],
        'profesionales'         => ['perfil', 'descripcion', 'enfoque'],
        'boxes'                 => ['el_espacio', 'equipamiento', 'descripcion'],
        'productos'             => [],
        'empresas'              => [],
        'usuarios'              => ['password_hash'],
    ];

    public function up(): void
    {
        // Misma intercalación que las tablas: las vistas y disparadores guardan la de la conexión
        // (MariaDB 11.8 usa utf8mb4_uca1400_ai_ci por defecto y provocaría «Illegal mix of collations»).
        $this->execute('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->execute("ALTER TABLE usuarios MODIFY rol ENUM('admin','editor','pacientes','gerencia') NOT NULL DEFAULT 'editor'");

        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS auditoria (
              id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              tabla          VARCHAR(40)  NOT NULL,
              registro_id    VARCHAR(40)  NOT NULL,
              accion         ENUM('crear','modificar','eliminar') NOT NULL,
              usuario_id     INT UNSIGNED NULL COMMENT 'NULL = proceso del sistema (cron, carga de datos)',
              usuario_nombre VARCHAR(160) NULL,
              origen         VARCHAR(20)  NOT NULL DEFAULT 'sistema' COMMENT 'panel, api o sistema',
              ip             VARCHAR(45)  NULL,
              datos_antes    LONGTEXT NULL CHECK (datos_antes IS NULL OR JSON_VALID(datos_antes)),
              datos_despues  LONGTEXT NULL CHECK (datos_despues IS NULL OR JSON_VALID(datos_despues)),
              creado_en      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
              PRIMARY KEY (id),
              KEY idx_aud_registro (tabla, registro_id, id),
              KEY idx_aud_usuario (usuario_id, creado_en),
              KEY idx_aud_fecha (creado_en)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Registro de auditoría: quién creó o modificó qué y cuándo'
            SQL);

        foreach (self::ENTIDADES as $tabla => $excluir) {
            $this->crearDisparadores($tabla, $excluir);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::ENTIDADES) as $tabla) {
            foreach (['ins', 'upd', 'del'] as $ev) {
                $this->execute("DROP TRIGGER IF EXISTS trg_aud_{$tabla}_{$ev}");
            }
        }
        $this->execute('DROP TABLE IF EXISTS auditoria');
        $this->execute("UPDATE usuarios SET rol = 'editor' WHERE rol = 'gerencia'");
        $this->execute("ALTER TABLE usuarios MODIFY rol ENUM('admin','editor','pacientes') NOT NULL DEFAULT 'admin'");
    }

    /** Genera los tres disparadores de una tabla a partir de sus columnas reales. */
    private function crearDisparadores(string $tabla, array $excluir): void
    {
        $cols = $this->fetchAll(
            "SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tabla' ORDER BY ORDINAL_POSITION"
        );
        $nombres = array_values(array_filter(array_column($cols, 'c'), fn ($c) => !in_array($c, $excluir, true)));
        $json = fn (string $alias) => 'JSON_OBJECT(' . implode(', ', array_map(fn ($c) => "'$c', $alias.`$c`", $nombres)) . ')';
        $pk = in_array('id', $nombres, true) ? 'id' : $nombres[0];
        $quien = "@app_usuario_id, @app_usuario_nombre, COALESCE(@app_origen, 'sistema'), @app_ip";
        $activo = 'COALESCE(@app_auditoria, 1) = 1';

        foreach (['ins', 'upd', 'del'] as $ev) {
            $this->execute("DROP TRIGGER IF EXISTS trg_aud_{$tabla}_{$ev}");
        }
        $this->execute("CREATE TRIGGER trg_aud_{$tabla}_ins AFTER INSERT ON `$tabla` FOR EACH ROW
            BEGIN
              IF $activo THEN
                INSERT INTO auditoria (tabla, registro_id, accion, usuario_id, usuario_nombre, origen, ip, datos_antes, datos_despues)
                VALUES ('$tabla', NEW.`$pk`, 'crear', $quien, NULL, {$json('NEW')});
              END IF;
            END");
        $this->execute("CREATE TRIGGER trg_aud_{$tabla}_upd AFTER UPDATE ON `$tabla` FOR EACH ROW
            BEGIN
              DECLARE v_antes LONGTEXT;
              DECLARE v_despues LONGTEXT;
              IF $activo THEN
                SET v_antes = {$json('OLD')};
                SET v_despues = {$json('NEW')};
                IF NOT (v_antes <=> v_despues) THEN
                  INSERT INTO auditoria (tabla, registro_id, accion, usuario_id, usuario_nombre, origen, ip, datos_antes, datos_despues)
                  VALUES ('$tabla', NEW.`$pk`, 'modificar', $quien, v_antes, v_despues);
                END IF;
              END IF;
            END");
        $this->execute("CREATE TRIGGER trg_aud_{$tabla}_del AFTER DELETE ON `$tabla` FOR EACH ROW
            BEGIN
              IF $activo THEN
                INSERT INTO auditoria (tabla, registro_id, accion, usuario_id, usuario_nombre, origen, ip, datos_antes, datos_despues)
                VALUES ('$tabla', OLD.`$pk`, 'eliminar', $quien, {$json('OLD')}, NULL);
              END IF;
            END");
    }
}
