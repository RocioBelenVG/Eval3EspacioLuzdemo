<?php
/**
 * Panel · Ver una nota de crédito (61) o de débito (56).  El router define $notaClase ('nc'|'nd') y $notaId.
 * Mismo formato que la vista de boletas/facturas (doc_preview_html: carta / 80 mm / 58 mm), con el
 * estado SII y el documento de referencia, como documento_ver de Trivoxa-DTE.
 */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/boletas.php';
require_once APP_ROOT . '/lib/cobros.php';
require_once APP_ROOT . '/lib/boleta_pdf.php';            // boleta_doc_v() + dte_pdf_doc_v()
require_once APP_ROOT . '/partials/panel/doc-preview.php'; // doc_preview_html()

$clase = ($notaClase ?? 'nc') === 'nd' ? 'nd' : 'nc';
$esNC = $clase === 'nc';
$tabla = $esNC ? 'notas_credito' : 'notas_debito';
$nota = null;
$items = [];
try {
    $st = db()->prepare("SELECT * FROM $tabla WHERE id = ?");
    $st->execute([(int) ($notaId ?? 0)]);
    $nota = $st->fetch() ?: null;
    if ($nota) {
        $si = db()->prepare("SELECT * FROM {$tabla}_items WHERE nota_id = ? ORDER BY id");
        $si->execute([(int) $nota['id']]);
        $items = $si->fetchAll();
    }
} catch (Throwable $e) {
    $nota = null;
}
$tipo = $esNC ? 61 : 56;
$nombre = $esNC ? 'Nota de Crédito Electrónica' : 'Nota de Débito Electrónica';

if (!$nota) {
    http_response_code(404);
    $panelTitle = 'Documento no encontrado';
    $panelActive = 'boletas';
    include APP_ROOT . '/partials/panel/top.php';
    echo '<div class="rounded-2xl border border-cafe/10 bg-crema-light p-8 text-center font-body text-cafe/60">Esa nota no existe. <a href="' . e(base_path('/admin/documentos')) . '" class="text-terracota-dark">Volver a Documentos</a></div>';
    include APP_ROOT . '/partials/panel/bottom.php';
    return;
}

$doc = getBoleta((int) $nota['documento_id']);
$folio = cobroFolio((int) $nota['folio']);
$sii = ($nota['serie'] ?? '') === 'sii';
$dteId = (int) ($nota['dte_documento_id'] ?? 0);
$esAdmin = isAdmin();
$vale = $nota['estado'] === 'emitida';
$siiEst = (string) ($nota['sii_estado'] ?? 'no_enviada');
$estado = !$vale ? 'anulado' : (!$sii ? 'interno' : ($siiEst === 'rechazada' ? 'rechazado' : 'enviado'));
$ESTADOS = ['enviado' => ['Enviado', '#6D8072'], 'rechazado' => ['Rechazado', '#dc2626'], 'anulado' => ['Anulado', '#8a7c70'], 'interno' => ['Interno', '#B85C3E']];
[$eTx, $eCol] = $ESTADOS[$estado];
$codRef = [1 => 'Anula documento', 2 => 'Corrige texto', 3 => 'Corrige montos'];
$docTipo = $doc ? boleta_tipo_dte($doc) : 0;
$docNombre = $doc ? [33 => 'Factura Electrónica', 34 => 'Factura Exenta Electrónica', 39 => 'Boleta Electrónica', 41 => 'Boleta Exenta Electrónica'][$docTipo] : '';
$ndAnula = 0;
if ($esNC) {
    try {
        $q = db()->prepare("SELECT folio FROM notas_debito WHERE nota_credito_id = ? AND estado = 'emitida' LIMIT 1");
        $q->execute([(int) $nota['id']]);
        $ndAnula = (int) $q->fetchColumn();
    } catch (Throwable $e) {
    }
}
$ncFolio = 0;
if (!$esNC && !empty($nota['nota_credito_id'])) {
    try {
        $q = db()->prepare("SELECT folio FROM notas_credito WHERE id = ?");
        $q->execute([(int) $nota['nota_credito_id']]);
        $ncFolio = (int) $q->fetchColumn();
    } catch (Throwable $e) {
    }
}

