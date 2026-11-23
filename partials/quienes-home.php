<?php
/**
 * Sección "Quiénes somos" para la portada (entre el hero y Tipos de atención).
 * Estructura inspirada en una referencia: encabezado + 3 tarjetas "Contamos con"
 * + bloque "¿Por qué Espacio Luz?" (features 2x2) junto al video.
 * Mantiene la paleta cálida del sitio. Espera $content.
 */
declare(strict_types=1);

$pg = $content['paginas']['quienes-somos'] ?? [];
$videoSrc = asset('videos/' . ($pg['video'] ?? 'QuienesSomos.mp4'));

// Texto editable de esta sección (se guarda en overrides, como el resto del sitio).
$ezp = fn(string $v, string $f, bool $ml = false, string $tag = 'span', string $cls = '', string $ph = '')
    => ez_editable($v, ['type' => 'pagina', 'key' => 'quienes-somos', 'field' => $f], $ml, $tag, $cls, $ph);
// Chips de tarjeta: para el admin, una línea editable separada por comas; para
// el público, las "pastillas" de siempre.
$ezChips = function (string $raw, string $field): string {
    $pills = '<div class="qh-chips-view mt-4 flex grow content-start flex-wrap gap-2">';
    foreach (array_filter(array_map('trim', explode(',', $raw)), 'strlen') as $chip) {
        $pills .= '<span class="rounded-full border border-cafe/12 bg-crema px-3 py-1 font-body text-xs font-medium text-cafe/75">' . e($chip) . '</span>';
    }
    $pills .= '</div>';
    if (!puedeEditarSitio()) {
        return $pills;
    }
    // Admin: pastillas al navegar + línea editable (separada por comas) al editar.
    $edit = ez_editable($raw, ['type' => 'pagina', 'key' => 'quienes-somos', 'field' => $field], false, 'p',
        'qh-chips-edit mt-4 grow font-body text-sm italic leading-relaxed text-cafe/55', 'Sepáralos con comas: Área 1, Área 2…');
    return $pills . $edit;
};

// SVG helpers (iconos de línea, estilo Lucide).
$ico = static function (string $inner, string $cls = 'h-5 w-5'): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="' . $cls . '" aria-hidden="true">' . $inner . '</svg>';
};
$iUsers = '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>';
$iSun = '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>';
$iHeart = '<path d="M19 14c1.5-1.5 3-3.2 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5c0 2.3 1.5 4 3 5.5l7 7Z"/>';
$iArrow = '<path d="M5 12h14M13 6l6 6-6 6"/>';

// Paleta por tarjeta (tono de color para ícono, franja superior y botón).
$tonos = [
    'terracota' => ['tile' => 'bg-terracota text-crema-light',      'cta' => 'text-terracota-dark hover:border-terracota hover:text-terracota',       'bar' => '#D97757'],
    'salvia'    => ['tile' => 'bg-salvia-dark text-crema-light',    'cta' => 'text-salvia-dark hover:border-salvia-dark hover:text-salvia-dark',       'bar' => '#8B9E8F'],
    'cafe'      => ['tile' => 'bg-cafe text-crema-light',           'cta' => 'text-cafe hover:border-cafe hover:text-cafe',                            'bar' => '#5C4033'],
];

