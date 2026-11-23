<?php
declare(strict_types=1);

/**
 * TRIVOXA ERP — Credenciales multi-empresa (PRODUCCIÓN)
 * ------------------------------------------------------------------
 * Cada empresa emisora tiene su propio certificado (.pfx) y sus CAF de
 * producción, aislados. La clave del .pfx se guarda CIFRADA con app_key.
 *
 *   priv/empresas/{empresa_id}/cert.pfx        certificado de la empresa
 *   priv/empresas/{empresa_id}/caf/CAF_NN_desde-hasta.xml  CAF en archivo (uno por rango)
 *
 * Folios y CAF viven en la tabla caf_empresa (ambiente='produccion').
 * Requiere: tabla empresa_cert y caf_empresa (ver sql/produccion_multiempresa.sql).
 */

// ---------------- Cifrado de la clave del certificado ----------------
function dte_key_bin(array $cfg): string {
    $hex = trim((string)($cfg['app_key'] ?? ''));
    if (strlen($hex) < 64 || !ctype_xdigit($hex)) {
        throw new RuntimeException('app_key inválida en config/produccion.php (64 hex). Genérala con bin2hex(random_bytes(32)).');
    }
    return substr(hex2bin($hex), 0, 32);
}
function dte_cifrar(array $cfg, string $plano): string {
    $k = dte_key_bin($cfg);
    $nonce = random_bytes(12); $tag = '';
    $c = openssl_encrypt($plano, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $nonce, $tag);
    return 'v1:' . base64_encode($nonce . $tag . $c);
}
function dte_descifrar(array $cfg, string $token): string {
    $k = dte_key_bin($cfg);
    if (strncmp($token, 'v1:', 3) !== 0) throw new RuntimeException('Token de clave inválido.');
    $raw = base64_decode(substr($token, 3));
    $nonce = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $c = substr($raw, 28);
    $out = openssl_decrypt($c, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($out === false) throw new RuntimeException('No se pudo descifrar la clave del certificado (app_key incorrecta).');
    return $out;
}

// ---------------- Rutas por empresa ----------------
function dte_dir_empresa(array $cfg, int $empresaId): string {
    $base = rtrim((string)($cfg['empresas_dir'] ?? (APP_PATH . '/priv/empresas')), '/');
    $dir = $base . '/' . $empresaId;
    foreach ([$dir, $dir . '/caf'] as $d) if (!is_dir($d)) @mkdir($d, 0700, true);
    return $dir;
}
function dte_cert_path(array $cfg, int $empresaId): string {
    return dte_dir_empresa($cfg, $empresaId) . '/cert.pfx';
}

// ---------------- Vencimiento de los CAF ----------------
/**
 * Los CAF autorizados desde el 01-07-2018 de factura afecta (33), liquidación-factura (43),
 * factura de compra (46), nota de débito (56) y nota de crédito (61) VENCEN a los 6 meses
 * (se usa 180 días desde la fecha de autorización del SII, el criterio más estricto).
 * Factura exenta (34) y boletas (39/41) no vencen. Un folio vencido es rechazado por el SII.
 */
const DTE_CAF_TIPOS_VENCEN = [33, 43, 46, 56, 61];
const DTE_CAF_DIAS_VIGENCIA = 180;

/** Fecha de vencimiento (Y-m-d) de un CAF, o null si su tipo no vence. Vigente mientras hoy < vence. */
function dte_caf_vence(int $tipo, ?string $fechaAutorizacion, ?string $creado = null): ?string {
    if (!in_array($tipo, DTE_CAF_TIPOS_VENCEN, true)) return null;
    $base = $fechaAutorizacion ?: ($creado ? substr($creado, 0, 10) : null);
    if (!$base || !strtotime($base)) return null;
    return date('Y-m-d', strtotime($base . ' +' . DTE_CAF_DIAS_VIGENCIA . ' days'));
}

/** Condición SQL: CAF vigente (no vencido) — para filtrar caf_empresa. */
function dte_caf_sql_vigente(): string {
    return "(tipo_dte NOT IN (33,43,46,56,61) OR COALESCE(fecha_autorizacion, DATE(created_at)) > (CURDATE() - INTERVAL 180 DAY))";
}

// ---------------- Guardar certificado de una empresa ----------------
/**
 * Extrae el RUT del titular de un certificado .pfx/.p12 (robusto).
 * Devuelve '' si no logra abrirlo o no encuentra RUT.
 */
function dte_rut_desde_pfx(string $pfxPath, string $pass): string {
    $cont = @file_get_contents($pfxPath);
    if ($cont === false || $cont === '') return '';
    $certs = [];
    if (!@openssl_pkcs12_read($cont, $certs, $pass)) return '';
    if (empty($certs['cert'])) return '';
    return dte_rut_desde_cert_pem((string)$certs['cert']);
}

/** Extrae el RUT del TITULAR desde el PEM del certificado.
 *  En certificados chilenos el RUT va en un otherName del subjectAltName que PHP NO
 *  decodifica ('othername:<unsupported>'). Se lee del DER crudo: el RUT del titular
 *  (subjectAltName) aparece ANTES que el de la CA (issuerAltName). */
function dte_rut_desde_cert_pem(string $pem): string {
    $rxp = '/(\d{7,8}-[\dkK])/';
    $norm = static function (string $s): string { return strtoupper(str_replace(['.', ' '], '', trim($s))); };
    $info = @openssl_x509_parse($pem);
    if (is_array($info)) {
        $sn = (string)($info['subject']['serialNumber'] ?? '');
        if ($sn && preg_match($rxp, $sn, $m)) return $norm($m[1]);
    }
    // DER crudo: primer RUT = titular (subjectAltName precede a issuerAltName).
    $b64 = preg_replace('/-----[^-]+-----|\s+/', '', $pem);
    $der = base64_decode((string)$b64, true);
    if ($der !== false && $der !== '' && preg_match($rxp, $der, $m)) return $norm($m[1]);
    return '';
}

/** Ejecuta openssl (CLI) con la clave en una variable de entorno y datos por stdin/stdout (nada en disco). */
function dte_openssl_cli(array $args, string $stdin, string $pass): array {
    if (!function_exists('proc_open')) return ['ok' => false, 'out' => '', 'err' => 'proc_open deshabilitado'];
    $bin = is_executable('/usr/bin/openssl') ? '/usr/bin/openssl' : 'openssl';
    $cmd = escapeshellarg($bin) . ' ' . implode(' ', array_map('escapeshellarg', $args));
    $env = ['DTE_PFX_PASS' => $pass, 'PATH' => '/usr/bin:/bin'];
    $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($p)) return ['ok' => false, 'out' => '', 'err' => 'no se pudo ejecutar openssl'];
    fwrite($pipes[0], $stdin); fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($p);
    return ['ok' => $code === 0, 'out' => $out, 'err' => $err];
}

