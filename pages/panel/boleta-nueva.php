<?php
/** Panel · Emitir BOLETA a un paciente. Solo admin. Las facturas son solo para Clientes (/admin/facturas/nueva). */
declare(strict_types=1);

requireAdminPage();
require_once APP_ROOT . '/lib/boletas.php';
require_once APP_ROOT . '/lib/empresas.php';
require_once APP_ROOT . '/lib/productos.php';
require_once APP_ROOT . '/lib/pacientes.php';
require_once APP_ROOT . '/lib/dte_integra.php';

// Solo empresas que emiten boletas: a los pacientes nunca se les factura.
$empresas = array_values(array_filter(empresasActivas(), fn($e) => ($e['tipo_documento'] ?? 'boleta') !== 'factura'));
$prodPorEmpresa = productosPorEmpresa();

// Pacientes activos (para el select) con el receptor YA resuelto por la regla de
// cobro: menor de edad -> tutor/apoderado; mayor de edad -> el propio paciente.
$pacientesJs = [];
try {
    foreach (db()->query("SELECT * FROM pacientes WHERE activo = 1 ORDER BY nombre") as $c) {
        $rc = receptorCobroPaciente($c);
        $edadP = pacienteEdad($c['fecha_nacimiento'] ?? null);
        $pacientesJs[] = [
            'id' => (int) $c['id'],
            'nombre' => $c['nombre'],
            'rut' => $c['rut'] ?? '',
            'etiqueta' => $c['nombre'] . (pacienteDocumento($c) !== '' ? ' — ' . pacienteDocumento($c) : '') . ($edadP !== null ? ' · ' . $edadP . ' años' : ''),
            'rc' => $rc,
        ];
    }
} catch (Throwable $e) {
}

// Las boletas las emiten SOLO las socias (Fernanda y Javiera), con su RUT para autocompletar.
$profesionalesJs = [];
try {
    foreach (db()->query("SELECT id, nombre, rut FROM profesionales WHERE activo = 1 AND eliminado_at IS NULL" . (profesionalesSocias() ? ' AND socia = 1' : '') . " ORDER BY nombre") as $p) {
        $profesionalesJs[] = ['id' => (int) $p['id'], 'nombre' => $p['nombre'], 'rut' => $p['rut'] ?? ''];
    }
} catch (Throwable $e) {
}

// Empresa preseleccionada (?empresa=) y paciente preseleccionado (?paciente=).
$preEmpresa = (int) ($_GET['empresa'] ?? 0);
if (!$preEmpresa && $empresas) {
    $preEmpresa = (int) $empresas[0]['id'];
}
$preCli = (($_GET['paciente'] ?? '') !== '' && getPaciente((int) $_GET['paciente'])) ? (int) $_GET['paciente'] : 0;

// ?tipo=41 (desde la tarjeta «Boleta Exenta» de Documentos): todos los ítems van exentos.
$tipoForzado = (int) ($_GET['tipo'] ?? 0);
$empJs = array_map(fn($e) => ['id' => (int) $e['id'], 'nombre' => $e['nombre_fantasia'] ?: $e['razon_social'], 'tipo' => 'boleta',
    'solo_exento' => dteBoletaSoloExenta($e) || ($tipoForzado === 41 && (int) $e['id'] === $preEmpresa)], $empresas);

$panelTitle = 'Emitir boleta';
$panelActive = 'boletas';
include APP_ROOT . '/partials/panel/top.php';
?>
<?php if (!$empresas): ?>
    <div class="rounded-2xl border border-dashed border-cafe/20 bg-crema-light p-8 text-center font-body text-sm text-cafe/55">
        No hay empresas activas que emitan boletas. Crea o activa una en <a href="<?= e(base_path('/admin/empresas')) ?>" class="text-terracota-dark font-semibold">Empresas</a> antes de emitir.
    </div>
