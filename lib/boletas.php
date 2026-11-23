<?php
/**
 * Boletas (comprobantes de venta) — capa de datos.
 * MVP: se registran y se generan como documento imprimible/PDF y por correo.
 * Todavía NO se timbran en el SII (sin folio/CAF/TED reales): el documento se
 * marca "pendiente de timbrado SII" hasta conectar la emisión electrónica.
 * Cada boleta emitida cuenta como ingreso (dashboard).
 */

declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';
require_once APP_ROOT . '/lib/panel.php'; // clp()
require_once APP_ROOT . '/lib/empresas.php';

const IVA_TASA = 0.19;

/** Datos del emisor (centro) — fallback si un documento no tiene empresa. */
function emisorBoleta(): array
{
    return [
        'nombre'    => getenv('ELUZ_EMISOR_NOMBRE') ?: 'Espacio Luz',
        'rut'       => getenv('ELUZ_EMISOR_RUT') ?: '76.000.000-0',
        'giro'      => getenv('ELUZ_EMISOR_GIRO') ?: 'Centro de terapias y arriendo de espacios',
        'direccion' => getenv('ELUZ_EMISOR_DIR') ?: 'Av. Gabriela Mistral 1172, Villa Cataluña',
        'comuna'    => getenv('ELUZ_EMISOR_COMUNA') ?: 'Los Ángeles',
        'email'     => getenv('ELUZ_EMISOR_EMAIL') ?: 'contacto@espacioluz.cl',
        'rut_configurado' => (bool) getenv('ELUZ_EMISOR_RUT'),
        'tipo_documento' => 'boleta',
    ];
}

/** Datos del emisor a partir del registro de la empresa (o fallback). */
function emisorDeBoleta(array $b): array
{
    $emp = ($b['empresa_id'] ?? null) ? getEmpresa((int) $b['empresa_id']) : null;
    if (!$emp) {
        $f = emisorBoleta();
        $f['tipo_documento'] = $b['tipo'] ?? 'boleta';
        return $f;
    }
    return [
        'nombre'    => $emp['nombre_fantasia'] ?: $emp['razon_social'],
        'razon_social' => $emp['razon_social'],
        'rut'       => $emp['rut'] ?: '—',
        'giro'      => $emp['giro'] ?: '—',
        'direccion' => $emp['direccion'] ?: '—',
        'comuna'    => $emp['comuna'] ?: '',
        'email'     => $emp['email'] ?: '',
        'actividad_economica' => $emp['actividad_economica'] ?? '',
        'rut_configurado' => !empty($emp['rut']),
        'tipo_documento' => $emp['tipo_documento'],
    ];
}

/** Formatea un RUT chileno: "190361233" / "19036123-3" -> "19.036.123-3". Si no parece RUT, lo deja igual. */
if (!function_exists('formatoRut')) {
    function formatoRut(?string $rut): string
    {
        $rut = trim((string) $rut);
        $limpio = strtoupper(preg_replace('/[^0-9kK]/', '', $rut));
        if (strlen($limpio) < 2 || !preg_match('/^\d+[0-9K]$/', $limpio)) {
            return $rut;
        }
        $cuerpo = substr($limpio, 0, -1);
        $dv = substr($limpio, -1);
        return number_format((int) $cuerpo, 0, ',', '.') . '-' . $dv;
    }
}

/** Etiqueta legible del medio de pago. */
function medioPagoLabel(string $m): string
{
    return [
        'efectivo' => 'Efectivo',
        'tarjeta' => 'Tarjeta',
        'transferencia' => 'Transferencia',
        'otro' => 'Otro',
    ][$m] ?? ucfirst($m);
}

/**
 * Datos del receptor (dirección, comuna, cód. prestación) y del profesional que
 * atendió, normalizados para guardarlos en el documento.
 * - Dirección/comuna/código: lo escrito en el formulario manda; si va vacío, la ficha del paciente.
 * - Profesional: el nombre sale de su ficha; el RUT escrito manda y, si va vacío, el de la ficha.
 */
