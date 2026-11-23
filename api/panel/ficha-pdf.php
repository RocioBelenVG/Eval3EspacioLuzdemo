<?php
/**
 * Panel · PDF de la ficha clínica.
 *   /admin/pacientes/{id}/fichas/{fichaId}/pdf            -> descarga
 *   /admin/pacientes/{id}/fichas/{fichaId}/pdf?ver=1      -> se muestra en el navegador
 * Opciones: s=secciones (coma), ev=todas|rango|sel, desde, hasta, ids. El router define $pacienteId y $fichaId.
 */
declare(strict_types=1);

requireAuthPage();
require_once APP_ROOT . '/lib/ficha_clinica.php';

$paciente = getPaciente((int) ($pacienteId ?? 0));
$ficha = getFicha((int) ($fichaId ?? 0));
if (!$paciente || !$ficha || (int) $ficha['paciente_id'] !== (int) $paciente['id']) {
    http_response_code(404);
    echo 'Ficha clínica no encontrada.';
    return;
}

try {
    $pdf = fichaPdfBytes($paciente, $ficha, fichaPdfOpciones($_GET));
} catch (Throwable $e) {
    error_log('[ficha-pdf] ' . $e->getMessage());
    http_response_code(500);
    echo 'No se pudo generar el PDF de la ficha clínica.';
    return;
}

$nombre = $pdf['filename'];
$ascii = preg_replace('/[^A-Za-z0-9 ._()-]/', '', strtr($nombre, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','ñ'=>'n','Ñ'=>'N','ü'=>'u','Ü'=>'U']));
$disp = (($_GET['ver'] ?? '') === '1') ? 'inline' : 'attachment';
header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disp . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
header('Content-Length: ' . strlen($pdf['bytes']));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
echo $pdf['bytes'];
