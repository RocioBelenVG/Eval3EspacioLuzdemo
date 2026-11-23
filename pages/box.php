<?php
/**
 * Detalle de un box. Reemplaza a app/boxes/[slug]/page.tsx.
 * Espera $slug (definido por el router).
 */
declare(strict_types=1);

$content = getContent();
$box = getBoxBySlug($content['boxes'], $slug ?? '');

// Un box oculto en el panel no se muestra al público (sí a quien tiene sesión, para revisarlo).
if ($box !== null && isset($box['publico']) && (int) $box['publico'] !== 1 && !isAuthenticated()) {
    $box = null;
}
if ($box === null) {
    http_response_code(404);
    require APP_ROOT . '/pages/404.php';
    return;
}

$centro = $content['centro'];
$hasLogo = hasLogo();
// El equipamiento base (no editado) define el ícono, para que una edición de
// redacción no rompa el ícono asignado (igual que en el original).
$defaultBox = getBoxBySlug($GLOBALS['BOXES'], $slug);

// Fotos del box para el carrusel del detalle (galería subida, independiente de la
// foto de portada que se ve en la tarjeta del home).
$extraImages = listGalleryImages($box['slug']);

$banners = [
    'loica' => ['file' => 'LoicaLogo.png', 'w' => 660, 'h' => 615],
    'martin-pescador' => ['file' => 'MartinLogo.png', 'w' => 660, 'h' => 670],
    '7-colores' => ['file' => '7ColoresLogo.png', 'w' => 580, 'h' => 615],
    'colibri' => ['file' => 'ColibriLogo.png', 'w' => 840, 'h' => 625],
    'golondrina' => ['file' => 'GolondrinaLogo.png', 'w' => 700, 'h' => 640],
];
$banner = $banners[$box['aveSlug']] ?? $banners['loica'];

// Imagen "Próximamente" por box (se usa cuando aún no hay fotos en la galería).
$comingSoon = [
    'loica' => 'Loica.jpg',
    'martin-pescador' => 'MartinPescador.jpg',
    '7-colores' => '7Colores.jpg',
    'colibri' => 'Colibri.jpg',
];
$comingSoonFile = $comingSoon[$box['aveSlug']] ?? 'Loica.jpg';

$pageTitle = $box['nombre'] . ' — Espacio Luz';
$pageDescription = $box['descripcion'];

// URLs absolutas de las fotos de la galería (para el carrusel/lightbox en JS).
$extraUrls = array_map(function ($img) {
    return str_starts_with($img, 'http') ? $img : imageUrl($img);
}, $extraImages);

// Iconos para los controles de admin de las fotos.
$svgOpenBox = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
$icoCam = $svgOpenBox . '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2Z"/><circle cx="12" cy="13" r="4"/></svg>';
$icoX = $svgOpenBox . '<path d="M18 6 6 18M6 6l12 12"/></svg>';

include APP_ROOT . '/partials/head.php';
?>
<div class="border-b border-cafe/10 bg-crema-light">
    <div class="container flex h-20 items-center">
        <a href="<?= e(base_path('/#boxes')) ?>"
            class="inline-flex items-center gap-2 rounded-full border border-cafe/20 px-4 py-2 font-body text-sm font-semibold text-cafe transition-colors hover:border-terracota hover:text-terracota">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Todos los espacios
        </a>
    </div>
</div>

