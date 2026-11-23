<?php
/**
 * Panel · Ficha del cliente (igual que la ficha del paciente): cabecera, indicadores,
 * datos agrupados por tema, notas internas, historial de horas en boxes y facturas.
 * Sin montos: los totales de dinero los lleva el módulo Boletas y Facturas.
 * El router define $clienteId.
 */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/clientes.php';
require_once APP_ROOT . '/lib/boletas.php'; // medioPagoLabel()

$cliente = getCliente((int) ($clienteId ?? 0));
if (!$cliente) {
    http_response_code(404);
    $panelTitle = 'Cliente no encontrado';
    $panelActive = 'clientes';
    include APP_ROOT . '/partials/panel/top.php';
    echo '<div class="rounded-2xl border border-cafe/10 bg-crema-light p-8 text-center font-body text-cafe/60">Ese cliente no existe. <a href="' . e(base_path('/admin/clientes')) . '" class="text-terracota-dark">Volver</a></div>';
    include APP_ROOT . '/partials/panel/bottom.php';
    return;
}

$cid = (int) $cliente['id'];
$esAdmin = isAdmin();
$activo = (int) $cliente['activo'] === 1;
$falt = clienteFaltantes($cliente);
$resumen = clienteResumen($cliente);
$reservas = clienteReservas($cliente, 60);
$facturas = facturasCliente($cid);
$box = !empty($cliente['box_slug']) ? boxFacturable((string) $cliente['box_slug']) : null;
$tarifaTxt = '';
if ($box && !empty($cliente['tarifa'])) {
    $tarifaTxt = (string) $cliente['tarifa'];
    foreach ($box['tarifas'] as $t) {
        if ($t['key'] === $cliente['tarifa']) {
            $tarifaTxt = $t['key'] . ' — ' . clp($t['precio']);
        }
    }
}
$urlFacturar = base_path('/admin/facturas/nueva?cliente=' . $cid);
$puedeFacturar = $esAdmin && $activo && !$falt;
$estados = ['pendiente' => 'bg-amber-100 text-amber-700', 'confirmada' => 'bg-salvia/20 text-salvia-dark', 'realizada' => 'bg-green-100 text-green-700', 'cancelada' => 'bg-red-100 text-red-700', 'no_show' => 'bg-cafe/15 text-cafe'];

$panelTitle = $cliente['razon_social'];
$panelActive = 'clientes';
include APP_ROOT . '/partials/panel/top.php';

