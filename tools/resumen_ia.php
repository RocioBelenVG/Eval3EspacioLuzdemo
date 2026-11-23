<?php
/**
 * Genera un resumen con IA real (POST /api/v1/ia/resumen) como Gerencia y lo imprime en JSON,
 * para dejar evidencia de la integración. Solo CLI.
 *   php tools/resumen_ia.php [desde] [hasta] [enfoque]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('EL_SIN_SESION', true);
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REMOTE_ADDR'] = 'cli';
require dirname(__DIR__) . '/config.php';
require APP_ROOT . '/vendor/autoload.php';

use EspacioLuz\App;
use EspacioLuz\Http\Request;
use EspacioLuz\Kernel;
use EspacioLuz\Security\Jwt;

$c = App::container();
$pdo = $c->get(EspacioLuz\Infrastructure\Database::class)->pdo;
$u = $pdo->query("SELECT id, rol FROM usuarios WHERE rol = 'gerencia' AND activo = 1 LIMIT 1")->fetch();
$token = (new Jwt((string) env('JWT_SECRET'), 600))->encode(['sub' => (int) $u['id'], 'rol' => $u['rol']]);
$body = ['desde' => $argv[1] ?? date('Y-m-d', strtotime('-6 months +1 day')), 'hasta' => $argv[2] ?? date('Y-m-d'), 'enfoque' => $argv[3] ?? 'general'];
$r = (new Kernel($c))->handle(Request::create('POST', '/ia/resumen', $body, [], $token));
echo json_encode(['solicitud' => $body, 'http' => $r->status, 'respuesta' => $r->data()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
