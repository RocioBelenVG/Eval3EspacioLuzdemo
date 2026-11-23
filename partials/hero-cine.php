<?php
/**
 * Hero de la portada: UNA sola pantalla, sin transición ni animación por scroll.
 * Funde la ilustración de la ventana (fondo + arbustos al frente) con la bienvenida:
 *  - Escritorio: texto a la izquierda; la acuarela a la derecha, con los bordes
 *    difuminados hacia el crema para que ilustración y fondo sean una sola pieza.
 *  - Móvil/tablet: la ilustración arriba y el texto debajo, centrado.
 * Los rayos de luz suaves quedan de fondo, estáticos. Espera $content.
 */
declare(strict_types=1);

$centro = $content['centro'];
$especialidades = $content['especialidades'];
$fondo = e(asset('images/hero/fondo.jpg') . '?v=' . @filemtime(IMAGES_DIR . '/hero/fondo.jpg'));
$frente = e(asset('images/hero/frente.png') . '?v=' . @filemtime(IMAGES_DIR . '/hero/frente.png'));
?>
<section id="top" class="hero-luz relative overflow-hidden bg-crema-light">
    <!-- Rayos de luz suaves (estáticos) -->
    <div aria-hidden="true" class="pointer-events-none absolute inset-0">
        <div class="absolute left-[30%] top-1/2 h-[170vmax] w-[170vmax] -translate-x-1/2 -translate-y-1/2 opacity-70"
            style="background: repeating-conic-gradient(from 0deg at 50% 50%, rgba(240,180,138,0) 0deg, rgba(240,180,138,.13) 2.2deg, rgba(240,180,138,0) 6deg);"></div>
        <div class="absolute inset-0" style="background: radial-gradient(60% 55% at 30% 48%, rgba(255,251,243,.92), rgba(251,249,244,.6) 45%, rgba(251,249,244,0) 80%);"></div>
    </div>

    <div class="container relative">
        <div class="grid min-h-[100svh] grid-cols-1 items-center gap-6 pb-12 pt-24 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] lg:gap-2 lg:pb-10 lg:pt-24">
            <!-- Bienvenida -->
            <div class="order-2 mx-auto max-w-xl text-center lg:order-1 lg:mx-0 lg:text-left">
                <span class="mb-5 inline-flex items-center gap-2 rounded-full border border-cafe/20 bg-crema/70 px-4 py-2 font-body text-[11px] font-semibold uppercase tracking-[0.2em] text-terracota-dark backdrop-blur-sm sm:text-xs">
                    <?= ez_editable($centro['ubicacion'], ['type' => 'centro', 'field' => 'ubicacion']) ?>
                </span>
                <h1 class="font-heading text-4xl font-semibold leading-[1.08] text-cafe sm:text-5xl xl:text-6xl" style="text-wrap: balance;">
                    <?= ez_editable($centro['heroTitulo'], ['type' => 'centro', 'field' => 'heroTitulo']) ?>
                    <span class="text-terracota">Luz</span>
                </h1>
                <p class="mx-auto mt-5 max-w-lg font-body text-lg leading-relaxed text-cafe/80 lg:mx-0 xl:text-xl">
                    <?= ez_editable($centro['heroTexto'], ['type' => 'centro', 'field' => 'heroTexto'], true) ?>
                </p>
                <div class="mt-8 flex flex-wrap items-center justify-center gap-4 lg:justify-start">
                    <?= render_button(isAuthenticated() ? 'Sistema interno' : 'Agenda tu hora', isAuthenticated() ? '/admin' : agenda_cta_href(), 'primary', 'lg') ?>
                    <a href="<?= e(base_path('/#boxes')) ?>" class="inline-flex items-center justify-center rounded-full border-2 border-cafe/20 bg-crema-light/60 px-6 py-3 font-body text-sm font-semibold text-cafe transition-colors hover:border-terracota hover:text-terracota">Conoce nuestros espacios</a>
                </div>
                <div class="mt-9 flex flex-wrap justify-center gap-x-7 gap-y-3 lg:justify-start">
                    <?php foreach ($especialidades as $esp): ?>
                        <span class="flex items-center gap-2 font-body text-sm font-medium text-cafe/75">
                            <span aria-hidden="true" class="h-1.5 w-1.5 rounded-full bg-terracota"></span>
                            <?= ez_editable($esp['nombre'], ['type' => 'especialidad', 'slug' => $esp['slug'], 'field' => 'nombre']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Ilustración: la ventana de Espacio Luz, fundida con el fondo -->
            <div class="order-1 lg:order-2 lg:-mr-8 xl:-mr-16">
                <div class="hl-art relative mx-auto aspect-[4/3] w-full max-w-[560px] overflow-hidden sm:max-w-[640px] lg:max-w-none">
                    <img src="<?= $fondo ?>" alt="Ventana de Espacio Luz con su logo, arcoíris, colibríes y flores en acuarela"
                        class="absolute inset-0 h-full w-full object-cover" fetchpriority="high" decoding="async">
                    <img src="<?= $frente ?>" alt="" aria-hidden="true"
                        class="pointer-events-none absolute inset-x-0 bottom-0 w-full translate-y-[27%]" decoding="async">
                </div>
            </div>
        </div>
    </div>
</section>
<style>
    /* La acuarela se disuelve en el crema: sin bordes, ilustración y fondo son una sola pieza. */
    .hero-luz .hl-art {
        -webkit-mask-image: radial-gradient(ellipse 54% 56% at 50% 45%, #000 66%, rgba(0,0,0,.55) 84%, transparent 99%);
                mask-image: radial-gradient(ellipse 54% 56% at 50% 45%, #000 66%, rgba(0,0,0,.55) 84%, transparent 99%);
    }
    .hero-luz .hl-art > img:first-child { transform: scale(1.06); transform-origin: 50% 52%; }
    /* Sobre el crema, la cabecera se ve sólida desde el inicio (texto café legible). */
    body:has(.hero-luz) #site-header { background: rgba(245,241,232,.92); backdrop-filter: blur(12px); }
    body:has(.hero-luz) #site-header .hdr-link { color: #5C4033; }
    body:has(.hero-luz) #site-header .hdr-link:hover { color: #D97757; }
    body:has(.hero-luz) #site-header .hdr-bar { background: #5C4033; }
    body:has(.hero-luz) #site-header [data-logo="light"] { display: none; }
    body:has(.hero-luz) #site-header [data-logo="dark"] { display: inline-flex; }
    body:has(.hero-luz) #site-header [data-auth="transparent"] { display: none; }
    body:has(.hero-luz) #site-header [data-auth="solid"] { display: inline-flex; }
</style>
