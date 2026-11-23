<?php
declare(strict_types=1);

namespace EspacioLuz;

use EspacioLuz\Controller\AtencionController;
use EspacioLuz\Controller\AuditoriaController;
use EspacioLuz\Controller\AuthController;
use EspacioLuz\Controller\CatalogoController;
use EspacioLuz\Controller\DashboardController;
use EspacioLuz\Controller\DocumentoController;
use EspacioLuz\Controller\HealthController;
use EspacioLuz\Controller\IaController;
use EspacioLuz\Controller\PacienteController;
use EspacioLuz\Controller\ReporteController;
use EspacioLuz\Domain\Rol;
use EspacioLuz\Exception\ApiException;
use EspacioLuz\Http\Request;
use EspacioLuz\Http\Response;
use EspacioLuz\Http\Router;
use EspacioLuz\Infrastructure\Database;
use EspacioLuz\Security\RateLimiter;
use EspacioLuz\Service\AuthService;

/**
 * Núcleo de la API REST /api/v1 (contrato: docs/openapi.yaml):
 *   ruta → autenticación JWT → límite de tasa → rol → controlador → servicio → repositorio.
 * Todo error sale con el esquema `Error` del contrato y un request_id para rastrearlo en el log.
 */
final class Kernel
{
    /** Solicitudes por usuario y minuto en las rutas autenticadas (OWASP API4). */
    public const API_LIMITE = 120;

    private Router $router;

    /** @param list<string> $origenesCors orígenes externos permitidos (vacío = solo el mismo sitio) */
    public function __construct(
        private readonly Container $c,
        private readonly ?string $logFile = null,
        private readonly array $origenesCors = [],
        private readonly ?string $rutaContrato = null,
    ) {
        $this->router = self::rutas();
    }

    public static function rutas(): Router
    {
        $A = Rol::Administrador;
        $R = Rol::Recepcion;
        $C = Rol::Clinico;
        $G = Rol::Gerencia;
        $r = new Router();

        $r->add('GET', '/health', [HealthController::class, 'health'], public: true);
        $r->add('POST', '/auth/login', [AuthController::class, 'login'], public: true);
        $r->add('POST', '/auth/sesion', [AuthController::class, 'sesion'], public: true);
        $r->add('GET', '/auth/me', [AuthController::class, 'me']);

        $r->add('GET', '/profesionales', [CatalogoController::class, 'profesionales']);
        $r->add('GET', '/boxes', [CatalogoController::class, 'boxes']);
        $r->add('GET', '/opciones', [CatalogoController::class, 'opciones']);

        $r->add('GET', '/atenciones', [AtencionController::class, 'index'], [$A, $R, $C, $G]);
        $r->add('POST', '/atenciones', [AtencionController::class, 'store'], [$A, $R]);
        $r->add('GET', '/atenciones/{id}', [AtencionController::class, 'show'], [$A, $R, $C, $G]);
        $r->add('PATCH', '/atenciones/{id}', [AtencionController::class, 'reprogramar'], [$A, $R]);
        $r->add('PATCH', '/atenciones/{id}/estado', [AtencionController::class, 'estado'], [$A, $R, $C]);
        $r->add('POST', '/atenciones/{id}/boleta', [AtencionController::class, 'boleta'], [$A, $R]);

        $r->add('GET', '/pacientes', [PacienteController::class, 'index'], [$A, $R, $C]);
        $r->add('POST', '/pacientes', [PacienteController::class, 'store'], [$A, $R]);
        $r->add('GET', '/pacientes/{id}', [PacienteController::class, 'show'], [$A, $R, $C]);
        $r->add('PUT', '/pacientes/{id}', [PacienteController::class, 'update'], [$A, $R]);

        $r->add('GET', '/documentos', [DocumentoController::class, 'index'], [$A, $R, $G]);
        $r->add('GET', '/documentos/{id}', [DocumentoController::class, 'show'], [$A, $R, $G]);
        $r->add('POST', '/documentos/{id}/pagos', [DocumentoController::class, 'pago'], [$A, $R]);

        $r->add('GET', '/dashboard/indicadores', [DashboardController::class, 'indicadores'], [$A, $G]);
        $r->add('GET', '/dashboard/series', [DashboardController::class, 'series'], [$A, $G]);

        $r->add('GET', '/reportes', [ReporteController::class, 'index'], [$A, $R, $G]);
        $r->add('GET', '/reportes/{reporte}', [ReporteController::class, 'datos'], [$A, $R, $G]);
        $r->add('GET', '/reportes/{reporte}/export', [ReporteController::class, 'exportar'], [$A, $R, $G]);

        $r->add('POST', '/ia/resumen', [IaController::class, 'resumen'], [$A, $G]);

        $r->add('GET', '/auditoria', [AuditoriaController::class, 'index'], [$A]);
        $r->add('GET', '/auditoria/exportaciones', [AuditoriaController::class, 'exportaciones'], [$A]);

        return $r;
    }

