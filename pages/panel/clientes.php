<?php
/**
 * Panel · Clientes (quienes arriendan boxes). Lista + búsqueda, igual que Pacientes.
 * Sin montos: los totales de facturas los lleva el módulo Boletas y Facturas.
 */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/clientes.php';

$q = trim((string) ($_GET['q'] ?? ''));
$verInactivos = ($_GET['inactivos'] ?? '') === '1';
$clientes = clientesLista($q, $verInactivos);
$pendCli = horasSinFacturarPorCliente();
$esAdmin = isAdmin();
$boxNom = [];
foreach (boxesFacturables() as $b) {
    $boxNom[$b['slug']] = $b['nombre'];
}
$urlLista = base_path('/admin/clientes');

$panelTitle = 'Clientes';
$panelActive = 'clientes';
include APP_ROOT . '/partials/panel/top.php';
?>
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <form method="get" action="<?= e($urlLista) ?>" class="flex flex-wrap items-center gap-2">
        <input name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre, RUT, giro o profesional…" class="w-72 max-w-full rounded-full border border-cafe/20 bg-crema-light px-4 py-2.5 font-body text-sm text-cafe outline-none focus:border-terracota">
        <?php if ($verInactivos): ?><input type="hidden" name="inactivos" value="1"><?php endif; ?>
        <button class="rounded-full bg-cafe px-4 py-2.5 font-body text-sm font-semibold text-crema-light hover:bg-cafe-light">Buscar</button>
        <?php if ($q !== ''): ?><a href="<?= e($urlLista . ($verInactivos ? '?inactivos=1' : '')) ?>" class="px-2 font-body text-xs font-semibold text-cafe/55 hover:text-terracota">Limpiar ✕</a><?php endif; ?>
    </form>
    <div class="flex flex-wrap items-center gap-2">
        <?php if ($esAdmin): ?>
        <a href="<?= e(base_path('/admin/facturas/nueva')) ?>" class="inline-flex items-center gap-2 rounded-full border-2 border-terracota/45 px-4 py-2 font-body text-sm font-semibold text-terracota-dark transition-colors hover:bg-terracota/10">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/><path d="M14 2v6h6M9 13h6M9 17h4"/></svg>
            Emitir factura
        </a>
        <?php endif; ?>
        <button type="button" id="cl-nuevo" class="inline-flex items-center gap-2 rounded-full bg-terracota px-5 py-2.5 font-body text-sm font-semibold text-crema-light shadow-card hover:bg-terracota-dark">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg> Nuevo cliente
        </button>
    </div>
</div>

<div class="mb-2 flex flex-wrap items-center justify-between gap-3 font-body text-xs text-cafe/55">
    <div class="flex flex-wrap items-center gap-4">
        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-1 rounded-full bg-salvia"></span>Cliente</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-1 rounded-full bg-terracota/75"></span>Con horas de box por facturar</span>
        <span><?= count($clientes) ?> <?= count($clientes) === 1 ? 'cliente' : 'clientes' ?><?= $verInactivos ? ' (incluye inactivos)' : '' ?></span>
    </div>
    <a href="<?= e($urlLista . '?' . http_build_query(array_filter(['q' => $q, 'inactivos' => $verInactivos ? '' : '1']))) ?>" class="font-semibold text-terracota-dark hover:underline"><?= $verInactivos ? 'Ocultar inactivos' : 'Ver también inactivos' ?></a>
</div>
<div class="ez-tabla-card">
    <div class="overflow-x-auto">
        <table class="ez-tabla">
            <thead><tr>
                <th class="px-4">Cliente</th><th class="px-4">RUT</th><th class="px-4">Teléfono</th><th class="px-4">Giro</th><th class="px-4">Dirección</th><th class="px-4">Profesional · box</th><th class="px-4"></th>
            </tr></thead>
            <tbody class="font-body text-sm text-cafe/85">
                <?php if (!$clientes): ?>
                    <tr><td colspan="7" class="px-5 py-12 text-center text-cafe/45"><?= $q !== '' ? 'Sin resultados para «' . e($q) . '».' : 'Aún no hay clientes. Crea el primero con «Nuevo cliente».' ?></td></tr>
                <?php else: foreach ($clientes as $c):
                    $url = base_path('/admin/clientes/' . $c['id']);
                    $activo = (int) $c['activo'] === 1;
                    $horas = (int) ($pendCli[(int) $c['id']] ?? 0);
                    $dir = trim(((string) $c['direccion']) . ($c['comuna'] !== '' ? ', ' . $c['comuna'] : ''));
                    $falt = clienteFaltantes($c); ?>
                    <tr class="cursor-pointer <?= !$activo ? 'acc-off opacity-60' : ($horas > 0 ? 'acc-terracota' : '') ?>" onclick="location.href='<?= e($url) ?>'">
                        <td class="min-w-[220px] px-4 py-3">
                            <div class="font-semibold leading-snug text-cafe"><?= e($c['razon_social']) ?></div>
                            <?php if (!empty($c['nombre_fantasia'])): ?><div class="text-xs text-cafe/55"><?= e($c['nombre_fantasia']) ?></div><?php endif; ?>
                            <?php if (!$activo): ?><span class="mt-1 inline-block rounded-full bg-cafe/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-cafe/55">Inactivo</span><?php endif; ?>
                            <?php if ($falt): ?><span class="mt-1 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800" title="<?= e('Falta: ' . implode(', ', $falt)) ?>">Faltan datos</span><?php endif; ?>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap"><?= e($c['rut']) ?></td>
                        <td class="px-4 py-3 whitespace-nowrap"><?= e($c['telefono'] ?: '—') ?></td>
                        <td class="max-w-[220px] px-4 py-3 leading-snug"><?= e($c['giro']) ?></td>
                        <td class="px-4 py-3 leading-snug"><?= e($dir !== '' ? $dir : '—') ?></td>
                        <td class="px-4 py-3 leading-snug">
                            <?= !empty($c['profesionales_nombres']) ? '<div>' . e($c['profesionales_nombres']) . '</div>' : '<span class="text-cafe/35">Sin vincular</span>' ?>
                            <?php if (!empty($c['box_slug'])): ?><div class="text-xs text-cafe/55">Box <?= e($boxNom[$c['box_slug']] ?? $c['box_slug']) ?></div><?php endif; ?>
                            <?php if ($horas > 0): ?><span class="mt-1 inline-block rounded-full bg-terracota/15 px-2 py-0.5 text-[11px] font-bold text-terracota-dark"><?= $horas ?> h por facturar</span><?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="<?= e($url) ?>" class="ez-row-btn" onclick="event.stopPropagation()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/></svg>
                                Ver ficha
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include APP_ROOT . '/partials/panel/cliente-form.php'; ?>

<script>
(function () {
    document.getElementById('cl-nuevo').addEventListener('click', function () { window.ClienteForm.abrir(null); });
    if (location.hash === '#nuevo') window.ClienteForm.abrir(null);
})();
</script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
