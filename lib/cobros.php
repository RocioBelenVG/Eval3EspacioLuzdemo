<?php
/**
 * Cobros conectados: cada hora del calendario sabe cómo se cobró y cada documento sabe si está pagado.
 *
 *  Pacientes → Boletas : la sesión (reserva con paciente) queda unida a su boleta (reservas.boleta_id).
 *  Clientes  → Facturas: la hora de box queda unida a su factura (reservas.factura_id), por hora o
 *                        incluida en un plan mensual (reservas.factura_plan = 1, sin monto propio).
 *
 * Casos reales cubiertos:
 *  - Cancelar una hora ya cobrada: se decide «devolver» (nota de crédito por esa hora y queda libre)
 *    o «mantener el cobro» (cancelación tardía: sigue cobrada, sin devolución).
 *  - Traspasar una hora facturada a otro profesional: «ceder» (sigue pagada por quien la arrendó)
 *    o «cobrársela al nuevo» (nota de crédito al anterior y queda por facturar al nuevo).
 *    La boleta de un paciente siempre acompaña a su sesión.
 *  - Reagendar: cambia fecha/hora/box y conserva su cobro.
 *  - Anular un documento interno: deja de contar como ingreso y libera sus horas/sesiones.
 *  - Pagos: cada documento tiene pagos (y devoluciones); su estado se recalcula solo.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';
require_once APP_ROOT . '/lib/panel.php';
require_once APP_ROOT . '/lib/boletas.php';

/** Columnas de cada tipo de cobro en reservas: [documento, neto, afecto]. */
function cobroCols(string $tipo): array
{
    return $tipo === 'boleta' ? ['boleta_id', 'boleta_neto', 'boleta_afecto'] : ['factura_id', 'cobro_neto', 'cobro_afecto'];
}