// Tarjetas "Contamos con": [icono, título, descripción, chips[], ctaTexto, ctaHref, tono]
// Tarjetas "Contamos con": el ícono/color/enlace son fijos; título, descripción,
// chips y texto del botón se editan (campos cN* de la página quienes-somos).
$tarjetas = [
    ['ico' => $iUsers, 'n' => 'c1', 'href' => base_path('/#profesionales'), 'tono' => 'terracota'],
    ['ico' => $iSun,   'n' => 'c2', 'href' => base_path('/#atenciones'),    'tono' => 'salvia'],
    ['ico' => $iHeart, 'n' => 'c3', 'href' => str_starts_with(agenda_cta_href(), '/') ? base_path(agenda_cta_href()) : agenda_cta_href(), 'tono' => 'cafe'],
];
?>
<section id="quienes-somos" class="relative overflow-hidden bg-crema py-12 sm:py-16 lg:py-20">
    <div class="pointer-events-none absolute -left-24 top-8 h-72 w-72 rounded-full" style="background: radial-gradient(circle, rgba(217,119,87,.10), transparent 70%);"></div>
    <div class="pointer-events-none absolute -right-20 top-1/3 h-96 w-96 rounded-full" style="background: radial-gradient(circle, rgba(139,158,143,.12), transparent 70%);"></div>

    <div class="container relative z-[1]">
        <!-- 1 · Encabezado + video -->
        <div class="grid items-start gap-10 lg:grid-cols-[1fr_1.35fr] lg:gap-12">
            <div class="animate-fade-up">
                <?= $ezp($pg['eyebrow'] ?? 'Quiénes somos', 'eyebrow', false, 'span', 'mb-3 inline-block font-body text-xs font-bold uppercase tracking-[0.2em] text-terracota-dark sm:text-sm', 'Epígrafe') ?>
                <?= $ezp($pg['titulo'] ?? '', 'titulo', false, 'h2', 'font-heading text-3xl font-semibold leading-tight text-balance text-cafe sm:text-4xl md:text-[2.75rem]', 'Título de la sección') ?>
                <?= $ezp($pg['intro'] ?? '', 'intro', true, 'p', 'mt-4 font-body text-base leading-relaxed text-cafe/80 sm:text-lg', 'Texto de presentación del centro') ?>
                <div class="mt-7 flex w-full items-center gap-5 rounded-3xl border border-terracota/20 bg-gradient-to-br from-crema-light via-crema-light to-terracota/[0.08] p-6 shadow-card sm:gap-6 sm:p-7">
                    <img src="<?= e(asset('images/logo/logo.png')) ?>" alt="Espacio Luz" class="h-24 w-24 shrink-0 object-contain sm:h-28 sm:w-28">

                    <div class="leading-tight">
                        <?= $ezp($pg['fundadorasLema'] ?? 'Hecho con dedicación y cariño', 'fundadorasLema', false, 'p', 'font-body text-[11px] font-bold uppercase tracking-[0.15em] text-terracota-dark sm:text-xs', 'Lema') ?>
                        <?= $ezp($pg['fundadorasNombre'] ?? 'Fernanda y Javiera', 'fundadorasNombre', false, 'p', 'mt-1 font-heading text-2xl font-semibold text-cafe sm:text-[1.65rem]', 'Nombres de las fundadoras') ?>
                        <?= $ezp($pg['fundadorasRol'] ?? 'Fundadoras de Espacio Luz', 'fundadorasRol', false, 'p', 'mt-0.5 font-body text-sm text-cafe/60', 'Rol') ?>
                    </div>
                </div>
            </div>

            <!-- Video final (borde animado + video ya incrustados), marco 4:3 -->
            <div class="flex justify-center animate-fade-up lg:justify-end">
                <div class="relative w-full">
                    <div class="pointer-events-none absolute -inset-5 -z-0" style="background: radial-gradient(60% 55% at 25% 20%, rgba(217,119,87,.18), transparent 70%), radial-gradient(55% 55% at 80% 80%, rgba(139,158,143,.18), transparent 70%); filter: blur(8px);"></div>
                    <div id="qh-video-card" class="group relative z-[1] aspect-video w-full overflow-hidden rounded-[1.75rem] bg-cafe-dark shadow-warm ring-1 ring-cafe/10">
                        <video id="qh-video" class="h-full w-full object-cover" autoplay muted loop playsinline preload="metadata"
                               src="<?= e($videoSrc) ?>"></video>
                        <?php if (puedeEditarSitio()): ?>
                        <button type="button" id="qh-video-change" class="qh-vid-edit absolute left-1/2 top-3 z-[5] -translate-x-1/2 items-center gap-1.5 rounded-full bg-cafe/85 px-3.5 py-2 font-body text-xs font-semibold text-crema shadow-card backdrop-blur-sm transition-colors hover:bg-terracota">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3m0 0 4 4m-4-4-4 4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                            Cambiar video
                        </button>
                        <input type="file" id="qh-video-input" accept="video/mp4,.mp4" class="hidden">
                        <?php endif; ?>

                        <!-- Botón central grande: play / pausa (visible al pasar el cursor o en pausa) -->
                        <button type="button" id="qh-play" class="pointer-events-none absolute left-1/2 top-1/2 z-[3] flex h-16 w-16 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-cafe/55 text-crema-light opacity-0 shadow-warm backdrop-blur-sm transition-all duration-200 hover:scale-105 hover:bg-terracota" aria-label="Pausar video">
                            <svg class="qh-ico-pause h-7 w-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>
                            <svg class="qh-ico-play hidden h-7 w-7 translate-x-0.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                        </button>

                        <!-- Izquierda: reiniciar + volumen -->
                        <div class="group/snd absolute bottom-3 left-3 z-[3] flex items-center gap-2">
                            <button type="button" id="qh-restart" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-cafe/70 text-crema-light backdrop-blur-sm transition-colors hover:bg-terracota" aria-label="Reiniciar video">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 2.6-6.4L3 8"/><path d="M3 3v5h5"/></svg>
                            </button>
                            <button type="button" id="qh-sound" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-cafe/70 text-crema-light backdrop-blur-sm transition-colors hover:bg-terracota" aria-label="Activar sonido" aria-pressed="false">
                                <svg class="qh-ico-muted h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5Z"/><path d="m23 9-6 6M17 9l6 6"/></svg>
                                <svg class="qh-ico-on hidden h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5Z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a9 9 0 0 1 0 14"/></svg>
                            </button>
                            <input id="qh-vol" type="range" min="0" max="1" step="0.05" value="0.5" aria-label="Volumen"
                                   class="qh-vol h-1.5 w-0 cursor-pointer opacity-0 transition-all duration-200 group-hover/snd:w-20 group-hover/snd:opacity-100" style="accent-color:#D97757;">
                        </div>

                        <!-- Derecha: agrandar (ver en grande) -->
                        <button type="button" id="qh-expand" class="absolute bottom-3 right-3 z-[3] flex h-9 w-9 items-center justify-center rounded-full bg-cafe/70 text-crema-light backdrop-blur-sm transition-colors hover:bg-terracota" aria-label="Ver video en grande">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M8 21H5a2 2 0 0 1-2-2v-3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2 · Tarjetas "Contamos con" -->
        <div class="mt-12 grid gap-6 lg:grid-cols-3">
            <?php foreach ($tarjetas as $c): $t = $tonos[$c['tono']]; $n = $c['n']; ?>
                <div class="relative flex flex-col overflow-hidden rounded-3xl border border-cafe/10 bg-crema-light p-7 pt-8 shadow-card transition-all duration-200 hover:-translate-y-1 hover:shadow-warm">
                    <span class="absolute inset-x-0 top-0 h-1.5" style="background: <?= $t['bar'] ?>;"></span>
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl shadow-warm <?= $t['tile'] ?>"><?= $ico($c['ico'], 'h-6 w-6') ?></span>
                    <?= $ezp($pg[$n . 'Titulo'] ?? '', $n . 'Titulo', false, 'h3', 'mt-4 font-heading text-xl font-semibold text-cafe', 'Título de la tarjeta') ?>
                    <?= $ezp($pg[$n . 'Desc'] ?? '', $n . 'Desc', true, 'p', 'mt-2 font-body text-sm leading-relaxed text-cafe/70', 'Descripción breve') ?>
                    <?= $ezChips($pg[$n . 'Chips'] ?? '', $n . 'Chips') ?>
                    <a href="<?= e($c['href']) ?>"<?= str_starts_with($c['href'], 'http') ? ' target="_blank" rel="noopener noreferrer"' : '' ?> class="mt-6 flex items-center justify-center gap-1.5 rounded-full border border-cafe/15 px-4 py-3 font-body text-sm font-semibold transition-colors <?= $t['cta'] ?>">
                        <?= $ezp($pg[$n . 'Cta'] ?? '', $n . 'Cta', false, 'span', '', 'Texto del botón') ?> <?= $ico($iArrow, 'h-4 w-4') ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

    </div>

    <!-- Lightbox: ver el video en grande (cierra con X, Esc o clic fuera) -->
    <div id="qh-lightbox" class="fixed inset-0 z-[200] hidden items-center justify-center bg-cafe/90 p-4 backdrop-blur-sm">
        <button type="button" id="qh-lb-close" class="absolute right-4 top-4 z-[2] flex h-11 w-11 items-center justify-center rounded-full bg-crema/15 text-crema-light transition-colors hover:bg-terracota" aria-label="Cerrar (Esc)">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
        <div class="aspect-video w-[min(95vw,160vh)] overflow-hidden rounded-2xl bg-cafe-dark shadow-warm">
            <video id="qh-lb-video" class="h-full w-full object-contain" playsinline controls preload="none" src="<?= e($videoSrc) ?>"></video>
        </div>
    </div>
