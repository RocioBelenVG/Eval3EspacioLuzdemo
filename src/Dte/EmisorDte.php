<?php
declare(strict_types=1);

namespace EspacioLuz\Dte;

/**
 * Puerto de salida hacia el motor de documentos tributarios (inversión de dependencias:
 * el servicio de negocio depende de esta interfaz, no del motor DTE heredado).
 * En EspacioLuz Demo la única implementación productiva es MotorDteLegado, que usa el
 * motor original en MODO DEMOSTRACIÓN (nada llega al SII); las pruebas usan un doble.
 */
interface EmisorDte
{
    /**
     * Emite un documento y devuelve el resultado normalizado.
     *
     * @param list<array{descripcion: string, cantidad: int|float, precio_unitario: int, exento: int}> $items
     * @param array{rut?: string, razon_social?: string, contacto?: string} $receptor
     * @return array{ok: bool, folio?: int, documento_id?: int, trackid?: string, neto?: int, iva?: int, exento?: int, total?: int, error?: string|null, demo?: bool}
     */
    public function emitir(int $empresaId, int $tipo, array $items, array $receptor, ?int $pacienteId, string $observaciones, int $usuarioId, string $usuarioNombre): array;
}
