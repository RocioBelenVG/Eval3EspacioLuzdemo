<?php
/**
 * Iconos de línea para las secciones de las páginas legales/informativas
 * (privacidad y términos). Decorativos; combinan con el estilo del sitio.
 */
declare(strict_types=1);

/** Devuelve el markup SVG de un icono para la sección $n de la página $key. */
function page_section_icon(string $key, int $n): string
{
    $icons = [
        'building'  => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4M10 10h4M10 14h4"/>',
        'clipboard' => '<rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M8 11h8M8 15h5"/>',
        'target'    => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/>',
        'consent'   => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
        'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
        'rights'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m15 11 2 2 4-4"/>',
        'cookie'    => '<path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"/><path d="M8.5 8.5v.01M12 12v.01M15 15v.01M9 15v.01M16 10v.01"/>',
        'refresh'   => '<path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/>',
        'mail'      => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m2 7 10 6 10-6"/>',
        'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'calendar'  => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/>',
        'card'      => '<rect width="20" height="14" x="2" y="5" rx="2"/><path d="M2 10h20M6 15h4"/>',
        'heart'     => '<path d="M19 14c1.5-1.5 3-3.2 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5c0 2.3 1.5 4 3 5.5l7 7Z"/>',
        'monitor'   => '<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'copyright' => '<circle cx="12" cy="12" r="9"/><path d="M14.8 9.6a3.5 3.5 0 1 0 0 4.8"/>',
        'alert'     => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>',
        'scale'     => '<path d="M12 3v18M5 21h14M6 7h12l-6-3Z"/><path d="m6 7-3 6a4 4 0 0 0 6 0ZM18 7l3 6a4 4 0 0 0-6 0Z"/>',
        'document'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
    ];

    $map = [
        'privacidad' => [1 => 'building', 2 => 'clipboard', 3 => 'target', 4 => 'consent', 5 => 'users', 6 => 'clock', 7 => 'shield', 8 => 'rights', 9 => 'cookie', 10 => 'refresh', 11 => 'mail'],
        'terminos'   => [1 => 'info', 2 => 'calendar', 3 => 'card', 4 => 'refresh', 5 => 'heart', 6 => 'monitor', 7 => 'copyright', 8 => 'alert', 9 => 'refresh', 10 => 'scale', 11 => 'mail'],
    ];

    $name  = $map[$key][$n] ?? 'document';
    $inner = $icons[$name] ?? $icons['document'];

    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5" aria-hidden="true">' . $inner . '</svg>';
}
