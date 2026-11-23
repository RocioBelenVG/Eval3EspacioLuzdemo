<?php
/**
 * Configuración global de Espacio Luz (versión PHP).
 * Equivale a la unión de next.config, lib/base-path.ts y lib/auth-admins.ts.
 */

declare(strict_types=1);

// Variables de entorno (.env no versionado; ver .env.example).
require_once __DIR__ . '/lib/env.php';

// EspacioLuz Demo: copia académica (Evaluación 3). Los documentos tributarios son
// de demostración y nunca se envían al SII (ver dte/lib/dte_demo.php).
define('APP_DEMO', true);
define('APP_NOMBRE', 'Espacio Luz Demo');

// ---------------------------------------------------------------------------
// Rutas del sistema de archivos
// ---------------------------------------------------------------------------
define('APP_ROOT', __DIR__);
define('CONTENT_DIR', APP_ROOT . '/content');
define('ASSETS_DIR', APP_ROOT . '/assets');
define('IMAGES_DIR', ASSETS_DIR . '/images');
define('OVERRIDES_PATH', CONTENT_DIR . '/overrides.json');
define('RESERVAS_PATH', CONTENT_DIR . '/reservas.json');

// ---------------------------------------------------------------------------
// URL base (prefijo donde vive la app). Se autodetecta para funcionar tanto
// en la raíz del dominio como en un subdirectorio (p. ej. /espacioluz).
// Equivale a lib/base-path.ts (BASE_PATH).
// ---------------------------------------------------------------------------
// Puedes fijar la base manualmente con la variable de entorno ESPACIOLUZ_BASE
// (p. ej. "/espacioluz"). Si no, se autodetecta.
$envBase = getenv('APP_BASE');
if ($envBase !== false) {
    define('BASE_URL', rtrim($envBase, '/'));
} elseif (PHP_SAPI === 'cli-server') {
    // Servidor embebido (php -S): siempre en la raíz.
    define('BASE_URL', '');
} else {
    // Apache: con el .htaccess, SCRIPT_NAME termina en ".../index.php".
    // Quitamos ese sufijo para obtener el prefijo real (o "" en la raíz).
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $base = preg_replace('#/index\.php$#', '', $scriptName);
    $base = rtrim($base, '/');
    define('BASE_URL', ($base === '' || $base === '/') ? '' : $base);
}

/** Antepone la URL base a una ruta absoluta del sitio. */
function base_path(string $src): string
{
    if ($src === '' || preg_match('#^https?://#i', $src)) {
        return $src;
    }
    return BASE_URL . ($src[0] === '/' ? $src : '/' . $src);
}

/** URL pública de un asset (imágenes, css, js, map-style). */
function asset(string $src): string
{
    return base_path('/assets/' . ltrim($src, '/'));
}

// ---------------------------------------------------------------------------
// Base de datos MySQL (usuarios/roles y contenidos editables).
// Las cuentas de administración ya NO viven en este archivo: se guardan en la
// tabla `usuarios` con contraseñas hasheadas (bcrypt). Ver lib/auth.php.
// Los valores se pueden sobrescribir con variables de entorno sin editar aquí.
// ---------------------------------------------------------------------------
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_PORT', (int) env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', ''));
define('DB_USER', env('DB_USER', ''));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------------
// Sesión (reemplaza a NextAuth con cookies de sesión de PHP).
// ---------------------------------------------------------------------------
// La API REST (/api/v1) es sin estado (JWT): no abre sesión salvo que lo pida.
if (session_status() === PHP_SESSION_NONE && !defined('EL_SIN_SESION') && PHP_SAPI !== 'cli') {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_name('espacioluzdemo_sess');
    session_start();
}

// Zona horaria de Chile continental.
date_default_timezone_set('America/Santiago');
