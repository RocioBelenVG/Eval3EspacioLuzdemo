<?php
/** Panel · Ficha del paciente. El router define $pacienteId. */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/pacientes.php';
require_once APP_ROOT . '/lib/boletas.php';
require_once APP_ROOT . '/lib/certificados.php';

$paciente = getPaciente((int) ($pacienteId ?? 0));
if (!$paciente) {
    $panelTitle = 'Paciente no encontrado';
    $panelActive = 'pacientes';
    include APP_ROOT . '/partials/panel/top.php';
    echo '<div class="rounded-2xl border border-cafe/10 bg-crema-light p-8 text-center font-body text-cafe/60">Ese paciente no existe. <a href="' . e(base_path('/admin/pacientes')) . '" class="text-terracota-dark">Volver</a></div>';
    include APP_ROOT . '/partials/panel/bottom.php';
    return;
}

$cid = (int) $paciente['id'];
$edad = pacienteEdad($paciente['fecha_nacimiento']);
$resumen = pacienteResumen($cid);
$fichas = pacienteFichas($cid);
$reservas = pacienteReservas($cid);
$boletas = pacienteBoletas($cid);
require_once APP_ROOT . '/lib/cobros.php';
$cobroRes = [];
foreach (reservasCobro(array_column($reservas, 'id')) as $rc) {
    $cobroRes[(int) $rc['id']] = cobroEstadoReserva($rc)['sesion'];
}
$certificados = certListarPaciente($cid);
$urlNuevoCert = base_path('/admin/pacientes/' . $cid . '/certificado/nuevo');
$esps = especialidadesLista();
$profs = listProfesionales();
$estados = ['pendiente' => 'bg-amber-100 text-amber-700', 'confirmada' => 'bg-salvia/20 text-salvia-dark', 'realizada' => 'bg-green-100 text-green-700', 'cancelada' => 'bg-red-100 text-red-700', 'no_show' => 'bg-cafe/15 text-cafe'];

$panelTitle = $paciente['nombre'];
$panelActive = 'pacientes';
include APP_ROOT . '/partials/panel/top.php';
?>
<a href="<?= e(base_path('/admin/pacientes')) ?>" class="mb-4 inline-flex items-center gap-1.5 font-body text-sm font-semibold text-cafe/60 hover:text-terracota">← Volver a pacientes</a>

