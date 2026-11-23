<?php
/**
 * Calendario semanal reutilizable (boxes o profesionales).
 * Variables esperadas:
 *   $calModo ('box'|'prof'), $calRuta, $calOpciones (key=>label), $calSel,
 *   $calSemanaIni (Y-m-d lunes), $calGrilla (reservasGrilla), $calBoxes, $calProfesionales.
 */
declare(strict_types=1);
require_once APP_ROOT . '/lib/calendario_pdf.php';   // límites del PDF (no carga dompdf)

$horas = horasAgenda();
$dias = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
$ini = new DateTimeImmutable($calSemanaIni);
$fechas = [];
for ($i = 0; $i < 7; $i++) {
    $fechas[] = $ini->modify("+$i days");
}
$prevSem = $ini->modify('-7 days')->format('Y-m-d');
$nextSem = $ini->modify('+7 days')->format('Y-m-d');
$hoy = date('Y-m-d');
$mesLabel = mesAnioLabel($fechas[0]->format('Y-m-d'));
$calCupo = boxCupoMax();
// Domingos (siempre cerrados) y feriados de la semana: no trabajados = bloqueados; trabajados = aviso.
$calFer = feriadosEntre($fechas[0]->format('Y-m-d'), $fechas[6]->format('Y-m-d'));
$calCerrado = []; // fecha => motivo corto
foreach ($fechas as $f) {
    $fy = $f->format('Y-m-d');
    if ($f->format('N') === '7') {
        $calCerrado[$fy] = 'Domingo: el centro no atiende.';
    } elseif (!horarioDiaAbierto((int) $f->format('N'))) {
        $calCerrado[$fy] = 'Los ' . HORARIO_DIAS_PLURAL[(int) $f->format('N')] . ' el centro no atiende (se cambia en «Días y horarios»).';
    } elseif (isset($calFer[$fy]) && !$calFer[$fy]['se_trabaja']) {
        $calCerrado[$fy] = 'Feriado no trabajado (' . $calFer[$fy]['nombre'] . '). Si el centro atenderá ese día, márcalo como trabajado.';
    }
}
$calFerAviso = array_filter($calFer, fn($x) => !esDomingo($x['fecha']));
$calRayas = 'background-image: repeating-linear-gradient(135deg, rgba(92,64,51,.06) 0 6px, transparent 6px 12px);';
$calRayasFer = 'background-image: repeating-linear-gradient(135deg, rgba(139,92,246,.12) 0 6px, transparent 6px 12px);';
// Horario de la profesional (solo en el calendario de profesionales): fuera de él se avisa al agendar.
$calProfId = $calModo === 'prof' && $calSel !== '' ? (int) $calSel : 0;
$calProfHorario = $calProfId ? horarioProfesionalResumen($calProfId) : null;
$calSinAtencion = [];   // fechas con días sin atención por horario (no domingo, no feriado)
foreach ($fechas as $f) {
    if ($f->format('N') !== '7' && !horarioDiaAbierto((int) $f->format('N'))) $calSinAtencion[$f->format('Y-m-d')] = true;
}
?>
<div class="rounded-2xl border border-cafe/8 bg-crema-light p-4 shadow-card sm:p-5">
    <!-- Controles -->
    <form method="get" action="<?= e(base_path($calRuta)) ?>" class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <label class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60"><?= $calModo === 'box' ? 'Box' : 'Profesional' ?></label>
            <select name="<?= $calModo === 'box' ? 'box' : 'prof' ?>" onchange="this.form.submit()"
                class="rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm font-semibold text-cafe outline-none focus:border-terracota">
                <?php foreach ($calOpciones as $k => $label): ?>
                    <option value="<?= e((string) $k) ?>" <?= (string) $k === (string) $calSel ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="semana" value="<?= e($calSemanaIni) ?>">
        <div class="flex items-center gap-2">
            <span class="font-body text-sm font-semibold text-cafe"><?= e($mesLabel) ?></span>
            <a href="<?= e(base_path($calRuta) . '?' . ($calModo === 'box' ? 'box' : 'prof') . '=' . rawurlencode((string) $calSel) . '&semana=' . $prevSem) ?>" class="flex h-9 w-9 items-center justify-center rounded-full border border-cafe/15 text-cafe hover:border-terracota hover:text-terracota" aria-label="Semana anterior">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
            <a href="<?= e(base_path($calRuta) . '?' . ($calModo === 'box' ? 'box' : 'prof') . '=' . rawurlencode((string) $calSel)) ?>" class="rounded-full border border-cafe/15 px-3 py-1.5 font-body text-xs font-semibold text-cafe hover:border-terracota hover:text-terracota">Hoy</a>
            <a href="<?= e(base_path($calRuta) . '?' . ($calModo === 'box' ? 'box' : 'prof') . '=' . rawurlencode((string) $calSel) . '&semana=' . $nextSem) ?>" class="flex h-9 w-9 items-center justify-center rounded-full border border-cafe/15 text-cafe hover:border-terracota hover:text-terracota" aria-label="Semana siguiente">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
        </div>
    </form>

    <!-- Leyenda + gestión en bloque -->
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-4 font-body text-xs text-cafe/60">
            <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded border border-salvia/40 bg-salvia/20"></span> Disponible</span>
            <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded border border-amber-400/50 bg-amber-100"></span> Con cupo</span>
            <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded border border-terracota/35 bg-terracota/15"></span> <?= $calModo === 'box' ? 'Completo' : 'Tomada' ?></span>
            <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded border border-violet-300 bg-violet-100"></span> Feriado</span>
            <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded border border-cafe/15" style="<?= $calRayas ?>"></span> Cerrado</span>
            <?php if ($calProfHorario !== null): ?><span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded border border-dashed border-cafe/30 bg-white"></span> Fuera de su horario</span><?php endif; ?>
            <span class="text-cafe/45">· Atención: <?= e(horarioCentroResumen()) ?> · Máximo <?= $calCupo ?> reservas por box en la misma hora</span>
        </div>
        <div class="flex flex-wrap items-center gap-2">
        <button type="button" id="ez-cal-pdf"
            class="inline-flex items-center gap-2 rounded-full border-2 border-cafe/20 px-4 py-1.5 font-body text-xs font-semibold text-cafe transition-colors hover:border-terracota hover:text-terracota">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 14h12v7H6z"/></svg>
            Exportar PDF
        </button>
        <button type="button" id="ez-cal-feriados"
            class="inline-flex items-center gap-2 rounded-full border-2 border-violet-300 px-4 py-1.5 font-body text-xs font-semibold text-violet-800 transition-colors hover:bg-violet-50">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/><path d="M12 13l1.2 2.4 2.6.4-1.9 1.8.5 2.6-2.4-1.3-2.4 1.3.5-2.6-1.9-1.8 2.6-.4z"/></svg>
            Feriados
        </button>
        <button type="button" id="ez-cal-masivo"
            class="inline-flex items-center gap-2 rounded-full border-2 border-terracota/40 px-4 py-1.5 font-body text-xs font-semibold text-terracota-dark transition-colors hover:bg-terracota/5">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
            Cancelar o traspasar horas
        </button>
        </div>
    </div>

    <?php if ($calProfId): ?>
    <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border border-cafe/10 bg-white/70 px-4 py-2 font-body text-xs text-cafe/70">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-terracota"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        <span><strong class="text-cafe">Horario de atención:</strong> <?= $calProfHorario !== null ? e($calProfHorario) : 'sin horario definido (se puede agendar en todo el horario del centro)' ?></span>
        <?php if (isAdmin()): ?><a href="<?= e(base_path('/admin/horarios#prof-' . $calProfId)) ?>" class="font-semibold text-terracota-dark hover:underline">Cambiar →</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($calFerAviso): ?>
    <!-- Aviso de feriados de la semana -->
    <div class="mt-4 flex flex-col gap-2">
        <?php foreach ($calFerAviso as $fx): $nRes = reservasVigentesEnFecha($fx['fecha']); ?>
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-violet-300 bg-violet-50 px-4 py-3">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-violet-200/70 text-violet-800">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>
                    </span>
                    <div class="min-w-0 font-body">
                        <p class="text-sm font-semibold text-violet-900">Feriado: <?= e(ucfirst(fechaLarga($fx['fecha']))) ?> · <?= e($fx['nombre']) ?><?= $fx['irrenunciable'] ? ' <span class="ml-1 rounded-full bg-violet-200 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-violet-800">Irrenunciable</span>' : '' ?></p>
                        <p class="mt-0.5 text-xs text-violet-800/80">
                            <?php if ($fx['se_trabaja']): ?>
                                Marcado como <strong>día trabajado</strong>: se pueden tomar horas.
                            <?php else: ?>
                                Marcado como <strong>no trabajado</strong>: la toma de horas está bloqueada.<?php if ($nRes > 0): ?> <span class="font-semibold text-terracota-dark">Tiene <?= $nRes ?> hora<?= $nRes === 1 ? '' : 's' ?> tomada<?= $nRes === 1 ? '' : 's' ?>: reagéndala<?= $nRes === 1 ? '' : 's' ?> o cancéla<?= $nRes === 1 ? 'la' : 'las' ?>.</span><?php endif; ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <button type="button" class="ez-fer-toggle shrink-0 rounded-full <?= $fx['se_trabaja'] ? 'border-2 border-violet-300 text-violet-800 hover:bg-violet-100' : 'bg-violet-700 text-white hover:bg-violet-800' ?> px-4 py-1.5 font-body text-xs font-semibold transition-colors"
                    data-fecha="<?= e($fx['fecha']) ?>" data-trabaja="<?= $fx['se_trabaja'] ? '0' : '1' ?>">
                    <?= $fx['se_trabaja'] ? 'Marcar como no trabajado' : 'Marcar como trabajado' ?>
                </button>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Grilla -->
    <div class="mt-3 overflow-x-auto">
        <table class="w-full min-w-[720px] table-fixed border-separate" style="border-spacing: 4px;">
            <thead>
                <tr>
                    <th class="w-14"></th>
                    <?php foreach ($fechas as $i => $f): $fy = $f->format('Y-m-d'); $esHoy = $fy === $hoy; $fer = $calFer[$fy] ?? null; $esDom = $i === 6 || isset($calSinAtencion[$fy]); ?>
                        <th class="rounded-lg px-2 py-2 text-center align-top font-body <?= $fer && !$esDom ? 'bg-violet-100 ring-1 ring-violet-300' : ($esHoy ? 'bg-terracota/12' : ($esDom ? 'bg-cafe/5' : '')) ?>"
                            <?= $fer ? 'title="' . e('Feriado: ' . $fer['nombre'] . ($esDom ? '' : ($fer['se_trabaja'] ? ' · se trabaja' : ' · no se trabaja'))) . '"' : '' ?>>
                            <div class="text-xs font-semibold uppercase tracking-wide <?= $fer && !$esDom ? 'text-violet-700' : 'text-cafe/55' ?>"><?= $dias[$i] ?></div>
                            <div class="text-sm font-bold <?= $fer && !$esDom ? 'text-violet-900' : ($esHoy ? 'text-terracota-dark' : ($esDom ? 'text-cafe/40' : 'text-cafe')) ?>"><?= $f->format('j') ?></div>
                            <?php if ($fer && !$esDom): ?>
                                <div class="mx-auto mt-0.5 max-w-[9rem] truncate text-[10px] font-semibold text-violet-800"><?= e($fer['nombre']) ?></div>
                                <div class="mt-0.5 text-[9px] font-bold uppercase tracking-wide <?= $fer['se_trabaja'] ? 'text-salvia-dark' : 'text-violet-700' ?>"><?= $fer['se_trabaja'] ? 'Se trabaja' : 'No se trabaja' ?></div>
                            <?php elseif ($esDom): ?>
                                <div class="mt-0.5 text-[9px] font-bold uppercase tracking-wide text-cafe/40"><?= $i === 6 ? 'Cerrado' : 'Sin atención' ?><?= $fer ? ' · feriado' : '' ?></div>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($horas as $h): ?>
                    <tr>
                        <td class="pr-1 text-right align-middle font-body text-xs font-semibold text-cafe/45"><?= $h ?></td>
                        <?php foreach ($fechas as $f): $fecha = $f->format('Y-m-d'); $celda = $calGrilla[$fecha][$h] ?? null; ?>
                            <?php if ($celda): ?>
                                <?php
                                $rCount = count($celda);
                                $r0 = $celda[0];
                                $boxCelda = $calModo === 'box' ? (string) $calSel : (string) $r0['box_slug'];
                                $celProf = $r0['profesional_id'] ? nombreProfesional((int) $r0['profesional_id']) : '';
                                if ($calModo === 'box') {
                                    $celSub = $celProf !== '' ? $celProf : ($r0['modalidad_label'] ?: 'Arriendo');
                                } else {
                                    $celSub = nombreBox($r0['box_slug']);
                                }
                                ?>
                                <?php
                                // En el calendario de boxes se ve el cupo: ámbar si aún cabe otra reserva, terracota si está completo.
                                $conCupo = $calModo === 'box' && $rCount < $calCupo;
                                $tdCls = $conCupo ? 'border-amber-400/50 bg-amber-50 hover:bg-amber-100' : 'border-terracota/25 bg-terracota/10 hover:bg-terracota/20';
                                if (isset($calFer[$fecha])) {
                                    $tdCls .= ' ring-2 ring-violet-300';
                                }
                                ?>
                                <td class="ez-cal-cell cursor-pointer rounded-lg border px-2 py-1.5 align-top transition-colors <?= $tdCls ?>"
                                    data-taken="1" data-id="<?= (int) $r0['id'] ?>" data-box="<?= e($boxCelda) ?>" data-fecha="<?= e($fecha) ?>" data-hora="<?= e($h) ?>" data-count="<?= (int) $rCount ?>">
                                    <?php if ($calModo === 'box'):
                                        // Calendario de boxes: solo los profesionales que tomaron la hora (sin pacientes).
                                        $profsCelda = [];
                                        foreach ($celda as $rc) {
                                            $np = $rc['profesional_id'] ? nombreProfesional((int) $rc['profesional_id']) : '';
                                            $k = $np !== '' ? $np : '—';
                                            $profsCelda[$k] = ($profsCelda[$k] ?? 0) + 1;
                                        }
                                        $lineas = [];
                                        foreach ($profsCelda as $np => $veces) {
                                            $lineas[] = [$np, $veces];
                                        } ?>
                                        <div class="flex items-start justify-between gap-1">
                                            <?php [$np0, $v0] = $lineas[0]; ?>
                                            <span class="truncate font-body text-[11px] font-semibold <?= $np0 === '—' ? 'text-amber-700' : 'text-cafe' ?>"><?= $np0 === '—' ? 'Sin profesional' : e($np0) ?><?= $v0 > 1 ? ' <span class="font-normal text-cafe/45">×' . $v0 . '</span>' : '' ?></span>
                                            <span class="shrink-0 rounded-full px-1.5 font-body text-[9px] font-bold <?= $conCupo ? 'bg-amber-200/70 text-amber-800' : 'bg-terracota/25 text-terracota-dark' ?>" title="Reservas en esta hora / cupo del box"><?= (int) $rCount ?>/<?= $calCupo ?></span>
                                        </div>
                                        <?php if (isset($lineas[1])): [$np1, $v1] = $lineas[1]; ?>
                                            <div class="truncate font-body text-[11px] font-semibold <?= $np1 === '—' ? 'text-amber-700' : 'text-cafe' ?>"><?= $np1 === '—' ? 'Sin profesional' : e($np1) ?><?= $v1 > 1 ? ' <span class="font-normal text-cafe/45">×' . $v1 . '</span>' : '' ?></div>
                                        <?php else: ?>
                                            <div class="truncate font-body text-[10px] <?= $conCupo ? 'text-amber-700/80' : 'text-cafe/45' ?>"><?= $conCupo ? 'queda ' . ($calCupo - $rCount) . ' cupo' . ($calCupo - $rCount > 1 ? 's' : '') : 'box completo' ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                    <div class="flex items-start justify-between gap-1">
                                        <span class="truncate font-body text-[11px] font-semibold text-cafe"><?= e($r0['nombre']) ?></span>
                                        <?php if ($calModo === 'box'): ?>
                                            <span class="shrink-0 rounded-full px-1.5 font-body text-[9px] font-bold <?= $conCupo ? 'bg-amber-200/70 text-amber-800' : 'bg-terracota/25 text-terracota-dark' ?>" title="Reservas en esta hora / cupo del box"><?= (int) $rCount ?>/<?= $calCupo ?></span>
                                        <?php elseif ($rCount > 1): ?>
                                            <span class="shrink-0 rounded-full bg-terracota/25 px-1.5 font-body text-[9px] font-bold text-terracota-dark">+<?= (int) ($rCount - 1) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="truncate font-body text-[10px] text-cafe/55">
                                        <?php if ($calModo === 'box' && $celProf !== ''): ?>
                                            <span class="text-cafe/40">con</span> <?= e($celSub) ?><?php if ($rCount > 1): ?> <span class="text-cafe/40">y <?= (int) ($rCount - 1) ?> más</span><?php endif; ?>
                                        <?php else: ?>
                                            <?= e($celSub) ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                            <?php elseif (isset($calCerrado[$fecha])): $ferC = isset($calFer[$fecha]) && $f->format('N') !== '7'; ?>
                                <td class="ez-cal-cell cursor-not-allowed rounded-lg border <?= $ferC ? 'border-violet-200 bg-violet-50/60' : 'border-cafe/10 bg-cafe/5' ?> px-2 py-1.5 text-center align-middle"
                                    style="<?= $ferC ? $calRayasFer : $calRayas ?>" data-taken="0" data-cerrado="1" data-motivo="<?= e($calCerrado[$fecha]) ?>" data-fecha="<?= e($fecha) ?>" data-hora="<?= e($h) ?>" title="<?= e($calCerrado[$fecha]) ?>">
                                    <span class="font-body text-[10px] font-semibold <?= $ferC ? 'text-violet-700/70' : 'text-cafe/35' ?>"><?= $ferC ? 'feriado' : 'cerrado' ?></span>
                                </td>
                            <?php elseif (($fueraC = horaFueraHorarioMotivo($fecha, $h)) !== null): ?>
                                <td class="ez-cal-cell cursor-not-allowed rounded-lg border border-cafe/10 bg-cafe/5 px-2 py-1.5 text-center align-middle"
                                    style="<?= $calRayas ?>" data-taken="0" data-cerrado="1" data-motivo="<?= e($fueraC) ?>" data-fecha="<?= e($fecha) ?>" data-hora="<?= e($h) ?>" title="<?= e($fueraC) ?>">
                                    <span class="font-body text-[10px] font-semibold text-cafe/35">cerrado</span>
                                </td>
                            <?php elseif ($calProfId && !horarioProfesionalAtiende($calProfId, $fecha, $h)): ?>
                                <td class="ez-cal-cell cursor-pointer rounded-lg border border-dashed border-cafe/25 bg-white/60 px-2 py-1.5 text-center align-middle transition-colors hover:bg-amber-50"
                                    data-taken="0" data-fecha="<?= e($fecha) ?>" data-hora="<?= e($h) ?>" title="<?= e((string) horarioProfesionalAviso($calProfId, $fecha, $h) . ' Puedes agendar igual: se pedirá confirmación.') ?>">
                                    <span class="font-body text-[10px] font-semibold text-cafe/40">fuera de horario</span>
                                </td>
                            <?php elseif (isset($calFer[$fecha])): ?>
                                <td class="ez-cal-cell cursor-pointer rounded-lg border border-violet-300 bg-violet-50 px-2 py-1.5 text-center align-middle transition-colors hover:bg-violet-100"
                                    data-taken="0" data-fecha="<?= e($fecha) ?>" data-hora="<?= e($h) ?>" title="<?= e('Feriado trabajado: ' . $calFer[$fecha]['nombre']) ?>">
                                    <span class="font-body text-[10px] font-semibold text-violet-800">libre</span>
                                </td>
                            <?php else: ?>
                                <td class="ez-cal-cell cursor-pointer rounded-lg border border-salvia/30 bg-salvia/10 px-2 py-1.5 text-center align-middle transition-colors hover:bg-salvia/20"
                                    data-taken="0" data-fecha="<?= e($fecha) ?>" data-hora="<?= e($h) ?>">
                                    <span class="font-body text-[10px] font-semibold text-salvia-dark">libre</span>
                                </td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Datos para el JS del panel -->
