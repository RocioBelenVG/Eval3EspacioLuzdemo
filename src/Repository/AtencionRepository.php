<?php
declare(strict_types=1);

namespace EspacioLuz\Repository;

/**
 * Atenciones (tabla reservas): una hora de box con su profesional y, si es una sesión,
 * su paciente. Las lecturas usan la vista v_atenciones (relaciones ya resueltas).
 */
final class AtencionRepository extends Repository
{
    /** Orden permitido: clave pública => ORDER BY (lista blanca). */
    public const ORDENES = [
        'fecha' => 'a.fecha ASC, a.hora ASC, a.id ASC',
        '-fecha' => 'a.fecha DESC, a.hora DESC, a.id DESC',
        'profesional' => 'a.profesional ASC, a.fecha DESC',
        'paciente' => 'a.paciente ASC, a.fecha DESC',
        'estado' => 'a.estado ASC, a.fecha DESC',
        'precio' => 'a.precio_clp DESC, a.fecha DESC',
        'box' => 'a.box ASC, a.fecha DESC, a.hora DESC',
    ];

    public const COLUMNAS = 'a.id, a.publico_id, a.fecha, TIME_FORMAT(a.hora, \'%H:%i\') AS hora, a.duracion_min, a.estado, a.origen,
        a.tipo_atencion, a.modalidad_label, a.precio_clp, a.box_slug, a.box, a.profesional_id, a.profesional, a.especialidad_slug,
        a.especialidad, a.paciente_id, a.paciente, a.uso, a.boleta_id, a.factura_id, a.cobrada, a.creado_en';

    /**
     * Construye el WHERE común a la lista, el reporte operativo y el dashboard.
     * @return array{0: string, 1: list<mixed>}
     */
    public static function filtro(array $f, string $alias = 'a'): array
    {
        $w = ['1=1'];
        $p = [];
        if (!empty($f['desde'])) {
            $w[] = "$alias.fecha >= ?";
            $p[] = $f['desde'];
        }
        if (!empty($f['hasta'])) {
            $w[] = "$alias.fecha <= ?";
            $p[] = $f['hasta'];
        }
        if (!empty($f['estado'])) {
            $w[] = "$alias.estado = ?";
            $p[] = $f['estado'];
        }
        if (!empty($f['profesional_id'])) {
            $w[] = "$alias.profesional_id = ?";
            $p[] = (int) $f['profesional_id'];
        }
        if (!empty($f['box'])) {
            $w[] = "$alias.box_slug = ?";
            $p[] = $f['box'];
        }
        if (!empty($f['especialidad'])) {
            $w[] = "$alias.especialidad_slug = ?";
            $p[] = $f['especialidad'];
        }
        if (!empty($f['uso'])) {
            $w[] = "$alias.uso = ?";
            $p[] = $f['uso'];
        }
        if (!empty($f['paciente_id'])) {
            $w[] = "$alias.paciente_id = ?";
            $p[] = (int) $f['paciente_id'];
        }
        if (!empty($f['q'])) {
            $w[] = "($alias.paciente LIKE ? OR $alias.profesional LIKE ? OR $alias.publico_id = ?)";
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            array_push($p, $like, $like, $f['q']);
        }
        return [implode(' AND ', $w), $p];
    }

    /** @return array{data: list<array>, meta: array} */
    public function listar(array $f, string $orden, int $page, int $perPage): array
    {
        [$where, $params] = self::filtro($f);
        return $this->paginate(self::COLUMNAS, "FROM v_atenciones a WHERE $where", $params, self::ORDENES[$orden] ?? self::ORDENES['-fecha'], $page, $perPage);
    }

    /** Todas las filas del filtro (reporte operativo), con un tope de seguridad. */
    public function todas(array $f, string $orden, int $tope): array
    {
        [$where, $params] = self::filtro($f);
        return $this->all('SELECT ' . self::COLUMNAS . " FROM v_atenciones a WHERE $where ORDER BY " . (self::ORDENES[$orden] ?? self::ORDENES['fecha']) . " LIMIT $tope", $params);
    }

    public function contar(array $f): int
    {
        [$where, $params] = self::filtro($f);
        return (int) $this->scalar("SELECT COUNT(*) FROM v_atenciones a WHERE $where", $params);
    }

