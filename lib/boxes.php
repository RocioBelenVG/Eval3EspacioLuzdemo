<?php
/**
 * Boxes — capa de datos (tabla `boxes`). Es la ÚNICA fuente de los boxes:
 * la página principal, la página de cada box, el calendario, las reservas y la
 * facturación leen de aquí; el panel (módulo Boxes) y el modo edición del sitio escriben aquí.
 *
 * Formato «catálogo» (el mismo que usaba data.php, para no romper nada):
 *   ['id','slug','nombre','ave','aveSlug','imagen','descripcion','ubicacion','elEspacioDescripcion',
 *    'equipamiento'=>[..], 'publicoObjetivo', 'tarifas'=>[['precio'=>'$8.000','label','sub','gastosComunes'], ..],
 *    'publico', 'activo', 'orden']
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';

/** Ilustraciones disponibles (ave + tronco, banner y foto «próximamente»). */
const BOX_AVES = ['loica' => 'Loica', 'martin-pescador' => 'Martín Pescador', '7-colores' => 'Siete Colores', 'colibri' => 'Colibrí', 'golondrina' => 'Golondrina'];
const BOX_TARIFAS_INDIVIDUAL = [
    ['label' => 'Por hora', 'sub' => '', 'precio' => 8000, 'gc' => 0],
    ['label' => '1 día a la semana mensual', 'sub' => 'Jornada mañana · de 08:00 a 13:00 h', 'precio' => 140000, 'gc' => 0],
    ['label' => '1 día a la semana mensual', 'sub' => 'Jornada tarde · desde las 14:00 h', 'precio' => 150000, 'gc' => 0],
    ['label' => 'Exclusividad mensual', 'sub' => 'Media jornada mañana', 'precio' => 350000, 'gc' => 2500],
    ['label' => 'Exclusividad mensual', 'sub' => 'Media jornada tarde', 'precio' => 400000, 'gc' => 2500],
    ['label' => 'Jornada completa mensual', 'sub' => '', 'precio' => 600000, 'gc' => 5000],
];

function boxPrecioTexto(int $n): string
{
    return '$' . number_format($n, 0, ',', '.');
}

function boxPrecioInt($v): int
{
    return (int) preg_replace('/\D/', '', (string) $v);
}

/** Fila de la tabla -> formato catálogo. */
function boxFilaAFormato(array $r): array
{
    $tar = [];
    foreach ((json_decode((string) $r['tarifas'], true) ?: []) as $t) {
        $x = ['precio' => boxPrecioTexto((int) ($t['precio'] ?? 0)), 'label' => (string) ($t['label'] ?? '')];
        if (($t['sub'] ?? '') !== '') $x['sub'] = (string) $t['sub'];
        if ((int) ($t['gc'] ?? 0) > 0) $x['gastosComunes'] = boxPrecioTexto((int) $t['gc']);
        $tar[] = $x;
    }
    return [
        'id' => (int) $r['id'],
        'slug' => (string) $r['slug'],
        'nombre' => (string) $r['nombre'],
        'ave' => (string) $r['ave'],
        'aveSlug' => (string) $r['ave_slug'],
        'imagen' => (string) $r['imagen'],
        'descripcion' => (string) $r['descripcion'],
        'ubicacion' => (string) $r['ubicacion'],
        'elEspacioDescripcion' => (string) $r['el_espacio'],
        'equipamiento' => array_values(array_map('strval', json_decode((string) $r['equipamiento'], true) ?: [])),
        'publicoObjetivo' => (string) $r['publico_objetivo'],
        'tarifas' => $tar,
        'publico' => (int) $r['publico'],
        'activo' => (int) $r['activo'],
        'orden' => (int) $r['orden'],
        'eliminado_at' => $r['eliminado_at'] ?? null,
    ];
}

/**
 * Catálogo de boxes vigentes (no archivados y disponibles), en el orden del panel.
 * Si la tabla aún no existe o está vacía, devuelve $fallback (data.php).
 */
