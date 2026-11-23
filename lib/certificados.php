<?php
/**
 * Certificados de atención (opción «Certificado de atención» de cada paciente).
 * Replica el formato del «Certificado de atención» del centro: datos del
 * paciente, sesiones con su fecha y valor, total y firma del profesional.
 * El mismo HTML se usa para la vista previa en pantalla y para el PDF (dompdf).
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';
require_once APP_ROOT . '/lib/panel.php';    // clp()
require_once APP_ROOT . '/lib/pacientes.php'; // getPaciente()
require_once APP_ROOT . '/lib/rut.php';      // rutValido(), formatoRut()
require_once APP_ROOT . '/lib/firmas.php';   // firma y timbre del profesional

const CERT_MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** "2026-08-04" -> "04 de agosto de 2026". */
function certFechaLarga(string $ymd, bool $diaDosDigitos = true): string
{
    $t = strtotime($ymd);
    if (!$t) {
        return $ymd;
    }
    $dia = $diaDosDigitos ? date('d', $t) : date('j', $t);
    return $dia . ' de ' . CERT_MESES[(int) date('n', $t)] . ' de ' . date('Y', $t);
}

/** Especialidad a partir del título del profesional ("Terapeuta Ocupacional" -> "Terapia Ocupacional"). */
function certEspecialidadDeTitulo(?string $titulo): string
{
    $t = mb_strtolower(trim((string) $titulo), 'UTF-8');
    $map = [
        'terapeuta ocupacional' => 'Terapia Ocupacional',
        'psicóloga clínica' => 'Psicología Clínica', 'psicólogo clínico' => 'Psicología Clínica',
        'psicóloga' => 'Psicología', 'psicólogo' => 'Psicología',
        'psiquiatra' => 'Psiquiatría',
        'fonoaudióloga' => 'Fonoaudiología', 'fonoaudiólogo' => 'Fonoaudiología',
        'psicopedagoga' => 'Psicopedagogía', 'psicopedagogo' => 'Psicopedagogía',
        'kinesióloga' => 'Kinesiología', 'kinesiólogo' => 'Kinesiología',
        'nutricionista' => 'Nutrición',
    ];
    return $map[$t] ?? trim((string) $titulo);
}

/** "correspondientes al mes de agosto de 2026" o el período entre dos fechas. */
function certPeriodoTexto(array $sesiones): string
{
    $fechas = array_values(array_filter(array_map(fn($s) => (string) ($s['fecha'] ?? ''), $sesiones)));
    if (!$fechas) {
        return '';
    }
    sort($fechas);
    $ini = $fechas[0];
    $fin = $fechas[count($fechas) - 1];
    if (substr($ini, 0, 7) === substr($fin, 0, 7)) {
        $t = strtotime($ini);
        return 'al mes de ' . CERT_MESES[(int) date('n', $t)] . ' de ' . date('Y', $t);
    }
    return 'al período comprendido entre el ' . certFechaLarga($ini) . ' y el ' . certFechaLarga($fin);
}

/**
 * «la paciente» (f) / «el paciente» (m) según el sexo de la ficha. '' si no se puede
 * deducir (sin sexo registrado u «otro»): en ese caso se resuelve en el formulario.
 */
function certTratoDeSexo(?string $sexo): string
{
    return $sexo === 'mujer' ? 'f' : ($sexo === 'hombre' ? 'm' : '');
}

/** Texto de cierre por defecto según el trato. */
function certCierrePorDefecto(string $trato): string
{
    return 'Se extiende el presente certificado a solicitud ' . ($trato === 'm' ? 'del interesado' : 'de la interesada') . ', para los fines que estime convenientes.';
}

/**
 * Valida y normaliza los datos del formulario.
 * @return array{ok:bool, error?:string, row?:array}
 */
