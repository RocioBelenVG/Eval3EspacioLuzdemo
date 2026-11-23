<?php
/**
 * Clientes — capa de datos.
 *
 * Un cliente es quien arrienda un espacio en los boxes. Guarda TODOS los datos
 * que exige una factura electrónica (RUT, razón social, giro, dirección y comuna)
 * para que facturarle sea elegir y confirmar.
 *
 * Reglas:
 *  - Las facturas son SOLO para clientes (nunca para pacientes) y las emite
 *    siempre la empresa autorizada (Espacio Luz, ver facturaEmpresa()).
 *  - Si el cliente está vinculado a un profesional, sus horas tomadas en el
 *    Calendario de Boxes se traen solas al facturar (clienteHorasBox()) y, al
 *    emitir, quedan marcadas como facturadas (reservas.factura_id) para no
 *    cobrarlas dos veces.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';
require_once APP_ROOT . '/lib/rut.php';
require_once APP_ROOT . '/lib/empresas.php';
require_once APP_ROOT . '/lib/panel.php'; // nombreProfesional(), nombreBox()

const CLIENTE_MEDIOS = ['transferencia' => 'Transferencia', 'efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'otro' => 'Otro'];
const CLIENTE_GIROS_SUGERIDOS = [
    'Servicios de fonoaudiología',
    'Servicios de psicología',
    'Servicios de terapia ocupacional',
    'Servicios de kinesiología',
    'Servicios de psicopedagogía',
    'Otras actividades de atención de la salud humana',
    'Servicios profesionales independientes',
];
/** Campos obligatorios para poder facturar: clave => etiqueta. */
const CLIENTE_OBLIGATORIOS = ['rut' => 'RUT', 'razon_social' => 'Razón social', 'giro' => 'Giro', 'direccion' => 'Dirección', 'comuna' => 'Comuna'];
const MESES_ES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/* ------------------------------------------------------------ Utilidades */

function clientePrecioInt(string $precio): int
{
    return (int) preg_replace('/\D/', '', $precio);
}

/** "2026-11" -> "noviembre 2026". */
function mesLargo(string $ym): string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
        return $ym;
    }
    return (MESES_ES[(int) $m[2]] ?? $m[2]) . ' ' . $m[1];
}

/**
 * Boxes del sitio con sus tarifas, listos para facturar.
 * [['slug','nombre','hora'=>precio por hora,'tarifas'=>[['key','label','sub','precio','gc'], ...]], ...]
 */
function boxesFacturables(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $boxes = function_exists('getContent') ? (getContent()['boxes'] ?? []) : [];
    foreach ($boxes as $b) {
        $tarifas = [];
        $hora = 0;
        foreach (($b['tarifas'] ?? []) as $t) {
            $p = clientePrecioInt((string) ($t['precio'] ?? ''));
            if ($p <= 0) {
                continue;
            }
            $label = trim((string) ($t['label'] ?? 'Tarifa'));
            $sub = trim((string) ($t['sub'] ?? ''));
            if ($hora === 0 && $label === 'Por hora') {
                $hora = $p;
            }
            $tarifas[] = [
                'key' => $label . ($sub !== '' ? ' · ' . $sub : ''),
                'label' => $label,
                'sub' => $sub,
                'precio' => $p,
                'gc' => clientePrecioInt((string) ($t['gastosComunes'] ?? '')),
            ];
        }
        $cache[] = ['slug' => (string) $b['slug'], 'nombre' => (string) $b['nombre'], 'hora' => $hora, 'tarifas' => $tarifas];
    }
    return $cache;
}

function boxFacturable(string $slug): ?array
{
    foreach (boxesFacturables() as $b) {
        if ($b['slug'] === $slug) {
            return $b;
        }
    }
    return null;
}

/**
 * Empresa que emite TODAS las facturas a clientes (Espacio Luz).
 * Orden: configuración `factura_empresa_id` -> empresa de tipo factura -> la que se llame «Espacio Luz».
 */