function boxesCatalogo(array $fallback = []): array
{
    try {
        $rows = db()->query("SELECT * FROM boxes WHERE eliminado_at IS NULL AND activo = 1 ORDER BY orden, id")->fetchAll();
        if (!$rows) {
            $hay = (int) db()->query("SELECT COUNT(*) FROM boxes")->fetchColumn();
            return $hay ? [] : $fallback;
        }
        return array_map('boxFilaAFormato', $rows);
    } catch (Throwable $e) {
        return $fallback;
    }
}

/** Todos los boxes para el panel (incluye pausados; opcionalmente los archivados). */
function boxesPanel(bool $archivados = false): array
{
    try {
        $rows = db()->query("SELECT * FROM boxes WHERE eliminado_at IS " . ($archivados ? 'NOT ' : '') . "NULL ORDER BY orden, id")->fetchAll();
        return array_map('boxFilaAFormato', $rows);
    } catch (Throwable $e) {
        return [];
    }
}

function getBoxFila(int $id): ?array
{
    try {
        $st = db()->prepare("SELECT * FROM boxes WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ? boxFilaAFormato($r) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Nombre de un box por slug aunque esté archivado (para el historial). */
function boxNombrePorSlug(string $slug): ?string
{
    try {
        $st = db()->prepare("SELECT nombre FROM boxes WHERE slug = ? LIMIT 1");
        $st->execute([$slug]);
        $n = $st->fetchColumn();
        return $n !== false ? (string) $n : null;
    } catch (Throwable $e) {
        return null;
    }
}

function boxSlugify(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    return $s !== '' ? substr($s, 0, 50) : 'box';
}

/** Valida y normaliza el formulario. ['ok'=>true,'data'=>[...]] o error. */
function boxNormalizar(array $d): array
{
    $s = fn(string $k, int $max) => mb_substr(trim((string) ($d[$k] ?? '')), 0, $max, 'UTF-8');
    $x = [
        'nombre' => $s('nombre', 120),
        'ave_slug' => array_key_exists((string) ($d['ave_slug'] ?? ''), BOX_AVES) ? (string) $d['ave_slug'] : '',
        'ubicacion' => $s('ubicacion', 120),
        'publico_objetivo' => $s('publico_objetivo', 160),
        'descripcion' => $s('descripcion', 600),
        'el_espacio' => $s('el_espacio', 1200),
        'orden' => (int) ($d['orden'] ?? 0),
        'publico' => empty($d['publico']) ? 0 : 1,
        'activo' => array_key_exists('activo', $d) ? (empty($d['activo']) ? 0 : 1) : 1,
    ];
    if ($x['nombre'] === '') return ['ok' => false, 'error' => 'Escribe el nombre del box.'];
    if ($x['ave_slug'] === '') return ['ok' => false, 'error' => 'Elige la ilustración (ave) del box.'];
    if ($x['descripcion'] === '') return ['ok' => false, 'error' => 'Escribe una descripción breve: aparece en la página principal.'];
    $x['ave'] = trim((string) ($d['ave'] ?? '')) !== '' ? mb_substr(trim((string) $d['ave']), 0, 80, 'UTF-8') : BOX_AVES[$x['ave_slug']];

    $eq = [];
    foreach ((array) ($d['equipamiento'] ?? []) as $e) {
        $e = mb_substr(trim((string) $e), 0, 120, 'UTF-8');
        if ($e !== '') $eq[] = $e;
    }
    $x['equipamiento'] = array_slice($eq, 0, 20);

    $tar = [];
    $porHora = 0;
    foreach ((array) ($d['tarifas'] ?? []) as $t) {
        $label = mb_substr(trim((string) ($t['label'] ?? '')), 0, 80, 'UTF-8');
        $precio = boxPrecioInt($t['precio'] ?? 0);
        if ($label === '' && $precio === 0) continue;
        if ($label === '') return ['ok' => false, 'error' => 'Cada tarifa necesita su nombre (ej.: Por hora).'];
        if ($precio <= 0) return ['ok' => false, 'error' => 'La tarifa «' . $label . '» necesita un precio.'];
        if (mb_strtolower($label, 'UTF-8') === 'por hora') {
            $label = 'Por hora';
            $porHora++;
        }
        $tar[] = ['label' => $label, 'sub' => mb_substr(trim((string) ($t['sub'] ?? '')), 0, 120, 'UTF-8'), 'precio' => $precio, 'gc' => boxPrecioInt($t['gc'] ?? 0)];
    }
    if (!$tar) return ['ok' => false, 'error' => 'Agrega al menos una tarifa.'];
    if ($porHora !== 1) return ['ok' => false, 'error' => 'El box necesita UNA tarifa «Por hora»: con ella se valoran las horas del Calendario de Boxes y su facturación.'];
    // «Por hora» primero (así se muestra en el sitio).
    usort($tar, fn($a, $b) => ($b['label'] === 'Por hora') <=> ($a['label'] === 'Por hora'));
    $x['tarifas'] = $tar;
    return ['ok' => true, 'data' => $x];
}

function crearBox(array $d): array
{
    $n = boxNormalizar($d);
    if (!$n['ok']) return $n;
    $x = $n['data'];
    $base = boxSlugify($x['nombre']);
    $slug = $base;
    $i = 2;
    $chk = db()->prepare("SELECT COUNT(*) FROM boxes WHERE slug = ?");
    while (true) {
        $chk->execute([$slug]);
        if (!(int) $chk->fetchColumn()) break;
        $slug = $base . '-' . $i++;
    }
    if (!$x['orden']) {
        $x['orden'] = (int) db()->query("SELECT COALESCE(MAX(orden),0)+1 FROM boxes")->fetchColumn();
    }
    try {
        db()->prepare("INSERT INTO boxes (slug, nombre, ave, ave_slug, imagen, descripcion, ubicacion, el_espacio, equipamiento, publico_objetivo, tarifas, orden, publico, activo)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$slug, $x['nombre'], $x['ave'], $x['ave_slug'], '/images/boxes/preview/' . $x['ave_slug'] . '.jpg', $x['descripcion'], $x['ubicacion'], $x['el_espacio'],
                json_encode($x['equipamiento'], JSON_UNESCAPED_UNICODE), $x['publico_objetivo'], json_encode($x['tarifas'], JSON_UNESCAPED_UNICODE), $x['orden'], $x['publico'], $x['activo']]);
        return ['ok' => true, 'id' => (int) db()->lastInsertId(), 'slug' => $slug];
    } catch (Throwable $e) {
        error_log('[boxes crear] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo crear el box.'];
    }
}

/** Precio «Por hora» de una lista de tarifas (formato tabla o catálogo). */
function boxPrecioHora(array $tarifas): int
{
    foreach ($tarifas as $t) {
        if (($t['label'] ?? '') === 'Por hora') return boxPrecioInt($t['precio'] ?? 0);
    }
    return 0;
}

/** Último cambio de precios de cada box: [box_id => fila]. */
function boxesUltimoCambioPrecio(): array
{
    $out = [];
    try {
        foreach (db()->query("SELECT p.* FROM boxes_precios p JOIN (SELECT box_id, MAX(id) id FROM boxes_precios GROUP BY box_id) u ON u.id = p.id") as $r) {
            $out[(int) $r['box_id']] = $r;
        }
    } catch (Throwable $e) {
    }
    return $out;
}

function actualizarBox(int $id, array $d): array
{
    $actual = getBoxFila($id);
    if (!$actual) return ['ok' => false, 'error' => 'Box no encontrado.'];
    $n = boxNormalizar($d + ['orden' => $actual['orden']]);
    if (!$n['ok']) return $n;
    $x = $n['data'];
    $tarAntes = array_map(fn($t) => ['label' => $t['label'], 'sub' => $t['sub'] ?? '', 'precio' => boxPrecioInt($t['precio']), 'gc' => boxPrecioInt($t['gastosComunes'] ?? 0)], $actual['tarifas']);
    $cambioPrecios = json_encode($tarAntes) !== json_encode($x['tarifas']);
    // Si cambia el ave y la imagen era la de la ilustración anterior, se usa la nueva.
    $imagen = $actual['imagen'];
    if ($x['ave_slug'] !== $actual['aveSlug'] && $imagen === '/images/boxes/preview/' . $actual['aveSlug'] . '.jpg') {
        $imagen = '/images/boxes/preview/' . $x['ave_slug'] . '.jpg';
    }
    try {
        db()->prepare("UPDATE boxes SET nombre=?, ave=?, ave_slug=?, imagen=?, descripcion=?, ubicacion=?, el_espacio=?, equipamiento=?, publico_objetivo=?, tarifas=?, orden=?, publico=?, activo=? WHERE id=?")
            ->execute([$x['nombre'], $x['ave'], $x['ave_slug'], $imagen, $x['descripcion'], $x['ubicacion'], $x['el_espacio'], json_encode($x['equipamiento'], JSON_UNESCAPED_UNICODE),
                $x['publico_objetivo'], json_encode($x['tarifas'], JSON_UNESCAPED_UNICODE), $x['orden'], $x['publico'], $x['activo'], $id]);
        $actualizadas = 0;
        if ($cambioPrecios) {
            // Los precios nuevos rigen desde la próxima factura: las horas de arriendo AÚN NO
            // facturadas pasan al nuevo precio por hora; lo ya facturado no se toca.
            $hAntes = boxPrecioHora($tarAntes);
            $hNuevo = boxPrecioHora($x['tarifas']);
            if ($hAntes > 0 && $hNuevo > 0 && $hAntes !== $hNuevo) {
                $up = db()->prepare("UPDATE reservas SET precio_clp = ? WHERE box_slug = ? AND origen = 'panel' AND paciente_id IS NULL AND factura_id IS NULL AND estado <> 'cancelada' AND precio_clp = ?");
                $up->execute([$hNuevo, $actual['slug'], $hAntes]);
                $actualizadas = $up->rowCount();
            }
            try {
                db()->prepare("INSERT INTO boxes_precios (box_id, slug, por_hora_antes, por_hora_despues, tarifas_antes, tarifas_despues, reservas_actualizadas, creado_en, usuario_nombre) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$id, $actual['slug'], $hAntes, $hNuevo, json_encode($tarAntes, JSON_UNESCAPED_UNICODE), json_encode($x['tarifas'], JSON_UNESCAPED_UNICODE), $actualizadas, date('Y-m-d H:i:s'),
                        isset($_SESSION['admin_nombre']) ? mb_substr((string) $_SESSION['admin_nombre'], 0, 160, 'UTF-8') : null]);
            } catch (Throwable $e) {
                error_log('[boxes precios] ' . $e->getMessage());
            }
        }
        return ['ok' => true, 'id' => $id, 'slug' => $actual['slug'], 'cambio_precios' => $cambioPrecios, 'horas_actualizadas' => $actualizadas];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo guardar el box.'];
    }
}

/** Visible en el sitio (publico) o disponible para reservar (activo). */
function boxSetFlag(int $id, string $campo, bool $valor): array
{
    if (!in_array($campo, ['publico', 'activo'], true)) return ['ok' => false, 'error' => 'Campo inválido.'];
    $b = getBoxFila($id);
    if (!$b) return ['ok' => false, 'error' => 'Box no encontrado.'];
    if ($campo === 'activo' && !$valor) {
        $f = boxHorasFuturas($b['slug']);
        if ($f > 0) return ['ok' => false, 'error' => 'No se puede pausar: tiene ' . $f . ' hora(s) agendadas desde hoy. Cancélalas o reagéndalas en otro box primero.'];
    }
    db()->prepare("UPDATE boxes SET $campo = ? WHERE id = ?")->execute([$valor ? 1 : 0, $id]);
    return ['ok' => true];
}

function boxHorasFuturas(string $slug): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM reservas WHERE box_slug = ? AND estado IN ('pendiente','confirmada') AND fecha >= ?");
    $st->execute([$slug, date('Y-m-d')]);
    return (int) $st->fetchColumn();
}

