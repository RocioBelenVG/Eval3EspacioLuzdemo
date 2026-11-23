<?php
/**
 * Pie del sitio. Reemplaza a components/layout/Footer.tsx.
 * Espera $content (resultado de getContent()).
 */
declare(strict_types=1);

$centro = $content['centro'];
$quickLinks = [
    ['href' => '/#profesionales', 'label' => 'Profesionales'],
    ['href' => '/#boxes', 'label' => 'Boxes'],
    ['href' => '/#contacto', 'label' => 'Contacto'],
];
$telHref = 'tel:' . preg_replace('/\s/', '', $centro['telefono']);
$igHref = 'https://www.instagram.com/espacioterapeuticoluz/?hl=es-la';
?>
<footer id="contacto" class="mt-auto bg-cafe text-crema">
    <div class="container py-16">
        <div class="grid gap-12 md:grid-cols-[2fr_1fr_1fr]">
            <div>
                <?= render_logo(true, '', 'h-20 w-20') ?>
                <p class="mt-5 max-w-sm font-body text-sm leading-relaxed text-crema/75">
                    <?= ez_editable($centro['footerTexto'] ?? ($centro['descripcion'] . '. Un espacio cálido para tu bienestar y el de tu familia en ' . $centro['ubicacion'] . '.'), ['type' => 'centro', 'field' => 'footerTexto'], true) ?>
                </p>
            </div>

            <div>
                <h3 class="font-heading text-lg font-semibold text-crema">Navegación</h3>
                <ul class="mt-4 space-y-3">
                    <?php foreach ($quickLinks as $link): ?>
                        <li><a href="<?= e(base_path($link['href'])) ?>" class="font-body text-sm text-crema/75 transition-colors hover:text-terracota-light"><?= e($link['label']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div>
                <h3 class="font-heading text-lg font-semibold text-crema">Contacto</h3>
                <ul class="mt-4 space-y-3 font-body text-sm text-crema/75">
                    <li><?= ez_editable($centro['ubicacion'], ['type' => 'centro', 'field' => 'ubicacion']) ?></li>
                    <li><a href="<?= e($telHref) ?>" class="hover:text-terracota-light"><?= ez_editable($centro['telefono'], ['type' => 'centro', 'field' => 'telefono']) ?></a></li>
                    <li><a href="mailto:<?= e($centro['email']) ?>" class="hover:text-terracota-light"><?= ez_editable($centro['email'], ['type' => 'centro', 'field' => 'email']) ?></a></li>
                    <li>
                        <a href="<?= e($igHref) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 transition-colors hover:text-terracota-light">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true" class="shrink-0">
                                <rect x="2" y="2" width="20" height="20" rx="5.5"/>
                                <circle cx="12" cy="12" r="4.2"/>
                                <circle cx="17.6" cy="6.4" r="1.1" fill="currentColor" stroke="none"/>
                            </svg>
                            <?= ez_editable($centro['instagram'], ['type' => 'centro', 'field' => 'instagram']) ?>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <div class="mt-14 border-t border-crema/15 pt-8 font-body text-xs text-crema/60">
            <div class="grid items-center gap-6 text-center md:grid-cols-3 md:text-left">
                <!-- Izquierda: copyright + enlaces legales -->
                <div class="flex flex-col items-center gap-1.5 md:items-start">
                    <p>© <?= date('Y') ?> <?= e($centro['nombre']) ?>. Todos los derechos reservados.</p>
                    <div class="flex items-center gap-3 text-crema/50">
                        <a href="<?= e(base_path('/privacidad')) ?>" class="transition-colors hover:text-crema">Política de privacidad</a>
                        <span aria-hidden="true">·</span>
                        <a href="<?= e(base_path('/terminos')) ?>" class="transition-colors hover:text-crema">Términos y condiciones</a>
                    </div>
                </div>
                <!-- Centro: crédito arriba + logo Virginio Gómez abajo -->
                <div class="flex flex-col items-center gap-3">
                    <span class="font-body text-xs leading-tight text-crema/70">
                        Hecho por <span class="font-semibold text-crema">Rocío Riquelme &amp; Diego Godoy</span>, estudiantes de
                    </span>
                    <img src="<?= e(asset('images/partners/virginio-gomez-blanco.png')) ?>"
                        alt="Instituto Profesional Virginio Gómez — Universidad de Concepción"
                        class="h-24 w-auto">
                </div>
                <!-- Derecha: vacío (balancea el centro) -->
                <div class="hidden md:block"></div>
            </div>
        </div>
    </div>
</footer>
