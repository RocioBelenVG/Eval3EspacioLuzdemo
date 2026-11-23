<?php
declare(strict_types=1);
/**
 * Bootstrap del motor DTE de EspacioLuz.
 * Carga la conexión mysqli, la configuración y todo el motor replicado.
 * Uso:
 *     [$conex, $cfg] = require __DIR__ . '/dte/bootstrap.php';
 * Devuelve [mysqli $conex, array $cfg].
 */

// Idempotente: si ya se inicializó en este request, devuelve la conexión
// cacheada. Evita reabrir mysqli y que un segundo `require` deje $conex sin
// definir (require_once de conexion.php no re-ejecuta y no re-define $conex).
if (isset($GLOBALS['__DTE_CONEX']) && $GLOBALS['__DTE_CONEX'] instanceof mysqli) {
    return [$GLOBALS['__DTE_CONEX'], $GLOBALS['__DTE_CFG']];
}

require_once __DIR__ . '/conexion.php';            // define $conex (mysqli)
$cfg = require __DIR__ . '/config.php';            // array de configuración

require_once __DIR__ . '/sii_api/client.php';      // TrivoxaApiClient → Trivoxa API DTE (cURL, sin BD)
require_once __DIR__ . '/lib/folios.php';          // catálogo DTE + folios
require_once __DIR__ . '/lib/dte_tipos.php';       // tipos disponibles por empresa
require_once __DIR__ . '/lib/contabilidad.php';    // stub no-op (EspacioLuz no lleva contabilidad ERP)
require_once __DIR__ . '/lib/dte_cred.php';        // certificados + CAF por empresa
require_once __DIR__ . '/lib/dte_demo.php';        // MODO DEMO: nada sale al SII (EspacioLuz Demo)
require_once __DIR__ . '/lib/dte_emisor.php';      // emisión unitaria multi-empresa

$GLOBALS['__DTE_CONEX'] = $conex;
$GLOBALS['__DTE_CFG']   = $cfg;
return [$conex, $cfg];
