<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * V4 · Flujo de estados de una atención (tabla reservas).
 *
 *   pendiente ──► confirmada ──► realizada   (cierre)
 *       │              │    └──► no_show     (cierre)
 *       │              └──────► cancelada ──► pendiente | confirmada (reactivación)
 *       └──► realizada | no_show | cancelada
 *
 * realizada y no_show son estados de cierre: no admiten más cambios. Además, una
 * atención solo puede quedar «realizada» o «no_show» si su fecha ya llegó.
 * La misma regla vive en src/Domain/EstadoReserva.php (API) y en el panel clásico.
 */
final class FlujoEstadosReserva extends AbstractMigration
{
    public function up(): void
    {
        // Misma intercalación que las tablas: las vistas y disparadores guardan la de la conexión
        // (MariaDB 11.8 usa utf8mb4_uca1400_ai_ci por defecto y provocaría «Illegal mix of collations»).
        $this->execute('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->execute('DROP TRIGGER IF EXISTS trg_reservas_transicion');
        $this->execute(<<<'SQL'
            CREATE TRIGGER trg_reservas_transicion BEFORE UPDATE ON reservas FOR EACH ROW
            BEGIN
              IF NOT (OLD.estado <=> NEW.estado) THEN
                IF NOT (
                     (OLD.estado = 'pendiente'  AND NEW.estado IN ('confirmada', 'realizada', 'cancelada', 'no_show'))
                  OR (OLD.estado = 'confirmada' AND NEW.estado IN ('realizada', 'cancelada', 'no_show'))
                  OR (OLD.estado = 'cancelada'  AND NEW.estado IN ('pendiente', 'confirmada'))
                ) THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRANSICION_INVALIDA';
                END IF;
              END IF;
              IF NEW.estado IN ('realizada', 'no_show') AND NEW.fecha > CURDATE() THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ESTADO_FECHA_FUTURA';
              END IF;
            END
            SQL);

        $this->execute('DROP TRIGGER IF EXISTS trg_reservas_estado_ins');
        $this->execute(<<<'SQL'
            CREATE TRIGGER trg_reservas_estado_ins BEFORE INSERT ON reservas FOR EACH ROW
            BEGIN
              IF NEW.estado IN ('realizada', 'no_show') AND NEW.fecha > CURDATE() THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ESTADO_FECHA_FUTURA';
              END IF;
            END
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TRIGGER IF EXISTS trg_reservas_transicion');
        $this->execute('DROP TRIGGER IF EXISTS trg_reservas_estado_ins');
    }
}
