<?php
/**
 * Recorre las pantallas del panel clásico con una sesión simulada (sin contraseña) y
 * reporta el código HTTP, el tamaño y cualquier error PHP. Solo por CLI.
 *
 *   sh tools/recorrer_panel.sh [rol]        rol: admin (defecto), editor, pacientes, gerencia
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$rol = $argv[1] ?? 'admin';
$raiz = dirname(__DIR__);
$rutas = $argv[2] ?? null;

require $raiz . '/lib/env.php';
$pdo = new PDO('mysql:host=' . env('DB_HOST', 'localhost') . ';dbname=' . env('DB_NAME') . ';charset=utf8mb4', env('DB_USER'), env('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$u = $pdo->query("SELECT id, nombre, email, rol FROM usuarios WHERE rol = " . $pdo->quote($rol) . " AND activo = 1 LIMIT 1")->fetch();
if (!$u) {
    fwrite(STDERR, "No hay usuario activo con rol $rol\n");
    exit(1);
}
$pac = (int) $pdo->query('SELECT id FROM pacientes ORDER BY id LIMIT 1')->fetchColumn();
$cli = (int) $pdo->query('SELECT id FROM clientes ORDER BY id LIMIT 1')->fetchColumn();
$bol = (int) $pdo->query("SELECT id FROM boletas WHERE tipo = 'boleta' ORDER BY id DESC LIMIT 1")->fetchColumn();
$fac = (int) $pdo->query("SELECT id FROM boletas WHERE tipo = 'factura' ORDER BY id DESC LIMIT 1")->fetchColumn();
$nc = (int) $pdo->query('SELECT id FROM notas_credito ORDER BY id LIMIT 1')->fetchColumn();
$ficha = $pdo->query('SELECT id, paciente_id FROM fichas ORDER BY id LIMIT 1')->fetch();
$emp = (int) $pdo->query('SELECT id FROM empresas ORDER BY id LIMIT 1')->fetchColumn();

$lista = $rutas ? explode(',', $rutas) : [
    '/', '/boxes/loica', '/profesionales/fernanda-alarcon-rios', '/agenda', '/privacidad', '/terminos', '/no-existe',
    '/admin', '/admin/calendario-boxes', '/admin/calendario-profesionales', '/admin/horarios', '/admin/documentos',
    "/admin/boletas/$bol", "/admin/boletas/$fac", '/admin/boletas/nueva', "/admin/notas/nc/$nc", '/admin/usuarios', '/admin/apariencia',
    '/admin/profesionales', '/admin/empresas', "/admin/dte/$emp", '/admin/boxes', '/admin/productos', '/admin/clientes', "/admin/clientes/$cli",
    '/admin/facturas/nueva', '/admin/pacientes', "/admin/pacientes/$pac", "/admin/pacientes/{$ficha['paciente_id']}/fichas/{$ficha['id']}/pdf",
    "/admin/pacientes/$pac/certificado/nuevo", "/admin/dte/pdf?boleta=$bol", "/admin/dte/pdf?boleta=$fac", '/admin/calendario/pdf?modo=box&sel=todos',
    '/admin/bi', '/admin/reportes', '/admin/atenciones', '/admin/auditoria', '/admin/sin-permiso', '/api/docs',
];

// shell_exec suele estar deshabilitado en hosting compartido: este script solo imprime
// «ruta<TAB>usuario» y tools/recorrer_panel.sh ejecuta cada ruta en un proceso PHP aparte.
foreach ($lista as $ruta) {
    echo $ruta, "	", json_encode($u), "
";
}
