<?php
declare(strict_types=1);

namespace EspacioLuz\Service;

use EspacioLuz\Exception\ApiException;
use EspacioLuz\Infrastructure\Database;
use EspacioLuz\Repository\DocumentoRepository;
use EspacioLuz\Security\AuthUser;
use EspacioLuz\Validation\Validator;

/** Consulta de boletas y facturas (demo) y CU-06 · registro de pagos. */
final class DocumentoService
{
    public function __construct(private readonly Database $db, private readonly DocumentoRepository $documentos)
    {
    }

    public function filtros(array $query): array
    {
        $f = Validator::check($query, [
            'q' => ['string', 'max:80'],
            'tipo' => ['in:boleta,factura'],
            'pago_estado' => ['in:pendiente,parcial,pagado'],
            'estado' => ['in:emitida,anulada'],
            'desde' => ['date'],
            'hasta' => ['date'],
            'vencidos' => ['bool'],
            'paciente_id' => ['int', 'min:1'],
            'cliente_id' => ['int', 'min:1'],
            'sort' => ['in:' . implode(',', array_keys(DocumentoRepository::ORDENES))],
        ]);
        if (!empty($f['desde']) && !empty($f['hasta']) && $f['desde'] > $f['hasta']) {
            throw ApiException::field('hasta', 'La fecha final debe ser igual o posterior a la inicial.');
        }
        return $f;
    }

    public function listar(array $query): array
    {
        $f = $this->filtros($query);
        [$page, $perPage] = Validator::paginacion($query);
        return $this->documentos->listar($f, $f['sort'] ?? '-fecha', $page, $perPage);
    }

    public function ver(int $id): array
    {
        return $this->documentos->porId($id) ?? throw ApiException::notFound('El documento no existe.');
    }

    public function registrarPago(AuthUser $u, int $id, array $body): array
    {
        $d = Validator::check($body, [
            'monto' => ['required', 'int', 'min:1', 'max:50000000'],
            'fecha' => ['nullable', 'date'],
            'medio' => ['required', 'in:efectivo,tarjeta,transferencia,otro'],
            'referencia' => ['nullable', 'string', 'max:160'],
        ]);
        $fecha = $d['fecha'] ?? date('Y-m-d');
        if ($fecha > date('Y-m-d')) {
            throw ApiException::field('fecha', 'La fecha del pago no puede ser futura.');
        }
        $this->db->transactional(function () use ($u, $id, $d, $fecha) {
            $b = $this->documentos->bloquear($id) ?? throw ApiException::notFound('El documento no existe.');
            if ($b['estado'] === 'anulada') {
                throw ApiException::conflict('El documento está anulado: no recibe pagos.', 'DOCUMENTO_ANULADO');
            }
            $saldo = max(0, (int) $b['total_clp'] - (int) $b['nc_clp'] + (int) $b['nd_clp'] - (int) $b['pagado_clp']);
            if ($saldo <= 0) {
                throw ApiException::conflict('El documento ya está pagado.', 'DOCUMENTO_PAGADO');
            }
            if ($d['monto'] > $saldo) {
                throw ApiException::field('monto', 'El pago supera el saldo pendiente ($' . number_format($saldo, 0, ',', '.') . ').');
            }
            $this->documentos->agregarPago($id, $fecha, $d['monto'], $d['medio'], $d['referencia'] ?? null, $u->nombre);
            $this->documentos->recalcularPago($id);
        });
        return $this->ver($id);
    }
}
