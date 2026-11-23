<?php
/**
 * Calendario semanal en PDF (boxes o profesionales), listo para imprimir.
 *
 * Una página por semana y por box/profesional, en carta horizontal, con el
 * estilo del sistema (crema, café, terracota y salvia) y el ave de cada box
 * posada sobre el encabezado. Lunes a sábado, de 08:00 a 20:00 (el domingo el
 * centro no atiende). Usa la misma información que el calendario del panel.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/panel.php';
require_once APP_ROOT . '/lib/feriados.php';
require_once APP_ROOT . '/lib/certificados.php';   // certLogoDataUri

const CALPDF_MAX_SEMANAS = 8;
const CALPDF_MAX_PAGINAS = 40;
const CALPDF_AVES = ['loica', 'martin-pescador', '7-colores', 'colibri', 'golondrina'];

/** Lunes de la semana de una fecha Y-m-d (o null si no es válida). */
function calPdfLunes(string $ymd): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) || !checkdate((int) substr($ymd, 5, 2), (int) substr($ymd, 8, 2), (int) substr($ymd, 0, 4))) {
        return null;
    }
    return rangoSemana($ymd)[0];
}

/**
 * Opciones desde la URL: modo=box|prof, sel=<slug|id|todos>, desde, hasta (cualquier día
 * de la semana inicial y final), pac=1 (nombres de pacientes, solo profesionales).
 * @return array{ok:bool, error?:string, modo?:string, entidades?:array, semanas?:array, pac?:bool}
 */
function calPdfOpciones(array $q): array
{
    $modo = ($q['modo'] ?? '') === 'prof' ? 'prof' : 'box';
    $desde = calPdfLunes((string) ($q['desde'] ?? '')) ?? rangoSemana('today')[0];
    $hasta = calPdfLunes((string) ($q['hasta'] ?? '')) ?? $desde;
    if ($hasta < $desde) {
        [$desde, $hasta] = [$hasta, $desde];
    }
    $semanas = [];
    for ($d = new DateTimeImmutable($desde); $d->format('Y-m-d') <= $hasta; $d = $d->modify('+7 days')) {
        $semanas[] = $d->format('Y-m-d');
        if (count($semanas) > CALPDF_MAX_SEMANAS) {
            return ['ok' => false, 'error' => 'Elige como máximo ' . CALPDF_MAX_SEMANAS . ' semanas.'];
        }
    }

    $sel = (string) ($q['sel'] ?? '');
    $entidades = [];
    if ($modo === 'box') {
        foreach ($GLOBALS['BOXES'] ?? [] as $b) {
            if ($sel === 'todos' || $sel === $b['slug']) {
                $entidades[] = ['clave' => $b['slug'], 'nombre' => $b['nombre'], 'sub' => trim((string) ($b['publicoObjetivo'] ?? '')), 'ave' => (string) ($b['aveSlug'] ?? '')];
            }
        }
    } else {
        $profs = listProfesionales();
        $extra = [];
        try {
            foreach (db()->query('SELECT p.id, p.box_slug, p.titulo, e.nombre AS especialidad FROM profesionales p LEFT JOIN especialidades e ON e.slug = p.especialidad_slug') as $r) {
                $extra[(int) $r['id']] = $r;
            }
        } catch (Throwable $e) {
        }
        $i = 0;
        foreach ($profs as $id => $nombre) {
            if ($sel === 'todos' || $sel === (string) $id) {
                $x = $extra[$id] ?? [];
                $ave = '';
                foreach ($GLOBALS['BOXES'] ?? [] as $b) {
                    if (($x['box_slug'] ?? '') !== '' && $b['slug'] === $x['box_slug']) $ave = (string) $b['aveSlug'];
                }
                $entidades[] = ['clave' => (string) $id, 'nombre' => $nombre, 'sub' => (string) (($x['especialidad'] ?? '') ?: ($x['titulo'] ?? '')),
                    'ave' => $ave !== '' ? $ave : CALPDF_AVES[$i % count(CALPDF_AVES)]];
            }
            $i++;
        }
    }
    if (!$entidades) {
        return ['ok' => false, 'error' => $modo === 'box' ? 'Box no encontrado.' : 'Profesional no encontrado.'];
    }
    // «Todos»: un solo calendario por semana con todos juntos (una página por semana).
    if ($sel !== 'todos' && count($entidades) * count($semanas) > CALPDF_MAX_PAGINAS) {
        return ['ok' => false, 'error' => 'Son demasiadas páginas (' . count($entidades) * count($semanas) . '). Elige menos semanas o un solo ' . ($modo === 'box' ? 'box' : 'profesional') . '.'];
    }
    return ['ok' => true, 'modo' => $modo, 'entidades' => $entidades, 'semanas' => $semanas, 'pac' => $modo === 'prof' && ($q['pac'] ?? '1') !== '0', 'todos' => $sel === 'todos'];
}

