<?php
/**
 * Controlador frontal de la API REST /api/v1 (contrato: docs/openapi.yaml).
 * Lo incluye index.php para toda ruta que empiece con /api/v1. La API es sin estado
 * (JWT): no abre la sesión PHP, salvo en /auth/sesion, que intercambia la sesión del
 * panel clásico por un token.
 */
declare(strict_types=1);

use EspacioLuz\App;
use EspacioLuz\Http\Request;
use EspacioLuz\Kernel;

$rutaPedida = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
if (!preg_match('#/api/v1/auth/sesion/?$#', $rutaPedida) && !defined('EL_SIN_SESION')) {
    define('EL_SIN_SESION', true);
}
require_once dirname(__DIR__, 2) . '/config.php';
require_once APP_ROOT . '/vendor/autoload.php';
ini_set('display_errors', '0');

$sesionPanel = static function (): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['admin_id'])) {
        return null;
    }
    return ['id' => (int) $_SESSION['admin_id'], 'csrf' => (string) ($_SESSION['csrf'] ?? '')];
};

$kernel = new Kernel(
    App::container(['sesion' => $sesionPanel]),
    APP_ROOT . '/storage/logs/api.log',
    array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ORIGENES', ''))))),
    APP_ROOT . '/docs/openapi.yaml',
);
$kernel->handle(Request::fromGlobals(BASE_URL . '/api/v1'))->send();
