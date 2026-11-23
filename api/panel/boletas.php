<?php
/**
 * API del panel · boletas a Pacientes. Requiere sesión + CSRF. Emitir requiere admin.
 * Las FACTURAS son solo para Clientes y se emiten en /api/panel/clientes (action=facturar).
 * POST (crear)          -> emite el documento. Si la empresa tiene certificado y
 *                          CAF, lo timbra y envía al SII (DTE real); si no, lo
 *                          registra de forma interna ("pendiente de timbrar").
 * POST action=email     -> envía un documento existente por correo (PDF adjunto).
 * POST action=sii_recheck -> re-consulta en el SII el estado de un documento.
 *
 * En ambas rutas de emisión se guardan en el documento la dirección / comuna /
 * cód. prestación del paciente y el profesional que atendió (con su RUT).
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/boletas.php';
require_once APP_ROOT . '/lib/cobros.php';

requireAuthJson();

// GET ?action=sesiones&paciente_id= -> sesiones del paciente aún sin boleta (por cobrar).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    if (($_GET['action'] ?? '') === 'sesiones') {
        json_response(['ok' => true, 'sesiones' => sesionesPorCobrarPaciente((int) ($_GET['paciente_id'] ?? 0))]);
    }
    json_response(['error' => 'Acción no válida.'], 400);
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !csrf_check($csrf)) {
    json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
}

$body = read_json_body() ?? [];
$action = $body['action'] ?? 'crear';

if ($action === 'email') {
    $id = (int) ($body['id'] ?? 0);
    $b = getBoleta($id);
    if (!$b) {
        json_response(['error' => 'Boleta no encontrada'], 404);
    }
    $dest = trim((string) ($body['email'] ?? '')) ?: (string) ($b['receptor_email'] ?? '');
    if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
        json_response(['error' => 'Ingresa un correo válido para enviar la boleta.'], 400);
    }
    require_once APP_ROOT . '/lib/mailer.php';
    $r = enviarBoletaPorCorreo($id, $dest, (string) ($b['receptor_nombre'] ?? ''));
    if (!($r['ok'] ?? false)) {
        json_response(['error' => ($r['error'] ?? 'No se pudo enviar el correo.') . ' Puedes descargar el PDF y enviarlo manualmente.'], 502);
    }
    json_response(['ok' => true, 'email' => $dest]);
}

// --- Re-consultar en el SII el estado de un documento ya emitido ------------
if ($action === 'sii_recheck') {
    if (!isAdmin()) {
        json_response(['error' => 'Solo un administrador puede consultar el SII.'], 403);
    }
    require_once APP_ROOT . '/lib/dte_integra.php';
    // rapido=1: una sola consulta (la pantalla de aviso reconsulta sola cada pocos segundos).
    $r = dteReconsultarBoleta((int) ($body['id'] ?? 0), !empty($body['rapido']) ? 1 : 2);
    json_response($r['ok'] ? ['ok' => true, 'estado' => $r['estado'], 'mensaje' => $r['mensaje']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
}

// --- Cobro de un documento (boleta o factura): pagos, notas de crédito, anulación --
if (in_array($action, ['pago_registrar', 'pago_eliminar', 'nc_emitir', 'nd_emitir', 'nota_reconsultar', 'anular'], true)) {
    if (!isAdmin()) {
        json_response(['error' => 'Solo un administrador puede modificar cobros.'], 403);
    }
    $docId = (int) ($body['id'] ?? 0);
    if ($action === 'pago_registrar') {
        $r = registrarPago($docId, $body);
    } elseif ($action === 'pago_eliminar') {
        $r = eliminarPago((int) ($body['pago_id'] ?? 0));
    } elseif ($action === 'nc_emitir') {
        @set_time_limit(900); // si el documento está en el SII, la nota se emite allá (varios segundos)
        $r = emitirNotaCredito($docId, $body);
    } elseif ($action === 'nd_emitir') {
        @set_time_limit(900);
        $r = emitirNotaDebito($docId, $body);
    } elseif ($action === 'nota_reconsultar') {
        @set_time_limit(300);
        $r = notaReconsultar(($body['clase'] ?? '') === 'nd' ? 'nd' : 'nc', (int) ($body['nota_id'] ?? 0), !empty($body['rapido']) ? 1 : 2);
    } else {
        $r = anularDocumento($docId, (string) ($body['motivo'] ?? ''));
    }
    json_response($r['ok'] ? $r : ['error' => $r['error']], $r['ok'] ? 200 : 400);
}

// --- Emitir (crear) ---------------------------------------------------------
if (!isAdmin()) {
    json_response(['error' => 'Solo un administrador puede emitir documentos.'], 403);
}

$empresaId = (int) ($body['empresa_id'] ?? 0);
$empresa = $empresaId > 0 ? getEmpresa($empresaId) : null;
if (!$empresa) {
    json_response(['error' => 'Debes elegir la empresa emisora.'], 400);
}
if ((int) $empresa['activo'] !== 1) {
    json_response(['error' => 'La empresa seleccionada está desactivada.'], 400);
}
if (($empresa['tipo_documento'] ?? '') === 'factura') {
    json_response(['error' => 'Esa empresa emite solo facturas a Clientes. A los pacientes se les emite boleta.'], 400);
}

require_once APP_ROOT . '/lib/dte_integra.php';
$norm = dteNormalizarItems($body['items'] ?? []);
if (!$norm['tiene']) {
    json_response(['error' => 'Agrega al menos un ítem con descripción y precio.'], 400);
}
// Emisión 100% centrada en Pacientes: el receptor SIEMPRE sale de la ficha.
// Menor de edad -> se cobra al tutor/apoderado; mayor de edad -> al paciente.
require_once APP_ROOT . '/lib/pacientes.php';
$rc = receptorCobroPaciente((int) ($body['paciente_id'] ?? 0));
if (!$rc['ok']) {
    json_response(['error' => $rc['error']], 400);
}
// Las boletas las emiten SOLO las socias (Fernanda y Javiera), por sus propias sesiones.
if (profesionalesSocias() && !esSocia((int) ($body['profesional_id'] ?? 0) ?: null)) {
    json_response(['error' => 'Las boletas las emiten solo las socias (' . sociasTexto() . '). Elige quién emite.'], 400);
}
// Sesiones que paga esta boleta: deben ser del paciente, no estar canceladas ni ya pagadas.
$sesionIds = [];
foreach ((array) ($body['items'] ?? []) as $it) {
    foreach ((array) ($it['reserva_ids'] ?? []) as $sid) {
        if ((int) $sid > 0) $sesionIds[(int) $sid] = true;
    }
}
$sesionIds = array_keys($sesionIds);
if ($sesionIds) {
    $in = implode(',', array_fill(0, count($sesionIds), '?'));
    $soc = array_keys(profesionalesSocias());
    $vs = db()->prepare("SELECT COUNT(*) FROM reservas WHERE id IN ($in) AND paciente_id = ? AND boleta_id IS NULL AND estado <> 'cancelada'"
        . ($soc ? ' AND profesional_id IN (' . implode(',', array_map('intval', $soc)) . ')' : ''));
    $vs->execute(array_merge($sesionIds, [(int) $rc['paciente_id']]));
    if ((int) $vs->fetchColumn() !== count($sesionIds)) {
        json_response(['error' => 'Alguna de las sesiones elegidas ya fue pagada, se canceló o no es de este paciente. Vuelve a elegir las sesiones.'], 409);
    }
}
/** Une las sesiones a la boleta emitida y registra el pago al contado. */
$cerrarCobro = function (int $docId) use ($body) {
    foreach ((array) ($body['items'] ?? []) as $it) {
        $ids = array_filter(array_map('intval', (array) ($it['reserva_ids'] ?? [])));
        if ($ids) {
            cobroVincular($docId, 'boleta', $ids, max(0, (int) preg_replace('/\D/', '', (string) ($it['precio_unit'] ?? 0))), !empty($it['afecto']));
        }
    }
    cobroTrasEmitir($docId, true, (string) ($body['medio_pago'] ?? 'efectivo'));
};
$mailForm = trim((string) ($body['receptor_email'] ?? ''));
$body['paciente_id']                = $rc['paciente_id'];
$body['receptor_tipo']              = $rc['tipo'];
$body['receptor_nombre']            = $rc['nombre'];
$body['receptor_rut']               = $rc['rut'];
$body['receptor_email']             = filter_var($mailForm, FILTER_VALIDATE_EMAIL) ? $mailForm : $rc['email'];
$body['receptor_direccion']         = $rc['direccion'];
$body['receptor_comuna']            = $rc['comuna'];
$body['receptor_codigo_prestacion'] = $rc['codigo_prestacion'];