function facturaEmpresa(): ?array
{
    try {
        $st = db()->prepare("SELECT valor FROM configuracion WHERE clave = 'factura_empresa_id'");
        $st->execute();
        $id = (int) $st->fetchColumn();
        if ($id > 0 && ($e = getEmpresa($id))) {
            return $e;
        }
        $e = db()->query("SELECT * FROM empresas WHERE tipo_documento = 'factura' AND activo = 1 ORDER BY orden, id LIMIT 1")->fetch();
        if ($e) {
            return $e;
        }
        $e = db()->query("SELECT * FROM empresas WHERE razon_social LIKE '%Espacio Luz%' OR nombre_fantasia LIKE '%Espacio Luz%' ORDER BY activo DESC, id LIMIT 1")->fetch();
        return $e ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/* ------------------------------------------------------------- Clientes */

/** Valida y normaliza los datos del formulario. ['ok'=>true,'data'=>[...]] o ['ok'=>false,'error'=>..]. */
function clienteNormalizar(array $d): array
{
    $s = fn(string $k, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($d[$k] ?? ''))), 0, $max, 'UTF-8');
    $x = [
        'rut' => $s('rut', 20),
        'razon_social' => $s('razon_social', 200),
        'nombre_fantasia' => $s('nombre_fantasia', 200),
        'giro' => $s('giro', 200),
        'direccion' => $s('direccion', 300),
        'region' => $s('region', 80),
        'comuna' => $s('comuna', 120),
        'ciudad' => $s('ciudad', 120),
        'email' => mb_strtolower($s('email', 200), 'UTF-8'),
        'telefono' => $s('telefono', 60),
        'contacto' => $s('contacto', 160),
        'profesional_ids' => array_values(array_unique(array_filter(array_map('intval', array_merge((array) ($d['profesional_ids'] ?? []), [(int) ($d['profesional_id'] ?? 0)])), fn($i) => $i > 0))),
        'profesional_id' => null,
        'box_slug' => $s('box_slug', 60),
        'tarifa' => $s('tarifa', 200),
        'medio_pago' => array_key_exists((string) ($d['medio_pago'] ?? ''), CLIENTE_MEDIOS) ? (string) $d['medio_pago'] : 'transferencia',
        'notas' => mb_substr(trim((string) ($d['notas'] ?? '')), 0, 500, 'UTF-8'),
        'activo' => array_key_exists('activo', $d) ? (empty($d['activo']) ? 0 : 1) : 1,
    ];
    $faltan = [];
    foreach (CLIENTE_OBLIGATORIOS as $k => $lbl) {
        if ($x[$k] === '') {
            $faltan[] = $lbl;
        }
    }
    if ($faltan) {
        return ['ok' => false, 'error' => 'Para poder facturarle, completa: ' . implode(', ', $faltan) . '.'];
    }
    if (!rutValido($x['rut'])) {
        return ['ok' => false, 'error' => 'El RUT «' . $x['rut'] . '» no es válido (revisa el dígito verificador).'];
    }
    $x['rut'] = formatoRut($x['rut']);
    if ($x['email'] !== '' && !filter_var($x['email'], FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'El correo para facturas no es válido.'];
    }
    if ($x['box_slug'] !== '' && !boxFacturable($x['box_slug'])) {
        $x['box_slug'] = '';
    }
    if ($x['box_slug'] === '') {
        $x['tarifa'] = '';
    }
    if ($x['profesional_ids']) {
        $in = implode(',', array_fill(0, count($x['profesional_ids']), '?'));
        $st = db()->prepare("SELECT id FROM profesionales WHERE id IN ($in)");
        $st->execute($x['profesional_ids']);
        $x['profesional_ids'] = array_values(array_intersect($x['profesional_ids'], array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));
    }
    // Las socias (emiten boletas y facturas) no pagan arriendo: no se vinculan a clientes.
    $x['profesional_ids'] = array_values(array_filter($x['profesional_ids'], fn($i) => !esSocia((int) $i)));
    $x['profesional_id'] = $x['profesional_ids'][0] ?? null; // principal (compatibilidad)
    foreach (['nombre_fantasia', 'region', 'ciudad', 'email', 'telefono', 'contacto', 'box_slug', 'tarifa', 'notas'] as $k) {
        if ($x[$k] === '') {
            $x[$k] = null;
        }
    }
    return ['ok' => true, 'data' => $x];
}

/** Cliente con sus profesionales vinculados (prof_ids y profesionales [{id,nombre}]). */
function getCliente(int $id): ?array
{
    try {
        $st = db()->prepare(
            "SELECT c.*, p.nombre AS profesional_nombre, p.rut AS profesional_rut
               FROM clientes c LEFT JOIN profesionales p ON p.id = c.profesional_id
              WHERE c.id = ?"
        );
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) {
            return null;
        }
        $c['profesionales'] = clienteProfesionales($id);
        $c['prof_ids'] = array_map(fn($p) => (int) $p['id'], $c['profesionales']);
        $c['profesionales_nombres'] = implode(', ', array_column($c['profesionales'], 'nombre'));
        return $c;
    } catch (Throwable $e) {
        return null;
    }
}

/** Profesionales vinculados a un cliente: [['id','nombre'], ...]. */
function clienteProfesionales(int $clienteId): array
{
    try {
        $st = db()->prepare("SELECT p.id, p.nombre FROM clientes_profesionales cp JOIN profesionales p ON p.id = cp.profesional_id WHERE cp.cliente_id = ? ORDER BY p.nombre");
        $st->execute([$clienteId]);
        return array_map(fn($r) => ['id' => (int) $r['id'], 'nombre' => $r['nombre']], $st->fetchAll());
    } catch (Throwable $e) {
        return [];
    }
}

/** Guarda los profesionales vinculados de un cliente. */
function clienteGuardarProfesionales(int $clienteId, array $ids): void
{
    db()->prepare("DELETE FROM clientes_profesionales WHERE cliente_id = ?")->execute([$clienteId]);
    $ins = db()->prepare("INSERT IGNORE INTO clientes_profesionales (cliente_id, profesional_id) VALUES (?, ?)");
    foreach ($ids as $pid) {
        $ins->execute([$clienteId, (int) $pid]);
    }
}

/**
 * Lista de clientes (como la de pacientes): búsqueda por nombre, RUT, giro, correo,
 * teléfono, comuna o profesional. Por defecto solo activos. Incluye la cantidad de facturas.
 */
