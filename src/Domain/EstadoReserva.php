<?php
declare(strict_types=1);

namespace EspacioLuz\Domain;

/**
 * Estados de una atención (tabla reservas) y sus transiciones válidas (patrón State
 * expresado como máquina de estados en un enum).
 *
 *   pendiente ──► confirmada ──► realizada | no_show   (cierre)
 *       │              └──────► cancelada ──► pendiente | confirmada (reactivación)
 *       └──► realizada | no_show | cancelada
 *
 * La misma regla está en el disparador trg_reservas_transicion (base de datos) y la
 * usa el panel clásico (api/panel/reservas.php): ningún camino puede saltársela.
 */
enum EstadoReserva: string
{
    case Pendiente = 'pendiente';
    case Confirmada = 'confirmada';
    case Realizada = 'realizada';
    case Cancelada = 'cancelada';
    case NoShow = 'no_show';

    /** @return list<self> */
    public function siguientes(): array
    {
        return match ($this) {
            self::Pendiente => [self::Confirmada, self::Realizada, self::Cancelada, self::NoShow],
            self::Confirmada => [self::Realizada, self::Cancelada, self::NoShow],
            self::Cancelada => [self::Pendiente, self::Confirmada],
            self::Realizada, self::NoShow => [],
        };
    }

    public function puedePasarA(self $destino): bool
    {
        return in_array($destino, $this->siguientes(), true);
    }

    /** Estados de cierre: la atención ya ocurrió (o no se presentó) y no admite cambios. */
    public function esCierre(): bool
    {
        return $this === self::Realizada || $this === self::NoShow;
    }

    /** Solo se puede marcar como realizada o inasistencia cuando la fecha ya llegó. */
    public function exigeFechaCumplida(): bool
    {
        return $this->esCierre();
    }

    /** Ocupa cupo en el box (todas menos la cancelada). */
    public function ocupaCupo(): bool
    {
        return $this !== self::Cancelada;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Confirmada => 'Confirmada',
            self::Realizada => 'Realizada',
            self::Cancelada => 'Cancelada',
            self::NoShow => 'No asistió',
        };
    }

    /** Valores de texto (para validar y documentar). */
    public static function valores(): array
    {
        return array_map(fn (self $e) => $e->value, self::cases());
    }
}