// Empresa autorizada solo para boleta exenta (41): un ítem afecto la convertiría en boleta 39.
if (dteBoletaSoloExenta($empresa) && !$norm['todo_exento']) {
    json_response(['error' => ($empresa['nombre_fantasia'] ?: $empresa['razon_social']) . ' emite solo boleta exenta electrónica (41): todos los ítems deben ser «Exento». Cambia los ítems afectos (o el producto en Productos).'], 400);
}
// A los pacientes SIEMPRE se les emite boleta (39 afecta / 41 exenta). Las facturas son solo para Clientes.
$tipo = dteTipoDocumento($empresa, $norm['todo_exento'], 'boleta');

// Emite DTE real SOLO cuando la empresa está 100% lista: certificado cargado
// Y CAF con folios disponibles para este tipo. Mientras no se suban los CAF
// (o falte el certificado), el documento se registra en modo MVP interno
// (sin enviar al SII). Así nada se bloquea y, al subir cert + CAF, la emisión
// real se activa sola.
$chequeo = dteChequeoEmision($empresa, $tipo);
if ($chequeo['credenciales'] && !$chequeo['listo']) {
    // Ya hay certificado + CAF: no se emite a medias (ni interno ni con datos que el SII rechazaría).
    json_response(['error' => dteMensajeFaltan($chequeo, 'la boleta')], 400);
}
$listoParaDTE = $chequeo['listo'];