/**
 * Certificados .pfx antiguos (cifrado RC2/3DES) no abren con OpenSSL 3 sin el proveedor
 * "legacy": PHP lo informa igual que una clave incorrecta. Se re-empaqueta el mismo
 * certificado y su llave con cifrado moderno (AES-256) y LA MISMA clave, todo en memoria:
 * openssl CLI con -legacy lo abre y la extensión de PHP lo vuelve a empaquetar.
 * Devuelve los bytes del .pfx moderno o null si no se pudo (p. ej. la clave sí es incorrecta).
 */
function dte_pfx_modernizar(string $cont, string $pass): ?string {
    $pem = dte_openssl_cli(['pkcs12', '-legacy', '-in', '/dev/stdin', '-passin', 'env:DTE_PFX_PASS', '-nodes'], $cont, $pass);
    if (!$pem['ok'] || !str_contains($pem['out'], 'PRIVATE KEY') || !str_contains($pem['out'], 'CERTIFICATE')) return null;
    // Re-empaquetado con la extensión openssl de PHP (OpenSSL 3 usa AES-256 + PBKDF2 por defecto).
    if (!preg_match('/-----BEGIN (?:RSA |EC |)PRIVATE KEY-----.+?-----END (?:RSA |EC |)PRIVATE KEY-----/s', $pem['out'], $mk)) return null;
    preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem['out'], $mc);
    $pem = null;
    $llave = @openssl_pkey_get_private($mk[0]);
    $mk = null;
    if ($llave === false || empty($mc[0])) return null;
    // Certificado del titular = el que corresponde a la llave; el resto es la cadena de la CA.
    $titular = null; $cadena = [];
    foreach ($mc[0] as $c) {
        if ($titular === null && @openssl_x509_check_private_key($c, $llave)) $titular = $c; else $cadena[] = $c;
    }
    if ($titular === null) return null;
    $out = '';
    if (!@openssl_pkcs12_export($titular, $out, $llave, $pass, $cadena ? ['extracerts' => $cadena] : [])) return null;
    $certs = [];
    return @openssl_pkcs12_read($out, $certs, $pass) ? $out : null;
}

