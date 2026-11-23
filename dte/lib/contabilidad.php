<?php
declare(strict_types=1);
/**
 * Stub de contabilidad para EspacioLuz.
 *
 * El motor DTE replicado de Trivoxa-DTE llama a estas funciones como paso
 * "best-effort" (dentro de try/catch) para asentar/anular la venta en la
 * contabilidad del ERP. EspacioLuz NO lleva esa contabilidad, así que aquí
 * son no-op: no hacen nada y nunca rompen la emisión del documento.
 *
 * Si en el futuro EspacioLuz incorpora contabilidad, basta reemplazar estas
 * funciones por la implementación real (misma firma).
 */

if (!function_exists('cont_asentar_dte')) {
    function cont_asentar_dte(mysqli $conex, int $docId): void { /* no-op */ }
}

if (!function_exists('cont_anular_asiento')) {
    function cont_anular_asiento(mysqli $conex, string $tipo, int $docId, string $motivo = ''): void { /* no-op */ }
}
