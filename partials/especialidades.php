<?php
/**
 * Sección Especialidades. Reemplaza a components/sections/Especialidades.tsx.
 * Espera $content.
 */
declare(strict_types=1);

$especialidades = $content['especialidades'];
$seccion = $content['secciones']['especialidades'];
?>
<section id="especialidades" class="bg-crema-light py-24 sm:py-32">
    <div class="container">
        <div class="max-w-2xl mx-auto text-center">
            <span class="mb-3 inline-block font-body text-xs sm:text-sm font-bold uppercase tracking-[0.2em] text-terracota">
                <?= ez_editable($seccion['eyebrow'], ['type' => 'seccion', 'id' => 'especialidades', 'field' => 'eyebrow']) ?>
            </span>
            <h2 class="font-heading text-3xl sm:text-4xl md:text-[2.75rem] font-semibold leading-tight text-balance text-cafe">
                <?= ez_editable($seccion['titulo'], ['type' => 'seccion', 'id' => 'especialidades', 'field' => 'titulo']) ?>
            </h2>
            <p class="mt-4 font-body text-base sm:text-lg leading-relaxed text-balance text-cafe/75">
                <?= ez_editable($seccion['descripcion'], ['type' => 'seccion', 'id' => 'especialidades', 'field' => 'descripcion'], true) ?>
            </p>
        </div>

        <div class="mt-16 grid gap-8 md:grid-cols-3">
            <?php foreach ($especialidades as $i => $esp): ?>
                <?php
                $slotKey = 'especialidad-' . $esp['slug'];
                $size = IMAGE_SLOTS[$slotKey]['recommendedSize'] ?? '';
                $img = ez_image_slot(
                    $slotKey,
                    '<img src="' . e(getImageSrc($slotKey)) . '" alt="" class="absolute inset-0 h-full w-full object-contain">',
                    $size
                );
                ?>
                <div class="group relative overflow-hidden rounded-3xl border border-cafe/10 bg-white/60 p-8 shadow-card transition-all duration-300 hover:-translate-y-2 hover:shadow-warm animate-fade-up"
                    style="animation-delay: <?= $i * 120 ?>ms; animation-fill-mode: backwards;">
                    <div class="absolute -right-8 -top-8 h-28 w-28 rounded-full bg-salvia/10 transition-transform duration-500 group-hover:scale-125"></div>

                    <div class="relative aspect-[12/5] w-full overflow-hidden rounded-2xl bg-[#F9EFEA]">
                        <?= $img ?>
                    </div>

                    <h3 class="relative mt-6 font-heading text-2xl font-semibold text-cafe">
                        <?= ez_editable($esp['nombre'], ['type' => 'especialidad', 'slug' => $esp['slug'], 'field' => 'nombre']) ?>
                    </h3>
                    <p class="relative mt-3 font-body text-sm leading-relaxed text-cafe/70">
                        <?= ez_editable($esp['descripcion'], ['type' => 'especialidad', 'slug' => $esp['slug'], 'field' => 'descripcion'], true) ?>
                    </p>

                    <ul class="relative mt-6 space-y-2.5 border-t border-cafe/10 pt-6">
                        <?php foreach ($esp['beneficios'] as $bi => $b): ?>
                            <li class="flex items-start gap-2.5 font-body text-sm text-cafe/80">
                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-salvia-dark" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7 7a1 1 0 0 1-1.4 0l-3-3a1 1 0 1 1 1.4-1.4L9 11.6l6.3-6.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/>
                                </svg>
                                <?= ez_editable($b, ['type' => 'especialidad-beneficio', 'slug' => $esp['slug'], 'index' => $bi]) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