function dte_guardar_cert(mysqli $conex, array $cfg, int $empresaId, string $pfxTmpPath, string $pass): array {
    // EspacioLuz Demo: no se aceptan certificados digitales reales.
    if (dte_modo_demo()) return ['ok' => false, 'error' => 'Modo demostración: no se cargan certificados digitales reales. Los documentos se emiten con folios de prueba.'];
    $cont = @file_get_contents($pfxTmpPath);
    if ($cont === false || $cont === '') throw new RuntimeException('No se recibió el archivo del certificado.');
    $certs = [];
    if (!@openssl_pkcs12_read($cont, $certs, $pass)) {
        while (openssl_error_string() !== false) { /* limpia la cola de errores */ }
        // ¿Certificado con cifrado antiguo? Se convierte a cifrado moderno con la misma clave.
        $moderno = dte_pfx_modernizar($cont, $pass);
        if ($moderno === null || !@openssl_pkcs12_read($moderno, $certs, $pass)) {
            throw new RuntimeException('El certificado no abre con esa clave (revisa la contraseña del .pfx).');
        }
        $cont = $moderno;
    }
    $rut = ''; $nombre = ''; $vence = null;
    if (!empty($certs['cert'])) {
        $info = openssl_x509_parse($certs['cert']);
        $nombre = (string)($info['subject']['CN'] ?? '');
        $vence  = isset($info['validTo_time_t']) ? date('Y-m-d', $info['validTo_time_t']) : null;
        $rut = dte_rut_desde_cert_pem((string)$certs['cert']);
    }
    file_put_contents(dte_cert_path($cfg, $empresaId), $cont);
    @chmod(dte_cert_path($cfg, $empresaId), 0600);
    $passEnc = dte_cifrar($cfg, $pass);

    $st = $conex->prepare("INSERT INTO empresa_cert (empresa_id, cert_rut, cert_pass_enc, cert_nombre, cert_vence, updated_at)
        VALUES (?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE cert_rut=VALUES(cert_rut), cert_pass_enc=VALUES(cert_pass_enc),
        cert_nombre=VALUES(cert_nombre), cert_vence=VALUES(cert_vence), updated_at=NOW()");
    if (!$st) throw new RuntimeException('prepare empresa_cert: ' . $conex->error);
    $st->bind_param('issss', $empresaId, $rut, $passEnc, $nombre, $vence);
    if (!$st->execute()) throw new RuntimeException('insert empresa_cert: ' . $st->error);
    $st->close();
    return ['rut' => $rut, 'nombre' => $nombre, 'vence' => $vence];
}

// ---------------- Guardar CAF de producción de una empresa ----------------
function dte_inspeccionar_caf(string $xml): ?array {
    // El CAF del SII suele venir en ISO-8859-1. Para LEERLO convertimos una
    // copia a UTF-8; el original se guarda intacto (si se altera, se rompe la firma).
    $parse = $xml;
    $tiene_mb = function_exists('mb_check_encoding');
    if ($tiene_mb && !mb_check_encoding($parse, 'UTF-8')) {
        $parse = mb_convert_encoding($parse, 'UTF-8', 'ISO-8859-1');
    } elseif (!$tiene_mb) {
        // Sin mbstring: intento de conversión básica desde ISO-8859-1
        $parse = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $parse) ?: $parse;
    }
    // quitar declaración para evitar conflicto de encoding al re-parsear
    $parse = preg_replace('/^<\?xml[^>]*\?>/', '', $parse);
    $prev = libxml_use_internal_errors(true);
    $sx = simplexml_load_string($parse);
    libxml_use_internal_errors($prev);
    if (!$sx || !isset($sx->CAF->DA)) return null;
    $da = $sx->CAF->DA;
    // IDK = identificador de la llave pública del SII con que se firmó el CAF.
    // NOTA: identifica la LLAVE, no el ambiente. El ambiente (producción/
    // certificación) lo determina dónde se solicitó el CAF (palena vs maullin),
    // no el contenido del archivo. Por eso NO se usa para decidir producción.
    $idk = isset($da->IDK) ? (int)$da->IDK : 0;
    // FA = fecha de autorización del CAF por el SII (AAAA-MM-DD). Sirve para avisar
    // de CAF antiguos (folios que llevan mucho sin usarse pueden requerir revisión).
    $fa = isset($da->FA) ? trim((string)$da->FA) : '';
    if ($fa !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fa)) {
        $ts = strtotime($fa); $fa = $ts ? date('Y-m-d', $ts) : '';
    }
    return [
        'tipo'  => (int)$da->TD,
        'rut'   => (string)$da->RE,
        'desde' => (int)$da->RNG->D,
        'hasta' => (int)$da->RNG->H,
        'idk'   => $idk,
        'fecha_autorizacion' => $fa,
    ];
}
// Archivo del CAF de un rango concreto (bytes exactos del SII).
function dte_caf_archivo(array $cfg, int $empresaId, int $tipo, int $desde, int $hasta): string {
    return dte_dir_empresa($cfg, $empresaId) . "/caf/CAF_{$tipo}_{$desde}-{$hasta}.xml";
}

