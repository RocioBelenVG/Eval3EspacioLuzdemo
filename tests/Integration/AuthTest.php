<?php
declare(strict_types=1);

namespace EspacioLuz\Tests\Integration;

use EspacioLuz\Security\Jwt;
use PHPUnit\Framework\Attributes\Test;

/** Autenticación JWT, sesión del panel y protección de credenciales (OWASP API2). */
final class AuthTest extends ApiTestCase
{
    #[Test]
    public function el_login_correcto_entrega_un_jwt_y_los_datos_del_usuario(): void
    {
        $r = $this->request('POST', '/auth/login', ['email' => $this->usuarios['Recepcion']['email'], 'password' => $this->password]);
        $this->assertSame(200, $r->status, $r->body);
        $d = $r->data();
        $this->assertSame('Bearer', $d['token_type']);
        $this->assertSame(3, substr_count($d['token'], '.') + 1);
        $this->assertSame('editor', $d['usuario']['rol']);
        $this->assertArrayNotHasKey('password_hash', $d['usuario']);
    }

    #[Test]
    public function una_contrasena_incorrecta_no_revela_si_el_correo_existe(): void
    {
        $a = $this->request('POST', '/auth/login', ['email' => $this->usuarios['Recepcion']['email'], 'password' => 'incorrecta-123']);
        $b = $this->request('POST', '/auth/login', ['email' => 'no.existe@example.com', 'password' => 'incorrecta-123']);
        $this->assertError($a, 401, 'CREDENCIALES_INVALIDAS');
        $this->assertError($b, 401, 'CREDENCIALES_INVALIDAS');
        $this->assertSame($a->data()['error']['message'], $b->data()['error']['message']);
    }

    #[Test]
    public function el_login_valida_la_entrada_y_responde_422_con_detalle_por_campo(): void
    {
        $r = $this->request('POST', '/auth/login', ['email' => 'sin-arroba']);
        $this->assertError($r, 422, 'VALIDATION_ERROR');
        $campos = array_column($r->data()['error']['details'], 'field');
        $this->assertContains('email', $campos);
        $this->assertContains('password', $campos);
    }

    #[Test]
    public function seis_intentos_fallidos_seguidos_se_bloquean_con_429(): void
    {
        $email = $this->usuarios['Gerencia']['email'];
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(401, $this->request('POST', '/auth/login', ['email' => $email, 'password' => 'mala'])->status);
        }
        $r = $this->request('POST', '/auth/login', ['email' => $email, 'password' => 'mala']);
        $this->assertError($r, 429, 'DEMASIADAS_SOLICITUDES');
        $this->assertNotEmpty($r->headers['Retry-After'] ?? null);
    }

    #[Test]
    public function sin_token_o_con_token_alterado_la_api_responde_401(): void
    {
        $this->assertError($this->request('GET', '/auth/me'), 401, 'NO_AUTENTICADO');
        $t = $this->token('Administrador');
        $alterado = substr($t, 0, -2) . (str_ends_with($t, 'AA') ? 'BB' : 'AA');
        $this->assertError($this->request('GET', '/auth/me', [], [], $alterado), 401, 'TOKEN_INVALIDO');
        $none = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=') . '.' . explode('.', $t)[1] . '.';
        $this->assertError($this->request('GET', '/auth/me', [], [], $none), 401, 'TOKEN_INVALIDO');
    }

    #[Test]
    public function un_token_vencido_se_rechaza(): void
    {
        $jwt = new Jwt((string) env('JWT_SECRET'), 60);
        $viejo = $jwt->encode(['sub' => $this->usuarios['Administrador']['id'], 'rol' => 'admin'], time() - 3600);
        $this->assertError($this->request('GET', '/auth/me', [], [], $viejo), 401, 'TOKEN_EXPIRADO');
    }

    #[Test]
    public function desactivar_la_cuenta_invalida_de_inmediato_su_token(): void
    {
        $t = $this->token('Recepcion');
        $this->assertSame(200, $this->request('GET', '/auth/me', [], [], $t)->status);
        self::$db->pdo->exec('UPDATE usuarios SET activo = 0 WHERE id = ' . $this->usuarios['Recepcion']['id']);
        $this->assertError($this->request('GET', '/auth/me', [], [], $t), 401, 'CUENTA_INACTIVA');
    }

    #[Test]
    public function la_sesion_del_panel_se_cambia_por_un_token_solo_con_csrf_valido(): void
    {
        $this->assertError($this->request('POST', '/auth/sesion'), 401, 'SIN_SESION');
        $this->sesionPanel = ['id' => $this->usuarios['Gerencia']['id'], 'csrf' => 'abc123'];
        $this->assertError($this->request('POST', '/auth/sesion', [], [], null, ['x-csrf-token' => 'otro']), 403, 'CSRF_INVALIDO');
        $r = $this->request('POST', '/auth/sesion', [], [], null, ['x-csrf-token' => 'abc123']);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame('gerencia', $r->data()['usuario']['rol']);
    }

    #[Test]
    public function las_respuestas_traen_cabeceras_de_seguridad_y_request_id(): void
    {
        $r = $this->request('GET', '/health');
        $this->assertSame(200, $r->status);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertSame('DENY', $r->headers['X-Frame-Options']);
        $this->assertNotEmpty($r->headers['X-Request-Id']);
        $this->assertSame('demo', $r->data()['modo_dte']);
    }
}