/** Imagen PNG reducida (con transparencia) como data URI, con caché en storage/cache. */
function calPdfImg(string $rel, int $ancho): string
{
    $src = APP_ROOT . '/assets/images/' . $rel;
    if (!is_file($src) || !function_exists('imagecreatefrompng')) {
        return '';
    }
    $dir = APP_ROOT . '/storage/cache';
    $cache = $dir . '/calpdf_' . preg_replace('/[^a-z0-9]+/i', '_', $rel) . '_' . $ancho . '.png';
    if (!is_file($cache) || filemtime($cache) < filemtime($src)) {
        $im = @imagecreatefrompng($src);
        if (!$im) return '';
        $w = imagesx($im); $h = imagesy($im); $nh = (int) round($h * $ancho / max(1, $w));
        $dst = imagecreatetruecolor($ancho, $nh);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $ancho, $nh, $w, $h);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @imagepng($dst, $cache, 9);
        imagedestroy($im); imagedestroy($dst);
    }
    $d = @file_get_contents($cache);
    return $d ? 'data:image/png;base64,' . base64_encode($d) : '';
}

function calPdfCorto(string $s, int $n): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return mb_strlen($s, 'UTF-8') > $n ? rtrim(mb_substr($s, 0, $n - 1, 'UTF-8')) . '…' : $s;
}

/** «Javiera Montes Lagos» -> «Javiera Montes» (primer nombre y primer apellido, para que quepa en una línea). */
function calPdfNombreCorto(string $s, int $n = 21): string
{
    $p = preg_split('/\s+/u', trim($s)) ?: [];
    $corto = count($p) >= 4 ? $p[0] . ' ' . $p[2] : implode(' ', array_slice($p, 0, 2));
    return calPdfCorto($corto !== '' ? $corto : $s, $n);
}

/** «16 al 21 de noviembre de 2026» / «30 de noviembre al 5 de diciembre de 2026». */
function calPdfRangoTexto(string $ini, string $fin): string
{
    $m = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $a = strtotime($ini); $b = strtotime($fin);
    if (date('Y-m', $a) === date('Y-m', $b)) {
        return date('j', $a) . ' al ' . date('j', $b) . ' de ' . $m[(int) date('n', $b)] . ' de ' . date('Y', $b);
    }
    $anioA = date('Y', $a) !== date('Y', $b) ? ' de ' . date('Y', $a) : '';
    return date('j', $a) . ' de ' . $m[(int) date('n', $a)] . $anioA . ' al ' . date('j', $b) . ' de ' . $m[(int) date('n', $b)] . ' de ' . date('Y', $b);
}

