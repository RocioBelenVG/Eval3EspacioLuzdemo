<?php
declare(strict_types=1);
/**
 * EspacioLuz Demo · MODO DEMOSTRACIÓN del motor DTE.
 *
 * En esta copia del sistema NINGÚN documento tributario sale del servidor:
 *   - no hay conexión con Trivoxa API DTE ni con el SII (el cliente HTTP queda bloqueado);
 *   - boletas (39/41), facturas (33/34) y notas (56/61) se registran con el mismo
 *     flujo, tablas y formato que el sistema real, pero con folios de un CAF DEMO,
 *     TrackID «DEMO-…» y la leyenda «sin validez tributaria» en la vista y el PDF.
 *
 * El modo demo NO se puede desactivar por configuración: es una propiedad del código
 * de esta copia (requisito: la demo nunca emite documentos reales).
 */

const DTE_DEMO_NOTA = '<b>DOCUMENTO DE DEMOSTRACIÓN — SIN VALIDEZ TRIBUTARIA.</b> '
    . 'Emitido por EspacioLuz Demo con fines académicos: no fue enviado al SII y su folio pertenece a un CAF de prueba.';

/** Rango de folios que se crea cada vez que una empresa demo necesita un CAF nuevo. */
const DTE_DEMO_FOLIOS_POR_CAF = 1000;

/** Siempre verdadero en esta copia. */
function dte_modo_demo(): bool
{
    return true;
}

/** Corta cualquier intento de comunicación con el SII o con Trivoxa API DTE. */
function dte_demo_bloquear(string $que): void
{
    throw new RuntimeException('Modo demostración: ' . $que . ' está deshabilitado. EspacioLuz Demo no se comunica con el SII.');
}

/** RUT normalizado (sin puntos, DV en mayúscula). */
function dte_demo_rut(string $r): string
{
    $r = strtoupper(preg_replace('/[^0-9kK]/', '', $r) ?? '');
    return strlen($r) >= 2 ? substr($r, 0, -1) . '-' . substr($r, -1) : '';
}

/**
 * CAF demo vigente para empresa+tipo. Si no hay (o se agotó o venció), crea uno nuevo
 * a continuación del último rango usado, igual que cargar un CAF real.
 */