</section>
<script>
    (function () {
        var card = document.getElementById('qh-video-card');
        if (!card) return;
        var v = document.getElementById('qh-video');
        try { v.volume = 0.5; } catch (e) {} // volumen por defecto 50% (arranca muteado por autoplay)

        // --- Sonido + volumen ---
        var btn = document.getElementById('qh-sound');
        var icoM = btn.querySelector('.qh-ico-muted'), icoOn = btn.querySelector('.qh-ico-on');
        var vol = document.getElementById('qh-vol');
        function syncSound() {
            var off = v.muted || v.volume === 0;
            icoM.classList.toggle('hidden', !off);
            icoOn.classList.toggle('hidden', off);
            btn.setAttribute('aria-pressed', String(!off));
            btn.setAttribute('aria-label', off ? 'Activar sonido' : 'Silenciar');
            if (vol && document.activeElement !== vol) { vol.value = v.muted ? 0 : v.volume; }
        }
        btn.addEventListener('click', function () {
            if (v.muted || v.volume === 0) { v.muted = false; if (v.volume === 0) { v.volume = 0.5; } v.play(); }
            else { v.muted = true; }
            syncSound();
        });
        if (vol) vol.addEventListener('input', function () {
            var val = parseFloat(vol.value);
            v.volume = val;
            v.muted = (val === 0);
            if (val > 0) { v.play(); }
            syncSound();
        });
        v.addEventListener('volumechange', syncSound);
        syncSound();

        // --- Play / pausa (botón central grande) ---
        var play = document.getElementById('qh-play');
        var icoPause = play.querySelector('.qh-ico-pause'), icoPlay = play.querySelector('.qh-ico-play');
        var hovered = false;
        function updateCenter() {
            var show = v.paused || hovered;
            play.style.opacity = show ? '1' : '0';
            play.style.pointerEvents = show ? 'auto' : 'none';
        }
        function syncPlay() {
            var paused = v.paused;
            icoPause.classList.toggle('hidden', paused);
            icoPlay.classList.toggle('hidden', !paused);
            play.setAttribute('aria-label', paused ? 'Reanudar video' : 'Pausar video');
            updateCenter();
        }
        card.addEventListener('mouseenter', function () { hovered = true; updateCenter(); });
        card.addEventListener('mouseleave', function () { hovered = false; updateCenter(); });
        play.addEventListener('click', function () { if (v.paused) { v.play(); } else { v.pause(); } });
        v.addEventListener('play', syncPlay);
        v.addEventListener('pause', syncPlay);
        syncPlay();

        // --- Reiniciar ---
        document.getElementById('qh-restart').addEventListener('click', function () {
            try { v.currentTime = 0; } catch (e) {}
            v.play();
        });

        // --- Ver en grande (lightbox) ---
        var lb = document.getElementById('qh-lightbox');
        var lbv = document.getElementById('qh-lb-video');
        function openLb() {
            try { lbv.currentTime = v.currentTime; } catch (e) {}
            lbv.muted = false; lbv.volume = 0.5;
            lb.classList.remove('hidden'); lb.classList.add('flex');
            document.body.style.overflow = 'hidden';
            v.pause();
            lbv.play().catch(function () {});
        }
        function closeLb() {
            lb.classList.add('hidden'); lb.classList.remove('flex');
            document.body.style.overflow = '';
            lbv.pause();
            v.play().catch(function () {});
        }
        document.getElementById('qh-expand').addEventListener('click', openLb);
        document.getElementById('qh-lb-close').addEventListener('click', closeLb);
        lb.addEventListener('click', function (e) { if (e.target === lb) closeLb(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !lb.classList.contains('hidden')) { closeLb(); }
        });
    })();