<!-- Cabecera del paciente -->
<div class="rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-4">
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-terracota/15 font-heading text-xl font-bold text-terracota-dark"><?= e(getIniciales($paciente['nombre'])) ?></div>
            <div>
                <h2 class="font-heading text-2xl font-semibold text-cafe"><?= e($paciente['nombre']) ?></h2>
                <p class="mt-1 font-body text-sm text-cafe/60">
                    <?= $edad !== null ? $edad . ' años · ' : '' ?><?= e(pacienteDocumento($paciente) ?: 'Sin RUT') ?>
                    <?= $paciente['telefono'] ? ' · ' . e($paciente['telefono']) : '' ?><?= $paciente['email'] ? ' · ' . e($paciente['email']) : '' ?>
                </p>
                <p class="mt-1 font-body text-xs">
                    <?= $paciente['consentimiento'] ? '<span class="text-green-700">✓ Consentimiento de datos otorgado</span>' : '<span class="text-amber-700">⚠ Sin consentimiento de datos registrado</span>' ?>
                </p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" id="cli-edit" class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota hover:text-terracota">Editar datos</button>
            <a href="<?= e($urlNuevoCert) ?>" class="inline-flex items-center gap-1.5 rounded-full bg-terracota px-4 py-2 font-body text-sm font-semibold text-crema-light shadow-card hover:bg-terracota-dark">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg> Certificado de atención
            </a>
        </div>
    </div>
    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <?php
        $kpis = [
            ['Sesiones', (string) $resumen['sesiones']],
            ['Realizadas', (string) $resumen['realizadas']],
            ['Facturado', clp($resumen['facturado']) . (!empty($resumen['por_cobrar']) ? ' · ' . $resumen['por_cobrar'] . ' por cobrar' : '')],
            ['Próxima hora', $resumen['proxima'] ? date('d-m H:i', strtotime($resumen['proxima'])) : '—'],
        ];
        foreach ($kpis as $k): ?>
            <div class="rounded-xl bg-crema/50 px-4 py-3"><div class="font-body text-[11px] font-semibold uppercase tracking-wide text-cafe/50"><?= e($k[0]) ?></div><div class="mt-0.5 font-heading text-lg font-semibold text-cafe"><?= e($k[1]) ?></div></div>
        <?php endforeach; ?>
    </div>

    <?php
    // Todos los campos de la tabla `pacientes`, agrupados por tema.
    $txt = static fn($v): string => trim((string) ($v ?? ''));
    $fF = static function (?string $v, bool $hora = false): string {
        if (!$v || str_starts_with($v, '0000')) return '';
        try { return (new DateTimeImmutable($v))->format($hora ? 'd-m-Y · H:i' : 'd-m-Y'); } catch (Throwable $e) { return ''; }
    };
    $apod = $txt($paciente['apoderado']);
    $sinTutor = $apod === '' || preg_match('/^no aplica/i', $apod);
    $tel = $txt($paciente['telefono']); $mail = $txt($paciente['email']); $telA = $txt($paciente['apoderado_telefono'] ?? '');
    $telHref = static fn(string $t): string => 'tel:' . preg_replace('/[^\d+]/', '', $t);
    $chip = static fn(string $t, string $c): string => '<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ' . $c . '">' . $t . '</span>';
    $secciones = [
        ['Identificación', 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z', [
            ['Nombre completo', e($paciente['nombre'])],
            [pacienteDocEtiqueta($paciente), e($txt(pacienteDocNumero($paciente)))],
            ['N° de ficha', (string) $cid],
            ['Estado', (int) $paciente['activo'] === 1 ? $chip('Activo', 'bg-salvia/20 text-salvia-dark') : $chip('Inactivo', 'bg-cafe/10 text-cafe/70')],
        ]],
        ['Datos personales', 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z', [
            ['Fecha de nacimiento', e($fF($paciente['fecha_nacimiento'] ?? null))],
            ['Edad', $edad !== null ? $edad . ' años' : ''],
            ['Sexo', e(PACIENTE_SEXOS[$paciente['sexo'] ?? ''] ?? '')],
        ]],
        ['Contacto', 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM22 6l-10 7L2 6', [
            ['Teléfono', $tel !== '' ? '<a class="hover:text-terracota" href="' . e($telHref($tel)) . '">' . e($tel) . '</a>' : ''],
            ['Correo', $mail !== '' ? '<a class="break-all hover:text-terracota" href="mailto:' . e($mail) . '">' . e($mail) . '</a>' : ''],
            ['Contacto de emergencia', e($txt($paciente['contacto_emergencia']))],
        ]],
        ['Domicilio', 'M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0zM12 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6z', [
            ['Dirección', e($txt($paciente['direccion']))],
            ['Comuna', e($txt($paciente['comuna']))],
            ['Región', e($txt($paciente['region'] ?? ''))],
        ]],
        ['Previsión y facturación', 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M9 13h6M9 17h4', [
            ['Previsión', e($txt($paciente['prevision']))],
            ['Código de prestación', $txt($paciente['codigo_prestacion']) !== '' ? $chip(e($paciente['codigo_prestacion']), 'bg-salvia/20 text-cafe') : ''],
            ['Giro (para factura)', e($txt($paciente['giro'] ?? ''))],
        ]],
        ['Tutor / apoderado', 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8', [
            ['Nombre y relación', $sinTutor ? '<span class="text-cafe/55">' . e($apod !== '' ? $apod : 'No registrado') . '</span>' : e($apod)],
            ['RUT del apoderado', e($txt($paciente['apoderado_rut'] ?? ''))],
            ['Teléfono del tutor', $telA !== '' ? '<a class="hover:text-terracota" href="' . e($telHref($telA)) . '">' . e($telA) . '</a>' : ''],
        ]],
        ['Consentimiento y registro', 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z', [
            ['Consentimiento de datos', (int) $paciente['consentimiento'] === 1 ? $chip('✓ Otorgado', 'bg-green-100 text-green-700') : $chip('Pendiente', 'bg-amber-100 text-amber-700')],
            ['Fecha del consentimiento', e($fF($paciente['consentimiento_fecha'] ?? null, true))],
            ['Registrado el', e($fF($paciente['creado_en'] ?? null, true))],
            ['Última actualización', e($fF($paciente['actualizado_en'] ?? null, true))],
        ]],
    ];
    ?>
    <div class="mt-5 border-t border-cafe/8 pt-5">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-heading text-lg font-semibold text-cafe">Datos del paciente</h3>
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
            <?php include APP_ROOT . '/partials/panel/paciente-notas.php'; ?>
        </div>
    </div>
</div>

