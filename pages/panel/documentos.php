<?php
/**
 * Panel · Documentos (misma lógica que «Documentos» de Trivoxa-DTE, con los colores de Espacio Luz).
 *
 *  1. Empresa emisora (selector).
 *  2. Tarjetas con TODOS los documentos tributarios que esa empresa puede emitir
 *     (según los tipos certificados ante el SII y sus folios CAF de producción).
 *  3. Documentos emitidos: boletas, facturas, notas de crédito y de débito, con su estado
 *     (Enviado / Rechazado / Anulado / Interno) y las acciones Ver, PDF y Anular.
 *
 * Anular (igual que Trivoxa-DTE):
 *  - Documento enviado al SII: NO se borra ni se marca anulado. Se emite una Nota de
 *    Crédito electrónica REAL (61) con referencia código 1 = «Anula documento», con su
 *    mismo detalle. El original sigue en el libro de ventas y la NC lo deja en cero.
 *  - Documento interno (sin timbre SII): se anula localmente con su motivo.
 */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/boletas.php';
require_once APP_ROOT . '/lib/empresas.php';
require_once APP_ROOT . '/lib/cobros.php';
require_once APP_ROOT . '/lib/clientes.php';
require_once APP_ROOT . '/lib/dte_integra.php';

$esAdmin = isAdmin();

/* ---------------------------------------------------------------- Empresas */
$empresas = empresasLista(false);
$factEmp = facturaEmpresa();
$factEmpId = $factEmp ? (int) $factEmp['id'] : 0;

$conex = null;
try {
    [$conex] = require APP_ROOT . '/dte/bootstrap.php';
} catch (Throwable $e) {
    $conex = null;
}

/** ¿La empresa ya emite al SII? (certificado + algún CAF de producción). */
$docsEnProduccion = function (int $empId) use ($conex): bool {
    if (!$conex) return false;
    try {
        if (!dte_empresa_tiene_cert($conex, $empId)) return false;
        $st = $conex->prepare("SELECT COUNT(*) FROM caf_empresa WHERE empresa_id=? AND ambiente='produccion'");
        $st->bind_param('i', $empId); $st->execute();
        $n = (int) ($st->get_result()->fetch_row()[0] ?? 0); $st->close();
        return $n > 0;
    } catch (Throwable $e) {
        return false;
    }
};

$cliFiltro = (int) ($_GET['cliente'] ?? 0);
$empId = (int) ($_GET['empresa'] ?? 0);
$empIds = array_map(fn($x) => (int) $x['id'], $empresas);
if (!in_array($empId, $empIds, true)) {
    $empId = 0;
    if ($cliFiltro > 0 && in_array($factEmpId, $empIds, true)) {
        $empId = $factEmpId; // las facturas a clientes las emite siempre la empresa de facturas
    }
    if (!$empId) {
        foreach ($empresas as $x) {
            if ($docsEnProduccion((int) $x['id'])) { $empId = (int) $x['id']; break; }
        }
    }
    if (!$empId && $empresas) $empId = (int) $empresas[0]['id'];
}
$emp = null;
foreach ($empresas as $x) {
    if ((int) $x['id'] === $empId) { $emp = $x; break; }
}
$empNombre = $emp ? ($emp['nombre_fantasia'] ?: $emp['razon_social']) : '';
$enProduccion = $emp ? $docsEnProduccion($empId) : false;
$tieneCert = ($emp && $conex) ? dte_empresa_tiene_cert($conex, $empId) : false;

/* ------------------------------------------- Tipos que la empresa puede emitir */
$NOMBRES = [33 => 'Factura Electrónica', 34 => 'Factura Exenta Electrónica', 39 => 'Boleta Electrónica', 41 => 'Boleta Exenta Electrónica',
            56 => 'Nota de Débito Electrónica', 61 => 'Nota de Crédito Electrónica'];
$PARA = [33 => 'A clientes', 34 => 'A clientes', 39 => 'A pacientes', 41 => 'A pacientes', 56 => 'Sobre un documento emitido', 61 => 'Sobre un documento emitido'];
$tipos = [];
if ($emp) {
    $esFactEmp = ($emp['tipo_documento'] ?? 'boleta') === 'factura' || $empId === $factEmpId;
    $esBolEmp = ($emp['tipo_documento'] ?? 'boleta') !== 'factura';
    $cand = array_merge($esFactEmp ? [33, 34] : [], $esBolEmp ? [39, 41] : [], [56, 61]);
    $cert = dteCertificados($emp);
    foreach ([33, 34, 39, 41, 56, 61] as $t) {
        if (in_array($t, $cand, true) && ($cert === null || in_array($t, $cert, true))) $tipos[] = $t;
    }
}
$folios = [];
if ($emp && $conex) {
    try {
        foreach (dte_tipos_disponibles($conex, $empId) as $d) $folios[(int) $d['tipo']] = (int) $d['disponibles'];
    } catch (Throwable $e) {
    }
}
// Estado de cada tarjeta: 'on' (SII, con folios), 'int' (documento interno, sin SII) u 'off'.
$cards = [];
foreach ($tipos as $t) {
    $chk = dteChequeoEmision($emp, $t);
    if (!$chk['credenciales']) {
        $cards[$t] = ['estado' => 'int', 'texto' => 'Interno · sin timbre SII', 'detalle' => 'La empresa aún no emite al SII: el documento queda registrado de forma interna.'];
    } elseif ($chk['listo']) {
        $n = $folios[$t] ?? 0;
        $cards[$t] = ['estado' => 'on', 'texto' => 'Disponible · ' . number_format($n, 0, ',', '.') . ' ' . ($n === 1 ? 'folio' : 'folios'), 'detalle' => ''];
    } else {
        $sinCaf = ($folios[$t] ?? 0) <= 0;
        $cards[$t] = ['estado' => 'off', 'texto' => $sinCaf ? 'No disponible · sin folios' : 'No disponible', 'detalle' => 'Falta: ' . implode('; ', array_map('mb_strtolower', $chk['faltan'])) . '.', 'sin_caf' => $sinCaf];
    }
}

/* ------------------------------------------------------- Documentos emitidos */
$codigoDoc = fn(array $b): int => ($b['tipo'] ?? 'boleta') === 'factura' ? ((int) $b['iva_clp'] > 0 ? 33 : 34) : ((int) $b['iva_clp'] > 0 ? 39 : 41);
$estadoSii = function (string $serie, string $sii): string {
    if ($serie !== 'sii') return 'interno';
    if ($sii === 'rechazada') return 'rechazado';
    if ($sii === 'pendiente') return 'pendiente';
    return 'enviado';
};

$boletas = $emp ? listBoletas(3000, $empId) : [];
$porId = [];
foreach ($boletas as $b) $porId[(int) $b['id']] = $b;

