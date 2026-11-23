<?php
declare(strict_types=1);

namespace EspacioLuz\Tests\Support;

use EspacioLuz\Ia\IaClient;
use EspacioLuz\Ia\IaException;

/** Doble de prueba del servicio de IA: responde lo que se le programe, sin salir a internet. */
final class FakeIaClient implements IaClient
{
    public ?string $ultimosDatos = null;
    public int $llamadas = 0;

    /** @param array<string, mixed>|IaException $respuesta */
    public function __construct(
        private array|IaException $respuesta = [],
        private readonly bool $configurado = true,
    ) {
        if ($respuesta === []) {
            $this->respuesta = [
                'resumen' => 'La asistencia se mantuvo estable y la ocupación de boxes subió respecto del período anterior.',
                'hallazgos' => ['La tasa de asistencia fue de 92 %.', 'Las cuentas por cobrar bajaron.'],
                'recomendaciones' => ['Confirmar por teléfono las sesiones del día siguiente.', 'Ofrecer los bloques libres del sábado a los profesionales externos.'],
            ];
        }
    }

    public function completarJson(string $instrucciones, string $datos): array
    {
        $this->llamadas++;
        $this->ultimosDatos = $datos;
        if ($this->respuesta instanceof IaException) {
            throw $this->respuesta;
        }
        return $this->respuesta;
    }

    public function modelo(): string
    {
        return 'modelo-simulado';
    }

    public function configurado(): bool
    {
        return $this->configurado;
    }
}
