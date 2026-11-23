<?php
/** Panel · Calendario de Profesionales. */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/panel.php';

$calProfesionales = listProfesionales();          // [id => nombre]
$calOpciones = $calProfesionales;
$calSel = $_GET['prof'] ?? (array_key_first($calOpciones) ?? '');
if ($calSel === '' || !isset($calOpciones[(int) $calSel])) {
    $calSel = array_key_first($calOpciones) ?? '';
}

[$ini, $fin] = rangoSemana($_GET['semana'] ?? 'today');
$calSemanaIni = $ini;
$calGrilla = $calSel !== '' ? reservasGrilla($ini, $fin, null, (int) $calSel) : [];
$calBoxes = $GLOBALS['BOXES'] ?? [];
$calModo = 'prof';
$calRuta = '/admin/calendario-profesionales';

$panelTitle = 'Calendario de Profesionales';
$panelActive = 'cal-prof';
include APP_ROOT . '/partials/panel/top.php';
?>
<p class="mb-4 font-body text-sm text-cafe/60">Agenda semanal del profesional seleccionado (sus horas en cualquier box). Toca una hora <strong class="text-green-700">disponible</strong> para agendarle una hora, o una <strong class="text-red-700">tomada</strong> para gestionarla.</p>
<?php if (!$calProfesionales): ?>
    <div class="rounded-2xl border border-cafe/10 bg-crema-light p-8 text-center font-body text-sm text-cafe/60">No hay profesionales cargados en el catálogo.</div>
<?php else: ?>
    <?php include APP_ROOT . '/partials/panel/calendario.php'; ?>
<?php endif; ?>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