function certNormalizar(array $d): array
{
    $cid = (int) ($d['paciente_id'] ?? 0);
    $pac = $cid > 0 ? getPaciente($cid) : null;
    if (!$pac) {
        return ['ok' => false, 'error' => 'Paciente no válido.'];
    }
    $txt = static fn(string $k, int $max) => mb_substr(trim((string) ($d[$k] ?? '')), 0, $max, 'UTF-8');

    $especialidad = $txt('especialidad', 120);
    $nombre = $txt('paciente_nombre', 200);
    $firmante = $txt('firmante_nombre', 160);
    // Trato automático según el sexo de la ficha. Si la ficha no lo tiene, el formulario
    // pide el sexo (sexo_paciente) y se registra en la ficha al guardar.
    $sexoFicha = (string) ($pac['sexo'] ?? '');
    $sexoNuevo = ($sexoFicha === '' && in_array($d['sexo_paciente'] ?? '', ['mujer', 'hombre', 'otro'], true)) ? (string) $d['sexo_paciente'] : '';
    $trato = certTratoDeSexo($sexoFicha !== '' ? $sexoFicha : $sexoNuevo);
    if ($trato === '') {
        $trato = in_array($d['trato'] ?? '', ['f', 'm'], true) ? $d['trato'] : '';
    }
    $fecha = (string) ($d['fecha_documento'] ?? '');
    $certPago = !empty($d['certifica_pago']) ? 1 : 0;

    if ($especialidad === '') return ['ok' => false, 'error' => 'Indica la especialidad (ej.: Terapia Ocupacional).'];
    if ($nombre === '') return ['ok' => false, 'error' => 'Falta el nombre del paciente.'];
    if ($sexoFicha === '' && $sexoNuevo === '') return ['ok' => false, 'error' => 'Indica el sexo del paciente: se guardará en su ficha y define si el documento dice «la paciente» o «el paciente».'];
    if ($trato === '') return ['ok' => false, 'error' => 'Elige si el documento dice «la paciente» o «el paciente».'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) return ['ok' => false, 'error' => 'La fecha del certificado no es válida.'];
    if ($firmante === '') return ['ok' => false, 'error' => 'Falta el nombre de quien firma.'];

    // Sesiones: [{fecha:'YYYY-MM-DD', valor:int}], ordenadas por fecha.
    $ses = [];
    foreach ((array) ($d['sesiones'] ?? []) as $s) {
        $f = (string) ($s['fecha'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) || !strtotime($f)) {
            continue;
        }
        $v = max(0, (int) preg_replace('/\D/', '', (string) ($s['valor'] ?? '0')));
        $ses[] = ['fecha' => $f, 'valor' => $v];
    }
    if (!$ses) return ['ok' => false, 'error' => 'Agrega al menos una sesión con su fecha.'];
    if (count($ses) > 60) return ['ok' => false, 'error' => 'Máximo 60 sesiones por certificado.'];
    usort($ses, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));
    if ($certPago) {
        foreach ($ses as $s) {
            if ($s['valor'] <= 0) return ['ok' => false, 'error' => 'Si certificas el pago, cada sesión debe tener su valor.'];
        }
        // Solo se certifica el pago de sesiones que tienen boleta emitida (no anulada) ese día.
        try {
            $q = db()->prepare("SELECT r.fecha, COUNT(*) n FROM reservas r JOIN boletas b ON b.id = r.boleta_id
                                 WHERE r.paciente_id = ? AND r.estado <> 'cancelada' AND b.estado = 'emitida' GROUP BY r.fecha");
            $q->execute([$cid]);
            $pagadas = [];
            foreach ($q->fetchAll() as $row) {
                $pagadas[$row['fecha']] = (int) $row['n'];
            }
            $usadas = [];
            $sinBoleta = [];
            foreach ($ses as $s) {
                $usadas[$s['fecha']] = ($usadas[$s['fecha']] ?? 0) + 1;
                if ($usadas[$s['fecha']] > ($pagadas[$s['fecha']] ?? 0)) {
                    $sinBoleta[] = date('d-m-Y', strtotime($s['fecha']));
                }
            }
            if ($sinBoleta) {
                return ['ok' => false, 'error' => 'No se puede certificar el pago: ' . (count($sinBoleta) === 1 ? 'la sesión del ' : 'las sesiones del ') . implode(', ', array_unique($sinBoleta))
                    . ' no ' . (count($sinBoleta) === 1 ? 'tiene' : 'tienen') . ' boleta emitida. Emite su boleta o desmarca «Certificar también el pago».'];
            }
        } catch (Throwable $e) {
            // sin columnas de cobro: no se bloquea
        }
    }
    $total = $certPago ? array_sum(array_column($ses, 'valor')) : 0;

    $rut = $txt('paciente_rut', 20);
    // Solo un RUT válido se formatea; un pasaporte/documento extranjero queda tal cual.
    if ($rut !== '' && rutValido($rut)) {
        $rut = formatoRut($rut);
    }
    $cierre = $txt('texto_cierre', 500);

    // RUT de quien firma (por defecto, el de la ficha del profesional).
    $firRut = $txt('firmante_rut', 20);
    if ($firRut !== '' && !rutValido($firRut)) {
        return ['ok' => false, 'error' => 'El RUT de quien firma no es válido. Revisa el dígito verificador.'];
    }

    return ['ok' => true, 'sexo_nuevo' => $sexoNuevo !== '' ? $sexoNuevo : null, 'row' => [
        'paciente_id' => $cid,
        'profesional_id' => ((int) ($d['profesional_id'] ?? 0)) ?: null,
        'especialidad' => $especialidad,
        'ciudad' => $txt('ciudad', 80) ?: 'Los Ángeles',
        'fecha_documento' => $fecha,
        'paciente_nombre' => $nombre,
        'paciente_rut' => $rut !== '' ? $rut : null,
        'paciente_domicilio' => $txt('paciente_domicilio', 300) ?: null,
        'trato' => $trato,
        'modalidad' => $txt('modalidad', 120) ?: 'en consulta particular',
        'certifica_pago' => $certPago,
        'sesiones' => json_encode($ses, JSON_UNESCAPED_UNICODE),
        'total_clp' => $total,
        'texto_cierre' => $cierre !== '' ? $cierre : certCierrePorDefecto($trato),
        'observaciones' => $txt('observaciones', 2000) ?: null,
        'firmante_nombre' => $firmante,
        'firmante_titulo' => $txt('firmante_titulo', 160) ?: null,
        'firmante_detalle' => $txt('firmante_detalle', 200) ?: null,
        'firmante_rut' => $firRut !== '' ? formatoRut($firRut) : null,
    ]];
}