<script id="ez-cal-config" type="application/json"><?= json_encode([
    'modo' => $calModo,
    'sel' => (string) $calSel,
    'cupo' => $calCupo,
    'hoy' => date('Y-m-d'),
    'horas' => array_values($horas),
    'feriados' => (object) array_map(fn($x) => ['nombre' => $x['nombre'], 'se_trabaja' => $x['se_trabaja'], 'fecha_larga' => fechaLarga($x['fecha'])], $calFerAviso),
    'boxes' => array_map(fn($b) => ['slug' => $b['slug'], 'nombre' => $b['nombre']], array_values($calBoxes)),
    'tipos' => array_map(fn($k, $n) => ['clave' => $k, 'nombre' => $n], array_keys(RESERVA_TIPOS_ATENCION), array_values(RESERVA_TIPOS_ATENCION)),
    'profesionales' => array_map(fn($id, $n) => ['id' => $id, 'nombre' => $n], array_keys($calProfesionales), array_values($calProfesionales)),
], JSON_UNESCAPED_UNICODE) ?></script>

<!-- Modal detalle de reserva -->
<div id="ez-cal-modal" class="fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto bg-cafe/50 p-4 backdrop-blur-sm">
    <div class="my-auto max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-crema-light p-6 shadow-warm">
        <div id="ez-cal-modal-body"></div>
    </div>
