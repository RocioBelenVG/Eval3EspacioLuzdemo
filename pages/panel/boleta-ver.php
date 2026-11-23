<?php
/** Panel · Ver / previsualizar / imprimir / enviar documento. El router define $boletaId. */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/boletas.php';
require_once APP_ROOT . '/lib/boleta_pdf.php';          // boleta_doc_v()
require_once APP_ROOT . '/partials/panel/doc-preview.php'; // doc_preview_html()

$boleta = getBoleta((int) ($boletaId ?? 0));
if (!$boleta) {
    http_response_code(404);
    $panelTitle = 'Documento no encontrado';
    $panelActive = 'boletas';
    include APP_ROOT . '/partials/panel/top.php';
    echo '<div class="rounded-2xl border border-cafe/10 bg-crema-light p-8 text-center font-body text-cafe/60">Ese documento no existe. <a href="' . e(base_path('/admin/documentos')) . '" class="text-terracota-dark">Volver a Documentos</a></div>';
    include APP_ROOT . '/partials/panel/bottom.php';
    return;
}

$folio = str_pad((string) $boleta['folio'], 6, '0', STR_PAD_LEFT);
$docLabel = tipoDocLabel($boleta['tipo'] ?? 'boleta');
$pdfBase = base_path('/admin/dte/pdf?boleta=' . (int) $boleta['id']);
$initFmt = in_array($_GET['f'] ?? '', ['carta', '80', '58'], true) ? $_GET['f'] : 'carta';
$fmtKey = ['carta' => 'carta', '80' => '80mm', '58' => '58mm'];

// Datos del documento. Si ya es un DTE real (timbrado), enriquecer con el timbre.
$v = boleta_doc_v($boleta);
if (($boleta['sii_estado'] ?? 'no_enviada') !== 'no_enviada') {
    $v['mvp_note'] = '';
    try {
        [$conex] = require APP_ROOT . '/dte/bootstrap.php';
        require_once APP_ROOT . '/dte/lib/dte_doc.php';
        $bid = (int) $boleta['id'];
        $st = $conex->prepare("SELECT d.trackid, se.xml_dte FROM dte_documentos d LEFT JOIN sii_envios se ON se.id=d.sii_envio_id WHERE d.boleta_id=? ORDER BY d.id DESC LIMIT 1");
        $st->bind_param('i', $bid); $st->execute();
        if ($row = $st->get_result()->fetch_assoc()) {
            if (!empty($row['xml_dte'])) $v['timbre_uri'] = dte_timbre_png_datauri((string) $row['xml_dte']);
            if (!empty($row['trackid'])) $v['trackid'] = (string) $row['trackid'];
            $res = dte_resolucion();
            require_once APP_ROOT . '/lib/dte_integra.php';
            $resEmp = dteResolucionEmpresa(getEmpresa((int) $boleta['empresa_id']) ?? []);
            if ($resEmp['numero'] !== null && $resEmp['fecha'] !== null) $res = $resEmp;
            if (!empty($res['numero'])) {
                $rl = 'Res. N° ' . (int) $res['numero'];
                if (!empty($res['fecha'])) { $ts = strtotime($res['fecha']); if ($ts) $rl .= ' de ' . date('d-m-Y', $ts); }
                $v['res_linea'] = $rl;
            }
        }
        $st->close();
    } catch (Throwable $e) { /* sin timbre en la vista previa */ }
}

$panelTitle = $docLabel . ' N° ' . $folio;
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
    <a href="<?= e(base_path('/admin/documentos?empresa=' . (int) $boleta['empresa_id'])) ?>" class="inline-flex items-center gap-1.5 font-body text-sm font-semibold text-cafe/60 hover:text-terracota">← Volver a Documentos</a>
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
        <a id="btn-dl" href="<?= e($pdfBase . '&f=' . $initFmt . '&dl=1') ?>" class="inline-flex items-center gap-2 rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe transition-colors hover:border-terracota hover:text-terracota">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>
            Descargar PDF
        </a>
        <button type="button" id="btn-email" class="inline-flex items-center gap-2 rounded-full bg-terracota px-4 py-2 font-body text-sm font-semibold text-crema-light transition-colors hover:bg-terracota-dark">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"/><path d="M4 6l8 6 8-6"/></svg>
            Enviar por correo
        </button>
    </div>
</div>
<p id="email-msg" class="no-print mb-4 hidden rounded-lg px-4 py-2 font-body text-sm"></p>
<?php include APP_ROOT . '/partials/panel/doc-cobro.php'; ?>

<div id="doc-area" class="rounded-2xl border border-cafe/10 bg-crema p-5 sm:p-8">
    <div class="doc-fmt" data-fmt="carta"><?= doc_preview_html($v, 'carta') ?></div>
    <div class="doc-fmt" data-fmt="80"><?= doc_preview_html($v, '80mm') ?></div>
    <div class="doc-fmt" data-fmt="58"><?= doc_preview_html($v, '58mm') ?></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var P = window.__PANEL__ || {};
    var PDFBASE = <?= json_encode($pdfBase) ?>;
    var current = <?= json_encode($initFmt) ?>;
    var dl = document.getElementById('btn-dl');

    function markActive(f) {
        document.querySelectorAll('.fmt-btn').forEach(function (b) { b.classList.toggle('active', b.dataset.fmt === f); });
        document.querySelectorAll('.doc-fmt').forEach(function (d) { d.classList.toggle('active', d.dataset.fmt === f); });
        dl.href = PDFBASE + '&f=' + f + '&dl=1';
        current = f;
    }
    document.querySelectorAll('.fmt-btn').forEach(function (b) {
        b.addEventListener('click', function () { markActive(b.dataset.fmt); });
    });
    markActive(current);

    document.getElementById('btn-print').addEventListener('click', function () { window.print(); });

    // --- Enviar por correo ---
    var btn = document.getElementById('btn-email');
    var msg = document.getElementById('email-msg');
    var receptorEmail = <?= json_encode($boleta['receptor_email'] ?? '') ?>;
    function show(text, ok) {
        msg.textContent = text;
        msg.className = 'no-print mb-4 rounded-lg px-4 py-2 font-body text-sm ' + (ok ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-700');
        msg.classList.remove('hidden');
    }
    if (btn) btn.addEventListener('click', function () {
        ezPrompt('Enviar el documento al correo:', { title: 'Enviar documento', okText: 'Enviar', value: receptorEmail || '', placeholder: 'correo@ejemplo.cl' }).then(function (dest) {
            if (dest === null) return;
            dest = dest.trim();
            if (!dest) { show('Ingresa un correo.', false); return; }
            btn.disabled = true;
            fetch(P.base + '/api/panel/boletas', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf },
                body: JSON.stringify({ action: 'email', id: <?= (int) $boleta['id'] ?>, email: dest })
            }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
              .then(function (x) {
                btn.disabled = false;
                if (!x.ok) { show(x.d.error || 'No se pudo enviar.', false); return; }
                show('Documento enviado a ' + x.d.email, true);
              }).catch(function () { btn.disabled = false; show('No se pudo enviar.', false); });
        });
    });
});
</script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
