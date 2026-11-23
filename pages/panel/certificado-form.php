<?php
/**
 * Panel · Crear / editar el certificado de atención de un paciente.
 * El router define $pacienteId (nuevo) o $certId (editar).
 */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/certificados.php';

$cert = isset($certId) ? certGet((int) $certId) : null;
$paciente = getPaciente($cert ? (int) $cert['paciente_id'] : (int) ($pacienteId ?? 0));
if (!$paciente || (isset($certId) && !$cert)) {
    http_response_code(404);
    $panelTitle = 'No encontrado';
    $panelActive = 'pacientes';
    include APP_ROOT . '/partials/panel/top.php';
    echo '<div class="rounded-2xl border border-cafe/10 bg-crema-light p-8 text-center font-body text-cafe/60">No se encontró el paciente o el certificado. <a href="' . e(base_path('/admin/pacientes')) . '" class="text-terracota-dark">Volver a pacientes</a></div>';
    include APP_ROOT . '/partials/panel/bottom.php';
    return;
}
$cid = (int) $paciente['id'];

// Profesionales activos con su título y la última firma usada.
$profs = [];
try {
    foreach (db()->query("SELECT id, nombre, titulo, rut, firma_archivo FROM profesionales WHERE activo = 1 ORDER BY nombre") as $p) {
        $u = certUltimoFirmante((int) $p['id']);
        $profs[] = [
            'id' => (int) $p['id'],
            'nombre' => $p['nombre'],
            'titulo' => $p['titulo'] ?? '',
            'especialidad' => $u['especialidad'] ?? certEspecialidadDeTitulo($p['titulo'] ?? ''),
            'firmante_nombre' => $u['firmante_nombre'] ?? $p['nombre'],
            'firmante_titulo' => $u['firmante_titulo'] ?? ($p['titulo'] ?? ''),
            'firmante_detalle' => $u['firmante_detalle'] ?? '',
            'modalidad' => $u['modalidad'] ?? '',
            'rut' => $p['rut'] ?? '',
            'firma' => firmaUrl($p['firma_archivo'] ?? null),
        ];
    }
} catch (Throwable $e) {
}

// Horas del paciente en la agenda (para «Traer sesiones de la agenda»).
// Cada hora indica si ya tiene boleta (solo esas pueden certificarse como pagadas) y su valor cobrado.
require_once APP_ROOT . '/lib/cobros.php';
$agenda = [];
$cobrosAg = [];
foreach (reservasCobro(array_column(pacienteReservas($cid), 'id')) as $rc) {
    $cobrosAg[(int) $rc['id']] = $rc;
}
foreach (pacienteReservas($cid) as $r) {
    if (in_array($r['estado'], ['cancelada', 'no_show'], true)) continue;
    $rc = $cobrosAg[(int) $r['id']] ?? [];
    $pag = !empty($rc['boleta_id']);
    $neto = (int) ($rc['boleta_neto'] ?? 0);
    $agenda[] = ['fecha' => $r['fecha'], 'prof' => $r['profesional_id'] !== null ? (int) $r['profesional_id'] : null,
        'pagada' => $pag, 'valor' => $pag ? (!empty($rc['boleta_afecto']) ? $neto + (int) round($neto * 0.19) : $neto) : (int) ($r['precio_clp'] ?? 0)];
}

