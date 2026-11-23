<?php
/**
 * Cabecera común del documento. Replica app/layout.tsx + tailwind.config.ts +
 * app/globals.css usando Tailwind vía CDN con la misma configuración de tema.
 *
 * Variables esperadas: $pageTitle, $pageDescription (opcional),
 * $bodyClass (opcional), $isAuth (bool).
 */
declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Espacio Luz | Centro de Terapias y Bienestar';
$pageDescription = $pageDescription ?? 'Centro integral de terapias y bienestar en Santiago. Fonoaudiología, Terapia Ocupacional y Psicología en un espacio cálido pensado para ti.';
$bodyClass = $bodyClass ?? 'font-body bg-crema text-cafe antialiased';
$isAuth = $isAuth ?? puedeEditarSitio(); // el rol Pacientes no ve el modo edición
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= attr($pageDescription) ?>">
    <link rel="icon" href="<?= e(asset('images/logo/logo.png')) ?>">

    <!-- Fuentes seleccionables (panel → Apariencia): carga la elección guardada. -->
    <?php require_once APP_ROOT . '/partials/fonts.php'; echo ezFontsHead(); ?>

    <!-- Tailwind (CDN) con el mismo tema del proyecto original -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                container: {
                    center: true,
                    padding: { DEFAULT: "1.25rem", sm: "2rem", lg: "3rem", xl: "4rem" },
                },
                extend: {
                    colors: {
                        crema: { DEFAULT: "#F5F1E8", light: "#FBF9F4", dark: "#EAE3D3" },
                        salvia: { DEFAULT: "#8B9E8F", light: "#A9BAAD", dark: "#6D8072" },
                        terracota: { DEFAULT: "#D97757", light: "#E59A80", dark: "#B85C3E" },
                        cafe: { DEFAULT: "#5C4033", light: "#7A5A47", dark: "#3D2A21" },
                    },
                    fontFamily: {
                        heading: ["var(--font-heading)"],
                        body: ["var(--font-body)"],
                        script: ["var(--font-script)"],
                    },
                    boxShadow: {
                        warm: "0 20px 45px -15px rgba(92, 64, 51, 0.25)",
                        card: "0 10px 30px -10px rgba(92, 64, 51, 0.18)",
                    },
                    backgroundImage: {
                        "warm-gradient": "linear-gradient(135deg, #F5F1E8 0%, #F0E9DA 50%, #E8DCC8 100%)",
                    },
                    animation: {
                        "fade-up": "fadeUp 0.7s ease-out forwards",
                        float: "float 6s ease-in-out infinite",
                    },
                    keyframes: {
                        fadeUp: {
                            "0%": { opacity: "0", transform: "translateY(16px)" },
                            "100%": { opacity: "1", transform: "translateY(0)" },
                        },
                        float: {
                            "0%, 100%": { transform: "translateY(0)" },
                            "50%": { transform: "translateY(-10px)" },
                        },
                    },
                },
            },
        };
    </script>

    <!-- MapLibre (mapa de ubicación) -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/maplibre-gl/4.7.1/maplibre-gl.min.css" rel="stylesheet">

    <style>
        :root {
            /* --font-heading/body/script los define ezFontsHead() según lo elegido */
            --background: #f5f1e8;
            --foreground: #5c4033;
        }
        body { color: var(--foreground); background: var(--background); }
        section[id], footer[id] { scroll-margin-top: 5.5rem; }
        .text-balance { text-wrap: balance; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .bg-noise {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.035'/%3E%3C/svg%3E");
        }
        .maplibregl-cooperative-gesture-screen {
            background-color: rgba(92, 64, 51, 0.55) !important;
            font-family: var(--font-body), sans-serif !important;
        }
        .maplibregl-cooperative-gesture-screen .maplibregl-desktop-message,
        .maplibregl-cooperative-gesture-screen .maplibregl-mobile-message {
            background-color: #5c4033 !important;
            border-radius: 9999px !important;
            padding: 0.6rem 1.25rem !important;
            font-size: 0.9rem !important;
        }
        /* Edición inline (panel admin).
           Se edita SOBRE el propio elemento con contenteditable, así conserva
           exactamente su color, fuente y tamaño (idéntico a la página). Solo se
           añade un borde punteado para indicar qué es editable. */
        .ez-editing .ez-editable {
            cursor: text;
            outline: 2px dashed rgba(217,119,87,.55);
            outline-offset: 3px;
            border-radius: 4px;
            transition: outline-color .15s ease;
        }
        .ez-editing .ez-editable:hover { outline-color: rgba(217,119,87,.9); }
        .ez-editing .ez-editable[contenteditable="true"] {
            outline: 2px solid #D97757;
            outline-offset: 3px;
            caret-color: #D97757;
        }
        /* Etiqueta flotante "Guardando/Guardado" sin afectar el texto editado. */
        .ez-save-badge {
            position: absolute; top: -0.75rem; right: 0.25rem; z-index: 40;
            border-radius: 9999px; padding: 0.1rem 0.5rem;
            font-family: var(--font-body), sans-serif; font-size: 10px; font-weight: 600;
            box-shadow: 0 4px 10px -4px rgba(92,64,51,.4); pointer-events: none; white-space: nowrap;
        }
    </style>
    <?= $extraHead ?? '' ?>
</head>
<body class="<?= attr($bodyClass) ?> flex min-h-screen flex-col">
<script>
    window.__EZ__ = {
        base: <?= json_encode(BASE_URL) ?>,
        auth: <?= $isAuth ? 'true' : 'false' ?>,
        admin: <?= (function_exists('isAdmin') && isAdmin()) ? 'true' : 'false' ?>,
        csrf: <?= json_encode($isAuth && function_exists('csrf_token') ? csrf_token() : '') ?>
    };
</script>
