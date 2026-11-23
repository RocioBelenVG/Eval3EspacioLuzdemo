<?php
/**
 * Empresas emisoras de DTE — capa de datos.
 * Cada empresa es independiente y emite UN solo tipo de documento:
 * 'boleta' o 'factura'. Folio correlativo propio por empresa (ver lib/boletas.php).
 * MVP interno: sin conexión real al SII todavía.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';

/** Etiqueta legible del tipo de documento de una empresa. */
function tipoDocLabel(string $tipo, bool $plural = false): string
{
    $t = $tipo === 'factura' ? 'Factura' : 'Boleta';
    return $plural ? $t . 's' : $t;
}

/** Lista de empresas (por defecto solo activas). */
function empresasLista(bool $incluirInactivas = false): array
{
    try {
        $sql = "SELECT * FROM empresas";
        if (!$incluirInactivas) {
            $sql .= " WHERE activo = 1";
        }
        $sql .= " ORDER BY orden, id";
        return db()->query($sql)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Empresas activas (para selectores de emisión). */
function empresasActivas(): array
{
    return empresasLista(false);
}

function getEmpresa(int $id): ?array
{
    try {
        $st = db()->prepare("SELECT * FROM empresas WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Datos SII de la empresa (código de actividad, resolución, ciudad) validados.
 * ['ok'=>true,'v'=>[acteco, sii_resolucion_numero, sii_resolucion_fecha, ciudad]] o ['ok'=>false,'error'=>..].
 */
function empresaDatosSii(array $d): array
{
    $ac = preg_replace('/\D/', '', (string) ($d['acteco'] ?? ''));
    if ($ac !== '' && strlen($ac) !== 6) {
        return ['ok' => false, 'error' => 'El código de actividad económica del SII tiene 6 dígitos (ej.: 869090).'];
    }
    $num = trim((string) ($d['sii_resolucion_numero'] ?? ''));
    if ($num !== '' && !ctype_digit($num)) {
        return ['ok' => false, 'error' => 'El número de resolución SII debe ser un número (puede ser 0).'];
    }
    $fec = trim((string) ($d['sii_resolucion_fecha'] ?? ''));
    if ($fec !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fec) || !checkdate((int) substr($fec, 5, 2), (int) substr($fec, 8, 2), (int) substr($fec, 0, 4)))) {
        return ['ok' => false, 'error' => 'La fecha de resolución SII no es válida.'];
    }
    if (($num === '') !== ($fec === '')) {
        return ['ok' => false, 'error' => 'Completa el número Y la fecha de la resolución SII (o deja ambos vacíos).'];
    }
    $ciudad = trim((string) ($d['ciudad'] ?? ''));
    return ['ok' => true, 'v' => [$ac !== '' ? (int) $ac : null, $num !== '' ? (int) $num : null, $fec !== '' ? $fec : null, $ciudad !== '' ? mb_substr($ciudad, 0, 120, 'UTF-8') : null]];
}

/**
 * Tipos de DTE que la empresa tiene CERTIFICADOS ante el SII (p. ej. Espacio Luz: 34, 56, 61).
 * '' (vacío) = sin restricción. Solo se aceptan los tipos que maneja EspacioLuz.
 */
function empresaCertificadosNormalizar($v): ?string
{
    $lista = is_array($v) ? $v : preg_split('/\D+/', (string) $v);
    $t = array_values(array_unique(array_filter(array_map('intval', (array) $lista), fn($x) => in_array($x, [33, 34, 39, 41, 56, 61], true))));
    sort($t);
    return $t ? implode(',', $t) : null;
}

function empresaGuardarCertificados(int $id, array $d): void
{
    if (!array_key_exists('dte_certificados', $d)) {
        return;
    }
    try {
        db()->prepare("UPDATE empresas SET dte_certificados = ? WHERE id = ?")->execute([empresaCertificadosNormalizar($d['dte_certificados']), $id]);
    } catch (Throwable $e) {
        error_log('[empresas certificados] ' . $e->getMessage());
    }
}

function crearEmpresa(array $d): array
{
    $razon = trim((string) ($d['razon_social'] ?? ''));
    if ($razon === '') {
        return ['ok' => false, 'error' => 'La razón social es obligatoria.'];
    }
    $tipo = ($d['tipo_documento'] ?? '') === 'factura' ? 'factura' : 'boleta';
    $sii = empresaDatosSii($d);
    if (!$sii['ok']) {
        return $sii;
    }
    try {
        $st = db()->prepare(
            "INSERT INTO empresas (razon_social, nombre_fantasia, rut, giro, actividad_economica, direccion, comuna, email, telefono, tipo_documento, activo, orden,
                                   acteco, sii_resolucion_numero, sii_resolucion_fecha, ciudad)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $st->execute([
            mb_substr($razon, 0, 200, 'UTF-8'),
            trim((string) ($d['nombre_fantasia'] ?? '')) ?: null,
            trim((string) ($d['rut'] ?? '')) ?: null,
            trim((string) ($d['giro'] ?? '')) ?: null,
            trim((string) ($d['actividad_economica'] ?? '')) ?: null,
            trim((string) ($d['direccion'] ?? '')) ?: null,
            trim((string) ($d['comuna'] ?? '')) ?: null,
            trim((string) ($d['email'] ?? '')) ?: null,
            trim((string) ($d['telefono'] ?? '')) ?: null,
            $tipo,
            empty($d['activo']) ? 0 : 1,
            (int) ($d['orden'] ?? 0),
            ...$sii['v'],
        ]);
        $nuevoId = (int) db()->lastInsertId();
        empresaGuardarCertificados($nuevoId, $d);
        return ['ok' => true, 'id' => $nuevoId];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo crear la empresa.'];
    }
}

function actualizarEmpresa(int $id, array $d): array
{
    $razon = trim((string) ($d['razon_social'] ?? ''));
    if ($razon === '') {
        return ['ok' => false, 'error' => 'La razón social es obligatoria.'];
    }
    $emp = getEmpresa($id);
    if (!$emp) {
        return ['ok' => false, 'error' => 'Empresa no encontrada.'];
    }
    // Solo los documentos REALES (enviados al SII, serie 'sii') fijan el tipo de documento y el RUT.
    // Los internos son demo / pendientes de timbrar: no bloquean la configuración previa a producción.
    $docsSii = (int) db()->query("SELECT COUNT(*) FROM boletas WHERE serie = 'sii' AND empresa_id = " . (int) $id)->fetchColumn();
    $tipoPedido = ($d['tipo_documento'] ?? '') === 'factura' ? 'factura' : 'boleta';
    $tipo = $emp['tipo_documento'];
    if ($tipoPedido !== $emp['tipo_documento']) {
        if ($docsSii > 0) {
            return ['ok' => false, 'error' => 'No se puede cambiar el tipo de documento: esta empresa ya tiene documentos enviados al SII.'];
        }
        $tipo = $tipoPedido;
    }
    $rutNorm = fn($r) => strtoupper(preg_replace('/[^0-9kK]/', '', (string) $r));
    if ($docsSii > 0 && $rutNorm($d['rut'] ?? '') !== $rutNorm($emp['rut'] ?? '')) {
        return ['ok' => false, 'error' => 'No se puede cambiar el RUT: esta empresa ya tiene documentos enviados al SII con el RUT actual.'];
    }
    $sii = empresaDatosSii($d);
    if (!$sii['ok']) {
        return $sii;
    }
    try {
        $st = db()->prepare(
            "UPDATE empresas SET razon_social=?, nombre_fantasia=?, rut=?, giro=?, actividad_economica=?, direccion=?, comuna=?, email=?, telefono=?, tipo_documento=?, activo=?, orden=?,
                    acteco=?, sii_resolucion_numero=?, sii_resolucion_fecha=?, ciudad=? WHERE id=?"
        );
        $st->execute([
            mb_substr($razon, 0, 200, 'UTF-8'),
            trim((string) ($d['nombre_fantasia'] ?? '')) ?: null,
            trim((string) ($d['rut'] ?? '')) ?: null,
            trim((string) ($d['giro'] ?? '')) ?: null,
            trim((string) ($d['actividad_economica'] ?? '')) ?: null,
            trim((string) ($d['direccion'] ?? '')) ?: null,
            trim((string) ($d['comuna'] ?? '')) ?: null,
            trim((string) ($d['email'] ?? '')) ?: null,
            trim((string) ($d['telefono'] ?? '')) ?: null,
            $tipo,
            empty($d['activo']) ? 0 : 1,
            (int) ($d['orden'] ?? $emp['orden']),
            ...$sii['v'],
            $id,
        ]);
        empresaGuardarCertificados($id, $d);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo actualizar la empresa.'];
    }
}

function setEmpresaActivo(int $id, bool $activo): bool
{
    try {
        $st = db()->prepare("UPDATE empresas SET activo = ? WHERE id = ?");
        return $st->execute([$activo ? 1 : 0, $id]);
    } catch (Throwable $e) {
        return false;
    }
}

/** Elimina una empresa solo si no tiene documentos emitidos. */
function eliminarEmpresa(int $id): array
{
    try {
        $docs = (int) db()->query("SELECT COUNT(*) FROM boletas WHERE empresa_id = " . (int) $id)->fetchColumn();
        if ($docs > 0) {
            return ['ok' => false, 'error' => 'No se puede eliminar: la empresa tiene documentos emitidos. Puedes desactivarla.'];
        }
        // Los productos se borran en cascada (FK).
        $st = db()->prepare("DELETE FROM empresas WHERE id = ?");
        $st->execute([$id]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo eliminar la empresa.'];
    }
}
