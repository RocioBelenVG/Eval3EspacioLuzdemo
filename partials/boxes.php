<?php
/**
 * Sección Boxes (grilla con los 4 boxes). Reemplaza a components/sections/Boxes.tsx.
 * Antes era un carrusel; initBoxCarousel() de main.js se salta solo al no haber #ez-box-track.
 * Espera $content.
 */
declare(strict_types=1);

// Solo los boxes marcados «Visible en el sitio» en el panel (módulo Boxes).
$boxes = array_values(array_filter($content['boxes'], fn($b) => !isset($b['publico']) || (int) $b['publico'] === 1));
$seccion = $content['secciones']['boxes'];
// Iconos para el overlay de foto principal (modo edición).
$svgOpenBx = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
$bxCam = $svgOpenBx . '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2Z"/><circle cx="12" cy="13" r="4"/></svg>';
$bxX = $svgOpenBx . '<path d="M18 6 6 18M6 6l12 12"/></svg>';
?>
<style>
/* Ave + tronco (UNA sola imagen) como borde superior de cada tarjeta de box.
   El tronco cae sobre el borde y el ave asoma arriba; nunca se separan. */
.box-perch {
    position: absolute; left: 50%; bottom: 100%;
    width: 100%; height: auto; max-width: none;   /* EXACTAMENTE el ancho de la tarjeta (el tronco llena la imagen) */
    transform: translateX(-50%);
    z-index: 20; pointer-events: none;
    filter: drop-shadow(0 5px 7px rgba(50,30,15,.28));
}
/* Overlay para cambiar/eliminar la foto principal del box (modo edición). */
.ez-boxtools{position:absolute;inset-inline:0;top:0;z-index:30;display:flex;justify-content:center;gap:8px;padding:12px;opacity:0;pointer-events:none;transition:opacity .2s}
.ez-editing .ez-boxmain:hover .ez-boxtools{opacity:1;pointer-events:auto}
.ez-ovb{display:inline-flex;align-items:center;gap:6px;border-radius:9999px;background:rgba(251,249,244,.96);padding:7px 12px;font-family:var(--font-body,inherit);font-size:12px;font-weight:600;color:#5C4033;box-shadow:0 10px 30px -10px rgba(92,64,51,.35);cursor:pointer;border:none}
.ez-ovb:hover{background:#fff}
.ez-ovb svg{width:15px;height:15px}
.ez-ovb-danger{color:#dc2626}.ez-ovb-danger:hover{background:#fef2f2}

/* ---- Boxes: grilla de 5 en escritorio; carrusel deslizable en celular/tablet ---- */
.boxes-track{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:0 1.5rem}
.boxes-dots{display:none}
@media (max-width:1023px){
    .boxes-viewport{overflow:hidden}
    .boxes-track{display:flex;gap:0;will-change:transform;touch-action:pan-y}
    .boxes-slide{flex:0 0 auto;width:80%;margin-right:1rem}
    .boxes-carousel.is-ready .boxes-slide{cursor:grab}
    .boxes-carousel.is-ready .boxes-slide:active{cursor:grabbing}
    .boxes-dots{display:flex;justify-content:center;gap:.5rem;margin-top:1.5rem}
    .boxes-dots button{height:.5rem;width:.5rem;border-radius:9999px;background:rgba(245,241,232,.3);transition:all .3s;cursor:pointer;border:0;padding:0}
    .boxes-dots button[aria-current="true"]{width:1.75rem;background:#D97757}
}
@media (min-width:768px) and (max-width:1023px){
    .boxes-slide{width:46%}
}
</style>
<section id="boxes" class="relative overflow-hidden bg-cafe py-12 sm:py-16 lg:py-20">
    <div class="pointer-events-none absolute -left-24 top-10 h-64 w-64 rounded-full bg-terracota/10 blur-3xl"></div>
    <div class="pointer-events-none absolute -right-24 bottom-10 h-72 w-72 rounded-full bg-salvia/10 blur-3xl"></div>

    <div class="container relative">
        <div class="flex flex-col items-start justify-between gap-6 sm:flex-row sm:items-end">
            <div class="max-w-2xl text-left">
                <span class="mb-3 inline-block font-body text-xs sm:text-sm font-bold uppercase tracking-[0.2em] text-crema-dark">
                    <?= ez_editable($seccion['eyebrow'], ['type' => 'seccion', 'id' => 'boxes', 'field' => 'eyebrow']) ?>
                </span>
                <h2 class="font-heading text-3xl sm:text-4xl md:text-[2.75rem] font-semibold leading-tight text-balance text-crema">
                    <?= ez_editable($seccion['titulo'], ['type' => 'seccion', 'id' => 'boxes', 'field' => 'titulo']) ?>
                </h2>
                <p class="mt-4 font-body text-base sm:text-lg leading-relaxed text-balance text-crema-dark/90">
                    <?= ez_editable($seccion['descripcion'], ['type' => 'seccion', 'id' => 'boxes', 'field' => 'descripcion'], true) ?>
                </p>
            </div>
        </div>

        <!-- Grilla: los 4 boxes visibles a la vez (1 col en móvil, 2 en tablet, 4 en escritorio).
             Cada tarjeta reserva arriba el alto del ave (pt-*) para que no se corte. -->
        <?php
        // --- Posición vertical de la imagen (ave+tronco) sobre la tarjeta, POR BOX ---
        // Sube el % para que el tronco baje sobre el borde; baja el % para subirlo.
        $avePerchY = [
            'loica'           => '13%',
            'martin-pescador' => '13%',
            '7-colores'       => '13%',
            'colibri'         => '13%',
            'golondrina'      => '13%',
        ];
        ?>
        <div class="boxes-carousel mt-8" data-boxes-carousel>
          <div class="boxes-viewport">
            <div class="boxes-track" id="boxes-track">
            <?php foreach ($boxes as $i => $box): ?>
                <div class="boxes-slide min-w-0 pt-[170px] sm:pt-[190px]">
                    <div class="group relative">
                        <!-- Ave + tronco (una sola imagen) sobre el borde superior de la tarjeta -->
                        <?php
                        // Cache-busting: si cambias la imagen, se ve al instante.
                        $perchRel = 'images/boxes/aves/' . $box['aveSlug'] . '.png';
                        $perchAbs = ASSETS_DIR . '/' . $perchRel;
                        $perchSrc = asset($perchRel) . (is_file($perchAbs) ? '?v=' . filemtime($perchAbs) : '');
                        $perchY = $avePerchY[$box['aveSlug']] ?? '13%';
                        ?>
                        <img class="box-perch" src="<?= e($perchSrc) ?>" alt="" aria-hidden="true"
                            style="transform: translate(-50%, <?= e($perchY) ?>);">

                        <!-- AQUÍ ESTÁ EL CAMBIO: w-[calc(100%-2px)] pasó a ser w-[98%] -->
                        <div class="ez-boxmain relative z-10 w-full aspect-[3/4] overflow-hidden rounded-b-[2rem] rounded-t-md shadow-warm ring-1 ring-crema/10" data-box="<?= e($box['slug']) ?>" data-hasupload="<?= boxHasMainUpload($box['slug']) ? '1' : '0' ?>">
                            <?php $bxFoto = boxMainImage($box); if ($bxFoto !== ''): ?>
                            <img src="<?= e($bxFoto) ?>" alt="<?= attr($box['nombre']) ?>"
                                class="absolute inset-0 h-full w-full object-cover transition-transform duration-700 group-hover:scale-105">
                            <?php else: /* Sin foto subida: no se muestra ninguna foto, solo un fondo neutro. */ ?>
                            <div class="absolute inset-0 flex items-start justify-center bg-gradient-to-b from-[#EDE4D6] to-[#D9C7B0] pt-[38%]" aria-hidden="true">
                                <span class="font-body text-xs font-semibold uppercase tracking-[0.18em] text-cafe/45">Fotos próximamente</span>
                            </div>
                            <?php endif; ?>
                            <div class="pointer-events-none absolute inset-0" style="background:linear-gradient(to top,rgba(61,42,33,.92) 0%,rgba(61,42,33,.5) 22%,rgba(92,64,51,.08) 45%,transparent 62%)"></div>
                            <?php if (puedeEditarSitio()): ?>
                            <div class="ez-boxtools">
                                <button type="button" class="ez-boxmain-change ez-ovb"><?= $bxCam ?> Cambiar foto</button>
                                <button type="button" class="ez-boxmain-del ez-ovb ez-ovb-danger"><?= $bxX ?> Eliminar foto</button>
                                <input type="file" class="ez-boxmain-input hidden" accept="image/jpeg,image/png,image/webp">
                            </div>
                            <?php endif; ?>

                            <div class="absolute inset-x-0 bottom-0 p-6 [text-shadow:0_1px_8px_rgba(30,18,10,.55)]">
                                <h3 class="font-heading text-[1.65rem] font-bold leading-tight text-white [text-shadow:0_2px_10px_rgba(30,18,10,.6)]">
                                    <?= ez_editable($box['nombre'], ['type' => 'box', 'slug' => $box['slug'], 'field' => 'nombre']) ?>
                                </h3>
                                <p class="mt-1 font-body text-sm font-semibold text-crema/90">
                                    <?= ez_editable($box['publicoObjetivo'], ['type' => 'box', 'slug' => $box['slug'], 'field' => 'publicoObjetivo']) ?>
                                </p>
                                <div class="mt-4">
                                    <span class="font-body text-sm text-crema/95">
                                        Desde <strong class="font-heading text-lg text-terracota-light"><?= e(getPrecioPorHora($box)) ?></strong> / hora
                                    </span>
                                </div>
                                <?= render_button('Revisar box', '/boxes/' . $box['slug'], 'secondary-light', 'md', 'mt-4 w-full justify-center') ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div><!-- /.boxes-track -->
          </div><!-- /.boxes-viewport -->
          <div class="boxes-dots" id="boxes-dots" aria-hidden="true"></div>
        </div><!-- /.boxes-carousel -->
    </div>
</section>
<script>
(function () {
    var carousel = document.querySelector('[data-boxes-carousel]');
    if (!carousel) return;
    var viewport = carousel.querySelector('.boxes-viewport');
    var track = carousel.querySelector('.boxes-track');
    var dotsWrap = carousel.querySelector('.boxes-dots');
    var orig = Array.prototype.slice.call(track.querySelectorAll('.boxes-slide'));
    var n = orig.length;
    if (n < 2) return;
    var mq = window.matchMedia('(max-width: 1023px)');

    var on = false, clones = [], index = 0, step = 0;
    var autoT = null, paused = false, resumeT = null;
    var dragging = false, startX = 0, startT = 0, moved = false;

    function mkClone(s) {
        var c = s.cloneNode(true);
        c.setAttribute('aria-hidden', 'true');
        c.classList.add('boxes-clone');
        c.querySelectorAll('[id]').forEach(function (e) { e.removeAttribute('id'); });
        c.querySelectorAll('a,button,input').forEach(function (e) { e.setAttribute('tabindex', '-1'); });
        return c;
    }
    function buildDots() {
        dotsWrap.innerHTML = '';
        for (var i = 0; i < n; i++) {
            (function (i) {
                var b = document.createElement('button');
                b.type = 'button';
                b.setAttribute('aria-label', 'Ir al box ' + (i + 1));
                b.addEventListener('click', function () { pause(); index = n + i; goTo(index, true); resumeLater(); });
                dotsWrap.appendChild(b);
            })(i);
        }
    }
    function updateDots() {
        var real = ((index - n) % n + n) % n;
        Array.prototype.forEach.call(dotsWrap.children, function (d, i) {
            d.setAttribute('aria-current', i === real ? 'true' : 'false');
        });
    }
    function measure() {
        var all = track.querySelectorAll('.boxes-slide');
        step = all.length > 1 ? (all[1].getBoundingClientRect().left - all[0].getBoundingClientRect().left) : all[0].getBoundingClientRect().width;
    }
    function goTo(i, animate) {
        track.style.transition = animate ? 'transform .5s cubic-bezier(.4,0,.2,1)' : 'none';
        track.style.transform = 'translate3d(' + (-i * step) + 'px,0,0)';
        updateDots();
    }
    function next() { index++; goTo(index, true); }

    function onEnd() {
        if (index >= 2 * n) { index -= n; goTo(index, false); }
        else if (index < n) { index += n; goTo(index, false); }
    }
    function startAuto() { stopAuto(); autoT = window.setInterval(function () {
        if (paused) return;
        if (document.body.classList.contains('ez-editing')) return;
        next();
    }, 4500); }
    function stopAuto() { if (autoT) { window.clearInterval(autoT); autoT = null; } }
    function pause() { paused = true; window.clearTimeout(resumeT); }
    function resumeLater() { window.clearTimeout(resumeT); resumeT = window.setTimeout(function () { paused = false; }, 6000); }

    function onDown(e) {
        dragging = true; moved = false;
        startX = e.clientX; startT = -index * step;
        track.style.transition = 'none';
        pause();
        try { track.setPointerCapture(e.pointerId); } catch (x) {}
    }
    function onMove(e) {
        if (!dragging) return;
        var dx = e.clientX - startX;
        if (Math.abs(dx) > 5) moved = true;
        track.style.transform = 'translate3d(' + (startT + dx) + 'px,0,0)';
    }
    function onUp(e) {
        if (!dragging) return;
        dragging = false;
        var dx = e.clientX - startX;
        index = Math.round(-(startT + dx) / step);
        goTo(index, true);
        resumeLater();
    }

    function build() {
        if (on) return; on = true;
        var before = orig.map(mkClone), after = orig.map(mkClone);
        before.forEach(function (c) { track.insertBefore(c, orig[0]); });
        after.forEach(function (c) { track.appendChild(c); });
        clones = before.concat(after);
        buildDots();
        index = n;
        measure();
        goTo(index, false);
        carousel.classList.add('is-ready');
        track.addEventListener('transitionend', onEnd);
        track.addEventListener('pointerdown', onDown);
        track.addEventListener('pointermove', onMove);
        track.addEventListener('pointerup', onUp);
        track.addEventListener('pointercancel', onUp);
        track.addEventListener('click', onClickGuard, true);
        track.addEventListener('mouseenter', pause);
        track.addEventListener('mouseleave', resumeLater);
        startAuto();
    }
    function onClickGuard(e) { if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; } }

    function teardown() {
        if (!on) return; on = false;
        stopAuto();
        track.removeEventListener('transitionend', onEnd);
        track.removeEventListener('pointerdown', onDown);
        track.removeEventListener('pointermove', onMove);
        track.removeEventListener('pointerup', onUp);
        track.removeEventListener('pointercancel', onUp);
        track.removeEventListener('click', onClickGuard, true);
        track.removeEventListener('mouseenter', pause);
        track.removeEventListener('mouseleave', resumeLater);
        clones.forEach(function (c) { if (c.parentNode) c.parentNode.removeChild(c); });
        clones = [];
        dotsWrap.innerHTML = '';
        track.style.transition = '';
        track.style.transform = '';
        carousel.classList.remove('is-ready');
    }

    function onResize() { if (on) { measure(); goTo(index, false); } }
    function apply() { if (mq.matches) build(); else teardown(); }

    if (mq.addEventListener) mq.addEventListener('change', apply); else mq.addListener(apply);
    window.addEventListener('resize', onResize);
    document.addEventListener('visibilitychange', function () { if (document.hidden) stopAuto(); else if (on) startAuto(); });
    apply();
})();
</script>