<?php
/**
 * Utilidades de presentación: escape, iniciales, íconos de equipamiento (SVG)
 * y respuestas JSON. Reemplaza a componentes UI puros del original.
 */

declare(strict_types=1);

/** Escapa texto para HTML (equivalente implícito de React al renderizar). */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escapa un atributo (alias semántico de e). */
function attr(?string $value): string
{
    return e($value);
}

/** Responde JSON y termina. Reemplaza a NextResponse.json. */
function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Lee el cuerpo JSON de una petición. Devuelve array o null. */
function read_json_body(): ?array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/** Token CSRF de la sesión (se crea si no existe). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Campo oculto con el token CSRF para formularios. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Valida el token CSRF recibido. */
function csrf_check(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/** Iniciales de un nombre (equivale a getIniciales de Profesionales.tsx). */
function getIniciales(string $nombre): string
{
    $partes = preg_split('/\s+/', trim($nombre)) ?: [];
    $primera = mb_substr($partes[0] ?? '', 0, 1, 'UTF-8');
    $ultima = count($partes) > 1 ? mb_substr($partes[count($partes) - 1], 0, 1, 'UTF-8') : '';
    return mb_strtoupper($primera . $ultima, 'UTF-8');
}

/**
 * Botón/enlace con estilos del sistema. Reemplaza a components/ui/Button.tsx.
 * $href puede ser interno (/...), ancla (#...) o externo (http/tel/mailto).
 */
function render_button(string $label, string $href, string $variant = 'primary', string $size = 'md', string $class = ''): string
{
    $base = 'inline-flex items-center justify-center gap-2 rounded-full font-body font-semibold transition-all duration-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-terracota focus-visible:ring-offset-2 focus-visible:ring-offset-crema disabled:opacity-50 disabled:pointer-events-none';
    $variants = [
        'primary' => 'bg-terracota text-crema-light shadow-card hover:bg-terracota-dark hover:shadow-warm hover:-translate-y-0.5',
        'secondary' => 'bg-transparent text-cafe border-2 border-cafe/30 hover:border-terracota hover:text-terracota',
        'secondary-light' => 'bg-crema/10 text-crema border-2 border-crema/40 backdrop-blur-sm hover:border-crema hover:bg-crema/20',
        'ghost' => 'bg-transparent text-cafe hover:text-terracota',
    ];
    $sizes = [
        'md' => 'px-6 py-3 text-sm sm:text-base',
        'lg' => 'px-8 py-4 text-base sm:text-lg',
    ];
    $classes = trim($base . ' ' . ($variants[$variant] ?? $variants['primary']) . ' ' . ($sizes[$size] ?? $sizes['md']) . ' ' . $class);

    $isHttp = str_starts_with($href, 'http');
    $isExternal = $isHttp || str_starts_with($href, 'tel:') || str_starts_with($href, 'mailto:');
    $url = ($href !== '' && $href[0] === '/') ? base_path($href) : $href;
    $target = $isHttp ? ' target="_blank" rel="noopener noreferrer"' : '';
    // Los anclas internas (#...) y rutas se dejan tal cual salvo el prefijo base.
    return '<a class="' . e($classes) . '" href="' . e($url) . '"' . $target . '>' . e($label) . '</a>';
}

/** Enlace de WhatsApp del centro con mensaje prellenado. */
function whatsapp_href(string $mensaje = ''): string
{
    return 'https://wa.me/56934035774' . ($mensaje !== '' ? '?text=' . rawurlencode($mensaje) : '');
}

/**
 * Destino de los botones "Agenda tu hora": la agenda online si hay alguna
 * profesional con reserva en línea activa; si no, WhatsApp para consultar horas.
 * Devuelve una ruta interna sin prefijo ('/agenda') o una URL absoluta.
 */
function agenda_cta_href(): string
{
    static $href = null;
    if ($href !== null) {
        return $href;
    }
    $hayAgenda = false;
    try {
        $hayAgenda = (bool) db()->query(
            "SELECT 1 FROM profesionales WHERE agenda_online = 1 AND activo = 1 AND publico = 1 LIMIT 1"
        )->fetchColumn();
    } catch (Throwable $e) {
        $hayAgenda = false;
    }
    return $href = $hayAgenda
        ? '/agenda'
        : whatsapp_href('Hola, quiero agendar una hora en Espacio Luz. ¿Qué horarios tienen disponibles?');
}

/** ¿Existe el logo real del centro? (equivale a hasLogo del original). */
function hasLogo(): bool
{
    return is_file(IMAGES_DIR . '/logo/logo.png');
}

/**
 * Texto editable inline. Para el público renderiza el texto plano; para un
 * administrador con sesión añade metadatos (data-*) que el JS del panel usa
 * para transformar el elemento en un campo editable. Reemplaza a
 * components/admin/EditableText.tsx.
 */
function ez_editable(string $value, array $target, bool $multiline = false, string $tag = 'span', string $class = '', string $placeholder = ''): string
{
    $cls = trim($class);
    if (!puedeEditarSitio()) {
        $clsAttr = $cls !== '' ? ' class="' . e($cls) . '"' : '';
        return "<{$tag}{$clsAttr}>" . e($value) . "</{$tag}>";
    }
    $cls = trim($cls . ' ez-editable');
    $json = htmlspecialchars(json_encode($target, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    // data-ez-ph: texto guía que se muestra (vía CSS) cuando el campo está vacío
    // y se está editando, para indicar qué va en cada lugar (efecto "skeleton").
    $phAttr = $placeholder !== '' ? ' data-ez-ph="' . attr($placeholder) . '"' : '';
    return "<{$tag} class=\"" . e($cls) . "\" data-ez-target='{$json}' data-ez-ml=\"" . ($multiline ? '1' : '0') . "\"{$phAttr}>" . e($value) . "</{$tag}>";
}

/**
 * Envuelve la imagen de un slot para permitir su reemplazo desde el panel.
 * Para el público devuelve la imagen tal cual; para un admin añade el
 * contenedor con data-* que el JS usa para mostrar el botón "Cambiar imagen".
 * Reemplaza a components/admin/EditableImage.tsx.
 */
function ez_image_slot(string $slotKey, string $imgHtml, string $recommendedSize = ''): string
{
    if (!puedeEditarSitio()) {
        return $imgHtml;
    }
    return '<div class="ez-image-slot absolute inset-0 z-[3]" data-ez-slot="' . e($slotKey) . '" data-ez-size="' . e($recommendedSize) . '">' . $imgHtml . '</div>';
}

/**
 * Envuelve la foto de un profesional para permitir cambiarla desde el sitio
 * (sube a /api/content/upload-professional-photo y actualiza profesionales.foto).
 * Para el público devuelve la imagen tal cual.
 */
function ez_prof_photo(int $profId, string $imgHtml): string
{
    if (!puedeEditarSitio()) {
        return $imgHtml;
    }
    return '<div class="ez-prof-photo absolute inset-0 z-[3]" data-ez-prof="' . (int) $profId . '">' . $imgHtml . '</div>';
}

/**
 * Logo del centro. Si existe la imagen real la usa; si no, el logo de
 * reemplazo (ícono + texto). Reemplaza a components/layout/Logo.tsx.
 */
function render_logo(bool $light = false, string $class = '', string $sizeCls = 'h-16 w-16'): string
{
    if (hasLogo()) {
        $cls = trim($sizeCls . ' rounded-full object-cover ' . $class);
        return '<img src="' . e(asset('images/logo/logo.png')) . '" alt="Espacio Luz" class="' . e($cls) . '">';
    }
    $circleFill = $light ? 'fill-crema/10' : 'fill-terracota/10';
    $moonFill = $light ? 'fill-crema' : 'fill-cafe';
    $dotFill = $light ? 'fill-crema' : 'fill-terracota';
    $luz = $light ? 'text-crema' : 'text-cafe';
    $sub = $light ? 'text-crema/80' : 'text-cafe/70';
    $acc = $light ? 'text-terracota-light' : 'text-terracota';
    $wrap = trim('flex items-center gap-2.5 ' . $class);
    return '
    <div class="' . e($wrap) . '">
        <svg viewBox="0 0 48 48" class="h-11 w-11 shrink-0" aria-hidden="true">
            <circle cx="24" cy="24" r="23" class="' . $circleFill . '" stroke="currentColor" stroke-width="1" />
            <path d="M29 12a12 12 0 1 0 0 24 12 12 0 0 1 0-24Z" class="' . $moonFill . '" />
            <circle cx="30" cy="16" r="1.3" class="' . $dotFill . '" />
            <circle cx="34" cy="24" r="0.9" class="' . $dotFill . '" />
        </svg>
        <span class="flex flex-col leading-none">
            <span class="font-heading text-xl font-semibold leading-tight ' . $luz . '">Espacio <span class="' . $acc . '">Luz</span></span>
            <span class="font-body text-[0.6rem] font-semibold uppercase tracking-[0.25em] ' . $sub . '">Centro Terapéutico</span>
        </span>
    </div>';
}

/**
 * Ícono SVG de un ítem de equipamiento por su texto original.
 * Reemplaza a components/ui/EquipmentIcon.tsx + icons/EquipmentIcons.tsx
 * (lucide-react + íconos personalizados) con SVG inline equivalentes.
 */
function equipmentIcon(string $label, string $class = 'h-5 w-5'): string
{
    $c = e($class);
    $open = '<svg class="' . $c . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
    $paths = [
        // Armchair (lucide)
        '2 sillones individuales' => '<path d="M19 9V6a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v3"/><path d="M3 16a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/><path d="M5 18v2"/><path d="M19 18v2"/><path d="M5 12v4"/><path d="M19 12v4"/>',
        // Archive (lucide)
        'Almacenamiento de objetos personales' => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
        // Puzzle (lucide)
        'Espacio de juego' => '<path d="M15.39 4.39a1 1 0 0 0 1.68-.474 2.5 2.5 0 1 1 3.014 3.015 1 1 0 0 0-.474 1.68l1.683 1.682a2.414 2.414 0 0 1 0 3.414L19.61 19.39a1 1 0 0 1-1.68-.474 2.5 2.5 0 1 0-3.014 3.015 1 1 0 0 1 .474 1.68l-1.683 1.682"/><path d="M4.39 15.39a1 1 0 0 1-.474 1.68 2.5 2.5 0 1 0 3.014 3.015 1 1 0 0 1 1.68.474"/>',
        // Estufa (personalizado)
        'Estufa' => '<rect x="4" y="8" width="16" height="12" rx="2"/><path d="M4 13h16"/><circle cx="8.5" cy="16.5" r="1"/><path d="M8 4c0 1.5 1 2 1 3M12 4c0 1.5 1 2 1 3M16 4c0 1.5 1 2 1 3"/>',
        // Velas y aromas (personalizado)
        'Velas y aromas' => '<path d="M12 3c1.5 1.5 2 2.5 0 4-2-1.5-1.5-2.5 0-4Z"/><rect x="9" y="9" width="6" height="11" rx="1"/><path d="M12 7v2"/>',
        // Cama saltarina (personalizado)
        'Cama saltarina individual' => '<ellipse cx="12" cy="9" rx="9" ry="3.5"/><path d="M4 10l-1 9M20 10l1 9M7 11l-1 8M17 11l1 8"/>',
        // Hamacas (personalizado)
        'Hamacas' => '<path d="M3 5v14M21 5v14M3 10c4 4 14 4 18 0"/><path d="M6 11l1 3M18 11l-1 3"/>',
        // Piscina de pelotas (personalizado)
        'Piscina de pelotas' => '<path d="M3 11h18v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><circle cx="7" cy="8" r="2"/><circle cx="12" cy="7" r="2"/><circle cx="17" cy="8" r="2"/>',
        // Suelo acolchado (personalizado)
        'Suelo acolchado' => '<rect x="3" y="8" width="18" height="10" rx="2"/><path d="M9 8v10M15 8v10M3 13h18"/>',
    ];
    $path = $paths[$label] ?? $paths['Almacenamiento de objetos personales'];
    return $open . $path . '</svg>';
}
