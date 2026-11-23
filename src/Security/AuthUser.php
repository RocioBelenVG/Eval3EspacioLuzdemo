<?php
declare(strict_types=1);

namespace EspacioLuz\Security;

use EspacioLuz\Domain\Rol;

/** Usuario autenticado, reconstruido desde el JWT y confirmado contra la base en cada solicitud. */
final class AuthUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $nombre,
        public readonly string $email,
        public readonly Rol $rol,
        /** Profesional vinculado (rol clínico): limita lo que puede ver a sus propios pacientes. */
        public readonly ?int $profesionalId = null,
    ) {
    }

    public function es(Rol ...$roles): bool
    {
        return in_array($this->rol, $roles, true);
    }

    /** @return array{id: int, nombre: string, email: string, rol: string, rol_etiqueta: string, profesional_id: int|null} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'nombre' => $this->nombre, 'email' => $this->email, 'rol' => $this->rol->value,
            'rol_etiqueta' => $this->rol->etiqueta(), 'profesional_id' => $this->profesionalId];
    }
}