<main class="bg-crema-light">
    <div class="container py-12 sm:py-16">
        <!-- Mismo grid que el contenido de abajo: el ave en la columna izquierda y el
             título en la derecha, para que arranque al mismo nivel que el carrusel. -->
        <div class="grid gap-8 lg:grid-cols-[280px_1fr] lg:items-end lg:gap-10">
            <div class="flex justify-center lg:justify-start">
                <img src="<?= e(asset('images/birds/banner/' . $banner['file'])) ?>" alt=""
                    width="<?= $banner['w'] ?>" height="<?= $banner['h'] ?>"
                    class="h-[250px] w-[250px] shrink-0 object-contain drop-shadow-md">
            </div>
            <div>
                <p class="font-body text-xs font-semibold uppercase tracking-[0.2em] text-terracota">
                    <?= ez_editable($box['ubicacion'], ['type' => 'box', 'slug' => $box['slug'], 'field' => 'ubicacion']) ?>
                </p>
                <h1 class="mt-1 font-heading text-3xl font-semibold text-cafe sm:text-4xl">
                    <?= ez_editable($box['nombre'], ['type' => 'box', 'slug' => $box['slug'], 'field' => 'nombre']) ?>
                </h1>
                <p class="mt-1 max-w-md font-body text-sm text-cafe/70 sm:text-base">
                    <?= ez_editable($box['descripcion'], ['type' => 'box', 'slug' => $box['slug'], 'field' => 'descripcion'], true) ?>
                </p>
            </div>
        </div>

        <div class="mt-8 grid gap-8 lg:grid-cols-[280px_1fr] lg:items-start lg:gap-10">
            <div class="order-2 lg:order-1">
                <h2 class="font-heading text-xl font-semibold text-cafe">
                    <?= ez_editable($centro['boxElEspacioTitulo'], ['type' => 'centro', 'field' => 'boxElEspacioTitulo']) ?>
                </h2>
                <p class="mt-2 font-body text-sm text-cafe/70">
                    <?= ez_editable($box['elEspacioDescripcion'], ['type' => 'box', 'slug' => $box['slug'], 'field' => 'elEspacioDescripcion'], true) ?>
                </p>

                <h2 class="mt-9 font-heading text-xl font-semibold text-cafe">
                    <?= ez_editable($centro['boxEquipamientoTitulo'], ['type' => 'centro', 'field' => 'boxEquipamientoTitulo']) ?>
                </h2>
                <ul class="mt-3 space-y-3">
                    <?php foreach ($box['equipamiento'] as $i => $item): ?>
                        <li class="flex items-center gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-salvia/15 text-salvia-dark">
                                <?= equipmentIcon($defaultBox['equipamiento'][$i] ?? $item, 'h-5 w-5') ?>
                            </span>
                            <span class="font-body text-sm text-cafe/80">
                                <?= ez_editable($item, ['type' => 'box-equipamiento', 'slug' => $box['slug'], 'index' => $i]) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="order-1 lg:order-2">
                <style>
                    .ez-ovl{opacity:0;pointer-events:none;background:rgba(55,50,46,.5);-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px);transition:opacity .25s ease}
                    .ez-editing .ez-boxmain:hover .ez-ovl,.ez-editing .ez-galcell:hover .ez-ovl{opacity:1;pointer-events:auto}
                    .ez-ovb{display:inline-flex;align-items:center;gap:6px;border-radius:9999px;background:rgba(251,249,244,.96);padding:7px 12px;font-family:var(--font-body,inherit);font-size:12px;font-weight:600;color:#5C4033;box-shadow:0 10px 30px -10px rgba(92,64,51,.25);cursor:pointer;border:none}
                    .ez-ovb:hover{background:#fff}
                    .ez-ovb svg{width:15px;height:15px}
                    .ez-ovb-danger{color:#dc2626}.ez-ovb-danger:hover{background:#fef2f2}
                </style>

                <!-- Carrusel del box: SOLO las fotos de su galería (independientes de la
                     foto de portada de la tarjeta del home). Todas se pueden borrar. -->
                <?php if (!empty($extraUrls)): ?>
                    <div id="ez-gallery" class="relative mx-auto w-full"
                        data-images='<?= htmlspecialchars(json_encode($extraUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>'
                        data-alt="<?= attr($box['nombre']) ?>">
                        <div class="relative aspect-[4/5] overflow-hidden rounded-[2rem] shadow-warm sm:aspect-[16/10]">
                            <img id="ez-gallery-main" src="<?= e($extraUrls[0]) ?>" alt="<?= attr($box['nombre']) ?>"
                                class="absolute inset-0 h-full w-full cursor-zoom-in object-cover transition-opacity duration-500">
                            <button type="button" id="ez-gallery-prev" aria-label="Anterior"
                                class="absolute left-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-cafe/50 text-crema backdrop-blur-sm transition-colors hover:bg-terracota">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <button type="button" id="ez-gallery-next" aria-label="Siguiente"
                                class="absolute right-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-cafe/50 text-crema backdrop-blur-sm transition-colors hover:bg-terracota">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </div>
                        <div id="ez-gallery-thumbs" class="mt-3 flex justify-center gap-2"></div>
                    </div>
                <?php else: ?>
                    <!-- Sin fotos aún: imagen "Próximamente" del box + aviso centrado con el logo. -->
                    <div class="relative aspect-[4/3] overflow-hidden rounded-[2rem] shadow-warm ring-1 ring-cafe/10">
                        <img src="<?= e(asset('images/ComingSoon/' . $comingSoonFile)) ?>"
                            alt="Próximamente mostraremos imágenes de <?= attr($box['nombre']) ?>"
                            class="absolute inset-0 h-full w-full object-cover">
                        <!-- Texto arriba, centrado -->
                        <div class="absolute inset-x-0 top-6 px-6 text-center sm:top-8">
                            <p class="font-heading text-2xl font-semibold text-cafe [text-shadow:0_1px_10px_rgba(245,241,232,.95)] sm:text-3xl">Próximamente mostraremos imágenes</p>
                            <p class="mt-1 font-body text-base text-cafe/70 [text-shadow:0_1px_8px_rgba(245,241,232,.95)]">Estamos preparando las fotos de este espacio.</p>
                        </div>
                        <!-- Logo centrado al medio de la imagen -->
                        <img src="<?= e(asset('images/logo/logo.png')) ?>" alt="Espacio Luz"
                            class="absolute left-1/2 top-1/2 h-32 w-auto -translate-x-1/2 -translate-y-1/2 drop-shadow-sm sm:h-40">
                    </div>
                <?php endif; ?>

                <?php if (puedeEditarSitio()): ?>
                    <div id="ez-gallery-editor" data-box="<?= e($box['slug']) ?>"
                        data-extra='<?= htmlspecialchars(json_encode(array_map(fn($i) => imageUrl($i), $extraImages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>'
                        data-extra-raw='<?= htmlspecialchars(json_encode($extraImages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>'></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-12 grid gap-8 lg:grid-cols-[1fr_1.2fr] lg:items-start">
            <div class="rounded-2xl bg-crema p-6 shadow-card sm:p-7">
                <h2 class="font-heading text-xl font-semibold text-cafe">Valores de arriendo</h2>
                <p class="mt-1 font-body text-xs text-cafe/45">Pasa el cursor sobre una tarifa para ver el detalle</p>
                <style>
                    .ez-tarifas { display: flex; flex-direction: column; }
                    .ez-tarifa { position: relative; border-top: 1px solid rgba(92,64,51,.10); padding: 13px 14px; border-radius: 14px; outline: none; transition: transform .25s ease, background .25s ease, box-shadow .25s ease; }
                    .ez-tarifas .ez-tarifa:first-child { border-top: none; }
                    .ez-tarifa::before { content: ""; position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 3px; height: 0; background: #D97757; transition: height .25s ease; }
                    .ez-tarifa-main { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; }
                    .ez-tarifa-precio { font-size: 1.125rem; line-height: 1.1; transition: font-size .25s ease; }
                    .ez-tarifa-label { font-size: .8125rem; text-align: right; }
                    .ez-tarifa-detalle { max-height: 0; overflow: hidden; opacity: 0; font-size: .75rem; line-height: 1.5; text-align: right; transition: max-height .3s ease, opacity .25s ease, margin-top .25s ease; }
                    .ez-tarifa:hover, .ez-tarifa:focus-visible { background: #FBF9F4; transform: scale(1.035); box-shadow: 0 16px 32px -14px rgba(92,64,51,.32); border-top-color: transparent; z-index: 2; }
                    .ez-tarifa:hover + .ez-tarifa, .ez-tarifa:focus-visible + .ez-tarifa { border-top-color: transparent; }
                    .ez-tarifa:hover::before, .ez-tarifa:focus-visible::before { height: 58%; }
                    .ez-tarifa:hover .ez-tarifa-precio, .ez-tarifa:focus-visible .ez-tarifa-precio { font-size: 1.5rem; }
                    .ez-tarifa:hover .ez-tarifa-detalle, .ez-tarifa:focus-visible .ez-tarifa-detalle { max-height: 64px; opacity: 1; margin-top: 7px; }
                    @media (prefers-reduced-motion: reduce) { .ez-tarifa, .ez-tarifa-precio, .ez-tarifa-detalle, .ez-tarifa::before { transition: none; } }
                </style>
                <div class="ez-tarifas mt-3">
                    <?php foreach ($box['tarifas'] as $row): ?>
                        <?php $tieneDetalle = !empty($row['sub']) || !empty($row['gastosComunes']); ?>
                        <div class="ez-tarifa" tabindex="0">
                            <div class="ez-tarifa-main">
                                <span class="ez-tarifa-precio font-heading font-semibold text-terracota"><?= e($row['precio']) ?></span>
                                <span class="ez-tarifa-label font-body text-cafe/75"><?= e($row['label']) ?></span>
                            </div>
                            <?php if ($tieneDetalle): ?>
                                <div class="ez-tarifa-detalle font-body">
                                    <?php if (!empty($row['sub'])): ?><span class="text-cafe/60"><?= e($row['sub']) ?></span><?php endif; ?>
                                    <?php if (!empty($row['gastosComunes'])): ?><?php if (!empty($row['sub'])): ?> · <?php endif; ?><span class="text-cafe/45">+ <?= e($row['gastosComunes']) ?> gastos comunes</span><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="mt-6 text-center font-body text-sm text-cafe/70">
                    ¿Buscas atención con una especialista?
                    <a href="<?= e(base_path('/#profesionales')) ?>" class="font-semibold text-terracota hover:underline">Reserva una hora.</a>
                </p>
            </div>

            <?php
            $waMsg = 'Hola, quiero solicitar información para reservar el box "' . $box['nombre'] . '" en Espacio Luz.';
            $waHref = 'https://wa.me/56934035774?text=' . rawurlencode($waMsg);
            ?>
            <div class="rounded-2xl bg-salvia/12 p-7 text-cafe shadow-card ring-1 ring-salvia/25 sm:p-8">
                <span class="inline-flex items-center gap-2 rounded-full bg-salvia/25 px-3 py-1 font-body text-xs font-semibold uppercase tracking-wide text-salvia-dark">Arriendo de espacio</span>
                <h2 class="mt-4 font-heading text-2xl font-semibold text-cafe">¿Te interesa arrendar <?= e($box['nombre']) ?>?</h2>
                <p class="mt-2 font-body text-sm leading-relaxed text-cafe/70">
                    Escríbenos por WhatsApp y te enviamos la información, valores y disponibilidad de este espacio. Coordinamos todo de forma personalizada.
                </p>
                <a href="<?= e($waHref) ?>" target="_blank" rel="noopener noreferrer"
                    class="mt-6 inline-flex w-full items-center justify-center gap-2.5 rounded-full bg-salvia-dark px-6 py-3.5 font-body text-base font-semibold text-crema-light shadow-card transition-all hover:-translate-y-0.5 hover:bg-salvia">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.29-.15-1.71-.84-1.97-.94-.26-.1-.46-.15-.65.15-.19.29-.75.94-.92 1.13-.17.19-.34.21-.63.07-.29-.15-1.22-.45-2.33-1.44-.86-.77-1.44-1.72-1.61-2.01-.17-.29-.02-.45.13-.6.13-.13.29-.34.44-.51.15-.17.19-.29.29-.48.1-.19.05-.36-.02-.51-.07-.15-.65-1.57-.89-2.15-.24-.57-.48-.49-.65-.5l-.56-.01c-.19 0-.51.07-.77.36-.26.29-1.01.99-1.01 2.41 0 1.42 1.04 2.79 1.18 2.98.15.19 2.04 3.12 4.95 4.37.69.3 1.23.48 1.65.62.69.22 1.32.19 1.82.12.56-.08 1.71-.7 1.95-1.37.24-.67.24-1.25.17-1.37-.07-.12-.26-.19-.55-.34zM12.05 21.5h-.01a9.44 9.44 0 0 1-4.81-1.32l-.34-.2-3.57.94.95-3.48-.22-.36a9.42 9.42 0 0 1-1.44-5.02c0-5.2 4.24-9.44 9.46-9.44 2.53 0 4.9.99 6.69 2.78a9.38 9.38 0 0 1 2.77 6.67c0 5.2-4.24 9.44-9.46 9.44zm8.04-17.48A11.36 11.36 0 0 0 12.05.5C5.8.5.72 5.58.72 11.83c0 2.08.54 4.11 1.58 5.9L.62 23.5l5.9-1.55a11.3 11.3 0 0 0 5.52 1.41h.01c6.25 0 11.33-5.08 11.33-11.33 0-3.03-1.18-5.87-3.29-8.01z"/></svg>
                    Solicitar información
                </a>
                <p class="mt-3 text-center font-body text-xs text-cafe/50">Te respondemos por WhatsApp a la brevedad.</p>
            </div>
        </div>
    </div>
</main>

<?php
include APP_ROOT . '/partials/footer.php';
include APP_ROOT . '/partials/foot.php';