function clientesLista(string $q = '', bool $incluirInactivos = false): array
{
    try {
        $sql = "SELECT c.*, p.nombre AS profesional_nombre,
                       (SELECT GROUP_CONCAT(pp.nombre ORDER BY pp.nombre SEPARATOR ', ') FROM clientes_profesionales cp JOIN profesionales pp ON pp.id = cp.profesional_id WHERE cp.cliente_id = c.id) AS profesionales_nombres,
                       (SELECT GROUP_CONCAT(cp.profesional_id) FROM clientes_profesionales cp WHERE cp.cliente_id = c.id) AS prof_ids_csv,
                       (SELECT COUNT(*) FROM boletas b WHERE b.cliente_id = c.id) AS facturas_n
                  FROM clientes c
                  LEFT JOIN profesionales p ON p.id = c.profesional_id";
        $cond = [];
        $params = [];
        if (!$incluirInactivos) {
            $cond[] = "c.activo = 1";
        }
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $rutQ = preg_replace('/[^0-9kK]/', '', $q);
            $sub = "(c.razon_social LIKE ? OR c.nombre_fantasia LIKE ? OR c.giro LIKE ? OR c.email LIKE ? OR c.telefono LIKE ? OR c.comuna LIKE ? OR c.contacto LIKE ? OR EXISTS (SELECT 1 FROM clientes_profesionales cp JOIN profesionales pp ON pp.id = cp.profesional_id WHERE cp.cliente_id = c.id AND pp.nombre LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
            if (strlen($rutQ) >= 3) {
                $sub .= " OR REPLACE(REPLACE(c.rut, '.', ''), '-', '') LIKE ?";
                $params[] = '%' . $rutQ . '%';
            }
            $cond[] = $sub . ')';
        }
        if ($cond) {
            $sql .= ' WHERE ' . implode(' AND ', $cond);
        }
        $sql .= " ORDER BY c.razon_social";
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Etiquetas de los datos obligatorios que le faltan a un cliente (vacío = listo para facturar). */
function clienteFaltantes(array $c): array
{
    $f = [];
    foreach (CLIENTE_OBLIGATORIOS as $k => $lbl) {
        if (trim((string) ($c[$k] ?? '')) === '') {
            $f[] = $lbl;
        }
    }
    if (trim((string) ($c['rut'] ?? '')) !== '' && !rutValido((string) $c['rut'])) {
        $f[] = 'RUT válido';
    }
    return $f;
}

function clienteRutDuplicado(string $rut, int $exceptoId = 0): ?array
{
    $limpio = rutLimpio($rut);
    foreach (db()->query("SELECT id, rut, razon_social FROM clientes")->fetchAll() as $r) {
        if ((int) $r['id'] !== $exceptoId && rutLimpio((string) $r['rut']) === $limpio) {
            return $r;
        }
    }
    return null;
}

function crearCliente(array $d): array
{
    $n = clienteNormalizar($d);
    if (!$n['ok']) {
        return $n;
    }
    $x = $n['data'];
    if ($dup = clienteRutDuplicado($x['rut'])) {
        return ['ok' => false, 'error' => 'Ya existe un cliente con el RUT ' . $x['rut'] . ': ' . $dup['razon_social'] . '.'];
    }
    try {
        $st = db()->prepare(
            "INSERT INTO clientes (rut, razon_social, nombre_fantasia, giro, direccion, region, comuna, ciudad, email, telefono, contacto,
                                   profesional_id, box_slug, tarifa, medio_pago, notas, activo, creado_por)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $st->execute([
            $x['rut'], $x['razon_social'], $x['nombre_fantasia'], $x['giro'], $x['direccion'], $x['region'], $x['comuna'], $x['ciudad'],
            $x['email'], $x['telefono'], $x['contacto'], $x['profesional_id'], $x['box_slug'], $x['tarifa'], $x['medio_pago'], $x['notas'],
            $x['activo'], $_SESSION['admin_id'] ?? null,
        ]);
        $nuevo = (int) db()->lastInsertId();
        clienteGuardarProfesionales($nuevo, $x['profesional_ids']);
        return ['ok' => true, 'id' => $nuevo];
    } catch (Throwable $e) {
        error_log('[clientes crear] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo crear el cliente.'];
    }
}

function actualizarCliente(int $id, array $d): array
{
    if (!getCliente($id)) {
        return ['ok' => false, 'error' => 'Cliente no encontrado.'];
    }
    $n = clienteNormalizar($d);
    if (!$n['ok']) {
        return $n;
    }
    $x = $n['data'];
    if ($dup = clienteRutDuplicado($x['rut'], $id)) {
        return ['ok' => false, 'error' => 'Ya existe otro cliente con el RUT ' . $x['rut'] . ': ' . $dup['razon_social'] . '.'];
    }
    try {
        db()->prepare(
            "UPDATE clientes SET rut=?, razon_social=?, nombre_fantasia=?, giro=?, direccion=?, region=?, comuna=?, ciudad=?, email=?, telefono=?,
                    contacto=?, profesional_id=?, box_slug=?, tarifa=?, medio_pago=?, notas=?, activo=? WHERE id=?"
        )->execute([
            $x['rut'], $x['razon_social'], $x['nombre_fantasia'], $x['giro'], $x['direccion'], $x['region'], $x['comuna'], $x['ciudad'],
            $x['email'], $x['telefono'], $x['contacto'], $x['profesional_id'], $x['box_slug'], $x['tarifa'], $x['medio_pago'], $x['notas'],
            $x['activo'], $id,
        ]);
        clienteGuardarProfesionales($id, $x['profesional_ids']);
        return ['ok' => true, 'id' => $id];
    } catch (Throwable $e) {
        error_log('[clientes actualizar] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo guardar el cliente.'];
    }
}

function setClienteActivo(int $id, bool $activo): bool
{
    try {
        return db()->prepare("UPDATE clientes SET activo = ? WHERE id = ?")->execute([$activo ? 1 : 0, $id]);
    } catch (Throwable $e) {
        return false;
    }
}

/** Elimina un cliente solo si no tiene facturas; si las tiene, se desactiva (historial tributario intacto). */
function eliminarCliente(int $id): array
{
    if (!getCliente($id)) {
        return ['ok' => false, 'error' => 'Cliente no encontrado.'];
    }
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM boletas WHERE cliente_id = ?");
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            setClienteActivo($id, false);
            return ['ok' => true, 'modo' => 'desactivado'];
        }
        db()->prepare("DELETE FROM clientes WHERE id = ?")->execute([$id]);
        return ['ok' => true, 'modo' => 'eliminado'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo eliminar el cliente.'];
    }
}

