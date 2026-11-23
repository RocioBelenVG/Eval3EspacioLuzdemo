<?php
/**
 * PDF de un documento INTERNO (tabla boletas) en los 3 formatos, reusando el
 * renderizador de EspacioLuz (dte/lib/dte_pdf.php). Sin timbre SII: lleva la
 * nota de "pendiente de timbrado". Se usa para los documentos de prueba/MVP.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/boletas.php';     // getBoleta, emisorDeBoleta, medioPagoLabel
require_once APP_ROOT . '/lib/pacientes.php';    // getPaciente
require_once APP_ROOT . '/dte/lib/dte_pdf.php'; // dte_pdf_render

/** Tipo de DTE (39/41/33/34) de un documento interno según empresa + ítems. */
function boleta_tipo_dte(array $b): int
{
    $esFactura = ($b['tipo'] ?? 'boleta') === 'factura';
    $todoExento = true;
    foreach ($b['items'] ?? [] as $it) {
        if (!empty($it['afecto'])) { $todoExento = false; break; }
    }
    if ($esFactura) return $todoExento ? 34 : 33;
    return $todoExento ? 41 : 39;
}

/**
 * Arma el arreglo normalizado del documento ($v) desde una fila de boletas.
 * Lo usan tanto el PDF (boleta_pdf_bytes) como la vista previa HTML.
 */
function boleta_doc_v(array $b): array
{
    $em = emisorDeBoleta($b);
    $esFactura = ($b['tipo'] ?? 'boleta') === 'factura';
    // Ficha del paciente: respaldo para documentos antiguos sin los datos guardados.
    $cli = !empty($b['paciente_id']) ? getPaciente((int)$b['paciente_id']) : null;
    // Factura: el receptor es un CLIENTE (nunca un paciente).
    $cliente = null;
    if ($esFactura && !empty($b['cliente_id'])) {
        require_once APP_ROOT . '/lib/clientes.php';
        $cliente = getCliente((int)$b['cliente_id']);
    }
    $giroRec = trim((string)($b['receptor_giro'] ?? '')) ?: trim((string)($cliente['giro'] ?? ''));
    $tipo = boleta_tipo_dte($b);

    $items = [];
    foreach ($b['items'] ?? [] as $it) {
        $items[] = [
            'descripcion' => $it['descripcion'],
            'cantidad'    => (int)$it['cantidad'],
            'precio'      => (int)$it['precio_unit_clp'],
            'total'       => (int)$it['total_clp'],
            'exento'      => empty($it['afecto']) ? 1 : 0,
        ];
    }

    $emNombre = $em['razon_social'] ?? $em['nombre'];
    $loc = trim((string)($em['comuna'] ?? ''));
    // "Consumidor Final" solo si NO hay nombre de paciente.
    $esCF = (trim((string)($b['receptor_nombre'] ?? '')) === '');
    // Dirección / comuna / cód. prestación: primero lo GUARDADO en el documento; si no, la ficha.
    $dirTxt = trim((string)($b['receptor_direccion'] ?? '')) ?: trim((string)($cli['direccion'] ?? ''));
    $comTxt = trim((string)($b['receptor_comuna'] ?? '')) ?: trim((string)($cli['comuna'] ?? ''));
    $codTxt = $esFactura ? '' : (trim((string)($b['receptor_codigo_prestacion'] ?? '')) ?: trim((string)($cli['codigo_prestacion'] ?? '')));
    $rdir = trim($dirTxt . ($comTxt !== '' ? ($dirTxt !== '' ? ', ' : '') . $comTxt : ''));
    $profNom = trim((string)($b['profesional_nombre'] ?? ''));
    $profRut = trim((string)($b['profesional_rut'] ?? ''));
    if ($profRut !== '' && function_exists('formatoRut')) $profRut = formatoRut($profRut);
    $ts = strtotime((string)$b['fecha_emision']);
    $tituloLower = $esFactura ? 'factura' : 'boleta';
    $mvp = 'Documento interno emitido por ' . htmlspecialchars((string)$emNombre, ENT_QUOTES, 'UTF-8')
         . '. <strong>Pendiente de timbrado electrónico ante el SII</strong> — se convertirá en '
         . $tituloLower . ' electrónica válida al conectar la emisión con el SII.';

    return [
        'tipo'  => $tipo,
        'folio' => str_pad((string)$b['folio'], 6, '0', STR_PAD_LEFT),
        'fecha' => $ts ? date('d-m-Y H:i', $ts) : (string)$b['fecha_emision'],
        'emisor' => [
            'nombre' => $emNombre, 'fantasia' => $em['nombre'] ?? '', 'rut' => $em['rut'] ?? '',
            'giro' => $em['giro'] ?? '', 'acteco' => $em['actividad_economica'] ?? '',
            'dir' => $em['direccion'] ?? '', 'loc' => $loc, 'email' => $em['email'] ?? '',
        ],
        'receptor' => [
            'es_cf' => $esCF, 'nombre' => $b['receptor_nombre'] ?? '', 'rut' => $b['receptor_rut'] ?? '',
            'giro' => $esFactura ? $giroRec : '', 'dir' => $rdir, 'email' => $b['receptor_email'] ?? '',
            'cod_prestacion' => $codTxt,
            'es_tutor' => ($b['receptor_tipo'] ?? '') === 'tutor',
            'paciente' => trim((string)($cli['nombre'] ?? '')), 'paciente_rut' => trim((string)($cli['rut'] ?? '')),
        ],
        'profesional' => ['nombre' => $profNom, 'rut' => $profRut],
        'medio' => medioPagoLabel((string)$b['medio_pago']),
        'emitida_por' => $b['emisor_nombre'] ?? '',
        'trackid' => '',
        'items' => $items,
        'neto' => (int)$b['neto_clp'], 'iva' => (int)$b['iva_clp'], 'exento' => (int)$b['exento_clp'], 'total' => (int)$b['total_clp'],
        'observaciones' => (string)($b['glosa'] ?? ''),
        'timbre_uri' => '', 'res_linea' => '', 'mvp_note' => $mvp,
    ];
}

/**
 * Genera el PDF de un documento interno MVP en el formato pedido.
 * @return array{filename:string, bytes:string}|null
 */
function boleta_pdf_bytes(array $b, string $formato = 'carta'): ?array
{
    if (empty($b['id'])) return null;
    return dte_pdf_render(boleta_doc_v($b), $formato);
}