$ncs = []; $nds = [];
if ($emp) {
    try {
        $st = db()->prepare("SELECT n.*, (SELECT nd.folio FROM notas_debito nd WHERE nd.nota_credito_id = n.id AND nd.estado = 'emitida' LIMIT 1) AS anulada_por_nd
                               FROM notas_credito n WHERE n.empresa_id = ? ORDER BY n.id DESC");
        $st->execute([$empId]);
        $ncs = $st->fetchAll();
        $st = db()->prepare("SELECT d.*, nc.folio AS nc_folio FROM notas_debito d LEFT JOIN notas_credito nc ON nc.id = d.nota_credito_id WHERE d.empresa_id = ? ORDER BY d.id DESC");
        $st->execute([$empId]);
        $nds = $st->fetchAll();
    } catch (Throwable $e) {
    }
}
// Nota de crédito vigente sobre cada documento (prioriza la de anulación), como el «Anulada por NC N°» de Trivoxa-DTE.
$ncDe = [];
foreach (array_reverse($ncs) as $n) {
    if ($n['estado'] !== 'emitida' || ($n['sii_estado'] ?? '') === 'rechazada' || !empty($n['anulada_por_nd'])) continue;
    $k = (int) $n['documento_id'];
    $anula = $n['accion'] === 'anulacion';
    if (!isset($ncDe[$k]) || ($anula && !$ncDe[$k]['anula'])) {
        $ncDe[$k] = ['folio' => (int) $n['folio'], 'anula' => $anula, 'sii' => ($n['serie'] ?? '') === 'sii'];
    }
}

$filas = [];
foreach ($boletas as $b) {
    $id = (int) $b['id'];
    $serie = (string) ($b['serie'] ?? 'interna');
    $anulada = $b['estado'] === 'anulada';
    $est = $anulada ? 'anulado' : $estadoSii($serie, (string) ($b['sii_estado'] ?? 'no_enviada'));
    $sd = docSaldo($b);
    [$pgTxt, $pgCls] = docPagoEtiqueta($b);
    $cod = $codigoDoc($b);
    $nc = $ncDe[$id] ?? null;
    $filas[] = [
        'clase' => 'doc', 'id' => $id, 'tipo' => $cod, 'folio' => (int) $b['folio'], 'fecha' => (string) $b['fecha_emision'],
        'receptor' => (string) $b['receptor_nombre'], 'rut' => (string) ($b['receptor_rut'] ?? ''), 'cli' => (int) ($b['cliente_id'] ?? 0),
        'quien' => !empty($b['cliente_id']) ? 'Cliente' : (!empty($b['paciente_id']) ? 'Paciente' : ''),
        'total' => (int) $b['total_clp'], 'estado' => $est, 'serie' => $serie,
        'motivo' => $anulada ? (string) ($b['anulada_motivo'] ?? '') : '',
        'nc' => $nc, 'pago' => ($anulada || $est === 'rechazado') ? null : [$pgTxt, $pgCls],
        'ver' => base_path('/admin/boletas/' . $id),
        'pdf' => $esAdmin ? base_path('/admin/dte/pdf?boleta=' . $id . '&f=carta&dl=1') : null, // el PDF es solo para administradores
        // Se puede anular si sigue vigente: interno (anulación local) o en el SII con saldo (NC de anulación).
        'anulable' => $esAdmin && !$anulada && $est !== 'rechazado' && ($serie !== 'sii' || $sd['a_pagar'] > 0),
        'saldo_nc' => $sd['a_pagar'], 'con_notas' => $sd['nc'] > 0 || $sd['nd'] > 0,
    ];
}
foreach ($ncs as $n) {
    $p = $porId[(int) $n['documento_id']] ?? [];
    $serie = (string) ($n['serie'] ?? 'interna');
    $vale = $n['estado'] === 'emitida';
    $filas[] = [
        'clase' => 'nc', 'id' => (int) $n['id'], 'tipo' => 61, 'folio' => (int) $n['folio'], 'fecha' => (string) $n['fecha_emision'],
        'receptor' => (string) ($p['receptor_nombre'] ?? '—'), 'rut' => (string) ($p['receptor_rut'] ?? ''), 'cli' => (int) ($p['cliente_id'] ?? 0), 'quien' => '',
        'total' => (int) $n['total_clp'], 'estado' => $vale ? $estadoSii($serie, (string) ($n['sii_estado'] ?? 'no_enviada')) : 'anulado', 'serie' => $serie,
        'motivo' => (string) $n['motivo'], 'nc' => null, 'pago' => null,
        'ref' => ($p ? $NOMBRES[$codigoDoc($p)] . ' N° ' . cobroFolio((int) $p['folio']) : '') . ' · ' . ([1 => 'Anula', 2 => 'Corrige texto', 3 => 'Corrige montos'][(int) $n['codigo_ref']] ?? ''),
        'anulada_nd' => !empty($n['anulada_por_nd']) ? (int) $n['anulada_por_nd'] : 0,
        'ver' => base_path('/admin/notas/nc/' . (int) $n['id']),
        'pdf' => ($esAdmin && $serie === 'sii' && !empty($n['dte_documento_id'])) ? base_path('/admin/dte/pdf?doc=' . (int) $n['dte_documento_id'] . '&f=carta&dl=1') : null,
        'anulable' => false,
    ];
}
foreach ($nds as $n) {
    $p = $porId[(int) $n['documento_id']] ?? [];
    $serie = (string) ($n['serie'] ?? 'interna');
    $vale = $n['estado'] === 'emitida';
    $filas[] = [
        'clase' => 'nd', 'id' => (int) $n['id'], 'tipo' => 56, 'folio' => (int) $n['folio'], 'fecha' => (string) $n['fecha_emision'],
        'receptor' => (string) ($p['receptor_nombre'] ?? '—'), 'rut' => (string) ($p['receptor_rut'] ?? ''), 'cli' => (int) ($p['cliente_id'] ?? 0), 'quien' => '',
        'total' => (int) $n['total_clp'], 'estado' => $vale ? $estadoSii($serie, (string) ($n['sii_estado'] ?? 'no_enviada')) : 'anulado', 'serie' => $serie,
        'motivo' => (string) $n['motivo'], 'nc' => null, 'pago' => null,
        'ref' => ($p ? $NOMBRES[$codigoDoc($p)] . ' N° ' . cobroFolio((int) $p['folio']) : '') . ($n['accion'] === 'anula_nc' ? ' · Anula NC N° ' . cobroFolio((int) $n['nc_folio']) : ' · Cobro adicional'),
        'ver' => base_path('/admin/notas/nd/' . (int) $n['id']),
        'pdf' => ($esAdmin && $serie === 'sii' && !empty($n['dte_documento_id'])) ? base_path('/admin/dte/pdf?doc=' . (int) $n['dte_documento_id'] . '&f=carta&dl=1') : null,
        'anulable' => false,
    ];
}
usort($filas, fn($a, $b) => [$b['fecha'], $b['clase'] === 'doc' ? 0 : 1, $b['id']] <=> [$a['fecha'], $a['clase'] === 'doc' ? 0 : 1, $a['id']]);

// Documentos a los que se les puede emitir una nota (para las tarjetas 61 / 56).
$docsNota = [];
foreach ($boletas as $b) {
    if ($b['estado'] === 'anulada' || ($b['sii_estado'] ?? '') === 'rechazada') continue;
    if ($enProduccion && ($b['serie'] ?? '') !== 'sii') continue; // en el SII, las notas solo van sobre documentos del SII
    $docsNota[] = ['id' => (int) $b['id'], 'txt' => $NOMBRES[$codigoDoc($b)] . ' N° ' . cobroFolio((int) $b['folio']) . ' · ' . date('d-m-Y', strtotime((string) $b['fecha_emision'])) . ' · ' . $b['receptor_nombre'] . ' · ' . clp((int) $b['total_clp'])];
}

// ¿Se puede emitir hoy la NC de anulación (61) al SII? (certificado, CAF de 61 con folios…)
$nc61 = ['listo' => true, 'motivo' => '', 'folios' => $folios[61] ?? 0];
if ($enProduccion) {
    if (!in_array(61, $tipos, true)) {
        $nc61 = ['listo' => false, 'motivo' => $empNombre . ' no está certificada ante el SII para emitir notas de crédito (61).', 'folios' => 0];
    } else {
        $c61 = dteChequeoEmision($emp, 61);
        if (!$c61['listo']) $nc61 = ['listo' => false, 'motivo' => 'Para emitir la nota de crédito electrónica falta: ' . implode('; ', array_map('mb_strtolower', $c61['faltan'])) . '. Revísalo en Empresas y SII.', 'folios' => $folios[61] ?? 0];
    }
}

$cliNombre = '';
if ($cliFiltro > 0) {
    foreach ($filas as $f) {
        if ($f['cli'] === $cliFiltro) { $cliNombre = $f['receptor']; break; }
    }
    if ($cliNombre === '' && ($c = getCliente($cliFiltro))) $cliNombre = (string) $c['razon_social'];
}

/* ------------------------------- KPIs: total en boletas emitidas por cada socia */
// Todas las empresas. Neto = total - notas de credito vigentes + notas de debito; no cuenta rechazadas
// ni anuladas internamente. Se atribuye a la profesional de la boleta (boletas.profesional_id).
$MESES = ['01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril', '05' => 'Mayo', '06' => 'Junio',
          '07' => 'Julio', '08' => 'Agosto', '09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'];
$mesTxt = fn(string $m): string => ($MESES[substr($m, 5, 2)] ?? '') . ' ' . substr($m, 0, 4);
$mesHoy = date('Y-m');
$kpiMeses = [$mesHoy];
try {
    foreach (db()->query("SELECT DISTINCT DATE_FORMAT(fecha_emision, '%Y-%m') FROM boletas WHERE tipo = 'boleta'")->fetchAll(PDO::FETCH_COLUMN) as $m) {
        if (preg_match('/^\d{4}-\d{2}$/', (string) $m) && !in_array((string) $m, $kpiMeses, true)) $kpiMeses[] = (string) $m;
    }
} catch (Throwable $e) {
}
rsort($kpiMeses);
$kpiPer = (string) ($_GET['periodo'] ?? '');
if ($kpiPer !== 'todo' && !in_array($kpiPer, $kpiMeses, true)) $kpiPer = $mesHoy;

$empNom = [];
foreach ($empresas as $x) $empNom[(int) $x['id']] = (string) ($x['nombre_fantasia'] ?: $x['razon_social']);
$kpiSocias = [];
try {
    $cond = "b.tipo = 'boleta' AND b.estado = 'emitida' AND b.sii_estado <> 'rechazada'";
    $par = [];
    if ($kpiPer !== 'todo') {
        $cond .= " AND b.fecha_emision >= ? AND b.fecha_emision < ?";
        $par = [$kpiPer . '-01 00:00:00', date('Y-m-01', strtotime($kpiPer . '-01 +1 month')) . ' 00:00:00'];
    }
    $st = db()->prepare("SELECT p.id, p.nombre, MAX(es.nombre) AS especialidad, b.empresa_id,
                                COUNT(b.id) AS n, COALESCE(SUM(b.total_clp), 0) AS bruto, COALESCE(SUM(b.nc_clp), 0) AS nc,
                                COALESCE(SUM(b.nd_clp), 0) AS nd, COALESCE(SUM(b.nc_clp > 0 AND b.nc_clp >= b.total_clp + b.nd_clp), 0) AS anuladas
                           FROM profesionales p
                           LEFT JOIN especialidades es ON es.slug = p.especialidad_slug
                           LEFT JOIN boletas b ON b.profesional_id = p.id AND $cond
                          WHERE p.socia = 1 AND p.eliminado_at IS NULL
                          GROUP BY p.id, p.nombre, b.empresa_id
                          ORDER BY p.nombre");
    $st->execute($par);
    foreach ($st->fetchAll() as $r) {
        $pid = (int) $r['id'];
        $kpiSocias[$pid] ??= ['nombre' => (string) $r['nombre'], 'esp' => (string) ($r['especialidad'] ?? ''), 'n' => 0, 'neto' => 0, 'nc' => 0, 'anuladas' => 0, 'emp' => []];
        $neto = (int) $r['bruto'] - (int) $r['nc'] + (int) $r['nd'];
        $kpiSocias[$pid]['n'] += (int) $r['n'];
        $kpiSocias[$pid]['neto'] += $neto;
        $kpiSocias[$pid]['nc'] += (int) $r['nc'];
        $kpiSocias[$pid]['anuladas'] += (int) $r['anuladas'];
        if ($r['empresa_id'] !== null && (int) $r['n'] > 0) {
            $kpiSocias[$pid]['emp'][] = [$empNom[(int) $r['empresa_id']] ?? ('Empresa #' . (int) $r['empresa_id']), $neto];
        }
    }
} catch (Throwable $e) {
    $kpiSocias = [];
}
$kpiTotal = array_sum(array_map(fn($k) => max(0, $k['neto']), $kpiSocias));
$kpiIniciales = function (string $nombre): string {
    $p = preg_split('/\s+/u', trim($nombre)) ?: [];
    return mb_strtoupper(mb_substr($p[0] ?? '', 0, 1) . mb_substr($p[1] ?? '', 0, 1));
};

$ESTADOS = [
    'enviado'   => ['Enviado',   'bg-salvia/20 text-salvia-dark'],
    'pendiente' => ['Enviado',   'bg-salvia/20 text-salvia-dark'],
    'rechazado' => ['Rechazado', 'bg-red-100 text-red-700'],
    'anulado'   => ['Anulado',   'bg-cafe/10 text-cafe/70'],
    'interno'   => ['Interno',   'bg-crema-dark text-cafe/70'],
];
$ICON = [
    'factura' => '<path d="M6 2h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/><path d="M14 2v6h6M9 12h6M9 16h6"/>',
    'boleta'  => '<path d="M6 2h12v19l-2-1.4-2 1.4-2-1.4-2 1.4-2-1.4L6 21z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
    '61'      => '<path d="M6 2h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/><path d="M14 2v6h6M9 15h6"/>',
    '56'      => '<path d="M6 2h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/><path d="M14 2v6h6M12 12v6M9 15h6"/>',
];
$iconoTipo = fn(int $t): string => $ICON[in_array($t, [33, 34], true) ? 'factura' : (in_array($t, [39, 41], true) ? 'boleta' : (string) $t)];
$emitirUrl = function (int $t) use ($empId): string {
    if (in_array($t, [33, 34], true)) return base_path('/admin/facturas/nueva');
    return base_path('/admin/boletas/nueva?empresa=' . $empId . '&tipo=' . $t);
};
$selCls = 'w-full rounded-xl border border-cafe/20 bg-white px-4 py-2.5 font-body text-sm text-cafe outline-none focus:border-terracota focus:ring-2 focus:ring-terracota/15';

$panelTitle = 'Documentos';
$panelActive = 'boletas';
include APP_ROOT . '/partials/panel/top.php';
?>
<style>
    .doc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 158px), 1fr)); gap: 12px; }
    @media (min-width: 640px) { .doc-grid { grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 14px; } }
    .doc-card { display: flex; flex-direction: column; align-items: center; text-align: center; background: #fff; border: 1px solid rgba(92,64,51,.13); border-radius: 1rem; padding: 18px 14px 14px; box-shadow: 0 10px 30px -18px rgba(92,64,51,.25); transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease; }
    .doc-card.on:hover, .doc-card.int:hover { transform: translateY(-2px); border-color: rgba(217,119,87,.45); box-shadow: 0 16px 32px -18px rgba(217,119,87,.55); }
    .doc-card.off { background: rgba(251,249,244,.7); }
    .doc-card.off .doc-ic, .doc-card.off .doc-tt { opacity: .55; }
    .doc-ic { display: flex; height: 44px; width: 44px; align-items: center; justify-content: center; border-radius: 12px; background: rgba(217,119,87,.12); color: #B85C3E; }
    .doc-card.int .doc-ic { background: rgba(139,158,143,.18); color: #6D8072; }
    .doc-ic svg { height: 22px; width: 22px; }
    .doc-tt { margin-top: 10px; min-height: 2.5em; font-family: var(--font-heading); font-size: 15px; font-weight: 600; line-height: 1.2; color: #3D2A21; text-wrap: balance; }
    .doc-code { color: rgba(92,64,51,.45); font-family: var(--font-body); font-size: 12px; font-weight: 700; }
    .doc-st { margin-top: 6px; font-family: var(--font-body); font-size: 11.5px; font-weight: 700; }
    .doc-st.on { color: #6D8072; } .doc-st.int { color: rgba(92,64,51,.6); } .doc-st.off { color: rgba(92,64,51,.45); }
    .doc-btn { margin-top: 12px; display: inline-flex; width: 100%; align-items: center; justify-content: center; gap: 6px; border-radius: 9999px; padding: 9px 12px; font-family: var(--font-body); font-size: 13px; font-weight: 700; transition: background-color .15s ease, color .15s ease; }
    .doc-btn.on { background: #D97757; color: #FBF9F4; } .doc-btn.on:hover { background: #B85C3E; }
    .doc-btn.int { border: 1.5px solid rgba(109,128,114,.55); color: #6D8072; } .doc-btn.int:hover { background: #6D8072; color: #FBF9F4; }
    .doc-btn.off { border: 1.5px dashed rgba(92,64,51,.22); color: rgba(92,64,51,.45); cursor: not-allowed; }
    .doc-btn svg { height: 14px; width: 14px; }
    .ic-btn { display: inline-flex; height: 32px; width: 32px; align-items: center; justify-content: center; border-radius: 9px; color: rgba(92,64,51,.6); transition: background-color .15s ease, color .15s ease; }
    .ic-btn:hover { background: rgba(217,119,87,.12); color: #B85C3E; }
    .ic-btn.danger { color: #c2410c; } .ic-btn.danger:hover { background: #fee2e2; color: #b91c1c; }
    .ic-btn svg { height: 17px; width: 17px; }
    .doc-modal.hidden { display: none; }
    .doc-modal { position: fixed; inset: 0; z-index: 60; display: flex; align-items: flex-start; justify-content: center; overflow-y: auto; background: rgba(61,42,33,.45); padding: 5vh 16px; backdrop-filter: blur(2px); }
</style>

<p class="-mt-2 mb-5 font-body text-sm text-cafe/60">Emite cualquier documento tributario habilitado para la empresa según sus folios (CAF) de producción. Boletas a pacientes y facturas a clientes.</p>

<!-- KPIs: total en boletas emitidas por cada socia -->
<?php if ($kpiSocias): $ACC = [['bg-terracota/15 text-terracota-dark', 'bg-terracota'], ['bg-salvia/25 text-salvia-dark', 'bg-salvia-dark']]; $ki = 0; ?>
<section class="mb-7" aria-labelledby="kpi-tt">
    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h2 id="kpi-tt" class="font-heading text-lg font-semibold text-cafe">Boletas emitidas por profesional</h2>
            <p class="font-body text-xs text-cafe/55">Todas las empresas · total neto de notas de crédito · no incluye rechazadas por el SII.</p>
        </div>
        <form method="get" action="<?= e(base_path('/admin/documentos')) ?>">
            <input type="hidden" name="empresa" value="<?= $empId ?>">
            <?php if ($cliFiltro > 0): ?><input type="hidden" name="cliente" value="<?= $cliFiltro ?>"><?php endif; ?>
            <select name="periodo" class="rounded-xl border border-cafe/20 bg-white px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota" aria-label="Período" onchange="this.form.submit()">
                <?php foreach ($kpiMeses as $m): ?><option value="<?= e($m) ?>" <?= $m === $kpiPer ? 'selected' : '' ?>><?= e($mesTxt($m)) ?><?= $m === $mesHoy ? ' (este mes)' : '' ?></option><?php endforeach; ?>
                <option value="todo" <?= $kpiPer === 'todo' ? 'selected' : '' ?>>Todo el historial</option>
            </select>
        </form>
    </div>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <?php foreach ($kpiSocias as $k): [$accIc, $accBar] = $ACC[$ki++ % 2]; $pct = $kpiTotal > 0 ? (int) round(max(0, $k['neto']) * 100 / $kpiTotal) : 0; ?>
            <div class="rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/55"><?= e($k['nombre']) ?></p>
                        <p class="mt-2 font-heading text-3xl font-semibold tabular-nums text-cafe"><?= e(clp($k['neto'])) ?></p>
                        <p class="mt-1 font-body text-xs text-cafe/50">
                            <?= $k['n'] ?> <?= $k['n'] === 1 ? 'boleta emitida' : 'boletas emitidas' ?><?php if ($k['anuladas'] > 0): ?> · <?= $k['anuladas'] ?> <?= $k['anuladas'] === 1 ? 'anulada' : 'anuladas' ?> con NC<?php endif; ?><?php if ($k['nc'] > 0): ?> · NC <?= e(clp($k['nc'])) ?><?php endif; ?>
                        </p>
                    </div>
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl font-heading text-base font-semibold <?= $accIc ?>" title="<?= e($k['esp']) ?>"><?= e($kpiIniciales($k['nombre'])) ?></span>
                </div>
                <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-cafe/10" role="img" aria-label="<?= $pct ?>% del total en boletas del período"><div class="h-full rounded-full <?= $accBar ?>" style="width: <?= $pct ?>%"></div></div>
                <div class="mt-2 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 font-body text-[11px] text-cafe/55">
                    <span><?php if ($kpiTotal > 0): ?><strong class="tabular-nums text-cafe/75"><?= $pct ?>%</strong> del total<?php else: ?>Sin montos en el período<?php endif; ?><?= $k['esp'] !== '' ? ' · ' . e($k['esp']) : '' ?></span>
                    <?php if (count($k['emp']) > 0): ?><span class="tabular-nums"><?= implode(' · ', array_map(fn($x) => e($x[0]) . ' ' . e(clp($x[1])), $k['emp'])) ?></span><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- Empresa emisora -->
<form method="get" action="<?= e(base_path('/admin/documentos')) ?>" class="mb-4 max-w-md">
    <label class="flex flex-col gap-1.5">
        <span class="font-body text-xs font-bold uppercase tracking-wide text-cafe/65">Empresa emisora</span>
        <select name="empresa" class="<?= $selCls ?>" onchange="this.form.submit()">
            <?php if (!$empresas): ?><option value="0">— no hay empresas activas —</option><?php endif; ?>
            <?php foreach ($empresas as $x): ?>
                <option value="<?= (int) $x['id'] ?>" <?= (int) $x['id'] === $empId ? 'selected' : '' ?>><?= e($x['nombre_fantasia'] ?: $x['razon_social']) ?> · <?= e(formatoRut((string) $x['rut'])) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php if ($cliFiltro > 0): ?><input type="hidden" name="cliente" value="<?= $cliFiltro ?>"><?php endif; ?>
    <?php if ($kpiPer !== $mesHoy): ?><input type="hidden" name="periodo" value="<?= e($kpiPer) ?>"><?php endif; ?>
</form>

<?php if ($emp && !$enProduccion): ?>
    <div class="mb-4 rounded-xl border border-salvia/40 bg-salvia/10 px-4 py-3 font-body text-sm text-cafe/85">
        <strong class="text-salvia-dark"><?= e($empNombre) ?> aún no emite al SII</strong><?= $tieneCert ? ' (tiene certificado, pero no folios CAF de producción)' : ' (no tiene certificado digital ni folios CAF)' ?>:
        sus documentos se registran de forma <strong>interna</strong>, sin timbre electrónico.
        <?php if ($esAdmin): ?>Para emitir documentos reales, carga el certificado y los folios en <a href="<?= e(base_path('/admin/empresas')) ?>" class="font-semibold text-terracota-dark hover:underline">Empresas y SII</a>.<?php endif; ?>
    </div>
<?php endif; ?>

<!-- Documentos que la empresa puede emitir -->
<?php if ($emp): ?>
<div class="doc-grid mb-6">
    <?php foreach ($tipos as $t): $c = $cards[$t]; ?>
        <div class="doc-card <?= $c['estado'] ?>"<?= $c['detalle'] !== '' ? ' title="' . e($c['detalle']) . '"' : '' ?>>
            <div class="doc-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><?= $iconoTipo($t) ?></svg></div>
            <div class="doc-tt"><?= e($NOMBRES[$t]) ?> <span class="doc-code">(<?= $t ?>)</span></div>
            <div class="font-body text-[11px] text-cafe/45"><?= e($PARA[$t]) ?></div>
            <div class="doc-st <?= $c['estado'] ?>">
                <?php if ($c['estado'] === 'on'): ?>✓ <?php elseif ($c['estado'] === 'off'): ?>⊘ <?php endif; ?><?= e($c['texto']) ?>
            </div>
            <?php if (!$esAdmin): ?>
                <span class="doc-btn off" title="Solo un administrador emite documentos">Solo administradores</span>
            <?php elseif ($c['estado'] === 'off'): ?>
                <a href="<?= e(base_path('/admin/empresas')) ?>" class="doc-btn off" style="cursor:pointer" title="<?= e($c['detalle']) ?>"><?= !empty($c['sin_caf']) ? 'Sin CAF · cargar folios' : 'Ver qué falta' ?></a>
            <?php elseif (in_array($t, [56, 61], true)): ?>
                <button type="button" class="doc-btn <?= $c['estado'] ?>" data-nota="<?= $t ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg> Emitir</button>
            <?php else: ?>
                <a href="<?= e($emitirUrl($t)) ?>" class="doc-btn <?= $c['estado'] ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg> Emitir</a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php if (!$tipos): ?>
    <p class="mb-6 font-body text-sm text-amber-800">Esta empresa no tiene documentos habilitados. Revisa los tipos certificados en <a href="<?= e(base_path('/admin/empresas')) ?>" class="font-semibold underline">Empresas y SII</a>.</p>
<?php endif; ?>
<?php endif; ?>

<!-- Documentos emitidos -->
<section class="ez-tabla-card">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-cafe/10 px-4 py-4 sm:px-5">
        <h2 class="flex items-center gap-2 font-heading text-lg font-semibold text-cafe">
            <svg class="h-5 w-5 text-terracota" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 3 7.5l9 4.5 9-4.5z"/><path d="m3 12 9 4.5 9-4.5M3 16.5 12 21l9-4.5"/></svg>
            Documentos emitidos <span id="doc-n" class="font-body text-xs font-semibold text-cafe/45"></span>
        </h2>
        <div class="flex flex-wrap items-center gap-2">
            <?php if ($cliFiltro > 0): ?>
                <a href="<?= e(base_path('/admin/documentos?empresa=' . $empId)) ?>" class="inline-flex items-center gap-1.5 rounded-full bg-terracota/12 px-3 py-1.5 font-body text-xs font-semibold text-terracota-dark hover:bg-terracota/20" title="Quitar el filtro de cliente">Cliente: <?= e($cliNombre ?: '#' . $cliFiltro) ?> ✕</a>
            <?php endif; ?>
            <select id="f-tipo" class="rounded-xl border border-cafe/20 bg-white px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota" aria-label="Tipo de documento">
                <option value="">Todos los tipos</option>
                <?php foreach ($NOMBRES as $t => $nom): ?><option value="<?= $t ?>"><?= e($nom) ?> (<?= $t ?>)</option><?php endforeach; ?>
            </select>
            <select id="f-estado" class="rounded-xl border border-cafe/20 bg-white px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota" aria-label="Estado">
                <option value="">Todos los estados</option>
                <option value="enviado">Enviados al SII</option>
                <option value="anulado">Anulados</option>
                <option value="rechazado">Rechazados</option>
                <option value="interno">Internos</option>
            </select>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="ez-tabla">
            <thead>
                <tr>
                    <th class="px-4 py-3">Tipo</th>
                    <th class="px-3 py-3 text-center">Folio</th>
                    <th class="px-3 py-3">Fecha</th>
                    <th class="px-3 py-3">Receptor</th>
                    <th class="px-3 py-3 text-right">Total</th>
                    <th class="px-3 py-3">Estado</th>
                    <th class="px-4 py-3 text-right">Acción</th>
                </tr>
            </thead>
            <tbody id="doc-tbody" class="font-body text-sm text-cafe/85">
            <?php foreach ($filas as $f):
                [$eTx, $eCl] = $ESTADOS[$f['estado']];
                $acc = $f['estado'] === 'anulado' || $f['estado'] === 'rechazado' ? 'acc-off' : (in_array($f['tipo'], [33, 34], true) ? 'acc-terracota' : (in_array($f['tipo'], [56, 61], true) ? 'acc-cafe' : '')); ?>
                <tr class="doc-row <?= $acc ?>" data-tipo="<?= $f['tipo'] ?>" data-estado="<?= $f['estado'] === 'pendiente' ? 'enviado' : $f['estado'] ?>" data-cli="<?= $f['cli'] ?>">
                    <td class="px-4 py-3 leading-snug">
                        <span class="font-semibold text-cafe"><?= e($NOMBRES[$f['tipo']]) ?></span> <span class="text-xs font-semibold text-cafe/45">(<?= $f['tipo'] ?>)</span>
                        <?php if (!empty($f['ref'])): ?><div class="text-[11px] text-cafe/50">Ref. <?= e($f['ref']) ?></div><?php endif; ?>
                    </td>
                    <td class="px-3 py-3 text-center font-semibold tabular-nums text-cafe whitespace-nowrap"><?= e(cobroFolio($f['folio'])) ?></td>
                    <td class="px-3 py-3 tabular-nums text-cafe/70 whitespace-nowrap"><?= e(date('d-m-Y', strtotime($f['fecha']))) ?></td>
                    <td class="px-3 py-3 leading-snug"><?= e($f['receptor']) ?><div class="text-[11px] text-cafe/50"><?= e(trim(($f['rut'] !== '' ? $f['rut'] : '') . ($f['quien'] !== '' ? ($f['rut'] !== '' ? ' · ' : '') . $f['quien'] : ''))) ?></div></td>
                    <td class="px-3 py-3 text-right whitespace-nowrap">
                        <span class="font-semibold tabular-nums text-cafe"><?= $f['clase'] === 'nc' && $f['total'] > 0 ? '− ' : '' ?><?= e(clp($f['total'])) ?></span>
                    </td>
                    <td class="px-3 py-3">
                        <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-bold <?= $eCl ?>"<?= $f['motivo'] !== '' && $f['estado'] === 'anulado' ? ' title="Motivo: ' . e($f['motivo']) . '"' : '' ?>><?= e($eTx) ?></span>
                        <?php if ($f['estado'] === 'pendiente'): ?>
                            <div class="mt-1 flex items-center gap-1"><span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800">SII pendiente</span>
                            <?php if ($esAdmin && $f['clase'] === 'doc'): ?><button type="button" class="sii-recheck rounded border border-cafe/15 px-1 text-[10px] font-semibold text-cafe/55 hover:border-terracota hover:text-terracota" data-id="<?= $f['id'] ?>" title="Re-consultar estado en el SII">↻</button><?php endif; ?></div>
                        <?php endif; ?>
                        <?php if ($f['nc']): ?>
                            <div class="mt-1"><span class="inline-block whitespace-nowrap rounded-full bg-terracota/15 px-2 py-0.5 text-[10.5px] font-bold text-terracota-dark" title="Nota de crédito folio <?= (int) $f['nc']['folio'] ?>"><?= $f['nc']['anula'] ? 'Anulada por NC' : 'Con NC' ?> N° <?= e(cobroFolio($f['nc']['folio'])) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($f['anulada_nd'])): ?>
                            <div class="mt-1"><span class="inline-block whitespace-nowrap rounded-full bg-amber-100 px-2 py-0.5 text-[10.5px] font-bold text-amber-800">Anulada por ND N° <?= e(cobroFolio($f['anulada_nd'])) ?></span></div>
                        <?php endif; ?>
                        <?php if ($f['estado'] === 'anulado' && $f['motivo'] !== '' && $f['clase'] === 'doc'): ?><div class="mt-0.5 max-w-[14rem] truncate text-[11px] text-cafe/50" title="<?= e($f['motivo']) ?>">Motivo: <?= e($f['motivo']) ?></div><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a class="ic-btn doc-ver" title="Ver" href="<?= e($f['ver']) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></a>
                        <?php if ($f['pdf']): ?><a class="ic-btn" title="Descargar PDF" href="<?= e($f['pdf']) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/><path d="M14 2v6h6M12 11v6M9.5 14.5 12 17l2.5-2.5"/></svg></a><?php else: ?><span class="inline-block w-8"></span><?php endif; ?>
                        <?php if ($f['anulable']): ?>
                            <button type="button" class="ic-btn danger doc-anular" title="Anular documento"
                                data-id="<?= $f['id'] ?>" data-sii="<?= $f['serie'] === 'sii' ? '1' : '0' ?>" data-nombre="<?= e($NOMBRES[$f['tipo']]) ?>" data-tipo="<?= $f['tipo'] ?>"
                                data-folio="<?= e(cobroFolio($f['folio'])) ?>" data-receptor="<?= e($f['receptor']) ?>" data-rut="<?= e($f['rut']) ?>" data-fecha="<?= e(date('d-m-Y', strtotime($f['fecha']))) ?>"
                                data-total="<?= $f['total'] ?>" data-saldo="<?= (int) $f['saldo_nc'] ?>" data-notas="<?= $f['con_notas'] ? '1' : '0' ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 2h8.2L22 7.9v8.2L16.1 22H7.9L2 16.1V7.9z"/><path d="m15 9-6 6M9 9l6 6"/></svg>
                            </button>
                        <?php else: ?><span class="inline-block w-8"></span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
                <tr id="doc-vacio" class="<?= $filas ? 'hidden' : '' ?>"><td colspan="7" class="px-5 py-12 text-center text-cafe/45">Sin documentos emitidos<span id="doc-vacio-f" class="hidden"> con ese filtro</span> todavía.</td></tr>
            </tbody>
        </table>
    </div>
</section>

<!-- Ver documento: se abre aquí mismo, con todos sus datos y opciones -->
<div id="ver-modal" class="doc-modal hidden" role="dialog" aria-modal="true" aria-labelledby="ver-tt" style="padding-top:3vh;padding-bottom:3vh">
    <div class="flex w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-cafe/15 bg-[#F3EEE4] shadow-warm" style="height:94vh">
        <div class="flex items-center justify-between gap-3 border-b border-cafe/10 bg-crema-light px-4 py-2.5 sm:px-5">
            <h3 id="ver-tt" class="min-w-0 truncate font-heading text-lg font-semibold text-cafe">Documento</h3>
            <button type="button" data-cerrar class="inline-flex shrink-0 items-center gap-1.5 rounded-full border-2 border-cafe/15 px-3 py-1.5 font-body text-sm font-semibold text-cafe hover:border-terracota hover:text-terracota">✕ Cerrar</button>
        </div>
        <div class="relative min-h-0 flex-1">
            <p id="ver-cargando" class="absolute inset-0 flex items-center justify-center font-body text-sm text-cafe/50">Cargando…</p>
            <iframe id="ver-frame" title="Documento" class="relative h-full w-full border-0"></iframe>
        </div>
    </div>
</div>

<?php if ($esAdmin): ?>
<!-- Anular documento (misma lógica que Trivoxa-DTE) -->
<div id="an-modal" class="doc-modal hidden" role="dialog" aria-modal="true" aria-labelledby="an-tt">
    <form id="an-form" class="w-full max-w-lg rounded-2xl border border-cafe/10 bg-crema-light p-5 shadow-warm sm:p-6">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="font-body text-[11px] font-bold uppercase tracking-[0.14em] text-terracota-dark">Anular documento</p>
                <h3 id="an-tt" class="font-heading text-xl font-semibold text-cafe"></h3>
            </div>
            <button type="button" data-cerrar class="rounded-lg p-1 text-cafe/50 hover:bg-cafe/5 hover:text-cafe" aria-label="Cerrar">✕</button>
        </div>
        <dl class="mt-3 grid grid-cols-2 gap-2 rounded-xl bg-white px-4 py-3 font-body text-sm">
            <div class="col-span-2"><dt class="text-[11px] font-semibold uppercase text-cafe/50">Receptor</dt><dd id="an-rec" class="font-semibold text-cafe"></dd></div>
            <div><dt class="text-[11px] font-semibold uppercase text-cafe/50">Fecha</dt><dd id="an-fecha" class="tabular-nums text-cafe"></dd></div>
            <div><dt class="text-[11px] font-semibold uppercase text-cafe/50">Total</dt><dd id="an-total" class="font-semibold tabular-nums text-cafe"></dd></div>
        </dl>
        <div id="an-sii" class="mt-4 hidden">
            <h4 class="font-heading text-base font-semibold text-cafe">Dejar sin efecto con una nota de crédito</h4>
            <p class="mt-1 font-body text-sm text-cafe/75">Se emitirá una <strong>nota de crédito electrónica real (61)</strong> al SII, con referencia código 1 = <em>anula documento</em>, <span id="an-alcance">por el total del documento y con su mismo detalle</span>.</p>
            <p class="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 font-body text-xs text-amber-900">El documento original <strong>seguirá apareciendo en el libro de ventas</strong>: fue emitido legalmente y la nota de crédito es la que lo resta, dejando el neto en cero. Así lo exige el SII. Consume un folio de nota de crédito<?= $nc61['folios'] > 0 ? ' (quedan ' . (int) $nc61['folios'] . ')' : '' ?> y no se puede deshacer.</p>
            <?php if (!$nc61['listo']): ?><p class="mt-2 rounded-xl border border-red-200 bg-red-50 px-3 py-2 font-body text-xs text-red-800" id="an-bloqueo"><?= e($nc61['motivo']) ?></p><?php endif; ?>
        </div>
        <div id="an-int" class="mt-4 hidden">
            <h4 class="font-heading text-base font-semibold text-cafe">Anular documento interno</h4>
            <p class="mt-1 font-body text-sm text-cafe/75">Este documento no fue enviado al SII: se marca como <strong>anulado</strong> con el motivo indicado, deja de contar como ingreso y sus horas o sesiones quedan libres para volver a cobrarse.</p>
        </div>
        <label class="mt-4 flex flex-col gap-1">
            <span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Motivo *</span>
            <input name="motivo" maxlength="300" required class="rounded-lg border border-cafe/20 bg-white px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota">
        </label>
        <p id="an-err" class="mt-2 hidden font-body text-sm font-medium text-red-600"></p>
        <div class="mt-5 flex flex-wrap justify-end gap-2">
            <button type="button" data-cerrar class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-cafe/30">Cancelar</button>
            <button type="submit" id="an-ok" class="rounded-full bg-red-600 px-5 py-2 font-body text-sm font-semibold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"></button>
        </div>
    </form>
</div>

<!-- Nota de crédito / débito: elegir el documento de referencia -->
<div id="nt-modal" class="doc-modal hidden" role="dialog" aria-modal="true" aria-labelledby="nt-tt">
    <form id="nt-form" class="w-full max-w-lg rounded-2xl border border-cafe/10 bg-crema-light p-5 shadow-warm sm:p-6">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="font-body text-[11px] font-bold uppercase tracking-[0.14em] text-terracota-dark">Documento de referencia</p>
                <h3 id="nt-tt" class="font-heading text-xl font-semibold text-cafe"></h3>
            </div>
            <button type="button" data-cerrar class="rounded-lg p-1 text-cafe/50 hover:bg-cafe/5 hover:text-cafe" aria-label="Cerrar">✕</button>
        </div>
        <p class="mt-2 font-body text-sm text-cafe/70">Una nota siempre corrige un documento ya emitido. Elige cuál; se abrirá el documento con la nota lista para completar.</p>
        <?php if ($docsNota): ?>
            <label class="mt-3 flex flex-col gap-1">
                <span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Documento emitido a referenciar</span>
                <select name="doc" class="rounded-lg border border-cafe/20 bg-white px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota">
                    <?php foreach ($docsNota as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['txt']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" data-cerrar class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-cafe/30">Cancelar</button>
                <button type="submit" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Continuar</button>
            </div>
        <?php else: ?>
            <p class="mt-3 rounded-xl border border-dashed border-cafe/20 bg-white px-4 py-3 font-body text-sm text-cafe/60">Aún no hay documentos emitidos<?= $enProduccion ? ' al SII' : '' ?> por <?= e($empNombre) ?> sobre los que emitir una nota.</p>
            <div class="mt-5 flex justify-end"><button type="button" data-cerrar class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe">Cerrar</button></div>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var P = window.__PANEL__ || {};
    var CLI = <?= $cliFiltro ?>;
    var NC_LISTO = <?= $nc61['listo'] ? 'true' : 'false' ?>;
    var rows = Array.prototype.slice.call(document.querySelectorAll('#doc-tbody tr.doc-row'));
    var fTipo = document.getElementById('f-tipo'), fEstado = document.getElementById('f-estado');
    function clp(n) { return '$' + (parseInt(n, 10) || 0).toLocaleString('es-CL'); }

    // Filtro instantáneo por tipo y estado (y por cliente si se llegó desde su ficha).
    function aplicar() {
        var n = 0;
        rows.forEach(function (tr) {
            var ok = (!fTipo.value || tr.dataset.tipo === fTipo.value) && (!fEstado.value || tr.dataset.estado === fEstado.value) && (!CLI || tr.dataset.cli === String(CLI));
            tr.classList.toggle('hidden', !ok);
            if (ok) n++;
        });
        document.getElementById('doc-vacio').classList.toggle('hidden', n > 0);
        document.getElementById('doc-vacio-f').classList.toggle('hidden', !(fTipo.value || fEstado.value || CLI));
        document.getElementById('doc-n').textContent = rows.length ? '· ' + n + (n === 1 ? ' documento' : ' documentos') : '';
    }
    fTipo.addEventListener('change', aplicar);
    fEstado.addEventListener('change', aplicar);
    aplicar();

    function post(d) {
        return fetch(P.base + '/api/panel/boletas', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf }, body: JSON.stringify(d) })
            .then(function (r) { return r.text().then(function (t) { var j = {}; try { j = JSON.parse(t); } catch (e) { j = { error: 'Respuesta inesperada del servidor.' }; } return { ok: r.ok && !!j.ok, d: j }; }); })
            .catch(function () { return { ok: false, d: { error: 'No se pudo conectar con el servidor.' } }; });
    }

    // Re-consultar el estado SII de un documento pendiente.
    document.querySelectorAll('.sii-recheck').forEach(function (b) {
        b.addEventListener('click', function () {
            b.textContent = '…'; b.disabled = true;
            post({ action: 'sii_recheck', id: b.dataset.id }).then(function (x) {
                if (x.ok) { location.reload(); return; }
                ezAlert(x.d.error || 'No se pudo consultar el SII.'); b.textContent = '↻'; b.disabled = false;
            });
        });
    });

    function modal(id) {
        var m = document.getElementById(id);
        if (!m) return null;
        m.querySelectorAll('[data-cerrar]').forEach(function (b) { b.addEventListener('click', function () { m.classList.add('hidden'); }); });
        m.addEventListener('click', function (e) { if (e.target === m) m.classList.add('hidden'); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') m.classList.add('hidden'); });
        return m;
    }

    // ---- Anular (documento SII → nota de crédito 61 código 1; interno → anulación local) ----
    var am = modal('an-modal'), af = document.getElementById('an-form'), actual = null;
    document.querySelectorAll('.doc-anular').forEach(function (b) {
        b.addEventListener('click', function () {
            var d = b.dataset, sii = d.sii === '1';
            actual = d;
            document.getElementById('an-tt').textContent = d.nombre + ' (' + d.tipo + ') · Folio ' + d.folio;
            document.getElementById('an-rec').textContent = d.receptor + (d.rut ? ' · ' + d.rut : '');
            document.getElementById('an-fecha').textContent = d.fecha;
            document.getElementById('an-total').textContent = clp(d.total);
            document.getElementById('an-sii').classList.toggle('hidden', !sii);
            document.getElementById('an-int').classList.toggle('hidden', sii);
            document.getElementById('an-alcance').textContent = d.notas === '1'
                ? 'por lo que queda del documento (' + clp(d.saldo) + '), porque ya tiene otras notas'
                : 'por el total del documento y con su mismo detalle';
            af.motivo.value = 'Anula ' + d.nombre + ' folio ' + d.folio;
            var ok = document.getElementById('an-ok');
            ok.textContent = sii ? 'Emitir nota de crédito' : 'Anular documento';
            ok.disabled = sii && !NC_LISTO;
            document.getElementById('an-err').classList.add('hidden');
            am.classList.remove('hidden');
            af.motivo.focus(); af.motivo.select();
        });
    });
    if (af) af.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!actual) return;
        var sii = actual.sii === '1', motivo = af.motivo.value.trim(), err = document.getElementById('an-err');
        if (!motivo) { err.textContent = 'Indica el motivo de la anulación.'; err.classList.remove('hidden'); return; }
        var txt = sii
            ? 'Se emitirá una NOTA DE CRÉDITO ELECTRÓNICA REAL al SII por ' + actual.nombre + ' folio ' + actual.folio + '.\n\nConsume un folio y no se puede deshacer. ¿Continuar?'
            : '¿Anular ' + actual.nombre + ' folio ' + actual.folio + '? Deja de contar como ingreso y sus horas quedan libres.';
        ezConfirm(txt, { title: sii ? 'Emitir nota de crédito' : 'Anular documento', okText: sii ? 'Emitir NC' : 'Anular', danger: true }).then(function (ok) {
            if (!ok) return;
            var btn = document.getElementById('an-ok'), orig = btn.textContent;
            btn.disabled = true; btn.textContent = sii ? 'Enviando al SII…' : 'Anulando…';
            var req = sii ? { action: 'nc_emitir', id: actual.id, accion: 'anulacion', motivo: motivo } : { action: 'anular', id: actual.id, motivo: motivo };
            var esp = sii && window.ezEmision ? ezEmision.enviando({ titulo: 'Emitiendo nota de crédito…', texto: 'Preparando la nota de anulación y enviándola al SII. No cierres esta página.' }) : null;
            post(req).then(function (x) {
                if (esp) esp.cerrar();
                if (!x.ok) { btn.disabled = false; btn.textContent = orig; err.textContent = x.d.error || 'No se pudo anular.'; err.classList.remove('hidden'); return; }
                am.classList.add('hidden');
                if (sii && window.ezEmision) {
                    var d = x.d, nota = P.base + '/admin/notas/nc/' + d.id;
                    ezEmision.listo({
                        titulo: ezEmision.nombre(61) + ' N° ' + String(d.folio).padStart(6, '0') + ' emitida',
                        subtitulo: 'Anula ' + actual.nombre + ' N° ' + actual.folio + ' · ' + clp(d.total),
                        estado: d.sii_estado || 'aceptada',
                        trackid: d.trackid || '',
                        ver: { texto: 'Ver nota de crédito', accion: function () { vForzar = true; verAbrir(nota); } },
                        listo: { texto: 'Listo', accion: function () { location.reload(); } },
                        revisar: function () {
                            return post({ action: 'nota_reconsultar', clase: 'nc', nota_id: d.id, rapido: 1 })
                                .then(function (y) { return y.ok ? { estado: y.d.estado, mensaje: y.d.mensaje } : { estado: 'pendiente' }; });
                        }
                    });
                    return;
                }
                var msg = 'Documento anulado.' + (x.d.horas_liberadas ? ' ' + x.d.horas_liberadas + ' hora(s) quedaron libres para volver a cobrarse.' : '') + (x.d.devolver ? ' Se habían pagado ' + clp(x.d.devolver) + ': registra la devolución en el documento cuando la hagas.' : '');
                ezAlert(msg, { title: 'Documento anulado' }).then(function () { location.reload(); });
            });
        });
    });

    // ---- Ver: el documento se abre dentro de esta página (con su cobro, notas, PDF, impresión…) ----
    var vm = modal('ver-modal'), vf = document.getElementById('ver-frame'), vCarga = document.getElementById('ver-cargando'), vLoads = 0, vForzar = false;
    function verCerrar() {
        vm.classList.add('hidden');
        vf.removeAttribute('src');
        if (vLoads > 1 || vForzar) location.reload(); // algo cambió (pago, nota, anulación…): se refresca la lista
    }
    vm.querySelectorAll('[data-cerrar]').forEach(function (b) { b.addEventListener('click', verCerrar); });
    vm.addEventListener('click', function (e) { if (e.target === vm) verCerrar(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !vm.classList.contains('hidden')) verCerrar(); });
    window.addEventListener('message', function (e) {
        if (e.origin !== location.origin || !e.data || !e.data.ezDoc) return;
        if (e.data.ezDoc === 'cargado') { vLoads++; vCarga.classList.add('hidden'); if (e.data.titulo) document.getElementById('ver-tt').textContent = e.data.titulo; }
        if (e.data.ezDoc === 'cerrar') verCerrar();
    });
    function verAbrir(href) {
        var u = new URL(href, location.href);
        u.searchParams.set('embed', '1');
        vLoads = 0;
        vCarga.classList.remove('hidden');
        document.getElementById('ver-tt').textContent = 'Documento';
        vf.src = u.toString();
        vm.classList.remove('hidden');
    }
    document.querySelectorAll('a.doc-ver').forEach(function (a) {
        a.addEventListener('click', function (e) {
            if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return; // abrir en otra pestaña sigue disponible
            e.preventDefault();
            verAbrir(a.href);
        });
    });

    // ---- Nota de crédito / débito desde las tarjetas: elegir el documento a corregir ----
    var nm = modal('nt-modal'), nf = document.getElementById('nt-form'), notaTipo = 61;
    document.querySelectorAll('[data-nota]').forEach(function (b) {
        b.addEventListener('click', function () {
            notaTipo = parseInt(b.dataset.nota, 10);
            document.getElementById('nt-tt').textContent = notaTipo === 61 ? 'Emitir nota de crédito (61)' : 'Emitir nota de débito (56)';
            nm.classList.remove('hidden');
        });
    });
    if (nf) nf.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!nf.doc) return;
        nm.classList.add('hidden');
        verAbrir(P.base + '/admin/boletas/' + nf.doc.value + '?nota=' + (notaTipo === 61 ? 'nc' : 'nd') + '#cobro');
    });
});
</script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