/**
 * Elimina un box. Con horas agendadas a futuro no se puede. Si tiene historial
 * (reservas pasadas), se ARCHIVA (sale del sitio y del calendario, el historial queda).
 */
function eliminarBox(int $id): array
{
    $b = getBoxFila($id);
    if (!$b) return ['ok' => false, 'error' => 'Box no encontrado.'];
    $f = boxHorasFuturas($b['slug']);
    if ($f > 0) return ['ok' => false, 'error' => $b['nombre'] . ' tiene ' . $f . ' hora(s) agendadas desde hoy. Cancélalas o reagéndalas en otro box antes de eliminarlo.'];
    $st = db()->prepare("SELECT COUNT(*) FROM reservas WHERE box_slug = ?");
    $st->execute([$b['slug']]);
    if ((int) $st->fetchColumn() > 0) {
        db()->prepare("UPDATE boxes SET eliminado_at = ?, activo = 0, publico = 0 WHERE id = ?")->execute([date('Y-m-d H:i:s'), $id]);
        return ['ok' => true, 'modo' => 'archivado'];
    }
    db()->prepare("DELETE FROM boxes WHERE id = ?")->execute([$id]);
    if (function_exists('borrarFotoBoxPrincipal')) borrarFotoBoxPrincipal($b['slug']);
    return ['ok' => true, 'modo' => 'eliminado'];
}