$txt = static fn($v): string => trim((string) ($v ?? ''));
$fF = static function (?string $v, bool $hora = false): string {
    if (!$v || str_starts_with($v, '0000')) return '';
    try { return (new DateTimeImmutable($v))->format($hora ? 'd-m-Y · H:i' : 'd-m-Y'); } catch (Throwable $e) { return ''; }
};
$chip = static fn(string $t, string $c): string => '<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ' . $c . '">' . $t . '</span>';
$falta = '<span class="font-semibold text-amber-700">Falta</span>';
$tel = $txt($cliente['telefono']);
$mail = $txt($cliente['email']);
$secciones = [
    ['Identificación tributaria', 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M9 13h6M9 17h4', [
        ['Razón social', e($txt($cliente['razon_social'])) ?: $falta],
        ['RUT', e($txt($cliente['rut'])) ?: $falta],
        ['Nombre de fantasía', e($txt($cliente['nombre_fantasia']))],
        ['N° de cliente', (string) $cid],
        ['Estado', $activo ? $chip('Activo', 'bg-salvia/20 text-salvia-dark') : $chip('Inactivo', 'bg-cafe/10 text-cafe/70')],
    ]],
    ['Actividad y facturación', 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6', [
        ['Giro', e($txt($cliente['giro'])) ?: $falta],
        ['Medio de pago habitual', e(CLIENTE_MEDIOS[$cliente['medio_pago']] ?? (string) $cliente['medio_pago'])],
        ['Listo para facturar', $falt ? $chip('Falta: ' . e(implode(', ', $falt)), 'bg-amber-100 text-amber-800') : $chip('✓ Datos completos', 'bg-green-100 text-green-700')],
    ]],
    ['Dirección tributaria', 'M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0zM12 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6z', [
        ['Dirección', e($txt($cliente['direccion'])) ?: $falta],
        ['Comuna', e($txt($cliente['comuna'])) ?: $falta],
        ['Región', e($txt($cliente['region']))],
        ['Ciudad', e($txt($cliente['ciudad']))],
    ]],
    ['Contacto', 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM22 6l-10 7L2 6', [
        ['Correo para facturas', $mail !== '' ? '<a class="break-all hover:text-terracota" href="mailto:' . e($mail) . '">' . e($mail) . '</a>' : ''],
        ['Teléfono', $tel !== '' ? '<a class="hover:text-terracota" href="tel:' . e(preg_replace('/[^\d+]/', '', $tel)) . '">' . e($tel) . '</a>' : ''],
        ['Persona de contacto', e($txt($cliente['contacto']))],
    ]],
    ['Arriendo de boxes', 'M3 4h18v17H3zM3 9h18M8 2v4M16 2v4', [
        [count($cliente['prof_ids']) > 1 ? 'Profesionales vinculados' : 'Profesional vinculado', e($txt($cliente['profesionales_nombres'] ?? '')) ?: '<span class="text-cafe/45">Sin vincular (no trae horas del calendario)</span>'],
        ['Box habitual', e($box['nombre'] ?? '')],
        ['Tarifa habitual', e($tarifaTxt)],
    ]],
    ['Registro', 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z', [
        ['Registrado el', e($fF($cliente['creado_en'] ?? null, true))],
        ['Última actualización', e($fF($cliente['actualizado_en'] ?? null, true))],
    ]],
];
?>
<a href="<?= e(base_path('/admin/clientes')) ?>" class="mb-4 inline-flex items-center gap-1.5 font-body text-sm font-semibold text-cafe/60 hover:text-terracota">← Volver a clientes</a>

