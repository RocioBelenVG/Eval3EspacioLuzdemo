<?php
/**
 * Autenticación de administradores. Las cuentas viven en la tabla `usuarios`
 * de MySQL (email único + contraseña hasheada con bcrypt + rol). Reemplaza al
 * antiguo arreglo ADMIN_ACCOUNTS de config.php y a NextAuth del sistema original.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Intenta iniciar sesión con email + contraseña contra la base de datos.
 * Devuelve true si las credenciales son válidas y la cuenta está activa.
 */
function attemptLogin(string $email, string $password): bool
{
    $email = strtolower(trim($email));
    if ($email === '' || $password === '') {
        return false;
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, nombre, email, password_hash, rol
               FROM usuarios
              WHERE email = ? AND activo = 1
              LIMIT 1'
        );
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();
    } catch (Throwable $e) {
        return false; // Sin base de datos no se puede autenticar.
    }

    if (!$usuario) {
        return false;
    }

    // PHP genera y verifica bcrypt con prefijo $2y$. Los hashes heredados del
    // sistema original usan $2b$ (mismo algoritmo). Normalizamos para máxima
    // compatibilidad entre versiones de PHP.
    $hash = (string) $usuario['password_hash'];
    $normalized = preg_replace('/^\$2b\$/', '$2y$', $hash);
    if (!password_verify($password, $hash) && !password_verify($password, $normalized)) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['admin_id']     = (int) $usuario['id'];
    $_SESSION['admin_email']  = $usuario['email'];
    $_SESSION['admin_nombre'] = $usuario['nombre'];
    $_SESSION['admin_rol']    = $usuario['rol'];

    try {
        $up = db()->prepare('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?');
        $up->execute([(int) $usuario['id']]);
    } catch (Throwable $e) {
        // El registro del último acceso no es crítico.
    }

    return true;
}

/** Cierra la sesión del administrador. */
function logout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

/** ¿Hay un administrador con sesión iniciada? */
function isAuthenticated(): bool
{
    return !empty($_SESSION['admin_email']);
}

/** Email del administrador actual o null. */
function currentAdminEmail(): ?string
{
    return $_SESSION['admin_email'] ?? null;
}

/** Nombre del administrador actual o null. */
function currentAdminName(): ?string
{
    return $_SESSION['admin_nombre'] ?? null;
}

/** Rol del administrador actual ('admin' | 'editor') o null. */
function currentAdminRol(): ?string
{
    return $_SESSION['admin_rol'] ?? null;
}

/** ¿El usuario actual tiene rol de administrador? */
function isAdmin(): bool
{
    return ($_SESSION['admin_rol'] ?? '') === 'admin';
}

/** Corta la ejecución con 401 si no hay sesión (para endpoints protegidos). */
function requireAuthJson(): void
{
    if (!isAuthenticated()) {
        json_response(['error' => 'No autorizado'], 401);
    }
}

/** Corta con 403 si el usuario no es admin (para endpoints protegidos). */
function requireAdminJson(): void
{
    requireAuthJson();
    if (!isAdmin()) {
        json_response(['error' => 'Necesitas permisos de administrador'], 403);
    }
}

/** Redirige al login si no hay sesión (para páginas del panel). */
function requireAuthPage(): void
{
    if (!isAuthenticated()) {
        header('Location: ' . base_path('/admin/login'));
        exit;
    }
}

/** Redirige al panel si el usuario no es admin (para páginas solo-admin). */
function requireAdminPage(): void
{
    requireAuthPage();
    if (!isAdmin()) {
        header('Location: ' . base_path('/admin'));
        exit;
    }
}

/* ===================================================================== *
 *  Roles y permisos
 * ===================================================================== */

/** Roles disponibles: clave => [etiqueta, descripción]. */
const ROLES = [
    'admin'    => ['Administrador', 'Acceso total: usuarios, boletas, calendarios y configuración.'],
    'editor'   => ['Editor', 'Edita los contenidos del sitio y usa el panel (sin módulos de administración).'],
    'pacientes' => ['Pacientes', 'Solo el módulo Pacientes: fichas, evoluciones y certificados de atención, y sus propias atenciones.'],
    'gerencia' => ['Gerencia', 'Solo lectura: dashboard, indicadores, reportes (con exportación) y resumen con IA.'],
];

/** Etiqueta legible de un rol. */
function rolEtiqueta(?string $rol): string
{
    return ROLES[(string) $rol][0] ?? ucfirst((string) $rol);
}

/** Normaliza un rol recibido (si no existe, cae en el más restrictivo de los antiguos: editor). */
function rolValido(?string $rol): string
{
    return array_key_exists((string) $rol, ROLES) ? (string) $rol : 'editor';
}