// Datos del documento: si fue al SII, los mismos del PDF (con timbre); si es interna, desde el panel.
$v = null;
if ($sii && $dteId > 0) {
    try {
        [$conex] = require APP_ROOT . '/dte/bootstrap.php';
        $v = dte_pdf_doc_v($conex, $dteId);
    } catch (Throwable $e) {
        $v = null;
    }
}
if (!$v) {
    $v = $doc ? boleta_doc_v($doc) : ['emisor' => [], 'receptor' => [], 'profesional' => []];
    $det = [];
    foreach ($items as $it) {
        if ((int) $it['cantidad'] <= 0) continue; // horas liberadas (constancia, sin monto)
        $det[] = ['descripcion' => $it['descripcion'], 'cantidad' => (int) $it['cantidad'], 'precio' => (int) $it['precio_unit_clp'], 'total' => (int) $it['total_clp'], 'exento' => empty($it['afecto']) ? 1 : 0];
    }
    $refTxt = $doc ? $docNombre . ' N° ' . cobroFolio((int) $doc['folio']) . ' del ' . date('d-m-Y', strtotime((string) $doc['fecha_emision'])) : '';
    if (!empty($nota['codigo_ref'])) $refTxt .= ' · ' . ($codRef[(int) $nota['codigo_ref']] ?? '');
    $refTxt .= ' · ' . $nota['motivo'];
    $ts = strtotime((string) $nota['fecha_emision']);
    $v = array_merge($v, [
        'tipo' => $tipo, 'folio' => str_pad((string) $nota['folio'], 6, '0', STR_PAD_LEFT), 'fecha' => $ts ? date('d-m-Y H:i', $ts) : '',
        'medio' => '', 'trackid' => (string) ($nota['sii_track_id'] ?? ''), 'items' => $det,
        'neto' => (int) $nota['neto_clp'], 'iva' => (int) $nota['iva_clp'], 'exento' => (int) $nota['exento_clp'], 'total' => (int) $nota['total_clp'],
        'observaciones' => '', 'referencias' => [['texto' => $refTxt]], 'timbre_uri' => '', 'res_linea' => '',
        'mvp_note' => $sii ? '' : 'Nota interna: no fue enviada al SII y no lleva timbre electrónico.',
    ]);
}

$conPdf = $sii && $dteId > 0 && $esAdmin;
$pdfBase = base_path('/admin/dte/pdf?doc=' . $dteId);
$initFmt = in_array($_GET['f'] ?? '', ['carta', '80', '58'], true) ? $_GET['f'] : 'carta';

