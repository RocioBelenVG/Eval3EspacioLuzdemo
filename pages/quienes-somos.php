<?php
/** Página "Quiénes somos". Contenido editable (tipo 'pagina', key 'quienes-somos'). */
declare(strict_types=1);

$content = getContent();
$p = $content['paginas']['quienes-somos'];
$t = fn(string $f) => ['type' => 'pagina', 'key' => 'quienes-somos', 'field' => $f];

$pageTitle = 'Quiénes somos — Espacio Luz';
$pageDescription = 'Conoce Espacio Luz: un centro integral de terapias y bienestar, cálido, cercano y profesional.';

include APP_ROOT . '/partials/head.php';
include APP_ROOT . '/partials/header.php';

$videoSrc = asset('videos/quienes-somos.mp4');
?>
<main>
    <!-- Hero compacto en banda oscura: el header transparente se mantiene legible
         y la ilustración se muestra enmarcada (no recortada a pantalla completa). -->
    <section class="relative overflow-hidden bg-cafe">
        <div class="pointer-events-none absolute inset-0 bg-noise opacity-60"></div>
        <div class="pointer-events-none absolute inset-0" style="background: radial-gradient(120% 90% at 85% 0%, rgba(240,180,138,.22), transparent 55%);"></div>
        <div class="container relative z-10 pb-14 pt-28 sm:pb-20 sm:pt-32">
            <div class="grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
                <!-- Columna de texto: encabezado + intro + tarjeta de fundadoras -->
                <div class="order-2 lg:order-1">
                    <span class="mb-3 inline-block font-body text-xs font-bold uppercase tracking-[0.2em] text-terracota-light">
                        <?= ez_editable($p['eyebrow'], $t('eyebrow')) ?>
                    </span>
                    <h1 class="font-heading text-3xl font-semibold leading-tight text-balance text-crema sm:text-4xl lg:text-[2.75rem]">
                        <?= ez_editable($p['titulo'], $t('titulo')) ?>
                    </h1>
                    <p class="mt-4 font-body text-base leading-relaxed text-crema/85 sm:text-lg">
                        <?= ez_editable($p['intro'], $t('intro'), true) ?>
                    </p>

                    <!-- Fundadoras: sello con nombre + rol -->
                    <div class="mt-7 inline-flex items-center gap-3 rounded-full border border-crema/15 bg-crema/[0.06] py-2.5 pl-2.5 pr-5 backdrop-blur-sm">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-terracota/20 text-terracota-light ring-1 ring-crema/15" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5"><path d="M19 14c1.5-1.5 3-3.2 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5c0 2.3 1.5 4 3 5.5l7 7Z"/></svg>
                        </span>
                        <span class="leading-tight">
                            <span class="block font-heading text-base font-semibold text-crema"><?= ez_editable($p['fundadorasNombre'], $t('fundadorasNombre')) ?></span>
                            <span class="block font-body text-sm text-terracota-light"><?= ez_editable($p['fundadorasRol'], $t('fundadorasRol')) ?></span>
                        </span>
                    </div>
                </div>

                <!-- Columna de video vertical (reel), más grande -->
                <div class="order-1 flex justify-center lg:order-2 lg:justify-end">
                    <!-- Se muestra un fotograma de portada con botón de play; se reproduce con
                         clic (sin autoplay, sin loop) y al terminar vuelve a la portada. -->
                    <div id="qs-video-card" class="group relative aspect-[9/16] w-full max-w-[360px] overflow-hidden rounded-[2rem] bg-cafe-dark shadow-warm ring-1 ring-crema/15">
                        <video id="qs-video" class="h-full w-full object-cover" playsinline preload="metadata"
                               src="<?= e($videoSrc) ?>#t=0.3"></video>
                        <button type="button" id="qs-video-btn"
                            class="absolute inset-0 flex items-center justify-center bg-gradient-to-t from-cafe/55 via-cafe/5 to-transparent transition-opacity duration-200"
                            aria-label="Reproducir video">
                            <span class="flex h-20 w-20 items-center justify-center rounded-full bg-terracota text-crema-light shadow-warm ring-4 ring-crema/25 transition-transform duration-200 group-hover:scale-105">
                                <svg viewBox="0 0 24 24" fill="currentColor" class="ml-1 h-9 w-9" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Secciones en cuadrícula 2×2: más compacto, menos scroll. -->
    <section class="bg-crema py-12 sm:py-16">
        <div class="container">
            <div class="grid gap-5 md:grid-cols-2">
                <?php for ($n = 1; isset($p["s{$n}t"]); $n++): ?>
                    <div class="flex h-full flex-col rounded-2xl border border-cafe/10 bg-crema-light p-6 shadow-card">
                        <h2 class="font-heading text-xl font-semibold text-cafe">
                            <?= ez_editable($p["s{$n}t"], $t("s{$n}t")) ?>
                        </h2>
                        <p class="mt-2 font-body text-sm leading-relaxed text-cafe/75">
                            <?= ez_editable($p["s{$n}b"], $t("s{$n}b"), true) ?>
                        </p>
                        <?php if ($n === 3): ?>
                            <a href="<?= e(base_path('/#profesionales')) ?>" class="mt-auto pt-3 inline-flex items-center gap-1.5 font-body text-sm font-semibold text-terracota transition-colors hover:text-terracota-dark">Conoce al equipo
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                            </a>
                        <?php elseif ($n === 4): ?>
                            <a href="<?= e(base_path('/#boxes')) ?>" class="mt-auto pt-3 inline-flex items-center gap-1.5 font-body text-sm font-semibold text-terracota transition-colors hover:text-terracota-dark">Ver los espacios
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    </section>
</main>
<script>
    (function () {
        var card = document.getElementById('qs-video-card');
        if (!card) return;
        var v = card.querySelector('#qs-video');
        var btn = card.querySelector('#qs-video-btn');
        function overlay(show) {
            btn.style.opacity = show ? '1' : '0';
            btn.style.pointerEvents = show ? 'auto' : 'none';
        }
        btn.addEventListener('click', function () {
            v.controls = true;
            try { v.currentTime = 0; } catch (e) {}
            v.play();
        });
        v.addEventListener('play', function () { overlay(false); });
        v.addEventListener('ended', function () { v.controls = false; overlay(true); });
    })();
</script>
<?php
include APP_ROOT . '/partials/footer.php';
include APP_ROOT . '/partials/foot.php';