if ($listoParaDTE) {
    @set_time_limit(900);
    @ini_set('max_execution_time', '900');
    try {
        $r = dteEmitirReal($empresa, $tipo, $norm['items'], $body);
    } catch (Throwable $e) {
        error_log('[boletas.dte] ' . $e->getMessage());
        json_response(['error' => 'Error al emitir el documento electrónico: ' . $e->getMessage()], 500);
    }
    if (!($r['ok'] ?? false)) {
        // Rechazado por el SII o error: queda en dte_documentos como RECHAZADO,
        // pero NO se registra como ingreso en boletas.
        json_response([
            'error'  => $r['error'] ?? 'El SII rechazó el documento.',
            'sii'    => true,
            'estado' => $r['estado'] ?? 'RECHAZADO',
            'glosa'  => $r['glosa'] ?? null,
        ], 400);
    }
    try {
        $id = dteRegistrarBoleta($empresa, $body, $norm['items'], $r, $tipo);
    } catch (Throwable $e) {
        error_log('[boletas.dte registro] ' . $e->getMessage());
        json_response(['error' => 'El documento se emitió en el SII (folio ' . $r['folio'] . ', TrackID ' . $r['trackid'] . ') pero falló el registro local. Anota estos datos.'], 500);
    }
    // Dirección / cód. prestación del paciente y profesional que atendió.
    boletaGuardarExtras((int) $id, $body);
    $cerrarCobro((int) $id);
    json_response([
        'ok'      => true,
        'id'      => $id,
        'folio'   => $r['folio'],
        'tipo'    => 'boleta',
        'sii'     => true,
        'estado'  => $r['estado'],
        'trackid' => $r['trackid'],
        'tipo_dte' => $tipo,
        'total'   => (int) $r['total'],
        'sii_estado' => !empty($r['pendiente']) ? 'pendiente' : 'aceptada',
    ]);
}

// Empresa sin certificado/CAF: documento INTERNO (sin SII).
$res = crearBoleta([
    'empresa_id'     => $empresaId,
    'tipo_doc'       => 'boleta',
    'receptor_nombre' => $body['receptor_nombre'] ?? '',
    'receptor_rut'    => $body['receptor_rut'] ?? '',
    'receptor_email'  => $body['receptor_email'] ?? '',
    'paciente_id'     => $body['paciente_id'] ?? '',
    'receptor_tipo'   => $body['receptor_tipo'] ?? '',
    'receptor_direccion'         => $body['receptor_direccion'] ?? '',
    'receptor_comuna'            => $body['receptor_comuna'] ?? '',
    'receptor_codigo_prestacion' => $body['receptor_codigo_prestacion'] ?? '',
    'reserva_id'     => $body['reserva_id'] ?? '',
    'profesional_id'     => $body['profesional_id'] ?? '',
    'profesional_nombre' => $body['profesional_nombre'] ?? '',
    'profesional_rut'    => $body['profesional_rut'] ?? '',
    'medio_pago'     => $body['medio_pago'] ?? 'efectivo',
    'glosa'          => $body['glosa'] ?? '',
    'items'          => $body['items'] ?? [],
]);
if (!$res['ok']) {
    json_response(['error' => $res['error']], 400);
}
$cerrarCobro((int) $res['id']);
json_response(['ok' => true, 'id' => $res['id'], 'folio' => $res['folio'], 'tipo' => $res['tipo'], 'interno' => true, 'sesiones' => count($sesionIds), 'tipo_dte' => $tipo]);