/** HTML de una página (una semana de un box o profesional). */
function calPdfPagina(string $modo, array $ent, string $lunes, bool $pac, bool $ultima): string
{
    $h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $C = ['cafe' => '#5C4033', 'cafe2' => '#3D2A21', 'terr' => '#D97757', 'terrO' => '#B85C3E', 'salvia' => '#8B9E8F', 'salviaO' => '#5E7263',
          'crema' => '#F5F1E8', 'crema2' => '#FBF9F4', 'linea' => '#E2D7C5', 'gris' => '#85776B'];
    $horas = horasAgenda();
    $dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $ini = new DateTimeImmutable($lunes);
    $fechas = [];
    for ($i = 0; $i < 6; $i++) $fechas[] = $ini->modify("+$i days")->format('Y-m-d');
    $fer = feriadosEntre($fechas[0], $fechas[5]);
    $grilla = reservasGrilla($fechas[0], $fechas[5], $modo === 'box' ? $ent['clave'] : null, $modo === 'prof' ? (int) $ent['clave'] : null);
    $cupo = boxCupoMax();
    $hoy = date('Y-m-d');

    // Resumen de la semana
    $slots = 0; $reservas = 0; $personas = []; $boxesUsados = [];
    foreach ($grilla as $f => $porHora) {
        foreach ($porHora as $hh => $lista) {
            if (!in_array($hh, $horas, true) || !in_array($f, $fechas, true)) continue;
            $slots++; $reservas += count($lista);
            foreach ($lista as $r) {
                if ($modo === 'box') { if ($r['profesional_id']) $personas[(int) $r['profesional_id']] = 1; }
                else { $personas[mb_strtolower(trim((string) $r['nombre']), 'UTF-8')] = 1; $boxesUsados[$r['box_slug']] = 1; }
            }
        }
    }
    $capacidad = 0;   // bloques de 1 hora en que atiende el centro esa semana (sin feriados no trabajados)
    foreach ($fechas as $f) {
        if (!(isset($fer[$f]) && !$fer[$f]['se_trabaja'])) $capacidad += count(horasDelDia($f));
    }
    $pct = $capacidad > 0 ? (int) round($slots / $capacidad * 100) : 0;

    $ave = $ent['ave'] !== '' ? calPdfImg('boxes/aves/' . $ent['ave'] . '.png', 300) : '';
    $logo = certLogoDataUri();
    $titulo = $modo === 'box' ? 'Calendario de Boxes' : 'Calendario de Profesionales';

    $chip = static fn(string $v, string $t) => '<td style="padding:0 0 0 6pt;"><div style="background:#FFFFFF;border:0.6pt solid ' . $C['linea'] . ';border-radius:8pt;padding:3pt 8pt;text-align:center;">'
        . '<div style="font-family:\'DejaVu Serif\';font-size:12pt;font-weight:bold;color:' . $C['cafe'] . ';line-height:1.1;">' . $v . '</div>'
        . '<div style="font-size:6pt;letter-spacing:0.6pt;text-transform:uppercase;white-space:nowrap;color:' . $C['gris'] . ';">' . $t . '</div></div></td>';
    $chips = $modo === 'box'
        ? $chip((string) $slots, $slots === 1 ? 'hora reservada' : 'horas reservadas') . $chip($pct . '%', 'ocupación') . $chip((string) count($personas), count($personas) === 1 ? 'profesional' : 'profesionales')
        : $chip((string) $reservas, $reservas === 1 ? 'hora agendada' : 'horas agendadas') . $chip((string) count($personas), count($personas) === 1 ? 'paciente' : 'pacientes') . $chip((string) count($boxesUsados), count($boxesUsados) === 1 ? 'box' : 'boxes');

    $estadoColor = ['pendiente' => '#E0A93B', 'confirmada' => $C['salvia'], 'realizada' => '#4F8A5B', 'no_show' => '#9C8E82'];

    ob_start(); ?>
<div style="position:relative;<?= $ultima ? '' : 'page-break-after:always;' ?>">
    <div style="height:4pt;background:<?= $C['terr'] ?>;"></div>
    <div style="padding:10pt 28pt 0;">
        <!-- Encabezado -->
        <div style="position:relative;height:62pt;border-bottom:1.2pt solid <?= $C['linea'] ?>;">
            <table style="width:100%;border-collapse:collapse;"><tr>
                <td style="width:44pt;vertical-align:middle;"><?php if ($logo): ?><img src="<?= $h($logo) ?>" style="width:38pt;height:38pt;"><?php endif; ?></td>
                <td style="vertical-align:middle;padding-left:4pt;">
                    <div style="font-size:7pt;font-weight:bold;letter-spacing:1.4pt;text-transform:uppercase;color:<?= $C['terrO'] ?>;">Espacio Luz · <?= $titulo ?></div>
                    <div style="font-family:'DejaVu Serif';font-size:19pt;font-weight:bold;color:<?= $C['cafe2'] ?>;line-height:1.15;"><?= $h($ent['nombre']) ?></div>
                    <div style="font-size:8.5pt;color:<?= $C['gris'] ?>;">Semana del <strong style="color:<?= $C['cafe'] ?>;"><?= $h(calPdfRangoTexto($fechas[0], $fechas[5])) ?></strong><?= $ent['sub'] !== '' ? ' · ' . $h($ent['sub']) : '' ?><?php if ($modo === 'prof'): $hpTxt = horarioProfesionalResumen((int) $ent['clave']); ?><br><span style="font-size:7.5pt;">Horario de atención: <?= $hpTxt !== null ? $h($hpTxt) : 'sin horario definido' ?></span><?php endif; ?></div>
                </td>
                <td style="vertical-align:middle;text-align:right;padding-right:<?= $ave ? '112pt' : '0' ?>;">
                    <table style="border-collapse:collapse;margin-left:auto;"><tr><?= $chips ?></tr></table>
                </td>
            </tr></table>
            <?php if ($ave): ?><img src="<?= $h($ave) ?>" style="position:absolute;right:0;bottom:-7pt;width:104pt;"><?php endif; ?>
        </div>

        <!-- Grilla -->
        <table style="width:100%;margin-top:8pt;border-collapse:separate;border-spacing:2.2pt;page-break-inside:avoid;">
            <thead><tr>
                <th style="width:4.6%;"></th>
                <?php foreach ($fechas as $i => $f): $fx = $fer[$f] ?? null; $esHoy = $f === $hoy; $sinAt = !horarioDiaAbierto($i + 1); ?>
                    <th style="width:15.9%;padding:3.5pt 2pt 3pt;border-radius:5pt;text-align:center;vertical-align:top;background:<?= $fx ? '#EFE9FB' : ($sinAt ? '#ECE7DF' : ($esHoy ? '#F8E6DE' : $C['crema'])) ?>;<?= $fx ? 'border:0.6pt solid #C9B8EE;' : '' ?>">
                        <div style="font-size:6.5pt;font-weight:bold;letter-spacing:1pt;text-transform:uppercase;color:<?= $fx ? '#6D4FC2' : $C['gris'] ?>;"><?= $dias[$i] ?></div>
                        <div style="font-family:'DejaVu Serif';font-size:13pt;font-weight:bold;line-height:1.1;color:<?= $fx ? '#4C2F9E' : ($esHoy ? $C['terrO'] : $C['cafe']) ?>;"><?= (int) substr($f, 8, 2) ?></div>
                        <?php if ($sinAt && !$fx): ?><div style="font-size:5.6pt;font-weight:bold;letter-spacing:0.5pt;text-transform:uppercase;color:<?= $C['gris'] ?>;">Sin atención</div><?php endif; ?><?php if ($fx): ?><div style="font-size:6pt;font-weight:bold;color:#6D4FC2;line-height:1.2;"><?= $h(calPdfCorto($fx['nombre'], 26)) ?></div><div style="font-size:5.6pt;font-weight:bold;letter-spacing:0.5pt;text-transform:uppercase;color:<?= $fx['se_trabaja'] ? $C['salviaO'] : '#8A73CF' ?>;"><?= $fx['se_trabaja'] ? 'Se trabaja' : 'No se trabaja' ?></div><?php endif; ?>
                    </th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($horas as $hh): ?>
                <tr>
                    <td style="width:4.6%;height:24pt;text-align:right;vertical-align:middle;padding-right:4pt;font-size:7.5pt;font-weight:bold;color:<?= $C['gris'] ?>;"><?= $hh ?></td>
                    <?php foreach ($fechas as $f):
                        $lista = $grilla[$f][$hh] ?? [];
                        $fx = $fer[$f] ?? null;
                        $base = 'width:15.9%;height:24pt;border-radius:4pt;padding:2pt 4pt;vertical-align:middle;overflow:hidden;';
                        if ($lista):
                            $n = count($lista);
                            if ($modo === 'box') {
                                $conCupo = $n < $cupo;
                                $bg = $conCupo ? '#FCF1DA' : '#F6DDD2'; $bd = $conCupo ? '#E0A93B' : $C['terr'];
                                $nombres = [];
                                foreach ($lista as $r) {
                                    $np = $r['profesional_id'] ? nombreProfesional((int) $r['profesional_id']) : '';
                                    $k = $np !== '' ? $np : 'Sin profesional';
                                    $nombres[$k] = ($nombres[$k] ?? 0) + 1;
                                }
                                $l1 = ''; $j = 0;
                                foreach ($nombres as $np => $v) {
                                    if ($j++ >= 2) break;
                                    $l1 .= '<div style="font-size:7.2pt;font-weight:bold;color:' . $C['cafe2'] . ';line-height:1.15;white-space:nowrap;">' . $h($np === 'Sin profesional' ? $np : calPdfNombreCorto($np)) . ($v > 1 ? ' <span style="font-weight:normal;color:' . $C['gris'] . ';">×' . $v . '</span>' : '') . '</div>';
                                }
                                $l2 = count($nombres) < 2
                                    ? '<div style="font-size:6.3pt;color:' . ($conCupo ? '#9A6B12' : $C['terrO']) . ';">' . $n . '/' . $cupo . ' · ' . ($conCupo ? 'queda ' . ($cupo - $n) . ' cupo' . ($cupo - $n > 1 ? 's' : '') : 'box completo') . '</div>'
                                    : '';
                                echo '<td style="' . $base . 'background:' . $bg . ';border-left:2.4pt solid ' . $bd . ';">' . $l1 . $l2 . '</td>';
                            } else {
                                $r0 = $lista[0];
                                $dot = '<span style="color:' . ($estadoColor[$r0['estado']] ?? $C['gris']) . ';font-size:8pt;">●</span> ';
                                $quien = $pac ? calPdfNombreCorto((string) $r0['nombre'], 19) : 'Hora agendada';
                                echo '<td style="' . $base . 'background:#F6DDD2;border-left:2.4pt solid ' . $C['terr'] . ';">'
                                    . '<div style="font-size:7.2pt;font-weight:bold;color:' . $C['cafe2'] . ';line-height:1.15;white-space:nowrap;">' . $dot . $h($quien) . ($n > 1 ? ' <span style="color:' . $C['terrO'] . ';">+' . ($n - 1) . '</span>' : '') . '</div>'
                                    . '<div style="font-size:6.3pt;color:' . $C['gris'] . ';">' . $h(calPdfCorto(nombreBox((string) $r0['box_slug']), 26)) . '</div></td>';
                            }
                        elseif (horaFueraHorarioMotivo($f, $hh) !== null || !horarioDiaAbierto(horarioDow($f))):
                            echo '<td style="' . $base . 'vertical-align:middle;background:#ECE7DF;text-align:center;font-size:6.3pt;font-weight:bold;color:#B3A797;">cerrado</td>';
                        elseif ($modo === 'prof' && !horarioProfesionalAtiende((int) $ent['clave'], $f, $hh)):
                            echo '<td style="' . $base . 'background:#FFFFFF;border:0.6pt dashed #CDBFAE;text-align:center;font-size:6pt;color:#B3A797;">fuera de horario</td>';
                        elseif ($fx && !$fx['se_trabaja']):
                            echo '<td style="' . $base . 'background:#F1ECFA;text-align:center;font-size:6.3pt;font-weight:bold;color:#9A86D6;">feriado</td>';
                        else:
                            echo '<td style="' . $base . 'background:' . ($fx ? '#F6F2FC' : '#F1F4EF') . ';border:0.5pt solid ' . ($fx ? '#E3DAF6' : '#DCE4DA') . ';text-align:center;font-size:6.3pt;color:' . ($fx ? '#A898DA' : '#9DB0A1') . ';">libre</td>';
                        endif;
                    endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Leyenda + aves -->
        <table style="width:100%;border-collapse:collapse;margin-top:5pt;"><tr>
            <td style="vertical-align:middle;font-size:6.8pt;color:<?= $C['gris'] ?>;">
                <?php
                $sw = static fn(string $bg, string $bd, string $t) => '<span style="display:inline-block;width:10pt;height:8pt;border-radius:2pt;background:' . $bg . ';border:0.5pt solid ' . $bd . ';border-left:2.4pt solid ' . $bd . ';vertical-align:middle;"></span> ' . $t . '&nbsp;&nbsp;&nbsp;';
                echo $sw('#F1F4EF', '#DCE4DA', 'Libre');
                if ($modo === 'box') {
                    echo $sw('#FCF1DA', '#E0A93B', 'Reservada con cupo') . $sw('#F6DDD2', $C['terr'], 'Box completo (máx. ' . $cupo . ' reservas por hora)');
                } else {
                    echo $sw('#F6DDD2', $C['terr'], 'Hora agendada');
                    if (horarioProfesionalDefinido((int) $ent['clave'])) echo '<span style="display:inline-block;width:10pt;height:8pt;border-radius:2pt;background:#FFFFFF;border:0.6pt dashed #CDBFAE;vertical-align:middle;"></span> Fuera de su horario&nbsp;&nbsp;&nbsp;';
                    foreach (['pendiente' => 'Pendiente', 'confirmada' => 'Confirmada', 'realizada' => 'Realizada', 'no_show' => 'Inasistencia'] as $k => $t) {
                        echo '<span style="color:' . $estadoColor[$k] . ';font-size:8pt;">●</span> ' . $t . '&nbsp;&nbsp;&nbsp;';
                    }
                }
                echo $sw('#F1ECFA', '#C9B8EE', 'Feriado');
                ?>
                <span style="color:<?= $C['gris'] ?>;">· Atención: <?= $h(horarioCentroResumen()) ?></span>
            </td>
            <td style="width:150pt;text-align:right;vertical-align:bottom;white-space:nowrap;">
                <?php foreach (CALPDF_AVES as $a): if ($a === $ent['ave']) continue; $src = calPdfImg('boxes/aves/' . $a . '.png', 120); if ($src): ?><img src="<?= $h($src) ?>" style="width:34pt;margin-left:2pt;"><?php endif; endforeach; ?>
            </td>
        </tr></table>
    </div>
</div>
<?php
    return (string) ob_get_clean();
}