// Rango [desde, hasta] declarado dentro de un CAF (o null si no se reconoce).
function dte_caf_rango(string $xml): ?array {
    if (!preg_match('#<RNG>\s*<D>\s*(\d+)\s*</D>\s*<H>\s*(\d+)\s*</H>#', $xml, $m)) return null;
    return [(int)$m[1], (int)$m[2]];
}

// Bytes del CAF de la fila $caf (folio_desde, folio_hasta, caf_xml), verificando que
// el rango del archivo sea EXACTAMENTE el de la fila. Orden: archivo por rango,
// archivo antiguo CAF_TT.xml (solo si calza) y copia de BD (solo si calza).
function dte_caf_bytes(array $cfg, int $empresaId, int $tipo, array $caf): string {
    $d = (int)$caf['folio_desde']; $h = (int)$caf['folio_hasta'];
    $candidatos = [];
    $f = dte_caf_archivo($cfg, $empresaId, $tipo, $d, $h);
    if (is_file($f)) $candidatos[] = (string)file_get_contents($f);
    $legado = dte_dir_empresa($cfg, $empresaId) . '/caf/CAF_' . $tipo . '.xml';
    if (is_file($legado)) $candidatos[] = (string)file_get_contents($legado);
    $raw = (string)($caf['caf_xml'] ?? '');
    $dec = base64_decode($raw, true);
    $candidatos[] = ($dec !== false && str_contains($dec, '<AUTORIZACION')) ? $dec : $raw;
    foreach ($candidatos as $c) {
        if ($c !== '' && dte_caf_rango($c) === [$d, $h]) return $c;
    }
    throw new RuntimeException("No se encontró el archivo del CAF {$d}-{$h} del tipo {$tipo}. Vuelve a subir ese CAF en Folios y Credenciales.");
}