// Trato del documento: automático según el sexo de la ficha.
$sexoFicha = (string) ($paciente['sexo'] ?? '');
$tratoAuto = certTratoDeSexo($sexoFicha);
$domicilio = trim(((string) ($paciente['direccion'] ?? '')) . (($paciente['comuna'] ?? '') !== '' ? ', ' . $paciente['comuna'] : ''));
$v = $cert ?: [
    'profesional_id' => null, 'especialidad' => '', 'ciudad' => 'Los Ángeles', 'fecha_documento' => date('Y-m-d'),
    'paciente_nombre' => $paciente['nombre'], 'paciente_rut' => pacienteDocNumero($paciente), 'paciente_domicilio' => $domicilio,
    'trato' => '', 'modalidad' => 'en consulta particular', 'certifica_pago' => 1, 'sesiones_arr' => [],
    'texto_cierre' => '', 'observaciones' => '', 'firmante_nombre' => '', 'firmante_titulo' => '', 'firmante_detalle' => '', 'firmante_rut' => '',
];
// Al editar: si el cierre es el texto por defecto del otro trato, se ajusta al automático.
if ($cert && $tratoAuto !== '' && in_array(trim((string) $v['texto_cierre']), [certCierrePorDefecto($tratoAuto === 'f' ? 'm' : 'f'), 'Se extiende el presente certificado a solicitud de el interesado, para los fines que estime convenientes.'], true)) {
    $v['texto_cierre'] = certCierrePorDefecto($tratoAuto);
}
// Firma que se verá: la congelada en el certificado (si se está editando) o la del profesional.
$firmaActual = firmaUrl($cert['firma_archivo'] ?? null);

$inp = 'w-full rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota';
$lbl = 'font-body text-xs font-semibold uppercase tracking-wide text-cafe/60';

$panelTitle = $cert ? 'Editar certificado de atención' : 'Certificado de atención';
$panelActive = 'pacientes';
include APP_ROOT . '/partials/panel/top.php';
?>
<a href="<?= e(base_path('/admin/pacientes/' . $cid)) ?>" class="mb-4 inline-flex items-center gap-1.5 font-body text-sm font-semibold text-cafe/60 hover:text-terracota">← Volver a <?= e($paciente['nombre']) ?></a>

<div class="mb-5 rounded-2xl border border-cafe/12 bg-white px-5 py-4 shadow-card">
    <h2 class="font-heading text-xl font-semibold text-cafe"><?= $cert ? 'Editar certificado de atención' : 'Nuevo certificado de atención' ?></h2>
    <p class="mt-1 font-body text-sm text-cafe/60">Completa los datos y guarda: se genera el certificado con el diseño de Espacio Luz, listo para descargar en PDF como <strong>«Cer.Atencion <?= e($paciente['nombre']) ?>»</strong>.</p>
</div>

