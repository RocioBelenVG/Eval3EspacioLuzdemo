<?php
declare(strict_types=1);

/**
 * TRIVOXA ERP — Tipos de DTE disponibles por empresa (PRODUCCIÓN)
 * ------------------------------------------------------------------
 * Determina qué tipos de documento puede emitir una empresa según los
 * CAF de producción activos y con folios disponibles. Sirve para poblar
 * el selector de "Documentos" y para bloquear emisiones sin CAF.
 */

require_once __DIR__ . '/folios.php'; // dte_catalogo(), dte_nombre()

/**
 * Lista de tipos que la empresa PUEDE emitir ahora mismo.
 * Devuelve: [ ['tipo'=>33,'nombre'=>'Factura Electrónica','desde'=>..,'hasta'=>..,'actual'=>..,'disponibles'=>..], ... ]
 */
function dte_tipos_disponibles(mysqli $conex, int $empresaId, string $ambiente = 'produccion'): array {
    $out = [];
    if ($empresaId <= 0) return $out;
    $st = $conex->prepare(
        "SELECT tipo_dte, MIN(folio_desde) AS desde, MAX(folio_hasta) AS hasta,
                MIN(folio_actual) AS actual
         FROM caf_empresa
         WHERE empresa_id=? AND ambiente=? AND activo=1 AND folio_actual<=folio_hasta AND " . dte_caf_sql_vigente() . "
         GROUP BY tipo_dte ORDER BY tipo_dte"
    );
    $st->bind_param('is', $empresaId, $ambiente);
    $st->execute();
    $r = $st->get_result();
    $cat = dte_catalogo();
    while ($x = $r->fetch_assoc()) {
        $t = (int)$x['tipo_dte'];
        $disp = max(0, (int)$x['hasta'] - (int)$x['actual'] + 1);
        $out[] = [
            'tipo'        => $t,
            'nombre'      => $cat[$t] ?? ('DTE ' . $t),
            'desde'       => (int)$x['desde'],
            'hasta'       => (int)$x['hasta'],
            'actual'      => (int)$x['actual'],
            'disponibles' => $disp,
        ];
    }
    $st->close();
    return $out;
}

/** ¿La empresa puede emitir este tipo ahora? */
function dte_empresa_puede(mysqli $conex, int $empresaId, int $tipo, string $ambiente = 'produccion'): bool {
    foreach (dte_tipos_disponibles($conex, $empresaId, $ambiente) as $d) {
        if ($d['tipo'] === $tipo && $d['disponibles'] > 0) return true;
    }
    return false;
}

/** ¿La empresa tiene certificado cargado? */
function dte_empresa_tiene_cert(mysqli $conex, int $empresaId): bool {
    $st = $conex->prepare("SELECT 1 FROM empresa_cert WHERE empresa_id=? LIMIT 1");
    $st->bind_param('i', $empresaId); $st->execute();
    $ok = (bool)$st->get_result()->fetch_row(); $st->close();
    return $ok;
}

/**
 * Devuelve un mensaje legible si la empresa NO puede emitir el tipo dado,
 * o '' (cadena vacía) si todo está OK para emitir.
 */
function dte_motivo_no_disponible(mysqli $conex, int $empresaId, int $tipo, string $ambiente = 'produccion'): string {
    if ($empresaId <= 0) return 'Selecciona una empresa emisora.';
    if (!dte_empresa_tiene_cert($conex, $empresaId)) {
        return 'La empresa seleccionada no tiene su certificado digital cargado. Cárgalo en Folios y Credenciales.';
    }
    $nombre = dte_nombre($tipo);
    // Se evalúan TODOS los CAF del tipo: basta que UNO activo tenga folios.
    // (Antes tomaba el CAF más antiguo con LIMIT 1 y, si estaba agotado,
    //  bloqueaba la emisión aunque hubiera un CAF nuevo cargado.)
    $st = $conex->prepare("SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN activo=1 THEN 1 ELSE 0 END) AS activos,
            SUM(CASE WHEN activo=1 AND folio_actual<=folio_hasta THEN 1 ELSE 0 END) AS con_folios,
            SUM(CASE WHEN activo=1 AND folio_actual<=folio_hasta AND " . dte_caf_sql_vigente() . " THEN 1 ELSE 0 END) AS vigentes
        FROM caf_empresa
        WHERE empresa_id=? AND tipo_dte=? AND ambiente=?");
    $st->bind_param('iis', $empresaId, $tipo, $ambiente);
    $st->execute();
    $caf = $st->get_result()->fetch_assoc(); $st->close();
    if (!$caf || (int)$caf['total'] === 0) {
        return "La empresa seleccionada no tiene disponible {$nombre} ({$tipo}) en estos momentos (sin CAF de producción).";
    }
    if ((int)$caf['activos'] === 0) {
        return "El CAF de {$nombre} ({$tipo}) está desactivado para esta empresa.";
    }
    if ((int)$caf['con_folios'] === 0) {
        return "Se agotaron los folios de {$nombre} ({$tipo}). Carga un nuevo CAF de producción.";
    }
    if ((int)$caf['vigentes'] === 0) {
        return "Los folios de {$nombre} ({$tipo}) están vencidos (los CAF de este tipo vencen a los 6 meses). Solicita un CAF nuevo y anula los vencidos en sii.cl.";
    }
    return '';
}
