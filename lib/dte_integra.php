<?php
/**
 * Integración del motor DTE con el módulo de Boletas/Facturas de EspacioLuz.
 *
 * Puente entre el mundo PDO del panel y el motor DTE (mysqli). Cuando la
 * empresa tiene certificado y CAF con folios, la emisión manda el documento
 * REAL al SII (vía Trivoxa API DTE, la misma API con la que Trivoxa-DTE emite)
 * y guarda el resultado; si no, el panel sigue registrando el documento de
 * forma interna ("pendiente de timbrar").
 *
 * Tipos: boletas 39/41 (pacientes), facturas 33/34 (clientes) y notas de
 * crédito 61 / débito 56 sobre esos documentos. Cada empresa declara en
 * empresas.dte_certificados los tipos que tiene certificados ante el SII
 * (Espacio Luz: 34, 56 y 61); vacío = sin restricción.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';

/** Normaliza los ítems del formulario (igual criterio que crearBoleta). */
function dteNormalizarItems(array $items): array
{
    $norm = [];
    $todoExento = true;
    foreach ($items as $it) {
        $desc = trim((string) ($it['descripcion'] ?? ''));
        $pu = max(0, (int) preg_replace('/\D/', '', (string) ($it['precio_unit'] ?? $it['precio_unitario'] ?? 0)));
        if ($desc === '' || $pu <= 0) {
            continue;
        }
        $cant = max(1, (int) ($it['cantidad'] ?? 1));
        $afecto = !empty($it['afecto']);
        if ($afecto) {
            $todoExento = false;
        }
        $norm[] = [
            'descripcion' => mb_substr($desc, 0, 300, 'UTF-8'),
            'cantidad'    => $cant,
            'precio_unit' => $pu,
            'afecto'      => $afecto ? 1 : 0,
            'total'       => $cant * $pu,
            'producto_id' => (int) ($it['producto_id'] ?? 0) ?: null,
        ];
    }
    return ['items' => $norm, 'todo_exento' => $todoExento, 'tiene' => count($norm) > 0];
}

/**
 * Tipo de DTE a emitir según la empresa (o el tipo forzado) y si todo es exento.
 * $forzar: 'boleta' (Pacientes) o 'factura' (Clientes); vacío = el tipo de la empresa.
 */
function dteTipoDocumento(array $empresa, bool $todoExento, string $forzar = ''): int
{
    $esFactura = $forzar !== '' ? ($forzar === 'factura') : (($empresa['tipo_documento'] ?? 'boleta') === 'factura');
    if ($esFactura) {
        return $todoExento ? 34 : 33;   // 34 factura exenta, 33 factura afecta
    }
    return $todoExento ? 41 : 39;       // 41 boleta exenta, 39 boleta afecta
}

/** Tipos de DTE certificados por la empresa ante el SII, o null si no se declararon (sin restricción). */
function dteCertificados(array $empresa): ?array
{
    $raw = trim((string) ($empresa['dte_certificados'] ?? ''));
    if ($raw === '') {
        return null;
    }
    $t = array_values(array_unique(array_filter(array_map('intval', preg_split('/\D+/', $raw)), fn($x) => $x > 0)));
    sort($t);
    return $t;
}

/** ¿La empresa está certificada para emitir este tipo? (sin lista declarada = sí). */
function dteEmpresaCertificada(array $empresa, int $tipo): bool
{
    $c = dteCertificados($empresa);
    return $c === null || in_array($tipo, $c, true);
}

/** Facturas de esta empresa: solo exentas (certificada para 34 pero no para 33). */
function dteFacturaSoloExenta(array $empresa): bool
{
    $c = dteCertificados($empresa);
    return $c !== null && in_array(34, $c, true) && !in_array(33, $c, true);
}

/** Boletas de esta empresa: solo exentas (certificada para 41 pero no para 39). */
function dteBoletaSoloExenta(array $empresa): bool
{
    $c = dteCertificados($empresa);
    return $c !== null && in_array(41, $c, true) && !in_array(39, $c, true);
}