<!-- Cabecera del cliente -->
<div class="rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-4">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-terracota/15 font-heading text-xl font-bold text-terracota-dark"><?= e(getIniciales($cliente['razon_social'])) ?></div>
            <div class="min-w-0">
                <h2 class="font-heading text-2xl font-semibold text-cafe"><?= e($cliente['razon_social']) ?></h2>
                <p class="mt-1 font-body text-sm text-cafe/60">
                    <?= e($cliente['rut']) ?><?= $cliente['nombre_fantasia'] ? ' · ' . e($cliente['nombre_fantasia']) : '' ?>
                    <?= $tel !== '' ? ' · ' . e($tel) : '' ?><?= $mail !== '' ? ' · ' . e($mail) : '' ?>
                </p>
                <p class="mt-1 font-body text-xs">
                    <?= $falt ? '<span class="text-amber-700">⚠ Para facturarle falta: ' . e(implode(', ', $falt)) . '</span>' : '<span class="text-green-700">✓ Datos completos para facturar</span>' ?>
                    <?= !$activo ? ' · <span class="text-cafe/55">Cliente inactivo</span>' : '' ?>
                </p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" id="cv-editar" class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota hover:text-terracota">Editar datos</button>
            <?php if ($puedeFacturar): ?>
                <a href="<?= e($urlFacturar) ?>" class="inline-flex items-center gap-1.5 rounded-full bg-terracota px-4 py-2 font-body text-sm font-semibold text-crema-light shadow-card hover:bg-terracota-dark">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg> Emitir factura
                </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <?php
        $kpis = [
            ['Horas este mes', $cliente['prof_ids'] ? (string) $resumen['horas_mes'] : '—'],
            ['Por facturar', $cliente['prof_ids'] ? $resumen['por_facturar'] . ' h' : '—'],
            ['Facturas emitidas', $resumen['facturas'] . ($resumen['por_cobrar'] ? ' · ' . $resumen['por_cobrar'] . ' por cobrar' : '')],
            ['Próxima hora', $resumen['proxima'] ? date('d-m H:i', strtotime($resumen['proxima'])) : '—'],
        ];
        foreach ($kpis as $k): ?>
            <div class="rounded-xl bg-crema/50 px-4 py-3"><div class="font-body text-[11px] font-semibold uppercase tracking-wide text-cafe/50"><?= e($k[0]) ?></div><div class="mt-0.5 font-heading text-lg font-semibold text-cafe"><?= e($k[1]) ?></div></div>
        <?php endforeach; ?>
    </div>

    <div class="mt-5 border-t border-cafe/8 pt-5">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-heading text-lg font-semibold text-cafe">Datos del cliente</h3>
            <span class="font-body text-xs text-cafe/45">Para modificarlos usa «Editar datos»</span>
        </div>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($secciones as [$titulo, $icono, $campos]): ?>
                <section class="min-w-0 rounded-xl border border-cafe/10 bg-white/70 p-4">
                    <h4 class="mb-3 flex items-center gap-2 font-body text-xs font-bold uppercase tracking-wider text-terracota-dark">
                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $icono ?>"/></svg><?= e($titulo) ?>
                    </h4>
                    <dl class="flex flex-col gap-2.5">
                        <?php foreach ($campos as [$etq, $html]): ?>
                            <div class="grid grid-cols-[minmax(0,9.5rem)_minmax(0,1fr)] items-baseline gap-3">
                                <dt class="font-body text-[11px] font-semibold uppercase tracking-wide text-cafe/45"><?= e($etq) ?></dt>
                                <dd class="min-w-0 break-words font-body text-sm text-cafe/85"><?= $html !== '' ? $html : '<span class="text-cafe/30">—</span>' ?></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                </section>
            <?php endforeach; ?>
            <?php
            $notasCfg = [
                'id' => $cid, 'nombre' => $cliente['razon_social'], 'api' => '/api/panel/clientes', 'campo' => 'cliente_id',
                'titulo' => 'Notas del cliente',
                'ayuda' => 'Notas internas (acuerdos, horarios, pagos, avisos). Se guardan solas mientras escribes y no aparecen en las facturas.',
                'vacio' => 'Este cliente aún no tiene notas. Escribe la primera arriba.',
                'notas' => notasCliente($cid),
            ];
            include APP_ROOT . '/partials/panel/paciente-notas.php';
            ?>
        </div>
    </div>
</div>

