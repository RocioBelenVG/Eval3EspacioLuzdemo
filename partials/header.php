<?php
/**
 * Encabezado del sitio. Reemplaza a components/layout/Header.tsx.
 * El estado "sólido" (al hacer scroll o abrir el menú) lo maneja main.js
 * alternando la clase .solid; los colores se ajustan con el CSS de abajo.
 */
declare(strict_types=1);

// Las secciones viven en el inicio: se enlazan con la ruta del home + ancla, para
// que el menú funcione desde cualquier página (fichas, boxes, quiénes somos...).
// "Contacto" apunta al footer, que está en todas las páginas.
$navLinks = [
    ['href' => base_path('/#atenciones'), 'label' => 'Atenciones'],
    ['href' => base_path('/#profesionales'), 'label' => 'Profesionales'],
    ['href' => base_path('/#boxes'), 'label' => 'Boxes'],
    ['href' => '#contacto', 'label' => 'Contacto'],
];
$authed = isAuthenticated();
?>
<style>
    #site-header { background: linear-gradient(to bottom, rgba(92,64,51,.40), transparent); }
    #site-header.solid { background: rgba(245,241,232,.95); backdrop-filter: blur(12px); box-shadow: 0 10px 30px -10px rgba(92,64,51,.18); }
    #site-header .hdr-link { color: #F5F1E8; }
    #site-header.solid .hdr-link { color: #5C4033; }
    #site-header .hdr-link:hover { color: #D97757; }
    #site-header .hdr-bar { background: #F5F1E8; }
    #site-header.solid .hdr-bar { background: #5C4033; }
    #site-header [data-logo="light"] { display: inline-flex; }
    #site-header [data-logo="dark"] { display: none; }
    #site-header.solid [data-logo="light"] { display: none; }
    #site-header.solid [data-logo="dark"] { display: inline-flex; }
    #site-header [data-auth="transparent"] { display: inline-flex; }
    #site-header [data-auth="solid"] { display: none; }
    #site-header.solid [data-auth="transparent"] { display: none; }
    #site-header.solid [data-auth="solid"] { display: inline-flex; }
</style>

<header id="site-header" class="fixed inset-x-0 top-0 z-50 transition-all duration-300">
    <div class="container">
        <div class="flex h-20 items-center justify-between">
            <a href="<?= e(base_path('/')) ?>" class="shrink-0">
                <span data-logo="light"><?= render_logo(true) ?></span>
                <span data-logo="dark"><?= render_logo(false) ?></span>
            </a>

            <nav class="hidden items-center gap-9 lg:flex">
                <?php foreach ($navLinks as $link): ?>
                    <a href="<?= e($link['href']) ?>" class="hdr-link font-body text-sm font-semibold uppercase tracking-wide transition-colors"><?= e($link['label']) ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="hidden items-center gap-3 lg:flex">
                <?= header_auth_button($authed) ?>
                <?= render_button(isAuthenticated() ? 'Sistema interno' : 'Agenda tu hora', isAuthenticated() ? '/admin' : agenda_cta_href(), 'primary', 'md') ?>
            </div>

            <button type="button" id="ez-menu-toggle"
                class="flex h-10 w-10 flex-col items-center justify-center gap-1.5 lg:hidden"
                aria-label="Abrir menú" aria-expanded="false">
                <span class="hdr-bar h-0.5 w-6 rounded-full transition-all duration-300"></span>
                <span class="hdr-bar h-0.5 w-6 rounded-full transition-all duration-300"></span>
                <span class="hdr-bar h-0.5 w-6 rounded-full transition-all duration-300"></span>
            </button>
        </div>
    </div>

    <div id="ez-mobile-menu" class="overflow-hidden bg-crema shadow-card transition-[max-height] duration-300 ease-in-out lg:hidden" style="max-height:0">
        <div class="container">
            <nav class="flex flex-col gap-1 py-4">
                <?php foreach ($navLinks as $link): ?>
                    <a href="<?= e($link['href']) ?>" data-close-menu
                        class="rounded-lg px-3 py-3 font-body text-base font-semibold text-cafe transition-colors hover:bg-crema-dark hover:text-terracota"><?= e($link['label']) ?></a>
                <?php endforeach; ?>
                <?= render_button(isAuthenticated() ? 'Sistema interno' : 'Agenda tu hora', isAuthenticated() ? '/admin' : agenda_cta_href(), 'primary', 'md', 'mt-2 w-full') ?>
                <div class="mt-1 flex justify-center">
                    <?= header_auth_button($authed, true) ?>
                </div>
            </nav>
        </div>
    </div>
</header>

<?php
/**
 * Botón de sesión del header. Para la barra transparente y la sólida usa el
 * mismo texto pero distinto color (dos variantes que el CSS muestra/oculta).
 * $forceSolid fuerza el estilo oscuro (menú móvil, siempre sobre fondo claro).
 */
function header_auth_button(bool $authed, bool $forceSolid = false): string
{
    $userIcon = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 20c0-4 3.5-7 8-7s8 3 8 7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    $label = $authed ? 'Cerrar sesión' : 'Iniciar sesión';
    $href = $authed ? base_path('/logout') : base_path('/admin/login');

    $solidCls = 'border-cafe/20 text-cafe hover:border-terracota hover:text-terracota';
    $transCls = 'border-crema/30 text-crema hover:border-terracota-light hover:text-terracota-light';
    $baseCls = 'inline-flex items-center gap-1.5 rounded-full border px-4 py-2 font-body text-sm font-semibold transition-colors';

    if ($forceSolid) {
        return '<a href="' . e($href) . '" class="' . e($baseCls . ' ' . $solidCls) . '">' . $userIcon . ' ' . e($label) . '</a>';
    }
    // Dos variantes; el CSS del header decide cuál se ve según el estado.
    return '<a href="' . e($href) . '" data-auth="transparent" class="' . e($baseCls . ' ' . $transCls) . '">' . $userIcon . ' ' . e($label) . '</a>'
         . '<a href="' . e($href) . '" data-auth="solid" class="' . e($baseCls . ' ' . $solidCls) . '">' . $userIcon . ' ' . e($label) . '</a>';
}
?>
