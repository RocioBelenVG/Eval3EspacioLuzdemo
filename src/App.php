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
use EspacioLuz\Dte\EmisorDte;
use EspacioLuz\Dte\MotorDteLegado;
use EspacioLuz\Export\CsvExportador;
use EspacioLuz\Export\PdfExportador;
use EspacioLuz\Export\XlsxExportador;
use EspacioLuz\Ia\GroqClient;
use EspacioLuz\Ia\IaClient;
use EspacioLuz\Infrastructure\Database;
use EspacioLuz\Repository\AtencionRepository;
use EspacioLuz\Repository\AuditoriaRepository;
use EspacioLuz\Repository\CatalogoRepository;
use EspacioLuz\Repository\DashboardRepository;
use EspacioLuz\Repository\DocumentoRepository;
use EspacioLuz\Repository\PacienteRepository;
use EspacioLuz\Repository\UsuarioRepository;
use EspacioLuz\Security\Jwt;
use EspacioLuz\Security\RateLimiter;
use EspacioLuz\Service\AtencionService;
use EspacioLuz\Service\AuthService;
use EspacioLuz\Service\DashboardService;
use EspacioLuz\Service\DocumentoService;
use EspacioLuz\Service\IaService;
use EspacioLuz\Service\PacienteService;
use EspacioLuz\Service\ReporteService;

/**
 * Raíz de composición: registra cada pieza en el contenedor e inyecta sus dependencias
 * por constructor. Es el único lugar que conoce las clases concretas (inversión de
 * dependencias: los servicios dependen de interfaces como EmisorDte e IaClient).
 */
final class App
{
    /** @param array{db?: Database, emisor?: EmisorDte, ia?: IaClient, sesion?: \Closure, hoy?: \Closure} $sobrescribir dobles para pruebas */
    public static function container(array $sobrescribir = []): Container
    {
        $c = new Container();
        $hoy = $sobrescribir['hoy'] ?? null;

        $c->set(Database::class, fn () => $sobrescribir['db'] ?? Database::connect(
            (string) env('DB_HOST', 'localhost'), (string) env('DB_PORT', '3306'), (string) env('DB_NAME', ''), (string) env('DB_USER', ''), (string) env('DB_PASS', '')));
        $c->set(Jwt::class, fn () => new Jwt((string) env('JWT_SECRET', ''), (int) env('JWT_TTL', '3600')));
        $c->set(RateLimiter::class, fn (Container $c) => new RateLimiter($c->get(Database::class)->pdo));

        foreach ([UsuarioRepository::class, AtencionRepository::class, PacienteRepository::class, CatalogoRepository::class,
                  DocumentoRepository::class, DashboardRepository::class, AuditoriaRepository::class] as $repo) {
            $c->set($repo, fn (Container $c) => new $repo($c->get(Database::class)));
        }

        $c->set(EmisorDte::class, fn () => $sobrescribir['emisor'] ?? new MotorDteLegado(dirname(__DIR__)));
        $c->set(IaClient::class, fn () => $sobrescribir['ia'] ?? new GroqClient((string) env('GROQ_API_KEY', ''), (int) env('AI_TIMEOUT', '20'),
            (string) env('GROQ_MODELO', 'llama-3.3-70b-versatile')));

        $c->set(AuthService::class, fn (Container $c) => new AuthService($c->get(UsuarioRepository::class), $c->get(Jwt::class), $c->get(RateLimiter::class)));
        $c->set(AtencionService::class, fn (Container $c) => new AtencionService($c->get(Database::class), $c->get(AtencionRepository::class),
            $c->get(PacienteRepository::class), $c->get(CatalogoRepository::class), $c->get(DocumentoRepository::class), $c->get(EmisorDte::class), $hoy));
        $c->set(PacienteService::class, fn (Container $c) => new PacienteService($c->get(PacienteRepository::class), $c->get(CatalogoRepository::class)));
        $c->set(DocumentoService::class, fn (Container $c) => new DocumentoService($c->get(Database::class), $c->get(DocumentoRepository::class)));
        $c->set(DashboardService::class, fn (Container $c) => new DashboardService($c->get(DashboardRepository::class), $c->get(CatalogoRepository::class), $hoy));
        $c->set(ReporteService::class, fn (Container $c) => new ReporteService(
            $c->get(AtencionService::class), $c->get(AtencionRepository::class), $c->get(DocumentoService::class), $c->get(DocumentoRepository::class),
            $c->get(DashboardService::class), $c->get(DashboardRepository::class), $c->get(AuditoriaRepository::class),
            ['csv' => new CsvExportador(), 'xlsx' => new XlsxExportador(), 'pdf' => new PdfExportador()]));
        $c->set(IaService::class, fn (Container $c) => new IaService($c->get(IaClient::class), $c->get(DashboardService::class),
            $c->get(RateLimiter::class), $c->get(AuditoriaRepository::class)));

        $c->set(HealthController::class, fn (Container $c) => new HealthController($c->get(Database::class)));
        $c->set(AuthController::class, fn (Container $c) => new AuthController($c->get(AuthService::class), $sobrescribir['sesion'] ?? null));
        $c->set(AtencionController::class, fn (Container $c) => new AtencionController($c->get(AtencionService::class)));
        $c->set(PacienteController::class, fn (Container $c) => new PacienteController($c->get(PacienteService::class)));
        $c->set(CatalogoController::class, fn (Container $c) => new CatalogoController($c->get(CatalogoRepository::class)));
        $c->set(DocumentoController::class, fn (Container $c) => new DocumentoController($c->get(DocumentoService::class)));
        $c->set(DashboardController::class, fn (Container $c) => new DashboardController($c->get(DashboardService::class)));
        $c->set(ReporteController::class, fn (Container $c) => new ReporteController($c->get(ReporteService::class)));
        $c->set(IaController::class, fn (Container $c) => new IaController($c->get(IaService::class)));
        $c->set(AuditoriaController::class, fn (Container $c) => new AuditoriaController($c->get(AuditoriaRepository::class)));

        return $c;
    }
}