</script>
<?php if (puedeEditarSitio()): ?>
<style>
    .qh-vid-edit { display: none; }
    .ez-editing .qh-vid-edit { display: inline-flex; }
    .qh-chips-edit { display: none; }
    .ez-editing .qh-chips-view { display: none; }
    .ez-editing .qh-chips-edit { display: block; }
</style>
<script>
    (function () {
        // Cambiar el video de la sección: sube un mp4 a /api/content/upload-video,
        // que lo guarda y registra en los overrides; luego intercambia el <video>.
        var P = window.__EZ__ || {};
        var btn = document.getElementById('qh-video-change');
        var input = document.getElementById('qh-video-input');
        if (!btn || !input) return;
        var video = document.getElementById('qh-video');
        var lbVideo = document.getElementById('qh-lb-video');
        function aviso(m) { (window.ezAlert ? window.ezAlert : window.alert)(m); }
        btn.addEventListener('click', function () { input.click(); });
        input.addEventListener('change', function () {
            var f = input.files[0]; input.value = ''; if (!f) return;
            if (f.size > 60 * 1024 * 1024) { aviso('El video no puede pesar más de 60 MB.'); return; }
            var prev = btn.innerHTML; btn.disabled = true; btn.textContent = 'Subiendo video…';
            var form = new FormData(); form.append('file', f);
            fetch(P.base + '/api/content/upload-video', { method: 'POST', headers: { 'X-CSRF-Token': P.csrf || '' }, body: form })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (x) {
                    if (!x.ok) throw new Error((x.j && x.j.error) || 'No se pudo subir el video.');
                    var src = x.j.src;
                    if (video) { try { video.pause(); } catch (e) {} video.src = src; video.load(); video.play().catch(function () {}); }
                    if (lbVideo) { lbVideo.src = src; }
                    btn.disabled = false; btn.innerHTML = prev;
                })
                .catch(function (err) { btn.disabled = false; btn.innerHTML = prev; aviso(String(err && err.message || 'No se pudo subir el video.')); });
        });
    })();
</script>
<?php endif; ?>
