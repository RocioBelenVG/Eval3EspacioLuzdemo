<?php
declare(strict_types=1);
/**
 * ============================================================
 *  TRIVOXA ERP  -  Librería de Folios / CAF
 * ============================================================
 *  Lee el rango de un CAF (TD, D, H) y administra el correlativo
 *  de folios por empresa y tipo de DTE.
 * ============================================================
 */

/** Catálogo de tipos de DTE que el SII puede certificar. */
function dte_catalogo(): array {
    return [
        33  => 'Factura Electrónica',
        34  => 'Factura Exenta Electrónica',
        39  => 'Boleta Electrónica',
        41  => 'Boleta Exenta Electrónica',
        43  => 'Liquidación Factura Electrónica',
        46  => 'Factura de Compra Electrónica',
        52  => 'Guía de Despacho Electrónica',
        56  => 'Nota de Débito Electrónica',
        61  => 'Nota de Crédito Electrónica',
        110 => 'Factura de Exportación Electrónica',
        111 => 'Nota de Débito de Exportación',
        112 => 'Nota de Crédito de Exportación',
    ];
}

function dte_nombre(int $tipo): string {
    return dte_catalogo()[$tipo] ?? ('DTE ' . $tipo);
}

/**
 * Extrae TD (tipo), D (folio desde) y H (folio hasta) de un CAF XML.
 * @return array{ok:bool, tipo:?int, desde:?int, hasta:?int, error:?string}
 */
function caf_parse(string $xml): array {
    // Búsqueda tolerante (el CAF puede venir con o sin namespaces)
    $td = $d = $h = null;
    if (preg_match('#<TD>\s*(\d+)\s*</TD>#', $xml, $m)) $td = (int)$m[1];
    if (preg_match('#<RNG>.*?<D>\s*(\d+)\s*</D>#s', $xml, $m)) $d = (int)$m[1];
    if (preg_match('#<RNG>.*?<H>\s*(\d+)\s*</H>#s', $xml, $m)) $h = (int)$m[1];
    // Respaldo: primeros <D>/<H> sueltos
    if ($d === null && preg_match('#<D>\s*(\d+)\s*</D>#', $xml, $m)) $d = (int)$m[1];
    if ($h === null && preg_match('#<H>\s*(\d+)\s*</H>#', $xml, $m)) $h = (int)$m[1];

    if ($td === null || $d === null || $h === null) {
        return ['ok'=>false,'tipo'=>$td,'desde'=>$d,'hasta'=>$h,'error'=>'No se pudo leer TD/RNG del CAF. ¿Es un archivo CAF válido del SII?'];
    }
    if ($h < $d) {
        return ['ok'=>false,'tipo'=>$td,'desde'=>$d,'hasta'=>$h,'error'=>'Rango inválido (Hasta < Desde).'];
    }
    return ['ok'=>true,'tipo'=>$td,'desde'=>$d,'hasta'=>$h,'error'=>null];
}

/**
 * Devuelve el próximo folio disponible para emitir (sin consumirlo).
 * Retorna null si no hay CAF activo o se agotó el rango.
 */
function folio_proximo(mysqli $conex, int $empresa_id, int $tipo_dte, string $ambiente): ?int {
    $st = mysqli_prepare($conex,
        "SELECT folio_actual, folio_hasta FROM caf_empresa
         WHERE empresa_id=? AND tipo_dte=? AND ambiente=? AND activo=1
         ORDER BY folio_desde ASC LIMIT 1");
    mysqli_stmt_bind_param($st, 'iis', $empresa_id, $tipo_dte, $ambiente);
    mysqli_stmt_execute($st);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    if (!$r) return null;
    if ((int)$r['folio_actual'] > (int)$r['folio_hasta']) return null; // rango agotado
    return (int)$r['folio_actual'];
}

/**
 * Consume (reserva) el próximo folio de forma atómica y lo devuelve.
 * Llamar JUSTO antes de emitir. Devuelve null si no hay folios.
 *
 * Úsalo en los simpleapi_emitir_*.php así:
 *   $folio = folio_consumir($conex,$empresa_id,33,$ambiente);
 *   if ($folio===null) { ...sin folios... }
 */
function folio_consumir(mysqli $conex, int $empresa_id, int $tipo_dte, string $ambiente): ?int {
    mysqli_begin_transaction($conex);
    try {
        $st = mysqli_prepare($conex,
            "SELECT id, folio_actual, folio_hasta FROM caf_empresa
             WHERE empresa_id=? AND tipo_dte=? AND ambiente=? AND activo=1
             ORDER BY folio_desde ASC LIMIT 1 FOR UPDATE");
        mysqli_stmt_bind_param($st, 'iis', $empresa_id, $tipo_dte, $ambiente);
        mysqli_stmt_execute($st);
        $r = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        if (!$r || (int)$r['folio_actual'] > (int)$r['folio_hasta']) {
            mysqli_rollback($conex);
            return null;
        }
        $folio = (int)$r['folio_actual'];
        $up = mysqli_prepare($conex, "UPDATE caf_empresa SET folio_actual=folio_actual+1 WHERE id=?");
        $id = (int)$r['id'];
        mysqli_stmt_bind_param($up, 'i', $id);
        mysqli_stmt_execute($up);
        mysqli_commit($conex);
        return $folio;
    } catch (\Throwable $e) {
        mysqli_rollback($conex);
        return null;
    }
}
