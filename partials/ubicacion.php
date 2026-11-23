<?php
/**
 * Sección Cómo llegar (mapa MapLibre). Reemplaza a Ubicacion.tsx + MapaUbicacion.tsx.
 * El mapa se inicializa en main.js con el mismo map-style.json y marcador.
 * Espera $content (usa el centro fusionado).
 */
declare(strict_types=1);

$centro = $content['centro'];
$seccion = $content['secciones']['ubicacion'] ?? ['eyebrow' => 'Visítanos', 'titulo' => 'Cómo llegar', 'descripcion' => 'Nos encontramos en ' . $centro['ubicacion'] . '.'];
$mapsUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($centro['nombre'] . ', ' . $centro['ubicacion']);
?>
<section class="bg-crema py-12 sm:py-16 lg:py-20">
    <div class="container">
        <div class="max-w-2xl mx-auto text-center">
            <span class="mb-3 inline-block font-body text-xs sm:text-sm font-bold uppercase tracking-[0.2em] text-terracota">
                <?= ez_editable($seccion['eyebrow'], ['type' => 'seccion', 'id' => 'ubicacion', 'field' => 'eyebrow']) ?>
            </span>
            <h2 class="font-heading text-3xl sm:text-4xl md:text-[2.75rem] font-semibold leading-tight text-balance text-cafe">
                <?= ez_editable($seccion['titulo'], ['type' => 'seccion', 'id' => 'ubicacion', 'field' => 'titulo']) ?>
            </h2>
            <p class="mt-4 font-body text-base sm:text-lg leading-relaxed text-balance text-cafe/75">
                <?= ez_editable($seccion['descripcion'], ['type' => 'seccion', 'id' => 'ubicacion', 'field' => 'descripcion'], true) ?>
            </p>
        </div>

        <div class="mt-10 overflow-hidden rounded-[2rem] shadow-warm">
            <div class="relative h-[280px] w-full sm:h-[340px]">
                <div id="ez-map" class="h-full w-full"
                    data-style="<?= e(asset('map-style.json')) ?>"
                    data-lat="-37.4566161" data-lng="-72.3353233"
                    data-nombre="<?= attr($centro['nombre']) ?>"
                    data-direccion="<?= attr($centro['ubicacion']) ?>">
                    <div class="flex h-full w-full items-center justify-center bg-crema-dark/40">
                        <span class="font-body text-sm text-cafe/50">Cargando mapa…</span>
                    </div>
                </div>
            </div>

            <div class="flex flex-col items-start gap-4 bg-cafe p-6 text-crema sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <?= ez_editable($centro['nombre'], ['type' => 'centro', 'field' => 'nombre'], false, 'p', 'font-heading text-xl font-semibold') ?>
                    <?= ez_editable($centro['ubicacion'], ['type' => 'centro', 'field' => 'ubicacion'], true, 'p', 'mt-1 font-body text-sm text-crema/75') ?>
                </div>
                <?= render_button('Cómo llegar', $mapsUrl, 'primary', 'md') ?>
            </div>
        </div>
    </div>
</section>