<?php else: ?>
<p class="mx-auto mb-4 max-w-5xl rounded-xl border border-cafe/10 bg-crema-light px-4 py-2.5 font-body text-xs text-cafe/65">A los <strong class="text-cafe">pacientes</strong> se les emite <strong class="text-cafe">boleta</strong>. ¿Necesitas una factura? Las facturas son solo para <a href="<?= e(base_path('/admin/clientes')) ?>" class="font-semibold text-terracota-dark hover:underline">Clientes</a> (arriendo de boxes): <a href="<?= e(base_path('/admin/facturas/nueva')) ?>" class="font-semibold text-terracota-dark hover:underline">emitir factura a un cliente →</a></p>
<form id="ez-doc-form" class="mx-auto max-w-5xl">
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Empresa / paciente / pago -->
        <div class="lg:col-span-1 space-y-4 rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
            <div>
                <h2 class="font-heading text-lg font-semibold text-cafe">Empresa emisora</h2>
                <select name="empresa_id" id="doc-empresa" class="mt-2 w-full rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota">
                    <?php foreach ($empresas as $emp): ?>
                        <option value="<?= (int) $emp['id'] ?>" <?= $preEmpresa === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['razon_social']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="mt-2 flex items-center gap-2">
                    <span class="font-body text-xs text-cafe/55">Emitirá:</span>
                    <span id="doc-tipo-badge" class="rounded-full px-3 py-1 font-body text-xs font-bold uppercase tracking-wide"></span>
                </div>
            </div>

            <div class="border-t border-cafe/8 pt-4">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-heading text-lg font-semibold text-cafe">Paciente</h2>
                    <a href="<?= e(base_path('/admin/pacientes')) ?>" target="_blank" class="font-body text-xs font-semibold text-terracota-dark hover:underline">+ Nuevo paciente</a>
                </div>
                <input id="doc-paciente-buscar" type="search" autocomplete="off" placeholder="Buscar por nombre o RUT…" class="mt-2 w-full rounded-lg border border-cafe/20 bg-white px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota">
                <select id="doc-paciente" class="mt-2 w-full rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota">
                    <option value="">— Selecciona paciente —</option>
                    <?php foreach ($pacientesJs as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= e($c['etiqueta']) ?></option>
                    <?php endforeach; ?>
                </select>

                <div id="doc-receptor" class="mt-3 hidden">
                    <div id="doc-rc-error" class="hidden rounded-xl border border-red-200 bg-red-50 px-3 py-2.5 font-body text-xs leading-relaxed text-red-700"></div>
                    <div id="doc-rc-card" class="hidden rounded-xl border border-cafe/10 bg-white/80 p-3.5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-body text-[11px] font-bold uppercase tracking-wider text-cafe/50">Se cobra a</span>
                            <span id="doc-rc-badge" class="rounded-full px-2.5 py-0.5 font-body text-[11px] font-bold"></span>
                        </div>
                        <p id="doc-rc-nombre" class="mt-1 font-heading text-base font-semibold leading-snug text-cafe"></p>
                        <p id="doc-rc-motivo" class="font-body text-xs text-cafe/60"></p>
                        <dl id="doc-rc-datos" class="mt-2.5 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 border-t border-cafe/8 pt-2.5 font-body text-xs"></dl>
                        <p id="doc-rc-aviso" class="mt-2 hidden rounded-lg bg-amber-50 px-2.5 py-1.5 font-body text-[11px] text-amber-800"></p>
                    </div>
                    <a id="doc-rc-ficha" href="#" target="_blank" class="mt-2 hidden font-body text-[11px] font-semibold text-terracota-dark hover:underline">Corregir datos en la ficha del paciente →</a>
                    <label class="mt-3 flex flex-col gap-1"><span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Correo para enviar el documento</span><input name="receptor_email" type="email" class="rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota" placeholder="correo@ejemplo.cl"></label>
                </div>
                <input type="hidden" name="paciente_id">
                <input type="hidden" name="receptor_nombre"><input type="hidden" name="receptor_rut">
                <input type="hidden" name="receptor_direccion"><input type="hidden" name="receptor_comuna"><input type="hidden" name="receptor_codigo_prestacion">
                <p class="mt-2 font-body text-[11px] leading-relaxed text-cafe/45">Los datos a cobrar salen de la ficha en <a href="<?= e(base_path('/admin/pacientes')) ?>" class="text-terracota-dark" target="_blank">Pacientes</a>: si el paciente es <strong>menor de 18 años</strong> se cobra a su <strong>tutor/apoderado</strong>; si es mayor de edad, al propio paciente.</p>
            </div>

            <div class="border-t border-cafe/8 pt-4">
                <h2 class="font-heading text-lg font-semibold text-cafe">Emite (socia)</h2>
                <p class="mt-1 font-body text-[11px] leading-relaxed text-cafe/55">Las boletas las emiten solo <strong class="text-cafe"><?= e(sociasTexto()) ?></strong>, por sus propias sesiones. Los pacientes de quienes arriendan box los cobra cada profesional.</p>
                <select id="doc-prof" class="mt-2 w-full rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota">
                    <option value="">— Elige quién emite —</option>
                    <?php foreach ($profesionalesJs as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="mt-3 flex flex-col gap-1">
                    <span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">RUT del profesional</span>
                    <input id="doc-prof-rut" name="profesional_rut" class="rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota" placeholder="12.345.678-9">
                </label>
                <p class="mt-2 font-body text-[11px] text-cafe/45">Aparecerá en el documento, debajo del paciente. El RUT se completa solo si el profesional lo tiene guardado en su ficha; puedes editarlo aquí.</p>
            </div>

            <div class="border-t border-cafe/8 pt-4">
                <label class="flex flex-col gap-1">
                    <span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Medio de pago</span>
                    <select name="medio_pago" class="rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota">
                        <option value="efectivo">Efectivo</option><option value="tarjeta">Tarjeta</option><option value="transferencia">Transferencia</option><option value="otro">Otro</option>
                    </select>
                </label>
                <label class="mt-3 flex flex-col gap-1">
                    <span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Observaciones (opcional)</span>
                    <textarea name="glosa" rows="2" class="resize-none rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota"></textarea>
                </label>
            </div>
        </div>

        <!-- Detalle -->
        <div class="lg:col-span-2 rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
            <div class="flex items-center justify-between">
                <h2 class="font-heading text-lg font-semibold text-cafe">Detalle</h2>
                <button type="button" id="doc-add" class="inline-flex items-center gap-1.5 rounded-full border-2 border-cafe/15 px-3 py-1.5 font-body text-xs font-semibold text-cafe transition-colors hover:border-terracota hover:text-terracota">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg> Agregar ítem
                </button>
            </div>
            <!-- Sesiones del paciente aún sin boleta: al marcarlas pasan al detalle y quedan pagadas -->
            <div id="doc-ses-wrap" class="mt-3 hidden rounded-xl border border-salvia/40 bg-salvia/10 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-body text-xs font-bold uppercase tracking-wider text-salvia-dark">Sesiones por cobrar</h3>
                    <span id="doc-ses-n" class="font-body text-xs font-semibold text-cafe/60"></span>
                </div>
                <p class="mt-0.5 font-body text-[11px] text-cafe/55">Marca las sesiones que paga esta boleta: se agregan al detalle y quedan como <strong>pagadas</strong> en el calendario y en la ficha.</p>
                <div id="doc-ses-lista" class="mt-2 max-h-64 space-y-1.5 overflow-y-auto pr-1"></div>
            </div>
            <div class="mt-3 space-y-3" id="doc-items"></div>
            <p id="doc-empty-prod" class="mt-2 hidden font-body text-[11px] text-amber-700">Esta empresa aún no tiene productos. Agrégalos en <a href="<?= e(base_path('/admin/productos')) ?>" class="underline" target="_blank">Productos</a> o escribe el ítem manualmente.</p>
            <p class="mt-2 font-body text-[11px] text-cafe/45">Los precios son <strong>netos</strong>: a los ítems afectos se les <strong>suma</strong> el IVA 19%. Las prestaciones de salud suelen ir exentas.</p>

            <div class="mt-4 flex justify-end">
                <div class="w-64 space-y-1.5 font-body text-sm">
                    <div class="flex justify-between text-cafe/60"><span>Neto</span><span id="t-neto">$0</span></div>
                    <div class="flex justify-between text-cafe/60"><span>IVA 19%</span><span id="t-iva">$0</span></div>
                    <div class="flex justify-between text-cafe/60"><span>Exento</span><span id="t-exento">$0</span></div>
                    <div class="mt-1 flex justify-between rounded-lg bg-cafe px-3 py-2 font-heading text-lg font-semibold text-crema-light"><span>Total</span><span id="t-total">$0</span></div>
                </div>
            </div>

            <p id="doc-err" class="mt-3 hidden font-body text-sm font-medium text-red-600"></p>
            <div class="mt-4 flex items-center justify-end gap-3">
                <a href="<?= e(base_path('/admin/documentos' . ($preEmpresa ? '?empresa=' . $preEmpresa : ''))) ?>" class="font-body text-sm font-semibold text-cafe/60 hover:text-terracota">Cancelar</a>
                <button type="submit" id="doc-submit" class="rounded-full bg-terracota px-6 py-2.5 font-body text-sm font-semibold text-crema-light shadow-card transition-colors hover:bg-terracota-dark">Emitir</button>
            </div>
        </div>
    </div>
</form>

<script>
window.__DOC__ = {
    empresas: <?= json_encode($empJs, JSON_UNESCAPED_UNICODE) ?>,
    productos: <?= json_encode($prodPorEmpresa, JSON_UNESCAPED_UNICODE) ?>,
    pacientes: <?= json_encode($pacientesJs, JSON_UNESCAPED_UNICODE) ?>,
    profesionales: <?= json_encode($profesionalesJs, JSON_UNESCAPED_UNICODE) ?>,
    preCli: <?= (int) $preCli ?>,
    preReserva: <?= (int) ($_GET['reserva'] ?? 0) ?>
};
</script>
<?php endif; ?>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