/** La empresa solo emite documentos exentos (no está certificada ni para 33 ni para 39). */
function dteEmpresaSoloExenta(array $empresa): bool
{
    $c = dteCertificados($empresa);
    return $c !== null && !in_array(33, $c, true) && !in_array(39, $c, true) && (in_array(34, $c, true) || in_array(41, $c, true));
}

/** Nombre de cada tipo de DTE usado en EspacioLuz. */
function dteNombreCorto(int $tipo): string
{
    return [33 => 'Factura electrónica', 34 => 'Factura exenta electrónica', 39 => 'Boleta electrónica', 41 => 'Boleta exenta electrónica',
            56 => 'Nota de débito electrónica', 61 => 'Nota de crédito electrónica'][$tipo] ?? ('DTE ' . $tipo);
}

/** ¿La API key configurada tiene el formato de Trivoxa API DTE (TRVX-…)? */
function dteApiKeyValida(array $cfg): bool
{
    return (bool) preg_match('/^TRVX(-[A-Z0-9]{3,}){2,}$/i', trim((string) ($cfg['api_key'] ?? '')));
}

/** Código de actividad económica SII (6 dígitos) de la empresa: columna acteco o el que venga escrito en la actividad. */
function dteActecoEmpresa(array $empresa): int
{
    $a = (int) ($empresa['acteco'] ?? 0);
    if ($a >= 100000 && $a <= 999999) {
        return $a;
    }
    return preg_match('/\b(\d{6})\b/', (string) ($empresa['actividad_economica'] ?? ''), $m) ? (int) $m[1] : 0;
}

/** Resolución SII de la empresa (número y fecha). ['numero'=>?int, 'fecha'=>?string]. */
function dteResolucionEmpresa(array $empresa): array
{
    $f = (string) ($empresa['sii_resolucion_fecha'] ?? '');
    return [
        'numero' => ($empresa['sii_resolucion_numero'] ?? null) !== null ? (int) $empresa['sii_resolucion_numero'] : null,
        'fecha'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && $f !== '0000-00-00' ? $f : null,
    ];
}

/**
 * Chequeo COMPLETO antes de emitir un DTE real al SII (boletas 39/41, facturas 33/34 y notas 56/61).
 * Devuelve:
 *   'credenciales' => true si ya hay certificado + CAF con folios (la empresa "quiere" emitir al SII)
 *   'listo'        => true si TODO está en regla y el documento puede ir al SII
 *   'items'        => [['ok'=>bool, 'label'=>..., 'detalle'=>...], ...]  (para mostrar la lista)
 *   'faltan'       => etiquetas de lo que falta
 * Regla de emisión: sin credenciales -> documento interno; con credenciales pero algo
 * pendiente -> NO se emite (evita rechazos del SII y folios perdidos); todo listo -> SII.
 */