/** @return array{ok:bool, id?:int, error?:string} */
function certGuardar(array $d, int $id = 0): array
{
    $n = certNormalizar($d);
    if (!$n['ok']) {
        return $n;
    }
    $row = $n['row'];
    // Sexo indicado en el certificado (la ficha no lo tenía): queda registrado en la ficha.
    if (!empty($n['sexo_nuevo'])) {
        try { db()->prepare('UPDATE pacientes SET sexo = ? WHERE id = ? AND sexo IS NULL')->execute([$n['sexo_nuevo'], $row['paciente_id']]); } catch (Throwable $e) { error_log('[certificados sexo] ' . $e->getMessage()); }
    }
    // Firma y timbre: se congela la firma vigente del profesional al emitir/editar.
    // Si el profesional aún no tiene firma, se conserva la que el certificado ya tuviera.
    $firma = firmaDeProfesional($row['profesional_id']);
    if ($firma !== null) {
        $row['firma_archivo'] = $firma;
    }
    try {
        if ($id > 0) {
            $act = certGet($id);
            if (!$act || (int) $act['paciente_id'] !== (int) $row['paciente_id']) {
                return ['ok' => false, 'error' => 'Certificado no encontrado.'];
            }
            $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($row)));
            db()->prepare("UPDATE certificados_atencion SET $sets WHERE id = ?")->execute([...array_values($row), $id]);
            return ['ok' => true, 'id' => $id];
        }
        $row['creado_por'] = $_SESSION['admin_id'] ?? null;
        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($row)));
        $qs = implode(', ', array_fill(0, count($row), '?'));
        db()->prepare("INSERT INTO certificados_atencion ($cols) VALUES ($qs)")->execute(array_values($row));
        return ['ok' => true, 'id' => (int) db()->lastInsertId()];
    } catch (Throwable $e) {
        error_log('[certificados] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo guardar el certificado.'];
    }
}

function certGet(int $id): ?array
{
    try {
        $st = db()->prepare("SELECT * FROM certificados_atencion WHERE id = ?");
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) return null;
        $c['sesiones_arr'] = json_decode((string) $c['sesiones'], true) ?: [];
        return $c;
    } catch (Throwable $e) {
        return null;
    }
}