function restaurarBox(int $id): array
{
    db()->prepare("UPDATE boxes SET eliminado_at = NULL, activo = 1, publico = 0 WHERE id = ?")->execute([$id]);
    return ['ok' => true];
}

/** Horas por box: [slug => ['futuras' => n, 'mes' => n]]. */
function boxesUso(): array
{
    $out = [];
    try {
        $st = db()->prepare("SELECT box_slug, SUM(estado IN ('pendiente','confirmada') AND fecha >= ?) futuras, SUM(estado <> 'cancelada' AND fecha BETWEEN ? AND ?) mes FROM reservas GROUP BY box_slug");
        $st->execute([date('Y-m-d'), date('Y-m-01'), date('Y-m-t')]);
        foreach ($st->fetchAll() as $r) {
            $out[$r['box_slug']] = ['futuras' => (int) $r['futuras'], 'mes' => (int) $r['mes']];
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/* ------------------------------------------- Edición desde el sitio (modo edición) */

const BOX_CAMPOS_SITIO = ['nombre' => 'nombre', 'descripcion' => 'descripcion', 'publicoObjetivo' => 'publico_objetivo', 'ubicacion' => 'ubicacion', 'elEspacioDescripcion' => 'el_espacio'];

function boxActualizarCampoSitio(string $slug, string $campo, string $valor): array
{
    if (!isset(BOX_CAMPOS_SITIO[$campo])) return ['ok' => false, 'error' => 'Campo inválido.'];
    $col = BOX_CAMPOS_SITIO[$campo];
    $st = db()->prepare("UPDATE boxes SET $col = ? WHERE slug = ? AND eliminado_at IS NULL");
    $st->execute([mb_substr($valor, 0, $col === 'el_espacio' ? 1200 : 600, 'UTF-8'), $slug]);
    return $st->rowCount() >= 0 ? ['ok' => true] : ['ok' => false, 'error' => 'Box no encontrado.'];
}

function boxActualizarEquipamientoSitio(string $slug, int $idx, string $valor): array
{
    $st = db()->prepare("SELECT equipamiento FROM boxes WHERE slug = ? AND eliminado_at IS NULL");
    $st->execute([$slug]);
    $raw = $st->fetchColumn();
    if ($raw === false) return ['ok' => false, 'error' => 'Box no encontrado.'];
    $eq = json_decode((string) $raw, true) ?: [];
    if ($idx < 0 || $idx >= count($eq)) return ['ok' => false, 'error' => 'Ítem de equipamiento inválido'];
    $eq[$idx] = mb_substr($valor, 0, 120, 'UTF-8');
    db()->prepare("UPDATE boxes SET equipamiento = ? WHERE slug = ?")->execute([json_encode(array_values($eq), JSON_UNESCAPED_UNICODE), $slug]);
    return ['ok' => true];
}
