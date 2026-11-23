<?php
declare(strict_types=1);

namespace EspacioLuz\Domain;

/**
 * Roles del sistema. El valor es la clave guardada en usuarios.rol (la misma que usa
 * el panel clásico), así ambas capas comparten una sola fuente de verdad.
 */
enum Rol: string
{
    /** Acceso total: agenda, pacientes, documentos, reportes, auditoría y usuarios. */
    case Administrador = 'admin';
    /** Recepción (rol «editor» del sistema original): agenda, pacientes y cobros; sin reportes financieros. */
    case Recepcion = 'editor';
    /** Área clínica (rol «pacientes» del original): solo sus pacientes y sus atenciones. */
    case Clinico = 'pacientes';
    /** Gerencia: dashboard, indicadores, reportes e IA, en modo solo lectura. */
    case Gerencia = 'gerencia';

    public static function desdeBd(string $valor): ?self
    {
        return self::tryFrom($valor);
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Administrador => 'Administración',
            self::Recepcion => 'Recepción',
            self::Clinico => 'Área clínica',
            self::Gerencia => 'Gerencia',
        };
    }

    /** Roles que gestionan la agenda (crean, reprograman y cambian estados). */
    public function gestionaAgenda(): bool
    {
        return $this === self::Administrador || $this === self::Recepcion;
    }
}
