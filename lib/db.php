<?php
/**
 * Conexión a MySQL (PDO). Única fuente de datos para usuarios/roles y para los
 * contenidos editables del sitio. Las credenciales se definen en config.php.
 *
 * Reintenta la conexión unas pocas veces porque en hosting compartido el
 * servidor MySQL a veces devuelve un error transitorio (SQLSTATE 2002).
 */

declare(strict_types=1);

/** Devuelve la conexión PDO compartida (la crea la primera vez). */
function db(): PDO
{
    static $pdo = null;
    static $failed = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    // Si ya falló en esta misma petición, no reintentamos: lanzamos de inmediato
    // (evita colgar la página sumando timeouts en cada llamada a db()).
    if ($failed !== null) {
        throw $failed;
    }

    // En el servidor embebido (php -S, desarrollo local) la BD normalmente no
    // existe: fallamos rápido. En producción damos margen a errores transitorios.
    $esDev = PHP_SAPI === 'cli-server';
    $maxIntentos = $esDev ? 1 : 3;

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => $esDev ? 1 : 4, // seg. de timeout de conexión
    ];

    $lastError = null;
    for ($intento = 0; $intento < $maxIntentos; $intento++) {
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            db_contexto_auditoria($pdo);
            return $pdo;
        } catch (PDOException $e) {
            $lastError = $e;
            if ($intento < $maxIntentos - 1) {
                usleep(200000); // 0,2 s antes de reintentar
            }
        }
    }

    $failed = $lastError;
    throw $failed;
}

/**
 * Identifica al usuario en la conexión para el registro de auditoría: los
 * disparadores de la base de datos leen @app_usuario_id y @app_usuario_nombre
 * al guardar quién creó o modificó cada registro (panel clásico y API).
 */
function db_contexto_auditoria(PDO $pdo, ?int $usuarioId = null, ?string $nombre = null, string $origen = 'panel'): void
{
    if ($usuarioId === null && session_status() === PHP_SESSION_ACTIVE) {
        $usuarioId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
        $nombre = $_SESSION['admin_nombre'] ?? null;
    }
    try {
        $st = $pdo->prepare('SET @app_usuario_id = ?, @app_usuario_nombre = ?, @app_origen = ?, @app_ip = ?');
        $st->execute([$usuarioId, $nombre, $origen, substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45)]);
    } catch (Throwable $e) {
        // La auditoría nunca bloquea la operación.
    }
}
