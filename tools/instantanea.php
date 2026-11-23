<?php
/**
 * Instantánea para auditar la interfaz fuera del servidor (accesibilidad y capturas responsive).
 * Para un rol: guarda el HTML de las pantallas nuevas (renderizadas con sesión simulada, sin
 * contraseña) y las respuestas reales de la API que esas pantallas piden (fixtures JSON).
 * Solo CLI.   php tools/instantanea.php [rol] [directorio]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$rol = $argv[1] ?? 'admin';
$dir = $argv[2] ?? dirname(__DIR__) . '/storage/cache/instantanea/' . $rol;
@mkdir($dir, 0700, true);

define('EL_SIN_SESION', true);
$_SERVER['SCRIPT_NAME'] = '/espacioluzdemo/index.php';
require dirname(__DIR__) . '/config.php';
require APP_ROOT . '/vendor/autoload.php';

use EspacioLuz\App;
use EspacioLuz\Http\Request;
use EspacioLuz\Kernel;
use EspacioLuz\Security\Jwt;

$c = App::container();
$pdo = $c->get(EspacioLuz\Infrastructure\Database::class)->pdo;
$u = $pdo->query("SELECT id, nombre, email, rol FROM usuarios WHERE rol = " . $pdo->quote($rol) . " AND activo = 1 LIMIT 1")->fetch();
$token = (new Jwt((string) env('JWT_SECRET'), 600))->encode(['sub' => (int) $u['id'], 'rol' => $u['rol']]);
$kernel = new Kernel($c);
$hoy = date('Y-m-d');
$q = fn (int $dias) => date('Y-m-d', strtotime("$dias days"));

$llamadas = [
    ['GET', '/auth/me', []],
    ['GET', '/profesionales', []], ['GET', '/boxes', []], ['GET', '/opciones', []], ['GET', '/reportes', []],
    ['GET', '/atenciones', ['sort' => '-fecha', 'page' => 1, 'per_page' => 20]],
    ['GET', '/pacientes', ['activo' => 1, 'per_page' => 50, 'sort' => 'nombre']],
    ['GET', '/dashboard/indicadores', ['desde' => $q(-182), 'hasta' => $hoy]],
    ['GET', '/dashboard/series', ['desde' => $q(-182), 'hasta' => $hoy]],
    ['GET', '/reportes/atenciones', ['desde' => $q(-30), 'hasta' => $hoy, 'page' => 1, 'per_page' => 25]],
    ['GET', '/reportes/gerencial', ['desde' => $q(-364), 'hasta' => $hoy, 'page' => 1, 'per_page' => 25]],
    ['GET', '/reportes/documentos', ['desde' => $q(-90), 'hasta' => $hoy, 'page' => 1, 'per_page' => 25]],
    ['GET', '/auditoria', ['page' => 1, 'per_page' => 25]],
    ['GET', '/auditoria/exportaciones', []],
];
$fixtures = [];
foreach ($llamadas as [$m, $ruta, $query]) {
    $r = $kernel->handle(Request::create($m, $ruta, [], $query, $token));
    $fixtures[$ruta] = ['status' => $r->status, 'body' => $r->data()];
}
// Detalle de la primera atención de la lista (diálogo «Gestionar»).
$primera = $fixtures['/atenciones']['body']['data'][0]['id'] ?? null;
if ($primera) {
    $r = $kernel->handle(Request::create('GET', "/atenciones/$primera", [], [], $token));
    $fixtures['/atenciones/' . $primera] = ['status' => $r->status, 'body' => $r->data()];
}
$me = $fixtures['/auth/me']['body']['usuario'] ?? [];
$fixtures['/auth/sesion'] = ['status' => 200, 'body' => ['token' => 'instantanea', 'token_type' => 'Bearer', 'expires_in' => 3600, 'usuario' => $me]];
file_put_contents("$dir/fixtures.json", json_encode($fixtures, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "fixtures: " . count($fixtures) . "\n";