function dteChequeoEmision(array $empresa, int $tipo): array
{
    require_once APP_ROOT . '/dte/bootstrap.php';
    if (dte_modo_demo()) {
        return dte_chequeo_demo($empresa, $tipo);
    }
    require_once APP_ROOT . '/lib/rut.php';
    $items = [];
    $add = function (bool $ok, string $label, string $detalle = '') use (&$items) {
        $items[] = ['ok' => $ok, 'label' => $label, 'detalle' => $detalle];
    };
    $esFactura = in_array($tipo, [33, 34, 56, 61], true); // documentos con Acteco y receptor completo
    $empId = (int) ($empresa['id'] ?? 0);
    $hoy = date('Y-m-d');
    $certT = dteCertificados($empresa);
    if ($certT !== null) {
        $okT = in_array($tipo, $certT, true);
        $add($okT, 'Empresa certificada ante el SII para ' . dteNombreCorto($tipo) . ' (' . $tipo . ')',
            $okT ? 'Certificada para: ' . implode(', ', $certT) . '.' : 'Esta empresa solo está certificada para: ' . implode(', ', $certT) . '. Corrígelo en Empresas si ya certificaste este tipo.');
    }

    $certOk = false; $cafOk = false; $cfg = []; $enProduccion = false;
    try {
        [$conex, $cfg] = require APP_ROOT . '/dte/bootstrap.php';
        $est = dte_empresa_estado($conex, $empId);
        $cert = $est['cert'] ?? null;
        $archivo = $cert ? is_file(dte_cert_path($cfg, $empId)) : false;
        $vence = (string) ($cert['cert_vence'] ?? '');
        $certOk = $cert && $archivo && ($vence === '' || $vence >= $hoy);
        $add($certOk, 'Certificado digital cargado y vigente',
            !$cert ? 'Súbelo en Empresas y SII → Certificado y folios (archivo .pfx/.p12 del representante autorizado ante el SII).'
            : (!$archivo ? 'El registro existe pero falta el archivo .pfx en el almacén privado: vuelve a subirlo.'
            : ($vence !== '' && $vence < $hoy ? 'Venció el ' . date('d-m-Y', strtotime($vence)) . '. Renuévalo y súbelo de nuevo.'
            : 'Titular RUT ' . ($cert['cert_rut'] ?: '—') . ($vence !== '' ? ' · vence el ' . date('d-m-Y', strtotime($vence)) : ''))));
        $cafOk = in_array($tipo, $est['tipos_activos'] ?? [], true);
        // Empresa EN PRODUCCIÓN: tiene certificado y algún CAF de producción cargado (de
        // cualquier tipo, aunque esté agotado). Desde ahí nunca se emite un documento
        // interno: si falta algo (otro tipo sin CAF, folios agotados, certificado vencido)
        // se bloquea con el motivo, en vez de registrar un documento que no va al SII.
        $stP = $conex->prepare("SELECT COUNT(*) FROM caf_empresa WHERE empresa_id=? AND ambiente='produccion'");
        $stP->bind_param('i', $empId); $stP->execute();
        $enProduccion = $cert !== null && (int) ($stP->get_result()->fetch_row()[0] ?? 0) > 0;
        $stP->close();
        $add($cafOk, 'Folios (CAF) de ' . (function_exists('dte_nombre') ? dte_nombre($tipo) : 'tipo ' . $tipo) . ' (' . $tipo . ')',
            $cafOk ? 'Hay folios de producción disponibles.' : 'Pídelos en el SII y súbelos en Empresas y SII → Certificado y folios.');
    } catch (Throwable $e) {
        $add(false, 'Motor DTE', 'No se pudo leer el estado del certificado y los folios: ' . $e->getMessage());
    }

    $rut = (string) ($empresa['rut'] ?? '');
    $add($rut !== '' && rutValido($rut), 'RUT de la empresa válido', $rut !== '' ? $rut : 'Complétalo en Empresas.');
    $faltanDatos = [];
    foreach (['razon_social' => 'razón social', 'giro' => 'giro', 'direccion' => 'dirección', 'comuna' => 'comuna'] as $k => $l) {
        if (trim((string) ($empresa[$k] ?? '')) === '') {
            $faltanDatos[] = $l;
        }
    }
    $add(!$faltanDatos, 'Datos tributarios de la empresa', $faltanDatos ? 'Falta: ' . implode(', ', $faltanDatos) . ' (en Empresas).' : 'Razón social, giro, dirección y comuna completos.');
    if ($esFactura) {
        $ac = dteActecoEmpresa($empresa);
        $add($ac > 0, 'Código de actividad económica (SII)', $ac > 0 ? (string) $ac : 'Las facturas y las notas de crédito/débito exigen el código de 6 dígitos registrado en el SII. Agrégalo en Empresas.');
    }
    $res = dteResolucionEmpresa($empresa);
    $add($res['numero'] !== null && $res['fecha'] !== null, 'Resolución SII de la empresa',
        ($res['numero'] !== null && $res['fecha'] !== null) ? 'N° ' . $res['numero'] . ' del ' . date('d-m-Y', strtotime($res['fecha']))
        : 'Número y fecha de la resolución que autoriza a la empresa a emitir documentos electrónicos (aparecen en el SII, en los datos de la empresa autorizada). Agrégalos en Empresas.');
    $add(dteApiKeyValida($cfg), 'Conexión con Trivoxa API DTE', dteApiKeyValida($cfg)
        ? 'API key configurada (' . substr(trim((string) $cfg['api_key']), 0, 10) . '…). Puedes probarla en Empresas y SII.'
        : 'Falta la API key de producción (TRVX-…) de Trivoxa API DTE, con el RUT de la empresa autorizado. Se guarda en la configuración privada del servidor.');
    $ak = (string) ($cfg['app_key'] ?? '');
    $add(strlen($ak) >= 64 && ctype_xdigit($ak), 'Clave de cifrado de certificados', 'Necesaria para guardar la contraseña del .pfx de forma segura.');

    $faltan = array_values(array_map(fn($i) => $i['label'], array_filter($items, fn($i) => !$i['ok'])));
    return ['credenciales' => ($certOk && $cafOk) || $enProduccion, 'listo' => !$faltan, 'items' => $items, 'faltan' => $faltan];
}

