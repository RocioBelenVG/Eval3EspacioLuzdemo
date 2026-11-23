<?php
/**
 * Visor de firma y timbre (panel · Profesionales).
 * Se abre al hacer clic en la miniatura de la firma ([data-firma-ver] dentro de tr[data-id]).
 * Muestra cómo se imprime en el certificado (recuadro fijo 400×240 px sobre la línea)
 * y la imagen original sobre cuadriculado de transparencia, con datos técnicos.
 */
declare(strict_types=1);
?>
<style>
    .fv-damero { background-color: #fff; background-image: conic-gradient(#EEE8DE 25%, transparent 0 50%, #EEE8DE 0 75%, transparent 0); background-size: 18px 18px; }
    .fv-hoja { background: #fff; box-shadow: 0 1px 2px rgba(92,64,51,.08), 0 18px 40px -18px rgba(92,64,51,.35); }
    .fv-tab { border-radius: 9999px; padding: .45rem 1rem; font-size: .8rem; font-weight: 700; color: rgba(92,64,51,.6); transition: all .15s ease; }
    .fv-tab[aria-selected="true"] { background: #5C4033; color: #FBF9F4; }
    .fv-tab:not([aria-selected="true"]):hover { color: #B85C3E; }
    .firma-box.fv-on { cursor: zoom-in; transition: box-shadow .15s ease, border-color .15s ease; }
    .firma-box.fv-on:hover, .firma-box.fv-on:focus-visible { border-color: #D97757; border-style: solid; box-shadow: 0 0 0 3px rgba(217,119,87,.18); outline: none; }
    .firma-box .fv-lupa { opacity: 0; transition: opacity .15s ease; }
    .firma-box.fv-on:hover .fv-lupa, .firma-box.fv-on:focus-visible .fv-lupa { opacity: 1; }
    #fv-modal[data-zoom="1"] #fv-orig-img { max-width: none; width: 200%; }
</style>

<div id="fv-modal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-cafe/60 p-3 backdrop-blur-sm sm:p-6" role="dialog" aria-modal="true" aria-labelledby="fv-titulo">
    <div class="flex max-h-[94vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-crema-light shadow-warm">
        <!-- Cabecera -->
        <div class="flex items-start justify-between gap-4 border-b border-cafe/10 px-5 py-4 sm:px-6">
            <div class="min-w-0">
                <p class="font-body text-[11px] font-bold uppercase tracking-[0.14em] text-terracota-dark">Firma y timbre</p>
                <h3 id="fv-titulo" class="truncate font-heading text-xl font-semibold text-cafe"></h3>
                <p id="fv-area" class="font-body text-sm text-cafe/55"></p>
            </div>
            <button type="button" id="fv-cerrar-x" class="-mr-1 rounded-full p-2 text-cafe/50 hover:bg-cafe/5 hover:text-cafe" aria-label="Cerrar">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <!-- Pestañas -->
        <div class="flex flex-wrap items-center justify-between gap-2 px-5 pt-4 sm:px-6">
            <div class="inline-flex rounded-full border border-cafe/10 bg-white p-1 font-body" role="tablist">
                <button type="button" class="fv-tab" role="tab" data-fv-tab="cert" aria-selected="true">Así se imprime</button>
                <button type="button" class="fv-tab" role="tab" data-fv-tab="orig" aria-selected="false">Imagen original</button>
            </div>
            <button type="button" id="fv-zoom" class="hidden rounded-full border border-cafe/15 bg-white px-3 py-1.5 font-body text-xs font-semibold text-cafe/70 hover:border-terracota hover:text-terracota">Ampliar ×2</button>
        </div>

        <!-- Contenido -->
        <div class="min-h-0 flex-1 overflow-auto px-5 py-4 sm:px-6">
            <!-- Vista en el certificado: recuadro fijo 400×240 sobre la línea, como en el PDF -->
            <div data-fv-panel="cert" class="rounded-xl bg-crema/60 p-4 sm:p-6">
                <div class="fv-hoja mx-auto max-w-[520px] rounded-md px-6 pb-6 pt-5 sm:px-10">
                    <p class="font-body text-[11px] leading-relaxed text-cafe/45">… Se extiende el presente certificado a solicitud del interesado, para los fines que estime convenientes.</p>
                    <div class="mt-3 text-center">
                        <div class="mx-auto w-full max-w-[400px]" style="aspect-ratio:5/3;">
                            <img id="fv-cert-img" alt="Firma y timbre tal como se imprime" class="h-full w-full object-contain">
                        </div>
                        <div class="mx-auto mt-1 w-[90%] max-w-[360px] border-t-[1.5px] border-cafe"></div>
                    </div>
                </div>
                <p class="mt-3 text-center font-body text-[11px] text-cafe/50">Vista a escala del certificado de atención. Todas las firmas se ajustan a este mismo recuadro.</p>
            </div>
            <!-- Imagen original sobre cuadriculado (muestra la transparencia) -->
            <div data-fv-panel="orig" class="hidden">
                <div id="fv-orig-wrap" class="fv-damero overflow-auto rounded-xl border border-cafe/10 p-3 text-center">
                    <img id="fv-orig-img" alt="Imagen original de la firma" class="mx-auto max-w-full">
                </div>
                <p class="mt-2 text-center font-body text-[11px] text-cafe/50">El cuadriculado indica las zonas transparentes: en el documento solo se imprime el trazo y el timbre.</p>
            </div>
        </div>

        <!-- Datos técnicos + acciones -->
        <div class="border-t border-cafe/10 bg-white/60 px-5 py-4 sm:px-6">
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 font-body text-xs sm:grid-cols-4">
                <div><dt class="font-semibold uppercase tracking-wide text-cafe/45">Resolución</dt><dd id="fv-res" class="mt-0.5 text-cafe/85">—</dd></div>
                <div><dt class="font-semibold uppercase tracking-wide text-cafe/45">Tamaño impreso</dt><dd class="mt-0.5 text-cafe/85">10,6 × 6,4 cm</dd></div>
                <div><dt class="font-semibold uppercase tracking-wide text-cafe/45">Nitidez al imprimir</dt><dd id="fv-ppp" class="mt-0.5 text-cafe/85">—</dd></div>
                <div><dt class="font-semibold uppercase tracking-wide text-cafe/45">Actualizada</dt><dd id="fv-fecha" class="mt-0.5 text-cafe/85">—</dd></div>
            </dl>
            <div class="mt-4 flex flex-wrap items-center justify-end gap-2 font-body">
                <a id="fv-abrir" href="#" target="_blank" rel="noopener" class="rounded-full border-2 border-cafe/15 px-4 py-2 text-sm font-semibold text-cafe hover:border-terracota hover:text-terracota">Abrir en tamaño real</a>
                <button type="button" id="fv-cambiar" class="rounded-full border-2 border-terracota/40 px-4 py-2 text-sm font-semibold text-terracota-dark hover:bg-terracota/10">Cambiar firma</button>
                <button type="button" id="fv-cerrar" class="rounded-full bg-cafe px-5 py-2 text-sm font-semibold text-crema-light hover:bg-cafe-light">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('fv-modal'); if (!modal) return;
    var imgCert = document.getElementById('fv-cert-img'), imgOrig = document.getElementById('fv-orig-img');
    var zoomBtn = document.getElementById('fv-zoom'), filaActual = null, focoPrevio = null;
    // 10,6 cm de ancho impreso = 4,17 pulgadas.
    var PULGADAS = 400 / 96;

    function tab(nombre) {
        modal.querySelectorAll('[data-fv-tab]').forEach(function (b) { b.setAttribute('aria-selected', b.dataset.fvTab === nombre ? 'true' : 'false'); });
        modal.querySelectorAll('[data-fv-panel]').forEach(function (p) { p.classList.toggle('hidden', p.dataset.fvPanel !== nombre); });
        zoomBtn.classList.toggle('hidden', nombre !== 'orig');
        modal.dataset.zoom = '0'; zoomBtn.textContent = 'Ampliar ×2';
    }
    function abrir(tr) {
        var img = tr.querySelector('.firma-img'); if (!img || img.classList.contains('hidden') || !img.getAttribute('src')) return;
        filaActual = tr; focoPrevio = document.activeElement;
        var d = {}; try { d = JSON.parse(tr.dataset.json || '{}'); } catch (e) {}
        var src = img.getAttribute('src');
        document.getElementById('fv-titulo').textContent = d.nombre || 'Profesional';
        document.getElementById('fv-area').textContent = tr.dataset.area || '';
        document.getElementById('fv-fecha').textContent = tr.dataset.firmaFecha || '—';
        document.getElementById('fv-abrir').href = src;
        document.getElementById('fv-res').textContent = '…'; document.getElementById('fv-ppp').textContent = '…';
        imgCert.src = src; imgOrig.src = src;
        imgOrig.onload = function () {
            var w = imgOrig.naturalWidth, h = imgOrig.naturalHeight, ppp = Math.round(w / PULGADAS);
            document.getElementById('fv-res').textContent = w + ' × ' + h + ' px';
            document.getElementById('fv-ppp').innerHTML = ppp + ' ppp ' + (ppp >= 300 ? '<span class="font-semibold text-salvia-dark">· Excelente</span>' : ppp >= 200 ? '<span class="font-semibold text-salvia-dark">· Buena</span>' : '<span class="font-semibold text-amber-700">· Baja: sube una imagen de mayor calidad</span>');
        };
        tab('cert');
        modal.classList.remove('hidden'); modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
        document.getElementById('fv-cerrar').focus();
    }
    function cerrar() {
        modal.classList.add('hidden'); modal.classList.remove('flex'); document.body.style.overflow = '';
        imgCert.removeAttribute('src'); imgOrig.removeAttribute('src');
        if (focoPrevio && focoPrevio.focus) focoPrevio.focus();
    }

    document.querySelectorAll('tr[data-id] .firma-box').forEach(function (box) {
        var tr = box.closest('tr[data-id]'), img = box.querySelector('.firma-img');
        function sync() { var on = img && !img.classList.contains('hidden') && !!img.getAttribute('src'); box.classList.toggle('fv-on', on); box.tabIndex = on ? 0 : -1; box.setAttribute('role', on ? 'button' : ''); box.setAttribute('aria-label', on ? 'Ver firma y timbre en grande' : ''); box.title = on ? 'Ver en grande' : ''; }
        sync();
        if (img) new MutationObserver(sync).observe(img, { attributes: true, attributeFilter: ['class', 'src'] });
        box.addEventListener('click', function () { if (box.classList.contains('fv-on')) abrir(tr); });
        box.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && box.classList.contains('fv-on')) { e.preventDefault(); abrir(tr); } });
    });
    modal.querySelectorAll('[data-fv-tab]').forEach(function (b) { b.addEventListener('click', function () { tab(b.dataset.fvTab); }); });
    zoomBtn.addEventListener('click', function () { var z = modal.dataset.zoom === '1' ? '0' : '1'; modal.dataset.zoom = z; zoomBtn.textContent = z === '1' ? 'Ajustar a la ventana' : 'Ampliar ×2'; });
    document.getElementById('fv-cerrar').addEventListener('click', cerrar);
    document.getElementById('fv-cerrar-x').addEventListener('click', cerrar);
    document.getElementById('fv-cambiar').addEventListener('click', function () { var tr = filaActual; cerrar(); var inp = tr && tr.querySelector('.firma-file'); if (inp) inp.click(); });
    modal.addEventListener('click', function (e) { if (e.target === modal) cerrar(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.classList.contains('hidden')) cerrar(); });
})();
</script>
