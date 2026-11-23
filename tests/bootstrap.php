<?php
declare(strict_types=1);

// Las pruebas no abren sesión PHP. La configuración lee el .env: las pruebas de integración
// usan la base configurada, pero cada caso corre dentro de una transacción que se revierte.
if (!defined('EL_SIN_SESION')) {
    define('EL_SIN_SESION', true);
}
$_SERVER['SCRIPT_NAME'] ??= '/index.php';
require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/vendor/autoload.php';