/** Mensaje para cuando hay certificado + CAF pero falta algo para emitir al SII. */
function dteMensajeFaltan(array $chequeo, string $doc): string
{
    return 'La empresa ya tiene certificado y folios, pero antes de enviar ' . $doc . ' al SII falta: '
        . implode('; ', array_map('mb_strtolower', $chequeo['faltan'])) . '. Revísalo en Empresas y SII.';
}

/** ¿La empresa tiene certificado digital cargado? (vía motor DTE). */
function dteTieneCert(int $empresaId): bool
{
    [$conex] = require APP_ROOT . '/dte/bootstrap.php';
    return dte_empresa_tiene_cert($conex, $empresaId);
}

/** Mensaje si la empresa NO puede emitir ese tipo ahora ('' si puede). */
function dteMotivoNoEmite(int $empresaId, int $tipo): string
{
    [$conex] = require APP_ROOT . '/dte/bootstrap.php';
    return dte_motivo_no_disponible($conex, $empresaId, $tipo, 'produccion');
}

/**
 * Emisión genérica de un DTE real con el motor (mismo flujo que Trivoxa-DTE:
 * generar → sobre → envío → verificación del estado → folio).
 * $items: formato del motor [{descripcion,cantidad,precio_unitario,unidad,exento}].
 * $receptor: [rut, razon_social, giro, direccion, comuna, ciudad, contacto].
 * $referencias: [{tipo_doc, folio, fecha, codigo(1|2|3), razon}] (obligatorias en 56/61).
 */
function dteEmitirDte(array $empresa, int $tipo, array $items, array $receptor, array $referencias = [], string $obs = ''): array
{
    [$conex, $cfg] = require APP_ROOT . '/dte/bootstrap.php';
    $cfg = dteCfgEmpresa($cfg, $empresa);
    $payload = [
        'empresa_id'    => (int) $empresa['id'],
        'tipo'          => $tipo,
        'items'         => $items,
        'receptor'      => $receptor,
        'referencias'   => $referencias,
        'fecha_emision' => date('Y-m-d'),
        'forma_pago'    => 1, // contado
        'observaciones' => $obs,
    ];
    return dte_emitir($conex, $cfg, $payload);
}

/** Config del motor con la actividad económica y la resolución SII propias de la empresa. */
function dteCfgEmpresa(array $cfg, array $empresa): array
{
    $acteco = dteActecoEmpresa($empresa);
    if ($acteco > 0) {
        $cfg['acteco'] = $acteco;
    }
    $res = dteResolucionEmpresa($empresa);
    if ($res['numero'] !== null && $res['fecha'] !== null) {
        $cfg['numero_resolucion'] = $res['numero'];
        $cfg['fecha_resolucion'] = $res['fecha'];
    }
    return $cfg;
}

