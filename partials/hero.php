<?php
/**
 * Sección Hero. Responsive:
 *  - Escritorio (lg+): ilustración de fondo (object-cover) con el texto superpuesto a la izquierda.
 *  - Móvil/Tablet (<lg): se APILA -> panel de texto con fondo oscuro (para que la crema se lea) y,
 *    debajo, la ilustración COMPLETA (w-full h-auto), de modo que se vean las dos personas y el
 *    texto sin recortes.
 * Espera $content y $hasLogo.
 */
declare(strict_types=1);

$centro = $content['centro'];
$especialidades = $content['especialidades'];
$heroImg = ez_image_slot(
    'hero-bg',
    '<img src="' . e(getImageSrc('hero-bg')) . '" alt="Espacio cálido y acogedor de Espacio Luz" class="absolute inset-0 h-full w-full object-cover">',
    IMAGE_SLOTS['hero-bg']['recommendedSize']
);
$heroSrc = e(getImageSrc('hero-bg'));
?>
<section id="top" class="relative overflow-hidden bg-warm-gradient lg:flex lg:min-h-screen lg:items-center">
    <!-- Fondo con overlay: SOLO escritorio (en móvil se recortaría a las personas). -->
    <div class="absolute inset-0 hidden lg:block">
        <?= $heroImg ?>
        <div class="pointer-events-none absolute inset-0 z-[4] bg-gradient-to-t from-cafe/40 via-transparent to-transparent"></div>
        <div class="pointer-events-none absolute inset-0 z-[4]" style="background: linear-gradient(100deg, rgba(40,27,20,.76) 0%, rgba(40,27,20,.58) 32%, rgba(40,27,20,.26) 52%, transparent 66%);"></div>
        <div class="pointer-events-none absolute inset-0 z-[4] bg-noise opacity-40"></div>
        <div class="pointer-events-none absolute inset-0 z-[4]" style="background: radial-gradient(120% 80% at 50% -10%, rgba(240,180,138,.35), rgba(240,180,138,.08) 35%, transparent 60%); mix-blend-mode: screen;"></div>
    </div>

    <!-- Panel móvil (texto + ilustración enmarcada) con fondo oscuro; en escritorio transparente (sobre la imagen). -->
    <div class="relative z-10 w-full bg-gradient-to-b from-[#2d1f16] to-[#3b2a1d] lg:bg-none lg:from-transparent lg:to-transparent">
        <div class="container pt-28 pb-7 lg:pb-24">
            <div class="max-w-2xl animate-fade-up lg:ml-[5%] xl:ml-[9%] lg:[text-shadow:0_2px_16px_rgba(40,27,20,.5)]">
                <span class="mb-5 inline-flex items-center gap-2 rounded-full border border-crema/30 bg-crema/10 px-4 py-2 font-body text-xs font-semibold uppercase tracking-[0.2em] text-crema backdrop-blur-sm">
                    <?= ez_editable($centro['ubicacion'], ['type' => 'centro', 'field' => 'ubicacion']) ?>
                </span>

                <h1 class="font-heading text-4xl font-semibold leading-[1.1] text-crema sm:text-5xl md:text-6xl">
                    <?= ez_editable($centro['heroTitulo'], ['type' => 'centro', 'field' => 'heroTitulo']) ?>
                    <span class="text-terracota-light">Luz</span>
                </h1>

                <p class="mt-6 max-w-lg font-body text-lg leading-relaxed text-crema/90 sm:text-xl">
                    <?= ez_editable($centro['heroTexto'], ['type' => 'centro', 'field' => 'heroTexto'], true) ?>
                </p>

                <div class="mt-8 flex flex-wrap items-center gap-4 lg:mt-10">
                    <?= render_button(isAuthenticated() ? 'Sistema interno' : 'Agenda tu hora', isAuthenticated() ? '/admin' : agenda_cta_href(), 'primary', 'lg') ?>
                    <?= render_button('Conoce nuestros espacios', '#boxes', 'secondary-light', 'lg') ?>
                </div>

                <div class="mt-8 flex flex-wrap gap-x-8 gap-y-3 lg:mt-12">
                    <?php foreach ($especialidades as $esp): ?>
                        <span class="flex items-center gap-2 font-body text-sm font-medium text-crema/85">
                            <span aria-hidden="true" class="h-1.5 w-1.5 rounded-full bg-terracota-light"></span>
                            <?= ez_editable($esp['nombre'], ['type' => 'especialidad', 'slug' => $esp['slug'], 'field' => 'nombre']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Ilustración enmarcada (tarjeta redondeada): SOLO móvil/tablet. -->
        <div class="px-5 pb-12 lg:hidden">
            <div class="overflow-hidden rounded-3xl shadow-warm ring-1 ring-crema/15">
                <img src="<?= $heroSrc ?>" alt="Fundadoras de Espacio Luz"
                    class="block aspect-[16/10] w-full object-cover">
            </div>
        </div>
    </div>

    <!-- Flecha para bajar: solo escritorio. -->
    <a href="#especialidades" aria-label="Bajar a la siguiente sección"
        class="absolute bottom-8 left-1/2 z-10 hidden -translate-x-1/2 animate-float text-crema/80 transition-colors hover:text-crema lg:block">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
            <path d="M12 4v14m0 0 6-6m-6 6-6-6" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </a>
</section>
