<?php
/**
 * Ficha clínica: evoluciones (editar / eliminar con resguardo), próxima sesión
 * agendada y documento PDF de la ficha con secciones a elección + firma del
 * profesional responsable.
 *
 * Eliminación: la Ley 20.584 exige conservar la ficha clínica. Por eso una
 * evolución «eliminada» se oculta del sistema y del PDF, pero queda guardada con
 * quién y cuándo la eliminó (eliminado_en / eliminado_por).
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/pacientes.php';
require_once APP_ROOT . '/lib/panel.php';
require_once APP_ROOT . '/lib/firmas.php';
require_once APP_ROOT . '/lib/certificados.php';   // certLogoDataUri, certFechaLarga

const FICHA_CAMPOS = [
    'motivo_consulta' => 'Motivo de consulta',
    'antecedentes'    => 'Antecedentes',
    'diagnostico'     => 'Impresión / diagnóstico',
    'objetivos'       => 'Objetivos terapéuticos',
];
/** Secciones que se pueden elegir para el PDF (clave => etiqueta). */
const FICHA_PDF_SECCIONES = [
    'datos'           => 'Datos del paciente',
    'motivo_consulta' => 'Motivo de consulta',
    'antecedentes'    => 'Antecedentes',
    'diagnostico'     => 'Impresión / diagnóstico',
    'objetivos'       => 'Objetivos terapéuticos',
    'proxima'         => 'Próxima sesión agendada',
    'evoluciones'     => 'Progreso / evoluciones',
    'firma'           => 'Firma del profesional responsable',
];

function usuarioActualNombre(): ?string
{
    return isset($_SESSION['admin_nombre']) ? mb_substr((string) $_SESSION['admin_nombre'], 0, 120, 'UTF-8') : null;
}

/* ------------------------------------------------------------- Evoluciones */

