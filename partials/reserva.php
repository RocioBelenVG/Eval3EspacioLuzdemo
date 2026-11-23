<?php
/**
 * Widget "Arrienda este espacio". Reemplaza a components/sections/ReservaWidget.tsx.
 * La cabecera es estática; los pasos (modalidad, detalle, pago, confirmado) los
 * construye main.js a partir de los datos embebidos aquí.
 * Espera $box.
 */
declare(strict_types=1);

$reservaData = [
    'slug' => $box['slug'],
    'nombre' => $box['nombre'],
    'tarifas' => array_map(function ($t) {
        return [
            'precio' => $t['precio'],
            'label' => $t['label'],
            'sub' => $t['sub'] ?? null,
            'gastosComunes' => $t['gastosComunes'] ?? null,
        ];
    }, $box['tarifas']),
];
?>
<div class="rounded-2xl bg-crema p-6 shadow-card sm:p-7">
    <h2 class="font-heading text-xl font-semibold text-cafe">Arrienda este espacio</h2>
    <p class="mt-1 font-body text-sm text-cafe/60">
        Reserva <?= e($box['nombre']) ?> en unos pasos. El pago se coordina después.
    </p>

    <div id="ez-reserva"
        data-reserva='<?= htmlspecialchars(json_encode($reservaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>'>
        <!-- main.js construye aquí el flujo de reserva -->
    </div>
</div>