</div>

<?php
// Exportar el calendario en PDF (una página por semana; uno o todos los boxes/profesionales).
$cpSel = (string) $calSel;
$cpNombre = (string) ($calOpciones[$calModo === 'box' ? $cpSel : (int) $cpSel] ?? '');
$cpTodos = $calModo === 'box' ? 'Todos los boxes (' . count($calOpciones) . ')' : 'Todas las profesionales (' . count($calOpciones) . ')';
$cpFinSem = $ini->modify('+5 days')->format('Y-m-d');
$cpHasta = $ini->modify('+21 days')->format('Y-m-d');
?>
<div id="cp-modal" class="fixed inset-0 z-[65] hidden items-center justify-center bg-cafe/60 p-3 backdrop-blur-sm sm:p-6" role="dialog" aria-modal="true" aria-labelledby="cp-titulo">
    <div class="flex h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-crema-light shadow-warm">
        <div class="flex items-start justify-between gap-4 border-b border-cafe/10 px-5 py-4 sm:px-6">
            <div class="min-w-0">
                <p class="font-body text-[11px] font-bold uppercase tracking-[0.14em] text-terracota-dark">Exportar en PDF · versión para imprimir</p>
                <h3 id="cp-titulo" class="truncate font-heading text-xl font-semibold text-cafe"><?= $calModo === 'box' ? 'Calendario de Boxes' : 'Calendario de Profesionales' ?></h3>
            </div>
            <button type="button" data-cp-cerrar class="-mr-1 rounded-full p-2 text-cafe/50 hover:bg-cafe/5 hover:text-cafe" aria-label="Cerrar"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>
        <div class="flex min-h-0 flex-1 flex-col lg:flex-row">
            <div class="min-h-0 overflow-y-auto border-cafe/10 px-5 py-4 font-body text-sm text-cafe sm:px-6 lg:w-[360px] lg:shrink-0 lg:border-r">
                <span class="text-xs font-bold uppercase tracking-wide text-cafe/60"><?= $calModo === 'box' ? 'Boxes' : 'Profesionales' ?></span>
                <div class="mt-2 space-y-1.5">
                    <label class="flex cursor-pointer items-center gap-2"><input type="radio" name="cp-quien" value="<?= e($cpSel) ?>" class="accent-[#D97757]" checked> <span>Solo <strong class="font-semibold"><?= e($cpNombre) ?></strong></span></label>
                    <label class="flex cursor-pointer items-center gap-2"><input type="radio" name="cp-quien" value="todos" class="accent-[#D97757]"> <span><?= e($cpTodos) ?> <span class="block text-xs text-cafe/50">Juntos en un mismo calendario, cada <?= $calModo === 'box' ? 'box' : 'profesional' ?> con su color</span></span></label>
                </div>
                <span class="mt-5 block text-xs font-bold uppercase tracking-wide text-cafe/60">Período</span>
                <div class="mt-2 space-y-1.5">
                    <label class="flex cursor-pointer items-center gap-2"><input type="radio" name="cp-periodo" value="semana" class="accent-[#D97757]" checked> <span>Semana mostrada <span class="text-xs text-cafe/50">(<?= e((int) $ini->format('j') . ' al ' . (int) substr($cpFinSem, 8, 2) . ' · ' . mesAnioLabel($cpFinSem)) ?>)</span></span></label>
                    <label class="flex cursor-pointer items-center gap-2"><input type="radio" name="cp-periodo" value="rango" class="accent-[#D97757]"> Varias semanas</label>
                    <div id="cp-rango" class="ml-6 hidden grid-cols-2 gap-2">
                        <label class="flex flex-col gap-0.5 text-[11px] text-cafe/55">Desde la semana del<input type="date" id="cp-desde" value="<?= e($calSemanaIni) ?>" class="rounded-lg border border-cafe/20 bg-white px-2 py-1 text-sm text-cafe"></label>
                        <label class="flex flex-col gap-0.5 text-[11px] text-cafe/55">Hasta la semana del<input type="date" id="cp-hasta" value="<?= e($cpHasta) ?>" class="rounded-lg border border-cafe/20 bg-white px-2 py-1 text-sm text-cafe"></label>
                    </div>
                </div>
                <?php if ($calModo === 'prof'): ?>
                    <label id="cp-pac-wrap" class="mt-5 flex cursor-pointer items-start gap-2"><input type="checkbox" id="cp-pac" class="mt-0.5 h-4 w-4 accent-[#D97757]" checked> <span>Mostrar nombres de pacientes<span class="block text-xs text-cafe/50">Si lo desmarcas, cada hora aparece como «Hora agendada» (útil para dejarlo a la vista).</span></span></label>
                <?php endif; ?>
                <div class="mt-5 rounded-xl border border-cafe/10 bg-white/70 p-3 text-xs text-cafe/65">
                    <p id="cp-resumen" class="font-semibold text-cafe"></p>
                    <p class="mt-1">Carta horizontal · lunes a sábado, de 08:00 a 20:00 · una semana por página (máximo <?= CALPDF_MAX_SEMANAS ?> semanas).</p>
                </div>
                <p id="cp-aviso" class="mt-3 hidden rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800"></p>
            </div>
            <div class="flex min-h-0 flex-1 flex-col bg-cafe/5">
                <div class="relative min-h-0 flex-1">
                    <iframe id="cp-iframe" title="Vista previa del PDF" class="absolute inset-0 hidden h-full w-full bg-white lg:block"></iframe>
                    <div id="cp-cargando" class="pointer-events-none absolute inset-0 hidden items-center justify-center bg-white/60 font-body text-sm font-semibold text-cafe/60">Generando vista previa…</div>
                    <div class="flex h-full items-center justify-center p-6 text-center font-body text-sm text-cafe/55 lg:hidden">En el celular, usa «Ver PDF» para abrirlo a pantalla completa.</div>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-cafe/10 bg-white/60 px-5 py-3 font-body sm:px-6">
            <a id="cp-abrir" href="#" target="_blank" rel="noopener" class="rounded-full border-2 border-cafe/15 px-4 py-2 text-sm font-semibold text-cafe hover:border-terracota hover:text-terracota">Ver PDF</a>
            <a id="cp-descargar" href="#" class="inline-flex items-center gap-1.5 rounded-full bg-terracota px-5 py-2 text-sm font-semibold text-crema-light hover:bg-terracota-dark">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>Descargar PDF</a>
        </div>
    </div>
