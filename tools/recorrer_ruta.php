<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Ejecuta UNA ruta del sitio con sesión simulada (lo invoca tools/recorrer_panel.php).
[, $raiz, $ruta, $usuario] = $argv;
$u = json_decode($usuario, true);
$q = parse_url($ruta, PHP_URL_QUERY);
parse_str((string) $q, $_GET);
$_SERVER['REQUEST_URI'] = '/espacioluzdemo' . $ruta;
$_SERVER['SCRIPT_NAME'] = '/espacioluzdemo/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'trivoxa.cl';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
session_id('recorrido' . bin2hex(random_bytes(6)));
ob_start();
register_shutdown_function(function () {
    $cuerpo = ob_get_clean();
    $codigo = http_response_code() ?: 200;
    $loc = '';
    foreach (headers_list() as $h) {
        if (stripos($h, 'Location:') === 0) {
            $loc = ' → ' . trim(substr($h, 9));
        }
    }
    echo $codigo . $loc . "\n" . $cuerpo;
});
// La sesión se abre en config.php; se rellena antes de enrutar.
define('EL_SESION_PRUEBA', $u);
chdir($raiz);
require $raiz . '/config.php';
$_SESSION['admin_id'] = (int) $u['id'];
$_SESSION['admin_email'] = $u['email'];
$_SESSION['admin_nombre'] = $u['nombre'];
$_SESSION['admin_rol'] = $u['rol'];
require $raiz . '/index.php';