<form id="cert-form" class="grid grid-cols-1 gap-5 lg:grid-cols-5" onsubmit="return false;">
    <input type="hidden" name="id" value="<?= (int) ($cert['id'] ?? 0) ?>">
    <input type="hidden" name="paciente_id" value="<?= $cid ?>">

    <!-- Datos -->
    <div class="space-y-4 rounded-2xl border border-cafe/12 bg-white p-5 shadow-card lg:col-span-2">
        <h3 class="font-heading text-lg font-semibold text-cafe">Datos del certificado</h3>

        <label class="block"><span class="<?= $lbl ?>">Profesional que atiende</span>
            <select name="profesional_id" id="ct-prof" class="mt-1 <?= $inp ?>">
                <option value="">— Elige profesional —</option>
                <?php foreach ($profs as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= (int) ($v['profesional_id'] ?? 0) === $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?><?= $p['titulo'] ? ' — ' . e($p['titulo']) : '' ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="block"><span class="<?= $lbl ?>">Especialidad (título del certificado) *</span>
            <input name="especialidad" value="<?= e($v['especialidad']) ?>" class="mt-1 <?= $inp ?>" placeholder="Ej.: Terapia Ocupacional">
        </label>

        <div class="grid grid-cols-2 gap-3">
            <label class="block"><span class="<?= $lbl ?>">Fecha *</span><input type="date" name="fecha_documento" value="<?= e($v['fecha_documento']) ?>" class="mt-1 <?= $inp ?>"></label>
            <label class="block"><span class="<?= $lbl ?>">Ciudad</span><input name="ciudad" value="<?= e($v['ciudad']) ?>" class="mt-1 <?= $inp ?>"></label>
        </div>

        <div>
            <span class="<?= $lbl ?>">El documento dice</span>
            <?php if ($tratoAuto !== ''): ?>
                <!-- Automático: según el sexo registrado en la ficha -->
                <input type="radio" name="trato" value="<?= $tratoAuto ?>" checked class="hidden">
                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-salvia/30 bg-salvia/10 px-3 py-2">
                    <span class="font-body text-sm font-semibold text-cafe">«<?= $tratoAuto === 'f' ? 'la paciente' : 'el paciente' ?>»</span>
                    <span class="font-body text-xs text-cafe/60">Automático según el sexo registrado en la ficha (<?= e(PACIENTE_SEXOS[$sexoFicha] ?? $sexoFicha) ?>).</span>
                    <a href="<?= e(base_path('/admin/pacientes/' . $cid)) ?>" class="font-body text-xs font-semibold text-terracota-dark hover:underline">Cambiar en la ficha</a>
                </div>
            <?php elseif ($sexoFicha === ''): ?>
                <!-- La ficha no tiene sexo: se indica aquí y queda registrado en la ficha al guardar -->
                <span class="mt-1 block font-body text-xs font-semibold text-cafe/70">Sexo del paciente *</span>
                <div class="mt-1 grid grid-cols-3 gap-2">
                    <?php foreach (['mujer' => 'Mujer', 'hombre' => 'Hombre', 'otro' => 'Otro'] as $sk => $sl): ?>
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe has-[:checked]:border-terracota has-[:checked]:bg-terracota/10">
                            <input type="radio" name="sexo_paciente" value="<?= $sk ?>" class="accent-[#D97757]"> <?= $sl ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="mt-1 font-body text-[11px] text-cafe/55">La ficha aún no tiene el sexo registrado. Indícalo y el certificado dirá «la paciente» o «el paciente» automáticamente; quedará guardado en la ficha para los próximos documentos.</p>
                <input type="radio" name="trato" value="f" class="hidden" data-trato-auto="mujer">
                <input type="radio" name="trato" value="m" class="hidden" data-trato-auto="hombre">
                <div id="ct-trato-otro" class="mt-2 hidden grid-cols-2 gap-2">
                    <?php foreach (['f' => 'la paciente', 'm' => 'el paciente'] as $tk => $tl): ?>
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe has-[:checked]:border-terracota has-[:checked]:bg-terracota/10">
                            <input type="radio" name="trato" value="<?= $tk ?>" class="accent-[#D97757]"> «<?= $tl ?>»
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <!-- Sexo «otro»: se elige cómo se nombra en el documento -->
                <div class="mt-1 grid grid-cols-2 gap-2">
                    <?php foreach (['f' => 'la paciente', 'm' => 'el paciente'] as $tk => $tl): ?>
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe has-[:checked]:border-terracota has-[:checked]:bg-terracota/10">
                            <input type="radio" name="trato" value="<?= $tk ?>" class="accent-[#D97757]" <?= ($v['trato'] ?? '') === $tk ? 'checked' : '' ?>> «<?= $tl ?>»
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="mt-1 font-body text-[11px] text-cafe/55">Sexo registrado en la ficha: Otro. Elige cómo se nombra en el documento.</p>
            <?php endif; ?>
        </div>

        <div class="border-t border-cafe/8 pt-4">
            <span class="<?= $lbl ?>">Paciente</span>
            <input name="paciente_nombre" value="<?= e($v['paciente_nombre']) ?>" class="mt-1 <?= $inp ?>" placeholder="Nombre completo *">
            <div class="mt-2 grid grid-cols-2 gap-2">
                <input name="paciente_rut" value="<?= e((string) ($v['paciente_rut'] ?? '')) ?>" class="<?= $inp ?>" placeholder="RUT">
                <input name="modalidad" value="<?= e($v['modalidad']) ?>" class="<?= $inp ?>" placeholder="en consulta particular" title="Modalidad: «se encuentra recibiendo atención … en consulta particular»">
            </div>
            <input name="paciente_domicilio" value="<?= e((string) ($v['paciente_domicilio'] ?? '')) ?>" class="mt-2 <?= $inp ?>" placeholder="Domicilio">
            <p class="mt-1.5 font-body text-[11px] text-cafe/45">Se completan desde la ficha del paciente; puedes ajustarlos solo para este certificado.</p>
        </div>

        <label class="flex items-start gap-2 rounded-lg bg-crema/60 px-3 py-2.5">
            <input type="checkbox" name="certifica_pago" id="ct-pago" class="mt-0.5 h-4 w-4 accent-[#D97757]" <?= (int) $v['certifica_pago'] === 1 ? 'checked' : '' ?>>
            <span class="font-body text-sm text-cafe">Certificar también el <strong>pago íntegro</strong> de las sesiones <span class="block text-xs text-cafe/55">Muestra el valor de cada sesión y el total.</span></span>
        </label>
    </div>

    <!-- Sesiones -->
    <div class="rounded-2xl border border-cafe/12 bg-white p-5 shadow-card lg:col-span-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-heading text-lg font-semibold text-cafe">Sesiones</h3>
            <div class="flex flex-wrap items-center gap-2">
                <input type="month" id="ct-mes" value="<?= e(substr((string) $v['fecha_documento'], 0, 7)) ?>" class="rounded-lg border border-cafe/20 bg-crema-light px-2.5 py-1.5 font-body text-sm text-cafe outline-none focus:border-terracota" title="Mes a traer desde la agenda">
                <button type="button" id="ct-agenda" class="rounded-full border-2 border-cafe/15 px-3 py-1.5 font-body text-xs font-semibold text-cafe hover:border-terracota hover:text-terracota">Traer de la agenda</button>
            </div>
        </div>
        <div class="mt-3 flex items-center gap-2" id="ct-valor-wrap">
            <span class="<?= $lbl ?>">Valor por sesión</span>
            <input type="number" min="0" step="500" id="ct-valor" value="<?= (int) (($v['sesiones_arr'][0]['valor'] ?? 0) ?: 35000) ?>" class="w-32 rounded-lg border border-cafe/20 bg-crema-light px-2.5 py-1.5 text-right font-body text-sm text-cafe outline-none focus:border-terracota">
            <button type="button" id="ct-aplicar" class="font-body text-xs font-semibold text-terracota-dark hover:text-terracota">Aplicar a todas</button>
        </div>
        <p id="ct-agenda-msg" class="mt-2 hidden font-body text-xs"></p>

        <div class="ez-tabla-card mt-3">
            <table class="ez-tabla font-body text-sm">
                <thead><tr><th class="px-3">Sesión</th><th class="px-3">Fecha</th><th class="px-3 text-right ct-col-valor">Valor</th><th class="px-3"></th></tr></thead>
                <tbody id="ct-ses"></tbody>
            </table>
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <button type="button" id="ct-add" class="inline-flex items-center gap-1.5 rounded-full border-2 border-cafe/15 px-3 py-1.5 font-body text-xs font-semibold text-cafe hover:border-terracota hover:text-terracota">+ Agregar sesión</button>
            <div id="ct-total-box" class="flex items-center gap-4 rounded-xl bg-cafe px-4 py-2.5 text-crema-light">
                <span id="ct-total-lbl" class="font-body text-xs font-semibold">Total</span>
                <span id="ct-total" class="font-heading text-lg font-semibold">$0</span>
            </div>
        </div>
    </div>

    <!-- Cierre y firma -->
    <div class="rounded-2xl border border-cafe/12 bg-white p-5 shadow-card lg:col-span-5">
        <h3 class="font-heading text-lg font-semibold text-cafe">Cierre y firma</h3>
        <div class="mt-3 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="space-y-3">
                <label class="block"><span class="<?= $lbl ?>">Texto de cierre</span>
                    <textarea name="texto_cierre" id="ct-cierre" rows="2" class="mt-1 resize-none <?= $inp ?>"><?= e((string) ($v['texto_cierre'] ?? '')) ?></textarea>
                </label>
                <label class="block"><span class="<?= $lbl ?>">Observaciones (opcional)</span>
                    <textarea name="observaciones" rows="2" class="mt-1 resize-none <?= $inp ?>" placeholder="Párrafo adicional antes del cierre"><?= e((string) ($v['observaciones'] ?? '')) ?></textarea>
                </label>
            </div>
            <div class="space-y-3">
                <label class="block"><span class="<?= $lbl ?>">Firma — nombre completo *</span><input name="firmante_nombre" value="<?= e($v['firmante_nombre']) ?>" class="mt-1 <?= $inp ?>" placeholder="Ej.: Fernanda Isabel Alarcón Ríos"></label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="block"><span class="<?= $lbl ?>">Profesión</span><input name="firmante_titulo" value="<?= e((string) $v['firmante_titulo']) ?>" class="mt-1 <?= $inp ?>" placeholder="Terapeuta Ocupacional"></label>
                    <label class="block"><span class="<?= $lbl ?>">Grado / especialidad</span><input name="firmante_detalle" value="<?= e((string) $v['firmante_detalle']) ?>" class="mt-1 <?= $inp ?>" placeholder="Magíster en …"></label>
                </div>
                <label class="block"><span class="<?= $lbl ?>">RUT de quien firma</span><input name="firmante_rut" id="ct-frut" value="<?= e((string) ($v['firmante_rut'] ?? '')) ?>" class="mt-1 <?= $inp ?>" placeholder="Se completa desde la ficha del profesional"></label>
                <p class="font-body text-[11px] text-cafe/45">Los datos de la firma quedan recordados para el próximo certificado de este profesional. Si tiene <strong>firma y timbre</strong> cargados, bajo la línea no se imprime nada (la imagen ya trae nombre, RUT y título); estos datos solo se imprimen cuando no hay firma.</p>
                <!-- Firma y timbre (desde Profesionales) -->
                <div class="rounded-xl border border-cafe/12 bg-crema/40 p-3">
                    <span class="<?= $lbl ?>">Firma y timbre</span>
                    <div class="mt-2 flex items-center gap-3">
                        <div class="flex h-[84px] w-[140px] shrink-0 items-center justify-center overflow-hidden rounded-lg border border-dashed border-cafe/25 bg-white">
                            <img id="ct-firma-img" alt="Firma y timbre" class="h-full w-full object-contain <?= $firmaActual ? '' : 'hidden' ?>" src="<?= e((string) $firmaActual) ?>">
                            <span id="ct-firma-vacia" class="font-body text-[11px] text-cafe/40 <?= $firmaActual ? 'hidden' : '' ?>">Sin firma</span>
                        </div>
                        <p id="ct-firma-msg" class="font-body text-xs text-cafe/60">Se coloca automáticamente sobre la línea de firma.</p>
                    </div>
                </div>
            </div>
        </div>
        <p id="ct-err" class="mt-4 hidden rounded-lg bg-red-50 px-3 py-2 font-body text-sm font-medium text-red-700"></p>
        <div class="mt-4 flex items-center justify-end gap-3">
            <a href="<?= e(base_path('/admin/pacientes/' . $cid)) ?>" class="font-body text-sm font-semibold text-cafe/60 hover:text-terracota">Cancelar</a>
            <button type="button" id="ct-guardar" class="rounded-full bg-terracota px-6 py-2.5 font-body text-sm font-semibold text-crema-light shadow-card hover:bg-terracota-dark"><?= $cert ? 'Guardar cambios' : 'Guardar y ver certificado' ?></button>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var P = window.__PANEL__ || {};
    var PROFS = <?= json_encode($profs, JSON_UNESCAPED_UNICODE) ?>;
    var AGENDA = <?= json_encode($agenda, JSON_UNESCAPED_UNICODE) ?>;
    var SES = <?= json_encode(array_values($v['sesiones_arr'] ?? []), JSON_UNESCAPED_UNICODE) ?>;
    var ES_NUEVO = <?= $cert ? 'false' : 'true' ?>;
    var f = document.getElementById('cert-form');
    var tb = document.getElementById('ct-ses');
    var pago = document.getElementById('ct-pago');
    var valor = document.getElementById('ct-valor');
    var cierre = document.getElementById('ct-cierre');
    var MESES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];

    function clp(n) { return '$' + (parseInt(n, 10) || 0).toLocaleString('es-CL'); }
    function fechaLarga(ymd) { var p = (ymd || '').split('-'); return p.length === 3 ? p[2] + ' de ' + MESES[parseInt(p[1], 10) - 1] + ' de ' + p[0] : ''; }

    function fila(s) {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td class="px-3 py-2 font-semibold text-cafe ct-n whitespace-nowrap"></td>' +
            '<td class="px-3 py-2"><input type="date" class="ct-f w-full rounded-lg border border-cafe/20 bg-crema-light px-2 py-1.5 font-body text-sm text-cafe outline-none focus:border-terracota"><div class="ct-fl mt-0.5 text-[11px] text-cafe/45"></div></td>' +
            '<td class="px-3 py-2 text-right ct-col-valor"><input type="number" min="0" step="500" class="ct-v w-28 rounded-lg border border-cafe/20 bg-crema-light px-2 py-1.5 text-right font-body text-sm text-cafe outline-none focus:border-terracota"></td>' +
            '<td class="px-2 py-2 text-right"><button type="button" class="ct-del px-1 text-lg leading-none text-red-500 hover:text-red-700" aria-label="Quitar sesión">&times;</button></td>';
        tr.querySelector('.ct-f').value = s.fecha || '';
        tr.querySelector('.ct-v').value = (s.valor != null && s.valor !== '') ? s.valor : (valor.value || 0);
        tr.querySelectorAll('input').forEach(function (i) { i.addEventListener('input', recalc); });
        tr.querySelector('.ct-del').addEventListener('click', function () { tr.remove(); recalc(); });
        tb.appendChild(tr);
    }
    function datos() {
        return Array.prototype.map.call(tb.querySelectorAll('tr'), function (tr) {
            return { fecha: tr.querySelector('.ct-f').value, valor: parseInt(tr.querySelector('.ct-v').value || 0, 10) };
        });
    }
    function recalc() {
        var total = 0, n = 0;
        tb.querySelectorAll('tr').forEach(function (tr, i) {
            tr.querySelector('.ct-n').textContent = 'Sesión ' + (i + 1);
            tr.querySelector('.ct-fl').textContent = fechaLarga(tr.querySelector('.ct-f').value);
            n++; total += parseInt(tr.querySelector('.ct-v').value || 0, 10) || 0;
        });
        var conPago = pago.checked;
        document.querySelectorAll('.ct-col-valor').forEach(function (el) { el.style.display = conPago ? '' : 'none'; });
        document.getElementById('ct-valor-wrap').style.display = conPago ? '' : 'none';
        document.getElementById('ct-total-box').style.display = conPago ? '' : 'none';
        document.getElementById('ct-total-lbl').textContent = 'Total correspondiente a ' + n + (n === 1 ? ' sesión' : ' sesiones');
        document.getElementById('ct-total').textContent = clp(total);
    }

    // Profesional -> especialidad y firma (solo rellena campos vacíos o recién elegidos).
    var selProf = document.getElementById('ct-prof');
    var URL_PROFS = <?= json_encode(base_path('/admin/profesionales')) ?>;
    var ES_ADMIN = <?= isAdmin() ? 'true' : 'false' ?>;
    // Vista previa de la firma y timbre del profesional elegido.
    function mostrarFirma(p) {
        var img = document.getElementById('ct-firma-img'), vac = document.getElementById('ct-firma-vacia'), msg = document.getElementById('ct-firma-msg');
        if (p && p.firma) {
            img.src = p.firma; img.classList.remove('hidden'); vac.classList.add('hidden');
            msg.className = 'font-body text-xs text-cafe/60'; msg.textContent = 'Se coloca automáticamente sobre la línea de firma.';
        } else {
            img.removeAttribute('src'); img.classList.add('hidden'); vac.classList.remove('hidden');
            msg.className = 'font-body text-xs font-medium text-amber-700';
            msg.innerHTML = p ? ('Este profesional aún no tiene firma y timbre cargados; el certificado saldrá sin ella.' + (ES_ADMIN ? ' <a class="underline" target="_blank" href="' + URL_PROFS + '">Subirla en Profesionales</a>.' : '')) : 'Elige el profesional para ver su firma.';
        }
    }
    selProf.addEventListener('change', function () {
        var p = PROFS.filter(function (x) { return String(x.id) === selProf.value; })[0];
        if (!p) return;
        f.especialidad.value = p.especialidad || f.especialidad.value;
        f.firmante_nombre.value = p.firmante_nombre || p.nombre;
        f.firmante_titulo.value = p.firmante_titulo || p.titulo || '';
        f.firmante_detalle.value = p.firmante_detalle || '';
        f.firmante_rut.value = p.rut || '';
        mostrarFirma(p);
        if (p.modalidad) f.modalidad.value = p.modalidad;
    });

    // Cierre por defecto según el trato (si el usuario no lo personalizó).
    function cierreDef(t) { return 'Se extiende el presente certificado a solicitud ' + (t === 'm' ? 'del interesado' : 'de la interesada') + ', para los fines que estime convenientes.'; }
    var CIERRE_ANTIGUO = 'Se extiende el presente certificado a solicitud de el interesado, para los fines que estime convenientes.';
    f.querySelectorAll('[name=trato]').forEach(function (r) {
        r.addEventListener('change', function () {
            var actual = cierre.value.trim();
            if (actual === '' || actual === cierreDef('f') || actual === cierreDef('m') || actual === CIERRE_ANTIGUO) cierre.value = cierreDef(r.value);
        });
    });

    // Ficha sin sexo: Mujer/Hombre fijan el trato; Otro muestra la elección «la/el paciente».
    f.querySelectorAll('[name=sexo_paciente]').forEach(function (r) {
        r.addEventListener('change', function () {
            var otro = document.getElementById('ct-trato-otro');
            otro.classList.toggle('hidden', r.value !== 'otro'); otro.classList.toggle('grid', r.value === 'otro');
            f.querySelectorAll('[name=trato]').forEach(function (t) { t.checked = false; });
            var auto = f.querySelector('[data-trato-auto="' + r.value + '"]');
            if (auto) { auto.checked = true; auto.dispatchEvent(new Event('change')); }
        });
    });

    // Traer sesiones del mes desde la agenda del paciente (filtra por profesional si hay uno elegido).
    document.getElementById('ct-agenda').addEventListener('click', function () {
        var mes = document.getElementById('ct-mes').value, prof = selProf.value;
        var msg = document.getElementById('ct-agenda-msg');
        var ya = datos().map(function (s) { return s.fecha; });
        var delMes = AGENDA.filter(function (a) { return a.fecha.slice(0, 7) === mes && (!prof || String(a.prof) === prof) && ya.indexOf(a.fecha) === -1; });
        // Con «certificar el pago», solo entran las sesiones que ya tienen boleta (con lo que se cobró).
        var conPago = pago.checked;
        var sinBoleta = conPago ? delMes.filter(function (a) { return !a.pagada; }).length : 0;
        var nuevas = delMes.filter(function (a) { return !conPago || a.pagada; }).sort(function (a, b) { return a.fecha < b.fecha ? -1 : 1; });
        // Quita filas vacías antes de agregar.
        tb.querySelectorAll('tr').forEach(function (tr) { if (!tr.querySelector('.ct-f').value) tr.remove(); });
        nuevas.forEach(function (a) { fila({ fecha: a.fecha, valor: conPago && a.valor ? a.valor : undefined }); });
        msg.className = 'mt-2 font-body text-xs ' + (nuevas.length && !sinBoleta ? 'text-salvia-dark' : 'text-amber-700');
        msg.textContent = (nuevas.length ? ('Se agregaron ' + nuevas.length + ' sesión(es) desde la agenda.') : ('No hay horas en la agenda para ese mes' + (prof ? ' con ese profesional' : '') + ' que no estén ya en la lista.'))
            + (sinBoleta ? ' ' + sinBoleta + ' sesión(es) sin boleta no se agregaron: para certificar su pago, emite primero su boleta.' : '');
        if (!tb.children.length) fila({});
        recalc();
    });

    document.getElementById('ct-add').addEventListener('click', function () {
        var d = datos(), ult = d.length ? d[d.length - 1] : null;
        fila({ valor: ult ? ult.valor : valor.value });
        recalc();
    });
    document.getElementById('ct-aplicar').addEventListener('click', function () {
        tb.querySelectorAll('.ct-v').forEach(function (i) { i.value = valor.value; }); recalc();
    });
    pago.addEventListener('change', recalc);

    // Firma inicial: la del certificado (si ya tiene) o la del profesional seleccionado.
    if (!document.getElementById('ct-firma-img').getAttribute('src')) {
        mostrarFirma(PROFS.filter(function (x) { return String(x.id) === selProf.value; })[0] || null);
    }
    // RUT del firmante vacío pero profesional con RUT: completarlo.
    (function () { var p = PROFS.filter(function (x) { return String(x.id) === selProf.value; })[0]; if (p && p.rut && !f.firmante_rut.value) f.firmante_rut.value = p.rut; })();

    if (SES.length) SES.forEach(fila); else fila({});
    if (ES_NUEVO && !cierre.value.trim()) { var t = f.querySelector('[name=trato]:checked'); if (t) cierre.value = cierreDef(t.value); }
    recalc();

    document.getElementById('ct-guardar').addEventListener('click', function () {
        var err = document.getElementById('ct-err'); err.classList.add('hidden');
        var trato = f.querySelector('[name=trato]:checked');
        var body = {
            action: 'guardar', id: f.querySelector('[name=id]').value, paciente_id: f.querySelector('[name=paciente_id]').value,
            profesional_id: selProf.value, especialidad: f.especialidad.value, fecha_documento: f.fecha_documento.value, ciudad: f.ciudad.value,
            trato: trato ? trato.value : '', sexo_paciente: (f.querySelector('[name=sexo_paciente]:checked') || {}).value || '', paciente_nombre: f.paciente_nombre.value, paciente_rut: f.paciente_rut.value,
            paciente_domicilio: f.paciente_domicilio.value, modalidad: f.modalidad.value, certifica_pago: pago.checked ? 1 : 0,
            sesiones: datos().filter(function (s) { return s.fecha; }),
            texto_cierre: cierre.value, observaciones: f.observaciones.value,
            firmante_nombre: f.firmante_nombre.value, firmante_titulo: f.firmante_titulo.value, firmante_detalle: f.firmante_detalle.value,
            firmante_rut: f.firmante_rut.value
        };
        var b = document.getElementById('ct-guardar'), old = b.textContent; b.disabled = true; b.textContent = 'Guardando…';
        fetch(P.base + '/api/panel/certificados', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf }, body: JSON.stringify(body) })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, d: j }; }); })
            .then(function (x) {
                if (!x.ok) { err.textContent = x.d.error || 'No se pudo guardar.'; err.classList.remove('hidden'); b.disabled = false; b.textContent = old; err.scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
                location.href = P.base + '/admin/certificados/' + x.d.id;
            })
            .catch(function () { err.textContent = 'No se pudo conectar con el servidor.'; err.classList.remove('hidden'); b.disabled = false; b.textContent = old; });
    });
});
</script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