const COBRO_MEDIOS = ['transferencia' => 'Transferencia', 'efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'otro' => 'Otro'];

function cobroUsuario(): ?string
{
    return isset($_SESSION['admin_nombre']) ? mb_substr((string) $_SESSION['admin_nombre'], 0, 160, 'UTF-8') : null;
}

function cobroFolio(int $folio): string
{
    return str_pad((string) $folio, 6, '0', STR_PAD_LEFT);
}

function cobroFechaHora(array $r): string
{
    $ts = strtotime((string) $r['fecha']);
    return date('d-m-Y', $ts) . (!empty($r['hora']) ? ' ' . substr((string) $r['hora'], 0, 5) : '');
}

/* ===================================================================== Pagos */

/** Recalcula pagado, notas de crédito y estado de pago de un documento. */
function docRecalcularPago(int $docId): void
{
    try {
        $pdo = db();
        $b = $pdo->prepare("SELECT total_clp FROM boletas WHERE id = ?");
        $b->execute([$docId]);
        $total = (int) $b->fetchColumn();
        $p = $pdo->prepare("SELECT COALESCE(SUM(monto),0), MAX(fecha) FROM documento_pagos WHERE documento_id = ?");
        $p->execute([$docId]);
        [$pagado, $ultimo] = $p->fetch(PDO::FETCH_NUM);
        $n = $pdo->prepare("SELECT COALESCE(SUM(total_clp),0) FROM notas_credito WHERE documento_id = ? AND estado = 'emitida'");
        $n->execute([$docId]);
        $nc = (int) $n->fetchColumn();
        $nd = 0;
        try {
            $n = $pdo->prepare("SELECT COALESCE(SUM(total_clp),0) FROM notas_debito WHERE documento_id = ? AND estado = 'emitida'");
            $n->execute([$docId]);
            $nd = (int) $n->fetchColumn();
        } catch (Throwable $e) {
        }
        $aPagar = max(0, $total - $nc + $nd);
        $pagado = (int) $pagado;
        $estado = ($aPagar <= 0 || $pagado >= $aPagar) ? 'pagado' : ($pagado > 0 ? 'parcial' : 'pendiente');
        $pdo->prepare("UPDATE boletas SET pagado_clp = ?, nc_clp = ?, nd_clp = ?, pago_estado = ?, pagado_en = ? WHERE id = ?")
            ->execute([$pagado, $nc, $nd, $estado, $estado === 'pagado' ? ($ultimo ? $ultimo . ' 00:00:00' : date('Y-m-d H:i:s')) : null, $docId]);
    } catch (Throwable $e) {
        error_log('[cobros recalcular] ' . $e->getMessage());
    }
}

/** Montos de un documento: total, notas de crédito y débito, a pagar, pagado, saldo, a favor, vencida. */
function docSaldo(array $b): array
{
    $total = (int) $b['total_clp'];
    $nc = (int) ($b['nc_clp'] ?? 0);
    $nd = (int) ($b['nd_clp'] ?? 0);
    $aPagar = max(0, $total - $nc + $nd);
    $pagado = (int) ($b['pagado_clp'] ?? 0);
    $anulada = ($b['estado'] ?? '') === 'anulada';
    $saldo = $anulada ? 0 : max(0, $aPagar - $pagado);
    $aFavor = $anulada ? max(0, $pagado) : max(0, $pagado - $aPagar);
    $vence = (string) ($b['vence_en'] ?? '');
    return [
        'total' => $total, 'nc' => $nc, 'nd' => $nd, 'a_pagar' => $aPagar, 'pagado' => $pagado, 'saldo' => $saldo, 'a_favor' => $aFavor,
        'vence' => $vence !== '' ? $vence : null, 'vencida' => !$anulada && $saldo > 0 && $vence !== '' && $vence < date('Y-m-d'),
    ];
}

/** Etiqueta del estado de pago: ['texto','clase']. */
function docPagoEtiqueta(array $b): array
{
    if (($b['estado'] ?? '') === 'anulada') {
        return ['Anulada', 'bg-red-100 text-red-700'];
    }
    $s = docSaldo($b);
    if ($s['a_favor'] > 0) {
        return ['Devolver ' . clp($s['a_favor']), 'bg-amber-100 text-amber-800'];
    }
    if ($s['saldo'] <= 0) {
        return [$s['a_pagar'] <= 0 ? 'Saldada con NC' : 'Pagada', 'bg-salvia/20 text-salvia-dark'];
    }
    if ($s['vencida']) {
        return ['Vencida · ' . clp($s['saldo']), 'bg-red-100 text-red-700'];
    }
    return [($s['pagado'] > 0 ? 'Abono · debe ' : 'Por cobrar · ') . clp($s['saldo']), 'bg-terracota/15 text-terracota-dark'];
}

function docPagos(int $docId): array
{
    try {
        $st = db()->prepare("SELECT * FROM documento_pagos WHERE documento_id = ? ORDER BY fecha, id");
        $st->execute([$docId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Registra un pago (o una devolución) en un documento.
 * $d: monto (>0), tipo ('pago'|'devolucion'), fecha (Y-m-d), medio, referencia.
 */
function registrarPago(int $docId, array $d): array
{
    $b = getBoleta($docId);
    if (!$b) {
        return ['ok' => false, 'error' => 'Documento no encontrado.'];
    }
    $monto = (int) preg_replace('/\D/', '', (string) ($d['monto'] ?? '0'));
    if ($monto <= 0) {
        return ['ok' => false, 'error' => 'Ingresa un monto mayor a cero.'];
    }
    $devol = ($d['tipo'] ?? '') === 'devolucion';
    $s = docSaldo($b);
    if ($devol) {
        if ($monto > max(0, $s['pagado'])) {
            return ['ok' => false, 'error' => 'No se puede devolver más de lo pagado (' . clp($s['pagado']) . ').'];
        }
    } else {
        if ($b['estado'] === 'anulada') {
            return ['ok' => false, 'error' => 'El documento está anulado: no recibe pagos.'];
        }
        if ($monto > $s['saldo']) {
            return ['ok' => false, 'error' => 'El pago supera lo que se debe (' . clp($s['saldo']) . ').'];
        }
    }
    $fecha = (string) ($d['fecha'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        $fecha = date('Y-m-d');
    }
    $medio = array_key_exists((string) ($d['medio'] ?? ''), COBRO_MEDIOS) ? (string) $d['medio'] : 'transferencia';
    try {
        db()->prepare("INSERT INTO documento_pagos (documento_id, fecha, monto, medio, referencia, usuario_nombre, creado_en) VALUES (?,?,?,?,?,?,?)")
            ->execute([$docId, $fecha, $devol ? -$monto : $monto, $medio, mb_substr(trim((string) ($d['referencia'] ?? '')), 0, 160, 'UTF-8') ?: null, cobroUsuario(), date('Y-m-d H:i:s')]);
        docRecalcularPago($docId);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo registrar el pago.'];
    }
}

function eliminarPago(int $pagoId): array
{
    try {
        $st = db()->prepare("SELECT documento_id FROM documento_pagos WHERE id = ?");
        $st->execute([$pagoId]);
        $doc = (int) $st->fetchColumn();
        if (!$doc) {
            return ['ok' => false, 'error' => 'El pago ya no existe.'];
        }
        db()->prepare("DELETE FROM documento_pagos WHERE id = ?")->execute([$pagoId]);
        docRecalcularPago($doc);
        return ['ok' => true, 'documento_id' => $doc];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo eliminar el pago.'];
    }
}

/**
 * Tras emitir un documento: registra el pago al contado (o lo deja por cobrar con vencimiento).
 * Las boletas a pacientes se pagan al emitir; las facturas pueden quedar por cobrar.
 */
function cobroTrasEmitir(int $docId, bool $pagado, string $medio, ?string $vence = null): void
{
    $b = getBoleta($docId);
    if (!$b) {
        return;
    }
    try {
        if ($vence !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $vence)) {
            db()->prepare("UPDATE boletas SET vence_en = ? WHERE id = ?")->execute([$vence, $docId]);
        }
        if ($pagado && (int) $b['total_clp'] > 0) {
            db()->prepare("INSERT INTO documento_pagos (documento_id, fecha, monto, medio, referencia, usuario_nombre, creado_en) VALUES (?,?,?,?,?,?,?)")
                ->execute([$docId, date('Y-m-d'), (int) $b['total_clp'], array_key_exists($medio, COBRO_MEDIOS) ? $medio : 'otro', 'Pago al emitir', cobroUsuario(), date('Y-m-d H:i:s')]);
        }
    } catch (Throwable $e) {
        error_log('[cobros tras emitir] ' . $e->getMessage());
    }
    docRecalcularPago($docId);
}

/* ============================================ Horas/sesiones ↔ documentos */

/**
 * Une horas/sesiones a un documento recién emitido.
 * $tipo 'boleta' (sesiones de paciente) o 'factura' (horas de box). $neto: neto unitario cobrado.
 */
function cobroVincular(int $docId, string $tipo, array $ids, int $neto, bool $afecto, bool $plan = false): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    if (!$ids) {
        return 0;
    }
    [$col, $cNeto, $cAfecto] = cobroCols($tipo);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE reservas SET $col = ?, $cNeto = ?, $cAfecto = ?, cobro_mantenido = 0"
         . ($tipo === 'factura' ? ', factura_plan = ' . ($plan ? '1' : '0') : '')
         . " WHERE id IN ($in) AND $col IS NULL";
    $st = db()->prepare($sql);
    $st->execute(array_merge([$docId, $plan ? 0 : $neto, $afecto ? 1 : 0], $ids));
    return $st->rowCount();
}

/** Libera las horas/sesiones unidas a un documento (al anularlo). */
function cobroLiberarDocumento(int $docId): int
{
    $n = 0;
    foreach (['boleta', 'factura'] as $t) {
        [$col, $cNeto, $cAfecto] = cobroCols($t);
        $st = db()->prepare("UPDATE reservas SET $col = NULL, $cNeto = NULL, $cAfecto = NULL, cobro_mantenido = 0" . ($t === 'factura' ? ', factura_plan = 0' : '') . " WHERE $col = ?");
        $st->execute([$docId]);
        $n += $st->rowCount();
    }
    return $n;
}

/** Reservas con los datos de sus documentos (boleta y factura). */
function reservasCobro(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare(
        "SELECT r.*, fb.folio AS factura_folio, fb.receptor_nombre AS factura_receptor, fb.cliente_id AS factura_cliente, fb.pago_estado AS factura_pago,
                fb.serie AS factura_serie, bb.folio AS boleta_folio, bb.receptor_nombre AS boleta_receptor, bb.serie AS boleta_serie
           FROM reservas r
           LEFT JOIN boletas fb ON fb.id = r.factura_id
           LEFT JOIN boletas bb ON bb.id = r.boleta_id
          WHERE r.id IN ($in) ORDER BY r.fecha, r.hora"
    );
    $st->execute($ids);
    return $st->fetchAll();
}

/**
 * Estado de cobro de una reserva (para calendario, fichas y listas).
 * Devuelve ['sesion' => [...]|null, 'arriendo' => [...]] donde cada uno es ['clave','texto','clase','doc_id','folio'].
 */
function cobroEstadoReserva(array $r): array
{
    $cancel = ($r['estado'] ?? '') === 'cancelada';
    $out = ['sesion' => null, 'arriendo' => null];
    if (!empty($r['paciente_id'])) {
        if (!empty($r['boleta_id'])) {
            $f = cobroFolio((int) ($r['boleta_folio'] ?? 0));
            $out['sesion'] = $cancel
                ? ['clave' => 'mantenido', 'texto' => 'Cancelada · cobro mantenido (Boleta N° ' . $f . ')', 'clase' => 'bg-amber-100 text-amber-800', 'doc_id' => (int) $r['boleta_id'], 'folio' => $f]
                : ['clave' => 'pagada', 'texto' => 'Pagada · Boleta N° ' . $f, 'clase' => 'bg-salvia/20 text-salvia-dark', 'doc_id' => (int) $r['boleta_id'], 'folio' => $f];
        } elseif ($cancel) {
            $out['sesion'] = ['clave' => 'no_cobra', 'texto' => 'Cancelada · no se cobra', 'clase' => 'bg-cafe/10 text-cafe/55', 'doc_id' => 0, 'folio' => ''];
        } elseif (profesionalesSocias() && !esSocia(!empty($r['profesional_id']) ? (int) $r['profesional_id'] : null)) {
            // Paciente de una profesional que arrienda box: la sesión la cobra ella, no el centro.
            $out['sesion'] = ['clave' => 'externo', 'texto' => 'La cobra ' . (!empty($r['profesional_id']) ? nombreProfesional((int) $r['profesional_id']) : 'la profesional') . ' (arrienda box)', 'clase' => 'bg-cafe/10 text-cafe/60', 'doc_id' => 0, 'folio' => ''];
        } else {
            $out['sesion'] = ['clave' => 'por_cobrar', 'texto' => 'Por cobrar', 'clase' => 'bg-terracota/15 text-terracota-dark', 'doc_id' => 0, 'folio' => ''];
        }
    }
    if (!empty($r['factura_id'])) {
        $f = cobroFolio((int) ($r['factura_folio'] ?? 0));
        if (!empty($r['factura_plan'])) {
            $out['arriendo'] = ['clave' => 'plan', 'texto' => 'En plan mensual · Factura N° ' . $f, 'clase' => 'bg-salvia/20 text-salvia-dark', 'doc_id' => (int) $r['factura_id'], 'folio' => $f];
        } elseif ($cancel) {
            $out['arriendo'] = ['clave' => 'mantenido', 'texto' => 'Cancelada · cobro mantenido (Factura N° ' . $f . ')', 'clase' => 'bg-amber-100 text-amber-800', 'doc_id' => (int) $r['factura_id'], 'folio' => $f];
        } else {
            $pag = ($r['factura_pago'] ?? '') === 'pagado';
            $out['arriendo'] = ['clave' => $pag ? 'pagada' : 'facturada', 'texto' => ($pag ? 'Facturada y pagada' : 'Facturada · por pagar') . ' · N° ' . $f, 'clase' => $pag ? 'bg-salvia/20 text-salvia-dark' : 'bg-amber-100 text-amber-800', 'doc_id' => (int) $r['factura_id'], 'folio' => $f];
        }
    } elseif (!empty($r['profesional_id']) && esSocia((int) $r['profesional_id'])) {
        $out['arriendo'] = ['clave' => 'socia', 'texto' => 'Socia · no paga arriendo', 'clase' => 'bg-salvia/20 text-salvia-dark', 'doc_id' => 0, 'folio' => ''];
    } elseif (!empty($r['profesional_id'])) {
        $out['arriendo'] = $cancel
            ? ['clave' => 'no_cobra', 'texto' => 'Cancelada · no se cobra', 'clase' => 'bg-cafe/10 text-cafe/55', 'doc_id' => 0, 'folio' => '']
            : ['clave' => 'por_facturar', 'texto' => 'Por facturar', 'clase' => 'bg-terracota/15 text-terracota-dark', 'doc_id' => 0, 'folio' => ''];
    }
    return $out;
}

/* ===================================================== Impacto y decisiones */

/**
 * ¿Qué cobros toca cancelar o traspasar estas horas?
 * $operacion: 'cancelar' (boletas y facturas) | 'traspasar' (solo facturas: la boleta acompaña a la sesión).
 * Devuelve ['requiere' => bool, 'docs' => [[doc_id,tipo,folio,receptor,horas,ids,monto,plan]], 'texto' => '...'].
 */
function cobroImpacto(array $ids, string $operacion): array
{
    $docs = [];
    foreach (reservasCobro($ids) as $r) {
        if ($r['estado'] === 'cancelada' && $operacion === 'cancelar') {
            continue;
        }
        $links = [];
        if ($operacion === 'cancelar' && !empty($r['boleta_id'])) {
            $links[] = ['boleta', (int) $r['boleta_id'], (int) $r['boleta_folio'], (string) $r['boleta_receptor'], false];
        }
        if (!empty($r['factura_id'])) {
            $links[] = ['factura', (int) $r['factura_id'], (int) $r['factura_folio'], (string) $r['factura_receptor'], !empty($r['factura_plan'])];
        }
        if ($operacion === 'cancelar' && !empty($r['cobro_mantenido'])) {
            continue;
        }
        foreach ($links as [$tipo, $docId, $folio, $receptor, $plan]) {
            if ($plan) {
                continue; // incluida en un plan mensual: no tiene monto propio que devolver
            }
            $k = $docId;
            if (!isset($docs[$k])) {
                $docs[$k] = ['doc_id' => $docId, 'tipo' => $tipo, 'folio' => cobroFolio($folio), 'receptor' => $receptor, 'horas' => 0, 'ids' => [], 'monto' => 0];
            }
            [, $cNeto, $cAfecto] = cobroCols($tipo);
            $neto = (int) ($r[$cNeto] ?? 0);
            $docs[$k]['horas']++;
            $docs[$k]['ids'][] = (int) $r['id'];
            $docs[$k]['monto'] += !empty($r[$cAfecto]) ? $neto + (int) round($neto * IVA_TASA_COBRO) : $neto;
        }
    }
    $docs = array_values($docs);
    $partes = array_map(fn($d) => $d['horas'] . ($d['horas'] === 1 ? ' hora' : ' horas') . ' en la ' . ($d['tipo'] === 'boleta' ? 'boleta' : 'factura') . ' N° ' . $d['folio'] . ' (' . $d['receptor'] . ', ' . clp($d['monto']) . ')', $docs);
    return ['requiere' => count($docs) > 0, 'docs' => $docs, 'texto' => implode('; ', $partes)];
}

const IVA_TASA_COBRO = 0.19;

/**
 * Aplica la decisión de cobro ANTES de cancelar o traspasar.
 *  cancelar:  'nota_credito' (devuelve: NC por esas horas y quedan libres) | 'mantener' (sigue cobrada)
 *  traspasar: 'ceder' (sigue pagada por quien la arrendó) | 'recobrar' (NC al anterior; queda por facturar al nuevo)
 */
function cobroAplicar(array $ids, string $operacion, string $accion, string $motivo = ''): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    if (!$ids) {
        return ['ok' => true, 'notas' => []];
    }
    $validas = $operacion === 'cancelar' ? ['nota_credito', 'mantener'] : ['ceder', 'recobrar'];
    if (!in_array($accion, $validas, true)) {
        return ['ok' => false, 'error' => 'Elige qué hacer con el cobro de esas horas.'];
    }
    $imp = cobroImpacto($ids, $operacion);
    $ncs = [];
    if ($accion === 'mantener') {
        $in = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("UPDATE reservas SET cobro_mantenido = 1 WHERE id IN ($in) AND (boleta_id IS NOT NULL OR (factura_id IS NOT NULL AND factura_plan = 0))")->execute($ids);
    } elseif ($accion === 'nota_credito' || $accion === 'recobrar') {
        foreach ($imp['docs'] as $d) {
            $r = emitirNotaCredito($d['doc_id'], [
                'accion' => 'devolucion',
                'motivo' => $motivo !== '' ? $motivo : ($operacion === 'cancelar' ? 'Horas canceladas' : 'Horas traspasadas a otro profesional'),
                'reserva_ids' => $d['ids'],
            ]);
            if (!$r['ok']) {
                return $r;
            }
            $ncs[] = $r;
        }
        if ($operacion === 'traspasar') {
            // Las horas de plan también dejan de pertenecer al cliente anterior.
            $in = implode(',', array_fill(0, count($ids), '?'));
            db()->prepare("UPDATE reservas SET factura_id = NULL, factura_plan = 0, cobro_neto = NULL, cobro_afecto = NULL WHERE id IN ($in) AND factura_plan = 1")->execute($ids);
        }
    }
    foreach ($ids as $rid) {
        $txt = ['nota_credito' => 'Cobro devuelto con nota de crédito', 'mantener' => 'Cobro mantenido (sin devolución)', 'ceder' => 'Hora cedida: sigue pagada por quien la arrendó', 'recobrar' => 'Se devolvió al anterior; queda por facturar al nuevo'][$accion];
        registrarMovimientoReserva((int) $rid, 'cobro', null, null, null, $txt);
    }
    return ['ok' => true, 'notas' => $ncs];
}

/* ========================================================= Notas de crédito y débito */

/**
 * ¿Cómo se emite una nota (61 crédito / 56 débito) sobre este documento?
 *  - Documento interno (demo / sin timbrar)  → nota interna (folio propio).
 *  - Documento enviado al SII                → nota electrónica REAL al SII, solo si la empresa
 *    está certificada para ese tipo y todo está listo (certificado, CAF, API key…).
 * Devuelve ['ok'=>true,'modo'=>'interna'|'sii','empresa'=>?,'dte'=>?] o ['ok'=>false,'error'].
 */
function cobroModoNota(array $b, int $tipoNota): array
{
    if (($b['serie'] ?? 'interna') !== 'sii') {
        return ['ok' => true, 'modo' => 'interna', 'empresa' => null, 'dte' => null];
    }
    require_once APP_ROOT . '/lib/dte_integra.php';
    require_once APP_ROOT . '/lib/empresas.php';
    $nombre = $tipoNota === 56 ? 'la nota de débito' : 'la nota de crédito';
    $emp = getEmpresa((int) $b['empresa_id']);
    if (!$emp) {
        return ['ok' => false, 'error' => 'No se encontró la empresa emisora del documento.'];
    }
    $dte = dteDocDeBoleta((int) $b['id']);
    if (!$dte) {
        return ['ok' => false, 'error' => 'No se encontró el documento electrónico (DTE) de este documento: no se puede referenciar en ' . $nombre . '.'];
    }
    if (!dteEmpresaCertificada($emp, $tipoNota)) {
        return ['ok' => false, 'error' => ($emp['nombre_fantasia'] ?: $emp['razon_social']) . ' no está certificada ante el SII para emitir ' . dteNombreCorto($tipoNota) . ' (' . $tipoNota . ').'];
    }
    $chk = dteChequeoEmision($emp, $tipoNota);
    if (!$chk['listo']) {
        return ['ok' => false, 'error' => 'Para emitir ' . $nombre . ' electrónica falta: ' . implode('; ', array_map('mb_strtolower', $chk['faltan'])) . '. Revísalo en Empresas y SII.'];
    }
    return ['ok' => true, 'modo' => 'sii', 'empresa' => $emp, 'dte' => $dte];
}

/** Receptor de una nota electrónica: el mismo del documento que corrige. */
function cobroReceptorNota(array $b): array
{
    $rec = [
        'rut'          => trim((string) ($b['receptor_rut'] ?? '')),
        'razon_social' => trim((string) ($b['receptor_nombre'] ?? '')),
        'giro'         => trim((string) ($b['receptor_giro'] ?? '')),
        'direccion'    => trim((string) ($b['receptor_direccion'] ?? '')),
        'comuna'       => trim((string) ($b['receptor_comuna'] ?? '')),
        'contacto'     => trim((string) ($b['receptor_email'] ?? '')),
    ];
    if (!empty($b['cliente_id'])) {
        require_once APP_ROOT . '/lib/clientes.php';
        $c = getCliente((int) $b['cliente_id']);
        if ($c) {
            foreach (['giro' => 'giro', 'direccion' => 'direccion', 'comuna' => 'comuna', 'ciudad' => 'ciudad'] as $k => $ck) {
                if (($rec[$k] ?? '') === '' && !empty($c[$ck])) $rec[$k] = (string) $c[$ck];
            }
            if ($rec['rut'] === '' && !empty($c['rut'])) $rec['rut'] = formatoRut((string) $c['rut']);
        }
    }
    if ($rec['rut'] === '') {
        $rec['rut'] = '66666666-6';
        $rec['razon_social'] = $rec['razon_social'] ?: 'Consumidor Final';
    }
    return $rec;
}

/** Ítems del panel ([descripcion,cantidad,precio_unit(neto),afecto]) → formato del motor DTE. */
function cobroItemsDte(array $items, bool $todoExento): array
{
    $out = [];
    foreach ($items as $it) {
        $out[] = [
            'descripcion'     => mb_substr((string) $it['descripcion'], 0, 250, 'UTF-8'),
            'cantidad'        => max(1, (int) $it['cantidad']),
            'precio_unitario' => max(0, (int) $it['precio_unit']),
            'unidad'          => 'UN',
            'exento'          => ($todoExento || empty($it['afecto'])) ? 1 : 0,
        ];
    }
    return $out;
}

/** Suma neto/IVA/exento/total de ítems del panel. */
function cobroTotalesItems(array $items): array
{
    $neto = 0; $exento = 0;
    foreach ($items as $it) {
        $sub = (int) $it['precio_unit'] * (int) $it['cantidad'];
        if (!empty($it['afecto'])) $neto += $sub; else $exento += $sub;
    }
    $iva = (int) round($neto * IVA_TASA_COBRO);
    return ['neto' => $neto, 'iva' => $iva, 'exento' => $exento, 'total' => $neto + $iva + $exento];
}

/**
 * Emite una nota de crédito sobre una boleta o factura (interna, o electrónica 61 si el documento está en el SII).
 * $d: motivo, accion:
 *   'devolucion' | 'descuento' : reserva_ids[] (horas/sesiones a devolver, quedan libres) y/o monto (+ descripcion)
 *   'anulacion'                : deja sin efecto todo lo que queda del documento (sus horas quedan libres)
 *   'correccion'               : corrige texto (datos del receptor, glosa…), monto $0
 * Código SII de la referencia: 1 = anula (anulación de un documento sin notas previas),
 * 2 = corrige texto, 3 = corrige montos (devoluciones, descuentos y saldos).
 */
function emitirNotaCredito(int $docId, array $d): array
{
    $b = getBoleta($docId);
    if (!$b) {
        return ['ok' => false, 'error' => 'Documento no encontrado.'];
    }
    if ($b['estado'] === 'anulada') {
        return ['ok' => false, 'error' => 'El documento está anulado.'];
    }
    $motivo = mb_substr(trim((string) ($d['motivo'] ?? '')), 0, 300, 'UTF-8');
    if ($motivo === '') {
        return ['ok' => false, 'error' => 'Indica el motivo de la nota de crédito.'];
    }
    $accion = in_array($d['accion'] ?? '', ['devolucion', 'descuento', 'anulacion', 'correccion'], true) ? $d['accion'] : 'devolucion';
    $modo = cobroModoNota($b, 61);
    if (!$modo['ok']) {
        return $modo;
    }
    $sii = $modo['modo'] === 'sii';
    [$col, $cNeto, $cAfecto] = cobroCols($b['tipo'] === 'factura' ? 'factura' : 'boleta');
    $docAfecto = (int) $b['iva_clp'] > 0;
    $disponible = (int) $b['total_clp'] - (int) ($b['nc_clp'] ?? 0) + (int) ($b['nd_clp'] ?? 0);
    $hayNotas = (int) ($b['nc_clp'] ?? 0) > 0 || (int) ($b['nd_clp'] ?? 0) > 0 || notasCreditoDoc($docId) || notasDebitoDoc($docId);

    $items = [];
    $devolver = [];
    $liberadas = [];
    $codigo = 3;
    if ($accion === 'correccion') {
        $codigo = 2;
        $items[] = ['descripcion' => mb_substr('Corrige texto: ' . $motivo, 0, 300, 'UTF-8'), 'cantidad' => 1, 'precio_unit' => 0, 'afecto' => 0, 'reserva_id' => null];
    } elseif ($accion === 'anulacion') {
        if ($disponible <= 0) {
            return ['ok' => false, 'error' => 'Este documento ya no tiene saldo que anular.'];
        }
        $q = db()->prepare("SELECT id FROM reservas WHERE $col = ?");
        $q->execute([$docId]);
        $devolver = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        $liberadas = reservasCobro($devolver); // con su monto, antes de liberarlas
        if (!$hayNotas) {
            // Anulación completa: la NC replica el detalle del documento (código 1 = anula).
            $codigo = 1;
            $si = db()->prepare("SELECT descripcion, cantidad, precio_unit_clp, afecto FROM boleta_items WHERE boleta_id = ? ORDER BY id");
            $si->execute([$docId]);
            foreach ($si->fetchAll() as $x) {
                $items[] = ['descripcion' => (string) $x['descripcion'], 'cantidad' => (int) $x['cantidad'], 'precio_unit' => (int) $x['precio_unit_clp'], 'afecto' => (int) $x['afecto'], 'reserva_id' => null];
            }
            $tt = cobroTotalesItems($items);
            if (!$items || $tt['total'] !== (int) $b['total_clp']) {
                $items = [];
                $codigo = 3;
            }
        }
        if (!$items) {
            $neto = $docAfecto ? (int) round($disponible / (1 + IVA_TASA_COBRO)) : $disponible;
            $items[] = ['descripcion' => 'Anula saldo de ' . ($b['tipo'] === 'factura' ? 'la factura' : 'la boleta') . ' N° ' . cobroFolio((int) $b['folio']), 'cantidad' => 1, 'precio_unit' => $neto, 'afecto' => $docAfecto ? 1 : 0, 'reserva_id' => null];
        }
    } else {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($d['reserva_ids'] ?? [])), fn($i) => $i > 0)));
        foreach (reservasCobro($ids) as $r) {
            if ((int) $r[$col] !== $docId || ($col === 'factura_id' && !empty($r['factura_plan']))) {
                continue;
            }
            $neto = (int) ($r[$cNeto] ?? 0);
            $devolver[] = (int) $r['id'];
            if ($neto <= 0) {
                continue;
            }
            $items[] = [
                'descripcion' => ($b['tipo'] === 'factura' ? 'Devolución arriendo box ' . nombreBox((string) $r['box_slug']) : 'Devolución sesión') . ' · ' . cobroFechaHora($r),
                'cantidad' => 1, 'precio_unit' => $neto, 'afecto' => !empty($r[$cAfecto]) ? 1 : 0, 'reserva_id' => (int) $r['id'],
            ];
        }
        $montoLibre = (int) preg_replace('/\D/', '', (string) ($d['monto'] ?? '0'));
        if ($montoLibre > 0) {
            $neto = $docAfecto ? (int) round($montoLibre / (1 + IVA_TASA_COBRO)) : $montoLibre;
            $items[] = ['descripcion' => mb_substr(trim((string) ($d['descripcion'] ?? '')) ?: ($accion === 'descuento' ? 'Descuento' : 'Devolución'), 0, 300, 'UTF-8'), 'cantidad' => 1, 'precio_unit' => $neto, 'afecto' => $docAfecto ? 1 : 0, 'reserva_id' => null];
        }
        if (!$items && !$devolver) {
            return ['ok' => false, 'error' => 'Elige las horas a devolver o escribe un monto.'];
        }
        if (!$items && $sii) {
            return ['ok' => false, 'error' => 'Esas horas no tienen monto propio (están en un plan o en $0): no hay nada que acreditar ante el SII.'];
        }
    }

    $tt = cobroTotalesItems($items);
    if ($accion !== 'correccion' && $tt['total'] > $disponible) {
        return ['ok' => false, 'error' => 'La nota de crédito (' . clp($tt['total']) . ') supera lo que queda del documento (' . clp($disponible) . ').'];
    }

    // ---- Nota electrónica REAL (61) al SII: se emite ANTES de tocar la base local.
    $dteId = null; $siiEstado = 'no_enviada'; $track = null; $folio = 0;
    if ($sii && $items) {
        @set_time_limit(900);
        $dte = $modo['dte'];
        $ref = [[
            'tipo_doc' => (int) $dte['tipo_dte'], 'folio' => (int) $dte['folio'], 'fecha' => (string) $dte['fecha_emision'],
            'codigo' => $codigo, 'razon' => mb_substr($motivo, 0, 90, 'UTF-8'),
        ]];
        $todoExento = in_array((int) $dte['tipo_dte'], [34, 41], true);
        try {
            $r = dteEmitirDte($modo['empresa'], 61, cobroItemsDte($items, $todoExento), cobroReceptorNota($b), $ref, $motivo);
        } catch (Throwable $e) {
            error_log('[nc sii] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Error al emitir la nota de crédito electrónica: ' . $e->getMessage()];
        }
        if (!($r['ok'] ?? false)) {
            return ['ok' => false, 'error' => $r['error'] ?? 'El SII rechazó la nota de crédito.', 'sii' => true];
        }
        $folio = (int) $r['folio'];
        $dteId = (int) ($r['documento_id'] ?? 0) ?: null;
        $track = ($r['trackid'] ?? '') !== '' ? (string) $r['trackid'] : null;
        $siiEstado = !empty($r['pendiente']) ? 'pendiente' : 'aceptada';
        $tt = ['neto' => (int) $r['neto'], 'iva' => (int) $r['iva'], 'exento' => (int) $r['exento'], 'total' => (int) $r['total']];
    }

    $pdo = db();
    $propia = !$pdo->inTransaction(); // dentro de una operación mayor (cancelación en bloque) no abre otra
    try {
        if ($propia) $pdo->beginTransaction();
        $notaId = 0;
        if ($items) {
            if (!$sii) {
                $f = $pdo->prepare("SELECT COALESCE(MAX(folio),0)+1 FROM notas_credito WHERE empresa_id = ? AND serie = 'interna' FOR UPDATE");
                $f->execute([(int) $b['empresa_id']]);
                $folio = (int) $f->fetchColumn();
            }
            $pdo->prepare("INSERT INTO notas_credito (documento_id, empresa_id, folio, serie, fecha_emision, motivo, accion, codigo_ref, neto_clp, iva_clp, exento_clp, total_clp, emisor_nombre, usuario_nombre, dte_documento_id, sii_estado, sii_track_id)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$docId, (int) $b['empresa_id'], $folio, $sii ? 'sii' : 'interna', date('Y-m-d H:i:s'), $motivo, $accion, $codigo, $tt['neto'], $tt['iva'], $tt['exento'], $tt['total'], $b['emisor_nombre'], cobroUsuario(), $dteId, $siiEstado, $track]);
            $notaId = (int) $pdo->lastInsertId();
            $si = $pdo->prepare("INSERT INTO notas_credito_items (nota_id, descripcion, cantidad, precio_unit_clp, afecto, total_clp, reserva_id) VALUES (?,?,?,?,?,?,?)");
            foreach ($items as $it) {
                $si->execute([$notaId, $it['descripcion'], $it['cantidad'], $it['precio_unit'], $it['afecto'], $it['precio_unit'] * $it['cantidad'], $it['reserva_id']]);
            }
        }
        if ($devolver) {
            $in = implode(',', array_fill(0, count($devolver), '?'));
            $pdo->prepare("UPDATE reservas SET $col = NULL, $cNeto = NULL, $cAfecto = NULL, cobro_mantenido = 0" . ($col === 'factura_id' ? ', factura_plan = 0' : '') . " WHERE id IN ($in)")
                ->execute($devolver);
            if ($notaId && $accion === 'anulacion') {
                // Deja constancia de las horas liberadas por la anulación (con su monto unitario, sin
                // sumar al total: cantidad 0) para poder revertirla con una nota de débito.
                $si = $pdo->prepare("INSERT INTO notas_credito_items (nota_id, descripcion, cantidad, precio_unit_clp, afecto, total_clp, reserva_id) VALUES (?,?,0,?,?,0,?)");
                foreach ($liberadas as $r) {
                    $si->execute([$notaId, 'Hora liberada · ' . cobroFechaHora($r), (int) ($r[$cNeto] ?? 0), !empty($r[$cAfecto]) ? 1 : 0, (int) $r['id']]);
                }
            }
        }
        if ($propia) $pdo->commit();
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[nota credito] ' . $e->getMessage());
        if ($sii) {
            return ['ok' => false, 'error' => 'La nota de crédito se emitió en el SII (folio ' . $folio . ($track ? ', TrackID ' . $track : '') . ') pero falló el registro local. Anota estos datos y avísale al soporte.'];
        }
        if (!$propia) throw $e;
        return ['ok' => false, 'error' => 'No se pudo emitir la nota de crédito.'];
    }
    docRecalcularPago($docId);
    return ['ok' => true, 'id' => $notaId, 'folio' => $folio, 'total' => $tt['total'], 'horas' => count($devolver), 'sii' => $sii, 'codigo' => $codigo, 'trackid' => $track, 'sii_estado' => $siiEstado];
}