<!-- Certificados de atención (descargables en PDF) -->
<div class="mt-6 ez-tabla-card">
    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
        <div>
            <h3 class="font-heading text-lg font-semibold text-cafe">Certificados de atención (<?= count($certificados) ?>)</h3>
            <p class="font-body text-xs text-cafe/50">Certificados emitidos con el formato del centro. Cada uno se descarga en PDF.</p>
        </div>
        <a href="<?= e($urlNuevoCert) ?>" class="ez-row-btn">+ Nuevo certificado de atención</a>
    </div>
    <div class="overflow-x-auto">
        <table class="ez-tabla font-body text-sm">
            <thead><tr><th class="px-4">N°</th><th class="px-4">Fecha</th><th class="px-4">Especialidad</th><th class="px-4">Profesional</th><th class="px-4 text-center">Sesiones</th><th class="px-4 text-right">Total</th><th class="px-4"></th></tr></thead>
            <tbody class="text-cafe/85">
                <?php if (!$certificados): ?>
                    <tr><td colspan="7" class="px-4 py-8 text-center text-cafe/45">Aún no hay certificados. Usa «Certificado de atención» para crear el primero.</td></tr>
                <?php else: foreach ($certificados as $ct): $urlCt = base_path('/admin/certificados/' . (int) $ct['id']); ?>
                    <tr class="cursor-pointer acc-terracota" onclick="location.href='<?= e($urlCt) ?>'">
                        <td class="px-4 py-3 font-semibold text-cafe whitespace-nowrap">N° <?= e(str_pad((string) $ct['id'], 6, '0', STR_PAD_LEFT)) ?></td>
                        <td class="px-4 py-3 whitespace-nowrap"><?= e(date('d-m-Y', strtotime($ct['fecha_documento']))) ?></td>
                        <td class="px-4 py-3"><?= e($ct['especialidad']) ?></td>
                        <td class="px-4 py-3"><?= e($ct['firmante_nombre']) ?></td>
                        <td class="px-4 py-3 text-center"><?= count($ct['sesiones_arr']) ?></td>
                        <td class="px-4 py-3 text-right font-semibold text-cafe whitespace-nowrap"><?= (int) $ct['certifica_pago'] === 1 ? e(clp((int) $ct['total_clp'])) : '<span class="font-normal text-cafe/40">—</span>' ?></td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="<?= e($urlCt) ?>" class="mr-2 font-body text-xs font-semibold text-cafe/60 hover:text-terracota" onclick="event.stopPropagation()">Ver</a>
                            <a href="<?= e($urlCt . '/pdf') ?>" class="ez-row-btn" onclick="event.stopPropagation()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg> PDF
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
// Informes clínicos adjuntos: se muestran en su sección y dentro de la ficha de cada área.
require_once APP_ROOT . '/lib/informes.php';
$informes = pacienteInformes($cid);
$informesPorFicha = [];
foreach ($informes as $inf) if ($inf['ficha_id'] !== null) $informesPorFicha[(int) $inf['ficha_id']][] = $inf;
include APP_ROOT . '/partials/panel/ficha-clinica.php';
include APP_ROOT . '/partials/panel/paciente-informes.php';
?>

