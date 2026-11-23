<?php
/**
 * Panel · Panel general. Métricas del centro con filtro por rango de fechas,
 * ordenadas de lo general a lo particular: resumen y lectura del período, demanda,
 * uso de los boxes, ingresos y equipo; el detalle queda al final.
 * Días hábiles = días que atiende el centro («Días y horarios») sin feriados no
 * trabajados, desde el inicio de los registros. Ocupación en horas de box (lib/analytics.php).
 */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/panel.php';
require_once APP_ROOT . '/lib/analytics.php';

[$desde, $hasta] = analyticsRango();

$kpi        = anKpis($desde, $hasta);
$porFecha   = anPorFecha($desde, $hasta);
$serie      = anSerieDiaria($desde, $hasta, $porFecha);
$porDia     = anPorDiaSemana($serie);
$porHora    = anPorHora($desde, $hasta);
$estados    = anEstados($desde, $hasta);
$ingrFecha  = anIngresosPorFecha($desde, $hasta);
$ocup       = anOcupacionBoxes($desde, $hasta);
$hab        = $ocup['habiles'];
$boxSin     = anBoxesSinReservas($desde, $hasta, $hab);
$horBajos   = anHorariosBajos($porHora);
$esp        = anEspecialidades($desde, $hasta);
$profs      = anProfesionales($desde, $hasta, $hab);
$uso        = anTipoUso($desde, $hasta);
$proximas   = proximasReservas(8);
$tipos      = anTiposAtencion($desde, $hasta);
$ingEsp     = anIngresosPorEspecialidad($desde, $hasta);
$mensual    = anMensual($hasta, 12);
$diaProm    = anDiaSemanaPromedio($porFecha, $hab);
$boxEsp     = anBoxPorEspecialidad($desde, $hasta);

$diasLabel = anDiasLabel();
$fmtN = static fn(int $n): string => number_format($n, 0, ',', '.');