    public function sumaPrecio(array $f): int
    {
        [$where, $params] = self::filtro($f);
        return (int) $this->scalar("SELECT COALESCE(SUM(a.precio_clp), 0) FROM v_atenciones a WHERE $where", $params);
    }

    public function porId(int $id): ?array
    {
        $a = $this->one('SELECT ' . self::COLUMNAS . ', r.notas, r.factura_plan, r.cobro_mantenido, r.actualizado_en
                           FROM v_atenciones a JOIN reservas r ON r.id = a.id WHERE a.id = ?', [$id]);
        if ($a === null) {
            return null;
        }
        $a['movimientos'] = $this->all('SELECT accion, usuario_nombre, detalle, creado_en FROM reservas_movimientos WHERE reserva_id = ? ORDER BY id DESC LIMIT 20', [$id]);
        return $a;
    }

    /** Bloquea la fila para cambiar su estado sin carreras (dos recepcionistas a la vez). */
    public function bloquear(int $id): ?array
    {
        return $this->one('SELECT id, estado, fecha, hora, box_slug, profesional_id, paciente_id, boleta_id, factura_id, precio_clp, modalidad_label
                             FROM reservas WHERE id = ? FOR UPDATE', [$id]);
    }

    /** Reservas vigentes (no canceladas) del box en esa fecha y hora, sin contar $excepto. */
    public function ocupacionBox(string $box, string $fecha, string $hora, int $excepto = 0): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM reservas WHERE box_slug = ? AND fecha = ? AND hora = ? AND estado <> 'cancelada' AND id <> ?",
            [$box, $fecha, $hora . ':00', $excepto]);
    }

    public function choqueProfesional(int $profesionalId, string $fecha, string $hora, int $excepto = 0): bool
    {
        return (bool) $this->scalar("SELECT 1 FROM reservas WHERE profesional_id = ? AND fecha = ? AND hora = ? AND estado <> 'cancelada' AND id <> ? LIMIT 1",
            [$profesionalId, $fecha, $hora . ':00', $excepto]);
    }

    public function crear(array $d): int
    {
        return $this->insert('reservas', [
            'publico_id' => bin2hex(random_bytes(8)),
            'box_slug' => $d['box'],
            'profesional_id' => $d['profesional_id'],
            'paciente_id' => $d['paciente_id'],
            'modalidad_label' => $d['modalidad_label'],
            'tipo_atencion' => $d['tipo_atencion'],
            'precio_clp' => $d['precio_clp'],
            'fecha' => $d['fecha'],
            'hora' => $d['hora'] . ':00',
            'duracion_min' => $d['duracion_min'],
            'nombre' => $d['nombre'],
            'email' => $d['email'] ?? '',
            'telefono' => $d['telefono'] ?? '',
            'estado' => $d['estado'],
            'origen' => 'panel',
            'notas' => $d['notas'],
        ]);
    }

    public function cambiarEstado(int $id, string $estado, bool $reactivar): void
    {
        $this->exec('UPDATE reservas SET estado = ?' . ($reactivar ? ', cobro_mantenido = 0' : '') . ' WHERE id = ?', [$estado, $id]);
    }

    public function reprogramar(int $id, string $fecha, string $hora, string $box): void
    {
        $this->exec('UPDATE reservas SET fecha = ?, hora = ?, box_slug = ? WHERE id = ?', [$fecha, $hora . ':00', $box, $id]);
    }

    public function vincularBoleta(int $id, int $boletaId, int $neto): void
    {
        $this->exec('UPDATE reservas SET boleta_id = ?, boleta_neto = ?, boleta_afecto = 0 WHERE id = ?', [$boletaId, $neto, $id]);
    }

    public function registrarMovimiento(int $reservaId, string $accion, ?int $profesionalId, int $usuarioId, string $usuarioNombre, string $detalle): void
    {
        $this->insert('reservas_movimientos', [
            'reserva_id' => $reservaId, 'accion' => $accion, 'profesional_origen_id' => $profesionalId,
            'usuario_id' => $usuarioId, 'usuario_nombre' => $usuarioNombre, 'detalle' => mb_substr($detalle, 0, 300),
        ]);
    }
}