/** Facturas emitidas a un cliente (más recientes primero), con un resumen del detalle. */
function facturasCliente(int $id): array
{
    try {
        $st = db()->prepare(
            "SELECT b.*, (SELECT GROUP_CONCAT(descripcion ORDER BY id SEPARATOR ' · ') FROM boleta_items WHERE boleta_id = b.id) AS detalle,
                    (SELECT COUNT(*) FROM reservas WHERE factura_id = b.id) AS horas_box
               FROM boletas b WHERE b.cliente_id = ? ORDER BY b.fecha_emision DESC, b.id DESC"
        );
        $st->execute([$id]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/* ------------------------------------------------- Horas de box (calendario) */

/**
 * Horas que los profesionales vinculados al cliente tomaron en los boxes durante un mes,
 * agrupadas por profesional, box y precio. Excluye las canceladas. Separa las ya facturadas
 * (por hora o dentro de un plan mensual).
 */
function clienteHorasBox(array $cli, string $mes): array
{
    $profIds = $cli['prof_ids'] ?? array_map(fn($p) => (int) $p['id'], clienteProfesionales((int) $cli['id']));
    if (!$profIds) {
        return ['ok' => false, 'error' => 'Este cliente no está vinculado a ningún profesional, así que no hay horas del calendario que traer. Vincúlalo en «Editar datos» o agrega el arriendo por tarifa.'];
    }
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
        $mes = date('Y-m');
    }
    $desde = $mes . '-01';
    $hasta = date('Y-m-t', strtotime($desde));
    $in = implode(',', array_fill(0, count($profIds), '?'));
    $st = db()->prepare(
        "SELECT r.id, r.box_slug, r.profesional_id, r.fecha, r.hora, r.estado, r.origen, r.precio_clp, r.factura_id, r.factura_plan, b.folio AS factura_folio
           FROM reservas r LEFT JOIN boletas b ON b.id = r.factura_id
          WHERE r.profesional_id IN ($in) AND r.fecha BETWEEN ? AND ? AND r.estado <> 'cancelada'
          ORDER BY r.fecha, r.hora, r.id"
    );
    $st->execute(array_merge($profIds, [$desde, $hasta]));
    $dias = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    $hoy = date('Y-m-d');
    $grupos = [];
    $facturadas = [];
    foreach ($st->fetchAll() as $r) {
        $box = boxFacturable((string) $r['box_slug']);
        $boxNombre = $box['nombre'] ?? ucwords(str_replace('-', ' ', (string) $r['box_slug']));
        $prof = nombreProfesional((int) $r['profesional_id']);
        if (!empty($r['factura_id'])) {
            $fk = (string) $r['box_slug'] . '|' . $r['factura_id'];
            if (!isset($facturadas[$fk])) {
                $facturadas[$fk] = ['box' => $boxNombre, 'factura_id' => (int) $r['factura_id'], 'folio' => (int) $r['factura_folio'], 'horas' => 0, 'plan' => !empty($r['factura_plan'])];
            }
            $facturadas[$fk]['horas']++;
            continue;
        }
        // Precio por hora VIGENTE del box al momento de facturar (los cambios de precio rigen
        // desde la próxima factura; lo ya facturado conserva su precio en el documento).
        $precio = (int) ($box['hora'] ?? 0) ?: (int) $r['precio_clp'];
        $k = (string) $r['box_slug'] . '|' . (int) $r['profesional_id'] . '|' . $precio;
        if (!isset($grupos[$k])) {
            $grupos[$k] = ['key' => $k, 'box_slug' => (string) $r['box_slug'], 'box' => $boxNombre, 'profesional_id' => (int) $r['profesional_id'], 'profesional' => $prof,
                'precio' => $precio, 'horas' => 0, 'ids' => [], 'futuras' => [], 'estados' => [], 'fechas' => []];
        }
        $g = &$grupos[$k];
        $g['horas']++;
        $g['ids'][] = (int) $r['id'];
        if ((string) $r['fecha'] > $hoy) {
            $g['futuras'][] = (int) $r['id']; // aún no ocurre (hora de Chile)
        }
        $g['estados'][$r['estado']] = ($g['estados'][$r['estado']] ?? 0) + 1;
        $ts = strtotime((string) $r['fecha']);
        $g['fechas'][] = $dias[(int) date('w', $ts)] . ' ' . date('j', $ts) . ($r['hora'] ? ' · ' . substr((string) $r['hora'], 0, 5) : '');
        unset($g);
    }
    usort($grupos, fn($a, $b) => strcmp($a['profesional'], $b['profesional']) ?: strcmp($a['box'], $b['box']) ?: $a['precio'] <=> $b['precio']);
    return [
        'ok' => true, 'mes' => $mes, 'mes_label' => mesLargo($mes), 'hoy' => $hoy, 'hay_futuras' => $hasta > $hoy,
        'profesional' => (string) ($cli['profesionales_nombres'] ?? $cli['profesional_nombre'] ?? ''),
        'grupos' => array_values($grupos), 'facturadas' => array_values($facturadas),
    ];
}

/** Horas de box aún sin facturar (hasta hoy) por cliente: [cliente_id => horas]. */
function horasSinFacturarPorCliente(): array
{
    try {
        $out = [];
        // «Hoy» en hora de Chile (la base de datos está en UTC).
        $st = db()->prepare(
            "SELECT cp.cliente_id, COUNT(*) n FROM reservas r JOIN clientes_profesionales cp ON cp.profesional_id = r.profesional_id
              WHERE r.factura_id IS NULL AND r.estado <> 'cancelada' AND r.fecha <= ?
              GROUP BY cp.cliente_id"
        );
        $st->execute([date('Y-m-d')]);
        foreach ($st as $r) {
            $out[(int) $r['cliente_id']] = (int) $r['n'];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** Compatibilidad: horas sin facturar por profesional. */
function horasSinFacturarPorProfesional(): array
{
    try {
        $out = [];
        $st = db()->prepare("SELECT profesional_id, COUNT(*) n FROM reservas WHERE profesional_id IS NOT NULL AND factura_id IS NULL AND estado <> 'cancelada' AND fecha <= ? GROUP BY profesional_id");
        $st->execute([date('Y-m-d')]);
        foreach ($st as $r) {
            $out[(int) $r['profesional_id']] = (int) $r['n'];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** Resumen por mes de las horas sin facturar (hasta hoy) de uno o varios profesionales. */
function horasSinFacturarPorMes($profIds): array
{
    $profIds = array_values(array_filter(array_map('intval', (array) $profIds), fn($i) => $i > 0));
    if (!$profIds) {
        return [];
    }
    try {
        $in = implode(',', array_fill(0, count($profIds), '?'));
        $st = db()->prepare(
            "SELECT DATE_FORMAT(fecha, '%Y-%m') mes, COUNT(*) n FROM reservas
              WHERE profesional_id IN ($in) AND factura_id IS NULL AND estado <> 'cancelada' AND fecha <= ?
              GROUP BY mes ORDER BY mes DESC LIMIT 12"
        );
        $st->execute(array_merge($profIds, [date('Y-m-d')]));
        return array_map(fn($r) => ['mes' => $r['mes'], 'mes_label' => mesLargo($r['mes']), 'horas' => (int) $r['n']], $st->fetchAll());
    } catch (Throwable $e) {
        return [];
    }
}

/* ------------------------------------------------------------ Facturación */

/**
 * Emite una factura a un cliente. El receptor SIEMPRE sale de la ficha del cliente
 * y el emisor es SIEMPRE la empresa autorizada (facturaEmpresa()).
 * $body: cliente_id, items[{descripcion,cantidad,precio_unit(neto),afecto,reserva_ids[]}], medio_pago, glosa, receptor_email.
 * Si la empresa tiene certificado + CAF de factura, va al SII; si no, queda como documento interno.
 */
function emitirFacturaCliente(array $body): array
{
    require_once APP_ROOT . '/lib/boletas.php';
    require_once APP_ROOT . '/lib/dte_integra.php';

    $cli = getCliente((int) ($body['cliente_id'] ?? 0));
    if (!$cli) {
        return ['ok' => false, 'error' => 'Selecciona el cliente.'];
    }
    if ((int) $cli['activo'] !== 1) {
        return ['ok' => false, 'error' => 'Este cliente está desactivado. Actívalo para facturarle.'];
    }
    if ($falt = clienteFaltantes($cli)) {
        return ['ok' => false, 'error' => 'Faltan datos del cliente para facturar: ' . implode(', ', $falt) . '. Complétalos en su ficha.'];
    }
    $emp = facturaEmpresa();
    if (!$emp) {
        return ['ok' => false, 'error' => 'No está configurada la empresa que emite las facturas (Espacio Luz).'];
    }
    if ((int) $emp['activo'] !== 1) {
        return ['ok' => false, 'error' => 'La empresa que emite las facturas (' . ($emp['nombre_fantasia'] ?: $emp['razon_social']) . ') está desactivada.'];
    }

    $itemsIn = is_array($body['items'] ?? null) ? $body['items'] : [];
    // Empresa certificada solo para factura exenta (34): todos los ítems van exentos (sin IVA).
    $soloExenta = dteFacturaSoloExenta($emp);
    if ($soloExenta) {
        foreach ($itemsIn as &$itx) {
            if (is_array($itx)) $itx['afecto'] = 0;
        }
        unset($itx);
    }
    $norm = dteNormalizarItems($itemsIn);
    if (!$norm['tiene']) {
        return ['ok' => false, 'error' => 'Agrega al menos un ítem con descripción y precio.'];
    }

    // Horas del calendario que se cobran en esta factura (se validan contra el profesional del cliente).
    $resIds = [];
    foreach ($itemsIn as $it) {
        foreach ((array) ($it['reserva_ids'] ?? []) as $rid) {
            if ((int) $rid > 0) {
                $resIds[(int) $rid] = true;
            }
        }
    }
    $resIds = array_keys($resIds);
    $profIds = $cli['prof_ids'] ?? [];
    if ($resIds) {
        if (!$profIds) {
            return ['ok' => false, 'error' => 'Las horas del calendario solo se pueden facturar a un cliente vinculado a sus profesionales.'];
        }
        $in = implode(',', array_fill(0, count($resIds), '?'));
        $inP = implode(',', array_fill(0, count($profIds), '?'));
        $st = db()->prepare("SELECT COUNT(*) FROM reservas WHERE id IN ($in) AND profesional_id IN ($inP) AND estado <> 'cancelada' AND factura_id IS NULL");
        $st->execute(array_merge($resIds, $profIds));
        if ((int) $st->fetchColumn() !== count($resIds)) {
            return ['ok' => false, 'error' => 'Algunas horas del calendario ya fueron facturadas, se cancelaron o cambiaron de profesional. Vuelve a cargar las horas del período.'];
        }
    }
    // Pago: al contado (pagada al emitir) o por cobrar con vencimiento.
    $pagada = ($body['pago'] ?? '') === 'pagada';
    $vence = (string) ($body['vence_en'] ?? '');
    if (!$pagada && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vence)) {
        $vence = date('Y-m-d', strtotime('+15 days'));
    }

    $tipoInt = $norm['todo_exento'] ? 34 : 33;
    if ($tipoInt === 33 && !dteEmpresaCertificada($emp, 33)) {
        return ['ok' => false, 'error' => 'La empresa emisora no está certificada para factura afecta (33): emite la factura con ítems exentos.'];
    }
    $mailForm = trim((string) ($body['receptor_email'] ?? ''));
    $payload = [
        'cliente_id'         => (int) $cli['id'],
        'receptor_tipo'      => 'cliente',
        'receptor_nombre'    => (string) $cli['razon_social'],
        'receptor_rut'       => formatoRut((string) $cli['rut']),
        'receptor_giro'      => (string) $cli['giro'],
        'receptor_email'     => filter_var($mailForm, FILTER_VALIDATE_EMAIL) ? $mailForm : (string) ($cli['email'] ?? ''),
        'receptor_direccion' => (string) $cli['direccion'],
        'receptor_comuna'    => (string) $cli['comuna'],
        'receptor_ciudad'    => (string) ($cli['ciudad'] ?? ''),
        'medio_pago'         => array_key_exists((string) ($body['medio_pago'] ?? ''), CLIENTE_MEDIOS) ? (string) $body['medio_pago'] : (string) $cli['medio_pago'],
        'glosa'              => mb_substr(trim((string) ($body['glosa'] ?? '')), 0, 300, 'UTF-8'),
        'items'              => $itemsIn,
    ];

    $sii = false;
    $extra = [];
    $chequeo = dteChequeoEmision($emp, $tipoInt);
    if ($chequeo['credenciales'] && !$chequeo['listo']) {
        return ['ok' => false, 'error' => dteMensajeFaltan($chequeo, 'la factura')];
    }
    if ($chequeo['listo']) {
        // DTE real al SII (certificado + CAF de factura cargados).
        @set_time_limit(900);
        try {
            $r = dteEmitirReal($emp, $tipoInt, $norm['items'], $payload);
        } catch (Throwable $e) {
            error_log('[facturas.dte] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Error al emitir la factura electrónica: ' . $e->getMessage()];
        }
        if (!($r['ok'] ?? false)) {
            return ['ok' => false, 'error' => $r['error'] ?? 'El SII rechazó la factura.', 'sii' => true, 'glosa' => $r['glosa'] ?? null];
        }
        try {
            $id = dteRegistrarBoleta($emp, $payload, $norm['items'], $r, $tipoInt);
        } catch (Throwable $e) {
            error_log('[facturas.dte registro] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'La factura se emitió en el SII (folio ' . $r['folio'] . ', TrackID ' . $r['trackid'] . ') pero falló el registro local. Anota estos datos.'];
        }
        boletaGuardarExtras((int) $id, $payload);
        $folio = (int) $r['folio'];
        $sii = true;
        $extra = ['estado' => $r['estado'] ?? '', 'trackid' => $r['trackid'] ?? '', 'sii_estado' => !empty($r['pendiente']) ? 'pendiente' : 'aceptada', 'total' => (int) ($r['total'] ?? 0)];
    } else {
        // Sin certificado/CAF de factura: documento interno (pendiente de timbrar).
        $res = crearBoleta(array_merge($payload, ['empresa_id' => (int) $emp['id'], 'tipo_doc' => 'factura']));
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error']];
        }
        $id = (int) $res['id'];
        $folio = (int) $res['folio'];
    }

    try {
        db()->prepare("UPDATE boletas SET cliente_id = ?, receptor_giro = ?, receptor_tipo = 'cliente' WHERE id = ?")
            ->execute([(int) $cli['id'], mb_substr((string) $cli['giro'], 0, 200, 'UTF-8'), $id]);
        require_once APP_ROOT . '/lib/cobros.php';
        $enPlan = 0;
        foreach ($itemsIn as $it) {
            $netoU = max(0, (int) preg_replace('/\D/', '', (string) ($it['precio_unit'] ?? 0)));
            $ids = array_filter(array_map('intval', (array) ($it['reserva_ids'] ?? [])));
            if ($ids) {
                cobroVincular($id, 'factura', $ids, $netoU, !empty($it['afecto']));
            }
            // Plan mensual: las horas de sus profesionales en ese box y mes quedan incluidas (sin cobro aparte).
            $plan = is_array($it['plan'] ?? null) ? $it['plan'] : null;
            if ($plan && $profIds && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($plan['mes'] ?? '')) && boxFacturable((string) ($plan['box_slug'] ?? ''))) {
                $d0 = $plan['mes'] . '-01';
                $inP = implode(',', array_fill(0, count($profIds), '?'));
                $q = db()->prepare("SELECT id FROM reservas WHERE profesional_id IN ($inP) AND box_slug = ? AND fecha BETWEEN ? AND ? AND estado <> 'cancelada' AND factura_id IS NULL");
                $q->execute(array_merge($profIds, [(string) $plan['box_slug'], $d0, date('Y-m-t', strtotime($d0))]));
                $enPlan += cobroVincular($id, 'factura', $q->fetchAll(PDO::FETCH_COLUMN), 0, !empty($it['afecto']), true);
            }
        }
        cobroTrasEmitir($id, $pagada, (string) $payload['medio_pago'], $pagada ? null : $vence);
    } catch (Throwable $e) {
        error_log('[facturas enlace] ' . $e->getMessage());
    }

    return array_merge(['ok' => true, 'id' => $id, 'folio' => $folio, 'tipo_dte' => $tipoInt, 'sii' => $sii, 'interno' => !$sii, 'horas' => count($resIds), 'horas_plan' => $enPlan ?? 0, 'pagada' => $pagada, 'vence_en' => $pagada ? null : $vence], $extra);
}

/* ------------------------------------------------------------- Ficha */

/** Resumen de la ficha (sin montos: los montos los lleva el módulo Boletas y Facturas). */
function clienteResumen(array $cli): array
{
    $out = ['horas_mes' => 0, 'por_facturar' => 0, 'facturas' => 0, 'por_cobrar' => 0, 'proxima' => null];
    try {
        $st = db()->prepare("SELECT COUNT(*), SUM(estado = 'emitida' AND pago_estado <> 'pagado') FROM boletas WHERE cliente_id = ?");
        $st->execute([(int) $cli['id']]);
        [$n, $pc] = $st->fetch(PDO::FETCH_NUM);
        $out['facturas'] = (int) $n;
        $out['por_cobrar'] = (int) $pc;
        $profIds = $cli['prof_ids'] ?? [];
        if ($profIds) {
            $hoy = date('Y-m-d');
            $in = implode(',', array_fill(0, count($profIds), '?'));
            $st = db()->prepare(
                "SELECT SUM(fecha BETWEEN ? AND ?) AS mes, SUM(factura_id IS NULL AND fecha <= ?) AS pend
                   FROM reservas WHERE profesional_id IN ($in) AND estado <> 'cancelada'"
            );
            $st->execute(array_merge([date('Y-m-01'), date('Y-m-t'), $hoy], $profIds));
            $r = $st->fetch();
            $out['horas_mes'] = (int) ($r['mes'] ?? 0);
            $out['por_facturar'] = (int) ($r['pend'] ?? 0);
            $st = db()->prepare(
                "SELECT CONCAT(fecha, ' ', COALESCE(hora, '00:00:00')) FROM reservas
                  WHERE profesional_id IN ($in) AND estado IN ('pendiente','confirmada')
                    AND (fecha > ? OR (fecha = ? AND (hora IS NULL OR hora >= ?)))
                  ORDER BY fecha, hora LIMIT 1"
            );
            $st->execute(array_merge($profIds, [$hoy, $hoy, date('H:i:s')]));
            $out['proxima'] = $st->fetchColumn() ?: null;
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** Historial de horas en boxes de los profesionales vinculados (más recientes primero), con su estado de cobro. */
function clienteReservas(array $cli, int $limite = 60): array
{
    $profIds = $cli['prof_ids'] ?? [];
    if (!$profIds) {
        return [];
    }
    try {
        require_once APP_ROOT . '/lib/cobros.php';
        $in = implode(',', array_fill(0, count($profIds), '?'));
        $st = db()->prepare("SELECT id FROM reservas WHERE profesional_id IN ($in) ORDER BY fecha DESC, hora DESC LIMIT " . max(1, $limite));
        $st->execute($profIds);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $filas = reservasCobro($ids);
        usort($filas, fn($a, $b) => strcmp($b['fecha'] . $b['hora'], $a['fecha'] . $a['hora']));
        foreach ($filas as &$f) {
            $f['hhmm'] = $f['hora'] ? substr((string) $f['hora'], 0, 5) : '';
            $f['cobro'] = cobroEstadoReserva($f);
            $f['profesional'] = nombreProfesional((int) $f['profesional_id']);
        }
        return $filas;
    } catch (Throwable $e) {
        return [];
    }
}

/* ------------------------------------------------------------- Notas (como las de pacientes) */

function notaFormatoCliente(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'cliente_id' => (int) $r['cliente_id'],
        'texto' => (string) $r['texto'],
        'completada' => (int) $r['completada'],
        'completada_en' => $r['completada_en'],
        'autor' => (string) ($r['creado_por_nombre'] ?? ''),
        'creado_en' => $r['creado_en'],
        'editado_en' => $r['editado_en'],
    ];
}

function notasCliente(int $clienteId): array
{
    try {
        $st = db()->prepare('SELECT * FROM clientes_notas WHERE cliente_id = ? ORDER BY completada ASC, creado_en DESC, id DESC');
        $st->execute([$clienteId]);
        return array_map('notaFormatoCliente', $st->fetchAll());
    } catch (Throwable $e) {
        return [];
    }
}

function notaClienteGet(int $id): ?array
{
    try {
        $st = db()->prepare('SELECT * FROM clientes_notas WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ? notaFormatoCliente($r) : null;
    } catch (Throwable $e) {
        return null;
    }
}

function crearNotaCliente(int $clienteId, string $texto): array
{
    require_once APP_ROOT . '/lib/pacientes.php'; // notaTexto()
    if ($clienteId <= 0 || !getCliente($clienteId)) {
        return ['ok' => false, 'error' => 'Cliente no encontrado.'];
    }
    if (($t = notaTexto($texto, $err)) === null) {
        return ['ok' => false, 'error' => $err];
    }
    try {
        db()->prepare('INSERT INTO clientes_notas (cliente_id, texto, creado_en, creado_por, creado_por_nombre) VALUES (?, ?, ?, ?, ?)')
            ->execute([$clienteId, $t, date('Y-m-d H:i:s'), ($_SESSION['admin_id'] ?? null) ?: null, isset($_SESSION['admin_nombre']) ? mb_substr((string) $_SESSION['admin_nombre'], 0, 120, 'UTF-8') : null]);
        return ['ok' => true, 'nota' => notaClienteGet((int) db()->lastInsertId())];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo guardar la nota.'];
    }
}

function actualizarNotaCliente(int $id, string $texto, bool $borrador = false): array
{
    require_once APP_ROOT . '/lib/pacientes.php';
    $n = notaClienteGet($id);
    if (!$n) {
        return ['ok' => false, 'error' => 'La nota ya no existe (puede haber sido eliminada).'];
    }
    if (($t = notaTexto($texto, $err)) === null) {
        return ['ok' => false, 'error' => $err];
    }
    if ($t === $n['texto']) {
        return ['ok' => true, 'nota' => $n];
    }
    try {
        // Borrador recién creado (menos de 30 min): no cuenta como «editada».
        $ahora = date('Y-m-d H:i:s');
        db()->prepare('UPDATE clientes_notas SET texto = ?, editado_en = IF(? = 1 AND creado_en > ? - INTERVAL 30 MINUTE, editado_en, ?) WHERE id = ?')
            ->execute([$t, $borrador ? 1 : 0, $ahora, $ahora, $id]);
        return ['ok' => true, 'nota' => notaClienteGet($id)];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo guardar la nota.'];
    }
}

function marcarNotaCliente(int $id, bool $completada): array
{
    if (!notaClienteGet($id)) {
        return ['ok' => false, 'error' => 'La nota ya no existe (puede haber sido eliminada).'];
    }
    try {
        db()->prepare('UPDATE clientes_notas SET completada = ?, completada_en = ? WHERE id = ?')
            ->execute([$completada ? 1 : 0, $completada ? date('Y-m-d H:i:s') : null, $id]);
        return ['ok' => true, 'nota' => notaClienteGet($id)];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo actualizar la nota.'];
    }
}

function eliminarNotaCliente(int $id): array
{
    try {
        $st = db()->prepare('DELETE FROM clientes_notas WHERE id = ?');
        $st->execute([$id]);
        return $st->rowCount() > 0 ? ['ok' => true] : ['ok' => false, 'error' => 'La nota ya no existe.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo eliminar la nota.'];
    }
}
