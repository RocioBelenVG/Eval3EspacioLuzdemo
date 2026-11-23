<?php
declare(strict_types=1);

namespace EspacioLuz\Repository;

/** Boletas y facturas (tabla boletas, vista v_documentos), sus ítems y sus pagos. */
final class DocumentoRepository extends Repository
{
    public const ORDENES = [
        '-fecha' => 'd.fecha_emision DESC, d.id DESC',
        'fecha' => 'd.fecha_emision ASC, d.id ASC',
        '-total' => 'd.total_clp DESC, d.id DESC',
        '-saldo' => 'd.saldo_clp DESC, d.fecha_emision ASC',
        'receptor' => 'd.receptor_nombre ASC, d.fecha_emision DESC',
    ];

    private const COLUMNAS = 'd.id, d.tipo, d.serie, d.folio, d.fecha, d.empresa, d.receptor_nombre, d.receptor_rut, d.receptor_tipo,
        d.paciente_id, d.cliente_id, d.profesional_nombre, d.medio_pago, d.neto_clp, d.iva_clp, d.exento_clp, d.total_clp, d.nc_clp,
        d.nd_clp, d.total_ajustado, d.pagado_clp, d.saldo_clp, d.pago_estado, d.vence_en, d.pagado_en, d.dias_pago, d.estado,
        d.sii_estado, d.sii_track_id,
        (d.saldo_clp > 0 AND d.vence_en IS NOT NULL AND d.vence_en < CURDATE() AND d.estado = \'emitida\') AS vencido';

    /** @return array{0: string, 1: list<mixed>} */
    public static function filtro(array $f): array
    {
        $w = ['1=1'];
        $p = [];
        foreach (['desde' => 'd.fecha >= ?', 'hasta' => 'd.fecha <= ?', 'tipo' => 'd.tipo = ?', 'pago_estado' => 'd.pago_estado = ?',
                  'estado' => 'd.estado = ?', 'paciente_id' => 'd.paciente_id = ?', 'cliente_id' => 'd.cliente_id = ?', 'empresa_id' => 'd.empresa_id = ?'] as $k => $sql) {
            if (isset($f[$k]) && $f[$k] !== null && $f[$k] !== '') {
                $w[] = $sql;
                $p[] = $f[$k];
            }
        }
        if (!empty($f['vencidos'])) {
            $w[] = "d.saldo_clp > 0 AND d.vence_en < CURDATE() AND d.estado = 'emitida'";
        }
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $w[] = '(d.receptor_nombre LIKE ? OR d.receptor_rut LIKE ? OR d.folio = ?)';
            array_push($p, $like, $like, ctype_digit((string) $f['q']) ? (int) $f['q'] : -1);
        }
        return [implode(' AND ', $w), $p];
    }