</div>
<script>
(function () {
    var URL_PDF = <?= json_encode(base_path('/admin/calendario/pdf')) ?>, MODO = <?= json_encode($calModo) ?>, SEMANA = <?= json_encode($calSemanaIni) ?>;
    var N_TODOS = <?= count($calOpciones) ?>, MAX_SEM = <?= CALPDF_MAX_SEMANAS ?>, MAX_PAG = <?= CALPDF_MAX_PAGINAS ?>;
    var m = document.getElementById('cp-modal'), timer = null;
    function $(id) { return document.getElementById(id); }
    function val(n) { var x = document.querySelector('input[name="' + n + '"]:checked'); return x ? x.value : ''; }
    function lunes(ymd) { if (!ymd) return null; var d = new Date(ymd + 'T12:00:00'); if (isNaN(d)) return null; d.setDate(d.getDate() - (d.getDay() + 6) % 7); return d; }
    function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    function abrir() { m.classList.remove('hidden'); m.classList.add('flex'); document.body.style.overflow = 'hidden'; refrescar(true); }
    function cerrar() { m.classList.add('hidden'); m.classList.remove('flex'); document.body.style.overflow = ''; $('cp-iframe').removeAttribute('src'); }
    function refrescar(ya) {
        clearTimeout(timer);
        var rango = val('cp-periodo') === 'rango';
        $('cp-rango').classList.toggle('hidden', !rango); $('cp-rango').classList.toggle('grid', rango);
        var a = lunes(rango ? $('cp-desde').value : SEMANA), b = lunes(rango ? $('cp-hasta').value : SEMANA), err = '';
        if (!a || !b) err = 'Indica las fechas del período.';
        if (!err && b < a) { var t = a; a = b; b = t; }
        var sem = err ? 0 : Math.round((b - a) / 604800000) + 1;
        var todos = val('cp-quien') === 'todos', pag = sem;
        if ($('cp-pac-wrap')) $('cp-pac-wrap').classList.toggle('hidden', todos);
        if (!err && sem > MAX_SEM) err = 'Elige como máximo ' + MAX_SEM + ' semanas.';
        $('cp-aviso').textContent = err; $('cp-aviso').classList.toggle('hidden', !err);
        $('cp-resumen').textContent = err ? '' : (todos ? (MODO === 'box' ? 'Todos los boxes juntos' : 'Todas las profesionales juntas') + ' · ' : '') + (pag === 1 ? '1 página (1 semana)' : pag + ' páginas (una por semana)');
        ['cp-abrir', 'cp-descargar'].forEach(function (id) { $(id).classList.toggle('pointer-events-none', !!err); $(id).classList.toggle('opacity-40', !!err); });
        if (err) return;
        var q = 'modo=' + MODO + '&sel=' + encodeURIComponent(val('cp-quien')) + '&desde=' + iso(a) + '&hasta=' + iso(b);
        if (MODO === 'prof' && !todos) q += '&pac=' + ($('cp-pac').checked ? '1' : '0');
        var url = URL_PDF + '?' + q;
        $('cp-abrir').href = url + '&ver=1'; $('cp-descargar').href = url;
        timer = setTimeout(function () {
            if (getComputedStyle($('cp-iframe')).display === 'none') return;
            $('cp-cargando').classList.remove('hidden'); $('cp-cargando').classList.add('flex');
            $('cp-iframe').src = url + '&ver=1#toolbar=1&navpanes=0&view=FitH';
        }, ya ? 0 : 650);
    }
    $('cp-iframe').addEventListener('load', function () { $('cp-cargando').classList.add('hidden'); $('cp-cargando').classList.remove('flex'); });
    $('ez-cal-pdf').addEventListener('click', abrir);
    m.querySelectorAll('[data-cp-cerrar]').forEach(function (b) { b.addEventListener('click', cerrar); });
    m.addEventListener('click', function (e) { if (e.target === m) cerrar(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !m.classList.contains('hidden')) cerrar(); });
    m.querySelectorAll('input').forEach(function (i) { i.addEventListener('change', function () { refrescar(); }); });
})();
</script>