    public function handle(Request $req): Response
    {
        try {
            if ($req->method === 'GET' && $req->path === '/openapi.yaml' && $this->rutaContrato && is_file($this->rutaContrato)) {
                $resp = new Response(200, (string) file_get_contents($this->rutaContrato), ['Content-Type' => 'application/yaml; charset=utf-8', 'Cache-Control' => 'no-cache']);
            } elseif ($req->method === 'OPTIONS') {
                $resp = new Response(204, '', ['Allow' => 'GET, POST, PUT, PATCH, OPTIONS']);
            } else {
                $ruta = $this->router->match($req->method, $req->path);
                $req->params = $ruta['params'];

                if (!$ruta['public']) {
                    $token = $req->bearerToken() ?? throw ApiException::unauthorized('Falta el encabezado Authorization: Bearer <token>.');
                    $req->user = $this->c->get(AuthService::class)->autenticar($token);
                    $this->c->get(RateLimiter::class)->hit('api:' . $req->user->id, self::API_LIMITE, 60);
                    if ($ruta['roles'] !== null && !$req->user->es(...$ruta['roles'])) {
                        throw ApiException::forbidden();
                    }
                    // Auditoría: los disparadores registran a este usuario como autor de los cambios.
                    $this->c->get(Database::class)->identificar($req->user->id, $req->user->nombre, $req->ip);
                }

                [$clase, $metodo] = $ruta['handler'];
                $resp = $this->c->get($clase)->$metodo($req);
            }
        } catch (ApiException $e) {
            $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            if ($e->details) {
                $error['details'] = $e->details;
            }
            $error['request_id'] = $req->requestId;
            $resp = Response::json(['error' => $error], $e->status, $e->headers);
        } catch (\Throwable $e) {
            $this->log($req, $e);
            $resp = Response::json(['error' => [
                'code' => 'ERROR_INTERNO',
                'message' => 'Ocurrió un error inesperado. Informe este código a soporte: ' . $req->requestId,
                'request_id' => $req->requestId,
            ]], 500);
        }
        return $this->cabeceras($req, $resp);
    }

    /** Cabeceras de seguridad y CORS restringido (OWASP API8). */
    private function cabeceras(Request $req, Response $resp): Response
    {
        $resp->headers['X-Request-Id'] = $req->requestId;
        $resp->headers['Cache-Control'] ??= 'no-store';
        $resp->headers['X-Content-Type-Options'] = 'nosniff';
        $resp->headers['X-Frame-Options'] = 'DENY';
        $resp->headers['Referrer-Policy'] = 'no-referrer';
        $resp->headers['Content-Security-Policy'] = "default-src 'none'; frame-ancestors 'none'";
        $origen = $req->header('origin');
        if ($origen !== null && in_array($origen, $this->origenesCors, true)) {
            $resp->headers['Access-Control-Allow-Origin'] = $origen;
            $resp->headers['Vary'] = 'Origin';
            $resp->headers['Access-Control-Allow-Headers'] = 'Authorization, Content-Type, X-CSRF-Token';
            $resp->headers['Access-Control-Allow-Methods'] = 'GET, POST, PUT, PATCH, OPTIONS';
            $resp->headers['Access-Control-Max-Age'] = '600';
        }
        return $resp;
    }

    private function log(Request $req, \Throwable $e): void
    {
        $linea = sprintf("[%s] %s %s %s → %s: %s en %s:%d\n", date('c'), $req->requestId, $req->method, $req->path, $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
        if ($this->logFile) {
            @file_put_contents($this->logFile, $linea, FILE_APPEND | LOCK_EX);
        } else {
            error_log($linea);
        }
    }
}