function getEvolucion(int $id): ?array
{
    try {
        $st = db()->prepare('SELECT ev.*, p.nombre AS profesional_nombre FROM evoluciones ev LEFT JOIN profesionales p ON p.id = ev.profesional_id WHERE ev.id = ? AND ev.eliminado_en IS NULL');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function actualizarEvolucion(int $id, array $d): array
{
    if (!getEvolucion($id)) {
        return ['ok' => false, 'error' => 'La evolución ya no existe (puede haber sido eliminada).'];
    }
    $nota = trim((string) ($d['nota_progreso'] ?? ''));
    if ($nota === '') {
        return ['ok' => false, 'error' => 'La nota de progreso es obligatoria.'];
    }
    $fecha = trim((string) ($d['fecha'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4))) {
        return ['ok' => false, 'error' => 'La fecha no es válida.'];
    }
    try {
        db()->prepare('UPDATE evoluciones SET fecha = ?, profesional_id = ?, nota_progreso = ?, proxima_accion = ?, editado_en = NOW(), editado_por = ? WHERE id = ?')
            ->execute([
                $fecha,
                ($d['profesional_id'] ?? '') !== '' ? (int) $d['profesional_id'] : null,
                $nota,
                trim((string) ($d['proxima_accion'] ?? '')) ?: null,
                usuarioActualNombre(),
                $id,
            ]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo guardar la evolución.'];
    }
}

/** Elimina (oculta) una evolución, conservando el registro por obligación legal. */
function eliminarEvolucion(int $id): array
{
    try {
        $st = db()->prepare('UPDATE evoluciones SET eliminado_en = NOW(), eliminado_por = ? WHERE id = ? AND eliminado_en IS NULL');
        $st->execute([usuarioActualNombre(), $id]);
        return $st->rowCount() > 0 ? ['ok' => true] : ['ok' => false, 'error' => 'La evolución ya no existe.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo eliminar la evolución.'];
    }
}

/* -------------------------------------------------------- Próxima sesión */

/**
 * Próxima hora agendada (pendiente o confirmada, desde ahora) del paciente para
 * esta ficha: primero con el profesional responsable; si no hay, con cualquier
 * profesional del área. Devuelve null si no hay ninguna.
 */
function proximaSesionFicha(array $ficha): ?array
{
    $hoy = date('Y-m-d');
    $ahora = date('H:i:s');
    $base = "SELECT r.id, r.fecha, TIME_FORMAT(r.hora, '%H:%i') AS hhmm, r.box_slug, r.profesional_id, r.modalidad_label, r.estado, p.nombre AS profesional_nombre
               FROM reservas r LEFT JOIN profesionales p ON p.id = r.profesional_id
              WHERE r.paciente_id = ? AND r.estado IN ('pendiente','confirmada')
                AND (r.fecha > ? OR (r.fecha = ? AND (r.hora IS NULL OR r.hora >= ?)))";
    try {
        $pid = (int) $ficha['paciente_id'];
        if (!empty($ficha['profesional_responsable_id'])) {
            $st = db()->prepare($base . ' AND r.profesional_id = ? ORDER BY r.fecha, r.hora LIMIT 1');
            $st->execute([$pid, $hoy, $hoy, $ahora, (int) $ficha['profesional_responsable_id']]);
            if ($r = $st->fetch()) {
                return $r;
            }
        }
        $st = db()->prepare($base . ' AND p.especialidad_slug = ? ORDER BY r.fecha, r.hora LIMIT 1');
        $st->execute([$pid, $hoy, $hoy, $ahora, (string) $ficha['especialidad_slug']]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** «Jueves 20 de noviembre de 2026, 15:00 h». */
function fechaHoraLarga(string $ymd, ?string $hhmm = null): string
{
    $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $ts = strtotime($ymd);
    $txt = $ts ? ucfirst($dias[(int) date('w', $ts)]) . ' ' . certFechaLarga($ymd, false) : $ymd;
    return $txt . ($hhmm ? ', ' . $hhmm . ' h' : '');
}

/** Texto corto de la próxima sesión (para el panel). */
function proximaSesionTexto(?array $r): string
{
    if (!$r) {
        return '';
    }
    $partes = [fechaHoraLarga((string) $r['fecha'], $r['hhmm'] ?: null)];
    if (!empty($r['profesional_nombre'])) $partes[] = $r['profesional_nombre'];
    if (!empty($r['box_slug'])) $partes[] = nombreBox((string) $r['box_slug']);
    return implode(' · ', $partes);
}

/* ------------------------------------------------------------------ PDF */

/**
 * Opciones del PDF desde la URL: secciones (s=datos,motivo_consulta,…),
 * evoluciones (ev=todas|rango|sel, desde, hasta, ids=1,2,3).
 */
function fichaPdfOpciones(array $q): array
{
    $s = array_values(array_intersect(array_map('trim', explode(',', (string) ($q['s'] ?? ''))), array_keys(FICHA_PDF_SECCIONES)));
    if (!$s) {
        $s = array_keys(FICHA_PDF_SECCIONES);       // sin elección explícita: ficha completa
    }
    $ev = in_array($q['ev'] ?? '', ['todas', 'rango', 'sel'], true) ? $q['ev'] : 'todas';
    $fecha = static fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';
    return [
        'secciones' => $s,
        'ev' => $ev,
        'desde' => $fecha($q['desde'] ?? ''),
        'hasta' => $fecha($q['hasta'] ?? ''),
        'ids' => array_values(array_filter(array_map('intval', explode(',', (string) ($q['ids'] ?? ''))))),
    ];
}

/** Evoluciones de la ficha según el filtro elegido (orden cronológico). */
function fichaPdfEvoluciones(int $fichaId, array $o): array
{
    $evs = array_reverse(fichaEvoluciones($fichaId));
    return array_values(array_filter($evs, static function ($ev) use ($o) {
        if ($o['ev'] === 'sel') return in_array((int) $ev['id'], $o['ids'], true);
        if ($o['ev'] === 'rango') {
            if ($o['desde'] !== '' && $ev['fecha'] < $o['desde']) return false;
            if ($o['hasta'] !== '' && $ev['fecha'] > $o['hasta']) return false;
        }
        return true;
    }));
}

function fichaPdfNombreArchivo(array $paciente, array $ficha): string
{
    $limpio = static fn($s) => trim(preg_replace('/[\\\\\/:*?"<>|]+/', ' ', (string) $s));
    return 'Ficha clinica ' . $limpio($ficha['especialidad_nombre'] ?? $ficha['especialidad_slug']) . ' - ' . $limpio($paciente['nombre']) . '.pdf';
}

function fichaPdfHtml(array $paciente, array $ficha, array $o): string
{
    $h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $C = ['cafe' => '#5C4033', 'terr' => '#D97757', 'terrO' => '#B85C3E', 'salvia' => '#8B9E8F', 'crema' => '#F5F1E8', 'crema2' => '#FBF9F4', 'linea' => '#E2D7C5', 'gris' => '#85776B'];
    $sec = array_flip($o['secciones']);
    $area = (string) ($ficha['especialidad_nombre'] ?? $ficha['especialidad_slug']);
    $logo = certLogoDataUri();
    $fF = static fn($v) => $v ? date('d-m-Y', strtotime((string) $v)) : '';
    $resp = !empty($ficha['profesional_responsable_id']) ? db()->query('SELECT id, nombre, titulo, rut, firma_archivo FROM profesionales WHERE id = ' . (int) $ficha['profesional_responsable_id'])->fetch() : null;
    $evs = isset($sec['evoluciones']) ? fichaPdfEvoluciones((int) $ficha['id'], $o) : [];
    $prox = isset($sec['proxima']) ? proximaSesionFicha($ficha) : null;
    $txt = static fn($v) => trim((string) $v) !== '' ? nl2br($h(trim((string) $v))) : '<span style="color:#B3A89C;">Sin registro.</span>';

    // Estilos base del formulario clínico
    $bd = 'border:1px solid ' . $C['linea'] . ';';
    $lab = 'background:' . $C['crema2'] . ';color:' . $C['gris'] . ';font-size:8.5px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;padding:5px 7px;vertical-align:top;' . $bd;
    $val = 'font-size:10px;padding:5px 7px;vertical-align:top;' . $bd;
    $seccion = static fn(string $t, string $extra = '') => '<div style="margin:13px 0 5px;font-size:9px;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:' . $C['terrO'] . ';">' . $t . $extra . '</div>';

    ob_start(); ?>
<div style="font-family:'DejaVu Sans',Arial,sans-serif;color:<?= $C['cafe'] ?>;font-size:10px;line-height:1.42;">
    <div style="height:5px;background:<?= $C['terr'] ?>;"></div>
    <div style="padding:9px 40px 0;">
        <!-- Membrete -->
        <table style="width:100%;border-collapse:collapse;"><tr>
            <td style="width:52px;vertical-align:middle;"><?php if ($logo): ?><img src="<?= $h($logo) ?>" style="width:42px;height:42px;border-radius:50%;"><?php endif; ?></td>
            <td style="vertical-align:middle;">
                <div style="font-family:'DejaVu Serif',Georgia,serif;font-size:17px;font-weight:700;line-height:1.1;">Espacio Luz</div>
                <div style="font-size:8.5px;color:<?= $C['gris'] ?>;">Centro integral de terapias y bienestar</div>
            </td>
            <td style="vertical-align:middle;text-align:right;">
                <div style="font-size:8px;letter-spacing:1.3px;font-weight:700;color:<?= $C['salvia'] ?>;">FICHA CLÍNICA</div>
                <div style="font-size:14px;font-weight:800;line-height:1.15;">N° <?= str_pad((string) $ficha['id'], 6, '0', STR_PAD_LEFT) ?></div>
                <div style="font-size:8px;color:<?= $C['gris'] ?>;">Emitida el <?= date('d-m-Y') ?> · <?= date('H:i') ?> h</div>
            </td>
        </tr></table>

        <!-- Banda de título -->
        <table style="width:100%;border-collapse:collapse;margin-top:8px;background:<?= $C['crema'] ?>;border-left:3px solid <?= $C['terr'] ?>;"><tr>
            <td style="padding:7px 10px;vertical-align:middle;">
                <div style="font-family:'DejaVu Serif',Georgia,serif;font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Ficha clínica · <?= $h($area) ?></div>
                <div style="font-size:9px;color:<?= $C['gris'] ?>;margin-top:1px;">Profesional responsable: <strong style="color:<?= $C['cafe'] ?>;"><?= $h($resp['nombre'] ?? 'Sin asignar') ?></strong><?= !empty($resp['titulo']) ? ' · ' . $h($resp['titulo']) : '' ?></div>
            </td>
            <td style="padding:7px 10px;vertical-align:middle;text-align:right;font-size:9px;color:<?= $C['gris'] ?>;white-space:nowrap;">
                Estado: <strong style="color:<?= $ficha['estado'] === 'cerrada' ? $C['gris'] : '#4F6354' ?>;"><?= $ficha['estado'] === 'cerrada' ? 'Cerrada' : 'Activa' ?></strong><br>
                Apertura: <?= $h($fF($ficha['abierta_en'])) ?><?= $ficha['estado'] === 'cerrada' && $ficha['cerrada_en'] ? '<br>Cierre: ' . $h($fF($ficha['cerrada_en'])) : '' ?>
            </td>
        </tr></table>

        <!-- Protección de datos -->
        <table style="width:100%;border-collapse:collapse;margin-top:7px;"><tr>
            <td style="padding:5px 10px;background:<?= $C['crema2'] ?>;border:1px solid <?= $C['linea'] ?>;border-left:3px solid <?= $C['salvia'] ?>;font-size:8.5px;line-height:1.45;color:<?= $C['gris'] ?>;text-align:center;">
                <strong style="color:<?= $C['cafe'] ?>;">Documento confidencial:</strong> contiene datos sensibles de salud (Ley 20.584 de derechos y deberes de los pacientes y Ley 19.628 de protección de datos). Emitido el <?= date('d-m-Y') ?> a las <?= date('H:i') ?> h.
            </td>
        </tr></table>

        <?php
        // Bloques que nunca se parten entre páginas. La firma jamás queda sola: viaja con los
        // 2 últimos recuadros de información (si no caben, pasan juntos a la página siguiente).
        $bloques = [];

        if (isset($sec['datos'])) {
            $edad = pacienteEdad($paciente['fecha_nacimiento'] ?? null);
            $dir = implode(', ', array_filter([trim((string) $paciente['direccion']), trim((string) $paciente['comuna']), trim((string) ($paciente['region'] ?? ''))]));
            $filas = [
                ['Paciente', '<strong>' . $h($paciente['nombre']) . '</strong>', pacienteDocEtiqueta($paciente), $h(pacienteDocNumero($paciente) ?: '—')],
                ['Nacimiento', $h(($fF($paciente['fecha_nacimiento']) ?: '—') . ($edad !== null ? ' (' . $edad . ' años)' : '')), 'Sexo', $h(PACIENTE_SEXOS[$paciente['sexo'] ?? ''] ?? '—')],
                ['Previsión', $h($paciente['prevision'] ?: '—'), 'Teléfono', $h($paciente['telefono'] ?: '—')],
                ['Correo', $h($paciente['email'] ?: '—'), 'Domicilio', $h($dir ?: '—')],
            ];
            if (pacienteTieneTutor($paciente['apoderado'] ?? null)) {
                $filas[] = ['Tutor', $h($paciente['apoderado']), 'RUT · tel. tutor', $h(($paciente['apoderado_rut'] ?: '—') . ' · ' . ($paciente['apoderado_telefono'] ?: '—'))];
            }
            $t = '<table style="width:100%;border-collapse:collapse;">';
            foreach ($filas as $f) {
                $t .= '<tr><td style="width:15%;' . $lab . '">' . $f[0] . '</td><td style="width:35%;' . $val . '">' . $f[1] . '</td>'
                    . '<td style="width:15%;' . $lab . '">' . $f[2] . '</td><td style="width:35%;' . $val . '">' . $f[3] . '</td></tr>';
            }
            if (!empty($paciente['contacto_emergencia'])) {
                $t .= '<tr><td style="' . $lab . '">Emergencia</td><td colspan="3" style="' . $val . '">' . $h($paciente['contacto_emergencia']) . '</td></tr>';
            }
            if (isset($sec['proxima'])) {
                $pt = $prox
                    ? '<strong>' . $h(fechaHoraLarga((string) $prox['fecha'], $prox['hhmm'] ?: null)) . '</strong>'
                      . (!empty($prox['profesional_nombre']) ? ' · ' . $h($prox['profesional_nombre']) : '')
                      . (!empty($prox['box_slug']) ? ' · ' . $h(nombreBox((string) $prox['box_slug'])) : '')
                      . ' <span style="color:' . $C['gris'] . ';">(' . ($prox['estado'] === 'confirmada' ? 'confirmada' : 'por confirmar') . ')</span>'
                    : '<span style="color:' . $C['gris'] . ';">Sin sesiones agendadas a la fecha de emisión.</span>';
                $t .= '<tr><td style="' . $lab . '">Próxima sesión</td><td colspan="3" style="' . $val . '">' . $pt . '</td></tr>';
            }
            $bloques[] = $seccion('Identificación del paciente') . $t . '</table>';
        } elseif (isset($sec['proxima'])) {
            $pt = $prox
                ? '<strong>' . $h(fechaHoraLarga((string) $prox['fecha'], $prox['hhmm'] ?: null)) . '</strong>' . (!empty($prox['profesional_nombre']) ? ' · ' . $h($prox['profesional_nombre']) : '') . (!empty($prox['box_slug']) ? ' · ' . $h(nombreBox((string) $prox['box_slug'])) : '')
                : '<span style="color:' . $C['gris'] . ';">Sin sesiones agendadas a la fecha de emisión.</span>';
            $bloques[] = '<table style="width:100%;border-collapse:collapse;margin-top:10px;"><tr><td style="width:15%;' . $lab . '">Próxima sesión</td><td style="' . $val . '">' . $pt . '</td></tr></table>';
        }

        // Antecedentes clínicos en grilla de 2 columnas (cada fila de la grilla es un recuadro)
        $campos = array_values(array_filter(array_keys(FICHA_CAMPOS), static fn($k) => isset($sec[$k])));
        $par = array_chunk($campos, 2);
        foreach ($par as $i => $fila) {
            $t = '<table style="width:100%;border-collapse:collapse;' . ($i > 0 ? 'margin-top:-1px;' : '') . '"><tr>';
            foreach ($fila as $k) {
                $t .= '<td style="width:50%;' . $lab . (count($fila) === 1 ? '' : '') . '"' . (count($fila) === 1 ? ' colspan="2"' : '') . '>' . FICHA_CAMPOS[$k] . '</td>';
            }
            $t .= '</tr><tr>';
            foreach ($fila as $k) {
                $t .= '<td style="' . $val . 'text-align:justify;"' . (count($fila) === 1 ? ' colspan="2"' : '') . '>' . $txt($ficha[$k]) . '</td>';
            }
            $bloques[] = ($i === 0 ? $seccion('Antecedentes clínicos') : '') . $t . '</tr></table>';
        }

        // Evoluciones: tabla con columnas. Las 2 últimas filas van con la firma.
        $cabeza = '<tr><td style="width:13%;' . $lab . '">Fecha</td><td style="width:19%;' . $lab . '">Profesional</td><td style="' . $lab . '">Evolución / progreso</td><td style="width:26%;' . $lab . '">Indicaciones</td></tr>';
        $filaEvo = static function (array $ev, int $i) use ($h, $fF, $val, $C): string {
            $fondo = $i % 2 ? 'background:' . $C['crema2'] . ';' : '';
            return '<tr style="page-break-inside:avoid;"><td style="width:13%;' . $val . $fondo . 'font-weight:700;white-space:nowrap;">' . $h($fF($ev['fecha'])) . '</td>'
                . '<td style="width:19%;' . $val . $fondo . 'font-size:9px;">' . $h($ev['profesional_nombre'] ?: '—') . '</td>'
                . '<td style="' . $val . $fondo . 'text-align:justify;">' . nl2br($h($ev['nota_progreso'])) . '</td>'
                . '<td style="width:26%;' . $val . $fondo . 'font-size:9px;color:#6E6156;">' . (!empty($ev['proxima_accion']) ? nl2br($h($ev['proxima_accion'])) : '—') . '</td></tr>';
        };
        $colaEvo = null;
        if (isset($sec['evoluciones'])) {
            $periodo = '';
            if ($o['ev'] === 'rango' && ($o['desde'] || $o['hasta'])) {
                $periodo = ' · ' . ($o['desde'] && $o['hasta'] ? 'del ' . $fF($o['desde']) . ' al ' . $fF($o['hasta']) : ($o['desde'] ? 'desde el ' . $fF($o['desde']) : 'hasta el ' . $fF($o['hasta'])));
            } elseif ($o['ev'] === 'sel') {
                $periodo = ' · selección';
            }
            $tit = $seccion('Registro de evoluciones', ' <span style="color:' . $C['gris'] . ';font-weight:400;letter-spacing:.3px;">(' . count($evs) . ($periodo !== '' ? $h($periodo) : '') . ')</span>');
            $n = count($evs);
            if ($n === 0) {
                $bloques[] = $tit . '<div style="' . $bd . 'padding:7px;color:' . $C['gris'] . ';">Sin evoluciones registradas' . ($periodo !== '' ? ' para el filtro elegido' : '') . '.</div>';
            } elseif ($n <= 2) {
                $t = '<table style="width:100%;border-collapse:collapse;">' . $cabeza;
                foreach ($evs as $i => $ev) $t .= $filaEvo($ev, $i);
                $colaEvo = $tit . $t . '</table>';
            } else {
                $t = '<table style="width:100%;border-collapse:collapse;"><thead>' . $cabeza . '</thead><tbody>';
                for ($i = 0; $i < $n - 2; $i++) $t .= $filaEvo($evs[$i], $i);
                $bloques[] = ['libre' => $tit . $t . '</tbody></table>'];      // tabla larga: puede continuar en la página siguiente
                $t = '<table style="width:100%;border-collapse:collapse;margin-top:-1px;">';
                for ($i = $n - 2; $i < $n; $i++) $t .= $filaEvo($evs[$i], $i);
                $colaEvo = $t . '</table>';
            }
        }

        $firma = '';
        if (isset($sec['firma'])) {
            $firmaArch = $resp ? ($resp['firma_archivo'] ?? null) : null;
            $firmaSrc = $firmaArch ? firmaDataUri($firmaArch) : '';
            if ($firmaSrc !== '') {
                $firma = '<div style="margin-top:12px;text-align:center;">'
                    . '<div style="text-align:center;height:150px;margin-bottom:3px;"><img src="' . $h($firmaSrc) . '" style="width:250px;height:150px;"></div>'
                    . '<div style="display:inline-block;width:260px;border-top:1.2px solid ' . $C['cafe'] . ';"></div>'
                    . '<div style="font-size:8.5px;color:' . $C['gris'] . ';margin-top:2px;letter-spacing:.3px;">Firma y timbre · Profesional responsable</div></div>';
            } else {
                $firma = '<div style="margin-top:46px;text-align:center;"><div style="display:inline-block;min-width:260px;border-top:1.2px solid ' . $C['cafe'] . ';padding-top:5px;">'
                    . '<div style="font-weight:700;font-size:11px;">' . $h($resp['nombre'] ?? 'Profesional responsable') . '</div>'
                    . (!empty($resp['rut']) ? '<div style="font-size:9px;color:' . $C['gris'] . ';">RUT: ' . $h($resp['rut']) . '</div>' : '')
                    . (!empty($resp['titulo']) ? '<div style="font-size:9.5px;">' . $h($resp['titulo']) . '</div>' : '')
                    . '</div></div>';
            }
        }

        // Reunir: lo que acompaña a la firma (las 2 últimas filas de evolución, o los 2 últimos recuadros)
        $cola = [];
        if ($firma !== '') {
            if ($colaEvo !== null && count($evs) >= 2) {
                $cola = [$colaEvo];
            } else {
                if ($colaEvo !== null) $bloques[] = $colaEvo;
                $libres = array_filter($bloques, 'is_string');
                $tomar = min(2, count($libres));
                for ($k = count($bloques) - 1; $k >= 0 && $tomar > 0; $k--) {
                    if (is_string($bloques[$k])) { array_unshift($cola, $bloques[$k]); array_splice($bloques, $k, 1); $tomar--; }
                    else break;
                }
            }
        } elseif ($colaEvo !== null) {
            $bloques[] = $colaEvo;
        }
        foreach ($bloques as $b) {
            echo is_array($b) ? $b['libre'] : '<div style="page-break-inside:avoid;">' . $b . '</div>';
        }
        if ($firma !== '') {
            echo '<div style="page-break-inside:avoid;">' . implode('', $cola) . $firma . '</div>';
        }
        ?>
    </div>
</div>
<?php
    return (string) ob_get_clean();
}

/** @return array{filename:string, bytes:string} */
function fichaPdfBytes(array $paciente, array $ficha, array $o): array
{
    require_once APP_ROOT . '/dte/vendor/autoload.php';
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>@page{margin:14px 0 46px;} body{margin:0;background:#fff;} *{font-family:"DejaVu Sans",Arial,sans-serif;}</style></head><body>'
        . fichaPdfHtml($paciente, $ficha, $o) . '</body></html>';
    $opt = new \Dompdf\Options();
    $opt->set('isRemoteEnabled', false);
    $opt->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($opt);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    // Pie en cada página: línea, identificación, confidencialidad y numeración.
    $canvas = $dompdf->getCanvas();
    $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
    $w = $canvas->get_width(); $hgt = $canvas->get_height();
    $gris = [0.52, 0.47, 0.42];
    $canvas->page_line(30, $hgt - 38, $w - 30, $hgt - 38, [0.89, 0.84, 0.77], 0.6);
    $canvas->page_text(30, $hgt - 32, 'Espacio Luz · Ficha clínica N° ' . str_pad((string) $ficha['id'], 6, '0', STR_PAD_LEFT) . ' · ' . $paciente['nombre'] . ' · ' . ($ficha['especialidad_nombre'] ?? $ficha['especialidad_slug']), $font, 7, $gris);
    $canvas->page_text(30, $hgt - 22, 'Documento confidencial con datos sensibles de salud (Ley 20.584 y Ley 19.628). Uso exclusivo del equipo tratante y del paciente.', $font, 6.5, $gris);
    $canvas->page_text($w - 92, $hgt - 32, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 7, $gris);
    return ['filename' => fichaPdfNombreArchivo($paciente, $ficha), 'bytes' => (string) $dompdf->output()];
}