/** Documento DTE (dte_documentos) asociado a una boleta/factura del panel, o null si es interna. */
function dteDocDeBoleta(int $boletaId): ?array
{
    try {
        [$conex] = require APP_ROOT . '/dte/bootstrap.php';
        $st = $conex->prepare("SELECT id, empresa_id, tipo_dte, folio, fecha_emision, rut_receptor, razon_receptor, estado, trackid FROM dte_documentos WHERE boleta_id = ? ORDER BY id DESC LIMIT 1");
        $st->bind_param('i', $boletaId); $st->execute();
        $r = $st->get_result()->fetch_assoc(); $st->close();
        return $r ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Documento DTE por id (dte_documentos), o null. */
function dteDocPorId(int $id): ?array
{
    if ($id <= 0) return null;
    try {
        [$conex] = require APP_ROOT . '/dte/bootstrap.php';
        $st = $conex->prepare("SELECT id, empresa_id, tipo_dte, folio, fecha_emision, estado, trackid FROM dte_documentos WHERE id = ? LIMIT 1");
        $st->bind_param('i', $id); $st->execute();
        $r = $st->get_result()->fetch_assoc(); $st->close();
        return $r ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Re-consulta en el SII el estado de cualquier DTE ya emitido (por su id en dte_documentos).
 * Devuelve ['ok','estado'=>'aceptada'|'rechazada'|'pendiente','mensaje'].
 */
function dteReconsultarDte(int $dteDocId, int $intentos = 2): array
{
    [$conex, $cfg] = require APP_ROOT . '/dte/bootstrap.php';
    $res = dte_resync_estado($conex, $cfg, $dteDocId, $intentos);
    if (!($res['ok'] ?? false)) {
        return ['ok' => false, 'error' => $res['error'] ?? 'No se pudo consultar el estado.'];
    }
    $e = strtoupper((string) ($res['estado'] ?? ''));
    $estado = !empty($res['pendiente']) ? 'pendiente' : (['ENVIADO' => 'aceptada', 'ACEPTADO' => 'aceptada', 'RECHAZADO' => 'rechazada'][$e] ?? 'pendiente');
    return ['ok' => true, 'estado' => $estado, 'mensaje' => $res['mensaje'] ?? ('Estado SII: ' . $e)];
}

/**
 * Prueba la conexión con Trivoxa API DTE: salud del servicio y estado de la API key
 * (no emite nada ni consume folios). ['ok', 'salud', 'key', 'detalle'].
 */
function dteProbarConexion(): array
{
    require_once APP_ROOT . '/dte/bootstrap.php';
    if (dte_modo_demo()) {
        return ['ok' => true, 'salud' => true, 'key' => true, 'detalle' => 'Modo demostración: no hay conexión con el SII ni con Trivoxa API DTE. Las boletas, facturas y notas se emiten con folios de prueba y sin validez tributaria.'];
    }
    [, $cfg] = require APP_ROOT . '/dte/bootstrap.php';
    $base = rtrim((string) ($cfg['base_url'] ?? ''), '/');
    $get = function (string $url, bool $conKey) use ($cfg): array {
        $ch = curl_init($url);
        $h = ['Accept: application/json'];
        if ($conKey) {
            $h[] = 'X-Api-Key: ' . trim((string) ($cfg['api_key'] ?? ''));
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
                                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $b = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = is_string($b) ? json_decode($b, true) : null;
        return ['http' => $code, 'json' => is_array($j) ? $j : null];
    };
    $s = $get($base . '/api/v1/health', false);
    $salud = $s['http'] === 200;
    if (!dteApiKeyValida($cfg)) {
        return ['ok' => false, 'salud' => $salud, 'key' => false,
                'detalle' => ($salud ? 'Trivoxa API DTE responde, pero ' : 'Trivoxa API DTE no respondió y ') . 'falta la API key de producción (TRVX-…).'];
    }
    $k = $get($base . '/api/v1/Suscripcion/status', true);
    $keyOk = $k['http'] === 200;
    $det = $keyOk ? 'API key activa.' : ('La API key fue rechazada (HTTP ' . $k['http'] . ').');
    if ($keyOk && is_array($k['json'])) {
        foreach ($k['json'] as $kk => $vv) {
            if (is_scalar($vv) && $vv !== '' && preg_match('/plan|ambiente|rut|restante|vence|estado/i', (string) $kk)) {
                $det .= ' ' . $kk . ': ' . $vv . '.';
            }
        }
    }
    return ['ok' => $salud && $keyOk, 'salud' => $salud, 'key' => $keyOk, 'detalle' => ($salud ? 'Servicio en línea. ' : 'El servicio no respondió. ') . $det];
}

/**
 * Emite el DTE real al SII. Devuelve el array de dte_emitir (ok, folio,
 * trackid, estado, neto, iva, exento, total, documento_id, error, glosa).
 */
function dteEmitirReal(array $empresa, int $tipo, array $normItems, array $body): array
{

    // Ítems en el formato del motor (precio NETO; exento = !afecto).
    $items = [];
    foreach ($normItems as $it) {
        $items[] = [
            'descripcion'     => $it['descripcion'],
            'cantidad'        => $it['cantidad'],
            'precio_unitario' => $it['precio_unit'],
            'unidad'          => 'UN',
            'exento'          => $it['afecto'] ? 0 : 1,
        ];
    }

    $receptor = [
        'rut'          => trim((string) ($body['receptor_rut'] ?? '')),
        'razon_social' => trim((string) ($body['receptor_nombre'] ?? '')),
        'contacto'     => trim((string) ($body['receptor_email'] ?? '')),
    ];
    // Factura (cliente): el SII exige giro, dirección y comuna del receptor.
    if (in_array($tipo, [33, 34], true)) {
        $receptor['giro']      = trim((string) ($body['receptor_giro'] ?? ''));
        $receptor['direccion'] = trim((string) ($body['receptor_direccion'] ?? ''));
        $receptor['comuna']    = trim((string) ($body['receptor_comuna'] ?? ''));
        $receptor['ciudad']    = trim((string) ($body['receptor_ciudad'] ?? ''));
    }

    return dteEmitirDte($empresa, $tipo, $items, $receptor, [], trim((string) ($body['glosa'] ?? '')));
}

/**
 * Registra en la tabla `boletas` de EspacioLuz un documento ya emitido por el
 * motor DTE (para que aparezca en "Boletas y Facturas" y cuente como ingreso),
 * y lo enlaza con dte_documentos. Devuelve el id de la boleta.
 */
function dteRegistrarBoleta(array $empresa, array $body, array $normItems, array $r, int $tipo): int
{
    $pdo = db();
    // El tipo sale del DTE emitido (33/34 = factura a cliente; 39/41 = boleta a paciente).
    $tipoDoc = in_array($tipo, [33, 34], true) ? 'factura' : 'boleta';
    $medio = in_array($body['medio_pago'] ?? '', ['efectivo', 'tarjeta', 'transferencia', 'otro'], true) ? $body['medio_pago'] : 'efectivo';
    // Recibido por el SII pero sin veredicto todavía -> «pendiente» (la pantalla lo sigue reconsultando).
    $siiEstado = $r['estado'] === 'ENVIADO' ? (!empty($r['pendiente']) ? 'pendiente' : 'aceptada') : ($r['estado'] === 'RECHAZADO' ? 'rechazada' : 'pendiente');

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            "INSERT INTO boletas
                (folio, tipo, serie, empresa_id, emisor_usuario_id, emisor_nombre, receptor_nombre, receptor_rut, receptor_email, paciente_id, cliente_id, receptor_giro, reserva_id,
                 fecha_emision, medio_pago, neto_clp, iva_clp, exento_clp, total_clp, glosa, sii_estado, sii_track_id)
             VALUES (?,?,'sii',?,?,?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?,?,?)"
        );
        $st->execute([
            (int) $r['folio'], $tipoDoc, (int) $empresa['id'], $_SESSION['admin_id'] ?? null,
            $empresa['nombre_fantasia'] ?: $empresa['razon_social'],
            mb_substr(trim((string) ($body['receptor_nombre'] ?? '')) ?: 'Consumidor Final', 0, 200, 'UTF-8'),
            ($body['receptor_rut'] ?? '') !== '' ? mb_substr(trim((string) $body['receptor_rut']), 0, 20, 'UTF-8') : null,
            ($body['receptor_email'] ?? '') !== '' ? mb_substr(trim((string) $body['receptor_email']), 0, 200, 'UTF-8') : null,
            ($body['paciente_id'] ?? '') !== '' ? (int) $body['paciente_id'] : null,
            (int) ($body['cliente_id'] ?? 0) ?: null,
            ($body['receptor_giro'] ?? '') !== '' ? mb_substr(trim((string) $body['receptor_giro']), 0, 200, 'UTF-8') : null,
            ($body['reserva_id'] ?? '') !== '' ? (int) $body['reserva_id'] : null,
            date('Y-m-d H:i:s'), // hora de Chile (la base de datos está en UTC)
            $medio,
            (int) $r['neto'], (int) $r['iva'], (int) $r['exento'], (int) $r['total'],
            ($body['glosa'] ?? '') !== '' ? mb_substr(trim((string) $body['glosa']), 0, 300, 'UTF-8') : null,
            $siiEstado,
            ($r['trackid'] ?? '') !== '' ? (string) $r['trackid'] : null,
        ]);
        $id = (int) $pdo->lastInsertId();

        require_once APP_ROOT . '/lib/boletas.php';
        boletaInsertarItems($pdo, $id, $normItems);

        // Enlaza el documento DTE con esta boleta.
        if (!empty($r['documento_id'])) {
            $u = $pdo->prepare("UPDATE dte_documentos SET boleta_id = ? WHERE id = ?");
            $u->execute([$id, (int) $r['documento_id']]);
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        try { $pdo->rollBack(); } catch (Throwable $e2) {}
        throw $e;
    }
}

/**
 * Re-consulta en el SII el estado de una boleta ya emitida (por su TrackID) y
 * actualiza boletas.sii_estado / sii_track_id. Devuelve ['ok','estado','mensaje'].
 */
function dteReconsultarBoleta(int $boletaId, int $intentos = 2): array
{
    [$conex, $cfg] = require APP_ROOT . '/dte/bootstrap.php';
    // Documento DTE asociado a esta boleta.
    $st = $conex->prepare("SELECT id FROM dte_documentos WHERE boleta_id = ? ORDER BY id DESC LIMIT 1");
    $st->bind_param('i', $boletaId); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if (!$row) {
        return ['ok' => false, 'error' => 'Esta boleta no tiene un documento electrónico asociado.'];
    }
    $res = dte_resync_estado($conex, $cfg, (int) $row['id'], $intentos);
    if (!($res['ok'] ?? false)) {
        return ['ok' => false, 'error' => $res['error'] ?? 'No se pudo consultar el estado.'];
    }
    $map = ['ENVIADO' => 'aceptada', 'RECHAZADO' => 'rechazada', 'PENDIENTE' => 'pendiente', 'ANULADO' => 'anulada'];
    $siiEstado = $map[strtoupper((string) $res['estado'])] ?? 'pendiente';
    try {
        $up = db()->prepare("UPDATE boletas SET sii_estado = ? WHERE id = ?");
        $up->execute([$siiEstado === 'anulada' ? 'rechazada' : $siiEstado, $boletaId]);
    } catch (Throwable $e) { /* no crítico */ }
    return ['ok' => true, 'estado' => $siiEstado, 'mensaje' => $res['mensaje'] ?? ('Estado SII: ' . $res['estado'])];
}