function boletaDatosExtra(array $d): array
{
    $profId = (int) ($d['profesional_id'] ?? 0) ?: null;
    $profNombre = trim((string) ($d['profesional_nombre'] ?? ''));
    $profRut = trim((string) ($d['profesional_rut'] ?? ''));
    if ($profId !== null) {
        try {
            $ps = db()->prepare("SELECT nombre, rut FROM profesionales WHERE id = ?");
            $ps->execute([$profId]);
            if ($p = $ps->fetch()) {
                $profNombre = (string) $p['nombre'];
                if ($profRut === '') {
                    $profRut = (string) ($p['rut'] ?? '');
                }
            } else {
                $profId = null;
            }
        } catch (Throwable $e) {
        }
    }

    $cliDir = trim((string) ($d['receptor_direccion'] ?? ''));
    $cliComuna = trim((string) ($d['receptor_comuna'] ?? ''));
    $cliCod = trim((string) ($d['receptor_codigo_prestacion'] ?? ''));
    $cliIdNum = ($d['paciente_id'] ?? '') !== '' ? (int) $d['paciente_id'] : 0;
    if ($cliIdNum > 0 && ($cliDir === '' || $cliComuna === '' || $cliCod === '')) {
        require_once APP_ROOT . '/lib/pacientes.php';
        $ficha = getPaciente($cliIdNum);
        if ($ficha) {
            if ($cliDir === '') $cliDir = trim((string) ($ficha['direccion'] ?? ''));
            if ($cliComuna === '') $cliComuna = trim((string) ($ficha['comuna'] ?? ''));
            if ($cliCod === '') $cliCod = trim((string) ($ficha['codigo_prestacion'] ?? ''));
        }
    }

    return [
        'profesional_id' => $profId,
        'profesional_nombre' => $profNombre !== '' ? mb_substr($profNombre, 0, 160, 'UTF-8') : null,
        'profesional_rut' => $profRut !== '' ? mb_substr(formatoRut($profRut), 0, 30, 'UTF-8') : null,
        'receptor_direccion' => $cliDir !== '' ? mb_substr($cliDir, 0, 300, 'UTF-8') : null,
        'receptor_comuna' => $cliComuna !== '' ? mb_substr($cliComuna, 0, 120, 'UTF-8') : null,
        'receptor_codigo_prestacion' => $cliCod !== '' ? mb_substr($cliCod, 0, 40, 'UTF-8') : null,
        // A quién se cobró: 'paciente' (mayor de edad), 'tutor' (paciente menor de edad) o 'cliente' (factura).
        'receptor_tipo' => in_array($d['receptor_tipo'] ?? '', ['paciente', 'tutor', 'cliente'], true) ? $d['receptor_tipo'] : null,
    ];
}

/** Si el profesional aún no tiene RUT en su ficha, guarda el usado en el documento. */
function boletaRutAFicha(?int $profId, ?string $profRut): void
{
    if ($profId === null || $profRut === null || $profRut === '') {
        return;
    }
    // Solo se guarda en la ficha si el RUT es válido (dígito verificador correcto).
    require_once APP_ROOT . '/lib/rut.php';
    if (!rutValido($profRut)) {
        return;
    }
    try {
        db()->prepare("UPDATE profesionales SET rut = ? WHERE id = ? AND (rut IS NULL OR rut = '')")->execute([$profRut, $profId]);
    } catch (Throwable $e) {
    }
}

/**
 * Guarda en un documento ya registrado (p. ej. un DTE timbrado por el SII) la
 * dirección/código del paciente y el profesional que atendió.
 */
function boletaGuardarExtras(int $boletaId, array $d): void
{
    $x = boletaDatosExtra($d);
    try {
        db()->prepare(
            "UPDATE boletas SET receptor_direccion=?, receptor_comuna=?, receptor_codigo_prestacion=?, receptor_tipo=?,
                    profesional_id=?, profesional_nombre=?, profesional_rut=? WHERE id=?"
        )->execute([
            $x['receptor_direccion'], $x['receptor_comuna'], $x['receptor_codigo_prestacion'], $x['receptor_tipo'],
            $x['profesional_id'], $x['profesional_nombre'], $x['profesional_rut'], $boletaId,
        ]);
    } catch (Throwable $e) {
        error_log('[boletas extras] ' . $e->getMessage());
    }
    boletaRutAFicha($x['profesional_id'], $x['profesional_rut']);
}

/**
 * Guarda los ítems de un documento. Si el ítem viene de un producto, queda también la profesional
 * dueña del producto (Fernanda o Javiera), resuelta en el servidor desde el producto.
 */
function boletaInsertarItems(PDO $pdo, int $boletaId, array $items): void
{
    require_once APP_ROOT . '/lib/productos.php';
    $si = $pdo->prepare("INSERT INTO boleta_items (boleta_id, descripcion, cantidad, precio_unit_clp, afecto, total_clp, producto_id, profesional_id) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($items as $it) {
        $pid = (int) ($it['producto_id'] ?? 0) ?: null;
        $si->execute([$boletaId, $it['descripcion'], $it['cantidad'], $it['precio_unit'], $it['afecto'], $it['total'], $pid, $pid ? productoProfesionalId($pid) : null]);
    }
}