$panelTitle = $nombre . ' N° ' . $folio;
$panelActive = 'boletas';
include APP_ROOT . '/partials/panel/top.php';
?>
<style>
    .fmt-btn.active { background: #D97757; color: #FBF9F4; }
    .doc-fmt { display: none; }
    .doc-fmt.active { display: block; }
    @media print {
        #pnl-sidebar, #pnl-overlay, header, .no-print { display: none !important; }
        .lg\:pl-64 { padding-left: 0 !important; }
        main { padding: 0 !important; }
        body { background: #fff !important; }
        #doc-area { background: #fff !important; border: 0 !important; box-shadow: none !important; padding: 0 !important; }
    }
</style>

<div class="no-print mb-4 flex flex-wrap items-center justify-between gap-3">
    <a href="<?= e(base_path('/admin/documentos?empresa=' . (int) $nota['empresa_id'])) ?>" class="inline-flex items-center gap-1.5 font-body text-sm font-semibold text-cafe/60 hover:text-terracota">← Volver a Documentos</a>
    <div class="flex flex-wrap items-center gap-2">
        <span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/45">Formato:</span>
        <div class="inline-flex overflow-hidden rounded-full border border-cafe/15">
            <button type="button" data-fmt="carta" class="fmt-btn px-4 py-2 font-body text-sm font-semibold text-cafe transition-colors hover:bg-cafe/5">Carta</button>
            <button type="button" data-fmt="80" class="fmt-btn border-l border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe transition-colors hover:bg-cafe/5">80 mm</button>
            <button type="button" data-fmt="58" class="fmt-btn border-l border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe transition-colors hover:bg-cafe/5">58 mm</button>
        </div>
        <button type="button" id="btn-print" class="inline-flex items-center gap-2 rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe transition-colors hover:border-terracota hover:text-terracota">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
            Imprimir
        </button>
        <?php if ($conPdf): ?>
        <a id="btn-dl" href="<?= e($pdfBase . '&f=' . $initFmt . '&dl=1') ?>" class="inline-flex items-center gap-2 rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe transition-colors hover:border-terracota hover:text-terracota">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>
            Descargar PDF
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Estado y referencia -->
<section class="no-print mb-5 rounded-2xl border border-cafe/10 bg-white p-5 shadow-card sm:p-6" style="border-left:5px solid <?= $eCol ?>">
    <div class="flex flex-wrap items-center gap-2">
        <span class="rounded-full px-3 py-0.5 font-body text-xs font-bold text-white" style="background:<?= $eCol ?>"><?= e($eTx) ?></span>
        <span class="rounded-full bg-cafe/8 px-2 py-0.5 font-body text-[11px] font-bold text-cafe/60"><?= $sii ? 'SII · ' . $tipo : 'interna (sin timbre SII)' ?></span>
        <?php if (!empty($nota['sii_track_id'])): ?><span class="font-body text-xs text-cafe/55">TrackID: <?= e($nota['sii_track_id']) ?></span><?php endif; ?>
        <?php if ($sii && $esAdmin && $dteId > 0 && $vale): ?>
            <button type="button" id="btn-sii" class="ml-auto rounded-full border-2 border-cafe/15 px-3 py-1.5 font-body text-xs font-semibold text-cafe hover:border-terracota hover:text-terracota">↻ Actualizar estado con el SII</button>
        <?php endif; ?>
    </div>
    <p id="sii-msg" class="mt-2 hidden font-body text-xs text-cafe/70"></p>
    <div class="mt-3 font-body text-sm text-cafe">
        <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-terracota-dark">Documento de referencia</p>
        <?php if ($doc): ?>
            <p class="mt-1"><a href="<?= e(base_path('/admin/boletas/' . (int) $doc['id'])) ?>" class="font-semibold text-terracota-dark hover:underline"><?= e($docNombre) ?> N° <?= e(cobroFolio((int) $doc['folio'])) ?></a>
                del <?= e(date('d-m-Y', strtotime((string) $doc['fecha_emision']))) ?> · <?= e(clp((int) $doc['total_clp'])) ?>
                <?php if (!empty($nota['codigo_ref'])): ?> · <strong><?= e($codRef[(int) $nota['codigo_ref']] ?? '') ?></strong><?php endif; ?>
                <?php if ($ncFolio): ?> · anula la nota de crédito N° <?= e(cobroFolio($ncFolio)) ?><?php endif; ?></p>
        <?php else: ?>
            <p class="mt-1 text-cafe/60">No se encontró el documento original.</p>
        <?php endif; ?>
        <p class="mt-1 text-cafe/75"><strong>Motivo:</strong> <?= e((string) $nota['motivo']) ?></p>
        <?php if ($esNC && ($nota['accion'] ?? '') === 'anulacion' && $vale): ?>
            <p class="mt-1 text-xs text-cafe/60">El documento original sigue en el libro de ventas: esta nota de crédito lo deja en cero, como exige el SII.</p>
        <?php endif; ?>
        <?php if ($ndAnula): ?><p class="mt-1 text-xs font-semibold text-amber-800">Esta nota de crédito fue anulada con la nota de débito N° <?= e(cobroFolio($ndAnula)) ?>.</p><?php endif; ?>
    </div>
</section>

<div id="doc-area" class="rounded-2xl border border-cafe/10 bg-crema p-5 sm:p-8">
    <div class="doc-fmt" data-fmt="carta"><?= doc_preview_html($v, 'carta') ?></div>
    <div class="doc-fmt" data-fmt="80"><?= doc_preview_html($v, '80mm') ?></div>
    <div class="doc-fmt" data-fmt="58"><?= doc_preview_html($v, '58mm') ?></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var P = window.__PANEL__ || {};
    var PDFBASE = <?= json_encode($pdfBase) ?>, dl = document.getElementById('btn-dl');
    function marcar(f) {
        document.querySelectorAll('.fmt-btn').forEach(function (b) { b.classList.toggle('active', b.dataset.fmt === f); });
        document.querySelectorAll('.doc-fmt').forEach(function (d) { d.classList.toggle('active', d.dataset.fmt === f); });
        if (dl) dl.href = PDFBASE + '&f=' + f + '&dl=1';
    }
    document.querySelectorAll('.fmt-btn').forEach(function (b) { b.addEventListener('click', function () { marcar(b.dataset.fmt); }); });
    marcar(<?= json_encode($initFmt) ?>);
    document.getElementById('btn-print').addEventListener('click', function () { window.print(); });

    var bs = document.getElementById('btn-sii'), msg = document.getElementById('sii-msg');
    if (bs) bs.addEventListener('click', function () {
        bs.disabled = true; bs.textContent = 'Consultando al SII…';
        fetch(P.base + '/api/panel/boletas', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf },
            body: JSON.stringify({ action: 'nota_reconsultar', clase: <?= json_encode($clase) ?>, nota_id: <?= (int) $nota['id'] ?> }) })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                msg.classList.remove('hidden');
                msg.textContent = (j && (j.mensaje || j.error)) || 'Consulta realizada.';
                if (j && j.ok) { setTimeout(function () { location.reload(); }, 1200); return; }
                bs.disabled = false; bs.textContent = '↻ Actualizar estado con el SII';
            })
            .catch(function () { msg.classList.remove('hidden'); msg.textContent = 'No se pudo consultar el estado.'; bs.disabled = false; bs.textContent = '↻ Actualizar estado con el SII'; });
    });
});
</script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
