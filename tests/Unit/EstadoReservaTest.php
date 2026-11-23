<?php
declare(strict_types=1);

namespace EspacioLuz\Tests\Unit;

use EspacioLuz\Domain\EstadoReserva as E;
use EspacioLuz\Domain\Rol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Reglas de negocio del flujo de estados de una atención (capa de dominio, sin base de datos). */
final class EstadoReservaTest extends TestCase
{
    public static function transiciones(): array
    {
        return [
            'pendiente → confirmada' => [E::Pendiente, E::Confirmada, true],
            'pendiente → realizada' => [E::Pendiente, E::Realizada, true],
            'confirmada → no_show' => [E::Confirmada, E::NoShow, true],
            'confirmada → pendiente' => [E::Confirmada, E::Pendiente, false],
            'cancelada → confirmada (reactivación)' => [E::Cancelada, E::Confirmada, true],
            'cancelada → realizada' => [E::Cancelada, E::Realizada, false],
            'realizada → cancelada' => [E::Realizada, E::Cancelada, false],
            'no_show → realizada' => [E::NoShow, E::Realizada, false],
        ];
    }

    #[Test]
    #[DataProvider('transiciones')]
    public function respeta_la_tabla_de_transiciones(E $desde, E $hasta, bool $permitida): void
    {
        $this->assertSame($permitida, $desde->puedePasarA($hasta));
    }

    #[Test]
    public function los_estados_de_cierre_no_tienen_salida_y_exigen_fecha_cumplida(): void
    {
        foreach ([E::Realizada, E::NoShow] as $e) {
            $this->assertTrue($e->esCierre());
            $this->assertSame([], $e->siguientes());
            $this->assertTrue($e->exigeFechaCumplida());
        }
        $this->assertFalse(E::Cancelada->ocupaCupo());
        $this->assertTrue(E::Pendiente->ocupaCupo());
    }

    #[Test]
    public function ningun_estado_transita_a_si_mismo(): void
    {
        foreach (E::cases() as $e) {
            $this->assertFalse($e->puedePasarA($e), $e->value);
        }
    }

    #[Test]
    public function los_roles_usan_las_claves_del_sistema_original(): void
    {
        $this->assertSame(Rol::Recepcion, Rol::desdeBd('editor'));
        $this->assertSame(Rol::Clinico, Rol::desdeBd('pacientes'));
        $this->assertNull(Rol::desdeBd('superusuario'));
        $this->assertTrue(Rol::Recepcion->gestionaAgenda());
        $this->assertFalse(Rol::Gerencia->gestionaAgenda());
    }
}