function dte_demo_caf(mysqli $conex, int $empresaId, int $tipo): array
{
    $sql = "SELECT * FROM caf_empresa WHERE empresa_id=? AND tipo_dte=? AND ambiente='produccion' AND activo=1
             AND folio_actual<=folio_hasta AND " . dte_caf_sql_vigente() . " ORDER BY folio_desde LIMIT 1";
    $st = $conex->prepare($sql);
    $st->bind_param('ii', $empresaId, $tipo);
    $st->execute();
    $caf = $st->get_result()->fetch_assoc();
    $st->close();
    if ($caf) {
        return $caf;
    }
    $st = $conex->prepare("SELECT COALESCE(MAX(folio_hasta),0) FROM caf_empresa WHERE empresa_id=? AND tipo_dte=? AND ambiente='produccion'");
    $st->bind_param('ii', $empresaId, $tipo);
    $st->execute();
    $max = (int) ($st->get_result()->fetch_row()[0] ?? 0);
    $st->close();
    $desde = $max + 1;
    $hasta = $desde + DTE_DEMO_FOLIOS_POR_CAF - 1;
    $nombre = 'CAF DEMO ' . $tipo . ' (' . $desde . '-' . $hasta . ')';
    $st = $conex->prepare("INSERT INTO caf_empresa (empresa_id, tipo_dte, ambiente, folio_desde, folio_hasta, folio_actual, caf_xml, caf_nombre, fecha_autorizacion, activo)
                           VALUES (?,?,'produccion',?,?,?,NULL,?,CURDATE(),1)");
    $st->bind_param('iiiiis', $empresaId, $tipo, $desde, $hasta, $desde, $nombre);
    $st->execute();
    $id = (int) $st->insert_id;
    $st->close();
    return ['id' => $id, 'empresa_id' => $empresaId, 'tipo_dte' => $tipo, 'folio_desde' => $desde, 'folio_hasta' => $hasta, 'folio_actual' => $desde];
}

/**
 * Equivalente demo de dte_emitir(): mismas validaciones, totales y registro en BD
 * (dte_documentos, dte_documento_det, dte_documento_ref, sii_envios), mismo formato
 * de respuesta. El estado queda ENVIADO (aceptado simulado) con TrackID DEMO-….
 */
function dte_emitir_demo(mysqli $conex, array $cfgBase, array $p): array
{
    $empresaId = (int) ($p['empresa_id'] ?? 0);
    $tipo = (int) ($p['tipo'] ?? 0);
    if ($empresaId <= 0 || $tipo <= 0) {
        return ['ok' => false, 'error' => 'Falta empresa_id o tipo'];
    }
    if (!in_array($tipo, [33, 34, 39, 41, 56, 61], true)) {
        return ['ok' => false, 'error' => 'Tipo de documento no soportado en la demo: ' . $tipo];
    }
    $items = $p['items'] ?? [];
    if (!is_array($items) || !$items) {
        return ['ok' => false, 'error' => 'No hay items'];
    }

    $st = $conex->prepare("SELECT id, rut, razon_social FROM empresas WHERE id=? LIMIT 1");
    $st->bind_param('i', $empresaId);
    $st->execute();
    $empresa = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$empresa) {
        return ['ok' => false, 'error' => 'Empresa no existe'];
    }

    // Detalle y totales: misma regla que el motor real (precios NETOS, IVA 19% sobre afectos).
    $exentoTotal = in_array($tipo, [34, 41], true);
    $detalle = [];
    $neto = 0;
    $exento = 0;
    foreach ($items as $it) {
        $desc = trim((string) ($it['descripcion'] ?? ''));
        $cant = (float) ($it['cantidad'] ?? 0);
        $prec = (float) ($it['precio_unitario'] ?? $it['precio'] ?? 0);
        $exe = (int) ($it['exento'] ?? 0);
        if ($desc === '' || $cant <= 0 || $prec < 0) {
            return ['ok' => false, 'error' => 'Item inválido (descripción, cantidad o precio)'];
        }
        if ($exentoTotal) {
            $exe = 1;
        }
        $linea = (int) round($cant * $prec);
        if ($exe === 1) {
            $exento += $linea;
        } else {
            $neto += $linea;
        }
        $detalle[] = ['descripcion' => mb_substr($desc, 0, 255, 'UTF-8'), 'cantidad' => $cant, 'unidad' => (string) ($it['unidad'] ?? 'UN'),
            'precio' => $prec, 'exento' => $exe, 'linea' => $linea, 'producto_id' => (int) ($it['producto_id'] ?? 0)];
    }
    $iva = (int) round($neto * 0.19);
    $total = $neto + $iva + $exento;

    // Receptor: igual que el motor (paciente por id, o Consumidor Final en boletas).
    $rec = $p['receptor'] ?? [];
    $pacId = (int) ($p['paciente_id'] ?? 0);
    if ($pacId > 0 && empty($rec['rut'])) {
        $stc = $conex->prepare("SELECT rut, nombre FROM pacientes WHERE id=? LIMIT 1");
        $stc->bind_param('i', $pacId);
        $stc->execute();
        $cl = $stc->get_result()->fetch_assoc();
        $stc->close();
        if ($cl) {
            $rec = ['rut' => (string) $cl['rut'], 'razon_social' => (string) $cl['nombre']];
        }
    }
    $rutR = dte_demo_rut((string) ($rec['rut'] ?? ''));
    $razonR = trim((string) ($rec['razon_social'] ?? ''));
    if (in_array($tipo, [39, 41], true) && $rutR === '') {
        $rutR = '66666666-6';
        $razonR = $razonR !== '' ? $razonR : 'Consumidor Final';
    }
    if (in_array($tipo, [33, 34, 56, 61], true) && ($rutR === '' || $razonR === '')) {
        return ['ok' => false, 'error' => 'Las facturas y notas exigen RUT y razón social del receptor.'];
    }
    $referencias = is_array($p['referencias'] ?? null) ? $p['referencias'] : [];
    if (in_array($tipo, [56, 61], true) && !$referencias) {
        return ['ok' => false, 'error' => 'Las notas de crédito y débito deben referenciar el documento que corrigen.'];
    }

    $caf = dte_demo_caf($conex, $empresaId, $tipo);
    $folio = dte_reservar_folio($conex, (int) $caf['id']);
    if ($folio === null) {
        return ['ok' => false, 'error' => 'No se pudo reservar un folio demo. Intenta de nuevo.'];
    }

    $trackid = 'DEMO-' . date('ymd') . '-' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $estado = 'ENVIADO';
    $now = date('Y-m-d H:i:s');
    $fEmis = (string) ($p['fecha_emision'] ?? date('Y-m-d'));
    $fVenc = (string) ($p['fecha_vencimiento'] ?? $fEmis);
    $rutE = dte_demo_rut((string) $empresa['rut']);
    $cafId = (int) $caf['id'];
    $pacDb = $pacId > 0 ? $pacId : null;
    $obs = trim((string) ($p['observaciones'] ?? ''));
    $extra = ['demo' => true, 'forma_pago' => 'Contado'];
    if ($obs !== '') {
        $extra['observaciones'] = $obs;
    }
    $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE);

    $conex->begin_transaction();
    try {
        $st = $conex->prepare("INSERT INTO dte_documentos
            (empresa_id, tipo_dte, folio, ambiente, rut_emisor, rut_receptor, razon_receptor, paciente_id,
             fecha_emision, fecha_vencimiento, moneda, neto, exento, iva, total, estado, trackid, caf_empresa_id, datos_extra, created_at, updated_at)
            VALUES (?,?,?,'produccion',?,?,?,?,?,?,'CLP',?,?,?,?,?,?,?,?,?,?)");
        $st->bind_param('iiisssissiiiississs', $empresaId, $tipo, $folio, $rutE, $rutR, $razonR, $pacDb,
            $fEmis, $fVenc, $neto, $exento, $iva, $total, $estado, $trackid, $cafId, $extraJson, $now, $now);
        $st->execute();
        $docId = (int) $st->insert_id;
        $st->close();

        $st = $conex->prepare("INSERT INTO dte_documento_det (documento_id, producto_id, codigo, descripcion, cantidad, unidad, precio_unitario, descuento_monto, exento, total_linea)
                               VALUES (?,?,'',?,?,?,?,0,?,?)");
        foreach ($detalle as $d) {
            $pid = $d['producto_id'] > 0 ? $d['producto_id'] : null;
            $st->bind_param('iisdsdii', $docId, $pid, $d['descripcion'], $d['cantidad'], $d['unidad'], $d['precio'], $d['exento'], $d['linea']);
            $st->execute();
        }
        $st->close();

        if ($referencias) {
            $st = $conex->prepare("INSERT INTO dte_documento_ref (documento_id, tipo_doc, folio, fecha, codigo, razon) VALUES (?,?,?,?,?,?)");
            foreach ($referencias as $rf) {
                $td = (string) ($rf['tipo_doc'] ?? $rf['tipoDocumento'] ?? '');
                $fo = (string) ($rf['folio'] ?? $rf['folioReferencia'] ?? '');
                $fe = (string) ($rf['fecha'] ?? $rf['fechaDocumentoReferenciaString'] ?? $fEmis);
                $cd = (int) ($rf['codigo'] ?? $rf['codigoReferencia'] ?? 0);
                $rz = mb_substr((string) ($rf['razon'] ?? $rf['razonReferencia'] ?? ''), 0, 255, 'UTF-8');
                $st->bind_param('isssis', $docId, $td, $fo, $fe, $cd, $rz);
                $st->execute();
            }
            $st->close();
        }

        $lote = 'DEMO_' . date('Ymd_His');
        $resp = json_encode(['modo' => 'demo', 'mensaje' => 'Documento simulado: no se envió al SII.'], JSON_UNESCAPED_UNICODE);
        $vacio = '';
        $st = $conex->prepare("INSERT INTO sii_envios (doc_tipo, doc_id, rut_emisor, rut_receptor, folio, lote_id, trackid, estado, glosa_error, xml_dte, xml_envio, respuesta_sii, created_at, updated_at)
                               VALUES (?,?,?,?,?,?,?,?,NULL,?,?,?,?,?)");
        $tipoStr = (string) $tipo;
        $st->bind_param('sississssssss', $tipoStr, $docId, $rutE, $rutR, $folio, $lote, $trackid, $estado, $vacio, $vacio, $resp, $now, $now);
        $st->execute();
        $envId = (int) $st->insert_id;
        $st->close();

        $st = $conex->prepare("UPDATE dte_documentos SET sii_envio_id=? WHERE id=?");
        $st->bind_param('ii', $envId, $docId);
        $st->execute();
        $st->close();

        $conex->commit();
    } catch (Throwable $e) {
        $conex->rollback();
        dte_liberar_folio($conex, $cafId, $folio);
        return ['ok' => false, 'error' => 'Error guardando el documento demo: ' . $e->getMessage()];
    }

    return ['ok' => true, 'tipo' => $tipo, 'folio' => $folio, 'trackid' => $trackid, 'estado' => $estado, 'pendiente' => false,
        'neto' => $neto, 'iva' => $iva, 'exento' => $exento, 'total' => $total, 'documento_id' => $docId, 'error' => null,
        'moneda' => 'CLP', 'sii_envio_id' => $envId, 'glosa' => null, 'demo' => true];
}

/** Equivalente demo de dte_resync_estado(): no consulta al SII, informa el estado registrado. */
function dte_resync_estado_demo(mysqli $conex, int $docId): array
{
    $st = $conex->prepare("SELECT estado FROM dte_documentos WHERE id=? LIMIT 1");
    $st->bind_param('i', $docId);
    $st->execute();
    $doc = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$doc) {
        return ['ok' => false, 'error' => 'El documento no existe'];
    }
    return ['ok' => true, 'estado' => strtoupper((string) $doc['estado']), 'cambio' => false, 'glosa' => null,
        'mensaje' => 'Modo demostración: el documento no se envía al SII; su estado es simulado.'];
}

/** Equivalente demo de la solicitud de folios al SII: crea un CAF de prueba. */
function dte_folios_solicitar_demo(mysqli $conex, int $empresaId, int $tipo, int $cantidad, string $origen): array
{
    $cantidad = max(1, min(10000, $cantidad));
    $st = $conex->prepare("SELECT COALESCE(MAX(folio_hasta),0) FROM caf_empresa WHERE empresa_id=? AND tipo_dte=? AND ambiente='produccion'");
    $st->bind_param('ii', $empresaId, $tipo);
    $st->execute();
    $desde = (int) ($st->get_result()->fetch_row()[0] ?? 0) + 1;
    $st->close();
    $hasta = $desde + $cantidad - 1;
    $nombre = 'CAF DEMO ' . $tipo . ' (' . $desde . '-' . $hasta . ')';
    $st = $conex->prepare("INSERT INTO caf_empresa (empresa_id, tipo_dte, ambiente, folio_desde, folio_hasta, folio_actual, caf_xml, caf_nombre, fecha_autorizacion, activo)
                           VALUES (?,?,'produccion',?,?,?,NULL,?,CURDATE(),1)");
    $st->bind_param('iiiiis', $empresaId, $tipo, $desde, $hasta, $desde, $nombre);
    $st->execute();
    $st->close();
    $msg = "Modo demostración: se creó un CAF de prueba con los folios {$desde} a {$hasta} (no se pidió nada al SII).";
    if (function_exists('dte_folios_registrar')) {
        dte_folios_registrar($conex, $empresaId, $tipo, $cantidad, true, $msg, $origen, $desde, $hasta, null);
    }
    return ['ok' => true, 'mensaje' => $msg, 'desde' => $desde, 'hasta' => $hasta, 'maximo' => null, 'cantidad_excede' => false,
        'cuota' => ['limite' => 0, 'usadas' => 0, 'restantes' => 0]];
}

/** Lista de verificación de emisión en modo demo (reemplaza a la del SII real). */
function dte_chequeo_demo(array $empresa, int $tipo): array
{
    require_once APP_ROOT . '/lib/rut.php';
    $rut = (string) ($empresa['rut'] ?? '');
    $items = [
        ['ok' => true, 'label' => 'Modo demostración activo', 'detalle' => 'Los documentos se registran con folios de prueba y no se envían al SII.'],
        ['ok' => $rut !== '' && rutValido($rut), 'label' => 'RUT de la empresa válido', 'detalle' => $rut !== '' ? $rut : 'Complétalo en Empresas.'],
    ];
    $faltan = [];
    foreach (['razon_social' => 'razón social', 'giro' => 'giro', 'direccion' => 'dirección', 'comuna' => 'comuna'] as $k => $l) {
        if (trim((string) ($empresa[$k] ?? '')) === '') {
            $faltan[] = $l;
        }
    }
    $items[] = ['ok' => !$faltan, 'label' => 'Datos tributarios de la empresa', 'detalle' => $faltan ? 'Falta: ' . implode(', ', $faltan) . ' (en Empresas).' : 'Completos.'];
    $faltanLbl = array_values(array_map(fn($i) => $i['label'], array_filter($items, fn($i) => !$i['ok'])));
    return ['credenciales' => true, 'listo' => !$faltanLbl, 'items' => $items, 'faltan' => $faltanLbl, 'demo' => true];
}
