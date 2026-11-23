<?php
/**
 * API del panel · Credenciales DTE por empresa (solo admin, con CSRF).
 * Sube/elimina el certificado digital (.pfx) y los CAF de cada empresa, y
 * consulta el estado (cert vigente, folios disponibles por tipo). Usa el motor
 * DTE replicado (mysqli) vía dte/bootstrap.php; el aislamiento por empresa lo
 * garantiza el propio motor (RUT del CAF debe coincidir con el de la empresa).
 *
 * Multipart/form-data: campos _csrf, action, empresa_id (+ archivos cert/caf).
 */
declare(strict_types=1);

requireAdminJson();

$csrf = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!csrf_check($csrf)) {
    json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
}

$action    = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$empresaId = (int) ($_POST['empresa_id'] ?? $_GET['empresa_id'] ?? 0);
if ($empresaId <= 0) {
    json_response(['error' => 'Falta la empresa.'], 400);
}

// Motor DTE (abre su propia conexión mysqli a la BD de EspacioLuz).
[$conex, $cfg] = require APP_ROOT . '/dte/bootstrap.php';

/** Lista los CAF cargados de una empresa (para la tabla de folios). */
function dteCafsEmpresa(mysqli $conex, int $empresaId): array
{
    $out = [];
    $st = $conex->prepare(
        "SELECT id, tipo_dte, folio_desde, folio_hasta, folio_actual, activo, fecha_autorizacion, caf_nombre, created_at
           FROM caf_empresa
          WHERE empresa_id = ? AND ambiente = 'produccion'
          ORDER BY tipo_dte, folio_desde"
    );
    $st->bind_param('i', $empresaId);
    $st->execute();
    $r = $st->get_result();
    while ($x = $r->fetch_assoc()) {
        $x['disponibles'] = max(0, (int) $x['folio_hasta'] - (int) $x['folio_actual'] + 1);
        if ((int) $x['folio_actual'] > (int) $x['folio_hasta']) {
            $x['disponibles'] = 0;
        }
        $out[] = $x;
    }
    $st->close();
    return $out;
}

try {
    if ($action === 'estado') {
        json_response([
            'ok'     => true,
            'estado' => dte_empresa_estado($conex, $empresaId),
            'tipos'  => dte_tipos_disponibles($conex, $empresaId, 'produccion'),
            'cafs'   => dteCafsEmpresa($conex, $empresaId),
        ]);
    }

    if ($action === 'subir_cert') {
        if (empty($_FILES['cert']['tmp_name']) || !is_uploaded_file($_FILES['cert']['tmp_name'])) {
            json_response(['error' => 'Adjunta el archivo del certificado (.pfx / .p12).'], 400);
        }
        $pass = (string) ($_POST['cert_pass'] ?? '');
        if ($pass === '') {
            json_response(['error' => 'Ingresa la clave del certificado.'], 400);
        }
        $r = dte_guardar_cert($conex, $cfg, $empresaId, $_FILES['cert']['tmp_name'], $pass);
        json_response(['ok' => true, 'cert' => $r]);
    }

    if ($action === 'subir_caf') {
        if (empty($_FILES['caf']['tmp_name']) || !is_uploaded_file($_FILES['caf']['tmp_name'])) {
            json_response(['error' => 'Adjunta el archivo CAF (.xml).'], 400);
        }
        $xml = (string) file_get_contents($_FILES['caf']['tmp_name']);
        if (trim($xml) === '') {
            json_response(['error' => 'El archivo CAF está vacío.'], 400);
        }
        $nombre = (string) ($_FILES['caf']['name'] ?? '');
        $info = dte_guardar_caf($conex, $cfg, $empresaId, $xml, $nombre);
        json_response(['ok' => true, 'caf' => $info]);
    }

    // ---- Folios: solicitar al SII (vía Trivoxa API DTE) y configurar la solicitud automática ----
    if ($action === 'folios_solicitar' || $action === 'folios_config') {
        require_once APP_ROOT . '/dte/lib/dte_folios_sii.php';
        $tipo = (int) ($_POST['tipo'] ?? 0);
        if (!in_array($tipo, dte_folios_tipos_empresa($conex, $empresaId), true)) {
            json_response(['error' => 'Ese tipo de documento no corresponde a esta empresa.'], 400);
        }
        if ($action === 'folios_config') {
            $c = dte_folios_guardar_config($conex, $empresaId, $tipo, !empty($_POST['auto']), (int) ($_POST['umbral'] ?? 10), (int) ($_POST['cantidad'] ?? 20));
            json_response(['ok' => true, 'config' => $c]);
        }
        require_once APP_ROOT . '/lib/empresas.php';
        require_once APP_ROOT . '/lib/dte_integra.php';
        $emp = getEmpresa($empresaId);
        if (!$emp || !dteEmpresaCertificada($emp, $tipo)) {
            json_response(['error' => 'La empresa no está marcada como certificada para ' . dte_nombre($tipo) . ' (' . $tipo . '). Corrígelo en «Editar datos» si ya terminaste la certificación.'], 400);
        }
        $cantidad = (int) ($_POST['cantidad'] ?? 0);
        if ($cantidad < 1 || $cantidad > 10000) {
            json_response(['error' => 'Indica una cantidad de folios entre 1 y 10.000.'], 400);
        }
        @set_time_limit(600); // el timbraje del SII puede demorar (y hay reintentos si limita la cantidad)
        $r = dte_folios_solicitar_auto($conex, $cfg, $empresaId, $tipo, $cantidad, 'manual');
        json_response($r + ['ok' => !empty($r['ok'])], 200);
    }

    if ($action === 'borrar_caf') {
        $cafId = (int) ($_POST['caf_id'] ?? 0);
        if ($cafId <= 0) {
            json_response(['error' => 'CAF inválido.'], 400);
        }
        dte_borrar_caf($conex, $cfg, $empresaId, $cafId);
        json_response(['ok' => true]);
    }

    json_response(['error' => 'Acción inválida.'], 400);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 400);
}
