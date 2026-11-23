<?php
/**
 * Estructura común del panel administrativo (cabecera + barra lateral).
 * Variables esperadas: $panelTitle (string), $panelActive (string clave del menú).
 * Cierra con partials/panel/bottom.php.
 */
declare(strict_types=1);

$panelTitle = $panelTitle ?? 'Panel';
$panelActive = $panelActive ?? '';
$nombre = currentAdminName() ?? currentAdminEmail() ?? 'Administrador';
$rol = currentAdminRol() ?? 'editor';
$esAdmin = isAdmin();
$panelEmbed = ($_GET['embed'] ?? '') === '1';

$nav = [
    ['key' => 'dashboard', 'label' => 'Panel general', 'href' => '/admin',                         'admin' => false, 'sec' => 'centro', 'icon' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>'],
    ['key' => 'cal-boxes', 'label' => 'Calendario de Boxes', 'href' => '/admin/calendario-boxes', 'admin' => false, 'sec' => 'centro', 'icon' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>'],
    ['key' => 'cal-prof',  'label' => 'Calendario de Profesionales', 'href' => '/admin/calendario-profesionales', 'admin' => false, 'sec' => 'centro', 'icon' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18"/><circle cx="12" cy="14" r="2.2"/><path d="M8.5 19c0-1.9 1.6-3 3.5-3s3.5 1.1 3.5 3"/>'],
    ['key' => 'horarios',  'label' => 'Días y horarios', 'href' => '/admin/horarios',              'admin' => true,  'sec' => 'centro', 'icon' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'],
    ['key' => 'pacientes',  'label' => 'Pacientes',      'href' => '/admin/pacientes',                'admin' => false, 'sec' => 'centro', 'icon' => '<circle cx="12" cy="8" r="3.4"/><path d="M5 20c0-3.6 3-6 7-6s7 2.4 7 6"/>'],
    ['key' => 'profesionales', 'label' => 'Profesionales', 'href' => '/admin/profesionales',       'admin' => true,  'sec' => 'centro',  'icon' => '<path d="M12 2l2.4 4.9 5.4.8-3.9 3.8.9 5.4L12 14.9 7.2 17l.9-5.4L4.2 7.7l5.4-.8z"/>'],
    ['key' => 'boxes',     'label' => 'Boxes',         'href' => '/admin/boxes',                   'admin' => true,  'sec' => 'centro',  'icon' => '<path d="M3 21h18M5 21V8l7-5 7 5v13"/><path d="M9 21v-6h6v6"/><path d="M9 10h.01M15 10h.01"/>'],
    ['key' => 'empresas',  'label' => 'Empresas y SII', 'href' => '/admin/empresas',                'admin' => true,  'sec' => 'facturacion',  'icon' => '<path d="M3 21h18M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6M9 9h.01M15 9h.01M9 12h.01M15 12h.01"/>'],
    ['key' => 'productos', 'label' => 'Productos',     'href' => '/admin/productos',               'admin' => true,  'sec' => 'facturacion',  'icon' => '<path d="M20 7l-8-4-8 4 8 4 8-4z"/><path d="M4 7v10l8 4 8-4V7"/><path d="M12 11v10"/>'],
    ['key' => 'boletas',   'label' => 'Documentos', 'href' => '/admin/documentos',            'admin' => false, 'sec' => 'facturacion', 'icon' => '<path d="M6 2h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/><path d="M14 2v6h6M9 13h6M9 17h4"/>'],
    ['key' => 'clientes',  'label' => 'Clientes',      'href' => '/admin/clientes',                'admin' => false, 'sec' => 'facturacion', 'icon' => '<path d="M3 21V9l6-4 6 4v12"/><path d="M15 21V12h6v9"/><path d="M7 13h4M7 17h4M3 21h18"/>'],
    ['key' => 'usuarios',  'label' => 'Usuarios y Roles', 'href' => '/admin/usuarios',             'admin' => true,  'sec' => 'admin',  'icon' => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-5 6-5s6 1.7 6 5"/><circle cx="18" cy="9" r="2"/><path d="M16.5 20c0-2 .9-3.4 2.3-3.4"/>'],
    ['key' => 'sitio',     'label' => 'Editar o ver el sitio', 'href' => '/',                    'admin' => false, 'sec' => 'admin', 'icon' => '<path d="M3 12l9-9 9 9M5 10v10h14V10"/><path d="M14.5 14.5l3-3 1.5 1.5-3 3h-1.5z"/>'],
    // Módulos nuevos (Evaluación 3): consumen la API REST /api/v1 con JWT. 'roles' = quién los ve.
    ['key' => 'atenciones', 'label' => 'Atenciones', 'href' => '/admin/atenciones', 'admin' => false, 'sec' => 'analisis', 'roles' => ['admin', 'editor', 'pacientes', 'gerencia'], 'icon' => '<path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9"/>'],
    ['key' => 'bi',         'label' => 'Dashboard e indicadores', 'href' => '/admin/bi', 'admin' => false, 'sec' => 'analisis', 'roles' => ['admin', 'gerencia'], 'icon' => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>'],
    ['key' => 'reportes',   'label' => 'Reportes', 'href' => '/admin/reportes', 'admin' => false, 'sec' => 'analisis', 'roles' => ['admin', 'editor', 'gerencia'], 'icon' => '<path d="M6 2h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/><path d="M9 17v-3M12 17v-6M15 17v-4"/>'],
    ['key' => 'auditoria',  'label' => 'Auditoría', 'href' => '/admin/auditoria', 'admin' => true, 'sec' => 'analisis', 'roles' => ['admin'], 'icon' => '<path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z"/><path d="M9 12l2 2 4-4"/>'],
    ['key' => 'apidocs',    'label' => 'API (Swagger)', 'href' => '/api/docs', 'admin' => true, 'sec' => 'admin', 'roles' => ['admin'], 'icon' => '<path d="M8 9l-3 3 3 3M16 9l3 3-3 3M13 6l-2 12"/>'],
    ['key' => 'apariencia', 'label' => 'Apariencia',     'href' => '/admin/apariencia',             'admin' => true,  'sec' => 'admin',  'icon' => '<path d="M12 3a9 9 0 1 0 0 18c.9 0 1.6-.7 1.6-1.6 0-.4-.2-.8-.5-1.1-.3-.3-.5-.7-.5-1.1 0-.9.7-1.6 1.6-1.6H16a5 5 0 0 0 5-5c0-3.9-4-7-9-7Z"/><circle cx="7.5" cy="10.5" r="1"/><circle cx="12" cy="7.5" r="1"/><circle cx="16.5" cy="10.5" r="1"/>'],
];

// Secciones del menú: cada una con su propio fondo, color de acento y etiqueta.
$navSecciones = [
    'analisis'    => ['titulo' => 'Análisis y operación', 'sub' => 'Atenciones, dashboard y reportes'],
    'centro'      => ['titulo' => 'Gestión del centro', 'sub' => 'Agenda, pacientes y espacios'],
    'facturacion' => ['titulo' => 'Facturación',        'sub' => 'Documentos tributarios y cobros'],
    'admin'       => ['titulo' => 'Administración',     'sub' => 'Accesos y sitio web'],
];
$navPorSeccion = [];
foreach ($nav as $item) {
    if (isset($item['roles'])) {                       /* módulos nuevos: lista explícita de roles */
        if (!in_array($rol, $item['roles'], true)) continue;
    } else {
        if ($item['admin'] && !$esAdmin) continue;
        if (esRolPacientes() && $item['key'] !== 'pacientes') continue; /* rol Pacientes: su módulo + Atenciones */
        if (esRolGerencia()) continue;                 /* Gerencia: solo análisis (lectura) */
    }
    $navPorSeccion[$item['sec']][] = $item;
}
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($panelTitle) ?> — Panel · Espacio Luz Demo</title>
    <link rel="icon" href="<?= e(asset('images/logo/logo.png')) ?>">
    <?php require_once APP_ROOT . '/partials/fonts.php'; echo ezFontsHead(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: {
                colors: {
                    crema: { DEFAULT: "#F5F1E8", light: "#FBF9F4", dark: "#EAE3D3" },
                    salvia: { DEFAULT: "#8B9E8F", light: "#A9BAAD", dark: "#6D8072" },
                    terracota: { DEFAULT: "#D97757", light: "#E59A80", dark: "#B85C3E" },
                    cafe: { DEFAULT: "#5C4033", light: "#7A5A47", dark: "#3D2A21" },
                },
                fontFamily: { heading: ["var(--font-heading)"], body: ["var(--font-body)"], script: ["var(--font-script)"] },
                boxShadow: { card: "0 10px 30px -12px rgba(92,64,51,.18)", warm: "0 20px 45px -15px rgba(92,64,51,.25)" },
            } },
        };
    </script>
    <style>
        body { font-family: var(--font-body); background: #F3EEE4; color: #5C4033; }
        .pnl-link.active { background: rgba(217,119,87,.16); color: #F5F1E8; }
        .pnl-link.active .pnl-ic { color: #E59A80; }
        /* ===== Menú lateral por secciones ===== */
        .pnl-nav { scrollbar-width: thin; scrollbar-color: rgba(245,241,232,.18) transparent; }
        .pnl-nav::-webkit-scrollbar-thumb { background: rgba(245,241,232,.18); }
        .pnl-sec-head { display: flex; align-items: center; gap: .5rem; padding: 0 .75rem .35rem; }
        .pnl-sec-ic { display: flex; height: 1.35rem; width: 1.35rem; flex-shrink: 0; align-items: center; justify-content: center; border-radius: .45rem; }
        .pnl-sec-ic svg { height: .8rem; width: .8rem; }
        .pnl-sec-tit { font-family: var(--font-body); font-size: .62rem; font-weight: 800; text-transform: uppercase; letter-spacing: .16em; line-height: 1; }
        .pnl-sec-sub { font-family: var(--font-body); font-size: .62rem; line-height: 1.2; margin-top: .2rem; opacity: .55; }
        .pnl-sec-n { margin-left: auto; font-family: var(--font-body); font-size: .6rem; font-weight: 700; border-radius: 9999px; padding: .1rem .45rem; font-variant-numeric: tabular-nums; }
        .pnl-sec .pnl-link { position: relative; }
        /* 1 · Gestión del centro (terracota, sobre el café base) */
        .pnl-sec-centro .pnl-sec-ic { background: rgba(217,119,87,.22); color: #E59A80; }
        .pnl-sec-centro .pnl-sec-tit { color: #E59A80; }
        .pnl-sec-centro .pnl-sec-n { background: rgba(217,119,87,.18); color: #E59A80; }
        .pnl-sec-centro .pnl-link.active::before { content: ""; position: absolute; left: -.75rem; top: .45rem; bottom: .45rem; width: 3px; border-radius: 0 3px 3px 0; background: #D97757; }
        /* 2 · Facturación (salvia, bloque hundido más oscuro) */
        .pnl-sec-facturacion { background: linear-gradient(180deg, rgba(61,42,33,.85), rgba(61,42,33,.6)); border: 1px solid rgba(139,158,143,.28); border-radius: 1.1rem; padding: .6rem .4rem .35rem; box-shadow: inset 0 1px 0 rgba(169,186,173,.08), inset 0 -10px 24px -18px rgba(0,0,0,.6); }
        .pnl-sec-facturacion .pnl-sec-head { padding-left: .45rem; padding-right: .45rem; }
        .pnl-sec-facturacion .pnl-sec-ic { background: rgba(139,158,143,.25); color: #C3D1C6; }
        .pnl-sec-facturacion .pnl-sec-tit { color: #A9BAAD; }
        .pnl-sec-facturacion .pnl-sec-n { background: rgba(139,158,143,.2); color: #C3D1C6; }
        .pnl-sec-facturacion .pnl-link { color: rgba(234,227,211,.78); }
        .pnl-sec-facturacion .pnl-link .pnl-ic { color: rgba(169,186,173,.7); }
        .pnl-sec-facturacion .pnl-link:hover { background: rgba(139,158,143,.14); }
        .pnl-sec-facturacion .pnl-link.active { background: rgba(139,158,143,.26); color: #F5F1E8; box-shadow: inset 3px 0 0 #A9BAAD; }
        .pnl-sec-facturacion .pnl-link.active .pnl-ic { color: #D4E0D7; }
        /* 3 · Administración (crema, bloque claro con borde punteado) */
        .pnl-sec-admin { background: rgba(245,241,232,.06); border: 1px dashed rgba(245,241,232,.2); border-radius: 1.1rem; padding: .6rem .4rem .35rem; }
        .pnl-sec-admin .pnl-sec-head { padding-left: .45rem; padding-right: .45rem; }
        .pnl-sec-admin .pnl-sec-ic { background: rgba(245,241,232,.14); color: #F5F1E8; }
        .pnl-sec-admin .pnl-sec-tit { color: rgba(245,241,232,.85); }
        .pnl-sec-admin .pnl-sec-n { background: rgba(245,241,232,.12); color: rgba(245,241,232,.8); }
        .pnl-sec-admin .pnl-link { font-size: .82rem; color: rgba(245,241,232,.7); }
        .pnl-sec-admin .pnl-link:hover { background: rgba(245,241,232,.1); }
        .pnl-sec-admin .pnl-link.active { background: rgba(245,241,232,.92); color: #3D2A21; }
        .pnl-sec-admin .pnl-link.active .pnl-ic { color: #B85C3E; }
        /* ===== Efecto glass + movimiento entre opciones ===== */
        #pnl-sidebar { background-image:
            radial-gradient(120% 40% at 0% 8%, rgba(217,119,87,.16), transparent 60%),
            radial-gradient(110% 35% at 100% 55%, rgba(139,158,143,.14), transparent 60%),
            radial-gradient(120% 35% at 10% 100%, rgba(245,241,232,.08), transparent 60%); }
        .pnl-items { position: relative; display: flex; flex-direction: column; gap: .25rem; }
        .pnl-sec .pnl-link {
            position: relative; z-index: 1; isolation: isolate;
            background: linear-gradient(135deg, rgba(255,255,255,.075), rgba(255,255,255,.015));
            border: 1px solid rgba(255,255,255,.07);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.07), 0 1px 2px rgba(0,0,0,.12);
            -webkit-backdrop-filter: blur(10px) saturate(140%); backdrop-filter: blur(10px) saturate(140%);
            transition: transform .38s cubic-bezier(.2,.9,.25,1.15), background .3s ease, border-color .3s ease, box-shadow .3s ease, color .2s ease;
        }
        /* Brillo que cruza el vidrio al pasar */
        .pnl-sec .pnl-link::after {
            content: ""; position: absolute; inset: 0; z-index: -1; border-radius: inherit; pointer-events: none;
            background: linear-gradient(110deg, transparent 30%, rgba(255,255,255,.16) 48%, transparent 66%);
            background-size: 250% 100%; background-position: 130% 0; opacity: 0; transition: background-position .7s ease, opacity .3s ease;
        }
        .pnl-sec .pnl-link:hover::after { opacity: 1; background-position: -30% 0; }
        .pnl-sec .pnl-link:hover {
            transform: translateX(5px);
            background: linear-gradient(135deg, rgba(255,255,255,.15), rgba(255,255,255,.04));
            border-color: rgba(255,255,255,.18); color: #FBF9F4;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.14), 0 10px 22px -12px rgba(0,0,0,.55);
        }
        .pnl-sec .pnl-link:active { transform: translateX(3px) scale(.98); }
        .pnl-sec .pnl-link .pnl-ic { transition: transform .45s cubic-bezier(.2,.9,.25,1.3), color .2s ease; }
        .pnl-sec .pnl-link:hover .pnl-ic { transform: scale(1.14) rotate(-6deg); }
        /* Activas: vidrio teñido con el color de su sección */
        .pnl-sec-centro .pnl-link.active { background: linear-gradient(135deg, rgba(217,119,87,.32), rgba(217,119,87,.12)); border-color: rgba(229,154,128,.38); box-shadow: inset 0 1px 0 rgba(255,255,255,.14), 0 10px 24px -12px rgba(217,119,87,.55); }
        .pnl-sec-facturacion .pnl-link.active { background: linear-gradient(135deg, rgba(139,158,143,.38), rgba(139,158,143,.14)); border-color: rgba(169,186,173,.4); box-shadow: inset 3px 0 0 #A9BAAD, inset 0 1px 0 rgba(255,255,255,.12), 0 10px 24px -12px rgba(109,128,114,.6); }
        .pnl-sec-facturacion .pnl-link:hover:not(.active) { background: linear-gradient(135deg, rgba(139,158,143,.24), rgba(139,158,143,.06)); border-color: rgba(169,186,173,.3); }
        .pnl-sec-admin .pnl-link.active { background: linear-gradient(135deg, rgba(251,249,244,.95), rgba(234,227,211,.82)); border-color: rgba(255,255,255,.6); color: #3D2A21; box-shadow: inset 0 1px 0 #fff, 0 10px 24px -12px rgba(0,0,0,.5); }
        .pnl-sec-admin .pnl-link.active:hover { color: #3D2A21; }
        /* Píldora de vidrio que se desliza entre opciones */
        .pnl-glide {
            position: absolute; left: 0; right: 0; top: 0; height: 0; z-index: 0; pointer-events: none; opacity: 0; border-radius: .8rem;
            background: linear-gradient(135deg, rgba(255,255,255,.12), rgba(255,255,255,.03));
            border: 1px solid rgba(255,255,255,.14);
            box-shadow: 0 0 0 4px rgba(255,255,255,.03), 0 12px 26px -14px rgba(0,0,0,.6);
            transition: transform .42s cubic-bezier(.2,.9,.25,1.12), height .42s cubic-bezier(.2,.9,.25,1.12), opacity .25s ease;
        }
        .pnl-sec-centro .pnl-glide { box-shadow: 0 0 0 4px rgba(217,119,87,.08), 0 12px 26px -14px rgba(217,119,87,.5); }
        .pnl-sec-facturacion .pnl-glide { box-shadow: 0 0 0 4px rgba(139,158,143,.1), 0 12px 26px -14px rgba(109,128,114,.6); }
        @media (prefers-reduced-motion: reduce) {
            .pnl-sec .pnl-link, .pnl-sec .pnl-link .pnl-ic, .pnl-glide, .pnl-sec .pnl-link::after { transition: none !important; }
            .pnl-sec .pnl-link:hover, .pnl-sec .pnl-link:hover .pnl-ic { transform: none; }
        }
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-thumb { background: rgba(92,64,51,.25); border-radius: 9999px; }

        /* ===== Tablas del sistema (diseño único) =====
           Contenedor: .ez-tabla-card · Tabla: .ez-tabla
           Franja de color por fila con --acc (por defecto salvia). */
        .ez-tabla-card { background: #fff; border: 1px solid rgba(92,64,51,.15); border-radius: 1rem; box-shadow: 0 10px 30px -12px rgba(92,64,51,.18); overflow: hidden; }
        .ez-tabla { width: 100%; text-align: left; border-collapse: collapse; }
        .ez-tabla thead tr { background: rgba(92,64,51,.06); border-bottom: 2px solid rgba(92,64,51,.12); }
        .ez-tabla thead th { color: rgba(92,64,51,.68); font-family: var(--font-body); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding-top: .85rem; padding-bottom: .85rem; white-space: nowrap; }
        .ez-tabla tbody tr { --acc: #8B9E8F; border-bottom: 1px solid rgba(92,64,51,.10); transition: background-color .15s ease; }
        .ez-tabla tbody tr:last-child { border-bottom: 0; }
        .ez-tabla tbody tr:nth-child(even of :not(.hidden)) { background-color: rgba(245,241,232,.78); }
        .ez-tabla tbody tr:hover { background-color: rgba(217,119,87,.07); }
        .ez-tabla tbody tr > td:first-child:not([colspan]) { box-shadow: inset 4px 0 0 var(--acc); }
        .ez-tabla tbody tr.acc-terracota { --acc: rgba(217,119,87,.75); }
        .ez-tabla tbody tr.acc-cafe { --acc: rgba(92,64,51,.35); }
        .ez-tabla tbody tr.acc-off { --acc: rgba(92,64,51,.15); }
        /* Botón de acción principal de una fila (p. ej. «Ver ficha»). */
        .ez-row-btn { display: inline-flex; align-items: center; gap: .35rem; white-space: nowrap; border-radius: 9999px; border: 1.5px solid rgba(217,119,87,.45); background: rgba(217,119,87,.10); color: #B85C3E; padding: .4rem .85rem; font-family: var(--font-body); font-size: .78rem; font-weight: 700; transition: all .15s ease; }
        .ez-row-btn:hover { background: #D97757; border-color: #D97757; color: #FBF9F4; box-shadow: 0 6px 16px -8px rgba(217,119,87,.8); }
        .ez-row-btn svg { width: 14px; height: 14px; }
    </style>
    <?php foreach ($panelCss ?? [] as $css): ?><link rel="stylesheet" href="<?= e(asset($css)) ?>?v=<?= (int) @filemtime(ASSETS_DIR . '/' . $css) ?>"><?php endforeach; ?>
    <style>
        /* Accesibilidad global del panel (WCAG 2.2): foco siempre visible y enlace para saltar al contenido. */
        .el-saltar { position: absolute; left: .75rem; top: -100px; z-index: 100; background: #3D2A21; color: #fff; padding: .75rem 1rem; border-radius: .75rem; font-weight: 600; }
        .el-saltar:focus { top: .75rem; }
        :where(a, button, input, select, textarea, summary, [tabindex]):focus-visible { outline: 3px solid #1F5FBF; outline-offset: 2px; }
        .pnl-sec-analisis .pnl-sec-ic { background: rgba(245,241,232,.18); color: #FBF9F4; }
        .pnl-sec-analisis .pnl-sec-tit { color: #FBF9F4; }
        .pnl-sec-analisis .pnl-sec-n { background: rgba(245,241,232,.16); color: #FBF9F4; }
        .pnl-sec-analisis { background: rgba(217,119,87,.14); border: 1px solid rgba(229,154,128,.3); border-radius: 1.1rem; padding: .6rem .4rem .35rem; }
        .pnl-demo { background: #FFF4D6; color: #5A3E00; border-bottom: 1px solid #E0B44C; font-size: .8rem; font-weight: 600; text-align: center; padding: .35rem 1rem; }
    </style>
    <?php include APP_ROOT . '/partials/ez-dialog.php'; ?>
</head>
<body class="min-h-screen">
<a class="el-saltar" href="#contenido">Saltar al contenido</a>
<?php if ($panelEmbed): ?>
<div><div>
<script>
(function () {
    function avisar(m) { try { if (window.parent !== window) window.parent.postMessage(m, location.origin); } catch (e) {} }
    window.addEventListener('load', function () { avisar({ ezDoc: 'cargado', titulo: document.title.replace(/\s+—\s+Espacio Luz$/, '') }); });
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || a.target === '_blank' || a.hasAttribute('download')) return;
        var u; try { u = new URL(a.href, location.href); } catch (x) { return; }
        if (u.origin !== location.origin) { a.target = '_blank'; return; }
        if (/\/admin\/documentos\/?$/.test(u.pathname)) { e.preventDefault(); avisar({ ezDoc: 'cerrar' }); return; }
        if (/\/admin\/(boletas\/\d+|notas\/(nc|nd)\/\d+)$/.test(u.pathname)) { u.searchParams.set('embed', '1'); a.href = u.toString(); return; }
        if (/\/admin\/dte\/pdf$/.test(u.pathname)) return;
        a.target = '_top';
    }, true);
})();
</script>
        <main id="contenido" tabindex="-1" class="px-3 py-4 sm:px-6 sm:py-5">
<?php else: ?>
<div class="flex min-h-screen">
    <!-- Barra lateral -->
    <aside id="pnl-sidebar" class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full flex-col bg-cafe text-crema transition-transform duration-300 lg:translate-x-0 lg:flex">
        <div class="flex h-16 shrink-0 items-center gap-3 px-5">
            <img src="<?= e(asset('images/logo/logo.png')) ?>" alt="Espacio Luz" class="h-11 w-11 rounded-full object-cover">
            <div class="leading-tight">
                <p class="font-heading text-lg font-semibold leading-tight text-crema">Espacio <span class="text-terracota-light">Luz</span></p>
                <p class="font-body text-[0.6rem] font-semibold uppercase tracking-[0.2em] text-crema/70">Panel</p>
            </div>
        </div>
        <?php $secIcon = [
            'analisis'    => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
            'centro'      => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-5h4v5"/>',
            'facturacion' => '<path d="M6 2h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/><path d="M9 13h6M9 17h4"/>',
            'admin'       => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        ]; ?>
        <nav class="pnl-nav min-h-0 flex-1 space-y-3 overflow-y-auto px-3 pb-3 pt-1" aria-label="Módulos del panel">
            <?php foreach ($navSecciones as $secKey => $sec): if (empty($navPorSeccion[$secKey])) continue; $items = $navPorSeccion[$secKey]; ?>
                <section class="pnl-sec pnl-sec-<?= e($secKey) ?>" aria-labelledby="pnl-sec-<?= e($secKey) ?>">
                    <div class="pnl-sec-head">
                        <span class="pnl-sec-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $secIcon[$secKey] ?></svg></span>
                        <p id="pnl-sec-<?= e($secKey) ?>" class="pnl-sec-tit" title="<?= e($sec['sub']) ?>"><?= e($sec['titulo']) ?></p>
                        <span class="pnl-sec-n"><?= count($items) ?></span>
                    </div>
                    <div class="pnl-items">
                        <span class="pnl-glide" aria-hidden="true"></span>
                        <?php foreach ($items as $item): ?>
                            <a href="<?= e(base_path($item['href'])) ?>"<?= $panelActive === $item['key'] ? ' aria-current="page"' : '' ?>
                               class="pnl-link flex items-center gap-3 rounded-xl px-3 py-[0.4rem] font-body text-sm font-semibold text-crema/80 <?= $panelActive === $item['key'] ? 'active' : '' ?>">
                                <svg class="pnl-ic h-5 w-5 shrink-0 text-crema/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><?= $item['icon'] ?></svg>
                                <span class="min-w-0 leading-snug"><?= e($item['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </nav>
        <div class="shrink-0 border-t border-crema/10 p-2">
            <a href="<?= e(base_path('/logout')) ?>" class="flex items-center justify-center gap-2 whitespace-nowrap rounded-xl px-2 py-2 font-body text-xs font-semibold text-crema/70 transition-colors hover:bg-terracota/20 hover:text-crema">
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                Cerrar sesión
            </a>
        </div>
        <script>
        /* Píldora de vidrio que sigue al puntero entre las opciones de cada sección. */
        (function () {
            document.querySelectorAll('#pnl-sidebar .pnl-items').forEach(function (box) {
                var glide = box.querySelector('.pnl-glide');
                if (!glide) return;
                var mover = function (a) {
                    glide.style.transform = 'translateY(' + a.offsetTop + 'px)';
                    glide.style.height = a.offsetHeight + 'px';
                    glide.style.opacity = a.classList.contains('active') ? '0' : '1';
                };
                box.querySelectorAll('.pnl-link').forEach(function (a) {
                    a.addEventListener('mouseenter', function () { mover(a); });
                    a.addEventListener('focus', function () { mover(a); });
                });
                box.addEventListener('mouseleave', function () { glide.style.opacity = '0'; });
                box.addEventListener('focusout', function (ev) { if (!box.contains(ev.relatedTarget)) glide.style.opacity = '0'; });
            });
        })();
        </script>
    </aside>

    <!-- Fondo oscuro para el menú móvil -->
    <div id="pnl-overlay" class="fixed inset-0 z-30 hidden bg-cafe/50 backdrop-blur-sm lg:hidden"></div>

    <!-- Contenido -->
    <div class="flex min-w-0 flex-1 flex-col lg:pl-64">
        <header class="sticky top-0 z-20 flex h-16 items-center justify-between gap-4 border-b border-cafe/10 bg-crema-light/90 px-4 backdrop-blur sm:px-8">
            <div class="flex items-center gap-3">
                <button type="button" id="pnl-menu" class="flex h-11 w-11 items-center justify-center rounded-lg text-cafe hover:bg-cafe/5 lg:hidden" aria-label="Abrir menú" aria-controls="pnl-sidebar" aria-expanded="false">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <h1 class="font-heading text-xl font-semibold text-cafe sm:text-2xl"><?= e($panelTitle) ?></h1>
            </div>
            <div class="flex items-center gap-3">
                <div class="hidden text-right sm:block">
                    <p class="font-body text-sm font-semibold text-cafe"><?= e($nombre) ?></p>
                    <p class="font-body text-xs text-cafe/70"><?= e(rolEtiqueta($rol)) ?></p>
                </div>
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-terracota/15 font-body text-sm font-bold text-terracota-dark">
                    <?= e(getIniciales($nombre)) ?>
                </div>
            </div>
        </header>
        <p class="pnl-demo" role="note">Espacio Luz <strong>Demo</strong> · datos sintéticos · boletas y facturas de demostración, nunca enviadas al SII</p>
        <main id="contenido" tabindex="-1" class="flex-1 px-4 py-6 sm:px-8 sm:py-8">
<?php endif; ?>