<!-- Historial de horas + facturas -->
<div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
    <div class="ez-tabla-card">
        <h3 class="px-5 pt-4 font-heading text-lg font-semibold text-cafe">Historial de horas en boxes (<?= count($reservas) ?>)</h3>
        <p class="px-5 pb-3 font-body text-xs text-cafe/50"><?= $cliente['prof_ids'] ? 'Horas que tomó ' . e($cliente['profesionales_nombres']) . ' en el Calendario de Boxes, con su estado de cobro.' : 'Vincula al cliente con sus profesionales para ver sus horas.' ?></p>
        <div class="max-h-[560px] overflow-auto">
            <table class="ez-tabla font-body text-sm">
                <thead class="sticky top-0 z-[1] bg-[#F1ECE4]"><tr><th class="px-4 py-2.5">Fecha</th><th class="px-4 py-2.5">Box<?= count($cliente['prof_ids']) > 1 ? ' · profesional' : '' ?></th><th class="px-4 py-2.5 text-center">Estado</th><th class="px-4 py-2.5 text-right">Cobro</th></tr></thead>
                <tbody class="text-cafe/80">
                    <?php if (!$reservas): ?><tr><td colspan="4" class="px-4 py-8 text-center text-cafe/40">Sin horas registradas.</td></tr>
                    <?php else: foreach ($reservas as $r): ?>
                        <tr>
                            <td class="px-4 py-2.5 font-semibold text-cafe whitespace-nowrap"><?= e(date('d-m-Y', strtotime($r['fecha']))) ?><?= $r['hhmm'] ? ' ' . e($r['hhmm']) : '' ?></td>
                            <td class="px-4 py-2.5"><?= e(boxFacturable((string) $r['box_slug'])['nombre'] ?? (string) $r['box_slug']) ?><?php if (count($cliente['prof_ids']) > 1): ?><div class="text-[11px] text-cafe/50"><?= e($r['profesional']) ?></div><?php endif; ?></td>
                            <td class="px-4 py-2.5 text-center"><span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $estados[$r['estado']] ?? 'bg-cafe/10' ?>"><?= e($r['estado'] === 'no_show' ? 'no asistió' : $r['estado']) ?></span></td>
                            <td class="px-4 py-2.5 text-right">
                                <?php $ca = $r['cobro']['arriendo'] ?? null; if ($ca): ?>
                                    <?php if ($ca['doc_id']): ?><a href="<?= e(base_path('/admin/boletas/' . $ca['doc_id'])) ?>" class="inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold <?= $ca['clase'] ?> hover:opacity-80"><?= e($ca['texto']) ?></a>
                                    <?php else: ?><span class="inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold <?= $ca['clase'] ?>"><?= e($ca['texto']) ?></span><?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="ez-tabla-card">
        <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-4">
            <h3 class="font-heading text-lg font-semibold text-cafe">Facturas (<?= count($facturas) ?>)</h3>
            <div class="flex items-center gap-3">
                <?php if ($facturas && !esRolPacientes()): ?><a href="<?= e(base_path('/admin/documentos?cliente=' . $cid)) ?>" class="font-body text-xs font-semibold text-cafe/60 hover:text-terracota">Ver en Documentos →</a><?php endif; ?>
                <?php if ($puedeFacturar): ?><a href="<?= e($urlFacturar) ?>" class="font-body text-xs font-semibold text-terracota-dark hover:text-terracota">+ Emitir factura</a><?php endif; ?>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="ez-tabla font-body text-sm">
                <thead><tr><th class="px-4 py-2.5">Folio</th><th class="px-4 py-2.5">Fecha</th><th class="px-4 py-2.5">Pago</th><th class="px-4 py-2.5 text-center">Estado</th></tr></thead>
                <tbody class="text-cafe/80">
                    <?php if (!$facturas): ?><tr><td colspan="4" class="px-4 py-8 text-center text-cafe/40">Sin facturas emitidas.</td></tr>
                    <?php else: foreach ($facturas as $f):
                        $sii = $f['sii_estado'] ?? 'no_enviada'; ?>
                        <tr class="cursor-pointer" onclick="location.href='<?= e(base_path('/admin/boletas/' . $f['id'])) ?>'">
                            <td class="px-4 py-2.5 font-semibold text-cafe whitespace-nowrap">N° <?= e(str_pad((string) $f['folio'], 6, '0', STR_PAD_LEFT)) ?><?php if ((int) $f['horas_box'] > 0): ?><div class="text-[11px] font-normal text-cafe/50"><?= (int) $f['horas_box'] ?> h del calendario</div><?php endif; ?></td>
                            <td class="px-4 py-2.5 whitespace-nowrap"><?= e(date('d-m-Y', strtotime($f['fecha_emision']))) ?></td>
                            <td class="px-4 py-2.5"><?= e(medioPagoLabel((string) $f['medio_pago'])) ?></td>
                            <td class="px-4 py-2.5 text-center whitespace-nowrap">
                                <?php if ($f['estado'] === 'anulada'): ?><span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">Anulada</span>
                                <?php elseif ($sii === 'no_enviada'): ?><span class="rounded-full bg-cafe/8 px-2 py-0.5 text-xs font-semibold text-cafe/55" title="Documento interno, aún no enviado al SII">Interna</span>
                                <?php else: ?><span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $sii === 'aceptada' ? 'bg-emerald-100 text-emerald-700' : ($sii === 'rechazada' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') ?>">SII <?= e($sii) ?></span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include APP_ROOT . '/partials/panel/cliente-form.php'; ?>
<script>
(function () {
    var CLI = <?= json_encode($cliente, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    document.getElementById('cv-editar').addEventListener('click', function () { window.ClienteForm.abrir(CLI); });
    if (location.hash === '#editar') window.ClienteForm.abrir(CLI);
})();
</script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
