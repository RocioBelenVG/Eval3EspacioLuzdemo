<?php
declare(strict_types=1);

/**
 * EspacioLuz — Folios (CAF) de PRODUCCIÓN: estado por tipo y solicitud al SII
 * ---------------------------------------------------------------------------
 * Basado en lib/dte_folios_sii.php de Trivoxa-DTE (mismo endpoint y formato):
 *
 *   POST {base_url}/api/folios/get/{TIPO_DTE}/{CANTIDAD}      (Trivoxa API DTE)
 *     multipart: input = {"RutCertificado","Password","RutEmpresa","Ambiente":1} + files = .pfx
 *     Éxito: XML <AUTORIZACION><CAF>…</CAF>…</AUTORIZACION>  → se guarda con dte_guardar_caf().
 *     Error: JSON {"Mensaje": "…"} con la respuesta del SII (se muestra tal cual).
 *
 * La API entra al timbraje del SII (palena.sii.cl) con el certificado de la empresa.
 * Aquí se agrega, por empresa y tipo:
 *   - configuración (folios_config): solicitud automática sí/no, umbral, cantidad y el
 *     máximo de folios que informó el SII (cuando lo informa);
 *   - historial (folios_solicitudes): cada solicitud con la respuesta del SII.
 * La solicitud automática (cron) SOLO actúa sobre tipos que ya tuvieron un CAF cargado
 * (el primer CAF siempre se pide a mano, una vez terminada la certificación).
 */

require_once __DIR__ . '/dte_cred.php';    // dte_cert_datos(), dte_guardar_caf()
require_once __DIR__ . '/folios.php';      // dte_nombre()

const FOLIOS_LIMITE_MES    = 300; // tope de solicitudes al SII por mes (todas las empresas)
const FOLIOS_COOLDOWN_HRS  = 20;  // la automática no reintenta la misma empresa+tipo antes de esto
const FOLIOS_UMBRAL_DEF    = 10;  // pedir más cuando queden esta cantidad o menos
const FOLIOS_CANTIDAD_DEF  = 20;  // cantidad por defecto de cada solicitud
const FOLIOS_AVISO_VENCE_DIAS = 15; // avisar (y renovar en automático) cuando el CAF vigente vence en estos días

/** Valores por defecto según el tipo: las notas (56/61) se emiten poco y sus CAF vencen: lotes chicos. */
function dte_folios_defaults(int $tipo): array {
    return in_array($tipo, [56, 61], true) ? ['umbral' => 2, 'cantidad' => 5] : ['umbral' => FOLIOS_UMBRAL_DEF, 'cantidad' => FOLIOS_CANTIDAD_DEF];
}