/* ------------------------------------------------ Calendario combinado (todos) */

/** Color de cada box (el de su ave) y paleta para profesionales. */
const CALPDF_COLOR_AVE = ['loica' => '#C9503B', 'martin-pescador' => '#3F7FA3', '7-colores' => '#B8901A', 'colibri' => '#3A8F63', 'golondrina' => '#34507F'];
const CALPDF_PALETA = ['#D97757', '#5E8C6A', '#B8901A', '#7A5BC0', '#3F7FA3', '#B05A78', '#6B8A2E', '#8A5A3C', '#2F8C8C', '#C26A2E', '#5A6FB0', '#9C4F4F'];

/** «#C9503B» + 0.14 -> «rgba(201,80,59,0.14)» */
function calPdfRgba(string $hex, float $a): string
{
    $h = ltrim($hex, '#');
    return 'rgba(' . hexdec(substr($h, 0, 2)) . ',' . hexdec(substr($h, 2, 2)) . ',' . hexdec(substr($h, 4, 2)) . ',' . $a . ')';
}

/** «Gabriela Valenzuela» -> «Gabriela V.» (para el calendario combinado). */
function calPdfNombreInicial(string $s): string
{
    $p = preg_split('/\s+/u', trim($s)) ?: [];
    if (count($p) < 2) return calPdfCorto($s, 14);
    $ape = count($p) >= 4 ? $p[2] : $p[1];
    return calPdfCorto($p[0], 11) . ' ' . mb_substr($ape, 0, 1, 'UTF-8') . '.';
}

