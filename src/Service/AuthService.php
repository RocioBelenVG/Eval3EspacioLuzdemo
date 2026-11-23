<?php
declare(strict_types=1);

namespace EspacioLuz\Service;

use EspacioLuz\Domain\Rol;
use EspacioLuz\Exception\ApiException;
use EspacioLuz\Repository\UsuarioRepository;
use EspacioLuz\Security\AuthUser;
use EspacioLuz\Security\Jwt;
use EspacioLuz\Security\RateLimiter;

/** Autenticación (JWT) y reconstrucción del usuario en cada solicitud (OWASP API2). */
final class AuthService
{
    /** Intentos de inicio de sesión permitidos por IP y correo en cada ventana. */
    public const LOGIN_LIMITE = 5;
    public const LOGIN_VENTANA = 60;

    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly Jwt $jwt,
        private readonly RateLimiter $limiter,
    ) {
    }

    /** @return array{token: string, token_type: string, expires_in: int, usuario: array} */
    public function login(string $email, string $password, string $ip): array
    {
        $clave = 'login:' . $ip . ':' . strtolower($email);
        $this->limiter->hit($clave, self::LOGIN_LIMITE, self::LOGIN_VENTANA);

        $u = $this->usuarios->porEmail($email);
        // Si el correo no existe se verifica igual contra un hash de relleno, para no revelar
        // por el tiempo de respuesta qué correos están registrados.
        $hash = $u ? preg_replace('/^\$2b\$/', '$2y$', (string) $u['password_hash']) : password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        $ok = password_verify($password, (string) $hash);
        if (!$u || !$ok || !(int) $u['activo']) {
            throw ApiException::unauthorized('Correo o contraseña incorrectos.', 'CREDENCIALES_INVALIDAS');
        }
        $user = $this->aUsuario($u);
        $this->limiter->reset($clave);
        $this->usuarios->registrarAcceso($user->id);
        return $this->emitir($user);
    }

    /** Token para un usuario ya autenticado por otro medio (la sesión del panel clásico). */
    public function tokenParaUsuario(int $usuarioId): array
    {
        $u = $this->usuarios->porId($usuarioId);
        if (!$u || !(int) $u['activo']) {
            throw ApiException::unauthorized('La cuenta no existe o está desactivada.', 'CUENTA_INACTIVA');
        }
        return $this->emitir($this->aUsuario($u));
    }

    /**
     * Valida el token y vuelve a leer el usuario: si lo desactivaron o le cambiaron el rol,
     * el cambio rige de inmediato aunque el token siga vigente.
     */
    public function autenticar(string $token): AuthUser
    {
        $claims = $this->jwt->decode($token);
        $u = $this->usuarios->porId((int) ($claims['sub'] ?? 0));
        if (!$u || !(int) $u['activo']) {
            throw ApiException::unauthorized('La cuenta no existe o está desactivada.', 'CUENTA_INACTIVA');
        }
        return $this->aUsuario($u);
    }

    private function emitir(AuthUser $user): array
    {
        return [
            'token' => $this->jwt->encode(['sub' => $user->id, 'rol' => $user->rol->value]),
            'token_type' => 'Bearer',
            'expires_in' => $this->jwt->ttl(),
            'usuario' => $user->toArray(),
        ];
    }

    private function aUsuario(array $u): AuthUser
    {
        $rol = Rol::desdeBd((string) $u['rol']);
        if ($rol === null) {
            throw ApiException::forbidden('Su rol no tiene acceso a la API.', 'ROL_SIN_ACCESO');
        }
        return new AuthUser((int) $u['id'], (string) $u['nombre'], (string) $u['email'], $rol,
            $u['profesional_id'] !== null ? (int) $u['profesional_id'] : null);
    }
}