<!-- Historial de horas + pagos -->
<div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
    <div class="ez-tabla-card">
        <h3 class="px-5 py-4 font-heading text-lg font-semibold text-cafe">Historial de horas (<?= count($reservas) ?>)</h3>
        <div class="overflow-x-auto">
            <table class="ez-tabla font-body text-sm">
                <thead><tr><th class="px-4 py-2.5">Fecha</th><th class="px-4 py-2.5">Box</th><th class="px-4 py-2.5">Prof.</th><th class="px-4 py-2.5 text-center">Estado</th><th class="px-4 py-2.5 text-right" title="Precio de la hora. Solo es ingreso cuando se emite su boleta.">Precio · cobro</th></tr></thead>
                <tbody class="text-cafe/80">
                    <?php if (!$reservas): ?><tr><td colspan="5" class="px-4 py-8 text-center text-cafe/40">Sin horas registradas.</td></tr>
                    <?php else: foreach ($reservas as $r): ?>
                        <tr>
                            <td class="px-4 py-2.5 font-semibold text-cafe"><?= e(date('d-m-Y', strtotime($r['fecha']))) ?><?= $r['hhmm'] ? ' ' . e($r['hhmm']) : '' ?></td>
                            <td class="px-4 py-2.5"><?= e(nombreBox($r['box_slug'])) ?></td>
                            <td class="px-4 py-2.5"><?= e(nombreProfesional($r['profesional_id'] !== null ? (int) $r['profesional_id'] : null)) ?: '—' ?></td>
                            <td class="px-4 py-2.5 text-center"><span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $estados[$r['estado']] ?? 'bg-cafe/10' ?>"><?= e($r['estado']) ?></span></td>
                            <td class="px-4 py-2.5 text-right">
                                <div><?= $r['precio_clp'] ? e(clp((int) $r['precio_clp'])) : '—' ?></div>
                                <?php $cs = $cobroRes[(int) $r['id']] ?? null; if ($cs): ?>
                                    <?php if ($cs['doc_id'] && !esRolPacientes()): ?><a href="<?= e(base_path('/admin/boletas/' . $cs['doc_id'])) ?>" class="inline-block rounded-full px-2 py-0.5 text-[10px] font-semibold <?= $cs['clase'] ?> hover:opacity-80"><?= e($cs['texto']) ?></a>
                                    <?php elseif ($cs['clave'] === 'por_cobrar' && isAdmin()): ?><a href="<?= e(base_path('/admin/boletas/nueva?paciente=' . $cid . '&reserva=' . (int) $r['id'])) ?>" class="inline-block rounded-full px-2 py-0.5 text-[10px] font-semibold <?= $cs['clase'] ?> hover:opacity-80" title="Emitir la boleta de esta sesión"><?= e($cs['texto']) ?> →</a>
                                    <?php else: ?><span class="inline-block rounded-full px-2 py-0.5 text-[10px] font-semibold <?= $cs['clase'] ?>"><?= e($cs['texto']) ?></span><?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="ez-tabla-card">
        <div class="flex items-center justify-between px-5 py-4">
            <h3 class="font-heading text-lg font-semibold text-cafe">Pagos / boletas (<?= count($boletas) ?>)</h3>
            <?php if (isAdmin()): ?><a href="<?= e(base_path('/admin/boletas/nueva?paciente=' . $cid)) ?>" class="font-body text-xs font-semibold text-terracota-dark hover:text-terracota"><?= !empty($resumen['por_cobrar']) ? 'Cobrar ' . (int) $resumen['por_cobrar'] . ' ' . ((int) $resumen['por_cobrar'] === 1 ? 'sesión' : 'sesiones') . ' →' : '+ Emitir boleta' ?></a><?php endif; ?>
        </div>
        <div class="overflow-x-auto">
            <table class="ez-tabla font-body text-sm">
                <thead><tr><th class="px-4 py-2.5">Folio</th><th class="px-4 py-2.5">Fecha</th><th class="px-4 py-2.5">Pago</th><th class="px-4 py-2.5 text-right">Total</th></tr></thead>
                <tbody class="text-cafe/80">
                    <?php if (!$boletas): ?><tr><td colspan="4" class="px-4 py-8 text-center text-cafe/40">Sin pagos registrados.</td></tr>
                    <?php else: foreach ($boletas as $b): ?>
                        <?php if (esRolPacientes()): /* sin acceso al módulo Boletas: solo consulta */ ?>
                        <tr>
                        <?php else: ?>
                        <tr class="cursor-pointer" onclick="location.href='<?= e(base_path('/admin/boletas/' . $b['id'])) ?>'">
                        <?php endif; ?>
                            <td class="px-4 py-2.5 font-semibold text-cafe">N° <?= e(str_pad((string) $b['folio'], 6, '0', STR_PAD_LEFT)) ?></td>
                            <td class="px-4 py-2.5"><?= e(date('d-m-Y', strtotime($b['fecha_emision']))) ?></td>
                            <td class="px-4 py-2.5"><?php [$pgT, $pgC] = docPagoEtiqueta($b); ?><span class="inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold <?= $pgC ?>"><?= e($pgT) ?></span> <span class="text-xs text-cafe/50"><?= e(medioPagoLabel($b['medio_pago'])) ?></span></td>
                            <td class="px-4 py-2.5 text-right font-semibold text-cafe"><?= e(clp((int) $b['total_clp'])) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// Datos para el JS
$cliJson = json_encode([
    'id' => $cid, 'nombre' => $paciente['nombre'], 'rut' => $paciente['rut'], 'documento_tipo' => pacienteDocTipo($paciente), 'documento_numero' => $paciente['documento_numero'] ?? '', 'email' => $paciente['email'], 'telefono' => $paciente['telefono'],
    'fecha_nacimiento' => $paciente['fecha_nacimiento'], 'sexo' => $paciente['sexo'] ?? '', 'direccion' => $paciente['direccion'],
    'region' => $paciente['region'] ?? '', 'comuna' => $paciente['comuna'],
    'apoderado' => $paciente['apoderado'], 'apoderado_rut' => $paciente['apoderado_rut'] ?? '', 'apoderado_telefono' => $paciente['apoderado_telefono'] ?? '', 'contacto_emergencia' => $paciente['contacto_emergencia'], 'prevision' => $paciente['prevision'],
    'codigo_prestacion' => $paciente['codigo_prestacion'] ?? '', 'giro' => $paciente['giro'] ?? '',
    'consentimiento' => (int) $paciente['consentimiento'],
], JSON_UNESCAPED_UNICODE);
$espJson = json_encode(array_map(fn($s, $n) => ['slug' => $s, 'nombre' => $n], array_keys($esps), array_values($esps)), JSON_UNESCAPED_UNICODE);
$profJson = json_encode(array_map(fn($i, $n) => ['id' => $i, 'nombre' => $n], array_keys($profs), array_values($profs)), JSON_UNESCAPED_UNICODE);
require APP_ROOT . '/partials/panel/paciente-modales.php';
include APP_ROOT . '/partials/panel/bottom.php';