/** ¿El usuario actual tiene el rol «Pacientes»? */
function esRolPacientes(): bool
{
    return isAuthenticated() && currentAdminRol() === 'pacientes';
}

/** ¿El usuario actual tiene el rol «Gerencia» (solo lectura de análisis)? */
function esRolGerencia(): bool
{
    return isAuthenticated() && currentAdminRol() === 'gerencia';
}

/** ¿Puede editar los contenidos del sitio público? (los roles Pacientes y Gerencia no). */
function puedeEditarSitio(): bool
{
    return isAuthenticated() && !esRolPacientes() && !esRolGerencia();
}

/** Rutas del panel que puede usar Gerencia (módulos de análisis); el resto responde «sin permisos». */
function rolGerenciaPermite(string $path): bool
{
    foreach (['#^/admin/bi$#', '#^/admin/reportes$#', '#^/admin/atenciones$#', '#^/admin/sin-permiso$#', '#^/admin/login$#'] as $re) {
        if (preg_match($re, $path)) {
            return true;
        }
    }
    $interna = str_starts_with($path, '/admin') || str_starts_with($path, '/api/panel') || str_starts_with($path, '/api/content') || preg_match('#^/api/upload#', $path);
    return !$interna;
}

/**
 * Relee rol y estado del usuario desde la base de datos en cada visita, para que
 * un cambio de rol o una desactivación se apliquen de inmediato (no al volver a
 * iniciar sesión). Si la cuenta ya no existe o está inactiva, cierra la sesión.
 */
function sesionRevalidar(): void
{
    $id = (int) ($_SESSION['admin_id'] ?? 0);
    if ($id <= 0) {
        return;
    }
    try {
        $st = db()->prepare('SELECT rol, activo FROM usuarios WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $u = $st->fetch();
    } catch (Throwable $e) {
        return; // sin BD no se toca la sesión
    }
    if (!$u || (int) $u['activo'] !== 1) {
        logout();
        return;
    }
    $_SESSION['admin_rol'] = (string) $u['rol'];
}

/**
 * Rutas que puede usar el rol «Pacientes» (clave interna 'pacientes'). Todo lo demás del panel (/admin/…),
 * de la API del panel (/api/panel/…) y de la edición del sitio (/api/content…)
 * queda bloqueado. El sitio público y sus APIs públicas siguen disponibles.
 */
function rolPacientesPermite(string $path): bool
{
    $permitidas = [
        '#^/admin/pacientes$#',
        '#^/admin/pacientes/\d+$#',
        '#^/admin/pacientes/\d+/certificado/nuevo$#',
        '#^/admin/pacientes/\d+/fichas/\d+/pdf$#',
        '#^/admin/pacientes/\d+/informes/\d+$#',
        '#^/admin/certificados/\d+(/editar|/pdf)?$#',
        '#^/admin/firmas/prof-\d+-[0-9a-f]{12}\.png$#',
        '#^/api/panel/pacientes(\.php)?$#',
        '#^/api/panel/certificados(\.php)?$#',
        '#^/admin/atenciones$#',
        '#^/admin/sin-permiso$#',
    ];
    foreach ($permitidas as $re) {
        if (preg_match($re, $path)) {
            return true;
        }
    }
    // Fuera del panel y de las APIs internas/de edición: permitido (sitio público).
    $interna = str_starts_with($path, '/admin') || str_starts_with($path, '/api/panel') || str_starts_with($path, '/api/content')
        || preg_match('#^/api/upload#', $path);
    return !$interna || $path === '/admin/login';
}

/**
 * Aplica los permisos del rol a la ruta pedida (se llama desde index.php antes
 * de enrutar). Páginas no permitidas -> se redirige a Pacientes; APIs -> 403.
 */
function aplicarPermisosDeRol(string $path): void
{
    if (!isAuthenticated()) {
        return;
    }
    sesionRevalidar();
    if (esRolGerencia() && ($path === '/admin' || $path === '/admin/panel')) {
        header('Location: ' . base_path('/admin/bi'));   // su «inicio» es el dashboard
        exit;
    }
    if (esRolGerencia() && !rolGerenciaPermite($path)) {
        if (str_starts_with($path, '/api')) {
            json_response(['error' => 'Tu rol (Gerencia) solo tiene acceso de lectura a los módulos de análisis.'], 403);
        }
        header('Location: ' . base_path('/admin/sin-permiso'));
        exit;
    }
    if (!esRolPacientes() || rolPacientesPermite($path)) {
        return;
    }
    if (str_starts_with($path, '/api')) {
        json_response(['error' => 'Tu rol solo tiene acceso al módulo Pacientes.'], 403);
    }
    header('Location: ' . base_path('/admin/pacientes'));
    exit;
}
