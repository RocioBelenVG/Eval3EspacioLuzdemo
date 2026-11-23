<?php
/**
 * Sección "Tipos de atención" — modalidades de acompañamiento de la clínica.
 * Estática (estilo del sitio). Se muestra entre Especialidades y Profesionales.
 */
declare(strict_types=1);

// Textos editables (cabecera de sección + tarjetas) desde el sistema de contenidos.
$sec = $content['secciones']['atenciones'] ?? ['eyebrow' => 'Cómo acompañamos', 'titulo' => 'Tipos de atención', 'descripcion' => ''];
$atenciones = $content['atenciones'] ?? [];
?>
<section id="atenciones" class="relative overflow-hidden bg-warm-gradient py-12 sm:py-16 lg:py-20">
    <!-- Elementos decorativos -->
    <div class="pointer-events-none absolute -left-24 top-10 h-72 w-72 rounded-full bg-salvia/10 blur-3xl"></div>
    <div class="pointer-events-none absolute -right-24 bottom-0 h-80 w-80 rounded-full bg-terracota/10 blur-3xl"></div>
    <div class="bg-noise pointer-events-none absolute inset-0 opacity-60"></div>

    <div class="container relative">
        <div class="mx-auto max-w-2xl text-center">
            <span class="mb-3 inline-block font-body text-xs font-bold uppercase tracking-[0.2em] text-terracota sm:text-sm">
                <?= ez_editable($sec['eyebrow'], ['type' => 'seccion', 'id' => 'atenciones', 'field' => 'eyebrow']) ?>
            </span>
            <h2 class="font-heading text-3xl font-semibold leading-tight text-balance text-cafe sm:text-4xl md:text-[2.75rem]">
                <?= ez_editable($sec['titulo'], ['type' => 'seccion', 'id' => 'atenciones', 'field' => 'titulo']) ?>
            </h2>
            <p class="mt-4 font-body text-base leading-relaxed text-balance text-cafe/75 sm:text-lg">
                <?= ez_editable($sec['descripcion'], ['type' => 'seccion', 'id' => 'atenciones', 'field' => 'descripcion'], true) ?>
            </p>
            <span class="mx-auto mt-6 block h-1 w-16 rounded-full bg-terracota/70"></span>
        </div>

        <div class="mt-16 grid gap-6 sm:gap-8 md:grid-cols-2 lg:grid-cols-4">
            <?php foreach ($atenciones as $i => $a):
                $isTerr = $a['accent'] === 'terracota';
                $badgeBg = $isTerr ? 'bg-terracota/12' : 'bg-salvia/15';
                $badgeTx = $isTerr ? 'text-terracota-dark' : 'text-salvia-dark';
                $barBg = $isTerr ? 'bg-terracota' : 'bg-salvia-dark';
            ?>
                <article class="group animate-fade-up relative flex flex-col overflow-hidden rounded-3xl border border-cafe/10 bg-white/70 p-7 shadow-card backdrop-blur-sm transition-all duration-300 hover:-translate-y-2 hover:shadow-warm"
                    style="animation-delay: <?= $i * 120 ?>ms; animation-fill-mode: backwards;">
                    <!-- franja superior de color -->
                    <span class="absolute inset-x-0 top-0 h-1.5 <?= $barBg ?> opacity-80"></span>
                    <!-- círculo decorativo -->
                    <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full <?= $isTerr ? 'bg-terracota/8' : 'bg-salvia/10' ?> transition-transform duration-500 group-hover:scale-125"></div>

                    <div class="relative flex h-14 w-14 items-center justify-center rounded-2xl <?= $badgeBg ?> <?= $badgeTx ?>">
                        <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $a['icon'] ?></svg>
                    </div>

                    <span class="relative mt-5 inline-block self-start rounded-full <?= $badgeBg ?> px-3 py-1 font-body text-[0.65rem] font-bold uppercase tracking-[0.15em] <?= $badgeTx ?>">
                        <?= ez_editable($a['tag'], ['type' => 'atencion', 'index' => $i, 'field' => 'tag']) ?>
                    </span>

                    <h3 class="relative mt-3 font-heading text-xl font-semibold leading-snug text-cafe">
                        <?= ez_editable($a['titulo'], ['type' => 'atencion', 'index' => $i, 'field' => 'titulo']) ?>
                    </h3>
                    <p class="relative mt-2.5 font-body text-sm leading-relaxed text-cafe/70">
                        <?= ez_editable($a['desc'], ['type' => 'atencion', 'index' => $i, 'field' => 'desc'], true) ?>
                    </p>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- Nota de cierre -->
        <div class="mx-auto mt-14 flex max-w-3xl flex-col items-center gap-4 rounded-3xl border border-cafe/10 bg-white/60 px-8 py-7 text-center shadow-card sm:flex-row sm:text-left">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-salvia/15 text-salvia-dark">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/></svg>
            </div>
            <p class="font-body text-sm leading-relaxed text-cafe/75 sm:text-base">
                <?= ez_editable($sec['nota'] ?? '¿No sabes por dónde comenzar? Escríbenos y te orientamos para encontrar la atención más adecuada para ti o tu familia.', ['type' => 'seccion', 'id' => 'atenciones', 'field' => 'nota'], true) ?>
            </p>
            <a href="https://wa.me/56934035774?text=<?= rawurlencode('Hola, quiero más información sobre las atenciones de Espacio Luz.') ?>" target="_blank" rel="noopener" class="inline-flex shrink-0 items-center gap-2 rounded-full bg-terracota px-5 py-2.5 font-body text-sm font-semibold text-crema-light shadow-card transition-colors hover:bg-terracota-dark">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2c-5.5 0-9.96 4.46-9.96 9.96 0 1.76.46 3.45 1.33 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.004c5.5 0 9.96-4.46 9.96-9.96 0-2.66-1.04-5.16-2.92-7.04A9.9 9.9 0 0 0 12.04 2Zm5.8 14.06c-.24.68-1.4 1.32-1.95 1.37-.5.05-.98.24-3.3-.69-2.79-1.1-4.56-3.95-4.7-4.13-.14-.18-1.12-1.49-1.12-2.84 0-1.35.71-2.02.96-2.29.24-.27.53-.34.71-.34.18 0 .36.002.52.01.17.007.39-.06.61.47.24.57.79 1.95.86 2.09.07.14.12.3.02.48-.09.18-.14.3-.27.46-.14.16-.29.36-.41.48-.14.14-.28.29-.12.57.16.27.71 1.17 1.52 1.9 1.05.93 1.93 1.22 2.2 1.36.27.14.43.12.59-.07.16-.18.68-.79.86-1.07.18-.27.36-.23.61-.14.25.09 1.6.76 1.87.9.27.14.46.2.53.32.07.12.07.68-.17 1.36Z"/></svg>
                Contáctanos
            </a>
        </div>
    </div>
</section>
