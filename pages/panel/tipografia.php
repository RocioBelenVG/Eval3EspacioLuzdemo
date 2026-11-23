<?php
/** Panel · Apariencia — elegir las tipografías del sitio (solo admin). */
declare(strict_types=1);

requireAdminPage();
require_once APP_ROOT . '/partials/fonts.php';

$sel = ezFontsSaved();
$hCss = EZ_FONTS_HEADING[$sel['heading']]['css'];
$bCss = EZ_FONTS_BODY[$sel['body']]['css'];

// Mapa key => css para la vista previa en JS.
$cssMap = ['heading' => [], 'body' => []];
foreach (EZ_FONTS_HEADING as $k => $f) { $cssMap['heading'][$k] = $f['css']; }
foreach (EZ_FONTS_BODY as $k => $f) { $cssMap['body'][$k] = $f['css']; }

// <link> con TODAS las fuentes del catálogo, para las vistas previas de la página.
$allSpecs = [];
foreach (EZ_FONTS_HEADING as $f) { $allSpecs[] = $f['google']; }
foreach (EZ_FONTS_BODY as $f) { $allSpecs[] = $f['google']; }
$allHref = 'https://fonts.googleapis.com/css2?family=' . implode('&family=', array_values(array_unique($allSpecs))) . '&display=swap';

$panelTitle = 'Apariencia';
$panelActive = 'apariencia';
include APP_ROOT . '/partials/panel/top.php';
?>
<link href="<?= e($allHref) ?>" rel="stylesheet">
<div class="mx-auto max-w-3xl">
    <div class="rounded-2xl border border-cafe/8 bg-crema-light p-6 shadow-card sm:p-8">
        <h2 class="font-heading text-xl font-semibold text-cafe">Tipografía del sitio</h2>
        <p class="mt-1 font-body text-sm text-cafe/60">Elige las fuentes de <b>Títulos</b> y <b>Texto</b>. Al guardar, el cambio se aplica en todo el sitio público y el panel. (El estilo decorativo de las iniciales no cambia.)</p>

        <div class="mt-6 grid gap-5 sm:grid-cols-2">
            <label class="flex flex-col gap-1.5">
                <span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Títulos</span>
                <select id="f-heading">
                    <?php foreach (EZ_FONTS_HEADING as $k => $f): ?>
                        <option value="<?= e($k) ?>" style="font-family: <?= e($f['css']) ?>" <?= $k === $sel['heading'] ? 'selected' : '' ?>><?= e($f['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="flex flex-col gap-1.5">
                <span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Texto y botones</span>
                <select id="f-body">
                    <?php foreach (EZ_FONTS_BODY as $k => $f): ?>
                        <option value="<?= e($k) ?>" style="font-family: <?= e($f['css']) ?>" <?= $k === $sel['body'] ? 'selected' : '' ?>><?= e($f['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="mt-6 rounded-xl border border-cafe/10 bg-crema p-6">
            <span class="font-body text-[11px] font-bold uppercase tracking-[0.18em] text-terracota">Vista previa</span>
            <h3 id="pv-h" class="mt-2 text-3xl font-semibold leading-tight text-cafe sm:text-4xl" style="font-family: <?= e($hCss) ?>">Bienvenido a Espacio <span class="text-terracota-light">Luz</span></h3>
            <p id="pv-b" class="mt-3 text-base leading-relaxed text-cafe/80" style="font-family: <?= e($bCss) ?>">Centro integral de terapias y bienestar. Un lugar pensado para acompañarte a ti y a tu familia con calidez, cercanía y profesionalismo.</p>
            <button id="pv-btn" type="button" class="mt-4 rounded-full bg-terracota px-6 py-3 text-sm font-semibold text-crema-light" style="font-family: <?= e($bCss) ?>">Agenda tu hora</button>
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <button id="f-save" type="button" class="rounded-full bg-terracota px-6 py-2.5 font-body text-sm font-semibold text-crema-light transition-colors hover:bg-terracota-dark">Guardar cambios</button>
            <span id="f-msg" class="hidden font-body text-sm"></span>
        </div>
    </div>
</div>
<script>
(function () {
    var CSS = <?= json_encode($cssMap, JSON_UNESCAPED_SLASHES) ?>;
    var hSel = document.getElementById('f-heading'), bSel = document.getElementById('f-body');
    var pvH = document.getElementById('pv-h'), pvB = document.getElementById('pv-b'), pvBtn = document.getElementById('pv-btn');
    function applyPreview() {
        pvH.style.fontFamily = CSS.heading[hSel.value] || '';
        pvB.style.fontFamily = CSS.body[bSel.value] || '';
        pvBtn.style.fontFamily = CSS.body[bSel.value] || '';
    }
    hSel.addEventListener('change', applyPreview);
    bSel.addEventListener('change', applyPreview);
    applyPreview();
    document.getElementById('f-save').addEventListener('click', function () {
        var P = window.__PANEL__ || {};
        var btn = this, t = btn.textContent, msg = document.getElementById('f-msg');
        btn.disabled = true; btn.textContent = 'Guardando…'; msg.classList.add('hidden');
        fetch(P.base + '/api/content', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf },
            body: JSON.stringify({ target: { type: 'fonts' }, heading: hSel.value, body: bSel.value })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, d: j }; }); })
          .then(function (x) {
            btn.disabled = false; btn.textContent = t; msg.classList.remove('hidden');
            if (x.ok) { msg.textContent = '✓ Guardado. Recarga el sitio para ver el cambio.'; msg.className = 'font-body text-sm font-semibold text-salvia-dark'; }
            else { msg.textContent = (x.d && x.d.error) || 'No se pudo guardar.'; msg.className = 'font-body text-sm font-semibold text-red-600'; }
          })
          .catch(function () { btn.disabled = false; btn.textContent = t; msg.classList.remove('hidden'); msg.textContent = 'Error de conexión.'; msg.className = 'font-body text-sm font-semibold text-red-600'; });
    });
})();
</script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