/** Abreviación corta de un box para las celdas: «Gimnasio Golondrina» -> «Golondrina». */
function calPdfBoxCorto(string $nombre): string
{
    $n = trim(preg_replace('/^(box|gimnasio)\s+/iu', '', $nombre));
    return calPdfCorto($n !== '' ? $n : $nombre, 16);
}

/**
 * Una página con TODOS los boxes o TODOS los profesionales de una semana en un mismo
 * calendario: cada uno con su color; en cada hora se ve quién atiende y en qué box.
 */
function calPdfPaginaTodos(string $modo, array $entidades, string $lunes, bool $ultima): string
{
    $h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $C = ['cafe' => '#5C4033', 'cafe2' => '#3D2A21', 'terr' => '#D97757', 'terrO' => '#B85C3E', 'salvia' => '#8B9E8F', 'salviaO' => '#5E7263',
          'crema' => '#F5F1E8', 'linea' => '#E2D7C5', 'gris' => '#85776B'];
    $horas = horasAgenda();
    $dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $ini = new DateTimeImmutable($lunes);
    $fechas = [];
    for ($i = 0; $i < 6; $i++) $fechas[] = $ini->modify("+$i days")->format('Y-m-d');
    $fer = feriadosEntre($fechas[0], $fechas[5]);
    $grilla = reservasGrilla($fechas[0], $fechas[5]);
    $hoy = date('Y-m-d');

    // Color y orden de cada entidad
    $color = []; $orden = [];
    foreach ($entidades as $i => $en) {
        $color[$en['clave']] = $modo === 'box' ? (CALPDF_COLOR_AVE[$en['ave']] ?? CALPDF_PALETA[$i % count(CALPDF_PALETA)]) : CALPDF_PALETA[$i % count(CALPDF_PALETA)];
        $orden[$en['clave']] = $i;
    }
    $gris = '#9C8E82';

    // Entradas por celda: [clave, etiqueta, veces], agrupando repetidas.
    $celdas = []; $maxL = 0; $slotsBox = []; $reservas = 0; $profsSemana = []; $boxesSemana = [];
    foreach ($fechas as $f) {
        foreach ($horas as $hh) {
            $grupos = [];
            foreach ($grilla[$f][$hh] ?? [] as $r) {
                $pid = $r['profesional_id'] ? (string) (int) $r['profesional_id'] : '';
                if ($modo === 'prof') {
                    if ($pid === '' || !isset($orden[$pid])) continue;     // solo horas con profesional
                    $clave = $pid;
                    $etq = calPdfNombreInicial(nombreProfesional((int) $pid)) . ' · ' . calPdfBoxCorto(nombreBox((string) $r['box_slug']));
                } else {
                    $clave = (string) $r['box_slug'];
                    $etq = calPdfBoxCorto(nombreBox($clave)) . ' · ' . ($pid !== '' ? calPdfNombreInicial(nombreProfesional((int) $pid)) : 'Sin profesional');
                    $slotsBox[$clave . '|' . $f . '|' . $hh] = 1;
                }
                $reservas++;
                if ($pid !== '') $profsSemana[$pid] = 1;
                $boxesSemana[(string) $r['box_slug']] = 1;
                $k = $clave . '|' . $etq;
                if (!isset($grupos[$k])) $grupos[$k] = [$clave, $etq, 0];
                $grupos[$k][2]++;
            }
            uasort($grupos, static fn($a, $b) => ($orden[$a[0]] ?? 99) <=> ($orden[$b[0]] ?? 99));
            $celdas[$f][$hh] = array_values($grupos);
            $maxL = max($maxL, count($grupos));
        }
    }
    // Una columna si caben (≤ 3 por hora); si no, dos columnas con etiquetas más cortas.
    $dosCol = $maxL > 3;
    $maxVis = $dosCol ? 6 : 3;
    $altoFila = $maxL <= 2 ? 24 : 26;

    $horasSemana = 0;
    foreach ($fechas as $f) {
        if (!(isset($fer[$f]) && !$fer[$f]['se_trabaja'])) $horasSemana += count(horasDelDia($f));
    }
    $nBoxes = count($GLOBALS['BOXES'] ?? []);
    $pct = $horasSemana > 0 && $nBoxes > 0 ? (int) round(count($slotsBox) / ($horasSemana * $nBoxes) * 100) : 0;
    $logo = certLogoDataUri();
    $titulo = $modo === 'box' ? 'Calendario de Boxes' : 'Calendario de Profesionales';
    $nombre = $modo === 'box' ? 'Todos los boxes' : 'Todas las profesionales';

    $chip = static fn(string $v, string $t) => '<td style="padding:0 0 0 6pt;"><div style="background:#FFFFFF;border:0.6pt solid ' . $C['linea'] . ';border-radius:8pt;padding:3pt 8pt;text-align:center;">'
        . '<div style="font-family:\'DejaVu Serif\';font-size:12pt;font-weight:bold;color:' . $C['cafe'] . ';line-height:1.1;">' . $v . '</div>'
        . '<div style="font-size:6pt;letter-spacing:0.6pt;text-transform:uppercase;white-space:nowrap;color:' . $C['gris'] . ';">' . $t . '</div></div></td>';
    $nP = count($profsSemana); $nB = count($boxesSemana);
    $chips = $modo === 'box'
        ? $chip((string) count($slotsBox), 'horas de box') . $chip($pct . '%', 'ocupación') . $chip((string) $nP, $nP === 1 ? 'profesional' : 'profesionales')
        : $chip((string) $reservas, $reservas === 1 ? 'hora agendada' : 'horas agendadas') . $chip((string) $nP, $nP === 1 ? 'profesional' : 'profesionales') . $chip((string) $nB, $nB === 1 ? 'box' : 'boxes');

    $entrada = static function (array $g, bool $corta) use ($h, $color, $gris, $C): string {
        [$clave, $etq, $veces] = $g;
        $col = $color[$clave] ?? $gris;
        if ($corta) {
            $partes = explode(' · ', $etq);
            $etq = $partes[0];
        }
        return '<div style="margin:0 0 0.5pt;padding:0 2.5pt;border-left:2.2pt solid ' . $col . ';background:' . calPdfRgba($col, 0.2) . ';border-radius:1.5pt;'
            . 'font-size:' . ($corta ? '5.7' : '6.1') . 'pt;line-height:1.06;color:' . $C['cafe2'] . ';">'
            . $h($etq) . ($veces > 1 ? ' <span style="color:' . $C['gris'] . ';">×' . $veces . '</span>' : '') . '</div>';
    };

    ob_start(); ?>
<div style="position:relative;<?= $ultima ? '' : 'page-break-after:always;' ?>">
    <div style="height:4pt;background:<?= $C['terr'] ?>;"></div>
    <div style="padding:10pt 28pt 0;">
        <!-- Encabezado -->
        <div style="position:relative;height:54pt;border-bottom:1.2pt solid <?= $C['linea'] ?>;">
            <table style="width:100%;border-collapse:collapse;"><tr>
                <td style="width:44pt;vertical-align:middle;"><?php if ($logo): ?><img src="<?= $h($logo) ?>" style="width:38pt;height:38pt;"><?php endif; ?></td>
                <td style="vertical-align:middle;padding-left:4pt;">
                    <div style="font-size:7pt;font-weight:bold;letter-spacing:1.4pt;text-transform:uppercase;color:<?= $C['terrO'] ?>;">Espacio Luz · <?= $titulo ?></div>
                    <div style="font-family:'DejaVu Serif';font-size:19pt;font-weight:bold;color:<?= $C['cafe2'] ?>;line-height:1.15;"><?= $nombre ?></div>
                    <div style="font-size:8.5pt;color:<?= $C['gris'] ?>;">Semana del <strong style="color:<?= $C['cafe'] ?>;"><?= $h(calPdfRangoTexto($fechas[0], $fechas[5])) ?></strong></div>
                </td>
                <td style="vertical-align:middle;text-align:right;padding-right:196pt;white-space:nowrap;">
                    <table style="border-collapse:collapse;margin-left:auto;"><tr><?= $chips ?></tr></table>
                </td>
            </tr></table>
            <div style="position:absolute;right:0;bottom:-5pt;width:190pt;text-align:right;white-space:nowrap;"><?php foreach (CALPDF_AVES as $a): $src = calPdfImg('boxes/aves/' . $a . '.png', 140); if ($src): ?><img src="<?= $h($src) ?>" style="width:33pt;"><?php endif; endforeach; ?></div>
        </div>

        <!-- Grilla -->
        <table style="width:100%;margin-top:5pt;border-collapse:separate;border-spacing:2pt;">
            <thead><tr>
                <th style="width:4.6%;"></th>
                <?php foreach ($fechas as $i => $f): $fx = $fer[$f] ?? null; $esHoy = $f === $hoy; $sinAt = !horarioDiaAbierto($i + 1); ?>
                    <th style="width:15.9%;padding:2.5pt 2pt 2pt;border-radius:5pt;text-align:center;vertical-align:top;background:<?= $fx ? '#EFE9FB' : ($sinAt ? '#ECE7DF' : ($esHoy ? '#F8E6DE' : $C['crema'])) ?>;<?= $fx ? 'border:0.6pt solid #C9B8EE;' : '' ?>">
                        <div style="font-size:6.5pt;font-weight:bold;letter-spacing:1pt;text-transform:uppercase;color:<?= $fx ? '#6D4FC2' : $C['gris'] ?>;"><?= $dias[$i] ?></div>
                        <div style="font-family:'DejaVu Serif';font-size:13pt;font-weight:bold;line-height:1.1;color:<?= $fx ? '#4C2F9E' : ($esHoy ? $C['terrO'] : $C['cafe']) ?>;"><?= (int) substr($f, 8, 2) ?></div>
                        <?php if ($sinAt && !$fx): ?><div style="font-size:5.6pt;font-weight:bold;letter-spacing:0.5pt;text-transform:uppercase;color:<?= $C['gris'] ?>;">Sin atención</div><?php endif; ?><?php if ($fx): ?><div style="font-size:6pt;font-weight:bold;color:#6D4FC2;line-height:1.2;"><?= $h(calPdfCorto($fx['nombre'], 26)) ?></div><div style="font-size:5.6pt;font-weight:bold;letter-spacing:0.5pt;text-transform:uppercase;color:<?= $fx['se_trabaja'] ? $C['salviaO'] : '#8A73CF' ?>;"><?= $fx['se_trabaja'] ? 'Se trabaja' : 'No se trabaja' ?></div><?php endif; ?>
                    </th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($horas as $hh): ?>
                <tr>
                    <td style="width:4.6%;height:<?= $altoFila ?>pt;text-align:right;vertical-align:middle;padding-right:4pt;font-size:7.5pt;font-weight:bold;color:<?= $C['gris'] ?>;"><?= $hh ?></td>
                    <?php foreach ($fechas as $f):
                        $lista = $celdas[$f][$hh] ?? [];
                        $fx = $fer[$f] ?? null;
                        $base = 'width:15.9%;height:' . $altoFila . 'pt;border-radius:4pt;padding:1.5pt 2pt;overflow:hidden;';
                        if ($lista):
                            $vis = array_slice($lista, 0, count($lista) > $maxVis ? $maxVis - 1 : $maxVis);
                            $resto = count($lista) - count($vis);
                            $mas = $resto > 0 ? '<div style="font-size:5.8pt;font-weight:bold;color:' . $C['terrO'] . ';">+' . $resto . ' más</div>' : '';
                            echo '<td style="' . $base . 'vertical-align:top;background:#FFFFFF;border:0.5pt solid ' . $C['linea'] . ';">';
                            if ($dosCol) {
                                $cols = [[], []];
                                foreach ($vis as $j => $g) $cols[$j % 2][] = $entrada($g, true);
                                echo '<table style="width:100%;border-collapse:collapse;"><tr><td style="width:50%;padding:0 1pt 0 0;vertical-align:top;">' . implode('', $cols[0]) . '</td><td style="width:50%;padding:0 0 0 1pt;vertical-align:top;">' . implode('', $cols[1]) . $mas . '</td></tr></table>';
                            } else {
                                foreach ($vis as $g) echo $entrada($g, false);
                                echo $mas;
                            }
                            echo '</td>';
                        elseif (horaFueraHorarioMotivo($f, $hh) !== null || !horarioDiaAbierto(horarioDow($f))):
                            echo '<td style="' . $base . 'vertical-align:middle;background:#ECE7DF;text-align:center;font-size:6.3pt;font-weight:bold;color:#B3A797;">cerrado</td>';
                        elseif ($fx && !$fx['se_trabaja']):
                            echo '<td style="' . $base . 'vertical-align:middle;background:#F1ECFA;text-align:center;font-size:6.3pt;font-weight:bold;color:#9A86D6;">feriado</td>';
                        else:
                            echo '<td style="' . $base . 'vertical-align:middle;background:' . ($fx ? '#F6F2FC' : '#F1F4EF') . ';border:0.5pt solid ' . ($fx ? '#E3DAF6' : '#DCE4DA') . ';text-align:center;font-size:6.3pt;color:' . ($fx ? '#A898DA' : '#9DB0A1') . ';">libre</td>';
                        endif;
                    endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Leyenda de colores -->
        <div style="margin-top:3pt;font-size:6.6pt;color:<?= $C['gris'] ?>;line-height:1.6;">
            <?php foreach ($entidades as $en):
                $col = $color[$en['clave']];
                $avIcon = $modo === 'box' && $en['ave'] !== '' ? calPdfImg('boxes/aves/' . $en['ave'] . '.png', 80) : ''; ?>
                <span style="white-space:nowrap;margin-right:7pt;"><?php if ($avIcon): ?><img src="<?= $h($avIcon) ?>" style="width:15pt;vertical-align:middle;"> <?php endif; ?><span style="display:inline-block;width:9pt;height:7pt;border-radius:1.5pt;background:<?= calPdfRgba($col, 0.18) ?>;border-left:2.4pt solid <?= $col ?>;vertical-align:middle;"></span> <span style="color:<?= $C['cafe'] ?>;"><?= $h($modo === 'box' ? $en['nombre'] : calPdfNombreCorto($en['nombre'], 24)) ?></span></span>
            <?php endforeach; ?>
            <span style="white-space:nowrap;margin-right:9pt;"><span style="display:inline-block;width:9pt;height:7pt;border-radius:1.5pt;background:#F1F4EF;border:0.5pt solid #DCE4DA;vertical-align:middle;"></span> Libre</span>
            <span style="white-space:nowrap;margin-right:9pt;"><span style="display:inline-block;width:9pt;height:7pt;border-radius:1.5pt;background:#F1ECFA;border:0.5pt solid #C9B8EE;vertical-align:middle;"></span> Feriado</span>
            <span style="white-space:nowrap;">· Atención: <?= $h(horarioCentroResumen()) ?><?= $modo === 'box' ? ' · máx. ' . boxCupoMax() . ' reservas por box y hora' : '' ?></span>
        </div>
    </div>
</div>
<?php
    return (string) ob_get_clean();
}

