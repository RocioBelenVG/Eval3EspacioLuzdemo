<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * V1 · Esquema base: las 40 tablas y los disparadores del sistema EspacioLuz original
 * (agenda, boxes, profesionales, pacientes y fichas clínicas, clientes, documentos,
 * cobros, motor DTE) y sus catálogos públicos (regiones, comunas, previsiones,
 * feriados, especialidades, boxes y horario del centro).
 *
 * Los archivos SQL separan cada sentencia con una línea «-- ;;» para poder ejecutar
 * disparadores (que contienen «;» dentro de BEGIN … END) uno por uno.
 */
final class EsquemaBase extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('reservas')) {
            $this->output->writeln('  El esquema base ya existe: solo se registra la migración.');
            return;
        }
        $dir = dirname(__DIR__) . '/sql';
        $this->execute('SET FOREIGN_KEY_CHECKS = 0');
        foreach (preg_split('/^-- ;;\s*$/m', (string) file_get_contents($dir . '/esquema_base.sql')) as $sql) {
            $sql = trim(preg_replace('/^--.*$/m', '', $sql) ?? '');
            if ($sql !== '') {
                $this->execute($sql);
            }
        }
        foreach (file($dir . '/catalogos.sql', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            if (str_starts_with($linea, 'INSERT INTO')) {
                $this->execute($linea);
            }
        }
        $this->execute('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function down(): void
    {
        // Borrar el esquema completo solo se hace a mano y con un respaldo verificado.
        throw new \RuntimeException('La migración del esquema base no es reversible.');
    }
}
