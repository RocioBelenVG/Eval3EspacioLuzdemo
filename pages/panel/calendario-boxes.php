<?php
/** Panel · Calendario de Boxes. */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/panel.php';

$calBoxes = $GLOBALS['BOXES'] ?? [];
$calOpciones = [];
foreach ($calBoxes as $b) {
    $calOpciones[$b['slug']] = $b['nombre'];
}
$calSel = $_GET['box'] ?? array_key_first($calOpciones);
if (!isset($calOpciones[$calSel])) {
    $calSel = array_key_first($calOpciones);
}

[$ini, $fin] = rangoSemana($_GET['semana'] ?? 'today');
$calSemanaIni = $ini;
$calGrilla = reservasGrilla($ini, $fin, (string) $calSel, null);
$calProfesionales = listProfesionales();
$calModo = 'box';
$calRuta = '/admin/calendario-boxes';

$panelTitle = 'Calendario de Boxes';
$panelActive = 'cal-boxes';
include APP_ROOT . '/partials/panel/top.php';
?>
<p class="mb-4 font-body text-sm text-cafe/60">Agenda semanal del box seleccionado. Toca una hora <strong class="text-green-700">disponible</strong> para agendar, o una <strong class="text-red-700">tomada</strong> para ver o gestionar la reserva.</p>
<?php include APP_ROOT . '/partials/panel/calendario.php'; ?>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