function fechaCorta(string $ymd): string
{
    $meses = [1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'];
    $t = strtotime($ymd);
    return date('j', $t) . ' ' . ($meses[(int) date('n', $t)] ?? '');
}

/** "del 1 al 30 de noviembre de 2026" / "del 28 de octubre al 3 de noviembre de 2026". */
function rangoLargo(string $d, string $h): string
{
    $meses = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
    $t0 = strtotime($d); $t1 = strtotime($h);
    if ($d === $h) {
        return 'el ' . date('j', $t0) . ' de ' . $meses[(int) date('n', $t0)] . ' de ' . date('Y', $t0);
    }
    $fin = date('j', $t1) . ' de ' . $meses[(int) date('n', $t1)] . ' de ' . date('Y', $t1);
    if (date('Y-m', $t0) === date('Y-m', $t1)) {
        return 'del ' . date('j', $t0) . ' al ' . $fin;
    }
    $ini = date('j', $t0) . ' de ' . $meses[(int) date('n', $t0)] . (date('Y', $t0) !== date('Y', $t1) ? ' de ' . date('Y', $t0) : '');
    return 'del ' . $ini . ' al ' . $fin;
}

/** Hrefs de atajos de rango para el filtro. */
$base = base_path('/admin');
$hoy  = date('Y-m-d');
[$sIni, $sFin] = rangoSemana();
$presets = [
    'Hoy'             => ['desde' => $hoy, 'hasta' => $hoy],
    'Esta semana'     => ['desde' => $sIni, 'hasta' => $sFin],
    'Este mes'        => ['desde' => date('Y-m-01'), 'hasta' => date('Y-m-t')],
    'Mes anterior'    => ['desde' => date('Y-m-01', strtotime('first day of last month')), 'hasta' => date('Y-m-t', strtotime('last day of last month'))],
    'Últimos 30 días' => ['desde' => date('Y-m-d', strtotime('-29 days')), 'hasta' => $hoy],
    'Este año'        => ['desde' => date('Y-01-01'), 'hasta' => date('Y-12-31')],
];
$rangoActivo = static fn(array $p): bool => $p['desde'] === $desde && $p['hasta'] === $hasta;

// Datos para los gráficos (Chart.js).
$boxLabels = []; $boxData = []; $boxHoras = [];
foreach ($ocup['por_box'] as $b) { $boxLabels[] = $b['nombre']; $boxData[] = $b['pct']; $boxHoras[] = $b['ocupadas'] . ' de ' . $b['capacidad'] . ' h'; }

$estadoOrden = ['confirmada','realizada','pendiente','no_show','cancelada'];
$estadoLabel = ['confirmada'=>'Confirmadas','realizada'=>'Realizadas','pendiente'=>'Pendientes','no_show'=>'Inasistencias','cancelada'=>'Canceladas'];
$estadoColor = ['confirmada'=>'#8B9E8F','realizada'=>'#6D8072','pendiente'=>'#C7A17A','no_show'=>'#7A5A47','cancelada'=>'#B85C3E'];
$estLabels = []; $estData = []; $estColors = [];
foreach ($estadoOrden as $es) {
    if (!empty($estados[$es])) { $estLabels[] = $estadoLabel[$es]; $estData[] = $estados[$es]; $estColors[] = $estadoColor[$es]; }
}

$serieLabels = array_map(static fn($d) => fechaCorta($d['fecha']), $serie);
$serieData   = array_map(static fn($d) => $d['n'], $serie);

$ingrLabels = []; $ingrData = [];
foreach ($serie as $d) { $ingrLabels[] = fechaCorta($d['fecha']); $ingrData[] = $ingrFecha[$d['fecha']] ?? 0; }

$diaLabels = []; $diaData = [];
foreach ([1, 2, 3, 4, 5, 6] as $dn) { if (!horarioDiaAbierto($dn) && empty($porDia[$dn])) continue; $diaLabels[] = $diasLabel[$dn]; $diaData[] = $porDia[$dn] ?? 0; }

$horaLabels = array_map(static fn($h) => sprintf('%02d:00', $h), anHoras());
$horaData   = array_values($porHora);

$analyticsJs = [
    'box'    => ['labels' => $boxLabels, 'data' => $boxData, 'horas' => $boxHoras],
    'estado' => ['labels' => $estLabels, 'data' => $estData, 'colors' => $estColors],
    'serie'  => ['labels' => $serieLabels, 'data' => $serieData],
    'ingreso'=> ['labels' => $ingrLabels, 'data' => $ingrData],
    'dia'    => ['labels' => $diaLabels, 'data' => $diaData],
    'hora'   => ['labels' => $horaLabels, 'data' => $horaData],
    'mensual'=> ['labels' => array_column($mensual['meses'], 'label'), 'reservas' => array_column($mensual['meses'], 'reservas'),
                 'evaluaciones' => array_column($mensual['meses'], 'evaluaciones'), 'ingresos' => array_column($mensual['meses'], 'ingresos')],
    'tipos'  => ['labels' => array_values(array_map(static fn($t) => $t['nombre'], $tipos['filas'])), 'data' => array_values(array_map(static fn($t) => $t['n'], $tipos['filas']))],
];

// Días hábiles futuros del rango: su ocupación es solo lo ya agendado.
$habFuturos = count(array_filter($hab['dias'], static fn($f) => $f > date('Y-m-d')));

// Box más libre del período (para la tarjeta de boxes sin reservas).
$boxMasLibre = $boxSin['por_box'] ? reset($boxSin['por_box']) : null;
if ($boxMasLibre && $boxMasLibre['n'] === 0) {
    $boxMasLibre = null;
}

$tasaCanc = $kpi['reservas'] > 0 ? round($kpi['canceladas'] / $kpi['reservas'] * 100, 1) : 0.0;
$baseInasist = $kpi['realizadas'] + $kpi['no_show'];
$tasaInasist = $baseInasist > 0 ? round($kpi['no_show'] / $baseInasist * 100, 1) : 0.0;

$panelTitle = 'Panel general';
$panelActive = 'dashboard';
include APP_ROOT . '/partials/panel/top.php';

$cards = [
    ['label' => 'Ingresos del período', 'valor' => clp($kpi['ingresos']),
     'sub' => $kpi['boletas'] . ' ' . ($kpi['boletas'] === 1 ? 'boleta' : 'boletas') . ' · ' . $kpi['facturas'] . ' ' . ($kpi['facturas'] === 1 ? 'factura' : 'facturas') . ($kpi['documentos'] ? ' · promedio ' . clp($kpi['ticket']) . ' por documento' : ' emitidas'),
     'ayuda' => 'Boletas y facturas emitidas en el período (sin anuladas), menos notas de crédito y más notas de débito.',
     'accent' => 'terracota', 'icon' => '<path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'],
    ['label' => 'Reservas vigentes', 'valor' => $fmtN($kpi['activas']),
     'sub' => $fmtN($uso['atenciones']) . ' atenciones de las socias · ' . $fmtN($uso['arriendos']) . ' arriendos de box' . ($uso['sin_profesional'] ? ' · ' . $uso['sin_profesional'] . ' sin profesional' : ''),
     'ayuda' => 'Reservas no canceladas con fecha dentro del período. Atenciones = horas de Fernanda y Javiera; arriendos = horas de profesionales externos.',
     'accent' => 'salvia', 'icon' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>'],
    ['label' => 'Ocupación de boxes', 'valor' => anPctFmt($ocup['pct']),
     'sub' => $fmtN($ocup['ocupadas']) . ' de ' . $fmtN($ocup['capacidad']) . ' horas de box · efectiva ' . anPctFmt($ocup['pct_efectiva']),
     'ayuda' => 'Horas de box ocupadas ÷ horas disponibles. Disponibles = ' . $ocup['boxes'] . ' boxes × ' . ($ocup['uniforme'] ? $ocup['horas'] . ' horas diarias × ' . $ocup['dias'] . ' días hábiles' : $ocup['horas_box'] . ' horas de atención en los ' . $ocup['dias'] . ' días hábiles') . ' (horario: ' . horarioCentroResumen() . '). Efectiva: descuenta las horas que fueron inasistencia.',
     'accent' => 'cafe', 'icon' => '<path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/>'],
    ['label' => 'Boxes sin reservas', 'valor' => $fmtN($boxSin['total']) . ($boxSin['capacidad'] ? ' de ' . $fmtN($boxSin['capacidad']) : ''),
     'sub' => $boxSin['capacidad'] === 0 ? 'Sin días hábiles en el período'
        : 'días de box vacíos (' . $boxSin['boxes'] . ' boxes × ' . $boxSin['dias'] . ' ' . ($boxSin['dias'] === 1 ? 'día' : 'días') . ')'
          . ($boxMasLibre ? ' · más libre: ' . $boxMasLibre['nombre'] : ''),
     'ayuda' => 'Cuenta cada box en cada día hábil (lunes a sábado, sin feriados no trabajados) en que no tuvo ninguna reserva vigente. Ejemplo: si Colibrí no tuvo reservas el lunes y el martes, suma 2. El detalle por box está en «Detalle», al final del panel.',
     'accent' => 'cafe', 'icon' => '<path d="M3 21V8l9-5 9 5v13"/><path d="M9 21v-7h6v7"/><path d="M3 21h18"/>'],
];
$accentBg = ['terracota' => 'bg-terracota/10 text-terracota-dark', 'salvia' => 'bg-salvia/15 text-salvia-dark', 'cafe' => 'bg-cafe/10 text-cafe'];
$ayudaIc = '<svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01"/></svg>';
?>

<!-- Filtro por rango de fechas -->
<form method="get" action="<?= e($base) ?>" class="mb-6 rounded-2xl border border-cafe/8 bg-crema-light p-4 shadow-card sm:p-5">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="mb-1 block font-body text-xs font-semibold uppercase tracking-wide text-cafe/55">Desde</label>
                <input type="date" name="desde" value="<?= e($desde) ?>" class="rounded-xl border border-cafe/15 bg-crema-light px-3 py-2 font-body text-sm text-cafe focus:border-terracota focus:outline-none focus:ring-2 focus:ring-terracota/25">
            </div>
            <div>
                <label class="mb-1 block font-body text-xs font-semibold uppercase tracking-wide text-cafe/55">Hasta</label>
                <input type="date" name="hasta" value="<?= e($hasta) ?>" class="rounded-xl border border-cafe/15 bg-crema-light px-3 py-2 font-body text-sm text-cafe focus:border-terracota focus:outline-none focus:ring-2 focus:ring-terracota/25">
            </div>
            <button type="submit" class="rounded-full bg-terracota px-5 py-2.5 font-body text-sm font-semibold text-crema-light transition-colors hover:bg-terracota-dark">Aplicar</button>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <?php foreach ($presets as $nombre => $p): $act = $rangoActivo($p); ?>
                <a href="<?= e($base . '?desde=' . $p['desde'] . '&hasta=' . $p['hasta']) ?>"
                   class="rounded-full px-3 py-1.5 font-body text-xs font-semibold transition-colors <?= $act ? 'bg-cafe text-crema-light' : 'bg-cafe/8 text-cafe/70 hover:bg-cafe/15' ?>"><?= e($nombre) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <p class="mt-3 font-body text-xs text-cafe/55">
        Mostrando <span class="font-semibold text-cafe/75"><?= e(rangoLargo($desde, $hasta)) ?></span>
        · <?= $hab['n'] ?> <?= $hab['n'] === 1 ? 'día hábil' : 'días hábiles' ?> (<?= e(horarioCentroResumen()) ?>)
        · <?= $fmtN($kpi['reservas']) ?> <?= $kpi['reservas'] === 1 ? 'reserva' : 'reservas' ?> (<?= $kpi['canceladas'] ?> <?= $kpi['canceladas'] === 1 ? 'cancelada' : 'canceladas' ?>, <?= $kpi['no_show'] ?> <?= $kpi['no_show'] === 1 ? 'inasistencia' : 'inasistencias' ?>).
    </p>
    <?php if ($habFuturos > 0): ?>
        <p class="mt-1 font-body text-xs text-cafe/45">Incluye <?= $habFuturos ?> <?= $habFuturos === 1 ? 'día hábil futuro' : 'días hábiles futuros' ?>: en esos días la ocupación muestra solo lo que ya está agendado.</p>
    <?php endif; ?>
    <?php if ($hab['previos_excluidos'] > 0 && $hab['inicio']): ?>
        <p class="mt-1 font-body text-xs text-cafe/45">Los registros del sistema comienzan el <?= e(fechaLarga($hab['inicio'])) ?>: los <?= $hab['previos_excluidos'] ?> días hábiles anteriores no se cuentan en la ocupación ni en los boxes sin reservas.</p>
    <?php endif; ?>
    <?php if ($hab['feriados_cerrados']): ?>
        <p class="mt-1 font-body text-xs text-violet-800/80">Feriados no trabajados en el período (no cuentan como días hábiles): <?= e(implode(' · ', array_map(fn($f) => fechaCorta($f['fecha']) . ' ' . $f['nombre'], $hab['feriados_cerrados']))) ?>.</p>
    <?php endif; ?>
</form>

<?php if ($ocup['fuera'] > 0): ?>
    <div class="mb-6 rounded-2xl border border-amber-300 bg-amber-50 px-5 py-3 font-body text-sm text-amber-900">
        <strong><?= $ocup['fuera'] ?> <?= $ocup['fuera'] === 1 ? 'reserva quedó' : 'reservas quedaron' ?> fuera del horario de atención</strong> (domingo o feriado no trabajado, en <?= $ocup['fuera_dias'] ?> <?= $ocup['fuera_dias'] === 1 ? 'día' : 'días' ?>): no se cuentan en la ocupación. Revísalas en el calendario para reagendarlas o cancelarlas.
    </div>
<?php endif; ?>

<!-- KPIs principales -->
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <?php foreach ($cards as $c): ?>
        <div class="rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="flex items-center gap-1.5 font-body text-xs font-semibold uppercase tracking-wide text-cafe/55"><?= e($c['label']) ?>
                        <span class="cursor-help text-cafe/35 hover:text-cafe/70" title="<?= e($c['ayuda']) ?>" aria-label="<?= e($c['ayuda']) ?>"><?= $ayudaIc ?></span></p>
                    <p class="mt-2 font-heading text-3xl font-semibold tabular-nums text-cafe"><?= e($c['valor']) ?></p>
                    <p class="mt-1 font-body text-xs text-cafe/50"><?= e($c['sub']) ?></p>
                </div>
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl <?= $accentBg[$c['accent']] ?>">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><?= $c['icon'] ?></svg>
                </span>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Calidad de la agenda -->
<div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <?php
    $mini = [
        ['Horas de box libres', $fmtN($ocup['libres']) . ' h', 'Disponibles y sin reservar en días hábiles'],
        ['Tasa de cancelación', anPctFmt($tasaCanc), $kpi['canceladas'] . ' de ' . $fmtN($kpi['reservas']) . ' reservas'],
        ['Tasa de inasistencia', anPctFmt($tasaInasist), $kpi['no_show'] . ' de ' . $fmtN($baseInasist) . ' atenciones ya registradas (realizadas + inasistencias)'],
        ['Pacientes distintos', $fmtN(anPacientesDistintos($desde, $hasta)), 'Con ficha y al menos una reserva vigente'],
    ];
    foreach ($mini as [$t, $v, $s]): ?>
        <div class="rounded-2xl border border-cafe/8 bg-crema-light px-4 py-3 shadow-card">
            <p class="font-body text-[11px] font-semibold uppercase tracking-wide text-cafe/50"><?= e($t) ?></p>
            <p class="mt-1 font-heading text-xl font-semibold tabular-nums text-cafe"><?= e($v) ?></p>
            <p class="font-body text-[11px] text-cafe/45"><?= e($s) ?></p>
        </div>
    <?php endforeach; ?>
</div>

<?php
// ---------- Lectura del período: los hallazgos principales, en palabras ----------
$capitulo = static function (string $num, string $titulo, string $sub, string $id): string {
    return '<div id="' . $id . '" class="mt-10 mb-4 flex scroll-mt-24 items-end gap-3 border-b border-cafe/10 pb-2">'
        . '<span class="font-heading text-3xl font-semibold leading-none tabular-nums text-terracota/60">' . $num . '</span>'
        . '<div class="min-w-0"><h2 class="font-heading text-xl font-semibold text-cafe">' . e($titulo) . '</h2>'
        . '<p class="font-body text-xs text-cafe/55">' . e($sub) . '</p></div></div>';
};
$diaPl = static fn(array $d) => mb_strtolower(HORARIO_DIAS_PLURAL[$d['dow']] ?? $d['nombre'], 'UTF-8');
$fmtDec = static fn(float $v): string => number_format($v, 1, ',', '.');
$lectura = [];
if ($kpi['activas'] === 0) {
    $lectura[] = ['Sin reservas', 'No hay reservas vigentes en este período.'];
} else {
    $lectura[] = ['Actividad', 'Hubo <strong>' . $fmtN($kpi['activas']) . ' reservas vigentes</strong> en ' . $hab['n'] . ' ' . ($hab['n'] === 1 ? 'día hábil' : 'días hábiles')
        . ($hab['n'] > 0 ? ' (' . $fmtDec($kpi['activas'] / max(1, $hab['n'])) . ' por día)' : '') . ', con una ocupación de boxes de <strong>' . e(anPctFmt($ocup['pct'])) . '</strong>.'];
    if (!empty($esp['mayor'])) {
        $m0 = $esp['mayor'][0];
        $lectura[] = ['Especialidad', '<strong>' . e($m0['nombre']) . '</strong> es la especialidad con más demanda: ' . e(anHorasFmt($m0['horas'])) . ', el ' . e(anPctFmt($m0['pct'])) . ' de las horas.'
            . (!empty($esp['menor']) ? ' La de menor demanda es ' . e($esp['menor'][0]['nombre']) . '.' : '')];
    }
    if ($diaProm['mayor'] && $diaProm['menor']) {
        $lectura[] = ['Días', 'Los <strong>' . e($diaPl($diaProm['mayor'])) . '</strong> son los de más atención (' . $fmtDec($diaProm['mayor']['promedio']) . ' reservas por día) y los <strong>'
            . e($diaPl($diaProm['menor'])) . '</strong> los de menos (' . $fmtDec($diaProm['menor']['promedio']) . ' por día).'];
    }
    $hb0 = $horBajos['items'][0] ?? null;
    if ($hb0) {
        $lectura[] = ['Horarios', 'El horario con menos reservas es el de las <strong>' . sprintf('%02d:00', $hb0['hora']) . '</strong>'
            . ($horBajos['total_cero'] > 0 ? ' (' . $horBajos['total_cero'] . ' ' . ($horBajos['total_cero'] === 1 ? 'horario quedó' : 'horarios quedaron') . ' sin reservas)' : '') . '.'];
    }
    $pb = array_values($ocup['por_box']);
    if (count($pb) > 1) {
        $lectura[] = ['Boxes', 'El box más ocupado fue <strong>' . e($pb[0]['nombre']) . '</strong> (' . e(anPctFmt($pb[0]['pct'])) . ') y el más libre <strong>' . e($pb[count($pb) - 1]['nombre']) . '</strong> (' . e(anPctFmt($pb[count($pb) - 1]['pct'])) . ').'];
    }
}
if ($ingEsp['total'] > 0 && !empty($ingEsp['filas'])) {
    $ie0 = $ingEsp['filas'][0];
    $lectura[] = ['Ingresos', 'Ingresaron <strong>' . e(clp($ingEsp['total'])) . '</strong>; el ' . e(anPctFmt($ie0['pct'])) . ' viene de <strong>' . e($ie0['nombre']) . '</strong>.'];
}
$lectura[] = ['Tipo de atención', $tipos['con_tipo'] > 0
    ? 'Se registraron <strong>' . $fmtN($tipos['filas']['evaluacion']['n']) . ' ' . ($tipos['filas']['evaluacion']['n'] === 1 ? 'evaluación' : 'evaluaciones') . '</strong> y ' . $fmtN($tipos['con_tipo'] - $tipos['filas']['evaluacion']['n']) . ' atenciones de otro tipo' . ($tipos['sin_tipo'] ? ' (' . $fmtN($tipos['sin_tipo']) . ' reservas sin tipo indicado)' : '') . '.'
    : 'Aún no hay reservas con tipo de atención: se indica al agendar (o en el detalle de cada hora) y desde ahí se cuentan las evaluaciones de cada mes.'];
$lectura[] = ['Por mes', $mensual['con_datos'] >= 2 && $mensual['mayor']
    ? 'El mes con más demanda fue <strong>' . e($mensual['mayor']['nombre']) . '</strong> (' . $fmtN($mensual['mayor']['reservas']) . ' reservas).' . ($mensual['mayor_eval'] ? ' El de más evaluaciones: ' . e($mensual['mayor_eval']['nombre']) . '.' : '')
    : 'Los registros comienzan en ' . e(($hab['inicio'] ? mb_strtolower(mesAnioLabel($hab['inicio']), 'UTF-8') : 'este mes')) . ': la comparación entre meses (estacionalidad) aparecerá a medida que se acumulen más meses.'];
?>
<!-- Lectura del período -->
<section class="mt-6 rounded-2xl border border-terracota/20 bg-gradient-to-br from-crema-light to-terracota/5 p-5 shadow-card sm:p-6" aria-labelledby="lec-tt">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <p class="font-body text-[11px] font-bold uppercase tracking-[0.14em] text-terracota-dark">En pocas palabras</p>
            <h2 id="lec-tt" class="font-heading text-xl font-semibold text-cafe">Lectura del período</h2>
        </div>
        <nav class="flex flex-wrap gap-1.5 font-body text-xs" aria-label="Capítulos del panel">
            <?php foreach ([['cap-demanda', 'Demanda'], ['cap-boxes', 'Boxes'], ['cap-ingresos', 'Ingresos'], ['cap-equipo', 'Equipo'], ['cap-detalle', 'Detalle']] as [$ancla, $txt]): ?>
                <a href="#<?= $ancla ?>" class="rounded-full border border-cafe/15 bg-white/70 px-3 py-1 font-semibold text-cafe/70 hover:border-terracota hover:text-terracota"><?= $txt ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
    <dl class="mt-4 grid grid-cols-1 gap-x-8 gap-y-3 md:grid-cols-2">
        <?php foreach ($lectura as [$t, $txt]): ?>
            <div class="grid min-w-0 grid-cols-[7.5rem_minmax(0,1fr)] items-baseline gap-3">
                <dt class="font-body text-[11px] font-bold uppercase tracking-wide text-cafe/45"><?= e($t) ?></dt>
                <dd class="font-body text-sm leading-relaxed text-cafe/80"><?= $txt ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>
</section>

<?= $capitulo('01', 'Demanda', 'Cuándo y qué piden los pacientes: por mes, día, horario, tipo de atención y especialidad.', 'cap-demanda') ?>

<!-- Evolución mensual + día de la semana -->
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h3 class="font-heading text-lg font-semibold text-cafe">Evolución mensual</h3>
                <p class="mt-1 font-body text-xs text-cafe/50">Reservas vigentes, evaluaciones e ingresos de cada mes (hasta <?= e(mb_strtolower(mesAnioLabel($hasta), 'UTF-8')) ?>, máximo 12 meses). Sirve para ver la estacionalidad.</p>
            </div>
            <?php if ($mensual['con_datos'] >= 2 && $mensual['mayor']): ?>
                <span class="rounded-full bg-salvia/15 px-3 py-1 font-body text-xs font-semibold text-salvia-dark">Mes de mayor demanda: <?= e($mensual['mayor']['nombre']) ?></span>
            <?php endif; ?>
        </div>
        <div class="mt-4 h-64"><canvas id="chartMensual"></canvas></div>
        <?php if ($mensual['con_datos'] < 2): ?>
            <p class="mt-2 font-body text-[11px] text-cafe/50">Por ahora hay datos de <?= $mensual['con_datos'] ?> mes: el mes de mayor demanda se mostrará cuando haya al menos dos meses con reservas.</p>
        <?php endif; ?>
    </div>
    <div class="rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
        <h3 class="font-heading text-lg font-semibold text-cafe">Días con más y menos atención</h3>
        <p class="mt-1 font-body text-xs text-cafe/50">Promedio de reservas por cada día hábil de ese tipo (así se comparan aunque el período tenga más lunes que martes).</p>
        <?php if (!$diaProm['filas']): ?>
            <p class="mt-4 rounded-xl bg-crema/60 px-4 py-3 font-body text-sm text-cafe/60">El período no tiene días hábiles con registros.</p>
        <?php else: $maxP = max(array_column($diaProm['filas'], 'promedio')) ?: 1; ?>
            <div class="mt-4 space-y-2">
                <?php foreach ($diaProm['filas'] as $d):
                    $esMax = $diaProm['mayor'] && $d['dow'] === $diaProm['mayor']['dow']; $esMin = $diaProm['menor'] && $d['dow'] === $diaProm['menor']['dow']; ?>
                    <div class="grid grid-cols-[5.5rem_minmax(0,1fr)_5.5rem] items-center gap-2 font-body text-xs">
                        <span class="font-semibold <?= $esMin ? 'text-terracota-dark' : ($esMax ? 'text-salvia-dark' : 'text-cafe/70') ?>"><?= e($d['nombre']) ?></span>
                        <span class="h-2.5 overflow-hidden rounded-full bg-cafe/8"><span class="block h-full rounded-full <?= $esMin ? 'bg-terracota' : ($esMax ? 'bg-salvia-dark' : 'bg-cafe/30') ?>" style="width: <?= round($d['promedio'] / $maxP * 100) ?>%"></span></span>
                        <span class="text-right tabular-nums text-cafe/70"><?= $fmtDec($d['promedio']) ?> / día<?= $esMin ? ' ▼' : ($esMax ? ' ▲' : '') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($diaProm['menor']): ?>
                <p class="mt-4 rounded-xl bg-terracota/8 px-3 py-2 font-body text-xs text-cafe/75">Menos atención: <strong class="text-terracota-dark"><?= e($diaProm['menor']['nombre']) ?></strong> (<?= $fmtDec($diaProm['menor']['promedio']) ?> reservas por día). Más atención: <strong class="text-salvia-dark"><?= e($diaProm['mayor']['nombre']) ?></strong>.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Horarios -->
<div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2">
<div class="rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
        <h2 class="font-heading text-lg font-semibold text-cafe">Reservas por horario</h2>
        <p class="mt-1 font-body text-xs text-cafe/50">Bloques de una hora, de 08:00 a 21:00</p>
        <div class="mt-4 h-64"><canvas id="chartHora"></canvas></div>
    </div>

    </div>
<div class="rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
        <div class="flex items-center gap-2">
            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-salvia/15 text-salvia-dark">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            </span>
            <div>
                <h2 class="font-heading text-lg font-semibold text-cafe">Horarios de menor demanda</h2>
                <p class="font-body text-xs text-cafe/50"><?= $horBajos['total_cero'] ?> <?= $horBajos['total_cero'] === 1 ? 'horario' : 'horarios' ?> sin reservas en el período</p>
            </div>
        </div>
        <div class="mt-4 space-y-2">
            <?php foreach (array_slice($horBajos['items'], 0, 5) as $it): ?>
                <div class="flex items-center justify-between rounded-xl bg-crema/50 px-3 py-2">
                    <span class="font-body text-sm font-semibold text-cafe"><?= sprintf('%02d:00', $it['hora']) ?> h</span>
                    <span class="font-body text-xs font-semibold <?= $it['n'] === 0 ? 'text-terracota-dark' : 'text-cafe/55' ?>"><?= $it['n'] === 0 ? 'Sin reservas' : $it['n'] . ' ' . ($it['n'] === 1 ? 'reserva' : 'reservas') ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<!-- Tipo de atención -->
<section class="mt-6 rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card" aria-labelledby="tip-tt">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <h3 id="tip-tt" class="font-heading text-lg font-semibold text-cafe">Tipo de atención</h3>
            <p class="mt-1 font-body text-xs text-cafe/50">Reservas vigentes según el servicio: evaluación y diagnóstico, intervención terapéutica, orientación familiar… Se indica al agendar o en el detalle de cada hora.</p>
        </div>
        <div class="flex gap-2 font-body text-xs">
            <span class="rounded-full bg-terracota/10 px-3 py-1 font-semibold text-terracota-dark"><?= $fmtN($tipos['filas']['evaluacion']['n']) ?> <?= $tipos['filas']['evaluacion']['n'] === 1 ? 'evaluación' : 'evaluaciones' ?></span>
            <span class="rounded-full bg-cafe/8 px-3 py-1 font-semibold text-cafe/65"><?= $fmtN($tipos['sin_tipo']) ?> sin tipo indicado</span>
        </div>
    </div>
    <?php if ($tipos['con_tipo'] === 0): ?>
        <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 font-body text-sm text-amber-900">Ninguna reserva del período tiene tipo de atención todavía. Desde ahora, al agendar una hora en el calendario aparece «Tipo de atención»; también se puede completar en el detalle de cada reserva. Con eso este gráfico mostrará, por ejemplo, cuántas evaluaciones se hicieron en el mes.</p>
    <?php else: ?>
        <div class="mt-4 h-56"><canvas id="chartTipos"></canvas></div>
        <?php if ($tipos['sin_tipo'] > 0): ?><p class="mt-2 font-body text-[11px] text-cafe/50"><?= $fmtN($tipos['sin_tipo']) ?> de <?= $fmtN($tipos['total']) ?> reservas no tienen tipo de atención indicado y no aparecen en el gráfico.</p><?php endif; ?>
    <?php endif; ?>
</section>

<!-- Demanda por especialidad -->
<section class="mt-6 rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card" aria-labelledby="esp-tt">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <h2 id="esp-tt" class="font-heading text-lg font-semibold text-cafe">Demanda por especialidad</h2>
            <p class="mt-1 font-body text-xs text-cafe/50">Horas reservadas en el período (reservas no canceladas). Incluye atenciones de las socias y arriendos de box.</p>
        </div>
        <?php if ($esp['anterior']): ?>
            <p class="font-body text-[11px] text-cafe/45">Variación contra <?= e(rangoLargo($esp['anterior'][0], $esp['anterior'][1])) ?></p>
        <?php endif; ?>
    </div>

    <?php if (!$esp['filas'] || $esp['total_horas'] <= 0): ?>
        <p class="mt-4 rounded-xl bg-crema/60 px-4 py-3 font-body text-sm text-cafe/60">No hay horas reservadas con profesional en este período.</p>
    <?php else: ?>
        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <?php foreach ([['Mayor demanda', $esp['mayor'], 'bg-salvia/15 text-salvia-dark', 'border-salvia/40', '<path d="M7 17l5-5 5 5M7 11l5-5 5 5"/>'], ['Menor demanda', $esp['menor'], 'bg-terracota/10 text-terracota-dark', 'border-terracota/30', '<path d="M7 7l5 5 5-5M7 13l5 5 5-5"/>']] as [$tt, $lista, $ic, $bd, $svg]): ?>
                <div class="rounded-2xl border <?= $bd ?> bg-white/50 p-4">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl <?= $ic ?>"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $svg ?></svg></span>
                        <div class="min-w-0">
                            <p class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/55"><?= $tt ?></p>
                            <?php if (!$lista): ?>
                                <p class="mt-1 font-body text-sm text-cafe/60">Todas las especialidades tienen la misma demanda.</p>
                            <?php else: foreach ($lista as $x): ?>
                                <p class="mt-1 font-heading text-xl font-semibold text-cafe"><?= e($x['nombre']) ?></p>
                                <p class="font-body text-xs text-cafe/60">
                                    <strong class="tabular-nums text-cafe/80"><?= e(anHorasFmt($x['horas'])) ?></strong> · <?= e(anPctFmt($x['pct'])) ?> del total
                                    · <?= $x['prof_activos'] ?> de <?= $x['profesionales'] ?> <?= $x['profesionales'] === 1 ? 'profesional' : 'profesionales' ?> con horas
                                    · <?= $x['pacientes'] ?> <?= $x['pacientes'] === 1 ? 'paciente' : 'pacientes' ?>
                                    <?php if ($x['var'] !== null): ?> · <span class="<?= $x['var'] >= 0 ? 'text-salvia-dark' : 'text-terracota-dark' ?>"><?= $x['var'] >= 0 ? '▲' : '▼' ?> <?= abs($x['var']) ?> %</span><?php endif; ?>
                                </p>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-5 overflow-x-auto">
            <table class="w-full min-w-[720px] font-body text-sm">
                <thead>
                    <tr class="border-b border-cafe/10 text-left text-[11px] font-semibold uppercase tracking-wide text-cafe/50">
                        <th class="py-2 pr-3">Especialidad</th>
                        <th class="py-2 pr-3 text-right">Horas</th>
                        <th class="w-[24%] py-2 pr-3">% del total</th>
                        <th class="py-2 pr-3 text-right" title="Horas de las socias / horas de profesionales que arriendan">Atención / arriendo</th>
                        <th class="py-2 pr-3 text-right">Profesionales</th>
                        <th class="py-2 pr-3 text-right">Pacientes</th>
                        <th class="py-2 pr-3 text-right" title="Canceladas e inasistencias del período">Cancel. / inasist.</th>
                        <th class="py-2 text-right">Variación</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($esp['filas'] as $x): ?>
                        <tr class="border-b border-cafe/6">
                            <td class="py-2.5 pr-3 font-semibold text-cafe"><?= e($x['nombre']) ?></td>
                            <td class="py-2.5 pr-3 text-right tabular-nums text-cafe"><?= e(anHorasFmt($x['horas'])) ?></td>
                            <td class="py-2.5 pr-3">
                                <div class="flex items-center gap-2"><div class="h-2 flex-1 overflow-hidden rounded-full bg-cafe/8"><div class="h-full rounded-full bg-terracota" style="width: <?= $x['pct'] ?>%"></div></div><span class="w-12 text-right text-xs tabular-nums text-cafe/60"><?= e(anPctFmt($x['pct'])) ?></span></div>
                            </td>
                            <td class="py-2.5 pr-3 text-right tabular-nums text-cafe/70"><?= $x['atenciones'] ?> / <?= $x['arriendos'] ?></td>
                            <td class="py-2.5 pr-3 text-right tabular-nums text-cafe/70"><?= $x['prof_activos'] ?> de <?= $x['profesionales'] ?></td>
                            <td class="py-2.5 pr-3 text-right tabular-nums text-cafe/70"><?= $x['pacientes'] ?></td>
                            <td class="py-2.5 pr-3 text-right tabular-nums text-cafe/70"><?= $x['canceladas'] ?> / <?= $x['no_show'] ?></td>
                            <td class="py-2.5 text-right tabular-nums <?= $x['var'] === null ? 'text-cafe/35' : ($x['var'] >= 0 ? 'text-salvia-dark' : 'text-terracota-dark') ?>"><?= $x['var'] === null ? 'sin datos' : (($x['var'] >= 0 ? '▲ ' : '▼ ') . abs($x['var']) . ' %') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($esp['sin_profesional'] > 0): ?>
            <p class="mt-2 font-body text-[11px] text-amber-700"><?= $esp['sin_profesional'] ?> <?= $esp['sin_profesional'] === 1 ? 'reserva no tiene' : 'reservas no tienen' ?> profesional asignado y no se cuenta<?= $esp['sin_profesional'] === 1 ? '' : 'n' ?> en ninguna especialidad.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>


<!-- Tendencia diaria -->
<div class="mt-6">
<div class="rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
        <h2 class="font-heading text-lg font-semibold text-cafe">Tendencia de reservas</h2>
        <p class="mt-1 font-body text-xs text-cafe/50">Reservas vigentes por día</p>
        <div class="mt-4 h-64"><canvas id="chartTendencia"></canvas></div>
    </div>

</div>

<?= $capitulo('02', 'Uso de los boxes', 'Cuánto se ocupa cada box, qué especialidad lo usa más y en qué días y horarios queda libre.', 'cap-boxes') ?>

<!-- Ocupación por box + estado -->
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
        <h2 class="font-heading text-lg font-semibold text-cafe">Ocupación por box</h2>
        <p class="mt-1 font-body text-xs text-cafe/50">% de las horas disponibles de cada box que quedaron ocupadas (<?php if ($ocup['uniforme']): ?><?= $ocup['horas'] ?> horas diarias × <?= $ocup['dias'] ?> días hábiles = <?php else: ?>horas de atención de cada día hábil = <?php endif; ?><?= $fmtN($ocup['horas_box']) ?> h por box)</p>
        <div class="mt-4 h-72"><canvas id="chartBoxes"></canvas></div>
    </div>
    <div class="rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
        <h2 class="font-heading text-lg font-semibold text-cafe">Estado de las reservas</h2>
        <p class="mt-1 font-body text-xs text-cafe/50">Salud de la agenda</p>
        <div class="mt-4 h-72"><canvas id="chartEstados"></canvas></div>
    </div>
</div>


<!-- Box más solicitado por especialidad -->
<section class="mt-6 ez-tabla-card" aria-labelledby="bxe-tt">
    <div class="px-5 pt-4">
        <h3 id="bxe-tt" class="font-heading text-lg font-semibold text-cafe">Box más solicitado por especialidad</h3>
        <p class="mt-1 font-body text-xs text-cafe/50">Horas reservadas (no canceladas) de cada especialidad, según el box en que se atendieron.</p>
    </div>
    <div class="mt-3 overflow-x-auto">
        <table class="ez-tabla">
            <thead><tr>
                <th class="px-5 py-2.5">Especialidad</th>
                <th class="px-5 py-2.5">Box más solicitado</th>
                <th class="px-5 py-2.5 text-right">Horas en ese box</th>
                <th class="px-5 py-2.5">Otros boxes que usa</th>
            </tr></thead>
            <tbody class="font-body text-sm text-cafe/80">
                <?php if (!$boxEsp): ?>
                    <tr><td colspan="4" class="px-5 py-8 text-center text-cafe/45">No hay horas con profesional en este período.</td></tr>
                <?php else: foreach ($boxEsp as $x): ?>
                    <tr>
                        <td class="px-5 py-3 font-semibold text-cafe"><?= e($x['nombre']) ?></td>
                        <td class="px-5 py-3"><span class="rounded-full bg-salvia/15 px-2.5 py-0.5 text-xs font-semibold text-salvia-dark"><?= e($x['top']['nombre']) ?></span></td>
                        <td class="px-5 py-3 text-right tabular-nums"><strong class="text-cafe"><?= e(anHorasFmt($x['top']['horas'])) ?></strong> <span class="text-xs text-cafe/45">· <?= $x['top']['pct'] ?> % de <?= e(anHorasFmt($x['horas'])) ?></span></td>
                        <td class="px-5 py-3 text-xs text-cafe/60"><?= count($x['boxes']) > 1 ? e(implode(' · ', array_map(static fn($b) => $b['nombre'] . ' ' . anHorasFmt($b['horas']), array_slice($x['boxes'], 1)))) : '—' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Mapa de calor de ocupación -->
<div class="mt-6 rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h2 class="font-heading text-lg font-semibold text-cafe">Mapa de calor · ocupación por día y horario</h2>
            <p class="mt-1 font-body text-xs text-cafe/50">% de las horas de box ocupadas en ese día de la semana y horario (<?= $ocup['boxes'] ?> boxes × días hábiles de ese tipo). Las celdas claras son horarios que puedes ofrecer.</p>
        </div>
        <div class="flex items-center gap-2 font-body text-xs text-cafe/50">
            <span>0 %</span>
            <span class="inline-block h-3 w-4 rounded-sm" style="background:#EFE8DA"></span>
            <span class="inline-block h-3 w-4 rounded-sm" style="background:#D9C4A0"></span>
            <span class="inline-block h-3 w-4 rounded-sm" style="background:#C08A5C"></span>
            <span class="inline-block h-3 w-4 rounded-sm" style="background:#B85C3E"></span>
            <span>100 %</span>
        </div>
    </div>
    <div class="mt-4 overflow-x-auto">
        <table class="w-full border-separate" style="border-spacing:3px">
            <thead>
                <tr>
                    <th class="px-2 py-1 text-left font-body text-xs font-semibold text-cafe/45"></th>
                    <?php foreach (horasAgenda() as $h): ?>
                        <th class="px-1 py-1 text-center font-body text-[0.65rem] font-semibold text-cafe/45"><?= e(substr($h, 0, 2)) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php for ($dn = 1; $dn <= 6; $dn++): ?>
                    <tr>
                        <td class="px-2 py-1 font-body text-xs font-semibold text-cafe/60"><?= e($diasLabel[$dn]) ?></td>
                        <?php foreach (horasAgenda() as $h):
                            $cel = $ocup['heat'][$dn][$h] ?? ['n' => 0, 'cap' => 0, 'pct' => null];
                            if ($cel['pct'] === null) {
                                $bg = '#F3EEE4'; $fg = 'color:#5C403355'; $txt = '·'; $title = $diasLabel[$dn] . ' ' . $h . ' · sin días hábiles en el período';
                            } else {
                                $p = $cel['pct'];
                                if ($p === 0)      { $bg = '#EFE8DA'; }
                                elseif ($p <= 33)  { $bg = '#D9C4A0'; }
                                elseif ($p <= 66)  { $bg = '#C08A5C'; }
                                else               { $bg = '#B85C3E'; }
                                $fg = $p > 50 ? 'color:#FBF9F4' : 'color:#5C4033';
                                $txt = $p > 0 ? $p : '';
                                $title = $diasLabel[$dn] . ' ' . $h . ' · ' . $p . ' % (' . $cel['n'] . ' de ' . $cel['cap'] . ' horas de box)';
                            }
                        ?>
                            <td class="rounded-md text-center font-body text-[0.65rem] font-semibold tabular-nums" style="background:<?= $bg ?>;<?= $fg ?>;min-width:30px;height:30px" title="<?= e($title) ?>"><?= $txt ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>
</div>


<?= $capitulo('03', 'Ingresos', 'Lo que efectivamente se facturó: por día y por especialidad.', 'cap-ingresos') ?>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
<div class="rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
        <h2 class="font-heading text-lg font-semibold text-cafe">Ingresos por día</h2>
        <p class="mt-1 font-body text-xs text-cafe/50">Boletas y facturas emitidas en el período (las anuladas no suman)</p>
        <div class="mt-4 h-64"><canvas id="chartIngresos"></canvas></div>
    </div>

    <section class="rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card" aria-labelledby="ine-tt">
        <h3 id="ine-tt" class="font-heading text-lg font-semibold text-cafe">Ingresos por especialidad</h3>
        <p class="mt-1 font-body text-xs text-cafe/50">Cada boleta o factura se asigna a la especialidad de la profesional que atendió (las notas de crédito restan en la misma proporción). Total: <strong class="text-cafe/75"><?= e(clp($ingEsp['total'])) ?></strong>.</p>
        <?php if (!$ingEsp['filas']): ?>
            <p class="mt-4 rounded-xl bg-crema/60 px-4 py-3 font-body text-sm text-cafe/60">No hay documentos emitidos en este período.</p>
        <?php else: ?>
            <div class="mt-4 space-y-3">
                <?php foreach ($ingEsp['filas'] as $x): ?>
                    <div>
                        <div class="flex items-baseline justify-between gap-3 font-body text-sm">
                            <span class="min-w-0 truncate font-semibold text-cafe"><?= e($x['nombre']) ?></span>
                            <span class="shrink-0 tabular-nums text-cafe"><strong><?= e(clp($x['total'])) ?></strong> <span class="text-xs text-cafe/50">· <?= e(anPctFmt($x['pct'])) ?> · <?= $x['docs'] ?> doc.</span></span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-cafe/8"><div class="h-full rounded-full bg-terracota" style="width: <?= max(0, min(100, $x['pct'])) ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?= $capitulo('04', 'Equipo', 'Horas, pacientes y uso del horario de cada profesional.', 'cap-equipo') ?>

<!-- Profesionales -->
<section class="ez-tabla-card" aria-labelledby="prof-tt">
    <div class="px-5 pt-4">
        <h2 id="prof-tt" class="font-heading text-lg font-semibold text-cafe">Profesionales</h2>
        <p class="mt-1 font-body text-xs text-cafe/50">Ordenados por horas reservadas en el período. «Uso de su horario» compara sus horas con el horario de atención que tiene configurado.</p>
    </div>
    <div class="mt-3 overflow-x-auto">
        <table class="ez-tabla">
            <thead>
                <tr>
                    <th class="px-5 py-2.5">Profesional</th>
                    <th class="px-5 py-2.5">Especialidad</th>
                    <th class="px-5 py-2.5">Tipo</th>
                    <th class="px-5 py-2.5 text-right">Horas</th>
                    <th class="px-5 py-2.5 text-right">Pacientes</th>
                    <th class="px-5 py-2.5 text-right">Realizadas</th>
                    <th class="px-5 py-2.5 text-right">Cancel. / inasist.</th>
                    <th class="px-5 py-2.5 text-right">Uso de su horario</th>
                </tr>
            </thead>
            <tbody class="font-body text-sm text-cafe/80">
                <?php if (!$profs): ?>
                    <tr><td colspan="8" class="px-5 py-8 text-center text-cafe/45">No hay reservas con profesional en este período.</td></tr>
                <?php else: foreach ($profs as $p): ?>
                    <tr>
                        <td class="px-5 py-3 font-semibold text-cafe"><?= e($p['nombre']) ?></td>
                        <td class="px-5 py-3"><?= e($p['especialidad']) ?></td>
                        <td class="px-5 py-3"><span class="rounded-full px-2 py-0.5 text-[11px] font-semibold <?= $p['socia'] ? 'bg-terracota/10 text-terracota-dark' : 'bg-salvia/15 text-salvia-dark' ?>"><?= $p['socia'] ? 'Socia' : 'Arriendo' ?></span></td>
                        <td class="px-5 py-3 text-right tabular-nums font-semibold text-cafe"><?= e(anHorasFmt($p['horas'])) ?></td>
                        <td class="px-5 py-3 text-right tabular-nums"><?= $p['pacientes'] ?></td>
                        <td class="px-5 py-3 text-right tabular-nums"><?= $p['realizadas'] ?></td>
                        <td class="px-5 py-3 text-right tabular-nums"><?= $p['canceladas'] ?> / <?= $p['no_show'] ?></td>
                        <td class="px-5 py-3 text-right tabular-nums"><?= $p['uso'] === null ? '<span class="text-cafe/35" title="No tiene horario de atención configurado">—</span>' : '<span title="' . e(anHorasFmt($p['uso_horas']) . ' reservadas dentro de su horario, de ' . anHorasFmt($p['disponibles']) . ' disponibles') . '">' . e(anPctFmt($p['uso'])) . ' <span class="text-[11px] text-cafe/45">de ' . e(anHorasFmt($p['disponibles'])) . '</span></span>' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>


<?= $capitulo('05', 'Detalle', 'Listas para revisar con calma: boxes sin reservas día por día y las próximas horas.', 'cap-detalle') ?>

<details class="rounded-2xl border border-cafe/8 bg-crema-light shadow-card">
    <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 px-5 py-4 font-body text-sm font-semibold text-cafe">
        <span>Boxes sin reservas, día por día</span>
        <span class="font-normal text-cafe/55"><?= $fmtN($boxSin['total']) ?><?= $boxSin['capacidad'] ? ' de ' . $fmtN($boxSin['capacidad']) : '' ?> días de box vacíos · ver detalle</span>
    </summary>
    <div class="px-2 pb-2">
<section class="rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card" aria-labelledby="bsr-tt">
        <div class="flex items-center gap-2">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-cafe/10 text-cafe">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V8l9-5 9 5v13"/><path d="M9 21v-7h6v7"/><path d="M3 21h18"/></svg>
            </span>
            <div class="min-w-0">
                <h2 id="bsr-tt" class="font-heading text-lg font-semibold text-cafe">Boxes sin reservas</h2>
                <p class="font-body text-xs text-cafe/50">Días hábiles (lunes a sábado) en que cada box no tuvo ninguna reserva</p>
            </div>
        </div>
        <?php if ($hab['n'] === 0): ?>
            <p class="mt-4 rounded-xl bg-crema/60 px-4 py-3 font-body text-sm text-cafe/60">El período no tiene días hábiles con registros.</p>
        <?php elseif ($boxSin['total'] === 0): ?>
            <p class="mt-4 rounded-xl bg-salvia/10 px-4 py-3 font-body text-sm text-salvia-dark">Todos los boxes tuvieron al menos una reserva en cada día hábil del período.</p>
        <?php else: ?>
            <?php if ($boxSin['dias_todos_libres'] > 0): ?>
                <p class="mt-3 font-body text-xs text-terracota-dark"><?= $boxSin['dias_todos_libres'] ?> <?= $boxSin['dias_todos_libres'] === 1 ? 'día hábil quedó' : 'días hábiles quedaron' ?> sin reservas en ningún box.</p>
            <?php endif; ?>
            <div class="mt-4 space-y-2.5">
                <?php foreach ($boxSin['por_box'] as $b):
                    $pctLibre = $boxSin['dias'] > 0 ? round($b['n'] / $boxSin['dias'] * 100) : 0;
                ?>
                    <div class="rounded-xl bg-crema/50 px-3.5 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                            <span class="font-body text-sm font-semibold text-cafe"><?= e($b['nombre']) ?></span>
                            <span class="font-body text-xs tabular-nums <?= $b['n'] === 0 ? 'text-salvia-dark' : 'text-cafe/60' ?>">
                                <?php if ($b['n'] === 0): ?>Con reservas todos los días
                                <?php else: ?><strong class="text-cafe"><?= $b['n'] ?></strong> de <?= $boxSin['dias'] ?> <?= $boxSin['dias'] === 1 ? 'día' : 'días' ?> sin reservas<?php endif; ?>
                            </span>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-cafe/8"><div class="h-full rounded-full <?= $pctLibre >= 100 ? 'bg-terracota' : 'bg-terracota/60' ?>" style="width: <?= $pctLibre ?>%"></div></div>
                        <?php if ($b['n'] > 0 && $b['n'] === $boxSin['dias'] && $boxSin['dias'] > 1): ?>
                            <p class="mt-2 font-body text-xs font-semibold text-terracota-dark">Sin reservas en todo el período</p>
                        <?php elseif ($b['n'] > 0):
                            $chips = array_map(static function ($f) use ($diasLabel) {
                                $fut = $f > date('Y-m-d');
                                return '<span class="inline-flex items-center gap-1 rounded-md px-2 py-1 font-body text-[11px] font-semibold '
                                    . ($fut ? 'border border-dashed border-terracota/40 text-terracota-dark' : 'bg-terracota/10 text-terracota-dark')
                                    . '"' . ($fut ? ' title="Fecha futura: todavía se puede agendar"' : '') . '><span class="opacity-70">'
                                    . e($diasLabel[(int) date('N', strtotime($f))]) . '</span> ' . e(fechaCorta($f)) . '</span>';
                            }, $b['fechas']);
                            $max = 12;
                        ?>
                            <div class="mt-2 flex flex-wrap gap-1.5"><?= implode('', array_slice($chips, 0, $max)) ?></div>
                            <?php if (count($chips) > $max): ?>
                                <details class="mt-1.5">
                                    <summary class="cursor-pointer font-body text-[11px] font-semibold text-cafe/55 hover:text-cafe">Ver <?= count($chips) - $max ?> más</summary>
                                    <div class="mt-1.5 flex flex-wrap gap-1.5"><?= implode('', array_slice($chips, $max)) ?></div>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php
            $hayChipFuturo = false;
            foreach ($boxSin['por_box'] as $b) {
                if ($b['n'] > 0 && !($b['n'] === $boxSin['dias'] && $boxSin['dias'] > 1) && max($b['fechas']) > date('Y-m-d')) { $hayChipFuturo = true; break; }
            }
            if ($hayChipFuturo): ?>
                <p class="mt-3 font-body text-[11px] text-cafe/45">Las fechas con borde punteado son futuras: todavía se pueden agendar.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    </div>
</details>

<!-- Próximas reservas -->
<div class="mt-6 ez-tabla-card">
    <div class="flex items-center justify-between px-5 py-4">
        <h2 class="font-heading text-lg font-semibold text-cafe">Próximas reservas</h2>
        <a href="<?= e(base_path('/admin/calendario-boxes')) ?>" class="font-body text-xs font-semibold text-terracota-dark hover:text-terracota">Ver calendario →</a>
    </div>
    <div class="overflow-x-auto">
        <table class="ez-tabla">
            <thead>
                <tr>
                    <th class="px-5 py-2.5">Fecha</th>
                    <th class="px-5 py-2.5">Hora</th>
                    <th class="px-5 py-2.5">Box</th>
                    <th class="px-5 py-2.5">Paciente</th>
                    <th class="px-5 py-2.5 text-right" title="Precio de la reserva. Solo pasa a ser ingreso cuando se emite su boleta o factura.">Precio</th>
                </tr>
            </thead>
            <tbody class="font-body text-sm text-cafe/80">
                <?php if (!$proximas): ?>
                    <tr><td colspan="5" class="px-5 py-10 text-center text-cafe/45">Aún no hay reservas próximas.</td></tr>
                <?php else: foreach ($proximas as $r): ?>
                    <tr>
                        <td class="px-5 py-3 font-semibold text-cafe"><?= e(fechaCorta($r['fecha'])) ?></td>
                        <td class="px-5 py-3"><?= $r['hora'] ? e(substr($r['hora'], 0, 5)) : '<span class="text-cafe/40">—</span>' ?></td>
                        <td class="px-5 py-3"><?= e(nombreBox($r['box_slug'])) ?></td>
                        <td class="px-5 py-3"><?= e($r['nombre']) ?></td>
                        <td class="px-5 py-3 text-right font-semibold text-cafe"><?= $r['precio_clp'] ? e(clp((int) $r['precio_clp'])) : '—' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>


<!-- Cómo se calculan los indicadores -->
<details class="mt-6 rounded-2xl border border-cafe/8 bg-crema-light px-5 py-4 shadow-card">
    <summary class="cursor-pointer font-body text-sm font-semibold text-cafe">¿Cómo se calculan estos indicadores?</summary>
    <ul class="mt-3 list-disc space-y-1.5 pl-5 font-body text-xs text-cafe/65">
        <li><strong>Días hábiles:</strong> los días en que atiende el centro según «Días y horarios» (<?= e(horarioCentroResumen()) ?>). No cuentan los domingos, los días sin atención, los feriados marcados como no trabajados en el calendario ni los días anteriores al inicio de los registros (<?= $hab['inicio'] ? e(fechaLarga($hab['inicio'])) : 'sin registros aún' ?>).</li>
        <li><strong>Ocupación de boxes:</strong> horas de box ocupadas ÷ horas de box disponibles. Disponibles = <?= $ocup['boxes'] ?> boxes activos × <?= $ocup['uniforme'] ? $ocup['horas'] . ' bloques de 1 hora × ' . $ocup['dias'] . ' días hábiles' : 'los bloques de 1 hora del horario de cada uno de los ' . $ocup['dias'] . ' días hábiles (' . $fmtN($ocup['horas_box']) . ' h por box)' ?> = <?= $fmtN($ocup['capacidad']) ?> h. Ocupada = un box en una fecha y hora con al menos una reserva vigente; si hay dos reservas en el mismo box y hora, cuenta como una sola hora ocupada.</li>
        <li><strong>Boxes sin reservas:</strong> cada box en cada día hábil en que no tuvo ninguna reserva vigente cuenta como 1 día de box vacío. Total posible = <?= $boxSin['boxes'] ?> boxes × <?= $boxSin['dias'] ?> días hábiles = <?= $fmtN($boxSin['capacidad']) ?>.</li>
        <li><strong>Ocupación efectiva:</strong> igual a la anterior, pero sin las horas en que todos faltaron (inasistencias).</li>
        <li><strong>Demanda por especialidad:</strong> horas reservadas (no canceladas) por los profesionales de cada especialidad. Mayor y menor demanda se comparan entre las especialidades con profesionales activos.</li>
        <li><strong>Tasa de inasistencia:</strong> inasistencias ÷ (realizadas + inasistencias). <strong>Tasa de cancelación:</strong> canceladas ÷ todas las reservas del período.</li>
        <li><strong>Ingresos:</strong> boletas y facturas emitidas (sin anuladas) − notas de crédito + notas de débito, por fecha de emisión.</li>
        <li><strong>Ingresos por especialidad:</strong> cada documento se asigna a la especialidad de la profesional de cada ítem (o del documento); las facturas a clientes sin profesional son «Arriendo de boxes». Las notas de crédito y débito del período restan o suman en la misma proporción que su documento, así que el total coincide con «Ingresos del período».</li>
        <li><strong>Días con más y menos atención:</strong> reservas vigentes de cada día de la semana ÷ cantidad de días hábiles de ese tipo en el período.</li>
        <li><strong>Tipo de atención:</strong> el que se indica al agendar o en el detalle de la reserva. Las reservas sin tipo indicado se informan aparte.</li>
        <li><strong>Evolución mensual:</strong> reservas vigentes, evaluaciones e ingresos de cada mes calendario, hasta el mes del final del período (máximo 12 meses y desde el inicio de los registros).</li>
        <li><strong>Box más solicitado por especialidad:</strong> horas reservadas (no canceladas) de las profesionales de cada especialidad, agrupadas por box.</li>
    </ul>
</details>


<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function () {
    var A = <?= json_encode($analyticsJs, JSON_UNESCAPED_UNICODE) ?>;
    if (typeof Chart === 'undefined') return;

    var CAFE_SOFT = 'rgba(92,64,51,.08)', CAFE_MID = 'rgba(92,64,51,.55)';
    var TERRA = '#D97757', SALVIA = '#8B9E8F';
    Chart.defaults.font.family = "'Nunito', system-ui, sans-serif";
    Chart.defaults.color = CAFE_MID;

    function grid() { return { color: CAFE_SOFT, drawBorder: false }; }
    function noGrid() { return { display: false, drawBorder: false }; }
    var money = function (v) { return '$' + Number(v).toLocaleString('es-CL'); };
    var pct = function (v) { return Number(v).toLocaleString('es-CL') + ' %'; };

    // Ocupación por box (% de horas ocupadas)
    new Chart(document.getElementById('chartBoxes'), {
        type: 'bar',
        data: { labels: A.box.labels, datasets: [{ data: A.box.data, backgroundColor: TERRA, borderRadius: 8, maxBarThickness: 34 }] },
        options: {
            indexAxis: 'y', responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return pct(c.parsed.x) + ' · ' + A.box.horas[c.dataIndex]; } } } },
            scales: { x: { beginAtZero: true, suggestedMax: 100, ticks: { callback: function (v) { return v + ' %'; } }, grid: grid() }, y: { grid: noGrid() } }
        }
    });

    // Estado de las reservas (dona)
    new Chart(document.getElementById('chartEstados'), {
        type: 'doughnut',
        data: { labels: A.estado.labels, datasets: [{ data: A.estado.data, backgroundColor: A.estado.colors, borderColor: '#FBF9F4', borderWidth: 2 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14 } } } }
    });

    // Tendencia de reservas (línea/área)
    new Chart(document.getElementById('chartTendencia'), {
        type: 'line',
        data: { labels: A.serie.labels, datasets: [{ data: A.serie.data, borderColor: SALVIA, backgroundColor: 'rgba(139,158,143,.18)', fill: true, tension: .35, pointRadius: 2, pointHoverRadius: 4, borderWidth: 2 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: noGrid(), ticks: { maxTicksLimit: 10 } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: grid() } } }
    });

    // Ingresos por día (barras)
    new Chart(document.getElementById('chartIngresos'), {
        type: 'bar',
        data: { labels: A.ingreso.labels, datasets: [{ data: A.ingreso.data, backgroundColor: 'rgba(217,119,87,.75)', borderRadius: 6, maxBarThickness: 22 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return money(c.parsed.y); } } } }, scales: { x: { grid: noGrid(), ticks: { maxTicksLimit: 10 } }, y: { beginAtZero: true, grid: grid(), ticks: { callback: function (v) { return money(v); } } } } }
    });

    // Reservas por día de semana (lunes a sábado)
    if (document.getElementById('chartDia')) new Chart(document.getElementById('chartDia'), {
        type: 'bar',
        data: { labels: A.dia.labels, datasets: [{ data: A.dia.data, backgroundColor: SALVIA, borderRadius: 8, maxBarThickness: 46 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: noGrid() }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: grid() } } }
    });

    // Evolución mensual: reservas y evaluaciones (barras) + ingresos (línea, eje derecho)
    if (document.getElementById('chartMensual')) new Chart(document.getElementById('chartMensual'), {
        data: { labels: A.mensual.labels, datasets: [
            { type: 'bar', label: 'Reservas', data: A.mensual.reservas, backgroundColor: SALVIA, borderRadius: 6, maxBarThickness: 34, yAxisID: 'y' },
            { type: 'bar', label: 'Evaluaciones', data: A.mensual.evaluaciones, backgroundColor: TERRA, borderRadius: 6, maxBarThickness: 34, yAxisID: 'y' },
            { type: 'line', label: 'Ingresos', data: A.mensual.ingresos, borderColor: '#7A5A47', backgroundColor: '#7A5A47', tension: .3, pointRadius: 3, borderWidth: 2, yAxisID: 'y2' }
        ] },
        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14 } }, tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + (c.dataset.yAxisID === 'y2' ? money(c.parsed.y) : c.parsed.y); } } } },
            scales: { x: { grid: noGrid() }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: grid(), title: { display: true, text: 'Reservas' } },
                      y2: { beginAtZero: true, position: 'right', grid: noGrid(), ticks: { callback: function (v) { return money(v); } } } } }
    });

    // Tipo de atención (barras horizontales)
    if (document.getElementById('chartTipos')) new Chart(document.getElementById('chartTipos'), {
        type: 'bar',
        data: { labels: A.tipos.labels, datasets: [{ data: A.tipos.data, backgroundColor: [TERRA, SALVIA, '#C7A17A', '#7A5A47', '#B9A99A'], borderRadius: 6, maxBarThickness: 26 }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: grid() }, y: { grid: noGrid() } } }
    });

    // Reservas por horario (barras)
    new Chart(document.getElementById('chartHora'), {
        type: 'bar',
        data: { labels: A.hora.labels, datasets: [{ data: A.hora.data, backgroundColor: A.hora.data.map(function (v) { return v === 0 ? 'rgba(184,92,62,.55)' : '#C08A5C'; }), borderRadius: 6, maxBarThickness: 26 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: noGrid() }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: grid() } } }
    });
})();
</script>

<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