function dte_folios_tablas(mysqli $conex): void {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    @mysqli_query($conex, "CREATE TABLE IF NOT EXISTS folios_solicitudes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        empresa_id INT UNSIGNED NOT NULL,
        tipo_dte SMALLINT UNSIGNED NOT NULL,
        cantidad_solicitada INT UNSIGNED NOT NULL DEFAULT 0,
        ok TINYINT(1) NOT NULL DEFAULT 0,
        folio_desde INT UNSIGNED NULL,
        folio_hasta INT UNSIGNED NULL,
        maximo_sii INT UNSIGNED NULL,
        mensaje TEXT NULL,
        origen VARCHAR(20) NOT NULL DEFAULT 'manual',
        usuario VARCHAR(160) NULL,
        creado_en DATETIME NOT NULL,
        KEY idx_mes (creado_en),
        KEY idx_emp_tipo (empresa_id, tipo_dte, creado_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Solicitudes de folios (CAF) al SII y su respuesta'");
    @mysqli_query($conex, "CREATE TABLE IF NOT EXISTS folios_config (
        empresa_id INT UNSIGNED NOT NULL,
        tipo_dte SMALLINT UNSIGNED NOT NULL,
        auto TINYINT(1) NOT NULL DEFAULT 1,
        umbral INT UNSIGNED NOT NULL DEFAULT 10,
        cantidad INT UNSIGNED NOT NULL DEFAULT 20,
        maximo_sii INT UNSIGNED NULL,
        maximo_fecha DATETIME NULL,
        PRIMARY KEY (empresa_id, tipo_dte)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Solicitud automática de folios por empresa y tipo'");
}

/** Tipos de DTE que maneja una empresa: los certificados, o (sin lista) según su tipo de documento. */
function dte_folios_tipos_empresa(mysqli $conex, int $empresaId): array {
    $st = $conex->prepare("SELECT tipo_documento, dte_certificados FROM empresas WHERE id=? LIMIT 1");
    $st->bind_param('i', $empresaId); $st->execute();
    $e = $st->get_result()->fetch_assoc() ?: []; $st->close();
    $cert = trim((string)($e['dte_certificados'] ?? ''));
    $tipos = $cert !== '' ? array_map('intval', preg_split('/\D+/', $cert)) : ((($e['tipo_documento'] ?? 'boleta') === 'factura') ? [33, 34, 56, 61] : [39, 41]);
    // + cualquier tipo que ya tenga CAF cargado
    $st = $conex->prepare("SELECT DISTINCT tipo_dte FROM caf_empresa WHERE empresa_id=? AND ambiente='produccion'");
    $st->bind_param('i', $empresaId); $st->execute();
    $r = $st->get_result();
    while ($x = $r->fetch_assoc()) $tipos[] = (int)$x['tipo_dte'];
    $st->close();
    $tipos = array_values(array_unique(array_filter($tipos, fn($t) => $t > 0)));
    sort($tipos);
    return $tipos;
}

function dte_folios_config(mysqli $conex, int $empresaId, int $tipo): array {
    dte_folios_tablas($conex);
    $st = $conex->prepare("SELECT * FROM folios_config WHERE empresa_id=? AND tipo_dte=? LIMIT 1");
    $st->bind_param('ii', $empresaId, $tipo); $st->execute();
    $c = $st->get_result()->fetch_assoc(); $st->close();
    $def = dte_folios_defaults($tipo);
    return $c ?: ['empresa_id' => $empresaId, 'tipo_dte' => $tipo, 'auto' => 1, 'umbral' => $def['umbral'], 'cantidad' => $def['cantidad'], 'maximo_sii' => null, 'maximo_fecha' => null];
}

function dte_folios_guardar_config(mysqli $conex, int $empresaId, int $tipo, bool $auto, int $umbral, int $cantidad): array {
    dte_folios_tablas($conex);
    $umbral = max(1, min(5000, $umbral));
    $cantidad = max(1, min(10000, $cantidad));
    $a = $auto ? 1 : 0;
    $st = $conex->prepare("INSERT INTO folios_config (empresa_id, tipo_dte, auto, umbral, cantidad) VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE auto=VALUES(auto), umbral=VALUES(umbral), cantidad=VALUES(cantidad)");
    $st->bind_param('iiiii', $empresaId, $tipo, $a, $umbral, $cantidad); $st->execute(); $st->close();
    return dte_folios_config($conex, $empresaId, $tipo);
}

/**
 * Estado de folios por tipo de documento de una empresa:
 * [tipo => [tipo, nombre, disponibles (solo vigentes), desde, hasta, actual, cafs, ya_tuvo_caf,
 *           vence (del CAF vigente más próximo a vencer, o null), vence_dias, vencen (bool: el tipo vence),
 *           vencidos (folios sin usar en CAF vencidos), vencidos_rangos [[desde,hasta,venció]],
 *           estado, config, ultima]]
 * estado: 'sin_caf' | 'agotado' | 'vencido' | 'por_vencer' | 'bajo' | 'ok'.
 */
function dte_folios_resumen(mysqli $conex, int $empresaId): array {
    dte_folios_tablas($conex);
    $hoy = date('Y-m-d');
    $nuevo = fn(int $t) => ['tipo' => $t, 'nombre' => dte_nombre($t), 'disponibles' => 0, 'desde' => null, 'hasta' => null, 'actual' => null, 'cafs' => 0, 'ya_tuvo_caf' => false,
                            'vence' => null, 'vence_dias' => null, 'vencen' => in_array($t, DTE_CAF_TIPOS_VENCEN, true), 'vencidos' => 0, 'vencidos_rangos' => []];
    $out = [];
    foreach (dte_folios_tipos_empresa($conex, $empresaId) as $t) $out[$t] = $nuevo($t);
    $st = $conex->prepare("SELECT tipo_dte, folio_desde, folio_hasta, folio_actual, activo, fecha_autorizacion, created_at FROM caf_empresa WHERE empresa_id=? AND ambiente='produccion' ORDER BY tipo_dte, folio_desde");
    $st->bind_param('i', $empresaId); $st->execute();
    $r = $st->get_result();
    while ($x = $r->fetch_assoc()) {
        $t = (int)$x['tipo_dte'];
        $out[$t] ??= $nuevo($t);
        $out[$t]['ya_tuvo_caf'] = true;
        if ((int)$x['activo'] !== 1) continue;
        $disp = max(0, (int)$x['folio_hasta'] - (int)$x['folio_actual'] + 1);
        if ($disp <= 0) continue;
        $vence = dte_caf_vence($t, $x['fecha_autorizacion'] ?? null, $x['created_at'] ?? null);
        if ($vence !== null && $vence <= $hoy) {
            // CAF vencido con folios sin usar: no sirven; hay que anularlos en sii.cl.
            $out[$t]['vencidos'] += $disp;
            $out[$t]['vencidos_rangos'][] = [(int)$x['folio_actual'], (int)$x['folio_hasta'], $vence];
            continue;
        }
        $out[$t]['cafs']++;
        $out[$t]['disponibles'] += $disp;
        if ($out[$t]['actual'] === null) { $out[$t]['actual'] = (int)$x['folio_actual']; $out[$t]['desde'] = (int)$x['folio_desde']; }
        $out[$t]['hasta'] = (int)$x['folio_hasta'];
        if ($vence !== null && ($out[$t]['vence'] === null || $vence < $out[$t]['vence'])) $out[$t]['vence'] = $vence;
    }
    $st->close();
    foreach ($out as $t => &$o) {
        $o['config'] = dte_folios_config($conex, $empresaId, $t);
        $o['vence_dias'] = $o['vence'] !== null ? (int)floor((strtotime($o['vence']) - strtotime($hoy)) / 86400) : null;
        $o['estado'] = !$o['ya_tuvo_caf'] ? 'sin_caf'
            : ($o['disponibles'] <= 0 ? ($o['vencidos'] > 0 ? 'vencido' : 'agotado')
            : ($o['vence_dias'] !== null && $o['vence_dias'] <= FOLIOS_AVISO_VENCE_DIAS ? 'por_vencer'
            : ($o['disponibles'] <= (int)$o['config']['umbral'] ? 'bajo' : 'ok')));
        $q = $conex->prepare("SELECT * FROM folios_solicitudes WHERE empresa_id=? AND tipo_dte=? ORDER BY id DESC LIMIT 1");
        $q->bind_param('ii', $empresaId, $t); $q->execute();
        $o['ultima'] = $q->get_result()->fetch_assoc() ?: null; $q->close();
    }
    unset($o);
    ksort($out);
    return $out;
}

/** Historial de solicitudes de la empresa (más recientes primero). */
function dte_folios_historial(mysqli $conex, int $empresaId, int $limite = 15): array {
    dte_folios_tablas($conex);
    $st = $conex->prepare("SELECT * FROM folios_solicitudes WHERE empresa_id=? ORDER BY id DESC LIMIT ?");
    $st->bind_param('ii', $empresaId, $limite); $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    return $rows;
}

function dte_folios_estado_cuota(mysqli $conex): array {
    dte_folios_tablas($conex);
    $usados = 0;
    $r = @mysqli_query($conex, "SELECT COUNT(*) AS n FROM folios_solicitudes WHERE creado_en >= DATE_FORMAT(NOW(), '%Y-%m-01')");
    if ($r && ($row = mysqli_fetch_assoc($r))) $usados = (int)$row['n'];
    return ['usados' => $usados, 'limite' => FOLIOS_LIMITE_MES, 'restantes' => max(0, FOLIOS_LIMITE_MES - $usados)];
}

function dte_folios_cooldown_activo(mysqli $conex, int $empresaId, int $tipo, int $horas = FOLIOS_COOLDOWN_HRS): bool {
    dte_folios_tablas($conex);
    $st = $conex->prepare("SELECT 1 FROM folios_solicitudes WHERE empresa_id=? AND tipo_dte=? AND creado_en >= (NOW() - INTERVAL ? HOUR) LIMIT 1");
    $st->bind_param('iii', $empresaId, $tipo, $horas); $st->execute();
    $hit = (bool)$st->get_result()->fetch_assoc(); $st->close();
    return $hit;
}

/** Máximo de folios que informa el SII en su respuesta (si lo menciona), o null. */
function dte_folios_maximo_de_mensaje(string $msg): ?int {
    $m = mb_strtolower($msg, 'UTF-8');
    foreach (['/m[aá]xim[oa][^0-9]{0,80}?(\d{1,6})/u', '/(\d{1,6})\s*folios?[^.]{0,40}(?:m[aá]xim|permitid|autorizad)/u', '/(?:permitid|autorizad)[a-z]*[^0-9]{0,40}?(\d{1,6})/u'] as $rx) {
        if (preg_match($rx, $m, $x) && (int)$x[1] > 0) return (int)$x[1];
    }
    return null;
}

function dte_folios_registrar(mysqli $conex, int $empresaId, int $tipo, int $cantidad, bool $ok, string $mensaje, string $origen, ?int $desde = null, ?int $hasta = null, ?int $maximo = null): void {
    dte_folios_tablas($conex);
    $now = date('Y-m-d H:i:s');
    $okInt = $ok ? 1 : 0;
    $usr = isset($_SESSION['admin_nombre']) ? mb_substr((string)$_SESSION['admin_nombre'], 0, 160, 'UTF-8') : ($origen === 'auto' ? 'Automático' : null);
    $st = $conex->prepare("INSERT INTO folios_solicitudes (empresa_id, tipo_dte, cantidad_solicitada, ok, folio_desde, folio_hasta, maximo_sii, mensaje, origen, usuario, creado_en) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $st->bind_param('iiiiiiissss', $empresaId, $tipo, $cantidad, $okInt, $desde, $hasta, $maximo, $mensaje, $origen, $usr, $now);
    $st->execute(); $st->close();
    if ($maximo !== null) {
        dte_folios_config($conex, $empresaId, $tipo); // asegura fila
        $q = $conex->prepare("INSERT INTO folios_config (empresa_id, tipo_dte, maximo_sii, maximo_fecha) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE maximo_sii=VALUES(maximo_sii), maximo_fecha=VALUES(maximo_fecha)");
        $q->bind_param('iiis', $empresaId, $tipo, $maximo, $now); $q->execute(); $q->close();
    }
}

/** POST multipart a Trivoxa API DTE (folios). ['http','body','error']. */
function dte_folios_http_post(string $url, string $apiKey, array $input, string $pfxPath): array {
    if (!is_file($pfxPath)) return ['http' => 0, 'body' => '', 'error' => 'No existe el archivo del certificado.'];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'input' => json_encode($input, JSON_UNESCAPED_UNICODE),
            'files' => new CURLFile($pfxPath, 'application/x-pkcs12', basename($pfxPath)),
        ],
        CURLOPT_HTTPHEADER => ['Authorization: ' . $apiKey, 'X-Api-Key: ' . $apiKey, 'Accept: */*'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 150,   // el timbraje del SII puede demorar
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    if ($body === false) { $err = curl_error($ch); curl_close($ch); return ['http' => 0, 'body' => '', 'error' => $err]; }
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http' => $http, 'body' => (string)$body, 'error' => null];
}

/**
 * Solicita $cantidad folios de $tipo al SII para la empresa y, si llega el CAF, lo carga.
 * Devuelve ['ok', 'mensaje'|'error', 'desde', 'hasta', 'maximo', 'cantidad_excede', 'cuota'].
 */
function dte_folios_solicitar(mysqli $conex, array $cfg, int $empresaId, int $tipo, int $cantidad, string $origen = 'manual'): array {
    $cantidad = max(1, min(10000, $cantidad));
    $nombreTipo = dte_nombre($tipo);
    $cuota = dte_folios_estado_cuota($conex);
    if ($cuota['restantes'] <= 0) {
        return ['ok' => false, 'error' => "Se alcanzó el tope de {$cuota['limite']} solicitudes de folios de este mes.", 'cuota' => $cuota];
    }
    $apiKey = trim((string)($cfg['api_key'] ?? ''));
    if ($apiKey === '') return ['ok' => false, 'error' => 'Falta la API key de Trivoxa API DTE.', 'cuota' => $cuota];
    if (($cfg['ambiente'] ?? '') !== 'produccion') return ['ok' => false, 'error' => 'El motor no está en ambiente de producción.', 'cuota' => $cuota];
    try {
        $cert = dte_cert_datos($conex, $cfg, $empresaId);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'cuota' => $cuota];
    }
    $input = ['RutCertificado' => $cert['cert_rut'], 'Password' => $cert['cert_pass'], 'RutEmpresa' => $cert['rut_empresa'], 'Ambiente' => 1];
    $url = rtrim((string)$cfg['base_url'], '/') . "/api/folios/get/{$tipo}/{$cantidad}";
    $r = dte_folios_http_post($url, $apiKey, $input, $cert['cert_path']);
    $cert = null; $input = null;

    if ($r['error'] !== null) {
        dte_folios_registrar($conex, $empresaId, $tipo, $cantidad, false, 'Sin conexión con Trivoxa API DTE: ' . $r['error'], $origen);
        return ['ok' => false, 'error' => 'No se pudo contactar a Trivoxa API DTE: ' . $r['error'], 'cuota' => dte_folios_estado_cuota($conex)];
    }
    $body = trim($r['body']);
    if (str_starts_with($body, '<?xml') || str_starts_with($body, '<AUTORIZACION')) {
        try {
            $info = dte_guardar_caf($conex, $cfg, $empresaId, $body, "sii-{$origen}-" . date('Ymd_His') . '.xml');
            $msg = "El SII autorizó los folios {$info['desde']} a {$info['hasta']} de {$nombreTipo}; el CAF quedó cargado.";
            dte_folios_registrar($conex, $empresaId, $tipo, $cantidad, true, $msg, $origen, (int)$info['desde'], (int)$info['hasta']);
            return ['ok' => true, 'mensaje' => $msg, 'desde' => (int)$info['desde'], 'hasta' => (int)$info['hasta'], 'cuota' => dte_folios_estado_cuota($conex)];
        } catch (Throwable $e) {
            dte_folios_registrar($conex, $empresaId, $tipo, $cantidad, false, 'El SII entregó el CAF pero no se pudo guardar: ' . $e->getMessage(), $origen);
            return ['ok' => false, 'error' => 'El SII entregó el CAF pero no se pudo guardar: ' . $e->getMessage() . ' Puedes descargarlo en sii.cl (Reobtención de CAF) y subirlo a mano.', 'cuota' => dte_folios_estado_cuota($conex)];
        }
    }
    // Respuesta de error del SII / la API: {"Mensaje": "..."} o texto.
    $msg = '';
    $j = json_decode($body, true);
    if (is_array($j)) {
        foreach (['Mensaje', 'mensaje', 'Message', 'message', 'error', 'Error'] as $k) {
            if (!empty($j[$k]) && is_string($j[$k])) { $msg = $j[$k]; break; }
        }
    }
    if ($msg === '') $msg = $body !== '' ? mb_substr(strip_tags($body), 0, 600) : "El SII no autorizó la solicitud (HTTP {$r['http']}).";
    if ($r['http'] === 401 || $r['http'] === 403) $msg = 'Trivoxa API DTE rechazó la API key (HTTP ' . $r['http'] . '): ' . $msg;
    $maximo = dte_folios_maximo_de_mensaje($msg);
    $excede = $maximo !== null || stripos($msg, 'supera') !== false || stripos($msg, 'excede') !== false
           || (stripos($msg, 'cantidad') !== false && stripos($msg, 'permit') !== false);
    dte_folios_registrar($conex, $empresaId, $tipo, $cantidad, false, $msg, $origen, null, null, $maximo);
    return ['ok' => false, 'error' => $msg, 'maximo' => $maximo, 'cantidad_excede' => $excede, 'cuota' => dte_folios_estado_cuota($conex)];
}

/**
 * Igual que dte_folios_solicitar() pero se adapta al límite del SII: si responde que la cantidad
 * supera lo permitido, reintenta con el máximo informado (si lo dijo) o con 5, 2 y 1.
 */
function dte_folios_solicitar_auto(mysqli $conex, array $cfg, int $empresaId, int $tipo, int $cantidad, string $origen = 'manual'): array {
    $escalones = [$cantidad];
    $ultimo = ['ok' => false, 'error' => 'No se realizó ninguna solicitud.'];
    $intentos = 0;
    while ($escalones) {
        $n = array_shift($escalones);
        if (dte_folios_estado_cuota($conex)['restantes'] <= 0) break;
        $intentos++;
        $res = dte_folios_solicitar($conex, $cfg, $empresaId, $tipo, $n, $origen);
        $ultimo = $res;
        if (!empty($res['ok'])) break;
        if (empty($res['cantidad_excede']) || $intentos >= 4) break;
        if (!empty($res['maximo']) && $res['maximo'] < $n) {
            $escalones = [(int)$res['maximo']];
        } elseif (!$escalones) {
            $escalones = array_values(array_filter([5, 2, 1], fn($x) => $x < $n));
        }
    }
    $ultimo['intentos'] = $intentos;
    return $ultimo;
}