function dte_guardar_caf(mysqli $conex, array $cfg, int $empresaId, string $cafXml, string $nombre = ''): array {
    // EspacioLuz Demo: los CAF reales del SII no se cargan; se usan CAF de prueba.
    if (dte_modo_demo()) return ['ok' => false, 'error' => 'Modo demostración: no se cargan CAF reales del SII. Usa «Solicitar folios» para crear un CAF de prueba.'];
    $info = dte_inspeccionar_caf($cafXml);
    if (!$info) throw new RuntimeException("Archivo CAF inválido: {$nombre}");

    // --- Validación correcta: el RUT del CAF debe ser el de la empresa seleccionada ---
    // (El ambiente producción/certificación NO se puede determinar desde el contenido
    //  del CAF; depende de DÓNDE se solicitó en el SII —palena=producción / maullin=
    //  certificación—. El campo IDK sólo identifica la llave del SII, no el ambiente.)
    $stE = $conex->prepare("SELECT rut FROM empresas WHERE id=? LIMIT 1");
    $stE->bind_param('i', $empresaId); $stE->execute();
    $emp = $stE->get_result()->fetch_assoc(); $stE->close();
    if ($emp) {
        $norm = static function (string $r): string {
            return strtoupper(str_replace(['.', ' ', '-'], '', $r));
        };
        $rutCaf = $norm((string)$info['rut']);
        $rutEmp = $norm((string)$emp['rut']);
        $strict = !array_key_exists('caf_strict_produccion', $cfg) || !empty($cfg['caf_strict_produccion']);
        if ($strict && $rutCaf !== '' && $rutEmp !== '' && $rutCaf !== $rutEmp) {
            throw new RuntimeException(
                "Este CAF pertenece al RUT {$info['rut']}, distinto al de la empresa seleccionada ({$emp['rut']}). " .
                "Sube el CAF que corresponde a esta empresa."
            );
        }
    }

    // respaldo en archivo, aislado por empresa (BYTES EXACTOS: la firma del CAF
    // depende de ellos). Un archivo POR RANGO (CAF_TT_desde-hasta.xml): antes se usaba
    // un único CAF_TT.xml que cada carga sobrescribía, y el emisor firmaba folios de
    // un CAF anterior con el CAF nuevo (folio fuera de rango, rechazo del SII).
    file_put_contents(dte_caf_archivo($cfg, $empresaId, (int)$info['tipo'], (int)$info['desde'], (int)$info['hasta']), $cafXml);
    // En BD se guarda en base64 para no corromper los bytes Latin-1 en utf8mb4.
    $cafB64 = base64_encode($cafXml);
    // registrar en BD (producción). folio_actual parte en folio_desde.
    $st = $conex->prepare("INSERT INTO caf_empresa
        (empresa_id, tipo_dte, ambiente, folio_desde, folio_hasta, folio_actual, caf_xml, caf_nombre, activo, created_at, updated_at)
        VALUES (?,?, 'produccion', ?,?,?,?,?,1,NOW(),NOW())
        ON DUPLICATE KEY UPDATE folio_hasta=VALUES(folio_hasta), caf_xml=VALUES(caf_xml),
        caf_nombre=VALUES(caf_nombre), activo=1, updated_at=NOW()");
    if (!$st) throw new RuntimeException('prepare caf_empresa: ' . $conex->error);
    $st->bind_param('iiiiiss', $empresaId, $info['tipo'], $info['desde'], $info['hasta'], $info['desde'], $cafB64, $nombre);
    if (!$st->execute()) throw new RuntimeException('insert caf_empresa: ' . $st->error);
    $st->close();
    // Guardar la fecha de autorización del SII (best-effort: la columna es opcional).
    if (!empty($info['fecha_autorizacion'])) {
        $fa = $conex->real_escape_string((string)$info['fecha_autorizacion']);
        @$conex->query("UPDATE caf_empresa SET fecha_autorizacion='$fa'
            WHERE empresa_id=" . (int)$empresaId . " AND tipo_dte=" . (int)$info['tipo'] . " AND ambiente='produccion'");
    }
    return $info;
}

// ---------------- Borrar un CAF de una empresa ----------------
function dte_borrar_caf(mysqli $conex, array $cfg, int $empresaId, int $cafId): void {
    // ubicar tipo para borrar también el respaldo en archivo
    $st = $conex->prepare("SELECT tipo_dte, folio_desde, folio_hasta FROM caf_empresa WHERE id=? AND empresa_id=? LIMIT 1");
    $st->bind_param('ii', $cafId, $empresaId); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if (!$row) throw new RuntimeException('El CAF no existe o no pertenece a esta empresa.');
    $tipo = (int)$row['tipo_dte'];

    $st = $conex->prepare("DELETE FROM caf_empresa WHERE id=? AND empresa_id=? LIMIT 1");
    $st->bind_param('ii', $cafId, $empresaId);
    if (!$st->execute()) throw new RuntimeException('No se pudo borrar el CAF: ' . $st->error);
    $st->close();

    // Solo el archivo de ESTE rango: los demás CAF del tipo siguen vigentes.
    $f = dte_caf_archivo($cfg, $empresaId, $tipo, (int)$row['folio_desde'], (int)$row['folio_hasta']);
    if (is_file($f)) @unlink($f);
}

// ---------------- Estado de credenciales de una empresa ----------------
// Devuelve: ['tiene_cert'=>bool, 'cert'=>row|null, 'tipos_activos'=>[int,...]]
function dte_empresa_estado(mysqli $conex, int $empresaId): array {
    $cert = null;
    $st = $conex->prepare("SELECT cert_rut, cert_nombre, cert_vence FROM empresa_cert WHERE empresa_id=? LIMIT 1");
    $st->bind_param('i', $empresaId); $st->execute();
    $cert = $st->get_result()->fetch_assoc() ?: null; $st->close();

    $tipos = [];
    $st = $conex->prepare("SELECT DISTINCT tipo_dte FROM caf_empresa
        WHERE empresa_id=? AND ambiente='produccion' AND activo=1 AND folio_actual<=folio_hasta AND " . dte_caf_sql_vigente() . "
        ORDER BY tipo_dte");
    $st->bind_param('i', $empresaId); $st->execute();
    $r = $st->get_result();
    while ($x = $r->fetch_assoc()) $tipos[] = (int)$x['tipo_dte'];
    $st->close();

    return ['tiene_cert' => (bool)$cert, 'cert' => $cert, 'tipos_activos' => $tipos];
}

// ---------------- Resolver config de Trivoxa API DTE para una empresa+tipo ----------------
/**
 * Devuelve [cfgEmpresa, cafRow]. cfgEmpresa sirve para new TrivoxaApiClient(),
 * con el certificado de la empresa y el CAF del tipo (escrito a archivo temporal).
 */
/**
 * Datos del certificado de una empresa (RUT titular, RUT empresa, ruta y clave
 * descifrada), SIN requerir que exista un CAF. Se usa tanto para emitir DTE
 * (dte_resolver_cfg) como para pedirle folios nuevos al SII vía Trivoxa API DTE
 * Folios (lib/dte_folios_sii.php), que puede necesitarse ANTES de tener
 * ningún CAF cargado para ese tipo.
 */
function dte_cert_datos(mysqli $conex, array $cfgBase, int $empresaId): array {
    $st = $conex->prepare("SELECT cert_rut, cert_pass_enc FROM empresa_cert WHERE empresa_id=? LIMIT 1");
    $st->bind_param('i', $empresaId); $st->execute();
    $cert = $st->get_result()->fetch_assoc(); $st->close();
    if (!$cert) throw new RuntimeException('La empresa no tiene certificado cargado. Cárgalo en Folios y Credenciales.');
    $certPath = dte_cert_path($cfgBase, $empresaId);
    if (!is_file($certPath)) throw new RuntimeException('No existe el archivo del certificado de la empresa.');
    $pass = dte_descifrar($cfgBase, (string)$cert['cert_pass_enc']);

    $rutEmpresa = '';
    $stE = $conex->prepare("SELECT rut FROM empresas WHERE id=? LIMIT 1");
    $stE->bind_param('i', $empresaId); $stE->execute();
    if ($row = $stE->get_result()->fetch_assoc()) {
        $rutEmpresa = strtoupper(str_replace(['.', ' '], '', trim((string)$row['rut'])));
    }
    $stE->close();

    $certRut = dte_rut_desde_pfx($certPath, $pass);
    if ($certRut === '') $certRut = strtoupper(str_replace(['.', ' '], '', trim((string)$cert['cert_rut'])));
    if ($certRut === '') {
        throw new RuntimeException('No se pudo determinar el RUT del certificado. Verifica la clave del .pfx en Folios y Credenciales.');
    }
    if ($certRut !== trim((string)$cert['cert_rut'])) {
        $up = $conex->prepare("UPDATE empresa_cert SET cert_rut=? WHERE empresa_id=?");
        $up->bind_param('si', $certRut, $empresaId); $up->execute(); $up->close();
    }

    return [
        'cert_path'   => $certPath,
        'cert_pass'   => $pass,
        'cert_rut'    => $certRut,             // titular del certificado
        'rut_empresa' => $rutEmpresa ?: $certRut,
    ];
}

function dte_resolver_cfg(mysqli $conex, array $cfgBase, int $empresaId, int $tipo): array {
    // Certificado
    $certDatos = dte_cert_datos($conex, $cfgBase, $empresaId);
    $certPath  = $certDatos['cert_path'];
    $pass      = $certDatos['cert_pass'];

    // CAF del tipo (producción, activo) que TODAVÍA TENGA FOLIOS disponibles.
    // Clave: al subir un CAF nuevo, el viejo agotado sigue en la tabla; si no se
    // filtra folio_actual<=folio_hasta, el emisor tomaría el agotado y diría
    // "sin folios" aunque haya un CAF nuevo cargado. Se usa el más antiguo con
    // folios disponibles (consume en orden correlativo).
    // Además se saltan los CAF VENCIDOS (33/43/46/56/61 a los 6 meses): el SII rechaza esos folios.
    $st = $conex->prepare("SELECT id, folio_desde, folio_hasta, folio_actual, caf_xml
        FROM caf_empresa
        WHERE empresa_id=? AND tipo_dte=? AND ambiente='produccion' AND activo=1
          AND folio_actual <= folio_hasta AND " . dte_caf_sql_vigente() . "
        ORDER BY folio_desde ASC LIMIT 1");
    $st->bind_param('ii', $empresaId, $tipo); $st->execute();
    $caf = $st->get_result()->fetch_assoc(); $st->close();
    if (!$caf) throw new RuntimeException("La empresa no tiene CAF de producción vigente con folios disponibles para el tipo {$tipo} (los CAF de 33/56/61 vencen a los 6 meses). Solicita o carga un CAF nuevo.");

    // Escribir el CAF a archivo temporal para Trivoxa API DTE.
    // IMPORTANTE: el CAF está firmado por el SII sobre sus BYTES EXACTOS (Latin-1). La
    // copia guardada en la columna caf_xml (utf8mb4) corrompe los acentos y rompe la
    // firma -> CAF-3-514. Por eso se usa el RESPALDO EN DISCO, que conserva los bytes
    // originales tal como se subieron. Solo si no existe, se cae a la copia de BD.
    // Se usa el CAF de ESTA fila (su rango exacto), no el último archivo subido del tipo.
    $cafBytes = dte_caf_bytes($cfgBase, $empresaId, $tipo, $caf);
    $cafTmp = sys_get_temp_dir() . "/caf_{$empresaId}_{$tipo}_" . getmypid() . '_' . mt_rand(1000, 9999) . '.xml';
    file_put_contents($cafTmp, $cafBytes);
    // El CAF temporal contiene la autorización firmada del SII: se borra al terminar
    // el request en vez de acumularse en el directorio temporal del servidor.
    register_shutdown_function(static function () use ($cafTmp) { if (is_file($cafTmp)) @unlink($cafTmp); });

    $cfg = $cfgBase;
    $cfg['cert_path'] = $certPath;
    $cfg['cert_pass'] = $pass;
    $cfg['cert_rut']     = $certDatos['cert_rut'];    // titular del certificado (de aquí sale RutEnvia)
    $cfg['emisor_rut']   = $certDatos['rut_empresa']; // la empresa que emite
    // api_user_rut / api_user_pass se conservan de la config (cuenta Trivoxa API DTE/SII).
    $cfg["caf{$tipo}_path"] = $cafTmp;
    return [$cfg, $caf];
}

// Avanzar el folio tras una emisión exitosa.
// Avance MONOTÓNICO (GREATEST): folio_actual NUNCA retrocede. Así, aunque llegue
// un folio explícito antiguo, el correlativo no baja y no se reutilizan folios
// ya consumidos (defensa en profundidad frente a folios duplicados ante el SII).
function dte_consumir_folio(mysqli $conex, int $cafId, int $folioUsado): void {
    $st = $conex->prepare("UPDATE caf_empresa SET folio_actual=GREATEST(folio_actual, ?), updated_at=NOW() WHERE id=?");
    $next = $folioUsado + 1;
    $st->bind_param('ii', $next, $cafId); $st->execute(); $st->close();
}

// Reserva ATÓMICA del próximo folio de un CAF concreto: evita que dos emisiones
// concurrentes (doble clic, dos pestañas, dos usuarios) tomen el MISMO folio y lo
// envíen dos veces al SII. Avanza folio_actual dentro de una transacción con
// SELECT ... FOR UPDATE. Devuelve el folio reservado, o null si el CAF no tiene
// folios. Si el documento termina RECHAZADO o falla el guardado, hay que
// devolverlo con dte_liberar_folio (retrocede solo si sigue siendo el último).
function dte_reservar_folio(mysqli $conex, int $cafId): ?int {
    if ($cafId <= 0) return null;
    mysqli_begin_transaction($conex);
    try {
        $st = $conex->prepare("SELECT folio_actual, folio_hasta FROM caf_empresa WHERE id=? FOR UPDATE");
        if (!$st) { $conex->rollback(); return null; }
        $st->bind_param('i', $cafId); $st->execute();
        $r = $st->get_result()->fetch_assoc(); $st->close();
        if (!$r || (int)$r['folio_actual'] > (int)$r['folio_hasta']) { $conex->rollback(); return null; }
        $folio = (int)$r['folio_actual'];
        $up = $conex->prepare("UPDATE caf_empresa SET folio_actual=folio_actual+1, updated_at=NOW() WHERE id=?");
        $up->bind_param('i', $cafId); $up->execute(); $up->close();
        $conex->commit();
        return $folio;
    } catch (\Throwable $e) {
        $conex->rollback();
        return null;
    }
}

// Liberar (devolver) un folio que se había consumido pero cuyo documento el SII
// terminó RECHAZANDO. Sólo retrocede el correlativo si ese folio era el último
// consumido (folio_actual == folio+1); si otra emisión ya avanzó más, no toca
// nada para no provocar reutilización de folios válidos.
function dte_liberar_folio(mysqli $conex, int $cafId, int $folio): bool {
    if ($cafId <= 0 || $folio <= 0) return false;
    $st = $conex->prepare("UPDATE caf_empresa SET folio_actual=?, updated_at=NOW() WHERE id=? AND folio_actual=?");
    if (!$st) return false;
    $siguiente = $folio + 1;
    $st->bind_param('iii', $folio, $cafId, $siguiente);
    $st->execute();
    $ok = $st->affected_rows > 0;
    $st->close();
    return $ok;
}
