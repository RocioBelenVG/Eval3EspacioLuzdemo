<?php
declare(strict_types=1);

namespace EspacioLuz\Tests\Support;

use EspacioLuz\Dte\EmisorDte;

/**
 * Doble del motor DTE: las pruebas no tocan el motor heredado (abre su propia conexión
 * mysqli fuera de la transacción de la prueba) y no consumen folios del CAF demo.
 */
final class FakeEmisorDte implements EmisorDte
{
    public array $llamadas = [];

    public function __construct(private readonly bool $ok = true)
    {
    }

    public function emitir(int $empresaId, int $tipo, array $items, array $receptor, ?int $pacienteId, string $observaciones, int $usuarioId, string $usuarioNombre): array
    {
        $this->llamadas[] = compact('empresaId', 'tipo', 'items', 'receptor', 'pacienteId');
        if (!$this->ok) {
            return ['ok' => false, 'error' => 'Motor simulado sin folios.'];
        }
        $total = (int) array_sum(array_map(fn ($i) => (int) round($i['cantidad'] * $i['precio_unitario']), $items));
        return ['ok' => true, 'folio' => 900000 + count($this->llamadas), 'documento_id' => null, 'trackid' => 'DEMO-TEST-' . count($this->llamadas),
            'neto' => 0, 'iva' => 0, 'exento' => $total, 'total' => $total, 'error' => null, 'demo' => true];
    }
}
