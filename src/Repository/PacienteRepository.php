<?php
declare(strict_types=1);

namespace EspacioLuz\Repository;

final class PacienteRepository extends Repository
{
    public const ORDENES = [
        'nombre' => 'p.nombre ASC, p.id ASC',
        '-creado' => 'p.creado_en DESC, p.id DESC',
        'creado' => 'p.creado_en ASC, p.id ASC',
        '-ultima' => 'ultima_atencion IS NULL, ultima_atencion DESC, p.id DESC',
        'comuna' => 'p.comuna ASC, p.nombre ASC',
    ];

    private const COLUMNAS = "p.id, p.nombre, p.rut, p.email, p.telefono, p.fecha_nacimiento,
        TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) AS edad, p.sexo, p.comuna, p.region, p.prevision,
        p.apoderado, p.activo, p.consentimiento, p.creado_en,
        (SELECT MAX(r.fecha) FROM reservas r WHERE r.paciente_id = p.id AND r.estado = 'realizada') AS ultima_atencion,
        (SELECT COUNT(*) FROM reservas r WHERE r.paciente_id = p.id AND r.estado = 'realizada') AS atenciones";

    /**
     * @param int|null $profesionalId si viene (rol clínico), solo pacientes atendidos por ese profesional (OWASP API1).
     */
    public function listar(array $f, string $orden, int $page, int $perPage, ?int $profesionalId): array
    {
        $w = ['1=1'];
        $p = [];
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $w[] = '(p.nombre LIKE ? OR p.rut LIKE ? OR p.email LIKE ? OR p.apoderado LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        if (isset($f['activo']) && $f['activo'] !== null) {
            $w[] = 'p.activo = ?';
            $p[] = $f['activo'] ? 1 : 0;
        }
        if (!empty($f['comuna'])) {
            $w[] = 'p.comuna = ?';
            $p[] = $f['comuna'];
        }
        if (!empty($f['prevision'])) {
            $w[] = 'p.prevision = ?';
            $p[] = $f['prevision'];
        }
        if ($profesionalId !== null) {
            $w[] = 'EXISTS (SELECT 1 FROM reservas r WHERE r.paciente_id = p.id AND r.profesional_id = ?)';
            $p[] = $profesionalId;
        }
        return $this->paginate(self::COLUMNAS, 'FROM pacientes p WHERE ' . implode(' AND ', $w), $p,
            self::ORDENES[$orden] ?? self::ORDENES['nombre'], $page, $perPage);
    }

    public function porId(int $id): ?array
    {
        $p = $this->one('SELECT ' . self::COLUMNAS . ', p.direccion, p.apoderado_rut, p.apoderado_telefono, p.contacto_emergencia, p.notas,
                                p.consentimiento_fecha, p.actualizado_en FROM pacientes p WHERE p.id = ?', [$id]);
        if ($p === null) {
            return null;
        }
        $p['fichas'] = $this->all("SELECT f.id, f.especialidad_slug, e.nombre AS especialidad, f.estado, f.abierta_en, f.cerrada_en,
                                          pr.nombre AS profesional_responsable,
                                          (SELECT COUNT(*) FROM evoluciones ev WHERE ev.ficha_id = f.id AND ev.eliminado_en IS NULL) AS evoluciones
                                     FROM fichas f
                                     JOIN especialidades e ON e.slug = f.especialidad_slug
                                     LEFT JOIN profesionales pr ON pr.id = f.profesional_responsable_id
                                    WHERE f.paciente_id = ? ORDER BY f.abierta_en DESC", [$id]);
        $p['proximas'] = $this->all("SELECT id, fecha, TIME_FORMAT(hora, '%H:%i') AS hora, estado, profesional, box FROM v_atenciones
                                      WHERE paciente_id = ? AND fecha >= CURDATE() AND estado IN ('pendiente','confirmada') ORDER BY fecha, hora LIMIT 5", [$id]);
        $p['saldo_pendiente'] = (int) $this->scalar("SELECT COALESCE(SUM(saldo_clp), 0) FROM v_documentos WHERE paciente_id = ? AND estado = 'emitida'", [$id]);
        return $p;
    }

    public function atendidoPor(int $pacienteId, int $profesionalId): bool
    {
        return (bool) $this->scalar('SELECT 1 FROM reservas WHERE paciente_id = ? AND profesional_id = ? LIMIT 1', [$pacienteId, $profesionalId]);
    }

    public function existe(int $id): bool
    {
        return (bool) $this->scalar('SELECT 1 FROM pacientes WHERE id = ?', [$id]);
    }

    public function basico(int $id): ?array
    {
        return $this->one('SELECT id, nombre, rut, email, telefono, apoderado, apoderado_rut, fecha_nacimiento, activo FROM pacientes WHERE id = ?', [$id]);
    }

    /** Id del paciente con ese RUT (normalizado), o null. */
    public function idPorRut(string $rutFormateado, int $excepto = 0): ?int
    {
        $id = $this->scalar('SELECT id FROM pacientes WHERE rut = ? AND id <> ?', [$rutFormateado, $excepto]);
        return $id === false ? null : (int) $id;
    }

    /** Campos permitidos (lista blanca): la API nunca escribe columnas que el cliente invente (OWASP API3). */
    private const CAMPOS = ['nombre', 'rut', 'email', 'telefono', 'fecha_nacimiento', 'sexo', 'direccion', 'region', 'comuna',
        'apoderado', 'apoderado_rut', 'apoderado_telefono', 'contacto_emergencia', 'prevision', 'notas'];

    public function crear(array $d): int
    {
        $fila = array_intersect_key($d, array_flip(self::CAMPOS));
        $fila['documento_tipo'] = 'rut';
        $fila['consentimiento'] = 1;
        $fila['consentimiento_fecha'] = date('Y-m-d H:i:s');
        $fila['activo'] = 1;
        return $this->insert('pacientes', $fila);
    }

    public function actualizar(int $id, array $d): void
    {
        $fila = array_intersect_key($d, array_flip([...self::CAMPOS, 'activo']));
        if (array_key_exists('activo', $fila)) {
            $fila['activo'] = $fila['activo'] ? 1 : 0;
        }
        $this->update('pacientes', $id, $fila);
    }
}
