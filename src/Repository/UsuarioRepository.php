<?php
declare(strict_types=1);

namespace EspacioLuz\Repository;

final class UsuarioRepository extends Repository
{
    private const SELECT = 'SELECT u.id, u.nombre, u.email, u.password_hash, u.rol, u.activo,
                                   (SELECT p.id FROM profesionales p WHERE p.user_id = u.id AND p.eliminado_at IS NULL LIMIT 1) AS profesional_id
                            FROM usuarios u';

    public function porEmail(string $email): ?array
    {
        return $this->one(self::SELECT . ' WHERE u.email = ?', [strtolower(trim($email))]);
    }

    public function porId(int $id): ?array
    {
        return $this->one(self::SELECT . ' WHERE u.id = ?', [$id]);
    }

    public function registrarAcceso(int $id): void
    {
        $this->exec('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?', [$id]);
    }
}