/** @return array{filename:string, bytes:string} */
function calPdfBytes(array $o): array
{
    require_once APP_ROOT . '/dte/vendor/autoload.php';
    @set_time_limit(120);
    $paginas = [];
    if (!empty($o['todos'])) {
        $n = count($o['semanas']);
        foreach ($o['semanas'] as $i => $lunes) {
            $paginas[] = calPdfPaginaTodos($o['modo'], $o['entidades'], $lunes, $i === $n - 1);
        }
    }
    $total = !empty($o['todos']) ? 0 : count($o['entidades']) * count($o['semanas']);
    $k = 0;
    foreach (!empty($o['todos']) ? [] : $o['entidades'] as $ent) {
        foreach ($o['semanas'] as $lunes) {
            $k++;
            $paginas[] = calPdfPagina($o['modo'], $ent, $lunes, $o['pac'], $k === $total);
        }
    }
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>@page{margin:0 0 26pt 0;} body{margin:0;background:#FBF9F4;} *{font-family:"DejaVu Sans",Arial,sans-serif;}</style></head><body>'
        . implode('', $paginas) . '</body></html>';
    $opt = new \Dompdf\Options();
    $opt->set('isRemoteEnabled', false);
    $opt->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($opt);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'landscape');
    $dompdf->render();
    $canvas = $dompdf->getCanvas();
    $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
    $w = $canvas->get_width(); $hg = $canvas->get_height();
    $gris = [0.52, 0.47, 0.42];
    $titulo = $o['modo'] === 'box' ? 'Calendario de Boxes' : 'Calendario de Profesionales';
    $canvas->page_line(28, $hg - 22, $w - 28, $hg - 22, [0.89, 0.84, 0.77], 0.6);
    $canvas->page_text(28, $hg - 17, 'Espacio Luz · ' . $titulo . ' · emitido el ' . date('d-m-Y') . ' a las ' . date('H:i') . ' h' . ($o['modo'] === 'prof' && $o['pac'] && empty($o['todos']) ? ' · Documento con datos de pacientes: uso interno' : ''), $font, 6.5, $gris);
    $canvas->page_text($w - 92, $hg - 17, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 6.5, $gris);

    $limpio = static fn($s) => trim(preg_replace('/[\\\\\/:*?"<>|]+/', ' ', (string) $s));
    $quien = !empty($o['todos']) ? ($o['modo'] === 'box' ? 'todos los boxes' : 'todas las profesionales') : $o['entidades'][0]['nombre'];
    $sem = $o['semanas'];
    $periodo = count($sem) === 1 ? 'semana ' . date('d-m-Y', strtotime($sem[0])) : date('d-m-Y', strtotime($sem[0])) . ' a ' . date('d-m-Y', strtotime(end($sem) . ' +5 days'));
    return ['filename' => $limpio($titulo . ' - ' . $quien . ' - ' . $periodo) . '.pdf', 'bytes' => (string) $dompdf->output()];
}