/** Certificados de un paciente (más recientes primero). */
function certListarPaciente(int $pacienteId): array
{
    try {
        $st = db()->prepare("SELECT * FROM certificados_atencion WHERE paciente_id = ? ORDER BY fecha_documento DESC, id DESC");
        $st->execute([$pacienteId]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['sesiones_arr'] = json_decode((string) $r['sesiones'], true) ?: [];
        }
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function certEliminar(int $id): bool
{
    try {
        return db()->prepare("DELETE FROM certificados_atencion WHERE id = ?")->execute([$id]);
    } catch (Throwable $e) {
        return false;
    }
}

/** Último firmante usado por un profesional (para no reescribir su firma cada vez). */
function certUltimoFirmante(int $profId): ?array
{
    try {
        $st = db()->prepare("SELECT firmante_nombre, firmante_titulo, firmante_detalle, especialidad, modalidad FROM certificados_atencion WHERE profesional_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$profId]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Nombre del archivo: "Cer.Atencion Nombre del Paciente.pdf". */
function certNombreArchivo(array $c): string
{
    $n = trim(preg_replace('/[\\\\\/:*?"<>|\r\n\t]+/u', ' ', (string) $c['paciente_nombre']));
    $n = preg_replace('/\s+/u', ' ', $n);
    return 'Cer.Atencion ' . ($n !== '' ? $n : 'Paciente') . '.pdf';
}

/** Logo como data URI (para dompdf). Usa una copia reducida en caché si hay GD. */
function certLogoDataUri(): string
{
    $src = APP_ROOT . '/assets/images/logo/logo.png';
    if (!is_file($src)) return '';
    $cacheDir = APP_ROOT . '/storage/cache';
    $cache = $cacheDir . '/cert_logo.png';
    if (!is_file($cache) || filemtime($cache) < filemtime($src)) {
        $ok = false;
        if (function_exists('imagecreatefrompng')) {
            $im = @imagecreatefrompng($src);
            if ($im) {
                $w = imagesx($im); $h = imagesy($im); $nw = 180; $nh = (int) round($h * $nw / max(1, $w));
                $dst = imagecreatetruecolor($nw, $nh);
                imagealphablending($dst, false); imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
                imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
                if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
                $ok = @imagepng($dst, $cache, 9);
            }
        }
        if (!$ok) $cache = $src;
    }
    $data = @file_get_contents($cache);
    return $data === false ? '' : 'data:image/png;base64,' . base64_encode($data);
}

/**
 * Cuerpo del certificado (estilos en línea). $pdf=true usa fuentes de dompdf
 * y el logo embebido; $pdf=false usa las fuentes del sitio.
 */
function certDocHtml(array $c, bool $pdf = false): string
{
    $C = ['cafe' => '#5C4033', 'terr' => '#D97757', 'terrDark' => '#B85C3E', 'salvia' => '#8B9E8F', 'crema' => '#F5F1E8', 'linea' => '#E4DAC9', 'gris' => '#8a7c70'];
    $serif = $pdf ? "'DejaVu Serif', Georgia, serif" : "'Playfair Display', Georgia, serif";
    $sans = $pdf ? "'DejaVu Sans', Arial, sans-serif" : "'Nunito', Arial, sans-serif";
    $centro = $GLOBALS['CENTRO'] ?? [];
    $logo = $pdf ? certLogoDataUri() : asset('images/logo/logo.png');
    $ses = $c['sesiones_arr'] ?? (json_decode((string) ($c['sesiones'] ?? '[]'), true) ?: []);
    $pago = (int) ($c['certifica_pago'] ?? 1) === 1;
    $masc = (($c['trato'] ?? 'f') === 'm');
    $n = count($ses);
    $folio = str_pad((string) (int) ($c['id'] ?? 0), 6, '0', STR_PAD_LEFT);
    $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    // Espaciados: el PDF es más compacto para que quepa en una página carta.
    $lh = $pdf ? '1.42' : '1.7';
    $fz = $pdf ? '13px' : '13.5px';

    $datos = '<strong>' . $h($c['paciente_nombre']) . '</strong>';
    if (!empty($c['paciente_rut'])) $datos .= (rutValido((string) $c['paciente_rut']) ? ', RUT: ' : ', documento de identidad N° ') . '<strong>' . $h($c['paciente_rut']) . '</strong>';
    if (!empty($c['paciente_domicilio'])) $datos .= ', con domicilio en <strong>' . $h($c['paciente_domicilio']) . '</strong>';

    ob_start(); ?>
<div style="max-width:700px;margin:0 auto;background:#fff;color:<?= $C['cafe'] ?>;font-family:<?= $sans ?>;<?= $pdf ? '' : 'border:1px solid ' . $C['linea'] . ';border-radius:14px;overflow:hidden;box-shadow:0 14px 34px -16px rgba(92,64,51,.3);' ?>">
    <div style="height:7px;background:<?= $C['terr'] ?>;"></div>
    <div style="padding:<?= $pdf ? '6px 46px 8px' : '34px clamp(18px,6vw,44px) 30px' ?>;">
        <!-- Membrete -->
        <table style="width:100%;border-collapse:collapse;"><tr>
            <td style="vertical-align:middle;width:70px;"><?php if ($logo): ?><img src="<?= $h($logo) ?>" alt="" style="width:<?= $pdf ? 46 : 62 ?>px;height:<?= $pdf ? 46 : 62 ?>px;border-radius:50%;"><?php endif; ?></td>
            <td style="vertical-align:middle;padding-left:12px;">
                <div style="font-family:<?= $serif ?>;font-size:21px;font-weight:700;line-height:1.1;"><?= $h($centro['nombre'] ?? 'Espacio Luz') ?></div>
                <div style="font-size:11.5px;color:<?= $C['gris'] ?>;margin-top:3px;"><?= $h($centro['descripcion'] ?? 'Centro integral de terapias y bienestar') ?></div>
            </td>
            <td style="vertical-align:middle;text-align:right;">
                <div style="display:inline-block;border:1.5px solid <?= $C['salvia'] ?>;border-radius:9px;padding:7px 12px;text-align:center;">
                    <div style="font-size:9px;letter-spacing:1px;font-weight:700;text-transform:uppercase;color:#6D8072;">Certificado</div>
                    <div style="font-size:13px;font-weight:800;margin-top:1px;">N° <?= $h($folio) ?></div>
                </div>
            </td>
        </tr></table>

        <div style="border-top:1px solid <?= $C['linea'] ?>;margin:<?= $pdf ? '10px 0 12px' : '20px 0 22px' ?>;"></div>

        <!-- Título -->
        <div style="text-align:center;font-family:<?= $serif ?>;font-size:<?= $pdf ? '17px' : '19px' ?>;font-weight:700;letter-spacing:.6px;text-transform:uppercase;line-height:1.35;">
            Certificado de atención en <?= $h(mb_strtoupper($c['especialidad'], 'UTF-8')) ?>
        </div>
        <div style="width:64px;height:3px;background:<?= $C['terr'] ?>;border-radius:3px;margin:10px auto 0;"></div>

        <p style="margin:<?= $pdf ? '12px' : '24px' ?> 0 0;font-size:<?= $fz ?>;text-align:right;"><strong>Fecha:</strong> <?= $h($c['ciudad']) ?>, <?= $h(certFechaLarga((string) $c['fecha_documento'], false)) ?>.</p>

        <p style="margin:<?= $pdf ? '8px' : '18px' ?> 0 0;font-size:<?= $fz ?>;font-weight:700;">Presente</p>
        <p style="margin:3px 0 0;font-size:<?= $fz ?>;">A quien corresponda:</p>

        <p style="margin:<?= $pdf ? '8px' : '16px' ?> 0 0;font-size:<?= $fz ?>;line-height:<?= $lh ?>;text-align:justify;">
            Por medio de la presente, certifico que <?= $masc ? 'el paciente' : 'la paciente' ?> <?= $datos ?>, se encuentra recibiendo atención profesional de <strong><?= $h($c['especialidad']) ?></strong> <?= $h($c['modalidad']) ?>.
        </p>

        <p style="margin:<?= $pdf ? '10px' : '14px' ?> 0 0;font-size:<?= $fz ?>;line-height:<?= $lh ?>;text-align:justify;">
            El presente documento certifica la asistencia<?= $pago ? ' y el pago íntegro' : '' ?> de las siguientes sesiones de tratamiento correspondientes <?= $h(certPeriodoTexto($ses)) ?>:
        </p>

        <!-- Sesiones -->
        <table style="width:100%;border-collapse:collapse;margin-top:14px;font-size:13px;border:1px solid <?= $C['linea'] ?>;">
            <thead><tr style="background:#F0EBE2;">
                <th style="text-align:left;padding:9px 12px;font-size:10.5px;letter-spacing:.5px;text-transform:uppercase;color:#7a6a5e;border-bottom:2px solid #E0D6C6;">Sesión</th>
                <th style="text-align:left;padding:9px 12px;font-size:10.5px;letter-spacing:.5px;text-transform:uppercase;color:#7a6a5e;border-bottom:2px solid #E0D6C6;">Fecha</th>
                <?php if ($pago): ?><th style="text-align:right;padding:9px 12px;font-size:10.5px;letter-spacing:.5px;text-transform:uppercase;color:#7a6a5e;border-bottom:2px solid #E0D6C6;">Valor</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($ses as $i => $s): ?>
                <tr style="background:<?= $i % 2 ? '#FAF7F1' : '#fff' ?>;">
                    <td style="padding:<?= $pdf ? '4px' : '8px' ?> 12px;border-bottom:1px solid #EFE8DC;border-left:3px solid <?= $C['salvia'] ?>;font-weight:700;">Sesión <?= $i + 1 ?></td>
                    <td style="padding:<?= $pdf ? '4px' : '8px' ?> 12px;border-bottom:1px solid #EFE8DC;"><?= $h(certFechaLarga((string) $s['fecha'])) ?></td>
                    <?php if ($pago): ?><td style="padding:<?= $pdf ? '4px' : '8px' ?> 12px;border-bottom:1px solid #EFE8DC;text-align:right;"><?= $h(clp((int) $s['valor'])) ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($pago): ?>
        <table style="width:100%;border-collapse:collapse;margin-top:8px;"><tr>
            <td></td>
            <td style="width:380px;">
                <table style="width:100%;border-collapse:collapse;background:<?= $C['cafe'] ?>;border-radius:8px;"><tr>
                    <td style="padding:9px 14px;color:#fff;font-size:12px;font-weight:700;white-space:nowrap;">Total correspondiente a <?= $n ?> <?= $n === 1 ? 'sesión' : 'sesiones' ?></td>
                    <td style="padding:10px 14px;color:#fff;font-size:16px;font-weight:800;text-align:right;"><?= $h(clp((int) $c['total_clp'])) ?></td>
                </tr></table>
            </td>
        </tr></table>
        <?php endif; ?>

        <?php if (!empty($c['observaciones'])): ?>
            <p style="margin:<?= $pdf ? '12px' : '18px' ?> 0 0;font-size:<?= $fz ?>;line-height:<?= $lh ?>;text-align:justify;"><?= nl2br($h($c['observaciones'])) ?></p>
        <?php endif; ?>


        <!-- Cierre + firma + pie (no se separan entre páginas) -->
        <div style="page-break-inside:avoid;">
        <p style="margin:<?= $pdf ? '12px' : '18px' ?> 0 0;font-size:<?= $fz ?>;line-height:<?= $lh ?>;text-align:justify;"><?= $h($c['texto_cierre'] ?: certCierrePorDefecto((string) $c['trato'])) ?></p>
        <?php
        // Firma y timbre: la congelada en el certificado o, si no tiene, la vigente del profesional.
        // Todas se normalizan al MISMO lienzo (proporción 5:3, FIRMA_W × FIRMA_H) y se dibujan en un
        // recuadro fijo de 400×240 px: cada firma se escala hasta llenarlo, así todas salen del mismo tamaño.
        $firmaArch = ($c['firma_archivo'] ?? null) ?: firmaDeProfesional(isset($c['profesional_id']) ? (int) $c['profesional_id'] : null);
        $firmaSrc = $firmaArch ? ($pdf ? firmaDataUri($firmaArch) : (string) firmaUrl($firmaArch)) : '';
        ?>
        <table style="width:100%;border-collapse:collapse;<?= $pdf ? '' : 'table-layout:fixed;' ?>margin-top:<?= $firmaSrc !== '' ? ($pdf ? '6px' : '22px') : ($pdf ? '46px' : '70px') ?>;"><tr><td style="text-align:center;">
            <?php if ($firmaSrc !== ''): ?>
                <!-- Firma y timbre centrados y SIEMPRE por encima de la línea: la línea va bajo el borde
                     inferior de la imagen (nunca se superpone), así ninguna firma se corta, sea cual sea su tamaño.
                     Contenedor propio: dompdf no centra imágenes en bloque. -->
                <?php if ($pdf): ?>
                    <div style="text-align:center;height:240px;margin-bottom:4px;">
                        <img src="<?= $h($firmaSrc) ?>" alt="Firma y timbre" style="width:400px;height:240px;">
                    </div>
                <?php else: ?>
                    <?php /* En pantalla: inline-block (Tailwind fuerza display:block y la corre a la izquierda)
                             y se achica proporcionalmente en pantallas angostas (proporción fija 5:2). */ ?>
                    <div style="text-align:center;line-height:0;margin-bottom:4px;">
                        <img src="<?= $h($firmaSrc) ?>" alt="Firma y timbre" style="display:inline-block;vertical-align:bottom;width:400px;max-width:94%;height:auto;aspect-ratio:5/3;margin:0 auto;">
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <div style="text-align:center;">
            <?php if ($firmaSrc !== ''): ?>
                <!-- Con firma y timbre cargados, la imagen ya trae nombre, RUT y título: bajo la línea no va nada. -->
                <div style="display:inline-block;<?= $pdf ? 'width:360px;' : 'width:360px;max-width:100%;box-sizing:border-box;' ?>border-top:1.5px solid <?= $C['cafe'] ?>;"></div>
            <?php else: ?>
            <!-- Sin firma cargada: se identifica a quien firma con sus datos bajo la línea. -->
            <div style="display:inline-block;<?= $pdf ? 'min-width:340px;' : 'width:340px;max-width:100%;box-sizing:border-box;' ?>border-top:1.5px solid <?= $C['cafe'] ?>;padding-top:8px;">
                <div style="font-size:14px;font-weight:700;"><?= $h($c['firmante_nombre']) ?></div>
                <?php if (!empty($c['firmante_rut'])): ?><div style="font-size:12px;color:<?= $C['gris'] ?>;margin-top:1px;">RUT: <?= $h($c['firmante_rut']) ?></div><?php endif; ?>
                <?php if (!empty($c['firmante_titulo'])): ?><div style="font-size:12.5px;color:<?= $C['cafe'] ?>;margin-top:2px;"><?= $h($c['firmante_titulo']) ?></div><?php endif; ?>
                <?php if (!empty($c['firmante_detalle'])): ?><div style="font-size:12px;color:<?= $C['gris'] ?>;margin-top:1px;"><?= $h($c['firmante_detalle']) ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
            </div>
        </td></tr></table>

        <!-- Pie -->
        <div style="margin-top:<?= $pdf ? '8px' : '44px' ?>;border-top:1px solid <?= $C['linea'] ?>;padding-top:<?= $pdf ? '6px' : '10px' ?>;text-align:center;font-size:10.5px;color:<?= $C['gris'] ?>;line-height:1.6;">
            <?= $h($centro['nombre'] ?? 'Espacio Luz') ?><?= !empty($centro['ubicacion']) ? ' · ' . $h($centro['ubicacion']) : '' ?><br>
            <?= !empty($centro['telefono']) ? $h($centro['telefono']) : '' ?><?= !empty($centro['email']) ? ' · ' . $h($centro['email']) : '' ?><?= !empty($centro['instagram']) ? ' · ' . $h($centro['instagram']) : '' ?>
        </div>
        </div>
    </div>
</div>
<?php
    return (string) ob_get_clean();
}

/** @return array{filename:string, bytes:string} */
function certPdfBytes(array $c): array
{
    require_once APP_ROOT . '/dte/vendor/autoload.php';
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>@page{margin:18px 0;} body{margin:0;background:#fff;} *{font-family:"DejaVu Sans",Arial,sans-serif;}</style></head><body>'
        . certDocHtml($c, true) . '</body></html>';
    $opt = new \Dompdf\Options();
    $opt->set('isRemoteEnabled', false);
    $opt->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($opt);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return ['filename' => certNombreArchivo($c), 'bytes' => (string) $dompdf->output()];
}
