<?php
/**
 * Imprime (JSON) los indicadores y series del dashboard calculados con los mismos servicios de
 * la API, para documentarlos e interpretarlos en el informe. Solo CLI.
 *   php tools/indicadores.php [desde] [hasta]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('EL_SIN_SESION', true);
$_SERVER['SCRIPT_NAME'] = '/index.php';
require dirname(__DIR__) . '/config.php';
require APP_ROOT . '/vendor/autoload.php';

use EspacioLuz\App;
use EspacioLuz\Service\DashboardService;

$c = App::container();
$svc = $c->get(DashboardService::class);
$q = array_filter(['desde' => $argv[1] ?? null, 'hasta' => $argv[2] ?? null]);
$f = $svc->filtros($q);
echo json_encode([
    'filtros' => $f,
    'anterior' => DashboardService::anterior($f),
    'indicadores' => $svc->indicadores($f),
    'series' => $svc->series($f),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
