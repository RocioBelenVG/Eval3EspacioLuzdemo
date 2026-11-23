<?php
declare(strict_types=1);
/**
 * Conexión mysqli a la base de datos de EspacioLuz para el motor DTE.
 * El motor replicado de Trivoxa-DTE usa mysqli; EspacioLuz usa PDO para el
 * resto del sitio. Aquí se abre una conexión mysqli propia reutilizando las
 * credenciales de la app (definidas en EspacioLuz/config.php).
 *
 * Define la variable $conex (mysqli) para el resto del motor.
 */

if (!defined('DB_HOST')) {
    // EspacioLuz/config.php define DB_HOST, DB_NAME, DB_USER, DB_PASS.
    require_once dirname(__DIR__) . '/config.php';
}

$conex = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if (!$conex) {
    error_log('[dte] conexión BD falló: ' . mysqli_connect_error());
    throw new RuntimeException('No fue posible conectar con la base de datos (módulo DTE).');
}

mysqli_set_charset($conex, 'utf8mb4');
// Las llamadas HTTP al SII/SimpleAPI son largas; se evita "MySQL server has gone away".
@mysqli_query($conex, "SET SESSION wait_timeout=600");
// Auditoría: mismo usuario que la sesión del panel (los disparadores lo registran).
$__uid = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
$__unom = isset($_SESSION['admin_nombre']) ? (string) $_SESSION['admin_nombre'] : null;
$__ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
if ($__st = $conex->prepare("SET @app_usuario_id = ?, @app_usuario_nombre = ?, @app_origen = 'panel', @app_ip = ?")) {
    $__st->bind_param('iss', $__uid, $__unom, $__ip); $__st->execute(); $__st->close();
}
unset($__uid, $__unom, $__ip, $__st);
