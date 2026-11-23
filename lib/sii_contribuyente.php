<?php
/**
 * Datos de un contribuyente desde el SII a partir de su RUT: razón social, giros y direcciones.
 *
 * Funciona igual que el portal de facturación del SII cuando se escribe el RUT del receptor:
 * el servidor entra al SII con el CERTIFICADO DIGITAL de la empresa que factura (Espacio Luz Spa)
 * y lee el borrador de factura (mipeGenFacEx.cgi), que el SII devuelve con los datos del receptor.
 * No se emite, valida ni envía ningún documento.
 *
 *  - La sesión del SII se reutiliza (cookies en el almacén privado, fuera del sitio web).
 *  - Cada RUT consultado se guarda 7 días en caché (no se repite la consulta al SII).
 *  - La clave del certificado nunca sale del servidor; los PEM temporales se borran al terminar.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/rut.php';
require_once APP_ROOT . '/lib/clientes.php';   // facturaEmpresa()
require_once APP_ROOT . '/lib/pacientes.php';  // catalogoRegiones()

const SII_CONTRIB_CACHE_SEG = 7 * 86400;
const SII_CONTRIB_SESION_SEG = 50 * 60;
const SII_PORTAL = 'https://www1.sii.cl/cgi-bin/Portal001/';

/** Texto del SII a UTF-8: si ya es UTF-8 válido se deja tal cual; si no, viene en ISO-8859-1. */
function siiUtf8(string $s): string
{
    return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
}

