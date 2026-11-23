<?php
/**
 * Panel «Cobro» de un documento (boleta o factura): estado de pago, pagos y devoluciones,
 * lo que cobra (horas/sesiones del calendario), notas de crédito y anulación.
 * Requiere $boleta (getBoleta). Solo pantalla: no se imprime.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/cobros.php';

$cbDoc = $boleta;
$cbId = (int) $cbDoc['id'];
$cbAdmin = isAdmin();
$cbEsFactura = ($cbDoc['tipo'] ?? '') === 'factura';
$cbAnulada = $cbDoc['estado'] === 'anulada';
$cbInterna = ($cbDoc['serie'] ?? 'interna') !== 'sii';
$cbSaldo = docSaldo($cbDoc);
[$cbEtq, $cbCls] = docPagoEtiqueta($cbDoc);
$cbPagos = docPagos($cbId);
$cbNotas = notasCreditoDoc($cbId);
$cbNotasD = notasDebitoDoc($cbId);
// Productos del documento por profesional dueña (Fernanda / Javiera).
$cbPorProf = [];
try {
    $q = db()->prepare("SELECT i.descripcion, i.cantidad, i.total_clp, pr.nombre AS prof FROM boleta_items i JOIN profesionales pr ON pr.id = i.profesional_id WHERE i.boleta_id = ? ORDER BY pr.nombre, i.id");
    $q->execute([$cbId]);
    foreach ($q->fetchAll() as $x) $cbPorProf[$x['prof']][] = $x;
} catch (Throwable $e) {
}
$cbCol = $cbEsFactura ? 'factura_id' : 'boleta_id';
$cbRes = [];
try {
    $q = db()->prepare("SELECT id FROM reservas WHERE $cbCol = ? ORDER BY fecha, hora");
    $q->execute([$cbId]);
    $cbRes = reservasCobro(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)));
} catch (Throwable $e) {
}
// Horas devueltas con notas de crédito (ya no están unidas al documento).
$cbDevueltas = [];
foreach ($cbNotas as $n) {
    foreach ($n['items'] as $it) {
        if (!empty($it['reserva_id'])) $cbDevueltas[(int) $it['reserva_id']] = cobroFolio((int) $n['folio']);
    }
}
$cbNeto = $cbEsFactura ? 'cobro_neto' : 'boleta_neto';
$cbAfecto = $cbEsFactura ? 'cobro_afecto' : 'boleta_afecto';
$cbMonto = function (array $r) use ($cbNeto, $cbAfecto): int {
    $n = (int) ($r[$cbNeto] ?? 0);
    return !empty($r[$cbAfecto]) ? $n + (int) round($n * IVA_TASA_COBRO) : $n;
};
// Notas sobre documentos del SII: electrónicas reales (61/56), solo si la empresa está certificada y lista.
$cbModoNC = ['ok' => true, 'modo' => 'interna'];
$cbModoND = ['ok' => true, 'modo' => 'interna'];
if (!$cbInterna && $cbAdmin && !$cbAnulada) {
    $cbModoNC = cobroModoNota($cbDoc, 61);
    $cbModoND = cobroModoNota($cbDoc, 56);
}
$cbPuedeNC = $cbAdmin && !$cbAnulada && $cbModoNC['ok'];
$cbPuedeDevolver = $cbPuedeNC && $cbSaldo['a_pagar'] > 0;
$cbPuedeND = $cbAdmin && !$cbAnulada && $cbModoND['ok'];
$cbNcAnulables = array_values(array_filter($cbNotas, fn($n) => $n['estado'] === 'emitida' && (int) $n['total_clp'] > 0 && empty($n['anulada_por_nd'])
    && ($cbInterna || (($n['serie'] ?? '') === 'sii' && !empty($n['dte_documento_id'])))));
$cbSiiChip = ['aceptada' => ['SII aceptada', 'bg-emerald-100 text-emerald-700'], 'rechazada' => ['SII rechazada', 'bg-red-100 text-red-700'], 'pendiente' => ['SII pendiente', 'bg-amber-100 text-amber-700']];
$cbCodRef = [1 => 'Anula', 2 => 'Corrige texto', 3 => 'Corrige montos'];
$inp = 'rounded-lg border border-cafe/20 bg-white px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota';
?>
<section id="cobro" class="no-print mb-5 rounded-2xl border border-cafe/10 bg-white p-5 shadow-card sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="font-body text-[11px] font-bold uppercase tracking-[0.14em] text-terracota-dark">Cobro</p>
            <h2 class="font-heading text-xl font-semibold text-cafe">Estado de pago</h2>
        </div>
        <span class="rounded-full px-3 py-1 font-body text-sm font-bold <?= $cbCls ?>"><?= e($cbEtq) ?></span>
    </div>

    <?php if ($cbAnulada): ?>
        <p class="mt-3 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 font-body text-sm text-red-800">
            Anulado<?= $cbDoc['anulada_en'] ? ' el ' . e(date('d-m-Y H:i', strtotime($cbDoc['anulada_en']))) : '' ?><?= $cbDoc['anulada_por'] ? ' por ' . e($cbDoc['anulada_por']) : '' ?>: <?= e((string) $cbDoc['anulada_motivo']) ?>.
            No cuenta como ingreso y sus horas quedaron libres para volver a cobrarse.
            <?php if ($cbSaldo['a_favor'] > 0): ?><strong class="block">Se habían pagado <?= e(clp($cbSaldo['a_favor'])) ?>: registra la devolución abajo cuando la hagas.</strong><?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if ($cbPorProf): ?>
        <div class="mt-3 flex flex-wrap gap-2">
            <?php foreach ($cbPorProf as $cbPn => $cbIts): ?>
                <div class="rounded-xl border border-salvia/30 bg-salvia/8 px-3 py-2 font-body text-xs text-cafe/80">
                    <strong class="text-salvia-dark">Productos de <?= e($cbPn) ?>:</strong>
                    <?= e(implode(' · ', array_map(fn($x) => $x['descripcion'] . ((int) $x['cantidad'] > 1 ? ' ×' . (int) $x['cantidad'] : ''), $cbIts))) ?>
                    <span class="tabular-nums text-cafe/60">(<?= e(clp(array_sum(array_map(fn($x) => (int) $x['total_clp'], $cbIts)))) ?>)</span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
        <?php foreach ([['Total', clp($cbSaldo['total'])], [$cbSaldo['nd'] ? 'Notas crédito / débito' : 'Notas de crédito', ($cbSaldo['nc'] ? '− ' . clp($cbSaldo['nc']) : '—') . ($cbSaldo['nd'] ? ' / + ' . clp($cbSaldo['nd']) : '')], ['A pagar', clp($cbSaldo['a_pagar'])], ['Pagado', clp($cbSaldo['pagado'])],
                       [$cbSaldo['a_favor'] > 0 ? 'A devolver' : 'Saldo', clp($cbSaldo['a_favor'] > 0 ? $cbSaldo['a_favor'] : $cbSaldo['saldo'])]] as [$cbK, $cbV]): ?>
            <div class="rounded-xl bg-crema/50 px-3 py-2.5"><dt class="font-body text-[11px] font-semibold uppercase tracking-wide text-cafe/50"><?= e($cbK) ?></dt><dd class="mt-0.5 font-heading text-lg font-semibold tabular-nums text-cafe"><?= e($cbV) ?></dd></div>
        <?php endforeach; ?>
    </dl>
    <?php if ($cbSaldo['vence'] && $cbSaldo['saldo'] > 0): ?>
        <p class="mt-2 font-body text-xs <?= $cbSaldo['vencida'] ? 'font-semibold text-red-700' : 'text-cafe/60' ?>">Vence el <?= e(date('d-m-Y', strtotime($cbSaldo['vence']))) ?><?= $cbSaldo['vencida'] ? ' · vencida' : '' ?>.</p>
    <?php endif; ?>

    <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
        <!-- Pagos -->
        <div class="min-w-0">
            <h3 class="font-heading text-base font-semibold text-cafe">Pagos y devoluciones</h3>
            <ul class="mt-2 divide-y divide-cafe/8 rounded-xl border border-cafe/10">
                <?php if (!$cbPagos): ?><li class="px-3 py-3 font-body text-sm text-cafe/50">Aún no hay pagos registrados.</li><?php endif; ?>
                <?php foreach ($cbPagos as $pg): $neg = (int) $pg['monto'] < 0; ?>
                    <li class="flex items-center justify-between gap-3 px-3 py-2 font-body text-sm">
                        <span class="min-w-0"><strong class="text-cafe"><?= e(date('d-m-Y', strtotime($pg['fecha']))) ?></strong> · <?= e(COBRO_MEDIOS[$pg['medio']] ?? $pg['medio']) ?><?= $pg['referencia'] ? ' · ' . e($pg['referencia']) : '' ?><?= $neg ? ' <span class="rounded-full bg-amber-100 px-1.5 text-[10px] font-bold text-amber-800">devolución</span>' : '' ?></span>
                        <span class="flex shrink-0 items-center gap-2"><strong class="tabular-nums <?= $neg ? 'text-amber-800' : 'text-cafe' ?>"><?= $neg ? '− ' : '' ?><?= e(clp(abs((int) $pg['monto']))) ?></strong>
                            <?php if ($cbAdmin): ?><button type="button" data-pago-del="<?= (int) $pg['id'] ?>" class="rounded px-1 text-cafe/35 hover:bg-red-50 hover:text-red-600" title="Eliminar este registro" aria-label="Eliminar pago">&times;</button><?php endif; ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($cbAdmin && ($cbSaldo['saldo'] > 0 || $cbSaldo['a_favor'] > 0 || $cbSaldo['pagado'] > 0)): ?>
                <form id="cb-pago" class="mt-3 grid grid-cols-2 gap-2 rounded-xl bg-crema/40 p-3 sm:grid-cols-4">
                    <label class="col-span-2 flex flex-col gap-1 sm:col-span-1"><span class="font-body text-[11px] font-semibold uppercase text-cafe/55">Tipo</span>
                        <select name="tipo" class="<?= $inp ?>"><?php if (!$cbAnulada && $cbSaldo['saldo'] > 0): ?><option value="pago">Pago recibido</option><?php endif; ?><?php if ($cbSaldo['pagado'] > 0): ?><option value="devolucion" <?= $cbSaldo['a_favor'] > 0 ? 'selected' : '' ?>>Devolución</option><?php endif; ?></select></label>
                    <label class="flex flex-col gap-1"><span class="font-body text-[11px] font-semibold uppercase text-cafe/55">Monto</span><input name="monto" type="number" min="1" value="<?= (int) ($cbSaldo['a_favor'] > 0 ? $cbSaldo['a_favor'] : $cbSaldo['saldo']) ?>" class="<?= $inp ?> text-right"></label>
                    <label class="flex flex-col gap-1"><span class="font-body text-[11px] font-semibold uppercase text-cafe/55">Fecha</span><input name="fecha" type="date" value="<?= e(date('Y-m-d')) ?>" class="<?= $inp ?>"></label>
                    <label class="flex flex-col gap-1"><span class="font-body text-[11px] font-semibold uppercase text-cafe/55">Medio</span><select name="medio" class="<?= $inp ?>"><?php foreach (COBRO_MEDIOS as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === ($cbDoc['medio_pago'] ?? '') ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
                    <label class="col-span-2 flex flex-col gap-1 sm:col-span-3"><span class="font-body text-[11px] font-semibold uppercase text-cafe/55">Referencia (opcional)</span><input name="referencia" maxlength="160" placeholder="N° de transferencia, comprobante…" class="<?= $inp ?>"></label>
                    <div class="col-span-2 flex items-end sm:col-span-1"><button type="submit" class="w-full rounded-full bg-cafe px-4 py-2 font-body text-sm font-semibold text-crema-light hover:bg-cafe-light">Registrar</button></div>
                </form>
            <?php endif; ?>
        </div>

        <!-- Lo que cobra -->
        <div class="min-w-0">
            <h3 class="font-heading text-base font-semibold text-cafe"><?= $cbEsFactura ? 'Horas de box que cobra' : 'Sesiones que paga' ?> (<?= count($cbRes) ?>)</h3>
            <div class="mt-2 max-h-72 overflow-y-auto rounded-xl border border-cafe/10">
                <?php if (!$cbRes && !$cbDevueltas): ?><p class="px-3 py-3 font-body text-sm text-cafe/50"><?= $cbEsFactura ? 'Esta factura no está unida a horas del calendario (tarifa o concepto libre).' : 'Esta boleta no está unida a sesiones del calendario.' ?></p><?php endif; ?>
                <?php foreach ($cbRes as $cbR):
                    $plan = $cbEsFactura && !empty($cbR['factura_plan']);
                    $cancel = $cbR['estado'] === 'cancelada'; ?>
                    <label class="flex items-center gap-2.5 border-b border-cafe/8 px-3 py-2 font-body text-sm last:border-0">
                        <?php if ($cbPuedeDevolver && !$plan && $cbMonto($cbR) > 0): ?><input type="checkbox" class="cb-nc-res h-4 w-4 accent-[#D97757]" value="<?= (int) $cbR['id'] ?>" data-monto="<?= $cbMonto($cbR) ?>"><?php else: ?><span class="inline-block w-4"></span><?php endif; ?>
                        <span class="min-w-0 flex-1"><strong class="text-cafe"><?= e(cobroFechaHora($cbR)) ?></strong> · <?= e(nombreBox((string) $cbR['box_slug'])) ?><?= !empty($cbR['profesional_id']) ? ' · ' . e(nombreProfesional((int) $cbR['profesional_id'])) : '' ?>
                            <?php if ($plan): ?><span class="ml-1 rounded-full bg-salvia/20 px-1.5 text-[10px] font-bold text-salvia-dark">en plan</span><?php endif; ?>
                            <?php if ($cancel): ?><span class="ml-1 rounded-full bg-amber-100 px-1.5 text-[10px] font-bold text-amber-800">cancelada · cobro mantenido</span><?php elseif ($cbR['estado'] === 'no_show'): ?><span class="ml-1 rounded-full bg-cafe/10 px-1.5 text-[10px] font-bold text-cafe/60">no asistió</span><?php endif; ?></span>
                        <span class="shrink-0 tabular-nums text-cafe/80"><?= $plan ? '—' : e(clp($cbMonto($cbR))) ?></span>
                    </label>
                <?php endforeach; ?>
                <?php foreach (array_keys($cbDevueltas) as $rid):
                    $rr = getReserva($rid); if (!$rr) continue; ?>
                    <div class="flex items-center gap-2.5 border-b border-cafe/8 bg-crema/30 px-3 py-2 font-body text-sm text-cafe/55 last:border-0">
                        <span class="inline-block w-4"></span>
                        <span class="min-w-0 flex-1"><?= e(cobroFechaHora($rr)) ?> · <?= e(nombreBox((string) $rr['box_slug'])) ?> <span class="rounded-full bg-cafe/10 px-1.5 text-[10px] font-bold">devuelta en NC N° <?= e($cbDevueltas[$rid]) ?></span></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Notas de crédito -->
    <?php if ($cbNotas): ?>
        <div class="mt-5">
            <h3 class="font-heading text-base font-semibold text-cafe">Notas de crédito</h3>
            <ul class="mt-2 space-y-2">
                <?php foreach ($cbNotas as $n): $nSii = ($n['serie'] ?? '') === 'sii'; $nVale = $n['estado'] === 'emitida'; ?>
                    <li class="rounded-xl border border-cafe/10 <?= $nVale ? 'bg-crema/30' : 'bg-red-50/60' ?> px-4 py-3 font-body text-sm">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span><strong class="text-cafe"><?= $nSii ? 'Nota de crédito electrónica' : 'Nota de crédito' ?> N° <?= e(cobroFolio((int) $n['folio'])) ?></strong> · <?= e(date('d-m-Y H:i', strtotime($n['fecha_emision']))) ?> · <?= e($n['motivo']) ?>
                                <span class="rounded-full bg-cafe/8 px-1.5 text-[10px] font-bold text-cafe/55"><?= $nSii ? 'SII · 61' : 'interna' ?></span>
                                <?php if (!empty($n['codigo_ref'])): ?><span class="rounded-full bg-cafe/8 px-1.5 text-[10px] font-bold text-cafe/55"><?= e($cbCodRef[(int) $n['codigo_ref']] ?? '') ?></span><?php endif; ?>
                                <?php if ($nSii && isset($cbSiiChip[$n['sii_estado'] ?? ''])): [$sTx, $sCl] = $cbSiiChip[$n['sii_estado']]; ?><span class="rounded-full px-1.5 text-[10px] font-bold <?= $sCl ?>"><?= e($sTx) ?></span><?php endif; ?>
                                <?php if (!$nVale): ?><span class="rounded-full bg-red-100 px-1.5 text-[10px] font-bold text-red-700">sin efecto</span><?php endif; ?>
                                <?php if (!empty($n['anulada_por_nd'])): ?><span class="rounded-full bg-amber-100 px-1.5 text-[10px] font-bold text-amber-800">anulada con ND N° <?= e(cobroFolio((int) $n['anulada_por_nd'])) ?></span><?php endif; ?></span>
                            <strong class="tabular-nums text-cafe"><?= (int) $n['total_clp'] > 0 ? '− ' . e(clp((int) $n['total_clp'])) : '$0' ?></strong>
                        </div>
                        <ul class="mt-1 text-xs text-cafe/60"><?php foreach ($n['items'] as $it): ?><li>• <?= e($it['descripcion']) ?><?= (int) $it['cantidad'] > 0 ? ' · ' . e(clp((int) $it['total_clp'])) . ($it['afecto'] ? ' + IVA' : '') : '' ?></li><?php endforeach; ?></ul>
                        <a href="<?= e(base_path('/admin/notas/nc/' . (int) $n['id'])) ?>" class="mt-2 inline-block text-xs font-semibold text-terracota-dark hover:underline">Ver nota de crédito →</a>
                        <?php if ($nSii && !empty($n['dte_documento_id'])): ?>
                            <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                <a href="<?= e(base_path('/admin/dte/pdf?doc=' . (int) $n['dte_documento_id'])) ?>" target="_blank" class="font-semibold text-terracota-dark hover:underline">Ver PDF</a>
                                <?php if ($cbAdmin): ?><button type="button" data-nota-sii="nc:<?= (int) $n['id'] ?>" class="font-semibold text-cafe/60 hover:text-cafe hover:underline">Reconsultar estado SII</button><?php endif; ?>
                                <?php if (!empty($n['sii_track_id'])): ?><span class="text-cafe/45">TrackID <?= e($n['sii_track_id']) ?></span><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Notas de débito -->
    <?php if ($cbNotasD): ?>
        <div class="mt-5">
            <h3 class="font-heading text-base font-semibold text-cafe">Notas de débito</h3>
            <ul class="mt-2 space-y-2">
                <?php foreach ($cbNotasD as $n): $nSii = ($n['serie'] ?? '') === 'sii'; $nVale = $n['estado'] === 'emitida'; ?>
                    <li class="rounded-xl border border-cafe/10 <?= $nVale ? 'bg-crema/30' : 'bg-red-50/60' ?> px-4 py-3 font-body text-sm">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span><strong class="text-cafe"><?= $nSii ? 'Nota de débito electrónica' : 'Nota de débito' ?> N° <?= e(cobroFolio((int) $n['folio'])) ?></strong> · <?= e(date('d-m-Y H:i', strtotime($n['fecha_emision']))) ?> · <?= e($n['motivo']) ?>
                                <span class="rounded-full bg-cafe/8 px-1.5 text-[10px] font-bold text-cafe/55"><?= $nSii ? 'SII · 56' : 'interna' ?></span>
                                <span class="rounded-full bg-cafe/8 px-1.5 text-[10px] font-bold text-cafe/55"><?= $n['accion'] === 'anula_nc' ? 'Anula NC N° ' . e(cobroFolio((int) $n['nc_folio'])) : 'Cobro adicional' ?></span>
                                <?php if ($nSii && isset($cbSiiChip[$n['sii_estado'] ?? ''])): [$sTx, $sCl] = $cbSiiChip[$n['sii_estado']]; ?><span class="rounded-full px-1.5 text-[10px] font-bold <?= $sCl ?>"><?= e($sTx) ?></span><?php endif; ?>
                                <?php if (!$nVale): ?><span class="rounded-full bg-red-100 px-1.5 text-[10px] font-bold text-red-700">sin efecto</span><?php endif; ?></span>
                            <strong class="tabular-nums text-cafe">+ <?= e(clp((int) $n['total_clp'])) ?></strong>
                        </div>
                        <ul class="mt-1 text-xs text-cafe/60"><?php foreach ($n['items'] as $it): ?><li>• <?= e($it['descripcion']) ?> · <?= e(clp((int) $it['total_clp'])) ?><?= $it['afecto'] ? ' + IVA' : '' ?></li><?php endforeach; ?></ul>
                        <a href="<?= e(base_path('/admin/notas/nd/' . (int) $n['id'])) ?>" class="mt-2 inline-block text-xs font-semibold text-terracota-dark hover:underline">Ver nota de débito →</a>
                        <?php if ($nSii && !empty($n['dte_documento_id'])): ?>
                            <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                <a href="<?= e(base_path('/admin/dte/pdf?doc=' . (int) $n['dte_documento_id'])) ?>" target="_blank" class="font-semibold text-terracota-dark hover:underline">Ver PDF</a>
                                <?php if ($cbAdmin): ?><button type="button" data-nota-sii="nd:<?= (int) $n['id'] ?>" class="font-semibold text-cafe/60 hover:text-cafe hover:underline">Reconsultar estado SII</button><?php endif; ?>
                                <?php if (!empty($n['sii_track_id'])): ?><span class="text-cafe/45">TrackID <?= e($n['sii_track_id']) ?></span><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($cbAdmin && !$cbAnulada): ?>
        <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-cafe/10 pt-4">
            <?php if ($cbPuedeNC): ?>
                <button type="button" id="cb-nc-btn" class="rounded-full border-2 border-terracota/45 px-4 py-2 font-body text-sm font-semibold text-terracota-dark hover:bg-terracota/10">Emitir nota de crédito</button>
            <?php endif; ?>
            <?php if ($cbPuedeND): ?>
                <button type="button" id="cb-nd-btn" class="rounded-full border-2 border-cafe/20 px-4 py-2 font-body text-sm font-semibold text-cafe hover:bg-cafe/5">Emitir nota de débito</button>
            <?php endif; ?>
            <?php if ($cbInterna): ?>
                <button type="button" id="cb-anular" class="rounded-full px-4 py-2 font-body text-sm font-semibold text-red-600/85 hover:bg-red-50 hover:text-red-700">Anular documento</button>
            <?php else: ?>
                <span class="font-body text-xs text-cafe/50">Documento enviado al SII: no se anula; se deja sin efecto con una nota de crédito (anulación) y las notas van al SII.</span>
            <?php endif; ?>
            <span class="font-body text-[11px] text-cafe/45">Nota de crédito: devuelve, descuenta, anula el saldo o corrige un texto. Nota de débito: cobra algo adicional o anula una nota de crédito emitida por error.</span>
        </div>
        <?php if (!$cbModoNC['ok'] || !$cbModoND['ok']): ?>
            <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 font-body text-xs text-amber-900"><?= e(!$cbModoNC['ok'] ? $cbModoNC['error'] : $cbModoND['error']) ?></p>
        <?php endif; ?>
        <?php if ($cbPuedeNC): ?>
        <form id="cb-nc" class="mt-3 hidden space-y-3 rounded-xl border border-terracota/25 bg-[#FBF3EC] p-4" data-sii="<?= $cbInterna ? '0' : '1' ?>" data-disp="<?= (int) $cbSaldo['a_pagar'] ?>">
            <p class="font-body text-sm text-cafe"><strong>Nueva nota de crédito<?= $cbInterna ? '' : ' electrónica (61) al SII' ?></strong> · disponible: <?= e(clp($cbSaldo['a_pagar'])) ?>.</p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <label class="flex flex-col gap-1 sm:col-span-3"><span class="font-body text-[11px] font-semibold uppercase text-cafe/55">Tipo de nota</span>
                    <select name="accion" class="<?= $inp ?>">
                        <?php if ($cbSaldo['a_pagar'] > 0): ?><option value="devolucion">Devolver horas o descontar un monto (corrige montos)</option>
                        <option value="anulacion">Anular todo lo que queda del documento (<?= e(clp($cbSaldo['a_pagar'])) ?>)</option><?php endif; ?>
                        <option value="correccion">Corregir un texto: datos del receptor, glosa… (sin monto)</option>
                    </select></label>
                <label class="flex flex-col gap-1 sm:col-span-3"><span class="font-body text-[11px] font-semibold uppercase text-cafe/55" data-nc-lbl-motivo>Motivo *</span><input name="motivo" maxlength="300" class="<?= $inp ?>" placeholder="Ej.: la profesional dejó el arriendo desde el 15 de noviembre"></label>
                <label class="flex flex-col gap-1" data-nc-montos><span class="font-body text-[11px] font-semibold uppercase text-cafe/55">Monto adicional (opcional)</span><input name="monto" type="number" min="0" value="0" class="<?= $inp ?> text-right"></label>
                <label class="flex flex-col gap-1 sm:col-span-2" data-nc-montos><span class="font-body text-[11px] font-semibold uppercase text-cafe/55">Descripción del monto</span><input name="descripcion" maxlength="300" class="<?= $inp ?>" placeholder="Ej.: descuento por box sin calefacción"></label>
            </div>
            <p class="font-body text-[11px] text-cafe/55" data-nc-ayuda>Marca arriba las horas a devolver (quedan libres para cobrarse de nuevo) y/o escribe un monto.</p>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span id="cb-nc-total" class="font-body text-sm font-semibold text-cafe">Total: $0</span>
                <div class="flex gap-2"><button type="button" id="cb-nc-x" class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe">Cancelar</button>
                <button type="submit" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Emitir nota de crédito</button></div>
            </div>
        </form>
        <?php endif; ?>
        <?php if ($cbPuedeND): ?>
        <form id="cb-nd" class="mt-3 hidden space-y-3 rounded-xl border border-cafe/20 bg-crema/40 p-4" data-sii="<?= $cbInterna ? '0' : '1' ?>">
            <p class="font-body text-sm text-cafe"><strong>Nueva nota de débito<?= $cbInterna ? '' : ' electrónica (56) al SII' ?></strong></p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <label class="flex flex-col gap-1 sm:col-span-3"><span class="font-body text-[11px] font-semibold uppercase text-cafe/55">Tipo de nota</span>
                    <select name="accion" class="<?= $inp ?>">
                        <option value="cargo">Cobrar un monto adicional sobre este documento</option>
                        <?php if ($cbNcAnulables): ?><option value="anula_nc">Anular una nota de crédito emitida por error</option><?php endif; ?>
                    </select></label>
                <?php if ($cbNcAnulables): ?>
                <label class="hidden flex-col gap-1 sm:col-span-3" data-nd-nc><span class="font-body text-[11px] font-semibold uppercase text-cafe/55">Nota de crédito a anular</span>
                    <select name="nota_credito_id" class="<?= $inp ?>">
                        <?php foreach ($cbNcAnulables as $n): ?><option value="<?= (int) $n['id'] ?>" data-monto="<?= (int) $n['total_clp'] ?>">N° <?= e(cobroFolio((int) $n['folio'])) ?> · <?= e(clp((int) $n['total_clp'])) ?> · <?= e(mb_strimwidth((string) $n['motivo'], 0, 60, '…', 'UTF-8')) ?></option><?php endforeach; ?>
                    </select></label>
                <?php endif; ?>
                <label class="flex flex-col gap-1 sm:col-span-3"><span class="font-body text-[11px] font-semibold uppercase text-cafe/55">Motivo *</span><input name="motivo" maxlength="300" class="<?= $inp ?>" placeholder="Ej.: horas adicionales usadas en noviembre"></label>
                <label class="flex flex-col gap-1" data-nd-cargo><span class="font-body text-[11px] font-semibold uppercase text-cafe/55">Monto *</span><input name="monto" type="number" min="0" value="0" class="<?= $inp ?> text-right"></label>
                <label class="flex flex-col gap-1 sm:col-span-2" data-nd-cargo><span class="font-body text-[11px] font-semibold uppercase text-cafe/55">Descripción *</span><input name="descripcion" maxlength="300" class="<?= $inp ?>" placeholder="Ej.: 2 horas adicionales box Loica"></label>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span id="cb-nd-total" class="font-body text-sm font-semibold text-cafe">Total: $0</span>
                <div class="flex gap-2"><button type="button" id="cb-nd-x" class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe">Cancelar</button>
                <button type="submit" class="rounded-full bg-cafe px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-cafe-light">Emitir nota de débito</button></div>
            </div>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</section>

<script>
(function () {
    var BASE = <?= json_encode(BASE_URL) ?>, CSRF = <?= json_encode(csrf_token()) ?>, ID = <?= $cbId ?>;
    function clp(n) { return '$' + (parseInt(n, 10) || 0).toLocaleString('es-CL'); }
    function post(d) {
        d.id = d.id || ID;
        return fetch(BASE + '/api/panel/boletas', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(d) })
            .then(function (r) { return r.text().then(function (t) { var j = {}; try { j = JSON.parse(t); } catch (e) { j = { error: 'Respuesta inesperada del servidor.' }; } return { ok: r.ok && j.ok, d: j }; }); });
    }
    function listo(x) { if (!x.ok) { ezAlert(x.d.error || 'No se pudo completar.'); return; } location.hash = 'cobro'; location.reload(); }
    var EMBED = /[?&]embed=1\b/.test(location.search);
    /** Aviso «enviando» mientras se emite una nota (solo si va al SII: tarda unos segundos). */
    function enviandoNota(clase, sii) {
        return sii && window.ezEmision ? ezEmision.enviando({ titulo: 'Emitiendo ' + (clase === 'nc' ? 'nota de crédito' : 'nota de débito') + '…', texto: 'Preparando la nota y enviándola al SII. No cierres esta página.' }) : null;
    }
    /** Aviso en la misma pantalla: nota emitida y su estado en el SII (se actualiza solo). */
    function notaEmitida(clase, x, sii) {
        if (!window.ezEmision) { listo(x); return; }
        var d = x.d, nom = sii ? ezEmision.nombre(clase === 'nc' ? 61 : 56) : (clase === 'nc' ? 'Nota de crédito' : 'Nota de débito');
        ezEmision.listo({
            titulo: nom + ' N° ' + String(d.folio).padStart(6, '0') + ' emitida',
            subtitulo: (clase === 'nc' ? '− ' : '+ ') + clp(d.total),
            estado: sii ? (d.sii_estado || 'aceptada') : 'interno',
            trackid: d.trackid || '',
            ver: { url: BASE + '/admin/notas/' + clase + '/' + d.id + (EMBED ? '?embed=1' : ''), texto: clase === 'nc' ? 'Ver nota de crédito' : 'Ver nota de débito' },
            listo: { texto: 'Listo', accion: function () { location.hash = 'cobro'; location.reload(); } },
            revisar: function () {
                return post({ action: 'nota_reconsultar', clase: clase, nota_id: d.id, rapido: 1 })
                    .then(function (y) { return y.ok ? { estado: y.d.estado, mensaje: y.d.mensaje } : { estado: 'pendiente' }; });
            }
        });
    }
    var fp = document.getElementById('cb-pago');
    if (fp) fp.addEventListener('submit', function (e) {
        e.preventDefault();
        var dev = fp.tipo.value === 'devolucion';
        ezConfirm((dev ? 'Registrar una devolución de ' : 'Registrar un pago de ') + clp(fp.monto.value) + '?', { title: dev ? 'Devolución' : 'Pago', okText: 'Registrar' }).then(function (ok) {
            if (ok) post({ action: 'pago_registrar', tipo: fp.tipo.value, monto: fp.monto.value, fecha: fp.fecha.value, medio: fp.medio.value, referencia: fp.referencia.value }).then(listo);
        });
    });
    document.querySelectorAll('[data-pago-del]').forEach(function (b) {
        b.addEventListener('click', function () {
            ezConfirm('¿Eliminar este registro de pago? El estado de pago se recalcula.', { title: 'Eliminar pago', okText: 'Eliminar', danger: true }).then(function (ok) {
                if (ok) post({ action: 'pago_eliminar', pago_id: b.dataset.pagoDel }).then(listo);
            });
        });
    });
    var nb = document.getElementById('cb-nc-btn'), nf = document.getElementById('cb-nc');
    function ncAccion() { return nf ? nf.accion.value : 'devolucion'; }
    function totalNC() {
        if (!nf) return 0;
        var a = ncAccion(), t = 0;
        if (a === 'anulacion') t = parseInt(nf.dataset.disp, 10) || 0;
        else if (a === 'devolucion') {
            document.querySelectorAll('.cb-nc-res:checked').forEach(function (c) { t += parseInt(c.dataset.monto, 10) || 0; });
            t += parseInt(nf.monto.value, 10) || 0;
        }
        document.getElementById('cb-nc-total').textContent = 'Total: ' + clp(t);
        return t;
    }
    function ncModo() {
        var a = ncAccion();
        nf.querySelectorAll('[data-nc-montos]').forEach(function (x) { x.classList.toggle('hidden', a !== 'devolucion'); });
        document.querySelectorAll('.cb-nc-res').forEach(function (c) { c.disabled = a !== 'devolucion'; if (a !== 'devolucion') c.checked = false; });
        nf.querySelector('[data-nc-lbl-motivo]').textContent = a === 'correccion' ? 'Qué se corrige * (ej.: donde dice X debe decir Y)' : 'Motivo *';
        nf.querySelector('[data-nc-ayuda]').textContent = a === 'anulacion' ? 'Deja sin efecto todo lo que queda del documento; sus horas quedan libres para cobrarse de nuevo.'
            : (a === 'correccion' ? 'No cambia montos: deja constancia de la corrección de un dato del documento.' : 'Marca arriba las horas a devolver (quedan libres para cobrarse de nuevo) y/o escribe un monto.');
        totalNC();
    }
    if (nb && nf) {
        nb.addEventListener('click', function () { nf.classList.remove('hidden'); nf.motivo.focus(); ncModo(); });
        document.getElementById('cb-nc-x').addEventListener('click', function () { nf.classList.add('hidden'); });
        document.querySelectorAll('.cb-nc-res').forEach(function (c) { c.addEventListener('change', function () { nf.classList.remove('hidden'); if (ncAccion() !== 'devolucion') { nf.accion.value = 'devolucion'; ncModo(); } totalNC(); }); });
        nf.monto.addEventListener('input', totalNC);
        nf.accion.addEventListener('change', ncModo);
        nf.addEventListener('submit', function (e) {
            e.preventDefault();
            var a = ncAccion();
            var ids = a === 'devolucion' ? Array.prototype.map.call(document.querySelectorAll('.cb-nc-res:checked'), function (c) { return parseInt(c.value, 10); }) : [];
            var t = totalNC();
            if (!nf.motivo.value.trim()) { ezAlert(a === 'correccion' ? 'Indica qué se corrige.' : 'Indica el motivo de la nota de crédito.'); return; }
            if (a === 'devolucion' && t <= 0) { ezAlert('Marca las horas a devolver o escribe un monto.'); return; }
            var sii = nf.dataset.sii === '1';
            var txt = (sii ? 'Se emitirá una NOTA DE CRÉDITO ELECTRÓNICA real al SII' : 'Se emitirá una nota de crédito') +
                (a === 'correccion' ? ' que corrige un texto (sin monto).' : ' por ' + clp(t) + (a === 'anulacion' ? ' que anula lo que queda del documento.' : '') +
                (ids.length ? ' (' + ids.length + (ids.length === 1 ? ' hora queda libre' : ' horas quedan libres') + ')' : '') + '.') + (sii ? ' Consume un folio y no se puede deshacer.' : '');
            ezConfirm(txt, { title: 'Nota de crédito', okText: 'Emitir', danger: sii }).then(function (ok) {
                if (!ok) return;
                var btn = nf.querySelector('button[type=submit]'); btn.disabled = true; btn.textContent = sii ? 'Enviando al SII…' : 'Emitiendo…';
                var esp = enviandoNota('nc', sii);
                post({ action: 'nc_emitir', accion: a, motivo: nf.motivo.value.trim(), reserva_ids: ids, monto: a === 'devolucion' ? nf.monto.value : 0, descripcion: nf.descripcion.value.trim() })
                    .then(function (x) { if (esp) esp.cerrar(); if (!x.ok) { btn.disabled = false; btn.textContent = 'Emitir nota de crédito'; listo(x); return; } nf.classList.add('hidden'); notaEmitida('nc', x, sii); });
            });
        });
    }
    var db = document.getElementById('cb-nd-btn'), df = document.getElementById('cb-nd');
    function ndModo() {
        var a = df.accion.value;
        df.querySelectorAll('[data-nd-cargo]').forEach(function (x) { x.classList.toggle('hidden', a !== 'cargo'); });
        var sel = df.querySelector('[data-nd-nc]'); if (sel) { sel.classList.toggle('hidden', a !== 'anula_nc'); sel.classList.toggle('flex', a === 'anula_nc'); }
        totalND();
    }
    function totalND() {
        var t = 0;
        if (df.accion.value === 'anula_nc') { var o = df.nota_credito_id && df.nota_credito_id.selectedOptions[0]; t = o ? parseInt(o.dataset.monto, 10) || 0 : 0; }
        else t = parseInt(df.monto.value, 10) || 0;
        document.getElementById('cb-nd-total').textContent = 'Total: ' + clp(t);
        return t;
    }
    if (db && df) {
        db.addEventListener('click', function () { df.classList.remove('hidden'); df.motivo.focus(); ndModo(); });
        document.getElementById('cb-nd-x').addEventListener('click', function () { df.classList.add('hidden'); });
        df.accion.addEventListener('change', ndModo);
        df.monto.addEventListener('input', totalND);
        if (df.nota_credito_id) df.nota_credito_id.addEventListener('change', totalND);
        df.addEventListener('submit', function (e) {
            e.preventDefault();
            var a = df.accion.value, t = totalND(), sii = df.dataset.sii === '1';
            if (!df.motivo.value.trim()) { ezAlert('Indica el motivo de la nota de débito.'); return; }
            if (a === 'cargo' && (t <= 0 || !df.descripcion.value.trim())) { ezAlert('Escribe el monto y la descripción del cobro adicional.'); return; }
            var txt = (sii ? 'Se emitirá una NOTA DE DÉBITO ELECTRÓNICA real al SII' : 'Se emitirá una nota de débito') + ' por ' + clp(t) +
                (a === 'anula_nc' ? ' que anula la nota de crédito elegida (el monto vuelve a cobrarse y sus horas vuelven al documento si siguen libres).' : '.') + (sii ? ' Consume un folio y no se puede deshacer.' : '');
            ezConfirm(txt, { title: 'Nota de débito', okText: 'Emitir', danger: sii }).then(function (ok) {
                if (!ok) return;
                var btn = df.querySelector('button[type=submit]'); btn.disabled = true; btn.textContent = sii ? 'Enviando al SII…' : 'Emitiendo…';
                var esp = enviandoNota('nd', sii);
                post({ action: 'nd_emitir', accion: a, motivo: df.motivo.value.trim(), monto: df.monto.value, descripcion: df.descripcion.value.trim(), nota_credito_id: df.nota_credito_id ? df.nota_credito_id.value : 0 })
                    .then(function (x) { if (esp) esp.cerrar(); if (!x.ok) { btn.disabled = false; btn.textContent = 'Emitir nota de débito'; listo(x); return; } df.classList.add('hidden'); notaEmitida('nd', x, sii); });
            });
        });
    }
    document.querySelectorAll('[data-nota-sii]').forEach(function (b) {
        b.addEventListener('click', function () {
            var p = b.dataset.notaSii.split(':'); b.disabled = true; b.textContent = 'Consultando…';
            post({ action: 'nota_reconsultar', clase: p[0], nota_id: p[1] }).then(function (x) {
                if (!x.ok) { ezAlert(x.d.error || 'No se pudo consultar.'); b.disabled = false; b.textContent = 'Reconsultar estado SII'; return; }
                ezAlert(x.d.mensaje || 'Estado actualizado.').then(function () { location.hash = 'cobro'; location.reload(); });
            });
        });
    });
    var pideNota = new URLSearchParams(location.search).get('nota');
    if (pideNota === 'nc' && nb) { nb.click(); nf.scrollIntoView({ block: 'center' }); }
    else if (pideNota === 'nd' && db) { db.click(); df.scrollIntoView({ block: 'center' }); }
    else if (pideNota) { var cb = document.getElementById('cobro'); if (cb) cb.scrollIntoView(); }
    var an = document.getElementById('cb-anular');
    if (an) an.addEventListener('click', function () {
        ezPrompt('Motivo de la anulación (el documento deja de contar como ingreso y sus horas quedan libres):', { title: 'Anular documento', okText: 'Anular', placeholder: 'Ej.: emitida por error al cliente equivocado' }).then(function (m) {
            if (m === null) return;
            if (!String(m).trim()) { ezAlert('Indica el motivo.'); return; }
            post({ action: 'anular', motivo: String(m).trim() }).then(function (x) {
                if (!x.ok) { ezAlert(x.d.error || 'No se pudo anular.'); return; }
                var msg = 'Documento anulado.' + (x.d.horas_liberadas ? ' ' + x.d.horas_liberadas + ' hora(s) quedaron libres para volver a cobrarse.' : '') + (x.d.devolver ? ' Se habían pagado ' + clp(x.d.devolver) + ': registra la devolución cuando la hagas.' : '');
                ezAlert(msg).then(function () { location.hash = 'cobro'; location.reload(); });
            });
        });
    });
})();
</script>