    public function listar(array $f, string $orden, int $page, int $perPage): array
    {
        [$where, $params] = self::filtro($f);
        $r = $this->paginate(self::COLUMNAS, "FROM v_documentos d WHERE $where", $params, self::ORDENES[$orden] ?? self::ORDENES['-fecha'], $page, $perPage);
        $r['resumen'] = $this->one("SELECT COUNT(*) AS documentos, COALESCE(SUM(CASE WHEN d.estado = 'emitida' THEN d.total_ajustado END), 0) AS total,
                                           COALESCE(SUM(CASE WHEN d.estado = 'emitida' THEN d.saldo_clp END), 0) AS saldo,
                                           COALESCE(SUM(d.total_clp), 0) AS total_clp, COALESCE(SUM(d.nc_clp), 0) AS nc_clp,
                                           COALESCE(SUM(d.pagado_clp), 0) AS pagado_clp, COALESCE(SUM(d.saldo_clp), 0) AS saldo_clp
                                      FROM v_documentos d WHERE $where", $params);
        return $r;
    }

    public function todos(array $f, string $orden, int $tope): array
    {
        [$where, $params] = self::filtro($f);
        return $this->all('SELECT ' . self::COLUMNAS . " FROM v_documentos d WHERE $where ORDER BY " . (self::ORDENES[$orden] ?? self::ORDENES['fecha']) . " LIMIT $tope", $params);
    }

    public function porId(int $id): ?array
    {
        $d = $this->one('SELECT ' . self::COLUMNAS . ' FROM v_documentos d WHERE d.id = ?', [$id]);
        if ($d === null) {
            return null;
        }
        $d['items'] = $this->all('SELECT descripcion, cantidad, precio_unit_clp, afecto, total_clp FROM boleta_items WHERE boleta_id = ? ORDER BY id', [$id]);
        $d['pagos'] = $this->all('SELECT id, fecha, monto, medio, referencia, usuario_nombre, creado_en FROM documento_pagos WHERE documento_id = ? ORDER BY fecha, id', [$id]);
        $d['notas_credito'] = $this->all("SELECT id, folio, fecha_emision, motivo, total_clp, estado FROM notas_credito WHERE documento_id = ? ORDER BY id", [$id]);
        return $d;
    }

    public function bloquear(int $id): ?array
    {
        return $this->one('SELECT id, estado, total_clp, nc_clp, nd_clp, pagado_clp, paciente_id FROM boletas WHERE id = ? FOR UPDATE', [$id]);
    }

    public function agregarPago(int $id, string $fecha, int $monto, string $medio, ?string $referencia, string $usuario): int
    {
        return $this->insert('documento_pagos', ['documento_id' => $id, 'fecha' => $fecha, 'monto' => $monto, 'medio' => $medio,
            'referencia' => $referencia, 'usuario_nombre' => $usuario, 'creado_en' => date('Y-m-d H:i:s')]);
    }

    /** Recalcula pagado, notas y estado de pago (misma regla que docRecalcularPago del panel). */
    public function recalcularPago(int $id): void
    {
        $this->exec("UPDATE boletas b
            JOIN (SELECT COALESCE(SUM(monto), 0) AS pagado, MAX(fecha) AS ultimo FROM documento_pagos WHERE documento_id = ?) p
            JOIN (SELECT COALESCE(SUM(total_clp), 0) AS nc FROM notas_credito WHERE documento_id = ? AND estado = 'emitida') n
            JOIN (SELECT COALESCE(SUM(total_clp), 0) AS nd FROM notas_debito WHERE documento_id = ? AND estado = 'emitida') x
            SET b.pagado_clp = p.pagado, b.nc_clp = n.nc, b.nd_clp = x.nd,
                b.pago_estado = CASE WHEN GREATEST(b.total_clp - n.nc + x.nd, 0) <= p.pagado THEN 'pagado' WHEN p.pagado > 0 THEN 'parcial' ELSE 'pendiente' END,
                b.pagado_en = CASE WHEN GREATEST(b.total_clp - n.nc + x.nd, 0) <= p.pagado THEN COALESCE(TIMESTAMP(p.ultimo), NOW()) ELSE NULL END
            WHERE b.id = ?", [$id, $id, $id, $id]);
    }

    /** Registra en el panel la boleta ya emitida por el motor DTE (demo) y la enlaza con su documento DTE. */
    public function crearBoleta(array $d): int
    {
        $id = $this->insert('boletas', [
            'folio' => $d['folio'], 'tipo' => 'boleta', 'serie' => 'sii', 'empresa_id' => $d['empresa_id'],
            'emisor_usuario_id' => $d['usuario_id'], 'emisor_nombre' => $d['emisor_nombre'],
            'receptor_nombre' => $d['receptor_nombre'], 'receptor_rut' => $d['receptor_rut'], 'receptor_email' => $d['receptor_email'],
            'paciente_id' => $d['paciente_id'], 'receptor_tipo' => $d['receptor_tipo'], 'reserva_id' => $d['reserva_id'],
            'profesional_id' => $d['profesional_id'], 'profesional_nombre' => $d['profesional_nombre'],
            'fecha_emision' => date('Y-m-d H:i:s'), 'medio_pago' => $d['medio_pago'],
            'neto_clp' => $d['neto'], 'iva_clp' => $d['iva'], 'exento_clp' => $d['exento'], 'total_clp' => $d['total'],
            'glosa' => $d['glosa'], 'sii_estado' => 'aceptada', 'sii_track_id' => $d['trackid'],
        ]);
        $this->insert('boleta_items', ['boleta_id' => $id, 'descripcion' => $d['descripcion'], 'cantidad' => 1,
            'precio_unit_clp' => $d['total'], 'afecto' => 0, 'total_clp' => $d['total'], 'profesional_id' => $d['profesional_id']]);
        if (!empty($d['documento_dte_id'])) {
            $this->exec('UPDATE dte_documentos SET boleta_id = ?, reserva_id = ? WHERE id = ?', [$id, $d['reserva_id'], $d['documento_dte_id']]);
        }
        return $id;
    }
}