/** Quita tildes y pasa a mayúsculas (para comparar nombres de comunas). */
function siiNorm(string $s): string
{
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = strtr($s, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    return preg_replace('/[^A-Z0-9 ]+/', '', preg_replace('/\s+/', ' ', $s)) ?? $s;
}

/** «ELIZABETH ZUNIGA 620» -> «Elizabeth Zuniga 620». */
function siiTitulo(string $s): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    return $s === '' ? '' : mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

/** Comuna del SII -> [región, comuna] tal como están en el catálogo del sistema. */
function siiComunaCatalogo(string $comunaSii): array
{
    $n = siiNorm($comunaSii);
    if ($n === '') return ['', ''];
    foreach (catalogoRegiones() as $r) {
        foreach ($r['comunas'] as $c) {
            if (siiNorm($c) === $n) return [$r['nombre'], $c];
        }
    }
    return ['', siiTitulo($comunaSii)];
}

/** Arreglo JS del SII (`var x = [[...],[...]];`) -> filas, sin la fila final de ceros. */
function siiArregloJs(string $html, string $var): array
{
    if (!preg_match('#var\s+' . preg_quote($var, '#') . '\s*=\s*(\[.*?\]);#s', $html, $m)) return [];
    // Cada texto entre comillas se pasa a UTF-8 según venga (ISO-8859-1 o UTF-8) antes de leer el arreglo.
    $lit = preg_replace_callback('/"([^"]*)"/', fn($x) => '"' . siiUtf8($x[1]) . '"', $m[1]) ?? $m[1];
    $rows = json_decode($lit, true);
    if (!is_array($rows)) return [];
    return array_values(array_filter($rows, fn($r) => is_array($r) && trim((string) ($r[0] ?? '0')) !== '0' && trim((string) ($r[0] ?? '')) !== ''));
}

/**
 * Consulta un RUT en el SII.
 * ['ok'=>true, 'rut', 'razon_social', 'giros'=>[{codigo,glosa}], 'direcciones'=>[{direccion,comuna,region,ciudad}], 'cache'=>bool]
 * o ['ok'=>false, 'error', 'no_encontrado'=>bool].
 */
function siiConsultarContribuyente(string $rutIn): array
{
    $l = rutLimpio($rutIn);
    if (!rutValido($l)) {
        return ['ok' => false, 'error' => 'El RUT no es válido.'];
    }
    $cuerpo = substr($l, 0, -1);
    $dv = substr($l, -1);
    $rutFmt = number_format((int) $cuerpo, 0, ',', '.') . '-' . $dv;

    [$conex, $cfg] = require APP_ROOT . '/dte/bootstrap.php';
    $priv = dirname((string) ($cfg['debug_log_dir'] ?? ''));
    $dir = $priv . '/sii_contribuyentes';
    if ($priv === '' || $priv === '.' || (!is_dir($dir) && !@mkdir($dir . '/cache', 0700, true))) {
        return ['ok' => false, 'error' => 'No se pudo preparar el almacén privado para consultar el SII.'];
    }
    @mkdir($dir . '/cache', 0700, true);

    // Caché: el mismo RUT no se vuelve a consultar al SII durante 7 días.
    $cacheF = $dir . '/cache/' . $cuerpo . '.json';
    if (is_file($cacheF) && filemtime($cacheF) > time() - SII_CONTRIB_CACHE_SEG) {
        $c = json_decode((string) file_get_contents($cacheF), true);
        if (is_array($c) && !empty($c['ok'])) return $c + ['cache' => true];
    }

    $emp = facturaEmpresa();
    if (!$emp) {
        return ['ok' => false, 'error' => 'No está configurada la empresa que factura.'];
    }
    try {
        $cert = dte_cert_datos($conex, $cfg, (int) $emp['id']);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Para traer datos del SII hace falta el certificado digital de ' . ($emp['nombre_fantasia'] ?: $emp['razon_social']) . ' (Empresas y SII).'];
    }

    $lock = fopen($dir . '/.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    $certF = $dir . '/.c_' . bin2hex(random_bytes(6)) . '.pem';
    $keyF = $dir . '/.k_' . bin2hex(random_bytes(6)) . '.pem';
    $jar = $dir . '/.sesion';
    $t0 = microtime(true);
    try {
        $p12 = [];
        if (!openssl_pkcs12_read((string) @file_get_contents($cert['cert_path']), $p12, $cert['cert_pass'])) {
            return ['ok' => false, 'error' => 'No se pudo abrir el certificado digital de la empresa.'];
        }
        $umask = umask(0077);
        file_put_contents($certF, $p12['cert'] . implode('', $p12['extracerts'] ?? []));
        file_put_contents($keyF, $p12['pkey']);
        umask($umask);
        unset($p12);

        $pedir = function (string $url, ?array $post = null) use ($certF, $keyF, $jar): array {
            $ch = curl_init($url);
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 8,
                  CURLOPT_SSLCERT => $certF, CURLOPT_SSLKEY => $keyF, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
                  CURLOPT_USERAGENT => 'Mozilla/5.0 (EspacioLuz; facturacion)', CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25,
                  CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
            if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = http_build_query($post); }
            curl_setopt_array($ch, $o);
            $b = curl_exec($ch);
            $r = ['http' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => is_string($b) ? $b : '', 'err' => curl_error($ch)];
            curl_close($ch);
            return $r;
        };
        // Borrador de factura exenta con el RUT del receptor: el SII lo devuelve con sus datos.
        $consultar = function () use ($pedir, $cuerpo, $dv): array {
            return $pedir(SII_PORTAL . 'mipeGenFacEx.cgi?', ['PTDC_CODIGO' => '34', 'EFXP_RUT_RECEP' => $cuerpo, 'EFXP_DV_RECEP' => $dv]);
        };
        $esFormulario = fn(array $r): bool => $r['http'] === 200 && str_contains($r['body'], 'EFXP_RZN_SOC_RECEP');
        // Aviso del SII (p. ej. «El RUT del receptor no se encuentra habilitado para recibir documentos»):
        // la sesión está bien, pero ese RUT no tiene datos de facturación.
        $aviso = function (array $r): string {
            if ($r['http'] !== 200 || strlen($r['body']) > 5000 || !preg_match("#alert\(\s*['\"]([^'\"]{3,200})['\"]#", $r['body'], $m)) return '';
            return trim(siiUtf8($m[1]));
        };
        $noHallado = function (string $msg) use ($priv, $rutFmt, $t0): array {
            siiLogConsulta($priv, $rutFmt, false, microtime(true) - $t0);
            return ['ok' => false, 'no_encontrado' => true, 'error' => 'El SII no tiene datos de facturación para ese RUT (' . rtrim($msg, '.') . '). Completa los datos a mano.'];
        };

        $r = null;
        if (is_file($jar) && filemtime($jar) > time() - SII_CONTRIB_SESION_SEG) {
            $r = $consultar();
            if (!$esFormulario($r) && ($av = $aviso($r)) !== '') return $noHallado($av);
        }
        if (!$r || !$esFormulario($r)) {
            // Sesión nueva con el certificado (como «Ingresar con certificado digital» en sii.cl).
            @unlink($jar);
            $destino = SII_PORTAL . 'mipeLaunchPage.cgi?OPCION=34&TIPO=4';
            $a = $pedir('https://herculesr.sii.cl/cgi_AUT2000/CAutInicio.cgi?' . $destino, ['referencia' => $destino]);
            if ($a['http'] !== 200) {
                return ['ok' => false, 'error' => 'El SII no respondió al iniciar sesión con el certificado' . ($a['err'] ? ' (' . $a['err'] . ')' : '') . '. Intenta de nuevo en un momento.'];
            }
            $pedir(SII_PORTAL . 'mipeSelEmpresa.cgi?DESDE_DONDE_URL=OPCION%3D34%26TIPO%3D4');
            $r = $consultar();
            @chmod($jar, 0600);
            if (!$esFormulario($r) && ($av = $aviso($r)) !== '') return $noHallado($av);
            if (!$esFormulario($r)) {
                return ['ok' => false, 'error' => 'El SII no permitió la consulta con el certificado de ' . ($emp['nombre_fantasia'] ?: $emp['razon_social']) . '. Revisa que el titular tenga acceso a la facturación de la empresa en sii.cl.'];
            }
        }
        @touch($jar);
        @chmod($jar, 0600);

        // El portal mezcla codificaciones (la página en ISO-8859-1, algunos giros en UTF-8): se trabaja sobre
        // los bytes originales y cada valor se pasa a UTF-8 por separado (si no, «ENSEÑANZA» salía «ENSEÃ‘ANZA»).
        $h = $r['body'];
        $razon = '';
        if (preg_match('#<input[^>]*name="EFXP_RZN_SOC_RECEP"[^>]*>#i', $h, $m) && preg_match('#value="([^"]*)"#i', $m[0], $v)) {
            $razon = trim(html_entity_decode(siiUtf8($v[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $giros = [];
        foreach (siiArregloJs($h, 'recptoActEco') as $g) {
            $giros[] = ['codigo' => trim((string) $g[0]), 'glosa' => trim(html_entity_decode((string) ($g[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'))];
        }
        $dirs = [];
        foreach (siiArregloJs($h, 'recptorDir') as $d) {
            $txt = fn($x) => html_entity_decode((string) $x, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            [$reg, $com] = siiComunaCatalogo($txt($d[1] ?? ''));
            $dirs[] = ['direccion' => siiTitulo($txt($d[0])), 'comuna' => $com, 'region' => $reg, 'ciudad' => siiTitulo($txt($d[2] ?? ''))];
        }
        siiLogConsulta($priv, $rutFmt, $razon !== '', microtime(true) - $t0);
        if ($razon === '' && !$dirs && !$giros) {
            return ['ok' => false, 'no_encontrado' => true, 'error' => 'El SII no tiene datos de facturación para ese RUT (por ejemplo, una persona sin inicio de actividades). Completa los datos a mano.'];
        }
        $out = ['ok' => true, 'rut' => $rutFmt, 'razon_social' => $razon, 'giros' => $giros, 'direcciones' => $dirs, 'consultado' => date('Y-m-d H:i')];
        @file_put_contents($cacheF, json_encode($out, JSON_UNESCAPED_UNICODE));
        @chmod($cacheF, 0600);
        return $out + ['cache' => false];
    } catch (Throwable $e) {
        error_log('[sii contribuyente] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo consultar el SII en este momento. Intenta de nuevo o completa los datos a mano.'];
    } finally {
        @unlink($certF);
        @unlink($keyF);
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

/** Registro mínimo de consultas al SII (RUT, si encontró datos y cuánto tardó). */
function siiLogConsulta(string $priv, string $rut, bool $hallado, float $seg): void
{
    $f = $priv . '/logs/sii_contribuyentes-' . date('Ym') . '.log';
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $rut . ' · ' . ($hallado ? 'con datos' : 'sin datos') . ' · ' . number_format($seg, 2, ',', '') . "s\n", FILE_APPEND);
}
