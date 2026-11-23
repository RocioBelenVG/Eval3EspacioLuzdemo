<?php
declare(strict_types=1);
/**
 * EspacioLuz · Configuración del módulo DTE (facturación electrónica SII).
 * Los SECRETOS (app_key de cifrado + API key de Trivoxa API DTE) viven FUERA del
 * webroot en espacioluz_dte_priv/secret.php. Las credenciales de cada empresa
 * (certificado .pfx y CAF) NO salen de aquí: se resuelven por empresa desde la
 * base de datos (lib/dte_cred.php) y el almacén aislado por empresa.
 */

$PRIV = getenv('ESPACIOLUZ_DTE_PRIV') ?: '/home/u195628024/espacioluz_dte_priv';
$secret = is_file($PRIV . '/secret.php') ? (require $PRIV . '/secret.php') : [];
if (!is_array($secret)) { $secret = []; }

return [
    // --- Pasarela: Trivoxa API DTE (la misma API con la que Trivoxa-DTE emite y
    // certificó 33/34/52/56/61). Compatible con el formato de SimpleAPI.
    // La API key (TRVX-…) se crea en https://trivoxa.cl/TrivoxaAPIDTE/admin con el
    // ambiente "produccion" y el RUT de la empresa emisora autorizado, y se guarda
    // en secret.php como 'trivoxa_api_key'. Sin usuario/clave Basic (no se usan).
    'base_url' => (string)($secret['trivoxa_base_url'] ?? 'https://trivoxa.cl/TrivoxaAPIDTE'),
    'api_key'  => (string)($secret['trivoxa_api_key'] ?? ''),

    // Autenticación al SII: cada emisión usa el certificado propio de la empresa.
    'api_user_rut'  => (string)($secret['api_user_rut'] ?? ''),
    'api_user_pass' => (string)($secret['api_user_pass'] ?? ''),

    // --- Ambiente y resolución del SII ---
    'ambiente'          => 'produccion',
    'numero_resolucion' => (int)($secret['numero_resolucion'] ?? 0),
    'fecha_resolucion'  => (string)($secret['fecha_resolucion'] ?? '2020-01-01'),
    // Actividad económica por defecto (salud); la emisión la sobrescribe con la
    // actividad_economica real de cada empresa.
    'acteco'            => (int)($secret['acteco'] ?? 869090),

    // --- Clave maestra para cifrar las claves de los .pfx de cada empresa ---
    // Se genera una sola vez (64 hex) y se guarda en secret.php. Si se pierde,
    // no se podrán descifrar los certificados cargados.
    'app_key' => (string)($secret['app_key'] ?? ''),

    // --- Almacén aislado por empresa (FUERA del webroot) ---
    'empresas_dir'   => $PRIV . '/empresas',
    'tmp_dir'        => $PRIV . '/tmp',
    'debug_log_dir'  => $PRIV . '/logs',

    // Solo acepta CAF cuyo RUT coincide con el de la empresa. Mantener en true.
    'caf_strict_produccion' => true,

    'correo_notificacion' => (string)($secret['correo_notificacion'] ?? ''),

    // Tiempos de espera de las llamadas a Trivoxa API DTE (segundos).
    'timeout'         => (int)($secret['timeout'] ?? 90),
    'connect_timeout' => (int)($secret['connect_timeout'] ?? 20),
];
