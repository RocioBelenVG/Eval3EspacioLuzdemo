<?php
declare(strict_types=1);

namespace EspacioLuz\Controller;

use EspacioLuz\Exception\ApiException;
use EspacioLuz\Http\Request;
use EspacioLuz\Http\Response;
use EspacioLuz\Service\AuthService;
use EspacioLuz\Validation\Validator;

final class AuthController
{
    /** @param \Closure(): ?array{id: int, csrf: string} $sesionPanel lee la sesión del panel clásico (si existe) */
    public function __construct(private readonly AuthService $auth, private readonly ?\Closure $sesionPanel = null)
    {
    }

    public function login(Request $r): Response
    {
        $d = Validator::check($r->json(), [
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        return Response::json($this->auth->login($d['email'], $d['password'], $r->ip));
    }

    public function me(Request $r): Response
    {
        return Response::json(['usuario' => $r->user()->toArray()]);
    }

    /**
     * Intercambia la sesión del panel clásico por un JWT, para que las pantallas nuevas del
     * panel (dashboard, reportes, atenciones) consuman la API sin pedir de nuevo la contraseña.
     * Exige el token CSRF de la sesión en el encabezado X-CSRF-Token.
     */
    public function sesion(Request $r): Response
    {
        $s = $this->sesionPanel ? ($this->sesionPanel)() : null;
        if ($s === null) {
            throw ApiException::unauthorized('No hay una sesión activa en el panel.', 'SIN_SESION');
        }
        $csrf = (string) ($r->header('x-csrf-token') ?? '');
        if ($csrf === '' || !hash_equals($s['csrf'], $csrf)) {
            throw ApiException::forbidden('Falta o no coincide el token CSRF de la sesión.', 'CSRF_INVALIDO');
        }
        return Response::json($this->auth->tokenParaUsuario($s['id']));
    }
}
