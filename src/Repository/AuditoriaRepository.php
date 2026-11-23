<?php
declare(strict_types=1);

namespace EspacioLuz\Repository;

/**
 * Lectura del registro de auditoría. La escritura la hacen los disparadores de la base
 * de datos (migración V2), así el panel clásico, la API y el motor DTE quedan cubiertos.
 * Aquí solo se registran los eventos que no son cambios de filas: exportaciones e IA.
 */
final class AuditoriaRepository extends Repository
{
    public function listar(array $f, int $page, int $perPage): array
    {
        $w = ['1=1'];
        $p = [];
        foreach (['tabla' => 'a.tabla = ?', 'accion' => 'a.accion = ?', 'usuario_id' => 'a.usuario_id = ?', 'registro_id' => 'a.registro_id = ?', 'origen' => 'a.origen = ?'] as $k => $sql) {
            if (!empty($f[$k])) {
                $w[] = $sql;
                $p[] = $f[$k];
            }
        }
        if (!empty($f['desde'])) {
            $w[] = 'a.creado_en >= ?';
            $p[] = $f['desde'] . ' 00:00:00';
        }
        if (!empty($f['hasta'])) {
            $w[] = 'a.creado_en <= ?';
            $p[] = $f['hasta'] . ' 23:59:59';
        }
        $r = $this->paginate('a.id, a.tabla, a.registro_id, a.accion, a.usuario_id, a.usuario_nombre, a.origen, a.ip, a.datos_antes, a.datos_despues, a.creado_en',
            'FROM auditoria a WHERE ' . implode(' AND ', $w), $p, 'a.id DESC', $page, $perPage);
        foreach ($r['data'] as &$fila) {
            $antes = $fila['datos_antes'] ? json_decode((string) $fila['datos_antes'], true) : null;
            $despues = $fila['datos_despues'] ? json_decode((string) $fila['datos_despues'], true) : null;
            // Solo los campos que cambiaron: es lo que interesa leer en una auditoría.
            if (is_array($antes) && is_array($despues)) {
                $cambios = [];
                foreach ($despues as $k => $v) {
                    if (($antes[$k] ?? null) != $v) {
                        $cambios[$k] = ['antes' => $antes[$k] ?? null, 'despues' => $v];
                    }
                }
                $fila['cambios'] = $cambios;
            } else {
                $fila['cambios'] = null;
            }
            $fila['datos_antes'] = $antes;
            $fila['datos_despues'] = $despues;
        }
        return $r;
    }

    public function registrarExportacion(int $usuarioId, string $reporte, string $formato, array $filtros, int $filas, string $ip): void
    {
        $this->insert('exportaciones', ['usuario_id' => $usuarioId, 'reporte' => $reporte, 'formato' => $formato,
            'filtros' => json_encode($filtros, JSON_UNESCAPED_UNICODE), 'filas' => $filas, 'ip' => substr($ip, 0, 45)]);
    }

    public function exportaciones(int $limite = 50): array
    {
        return $this->all('SELECT e.id, e.reporte, e.formato, e.filtros, e.filas, e.creado_en, u.nombre AS usuario
                             FROM exportaciones e JOIN usuarios u ON u.id = e.usuario_id ORDER BY e.id DESC LIMIT ' . max(1, min(200, $limite)));
    }

    public function registrarIa(int $usuarioId, string $tipo, bool $exito, ?int $latencia, ?string $error): void
    {
        $this->insert('ia_consultas', ['usuario_id' => $usuarioId, 'tipo' => $tipo, 'exito' => $exito ? 1 : 0, 'latencia_ms' => $latencia,
            'error' => $error !== null ? mb_substr($error, 0, 200) : null]);
    }
}
