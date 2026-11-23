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

$conex = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conex) {
    error_log('[dte] conexión BD falló: ' . mysqli_connect_error());
    throw new RuntimeException('No fue posible conectar con la base de datos (módulo DTE).');
}

mysqli_set_charset($conex, 'utf8mb4');
// Las llamadas HTTP al SII/SimpleAPI son largas; se evita "MySQL server has gone away".
@mysqli_query($conex, "SET SESSION wait_timeout=600");
