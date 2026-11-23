<?php
/**
 * Genera archivos de ejemplo de los 3 reportes en los 3 formatos usando la API real (como Gerencia).
 * Solo CLI.   php tools/exportar_ejemplos.php [directorio]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$dir = $argv[1] ?? dirname(__DIR__) . '/storage/exports/ejemplos';
@mkdir($dir, 0700, true);
define('EL_SIN_SESION', true);
$_SERVER['SCRIPT_NAME'] = '/index.php';
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
$k = new Kernel($c);
$hoy = date('Y-m-d');
$casos = [
    'atenciones' => ['desde' => date('Y-m-d', strtotime('-30 days')), 'hasta' => $hoy, 'uso' => 'sesion'],
    'gerencial' => ['desde' => date('Y-m-d', strtotime('-1 year +1 day')), 'hasta' => $hoy],
    'documentos' => ['desde' => date('Y-m-d', strtotime('-90 days')), 'hasta' => $hoy, 'tipo' => 'factura'],
];
foreach ($casos as $rep => $q) {
    foreach (['xlsx', 'pdf', 'csv'] as $fmt) {
        $r = $k->handle(Request::create('GET', "/reportes/$rep/export", [], $q + ['formato' => $fmt], $token));
        $nombre = "reporte_{$rep}.{$fmt}";
        file_put_contents("$dir/$nombre", $r->body);
        printf("%-28s %d  %8d bytes  %s filas\n", $nombre, $r->status, strlen($r->body), $r->headers['X-Filas'] ?? '-');
    }
}
