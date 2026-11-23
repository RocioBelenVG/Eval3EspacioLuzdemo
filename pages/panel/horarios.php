<?php
/**
 * Panel · Días y horarios (solo admin).
 *  - Horario del centro: qué días atiende y desde/hasta qué hora (límite para tomar horas).
 *  - Horario de cada profesional: qué días y en qué franjas atiende (fuera de él se avisa al agendar).
 */
declare(strict_types=1);

requireAdminPage();
require_once APP_ROOT . '/lib/panel.php';
require_once APP_ROOT . '/lib/horario.php';

$centro = horarioCentro();
$profs = [];
try {
    $st = db()->query("SELECT p.id, p.nombre, p.socia, e.nombre AS especialidad FROM profesionales p
                        LEFT JOIN especialidades e ON e.slug = p.especialidad_slug
                        WHERE p.activo = 1 AND p.eliminado_at IS NULL ORDER BY p.orden, p.id");
    $profs = $st->fetchAll();
} catch (Throwable $e) {
}
$horasIni = array_map(static fn($h) => sprintf('%02d:00', $h), range(8, 20));
$horasFin = array_map(static fn($h) => sprintf('%02d:00', $h), range(9, 21));
$datosProf = [];
foreach ($profs as $p) {
    $hp = horarioProfesional((int) $p['id']);
    $datosProf[(int) $p['id']] = ['id' => (int) $p['id'], 'nombre' => $p['nombre'], 'franjas' => (object) $hp];
}

$panelTitle = 'Días y horarios';
$panelActive = 'horarios';
include APP_ROOT . '/partials/panel/top.php';
$SEL = 'rounded-lg border border-cafe/20 bg-white px-2.5 py-1.5 font-body text-sm text-cafe outline-none focus:border-terracota disabled:opacity-40';
// Barra visual 08:00–21:00 (13 bloques) para un rango.
$barra = static function (?string $ini, ?string $fin, string $color = 'bg-terracota/70'): string {
    $out = '<div class="flex h-2 w-full overflow-hidden rounded-full bg-cafe/8" aria-hidden="true">';
    for ($h = 8; $h <= 20; $h++) {
        $b = sprintf('%02d:00', $h);
        $on = $ini !== null && $b >= $ini && $b < $fin;
        $out .= '<span class="h-full flex-1 ' . ($on ? $color : '') . ($h > 8 ? ' border-l border-crema-light' : '') . '"></span>';
    }
    return $out . '</div>';
};
?>
<p class="mb-5 max-w-3xl font-body text-sm text-cafe/60">Aquí se define cuándo atiende el centro y cuándo atiende cada profesional. Los calendarios, la toma de horas, el PDF para imprimir y el Panel general usan esta información. Los <strong>feriados</strong> se marcan como trabajados o no en el calendario (botón <a class="font-semibold text-terracota-dark hover:underline" href="<?= e(base_path('/admin/calendario-boxes')) ?>">Feriados</a>).</p>

<!-- Horario del centro -->
<section class="rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card sm:p-6" aria-labelledby="hc-tt">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 id="hc-tt" class="font-heading text-xl font-semibold text-cafe">Horario del centro</h2>
            <p class="mt-1 font-body text-xs text-cafe/55">Fuera de este horario no se pueden tomar horas en ningún box. Actual: <strong id="hc-resumen" class="text-cafe/80"><?= e(horarioCentroResumen()) ?></strong></p>
        </div>
    </div>
    <div class="mt-4 overflow-x-auto">
        <table class="w-full min-w-[620px] font-body text-sm" id="hc-tabla">
            <thead><tr class="text-left text-[11px] font-semibold uppercase tracking-wide text-cafe/50">
                <th class="py-2 pr-3">Día</th><th class="py-2 pr-3">Atiende</th><th class="py-2 pr-3">Desde</th><th class="py-2 pr-3">Hasta</th><th class="w-[38%] py-2">08:00 · · · · · · · · · · · · 21:00</th>
            </tr></thead>
            <tbody class="divide-y divide-cafe/8">
            <?php for ($d = 1; $d <= 6; $d++): $c = $centro[$d]; ?>
                <tr data-dia="<?= $d ?>">
                    <td class="py-2.5 pr-3 font-semibold text-cafe"><?= HORARIO_DIAS[$d] ?></td>
                    <td class="py-2.5 pr-3">
                        <label class="inline-flex cursor-pointer items-center gap-2">
                            <input type="checkbox" class="hc-abierto peer sr-only" <?= $c['abierto'] ? 'checked' : '' ?>>
                            <span class="relative h-5 w-9 rounded-full bg-cafe/20 transition-colors after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:bg-salvia-dark peer-checked:after:translate-x-4"></span>
                            <span class="hc-estado text-xs font-semibold <?= $c['abierto'] ? 'text-salvia-dark' : 'text-cafe/45' ?>"><?= $c['abierto'] ? 'Sí' : 'No atiende' ?></span>
                        </label>
                    </td>
                    <td class="py-2.5 pr-3"><select class="hc-ini <?= $SEL ?>" <?= $c['abierto'] ? '' : 'disabled' ?>><?php foreach ($horasIni as $h): ?><option <?= $h === $c['ini'] ? 'selected' : '' ?>><?= $h ?></option><?php endforeach; ?></select></td>
                    <td class="py-2.5 pr-3"><select class="hc-fin <?= $SEL ?>" <?= $c['abierto'] ? '' : 'disabled' ?>><?php foreach ($horasFin as $h): ?><option <?= $h === $c['fin'] ? 'selected' : '' ?>><?= $h ?></option><?php endforeach; ?></select></td>
                    <td class="py-2.5 hc-barra"><?= $barra($c['abierto'] ? $c['ini'] : null, $c['fin']) ?></td>
                </tr>
            <?php endfor; ?>
                <tr class="text-cafe/45">
                    <td class="py-2.5 pr-3 font-semibold">Domingo</td>
                    <td class="py-2.5 pr-3 text-xs font-semibold" colspan="3">Cerrado siempre</td>
                    <td class="py-2.5"><?= $barra(null, null) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="mt-4 flex flex-wrap items-center justify-end gap-3">
        <p id="hc-msg" class="hidden font-body text-sm"></p>
        <button type="button" id="hc-guardar" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light shadow-card hover:bg-terracota-dark disabled:opacity-50">Guardar horario del centro</button>
    </div>
</section>

<!-- Horario de cada profesional -->
<section class="mt-6 rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card sm:p-6" aria-labelledby="hp-tt">
    <h2 id="hp-tt" class="font-heading text-xl font-semibold text-cafe">Horario de cada profesional</h2>
    <p class="mt-1 max-w-3xl font-body text-xs text-cafe/55">Días y franjas en que atiende cada profesional. Si se le agenda una hora fuera de su horario, el sistema avisa y pide confirmar (sirve para excepciones). Sin horario definido, se le puede agendar en todo el horario del centro. Los boxes siguen disponibles para otras profesionales.</p>
    <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
        <?php foreach ($profs as $p): $pid = (int) $p['id']; $res = horarioProfesionalResumen($pid); ?>
            <article id="prof-<?= $pid ?>" class="flex min-w-0 flex-col gap-3 rounded-xl border border-cafe/10 bg-white/70 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="truncate font-body text-sm font-semibold text-cafe"><?= e($p['nombre']) ?><?= (int) $p['socia'] === 1 ? ' <span class="ml-1 rounded-full bg-terracota/12 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-terracota-dark">Socia</span>' : '' ?></h3>
                        <p class="font-body text-xs text-cafe/50"><?= e((string) ($p['especialidad'] ?? '')) ?></p>
                    </div>
                    <button type="button" class="hp-editar shrink-0 rounded-full border-2 border-cafe/15 px-3 py-1 font-body text-xs font-semibold text-cafe hover:border-terracota hover:text-terracota" data-id="<?= $pid ?>">Editar horario</button>
                </div>
                <div class="grid grid-cols-6 gap-1.5">
                    <?php for ($d = 1; $d <= 6; $d++): $txt = horarioProfesionalTextoDia($pid, $d); $sin = $res === null; ?>
                        <div class="min-w-0 rounded-lg px-1.5 py-1 text-center font-body <?= $sin ? 'bg-cafe/5 text-cafe/40' : ($txt !== null ? 'bg-salvia/15 text-salvia-dark' : 'bg-cafe/5 text-cafe/35') ?>" title="<?= e(HORARIO_DIAS[$d] . ': ' . ($sin ? 'sin horario definido' : ($txt ?? 'no atiende'))) ?>">
                            <div class="text-[10px] font-bold uppercase tracking-wide"><?= HORARIO_DIAS_CORTO[$d] ?></div>
                            <div class="truncate text-[10px] leading-tight"><?= $sin ? '—' : ($txt !== null ? e(str_replace([' a ', ':00'], ['–', ''], $txt)) : 'no') ?></div>
                        </div>
                    <?php endfor; ?>
                </div>
                <p class="font-body text-xs <?= $res === null ? 'text-amber-700' : 'text-cafe/60' ?>"><?= $res === null ? 'Sin horario definido: se puede agendar en todo el horario del centro.' : e($res) ?></p>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<!-- Modal horario de una profesional -->
<div id="hp-modal" class="fixed inset-0 z-[60] hidden items-center justify-center overflow-y-auto bg-cafe/50 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="hp-titulo">
    <div class="my-auto w-full max-w-2xl rounded-2xl bg-crema-light p-6 shadow-warm">
        <p class="font-body text-[11px] font-bold uppercase tracking-[0.14em] text-terracota-dark">Horario de atención</p>
        <h3 id="hp-titulo" class="font-heading text-xl font-semibold text-cafe"></h3>
        <p class="mt-1 font-body text-xs text-cafe/55">Marca los días en que atiende y sus franjas (por ejemplo 09:00 a 13:00 y 15:00 a 19:00). El horario del centro es <span class="font-semibold"><?= e(horarioCentroResumen()) ?></span>.</p>
        <div id="hp-dias" class="mt-4 divide-y divide-cafe/8"></div>
        <div class="mt-3 flex flex-wrap gap-2 font-body text-xs">
            <button type="button" id="hp-copiar" class="rounded-full border border-cafe/15 px-3 py-1 font-semibold text-cafe hover:border-terracota hover:text-terracota">Copiar el lunes a los días marcados</button>
            <button type="button" id="hp-limpiar" class="rounded-full border border-cafe/15 px-3 py-1 font-semibold text-cafe/70 hover:border-terracota hover:text-terracota">Dejar sin horario definido</button>
        </div>
        <p id="hp-err" class="mt-3 hidden font-body text-sm font-medium text-red-600"></p>
        <div class="mt-5 flex justify-end gap-2 font-body">
            <button type="button" data-hp-cerrar class="rounded-full border-2 border-cafe/15 px-4 py-2 text-sm font-semibold text-cafe hover:border-terracota">Cancelar</button>
            <button type="button" id="hp-guardar" class="rounded-full bg-terracota px-5 py-2 text-sm font-semibold text-crema-light hover:bg-terracota-dark disabled:opacity-50">Guardar horario</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var P = window.__PANEL__ || {};
    var DIAS = <?= json_encode(array_slice(HORARIO_DIAS, 0, 6, true), JSON_UNESCAPED_UNICODE) ?>;
    var INI = <?= json_encode($horasIni) ?>, FIN = <?= json_encode($horasFin) ?>;
    var PROFS = <?= json_encode($datosProf, JSON_UNESCAPED_UNICODE) ?>;
    var SEL = <?= json_encode($SEL) ?>;
    function $(id) { return document.getElementById(id); }
    function confirmar(msg) { return window.ezConfirm ? window.ezConfirm(msg, { title: 'Horas ya tomadas', okText: 'Guardar igual', cancelText: 'Volver' }) : Promise.resolve(window.confirm(msg)); }
    function post(d) {
        return fetch(P.base + '/api/panel/horarios', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf }, body: JSON.stringify(d) })
            .then(function (r) { return r.json().catch(function () { return { error: 'Respuesta inesperada del servidor.' }; }).then(function (j) { return { ok: r.ok, d: j }; }); });
    }
    function enviar(d) {   // con confirmación si hay horas ya tomadas que quedan fuera
        return post(d).then(function (x) {
            if (x.ok && x.d.needs_confirm) return confirmar(x.d.mensaje).then(function (si) { if (!si) return null; d.force = 1; return enviar(d); });
            return x;
        });
    }
    function opciones(lista, sel) { return lista.map(function (h) { return '<option' + (h === sel ? ' selected' : '') + '>' + h + '</option>'; }).join(''); }

    /* ---- Horario del centro ---- */
    function barra(ini, fin) {
        var s = '<div class="flex h-2 w-full overflow-hidden rounded-full bg-cafe/8" aria-hidden="true">';
        for (var h = 8; h <= 20; h++) { var b = (h < 10 ? '0' : '') + h + ':00'; var on = ini && b >= ini && b < fin; s += '<span class="h-full flex-1 ' + (on ? 'bg-terracota/70' : '') + (h > 8 ? ' border-l border-crema-light' : '') + '"></span>'; }
        return s + '</div>';
    }
    document.querySelectorAll('#hc-tabla tr[data-dia]').forEach(function (tr) {
        var ab = tr.querySelector('.hc-abierto'), ini = tr.querySelector('.hc-ini'), fin = tr.querySelector('.hc-fin');
        function pintar() {
            ini.disabled = fin.disabled = !ab.checked;
            var est = tr.querySelector('.hc-estado'); est.textContent = ab.checked ? 'Sí' : 'No atiende';
            est.className = 'hc-estado text-xs font-semibold ' + (ab.checked ? 'text-salvia-dark' : 'text-cafe/45');
            tr.querySelector('.hc-barra').innerHTML = barra(ab.checked && fin.value > ini.value ? ini.value : null, fin.value);
        }
        [ab, ini, fin].forEach(function (el) { el.addEventListener('change', pintar); });
    });
    $('hc-guardar').addEventListener('click', function () {
        var dias = {}, err = '';
        document.querySelectorAll('#hc-tabla tr[data-dia]').forEach(function (tr) {
            var d = tr.dataset.dia, ab = tr.querySelector('.hc-abierto').checked, i = tr.querySelector('.hc-ini').value, f = tr.querySelector('.hc-fin').value;
            if (ab && f <= i) err = DIAS[d] + ': la hora de término debe ser posterior a la de inicio.';
            dias[d] = { abierto: ab ? 1 : 0, ini: i, fin: f };
        });
        var msg = $('hc-msg');
        if (err) { msg.textContent = err; msg.className = 'font-body text-sm text-red-600'; return; }
        var b = this; b.disabled = true; msg.className = 'hidden';
        enviar({ action: 'centro', dias: dias }).then(function (x) {
            b.disabled = false;
            if (!x) return;
            if (!x.ok) { msg.textContent = x.d.error || 'No se pudo guardar.'; msg.className = 'font-body text-sm text-red-600'; return; }
            $('hc-resumen').textContent = x.d.resumen;
            msg.textContent = '✓ Horario guardado. Ya se aplica en los calendarios.'; msg.className = 'font-body text-sm font-semibold text-salvia-dark';
        }).catch(function () { b.disabled = false; msg.textContent = 'Sin conexión. Intenta de nuevo.'; msg.className = 'font-body text-sm text-red-600'; });
    });

    /* ---- Horario de una profesional ---- */
    var M = $('hp-modal'), actual = null;
    function filaFranja(f) {
        return '<div class="hp-franja flex items-center gap-1.5"><select class="hp-i ' + SEL + '">' + opciones(INI, f.ini) + '</select><span class="text-xs text-cafe/50">a</span><select class="hp-f ' + SEL + '">' + opciones(FIN, f.fin) + '</select>' +
            '<button type="button" class="hp-quitar rounded-md px-1.5 text-lg leading-none text-cafe/40 hover:bg-red-50 hover:text-red-600" aria-label="Quitar franja">&times;</button></div>';
    }
    function filaDia(d, lista) {
        var on = lista && lista.length;
        return '<div class="hp-dia flex flex-wrap items-start gap-x-4 gap-y-2 py-2.5" data-dia="' + d + '">' +
            '<label class="flex w-32 cursor-pointer items-center gap-2 pt-1.5 font-body text-sm font-semibold text-cafe"><input type="checkbox" class="hp-on h-4 w-4 accent-[#D97757]"' + (on ? ' checked' : '') + '> ' + DIAS[d] + '</label>' +
            '<div class="hp-franjas flex min-w-0 flex-1 flex-col gap-1.5' + (on ? '' : ' hidden') + '">' + (on ? lista : [{ ini: '09:00', fin: '18:00' }]).map(filaFranja).join('') +
            '<button type="button" class="hp-mas self-start font-body text-xs font-semibold text-terracota-dark hover:underline">+ Agregar franja</button></div>' +
            '<span class="hp-no pt-1.5 font-body text-xs text-cafe/45' + (on ? ' hidden' : '') + '">No atiende</span></div>';
    }
    function abrir(id) {
        actual = PROFS[id]; $('hp-titulo').textContent = actual.nombre; $('hp-err').classList.add('hidden');
        var h = ''; for (var d = 1; d <= 6; d++) h += filaDia(d, (actual.franjas || {})[d] || []);
        $('hp-dias').innerHTML = h; M.classList.remove('hidden'); M.classList.add('flex');
    }
    function cerrar() { M.classList.add('hidden'); M.classList.remove('flex'); }
    document.querySelectorAll('.hp-editar').forEach(function (b) { b.addEventListener('click', function () { abrir(b.dataset.id); }); });
    M.querySelectorAll('[data-hp-cerrar]').forEach(function (b) { b.addEventListener('click', cerrar); });
    M.addEventListener('click', function (e) { if (e.target === M) cerrar(); });
    $('hp-dias').addEventListener('change', function (e) {
        if (!e.target.classList.contains('hp-on')) return;
        var row = e.target.closest('.hp-dia');
        row.querySelector('.hp-franjas').classList.toggle('hidden', !e.target.checked); row.querySelector('.hp-no').classList.toggle('hidden', e.target.checked);
    });
    $('hp-dias').addEventListener('click', function (e) {
        var row = e.target.closest('.hp-dia'); if (!row) return;
        if (e.target.classList.contains('hp-mas')) {
            if (row.querySelectorAll('.hp-franja').length >= 4) return;
            e.target.insertAdjacentHTML('beforebegin', filaFranja({ ini: '15:00', fin: '18:00' }));
        } else if (e.target.classList.contains('hp-quitar')) {
            var fr = row.querySelectorAll('.hp-franja');
            if (fr.length > 1) e.target.closest('.hp-franja').remove(); else { row.querySelector('.hp-on').checked = false; row.querySelector('.hp-on').dispatchEvent(new Event('change', { bubbles: true })); }
        }
    });
    $('hp-copiar').addEventListener('click', function () {
        var lun = document.querySelector('.hp-dia[data-dia="1"]'); if (!lun.querySelector('.hp-on').checked) return;
        var fr = [].map.call(lun.querySelectorAll('.hp-franja'), function (x) { return { ini: x.querySelector('.hp-i').value, fin: x.querySelector('.hp-f').value }; });
        document.querySelectorAll('.hp-dia').forEach(function (row) {
            if (row.dataset.dia === '1' || !row.querySelector('.hp-on').checked) return;
            var cont = row.querySelector('.hp-franjas'); cont.querySelectorAll('.hp-franja').forEach(function (x) { x.remove(); });
            cont.querySelector('.hp-mas').insertAdjacentHTML('beforebegin', fr.map(filaFranja).join(''));
        });
    });
    $('hp-limpiar').addEventListener('click', function () {
        document.querySelectorAll('.hp-on').forEach(function (c) { c.checked = false; c.dispatchEvent(new Event('change', { bubbles: true })); });
    });
    $('hp-guardar').addEventListener('click', function () {
        var franjas = {}, err = '';
        document.querySelectorAll('.hp-dia').forEach(function (row) {
            if (!row.querySelector('.hp-on').checked) return;
            var d = row.dataset.dia;
            franjas[d] = [].map.call(row.querySelectorAll('.hp-franja'), function (x) { var f = { ini: x.querySelector('.hp-i').value, fin: x.querySelector('.hp-f').value }; if (f.fin <= f.ini) err = DIAS[d] + ': cada franja debe terminar después de empezar.'; return f; });
        });
        var e2 = $('hp-err');
        if (err) { e2.textContent = err; e2.classList.remove('hidden'); return; }
        var b = this; b.disabled = true; e2.classList.add('hidden');
        enviar({ action: 'profesional', id: actual.id, franjas: franjas }).then(function (x) {
            b.disabled = false; if (!x) return;
            if (!x.ok) { e2.textContent = x.d.error || 'No se pudo guardar.'; e2.classList.remove('hidden'); return; }
            location.hash = 'prof-' + actual.id; location.reload();
        }).catch(function () { b.disabled = false; e2.textContent = 'Sin conexión. Intenta de nuevo.'; e2.classList.remove('hidden'); });
    });
});
</script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
