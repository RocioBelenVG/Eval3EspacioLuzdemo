<?php
/**
 * Controlador frontal (router). Reemplaza el enrutamiento del App Router de Next.
 * Todas las peticiones que no sean archivos existentes entran por aquí (.htaccess).
 */
declare(strict_types=1);

// Servidor embebido de PHP (php -S): servir archivos estáticos existentes
// directamente. En Apache esto lo resuelve el .htaccess (RewriteCond -f/-d).
if (PHP_SAPI === 'cli-server') {
    $reqPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
    if ($reqPath !== '/' && is_file(__DIR__ . $reqPath)) {
        return false;
    }
}

// API REST /api/v1 (JWT, sin sesión): su propio controlador frontal (api/v1/index.php).
if (preg_match('#/api/v1(/|$)#', (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'))) {
    require __DIR__ . '/api/v1/index.php';
    return;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/data.php';
require_once __DIR__ . '/lib/content.php';
require_once __DIR__ . '/lib/reservas.php';
require_once __DIR__ . '/lib/images.php';

// data.php define variables globales; que estén accesibles vía $GLOBALS.
$GLOBALS['CENTRO'] = $CENTRO;
$GLOBALS['SECCIONES'] = $SECCIONES;
$GLOBALS['ESPECIALIDADES'] = $ESPECIALIDADES;
$GLOBALS['PROFESIONALES'] = $PROFESIONALES;
// Boxes: la tabla `boxes` es la fuente única (módulo Boxes del panel). data.php queda como respaldo.
require_once __DIR__ . '/lib/boxes.php';
$BOXES = boxesCatalogo($BOXES);
$GLOBALS['BOXES'] = $BOXES;
$GLOBALS['ATENCIONES'] = $ATENCIONES;
$GLOBALS['PAGINAS'] = $PAGINAS;

// Ruta solicitada, relativa a la base de la app.
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$uri = rawurldecode($uri);
if (BASE_URL !== '' && str_starts_with($uri, BASE_URL)) {
    $uri = substr($uri, strlen(BASE_URL));
}
$path = '/' . trim($uri, '/');   // normaliza: "/", "/boxes/loica", etc.

// Permisos por rol (p. ej. el rol «Pacientes» solo accede al módulo Pacientes).
// También revalida rol/estado del usuario contra la base de datos en cada visita.
aplicarPermisosDeRol($path);

// --- Enrutamiento ---------------------------------------------------------
if ($path === '/' || $path === '') {
    require APP_ROOT . '/pages/home.php';
    return;
}
if ($path === '/privacidad') {
    require APP_ROOT . '/pages/privacidad.php';
    return;
}
if ($path === '/terminos') {
    require APP_ROOT . '/pages/terminos.php';
    return;
}

if ($path === '/admin/login') {
    require APP_ROOT . '/pages/login.php';
    return;
}

if ($path === '/logout') {
    logout();
    header('Location: ' . base_path('/'));
    return;
}

// --- Panel administrativo ---------------------------------------------------
if ($path === '/admin' || $path === '/admin/panel') {
    require APP_ROOT . '/pages/panel/dashboard.php';
    return;
}
if ($path === '/admin/calendario-boxes') {
    require APP_ROOT . '/pages/panel/calendario-boxes.php';
    return;
}
// Calendario semanal en PDF (boxes o profesionales), para imprimir.
if ($path === '/admin/calendario/pdf') {
    require APP_ROOT . '/api/panel/calendario-pdf.php';
    return;
}
if ($path === '/admin/horarios') {
    require APP_ROOT . '/pages/panel/horarios.php';
    return;
}
if ($path === '/admin/calendario-profesionales') {
    require APP_ROOT . '/pages/panel/calendario-profesionales.php';
    return;
}
if ($path === '/admin/documentos') {
    require APP_ROOT . '/pages/panel/documentos.php';
    return;
}
if (preg_match('#^/admin/notas/(nc|nd)/(\d+)$#', $path, $m)) {
    $notaClase = $m[1];
    $notaId = (int) $m[2];
    require APP_ROOT . '/pages/panel/nota-ver.php';
    return;
}
if ($path === '/admin/boletas') {
    // «Boletas y Facturas» pasó a llamarse «Documentos» (incluye notas de crédito y débito).
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . base_path('/admin/documentos' . ($qs !== '' ? '?' . $qs : '')), true, 302);
    return;
}
if ($path === '/admin/boletas/nueva') {
    require APP_ROOT . '/pages/panel/boleta-nueva.php';
    return;
}
if (preg_match('#^/admin/boletas/(\d+)$#', $path, $m)) {
    $boletaId = (int) $m[1];
    require APP_ROOT . '/pages/panel/boleta-ver.php';
    return;
}
if ($path === '/admin/usuarios') {
    require APP_ROOT . '/pages/panel/usuarios.php';
    return;
}
if ($path === '/admin/apariencia') {
    require APP_ROOT . '/pages/panel/tipografia.php';
    return;
}
if ($path === '/admin/profesionales') {
    require APP_ROOT . '/pages/panel/profesionales.php';
    return;
}
if ($path === '/admin/empresas') {
    require APP_ROOT . '/pages/panel/empresas.php';
    return;
}
if ($path === '/admin/dte') {
    // «Facturación DTE» se fusionó con Empresas (módulo «Empresas y SII»).
    header('Location: ' . base_path('/admin/empresas' . (isset($_GET['probar']) ? '?probar=1' : '')), true, 302);
    return;
}
if ($path === '/admin/dte/pdf') {
    require APP_ROOT . '/api/panel/dte-pdf.php';
    return;
}
if (preg_match('#^/admin/dte/(\d+)$#', $path, $m)) {
    $empresaId = (int) $m[1];
    require APP_ROOT . '/pages/panel/dte-empresa.php';
    return;
}
if ($path === '/admin/boxes') {
    require APP_ROOT . '/pages/panel/boxes.php';
    return;
}
if ($path === '/admin/productos') {
    require APP_ROOT . '/pages/panel/productos.php';
    return;
}
// Módulo Clientes (arriendo de boxes) y sus facturas. Las facturas son SOLO para clientes.
if ($path === '/admin/clientes') {
    require APP_ROOT . '/pages/panel/clientes.php';
    return;
}
if (preg_match('#^/admin/clientes/(\d+)$#', $path, $m)) {
    $clienteId = (int) $m[1];
    require APP_ROOT . '/pages/panel/cliente-ver.php';
    return;
}
if ($path === '/admin/facturas/nueva') {
    require APP_ROOT . '/pages/panel/factura-nueva.php';
    return;
}
// Módulo Pacientes.
if ($path === '/admin/pacientes') {
    require APP_ROOT . '/pages/panel/pacientes.php';
    return;
}
if (preg_match('#^/admin/pacientes/(\d+)$#', $path, $m)) {
    $pacienteId = (int) $m[1];
    require APP_ROOT . '/pages/panel/paciente-ver.php';
    return;
}
// PDF de la ficha clínica (secciones a elección).
if (preg_match('#^/admin/pacientes/(\d+)/fichas/(\d+)/pdf$#', $path, $m)) {
    $pacienteId = (int) $m[1];
    $fichaId = (int) $m[2];
    require APP_ROOT . '/api/panel/ficha-pdf.php';
    return;
}
// Informes clínicos adjuntos (archivo privado: descarga o vista con ?ver=1).
if (preg_match('#^/admin/pacientes/(\d+)/informes/(\d+)$#', $path, $m)) {
    $pacienteId = (int) $m[1];
    $informeId = (int) $m[2];
    require APP_ROOT . '/api/panel/informe-archivo.php';
    return;
}
// Fichas clínicas / certificados de atención.
if (preg_match('#^/admin/pacientes/(\d+)/certificado/nuevo$#', $path, $m)) {
    $pacienteId = (int) $m[1];
    require APP_ROOT . '/pages/panel/certificado-form.php';
    return;
}
if (preg_match('#^/admin/certificados/(\d+)$#', $path, $m)) {
    $certId = (int) $m[1];
    require APP_ROOT . '/pages/panel/certificado-ver.php';
    return;
}
if (preg_match('#^/admin/certificados/(\d+)/editar$#', $path, $m)) {
    $certId = (int) $m[1];
    require APP_ROOT . '/pages/panel/certificado-form.php';
    return;
}
if (preg_match('#^/admin/certificados/(\d+)/pdf$#', $path, $m)) {
    $certId = (int) $m[1];
    require APP_ROOT . '/api/panel/certificado-pdf.php';
    return;
}
// Firma y timbre de un profesional (imagen privada, requiere sesión).
if (preg_match('#^/admin/firmas/(prof-\d+-[0-9a-f]{12}\.png)$#', $path, $m)) {
    $firmaArchivo = $m[1];
    require APP_ROOT . '/api/panel/firma-img.php';
    return;
}

// --- API del panel ----------------------------------------------------------
if ($path === '/api/panel/reservas') {
    require APP_ROOT . '/api/panel/reservas.php';
    return;
}
if ($path === '/api/panel/horarios') {
    require APP_ROOT . '/api/panel/horarios.php';
    return;
}
if ($path === '/api/panel/feriados') {
    require APP_ROOT . '/api/panel/feriados.php';
    return;
}
if ($path === '/api/panel/boletas') {
    require APP_ROOT . '/api/panel/boletas.php';
    return;
}
if ($path === '/api/panel/usuarios') {
    require APP_ROOT . '/api/panel/usuarios.php';
    return;
}
if ($path === '/api/panel/profesionales') {
    require APP_ROOT . '/api/panel/profesionales.php';
    return;
}
if ($path === '/api/panel/empresas') {
    require APP_ROOT . '/api/panel/empresas.php';
    return;
}
if ($path === '/api/panel/dte') {
    require APP_ROOT . '/api/panel/dte.php';
    return;
}
if ($path === '/api/panel/boxes') {
    require APP_ROOT . '/api/panel/boxes.php';
    return;
}
if ($path === '/api/panel/productos') {
    require APP_ROOT . '/api/panel/productos.php';
    return;
}
if ($path === '/api/panel/certificados') {
    require APP_ROOT . '/api/panel/certificados.php';
    return;
}
if ($path === '/api/panel/pacientes') {
    require APP_ROOT . '/api/panel/pacientes.php';
    return;
}
if ($path === '/api/panel/clientes') {
    require APP_ROOT . '/api/panel/clientes.php';
    return;
}

if ($path === '/agenda') {
    // Sin profesionales con reserva en línea, la agenda se coordina por WhatsApp
    // (enlaces antiguos o guardados no quedan en una página vacía).
    if (agenda_cta_href() !== '/agenda') {
        header('Location: ' . agenda_cta_href(), true, 302);
        exit;
    }
    require APP_ROOT . '/pages/agenda.php';
    return;
}
if ($path === '/api/agenda') {
    require APP_ROOT . '/api/agenda.php';
    return;
}
if ($path === '/api/reservas') {
    require APP_ROOT . '/api/reservas.php';
    return;
}
if ($path === '/api/content') {
    require APP_ROOT . '/api/content.php';
    return;
}
if ($path === '/api/content/upload-image') {
    require APP_ROOT . '/api/upload-image.php';
    return;
}
if ($path === '/api/content/upload-gallery-image') {
    require APP_ROOT . '/api/upload-gallery-image.php';
    return;
}
if ($path === '/api/content/upload-professional-photo') {
    require APP_ROOT . '/api/upload-professional-photo.php';
    return;
}
if ($path === '/api/content/upload-video') {
    require APP_ROOT . '/api/upload-video.php';
    return;
}

if (preg_match('#^/boxes/([A-Za-z0-9\-]+)$#', $path, $m)) {
    $slug = $m[1];
    require APP_ROOT . '/pages/box.php';
    return;
}

if (preg_match('#^/profesionales/([a-z0-9\-]+)$#', $path, $m)) {
    $slug = $m[1];
    require APP_ROOT . '/pages/profesional.php';
    return;
}

// --- 404 ------------------------------------------------------------------
require APP_ROOT . '/pages/404.php';