/**
 * Crea una boleta con sus ítems dentro de una transacción.
 * $d: receptor_nombre, receptor_rut?, receptor_email?, medio_pago, glosa?,
 *     items => [ ['descripcion','cantidad','precio_unit','afecto'(bool)], ... ]
 * Devuelve ['ok'=>true,'id'=>int,'folio'=>int] o ['ok'=>false,'error'=>..].
 */
function crearBoleta(array $d): array
{
    // Empresa emisora (define el tipo de documento y el folio correlativo propio).
    $empresaId = (int) ($d['empresa_id'] ?? 0);
    $empresa = $empresaId > 0 ? getEmpresa($empresaId) : null;
    if (!$empresa) {
        return ['ok' => false, 'error' => 'Debes elegir la empresa emisora.'];
    }
    if ((int) $empresa['activo'] !== 1) {
        return ['ok' => false, 'error' => 'La empresa seleccionada está desactivada.'];
    }
    // Tipo: lo fija quien llama ('boleta' = Pacientes, 'factura' = Clientes); por defecto, el de la empresa.
    $tipoPedido = (string) ($d['tipo_doc'] ?? '');
    $tipo = in_array($tipoPedido, ['boleta', 'factura'], true) ? $tipoPedido : ($empresa['tipo_documento'] === 'factura' ? 'factura' : 'boleta');
    $docLabel = tipoDocLabel($tipo);

    $items = array_values(array_filter($d['items'] ?? [], function ($it) {
        return trim((string) ($it['descripcion'] ?? '')) !== '' && (int) ($it['precio_unit'] ?? 0) > 0;
    }));
    if (!$items) {
        return ['ok' => false, 'error' => 'Agrega al menos un ítem con descripción y precio.'];
    }
    if (trim((string) ($d['receptor_nombre'] ?? '')) === '') {
        return ['ok' => false, 'error' => $tipo === 'factura' ? 'Selecciona el cliente.' : 'Selecciona el paciente.'];
    }
    // La factura exige RUT del receptor (cliente).
    if ($tipo === 'factura' && trim((string) ($d['receptor_rut'] ?? '')) === '') {
        return ['ok' => false, 'error' => 'La factura requiere el RUT del cliente. Complétalo en su ficha.'];
    }

    $afectoTotal = 0;
    $exentoTotal = 0;
    $normItems = [];
    foreach ($items as $it) {
        $cant = max(1, (int) ($it['cantidad'] ?? 1));
        $pu = max(0, (int) preg_replace('/\D/', '', (string) ($it['precio_unit'] ?? 0)));
        $afecto = !empty($it['afecto']);
        $tot = $cant * $pu;
        if ($afecto) {
            $afectoTotal += $tot;
        } else {
            $exentoTotal += $tot;
        }
        $normItems[] = [
            'descripcion' => mb_substr(trim((string) $it['descripcion']), 0, 300, 'UTF-8'),
            'cantidad' => $cant,
            'precio_unit' => $pu,
            'afecto' => $afecto ? 1 : 0,
            'total' => $tot,
            'producto_id' => (int) ($it['producto_id'] ?? 0) ?: null,
        ];
    }

    // Los precios ingresados son NETOS; el IVA (19%) se agrega por encima en los afectos.
    $neto = $afectoTotal;
    $iva = (int) round($afectoTotal * IVA_TASA);
    $total = $afectoTotal + $iva + $exentoTotal;

    $medio = in_array($d['medio_pago'] ?? '', ['efectivo', 'tarjeta', 'transferencia', 'otro'], true) ? $d['medio_pago'] : 'efectivo';

    $x = boletaDatosExtra($d);
    $profId = $x['profesional_id']; $profNombre = $x['profesional_nombre']; $profRut = $x['profesional_rut'];
    $cliDir = $x['receptor_direccion']; $cliComuna = $x['receptor_comuna']; $cliCod = $x['receptor_codigo_prestacion'];

    try {
        $pdo = db();
        $pdo->beginTransaction();
        // Folio correlativo PROPIO de la empresa y del tipo (boletas y facturas llevan
        // numeraciones separadas, como los folios del SII). Bloqueo para evitar carreras.
        // Solo la serie INTERNA: los folios del SII (serie 'sii') los entrega el CAF.
        $fst = $pdo->prepare("SELECT COALESCE(MAX(folio),0)+1 FROM boletas WHERE empresa_id = ? AND tipo = ? AND serie = 'interna' FOR UPDATE");
        $fst->execute([$empresaId, $tipo]);
        $folio = (int) $fst->fetchColumn();

        $st = $pdo->prepare(
            "INSERT INTO boletas
                (folio, tipo, empresa_id, emisor_usuario_id, emisor_nombre, receptor_nombre, receptor_rut, receptor_email, paciente_id, cliente_id, receptor_tipo,
                 receptor_giro, receptor_direccion, receptor_comuna, receptor_codigo_prestacion, reserva_id,
                 profesional_id, profesional_nombre, profesional_rut,
                 fecha_emision, medio_pago, neto_clp, iva_clp, exento_clp, total_clp, glosa)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $st->execute([
            $folio,
            $tipo,
            $empresaId,
            $_SESSION['admin_id'] ?? null,
            $empresa['nombre_fantasia'] ?: $empresa['razon_social'],
            mb_substr(trim((string) $d['receptor_nombre']), 0, 200, 'UTF-8'),
            ($d['receptor_rut'] ?? '') !== '' ? mb_substr(trim((string) $d['receptor_rut']), 0, 20, 'UTF-8') : null,
            ($d['receptor_email'] ?? '') !== '' ? mb_substr(trim((string) $d['receptor_email']), 0, 200, 'UTF-8') : null,
            ($d['paciente_id'] ?? '') !== '' ? (int) $d['paciente_id'] : null,
            (int) ($d['cliente_id'] ?? 0) ?: null,
            $x['receptor_tipo'],
            ($d['receptor_giro'] ?? '') !== '' ? mb_substr(trim((string) $d['receptor_giro']), 0, 200, 'UTF-8') : null,
            $cliDir,
            $cliComuna,
            $cliCod,
            ($d['reserva_id'] ?? '') !== '' ? (int) $d['reserva_id'] : null,
            $profId,
            $profNombre,
            $profRut,
            date('Y-m-d H:i:s'), // hora de Chile (la base de datos está en UTC)
            $medio,
            $neto, $iva, $exentoTotal, $total,
            ($d['glosa'] ?? '') !== '' ? mb_substr(trim((string) $d['glosa']), 0, 300, 'UTF-8') : null,
        ]);
        $id = (int) $pdo->lastInsertId();

        boletaInsertarItems($pdo, $id, $normItems);
        $pdo->commit();
        boletaRutAFicha($profId, $profRut);
        return ['ok' => true, 'id' => $id, 'folio' => $folio, 'tipo' => $tipo];
    } catch (Throwable $e) {
        try { db()->rollBack(); } catch (Throwable $e2) {}
        return ['ok' => false, 'error' => 'No se pudo emitir el documento. Intenta de nuevo.'];
    }
}

/** Obtiene una boleta con sus ítems. */
function getBoleta(int $id): ?array
{
    try {
        $st = db()->prepare("SELECT * FROM boletas WHERE id = ?");
        $st->execute([$id]);
        $b = $st->fetch();
        if (!$b) {
            return null;
        }
        $si = db()->prepare("SELECT * FROM boleta_items WHERE boleta_id = ? ORDER BY id");
        $si->execute([$id]);
        $b['items'] = $si->fetchAll();
        return $b;
    } catch (Throwable $e) {
        return null;
    }
}

/** Lista de documentos (más recientes primero). Filtra por empresa y/o tipo. */
function listBoletas(int $limite = 100, int $empresaId = 0, string $tipo = ''): array
{
    try {
        $sql = "SELECT b.*, e.razon_social AS empresa_razon, e.nombre_fantasia AS empresa_fantasia
                  FROM boletas b LEFT JOIN empresas e ON e.id = b.empresa_id";
        $cond = [];
        $params = [];
        if ($empresaId > 0) {
            $cond[] = "b.empresa_id = ?";
            $params[] = $empresaId;
        }
        if ($tipo === 'boleta' || $tipo === 'factura') {
            $cond[] = "b.tipo = ?";
            $params[] = $tipo;
        }
        if ($cond) {
            $sql .= " WHERE " . implode(' AND ', $cond);
        }
        $sql .= " ORDER BY b.id DESC LIMIT " . max(1, $limite);
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Anula una boleta (no la borra: deja de contar como ingreso). */
function anularBoleta(int $id): bool
{
    try {
        $st = db()->prepare("UPDATE boletas SET estado='anulada' WHERE id = ?");
        return $st->execute([$id]);
    } catch (Throwable $e) {
        return false;
    }
}