function notasCreditoDoc(int $docId): array
{
    try {
        $st = db()->prepare("SELECT n.*, (SELECT nd.folio FROM notas_debito nd WHERE nd.nota_credito_id = n.id AND nd.estado = 'emitida' LIMIT 1) AS anulada_por_nd
                               FROM notas_credito n WHERE n.documento_id = ? ORDER BY n.id");
        $st->execute([$docId]);
        $out = $st->fetchAll();
        $si = db()->prepare("SELECT * FROM notas_credito_items WHERE nota_id = ? ORDER BY id");
        foreach ($out as &$n) {
            $si->execute([(int) $n['id']]);
            $n['items'] = $si->fetchAll();
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Emite una nota de débito (interna, o electrónica 56 si el documento está en el SII).
 * $d: motivo, accion:
 *   'cargo'    : cobro adicional sobre el documento (monto con IVA si el documento es afecto + descripcion). Código 3.
 *   'anula_nc' : anula una nota de crédito del documento (nota_credito_id). Código 1 sobre la NC: el monto vuelve
 *                a cobrarse y las horas que esa NC había liberado vuelven al documento si siguen libres.
 */
function emitirNotaDebito(int $docId, array $d): array
{
    $b = getBoleta($docId);
    if (!$b) {
        return ['ok' => false, 'error' => 'Documento no encontrado.'];
    }
    if ($b['estado'] === 'anulada') {
        return ['ok' => false, 'error' => 'El documento está anulado.'];
    }
    $motivo = mb_substr(trim((string) ($d['motivo'] ?? '')), 0, 300, 'UTF-8');
    if ($motivo === '') {
        return ['ok' => false, 'error' => 'Indica el motivo de la nota de débito.'];
    }
    $accion = ($d['accion'] ?? '') === 'anula_nc' ? 'anula_nc' : 'cargo';
    $modo = cobroModoNota($b, 56);
    if (!$modo['ok']) {
        return $modo;
    }
    $sii = $modo['modo'] === 'sii';
    [$col, $cNeto, $cAfecto] = cobroCols($b['tipo'] === 'factura' ? 'factura' : 'boleta');
    $docAfecto = (int) $b['iva_clp'] > 0;
    $items = [];
    $nc = null;
    $ref = null;
    if ($accion === 'anula_nc') {
        foreach (notasCreditoDoc($docId) as $n) {
            if ((int) $n['id'] === (int) ($d['nota_credito_id'] ?? 0)) $nc = $n;
        }
        if (!$nc || $nc['estado'] !== 'emitida') {
            return ['ok' => false, 'error' => 'Elige una nota de crédito vigente de este documento.'];
        }
        if (!empty($nc['anulada_por_nd'])) {
            return ['ok' => false, 'error' => 'Esa nota de crédito ya fue anulada con la nota de débito N° ' . cobroFolio((int) $nc['anulada_por_nd']) . '.'];
        }
        if ((int) $nc['total_clp'] <= 0) {
            return ['ok' => false, 'error' => 'Esa nota de crédito no tiene monto (corrige texto): no hay nada que anular.'];
        }
        if ($sii && (($nc['serie'] ?? '') !== 'sii' || empty($nc['dte_documento_id']))) {
            return ['ok' => false, 'error' => 'Esa nota de crédito no está en el SII: no se puede anular con una nota de débito electrónica.'];
        }
        foreach ($nc['items'] as $it) {
            if ((int) $it['total_clp'] <= 0) continue;
            $items[] = ['descripcion' => mb_substr('Anula NC N° ' . cobroFolio((int) $nc['folio']) . ': ' . $it['descripcion'], 0, 300, 'UTF-8'), 'cantidad' => (int) $it['cantidad'], 'precio_unit' => (int) $it['precio_unit_clp'], 'afecto' => (int) $it['afecto'], 'reserva_id' => $it['reserva_id'] !== null ? (int) $it['reserva_id'] : null];
        }
        if ($sii) {
            $ncDte = dteDocPorId((int) $nc['dte_documento_id']);
            if (!$ncDte) {
                return ['ok' => false, 'error' => 'No se encontró el DTE de la nota de crédito.'];
            }
            $ref = ['tipo_doc' => 61, 'folio' => (int) $ncDte['folio'], 'fecha' => (string) $ncDte['fecha_emision'], 'codigo' => 1, 'razon' => mb_substr($motivo, 0, 90, 'UTF-8')];
        }
    } else {
        $monto = (int) preg_replace('/\D/', '', (string) ($d['monto'] ?? '0'));
        if ($monto <= 0) {
            return ['ok' => false, 'error' => 'Escribe el monto de la nota de débito.'];
        }
        $desc = mb_substr(trim((string) ($d['descripcion'] ?? '')), 0, 300, 'UTF-8');
        if ($desc === '') {
            return ['ok' => false, 'error' => 'Describe el cobro adicional (aparece en el documento).'];
        }
        $neto = $docAfecto ? (int) round($monto / (1 + IVA_TASA_COBRO)) : $monto;
        $items[] = ['descripcion' => $desc, 'cantidad' => 1, 'precio_unit' => $neto, 'afecto' => $docAfecto ? 1 : 0, 'reserva_id' => null];
        if ($sii) {
            $dte = $modo['dte'];
            $ref = ['tipo_doc' => (int) $dte['tipo_dte'], 'folio' => (int) $dte['folio'], 'fecha' => (string) $dte['fecha_emision'], 'codigo' => 3, 'razon' => mb_substr($motivo, 0, 90, 'UTF-8')];
        }
    }
    if (!$items) {
        return ['ok' => false, 'error' => 'La nota de débito no tiene montos.'];
    }
    $tt = cobroTotalesItems($items);

    $dteId = null; $siiEstado = 'no_enviada'; $track = null; $folio = 0;
    if ($sii) {
        @set_time_limit(900);
        $todoExento = in_array((int) $modo['dte']['tipo_dte'], [34, 41], true);
        try {
            $r = dteEmitirDte($modo['empresa'], 56, cobroItemsDte($items, $todoExento), cobroReceptorNota($b), [$ref], $motivo);
        } catch (Throwable $e) {
            error_log('[nd sii] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Error al emitir la nota de débito electrónica: ' . $e->getMessage()];
        }
        if (!($r['ok'] ?? false)) {
            return ['ok' => false, 'error' => $r['error'] ?? 'El SII rechazó la nota de débito.', 'sii' => true];
        }
        $folio = (int) $r['folio'];
        $dteId = (int) ($r['documento_id'] ?? 0) ?: null;
        $track = ($r['trackid'] ?? '') !== '' ? (string) $r['trackid'] : null;
        $siiEstado = !empty($r['pendiente']) ? 'pendiente' : 'aceptada';
        $tt = ['neto' => (int) $r['neto'], 'iva' => (int) $r['iva'], 'exento' => (int) $r['exento'], 'total' => (int) $r['total']];
    }

    $pdo = db();
    $reVinculadas = 0;
    try {
        $pdo->beginTransaction();
        if (!$sii) {
            $f = $pdo->prepare("SELECT COALESCE(MAX(folio),0)+1 FROM notas_debito WHERE empresa_id = ? AND serie = 'interna' FOR UPDATE");
            $f->execute([(int) $b['empresa_id']]);
            $folio = (int) $f->fetchColumn();
        }
        $pdo->prepare("INSERT INTO notas_debito (documento_id, nota_credito_id, empresa_id, folio, serie, fecha_emision, motivo, accion, codigo_ref, neto_clp, iva_clp, exento_clp, total_clp, emisor_nombre, usuario_nombre, dte_documento_id, sii_estado, sii_track_id)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$docId, $nc ? (int) $nc['id'] : null, (int) $b['empresa_id'], $folio, $sii ? 'sii' : 'interna', date('Y-m-d H:i:s'), $motivo, $accion, $accion === 'anula_nc' ? 1 : 3,
                       $tt['neto'], $tt['iva'], $tt['exento'], $tt['total'], $b['emisor_nombre'], cobroUsuario(), $dteId, $siiEstado, $track]);
        $notaId = (int) $pdo->lastInsertId();
        $si = $pdo->prepare("INSERT INTO notas_debito_items (nota_id, descripcion, cantidad, precio_unit_clp, afecto, total_clp, reserva_id) VALUES (?,?,?,?,?,?,?)");
        foreach ($items as $it) {
            $si->execute([$notaId, $it['descripcion'], $it['cantidad'], $it['precio_unit'], $it['afecto'], $it['precio_unit'] * $it['cantidad'], $it['reserva_id']]);
        }
        if ($nc) {
            // Las horas que la NC había liberado vuelven a este documento (si nadie más las cobró).
            foreach ($nc['items'] as $it) {
                if (empty($it['reserva_id'])) continue;
                $up = $pdo->prepare("UPDATE reservas SET $col = ?, $cNeto = ?, $cAfecto = ?, cobro_mantenido = IF(estado = 'cancelada', 1, 0)" . ($col === 'factura_id' ? ', factura_plan = 0' : '') . " WHERE id = ? AND $col IS NULL");
                $up->execute([$docId, (int) $it['precio_unit_clp'] ?: null, (int) $it['afecto'], (int) $it['reserva_id']]);
                if ($up->rowCount() > 0) {
                    $reVinculadas++;
                    registrarMovimientoReserva((int) $it['reserva_id'], 'cobro', null, null, null, 'Vuelve a cobrarse: nota de crédito anulada con nota de débito');
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[nota debito] ' . $e->getMessage());
        if ($sii) {
            return ['ok' => false, 'error' => 'La nota de débito se emitió en el SII (folio ' . $folio . ($track ? ', TrackID ' . $track : '') . ') pero falló el registro local. Anota estos datos y avísale al soporte.'];
        }
        return ['ok' => false, 'error' => 'No se pudo emitir la nota de débito.'];
    }
    docRecalcularPago($docId);
    return ['ok' => true, 'id' => $notaId, 'folio' => $folio, 'total' => $tt['total'], 'sii' => $sii, 'horas' => $reVinculadas, 'trackid' => $track, 'sii_estado' => $siiEstado];
}

function notasDebitoDoc(int $docId): array
{
    try {
        $st = db()->prepare("SELECT nd.*, nc.folio AS nc_folio FROM notas_debito nd LEFT JOIN notas_credito nc ON nc.id = nd.nota_credito_id WHERE nd.documento_id = ? ORDER BY nd.id");
        $st->execute([$docId]);
        $out = $st->fetchAll();
        $si = db()->prepare("SELECT * FROM notas_debito_items WHERE nota_id = ? ORDER BY id");
        foreach ($out as &$n) {
            $si->execute([(int) $n['id']]);
            $n['items'] = $si->fetchAll();
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Re-consulta en el SII una nota electrónica ($clase 'nc' | 'nd'). Si el SII la RECHAZÓ, la nota
 * deja de valer y se revierte su efecto (horas, saldo del documento).
 */
function notaReconsultar(string $clase, int $notaId, int $intentos = 2): array
{
    require_once APP_ROOT . '/lib/dte_integra.php';
    $tabla = $clase === 'nd' ? 'notas_debito' : 'notas_credito';
    $st = db()->prepare("SELECT * FROM $tabla WHERE id = ?");
    $st->execute([$notaId]);
    $n = $st->fetch();
    if (!$n || empty($n['dte_documento_id'])) {
        return ['ok' => false, 'error' => 'La nota no tiene documento electrónico asociado.'];
    }
    $r = dteReconsultarDte((int) $n['dte_documento_id'], $intentos);
    if (!$r['ok']) {
        return $r;
    }
    db()->prepare("UPDATE $tabla SET sii_estado = ? WHERE id = ?")->execute([$r['estado'], $notaId]);
    if ($r['estado'] === 'rechazada' && $n['estado'] === 'emitida') {
        $b = getBoleta((int) $n['documento_id']);
        [$col, $cNeto, $cAfecto] = cobroCols(($b['tipo'] ?? '') === 'factura' ? 'factura' : 'boleta');
        db()->prepare("UPDATE $tabla SET estado = 'anulada' WHERE id = ?")->execute([$notaId]);
        $it = db()->prepare("SELECT reserva_id, precio_unit_clp, afecto FROM {$tabla}_items WHERE nota_id = ? AND reserva_id IS NOT NULL");
        $it->execute([$notaId]);
        foreach ($it->fetchAll() as $x) {
            if ($clase === 'nc') {
                // La devolución no valió: la hora vuelve al documento si sigue libre.
                db()->prepare("UPDATE reservas SET $col = ?, $cNeto = ?, $cAfecto = ? WHERE id = ? AND $col IS NULL")
                    ->execute([(int) $n['documento_id'], (int) $x['precio_unit_clp'] ?: null, (int) $x['afecto'], (int) $x['reserva_id']]);
            } elseif ($n['accion'] === 'anula_nc') {
                db()->prepare("UPDATE reservas SET $col = NULL, $cNeto = NULL, $cAfecto = NULL, cobro_mantenido = 0 WHERE id = ? AND $col = ?")
                    ->execute([(int) $x['reserva_id'], (int) $n['documento_id']]);
            }
        }
        docRecalcularPago((int) $n['documento_id']);
        $r['mensaje'] = 'El SII rechazó la nota: quedó sin efecto y se revirtieron sus cambios. ' . ($r['mensaje'] ?? '');
    }
    return $r;
}

/** Notas de crédito emitidas en un rango (restan de los ingresos). */
function ncEntre(string $desde, string $hasta): int
{
    try {
        $st = db()->prepare("SELECT COALESCE(SUM(n.total_clp),0) FROM notas_credito n JOIN boletas b ON b.id = n.documento_id
                              WHERE n.estado = 'emitida' AND b.estado = 'emitida' AND DATE(n.fecha_emision) BETWEEN ? AND ?");
        $st->execute([$desde, $hasta]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Notas de débito emitidas en un rango (suman a los ingresos). */
function ndEntre(string $desde, string $hasta): int
{
    try {
        $st = db()->prepare("SELECT COALESCE(SUM(n.total_clp),0) FROM notas_debito n JOIN boletas b ON b.id = n.documento_id
                              WHERE n.estado = 'emitida' AND b.estado = 'emitida' AND DATE(n.fecha_emision) BETWEEN ? AND ?");
        $st->execute([$desde, $hasta]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/* ===================================================== Anular documento */

/** Anula un documento INTERNO: deja de contar como ingreso y libera sus horas/sesiones. */
function anularDocumento(int $docId, string $motivo): array
{
    $b = getBoleta($docId);
    if (!$b) {
        return ['ok' => false, 'error' => 'Documento no encontrado.'];
    }
    if ($b['estado'] === 'anulada') {
        return ['ok' => false, 'error' => 'El documento ya está anulado.'];
    }
    if (($b['serie'] ?? 'interna') === 'sii') {
        return ['ok' => false, 'error' => 'Un documento enviado al SII no se anula: se deja sin efecto con una nota de crédito (anulación).'];
    }
    $motivo = mb_substr(trim($motivo), 0, 300, 'UTF-8');
    if ($motivo === '') {
        return ['ok' => false, 'error' => 'Indica el motivo de la anulación.'];
    }
    try {
        db()->prepare("UPDATE boletas SET estado = 'anulada', anulada_en = ?, anulada_motivo = ?, anulada_por = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $motivo, cobroUsuario(), $docId]);
        $libres = cobroLiberarDocumento($docId);
        docRecalcularPago($docId);
        $s = docSaldo(getBoleta($docId));
        return ['ok' => true, 'horas_liberadas' => $libres, 'devolver' => $s['a_favor']];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo anular el documento.'];
    }
}

/* ========================================================== Reagendar */

/** Cambia la fecha/hora (y opcionalmente el box) de una reserva vigente, conservando su cobro. */
function reagendarReserva(int $id, string $fecha, string $hora, string $box = ''): array
{
    $r = getReserva($id);
    if (!$r) {
        return ['ok' => false, 'error' => 'Reserva no encontrada.'];
    }
    if (!in_array($r['estado'], ['pendiente', 'confirmada'], true)) {
        return ['ok' => false, 'error' => 'Solo se reagendan horas pendientes o confirmadas.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
        return ['ok' => false, 'error' => 'Elige una fecha válida.'];
    }
    if (!in_array($hora, horasAgenda(), true)) {
        return ['ok' => false, 'error' => 'Elige una hora válida.'];
    }
    if (($cerrado = diaCerradoMotivo($fecha)) !== null) {
        return ['ok' => false, 'error' => $cerrado];
    }
    if (($fueraH = horaFueraHorarioMotivo($fecha, $hora)) !== null) {
        return ['ok' => false, 'error' => $fueraH];
    }
    $box = $box !== '' ? $box : (string) $r['box_slug'];
    $slugs = function_exists('getContent') ? array_column(getContent()['boxes'] ?? [], 'slug') : [];
    if ($slugs && !in_array($box, $slugs, true)) {
        return ['ok' => false, 'error' => 'Box no válido.'];
    }
    $antes = cobroFechaHora($r) . ' · ' . nombreBox((string) $r['box_slug']);
    if ($fecha === $r['fecha'] && $hora === substr((string) $r['hora'], 0, 5) && $box === $r['box_slug']) {
        return ['ok' => false, 'error' => 'Es la misma fecha, hora y box.'];
    }
    if (!empty($r['profesional_id'])) {
        $c = db()->prepare("SELECT COUNT(*) FROM reservas WHERE profesional_id = ? AND fecha = ? AND hora = ? AND estado <> 'cancelada' AND id <> ?");
        $c->execute([(int) $r['profesional_id'], $fecha, $hora . ':00', $id]);
        if ((int) $c->fetchColumn() > 0) {
            return ['ok' => false, 'error' => nombreProfesional((int) $r['profesional_id']) . ' ya tiene una hora tomada ese día a las ' . $hora . '.'];
        }
    }
    if (!empty($r['paciente_id'])) {
        $c = db()->prepare("SELECT COUNT(*) FROM reservas WHERE paciente_id = ? AND fecha = ? AND hora = ? AND estado <> 'cancelada' AND id <> ?");
        $c->execute([(int) $r['paciente_id'], $fecha, $hora . ':00', $id]);
        if ((int) $c->fetchColumn() > 0) {
            return ['ok' => false, 'error' => 'El paciente ya tiene otra hora ese día a las ' . $hora . '.'];
        }
    }
    try {
        db()->prepare("UPDATE reservas SET fecha = ?, hora = ?, box_slug = ? WHERE id = ?")->execute([$fecha, $hora . ':00', $box, $id]);
    } catch (Throwable $e) {
        if (esErrorCupoBox($e)) {
            return ['ok' => false, 'error' => 'El box ' . nombreBox($box) . ' ya tiene su cupo completo ese día a las ' . $hora . '.'];
        }
        if (($m = errorDiaCerrado($e, $fecha)) !== null) {
            return ['ok' => false, 'error' => $m];
        }
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'error' => 'Ese profesional ya tiene una hora en ese horario.'];
        }
        return ['ok' => false, 'error' => 'No se pudo reagendar.'];
    }
    $despues = date('d-m-Y', strtotime($fecha)) . ' ' . $hora . ' · ' . nombreBox($box);
    registrarMovimientoReserva($id, 'reagenda', !empty($r['profesional_id']) ? (int) $r['profesional_id'] : null, null, null, 'Reagendada: ' . $antes . ' → ' . $despues . (!empty($r['boleta_id']) || !empty($r['factura_id']) ? ' (conserva su cobro)' : ''));
    return ['ok' => true, 'antes' => $antes, 'despues' => $despues];
}

/* =================================================== Sesiones por cobrar */

/**
 * Sesiones del paciente que aún no tienen boleta (no canceladas), SOLO las atendidas por
 * las socias (las boletas las emiten ellas; los pacientes de quienes arriendan box los
 * cobra cada profesional). Las pasadas primero.
 */
function sesionesPorCobrarPaciente(int $pacienteId): array
{
    try {
        $socias = array_keys(profesionalesSocias());
        $filtro = $socias ? ' AND profesional_id IN (' . implode(',', array_map('intval', $socias)) . ')' : '';
        $st = db()->prepare(
            "SELECT id, fecha, TIME_FORMAT(hora,'%H:%i') AS hora, box_slug, profesional_id, modalidad_label, precio_clp, estado
               FROM reservas WHERE paciente_id = ? AND boleta_id IS NULL AND estado <> 'cancelada'$filtro
              ORDER BY fecha, hora"
        );
        $st->execute([$pacienteId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $r['profesional'] = $r['profesional_id'] !== null ? nombreProfesional((int) $r['profesional_id']) : '';
            $r['box'] = nombreBox((string) $r['box_slug']);
            $r['futura'] = $r['fecha'] > date('Y-m-d');
            $out[] = $r;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}
