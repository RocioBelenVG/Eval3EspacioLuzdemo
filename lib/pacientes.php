<?php
/**
 * Pacientes, fichas clínicas y evoluciones — capa de datos.
 * Datos personales y de salud (Ley 19.628 / Ley 20.584): acceso solo desde el
 * panel autenticado. Nunca DELETE físico de pacientes con historial (soft delete).
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';
require_once APP_ROOT . '/lib/panel.php';
require_once APP_ROOT . '/lib/profesionales.php';

/* ------------------------------------------- Catálogos (tablas regiones, comunas, previsiones) */

/** Valores válidos de sexo (columna pacientes.sexo) y su etiqueta. */
const PACIENTE_SEXOS = ['hombre' => 'Hombre', 'mujer' => 'Mujer', 'otro' => 'Otro'];

/**
 * Documento de identidad del paciente. 'rut' usa la columna `rut` (formato 12.345.678-9);
 * los extranjeros sin RUT usan `documento_tipo` + `documento_numero` y dejan `rut` vacío
 * (en boletas/facturas se tratan como receptor sin RUT).
 */
const PACIENTE_DOCUMENTOS = ['rut' => 'RUT', 'pasaporte' => 'Pasaporte', 'extranjero' => 'Doc. extranjero'];
const PACIENTE_DOCUMENTOS_LARGO = ['rut' => 'RUT', 'pasaporte' => 'Pasaporte', 'extranjero' => 'Documento extranjero'];

/** Tipo de documento efectivo del paciente ('rut' si no hay documento extranjero). */
function pacienteDocTipo(array $p): string
{
    $t = (string) ($p['documento_tipo'] ?? 'rut');
    return $t !== 'rut' && isset(PACIENTE_DOCUMENTOS[$t]) && trim((string) ($p['documento_numero'] ?? '')) !== '' ? $t : 'rut';
}

/** Número del documento (RUT formateado o número extranjero); '' si no tiene. */
function pacienteDocNumero(array $p): string
{
    return pacienteDocTipo($p) === 'rut' ? trim((string) ($p['rut'] ?? '')) : trim((string) $p['documento_numero']);
}

/** "19.036.123-3" o "Pasaporte AB1234567"; '' si no tiene documento. */
function pacienteDocumento(array $p): string
{
    $n = pacienteDocNumero($p);
    if ($n === '') {
        return '';
    }
    $t = pacienteDocTipo($p);
    return $t === 'rut' ? $n : PACIENTE_DOCUMENTOS_LARGO[$t] . ' ' . $n;
}

/** Etiqueta del campo: "RUT", "Pasaporte", "Documento extranjero". */
function pacienteDocEtiqueta(array $p): string
{
    return PACIENTE_DOCUMENTOS_LARGO[pacienteDocTipo($p)];
}

/** Agrega documento_tipo y documento_numero a pacientes (idempotente). */
function pacientesAsegurarDocumento(): void
{
    static $hecho = false;
    if ($hecho) {
        return;
    }
    $hecho = true;
    try {
        $pdo = db();
        $col = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'pacientes' AND column_name = 'documento_tipo'")->fetchColumn();
        if ((int) $col === 0) {
            $pdo->exec("ALTER TABLE pacientes
                ADD COLUMN documento_tipo VARCHAR(12) NOT NULL DEFAULT 'rut' AFTER rut,
                ADD COLUMN documento_numero VARCHAR(30) NULL AFTER documento_tipo,
                ADD UNIQUE KEY uq_paciente_doc (documento_tipo, documento_numero)");
        }
    } catch (Throwable $e) {
        // sin permisos de ALTER: el alta con documento extranjero fallará con mensaje genérico
    }
}

/** Regiones de Chile (norte a sur) con sus comunas: [['nombre'=>..,'numero'=>..,'comunas'=>[..]], ...]. */
function catalogoRegiones(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        $rows = db()->query(
            "SELECT r.id, r.numero, r.nombre AS region, c.nombre AS comuna
               FROM regiones r JOIN comunas c ON c.region_id = r.id
           ORDER BY r.orden, c.nombre"
        )->fetchAll();
        foreach ($rows as $row) {
            $k = (int) $row['id'];
            if (!isset($cache[$k])) {
                $cache[$k] = ['nombre' => $row['region'], 'numero' => $row['numero'], 'comunas' => []];
            }
            $cache[$k]['comunas'][] = $row['comuna'];
        }
        $cache = array_values($cache);
    } catch (Throwable $e) {
        $cache = [];
    }
    return $cache;
}

/** Previsiones de salud vigentes agrupadas: ['Sistema público' => ['FONASA - Tramo A', ...], ...]. */
function catalogoPrevisiones(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        foreach (db()->query("SELECT nombre, grupo FROM previsiones WHERE activo = 1 ORDER BY orden, nombre")->fetchAll() as $p) {
            $cache[$p['grupo']][] = $p['nombre'];
        }
    } catch (Throwable $e) {
        $cache = [];
    }
    return $cache;
}

/**
 * Normaliza y valida sexo, región, comuna y previsión contra los catálogos.
 * Si llega solo la comuna, completa su región. Devuelve un error o null.
 */
function validarCatalogosPaciente(array &$d): ?string
{
    $sexo = trim((string) ($d['sexo'] ?? ''));
    if ($sexo !== '' && !isset(PACIENTE_SEXOS[$sexo])) {
        return 'Elige un sexo válido.';
    }
    $d['sexo'] = $sexo !== '' ? $sexo : null;

    $region = trim((string) ($d['region'] ?? ''));
    $comuna = trim((string) ($d['comuna'] ?? ''));
    $regionDeComuna = null;
    $comunasDeRegion = null;
    foreach (catalogoRegiones() as $r) {
        if ($r['nombre'] === $region) {
            $comunasDeRegion = $r['comunas'];
        }
        if ($comuna !== '' && in_array($comuna, $r['comunas'], true)) {
            $regionDeComuna = $r['nombre'];
        }
    }
    if ($region !== '' && $comunasDeRegion === null) {
        return 'Elige una región válida.';
    }
    if ($comuna !== '') {
        if ($regionDeComuna === null) {
            return 'Elige una comuna válida.';
        }
        if ($region === '') {
            $region = $regionDeComuna;
        } elseif ($regionDeComuna !== $region) {
            return 'La comuna ' . $comuna . ' no pertenece a la región ' . $region . '.';
        }
    }
    $d['region'] = $region !== '' ? $region : null;
    $d['comuna'] = $comuna !== '' ? $comuna : null;

    $prev = trim((string) ($d['prevision'] ?? ''));
    if ($prev !== '') {
        $validas = array_merge([], ...array_values(catalogoPrevisiones()));
        if ($validas && !in_array($prev, $validas, true)) {
            return 'Elige una previsión de la lista.';
        }
    }
    $d['prevision'] = $prev !== '' ? $prev : null;
    return null;
}

/**
 * Selects de sexo, región, comuna y previsión para los formularios de paciente.
 * La comuna se completa en el navegador según la región (ver paciente-geo.php).
 * @return array{sexo:string, region:string, comuna:string, prevision:string}
 */
function pacienteSelectsHtml(string $cls): array
{
    $c = e($cls);
    $sexo = '<select name="sexo" class="' . $c . '"><option value="">— Seleccionar —</option>';
    foreach (PACIENTE_SEXOS as $v => $l) {
        $sexo .= '<option value="' . e($v) . '">' . e($l) . '</option>';
    }
    $sexo .= '</select>';

    $region = '<select name="region" class="' . $c . '" data-geo-region><option value="">— Seleccionar región —</option>';
    foreach (catalogoRegiones() as $r) {
        $region .= '<option value="' . e($r['nombre']) . '">' . e($r['nombre']) . '</option>';
    }
    $region .= '</select>';

    $comuna = '<select name="comuna" class="' . $c . '" data-geo-comuna disabled><option value="">— Primero elige la región —</option></select>';

    $prev = '<select name="prevision" class="' . $c . '"><option value="">— Seleccionar previsión —</option>';
    foreach (catalogoPrevisiones() as $grupo => $items) {
        $prev .= '<optgroup label="' . e($grupo) . '">';
        foreach ($items as $it) {
            $prev .= '<option value="' . e($it) . '">' . e($it) . '</option>';
        }
        $prev .= '</optgroup>';
    }
    $prev .= '</select>';

    return ['sexo' => $sexo, 'region' => $region, 'comuna' => $comuna, 'prevision' => $prev];
}

/* ----------------------------------------------------------------- Pacientes */

function pacientesLista(string $q = '', bool $incluirInactivos = false): array
{
    try {
        pacientesAsegurarDocumento();
        $sql = "SELECT id, nombre, rut, documento_tipo, documento_numero, email, telefono, apoderado, apoderado_rut, direccion, comuna, codigo_prestacion, activo FROM pacientes";
        $cond = [];
        $params = [];
        if (!$incluirInactivos) {
            $cond[] = "activo = 1";
        }
        if ($q !== '') {
            // El RUT se guarda como 12.345.678-9: también se encuentra si se busca sin puntos ni guion.
            $rutQ = strtoupper((string) preg_replace('/[^0-9kK]/', '', $q));
            $cond[] = "(nombre LIKE ? OR rut LIKE ? OR documento_numero LIKE ? OR email LIKE ? OR telefono LIKE ?"
                . (strlen($rutQ) >= 3 ? " OR REPLACE(REPLACE(rut, '.', ''), '-', '') LIKE ?" : '') . ")";
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like);
            if (strlen($rutQ) >= 3) {
                $params[] = '%' . $rutQ . '%';
            }
        }
        if ($cond) {
            $sql .= " WHERE " . implode(' AND ', $cond);
        }
        $sql .= " ORDER BY nombre LIMIT 300";
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function getPaciente(int $id): ?array
{
    try {
        $st = db()->prepare("SELECT * FROM pacientes WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Reglas comunes al crear/editar un paciente:
 *  - RUT del paciente y del apoderado: dígito verificador válido y se guardan
 *    siempre como 12.345.678-9.
 *  - Edad según la fecha de nacimiento: menor de 18 años -> tutor/apoderado obligatorio
 *    («No aplica» no cuenta como tutor).
 * Normaliza $d (rut, apoderado_rut, fecha_nacimiento). Devuelve el error o null.
 */
function validarIdentidadPaciente(array &$d): ?string
{
    require_once APP_ROOT . '/lib/rut.php';
    pacientesAsegurarDocumento();

    // Documento: RUT chileno o, para extranjeros, pasaporte / documento extranjero.
    // El formulario envía el número en `rut` junto con `documento_tipo`.
    $tipo = trim((string) ($d['documento_tipo'] ?? 'rut')) ?: 'rut';
    if (!isset(PACIENTE_DOCUMENTOS[$tipo])) {
        return 'Elige un tipo de documento válido.';
    }
    $d['documento_numero'] = '';
    if ($tipo !== 'rut') {
        $num = strtoupper((string) preg_replace('/\s+/', '', trim((string) ($d['rut'] ?? ''))));
        $d['rut'] = '';
        if ($num !== '' && !preg_match('/^[A-Z0-9][A-Z0-9.\-]{3,29}$/', $num)) {
            return 'El número de ' . mb_strtolower(PACIENTE_DOCUMENTOS_LARGO[$tipo], 'UTF-8')
                . ' no es válido: usa solo letras y números (entre 4 y 30 caracteres).';
        }
        $d['documento_numero'] = $num;
        if ($num === '') {
            $tipo = 'rut';
        }
    }
    $d['documento_tipo'] = $tipo;

    foreach (['rut' => 'El RUT del paciente', 'apoderado_rut' => 'El RUT del apoderado'] as $k => $quien) {
        $v = trim((string) ($d[$k] ?? ''));
        if ($v === '') {
            $d[$k] = '';
            continue;
        }
        if (!rutValido($v)) {
            return $quien . ' no es válido: revisa el número y el dígito verificador (ej: 12.345.678-9).';
        }
        $d[$k] = formatoRut($v);
    }

    $f = trim((string) ($d['fecha_nacimiento'] ?? ''));
    $d['fecha_nacimiento'] = $f;
    if ($f === '') {
        return null;
    }
    $nac = DateTimeImmutable::createFromFormat('!Y-m-d', $f);
    if (!$nac || $nac->format('Y-m-d') !== $f) {
        return 'La fecha de nacimiento no es válida.';
    }
    $hoy = new DateTimeImmutable('today');
    if ($nac > $hoy) {
        return 'La fecha de nacimiento no puede ser futura.';
    }
    $edad = $nac->diff($hoy)->y;
    if ($edad > 120) {
        return 'Revisa la fecha de nacimiento: la edad calculada supera los 120 años.';
    }
    if ($edad < PACIENTE_MAYORIA_EDAD && !pacienteTieneTutor((string) ($d['apoderado'] ?? ''))) {
        return 'El paciente es menor de edad (' . $edad . ($edad === 1 ? ' año' : ' años')
            . '): el tutor / apoderado es obligatorio.';
    }
    return null;
}

function crearPaciente(array $d): array
{
    $nombre = trim((string) ($d['nombre'] ?? ''));
    if ($nombre === '') {
        return ['ok' => false, 'error' => 'El nombre es obligatorio.'];
    }
    if ($err = validarIdentidadPaciente($d)) {
        return ['ok' => false, 'error' => $err];
    }
    $rut = trim((string) ($d['rut'] ?? ''));
    if ($err = validarCatalogosPaciente($d)) {
        return ['ok' => false, 'error' => $err];
    }
    try {
        $st = db()->prepare("INSERT INTO pacientes (nombre, rut, documento_tipo, documento_numero, email, telefono, fecha_nacimiento, sexo, direccion, region, comuna, apoderado, apoderado_rut, apoderado_telefono, contacto_emergencia, prevision, codigo_prestacion, notas, consentimiento, consentimiento_fecha)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $consent = !empty($d['consentimiento']) ? 1 : 0;
        $st->execute([
            mb_substr($nombre, 0, 200, 'UTF-8'),
            $rut !== '' ? mb_substr($rut, 0, 20, 'UTF-8') : null,
            $d['documento_tipo'],
            $d['documento_numero'] !== '' ? $d['documento_numero'] : null,
            trim((string) ($d['email'] ?? '')) ?: null,
            trim((string) ($d['telefono'] ?? '')) ?: null,
            trim((string) ($d['fecha_nacimiento'] ?? '')) ?: null,
            $d['sexo'],
            trim((string) ($d['direccion'] ?? '')) ?: null,
            $d['region'],
            $d['comuna'],
            trim((string) ($d['apoderado'] ?? '')) ?: null,
            trim((string) ($d['apoderado_rut'] ?? '')) ?: null,
            trim((string) ($d['apoderado_telefono'] ?? '')) ?: null,
            trim((string) ($d['contacto_emergencia'] ?? '')) ?: null,
            $d['prevision'],
            trim((string) ($d['codigo_prestacion'] ?? '')) ?: null,
            trim((string) ($d['notas'] ?? '')) ?: null,
            $consent,
            $consent ? date('Y-m-d H:i:s') : null,
        ]);
        return ['ok' => true, 'id' => (int) db()->lastInsertId()];
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'error' => str_contains($e->getMessage(), 'uq_paciente_doc')
                ? 'Ya existe un paciente con ese número de documento.' : 'Ya existe un paciente con ese RUT.'];
        }
        return ['ok' => false, 'error' => 'No se pudo crear el paciente.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo crear el paciente.'];
    }
}

function actualizarPaciente(int $id, array $d): array
{
    $nombre = trim((string) ($d['nombre'] ?? ''));
    if ($nombre === '') {
        return ['ok' => false, 'error' => 'El nombre es obligatorio.'];
    }
    if ($err = validarIdentidadPaciente($d)) {
        return ['ok' => false, 'error' => $err];
    }
    if ($err = validarCatalogosPaciente($d)) {
        return ['ok' => false, 'error' => $err];
    }
    try {
        // Giro solo se toca si viene en el formulario; la fecha del consentimiento se fija al otorgarlo.
        $conGiro = array_key_exists('giro', $d);
        $consent = !empty($d['consentimiento']) ? 1 : 0;
        $st = db()->prepare("UPDATE pacientes SET nombre=?, rut=?, documento_tipo=?, documento_numero=?, email=?, telefono=?, fecha_nacimiento=?, sexo=?, direccion=?, region=?, comuna=?, apoderado=?, apoderado_rut=?, apoderado_telefono=?, contacto_emergencia=?, prevision=?, codigo_prestacion=?, notas=IF(? = 1, ?, notas),"
            . ($conGiro ? ' giro=' . (($g = mb_substr(trim((string) $d['giro']), 0, 160, 'UTF-8')) !== '' ? db()->quote($g) : 'NULL') . ',' : '')
            . " consentimiento_fecha = CASE WHEN $consent = 1 THEN COALESCE(consentimiento_fecha, NOW()) ELSE NULL END, consentimiento=? WHERE id=?");
        $st->execute([
            mb_substr($nombre, 0, 200, 'UTF-8'),
            trim((string) ($d['rut'] ?? '')) ?: null,
            $d['documento_tipo'],
            $d['documento_numero'] !== '' ? $d['documento_numero'] : null,
            trim((string) ($d['email'] ?? '')) ?: null,
            trim((string) ($d['telefono'] ?? '')) ?: null,
            trim((string) ($d['fecha_nacimiento'] ?? '')) ?: null,
            $d['sexo'],
            trim((string) ($d['direccion'] ?? '')) ?: null,
            $d['region'],
            $d['comuna'],
            trim((string) ($d['apoderado'] ?? '')) ?: null,
            trim((string) ($d['apoderado_rut'] ?? '')) ?: null,
            trim((string) ($d['apoderado_telefono'] ?? '')) ?: null,
            trim((string) ($d['contacto_emergencia'] ?? '')) ?: null,
            $d['prevision'],
            trim((string) ($d['codigo_prestacion'] ?? '')) ?: null,
            array_key_exists('notas', $d) ? 1 : 0,
            trim((string) ($d['notas'] ?? '')) ?: null,
            !empty($d['consentimiento']) ? 1 : 0,
            $id,
        ]);
        return ['ok' => true];
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'error' => str_contains($e->getMessage(), 'uq_paciente_doc')
                ? 'Ya existe un paciente con ese número de documento.' : 'Ya existe un paciente con ese RUT.'];
        }
        return ['ok' => false, 'error' => 'No se pudo actualizar.'];
    }
}

/** Edad calculada a partir de la fecha de nacimiento. */
/* ---------------------------------------------------------------------------
 * Notas del paciente (tabla pacientes_notas): internas, no clínicas.
 * ------------------------------------------------------------------------- */
const NOTA_MAX = 2000;

/** Fila de pacientes_notas -> forma que usa el panel. */
function notaFormato(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'paciente_id' => (int) $r['paciente_id'],
        'texto' => (string) $r['texto'],
        'completada' => (int) $r['completada'],
        'completada_en' => $r['completada_en'],
        'autor' => (string) ($r['creado_por_nombre'] ?? ''),
        'creado_en' => $r['creado_en'],
        'editado_en' => $r['editado_en'],
    ];
}

/** Todas las notas del paciente: pendientes primero, luego las más nuevas. */
function notasPaciente(int $pacienteId): array
{
    try {
        $st = db()->prepare('SELECT * FROM pacientes_notas WHERE paciente_id = ? ORDER BY completada ASC, creado_en DESC, id DESC');
        $st->execute([$pacienteId]);
        return array_map('notaFormato', $st->fetchAll());
    } catch (Throwable $e) {
        return [];
    }
}

function notaGet(int $id): ?array
{
    try {
        $st = db()->prepare('SELECT * FROM pacientes_notas WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ? notaFormato($r) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Normaliza el texto; null si es inválido (con $error). */
function notaTexto(string $t, ?string &$error = null): ?string
{
    $t = trim(str_replace(["\r\n", "\r"], "\n", $t));
    if ($t === '') {
        $error = 'La nota está vacía.';
        return null;
    }
    if (mb_strlen($t, 'UTF-8') > NOTA_MAX) {
        $error = 'La nota no puede superar los ' . NOTA_MAX . ' caracteres.';
        return null;
    }
    return $t;
}

function crearNotaPaciente(int $pacienteId, string $texto): array
{
    if ($pacienteId <= 0 || !getPaciente($pacienteId)) {
        return ['ok' => false, 'error' => 'Paciente no encontrado.'];
    }
    if (($t = notaTexto($texto, $err)) === null) {
        return ['ok' => false, 'error' => $err];
    }
    try {
        db()->prepare('INSERT INTO pacientes_notas (paciente_id, texto, creado_por, creado_por_nombre) VALUES (?, ?, ?, ?)')
            ->execute([$pacienteId, $t, ($_SESSION['admin_id'] ?? null) ?: null, isset($_SESSION['admin_nombre']) ? mb_substr((string) $_SESSION['admin_nombre'], 0, 120, 'UTF-8') : null]);
        return ['ok' => true, 'nota' => notaGet((int) db()->lastInsertId())];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo guardar la nota.'];
    }
}

/**
 * $borrador: guardado automático mientras se escribe la nota recién creada; no la marca
 * como «editada» (solo cuenta como edición si la nota tiene más de 30 minutos).
 */
function actualizarNotaPaciente(int $id, string $texto, bool $borrador = false): array
{
    $n = notaGet($id);
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
        db()->prepare('UPDATE pacientes_notas SET texto = ?, editado_en = IF(? = 1 AND creado_en > NOW() - INTERVAL 30 MINUTE, editado_en, NOW()) WHERE id = ?')->execute([$t, $borrador ? 1 : 0, $id]);
        return ['ok' => true, 'nota' => notaGet($id)];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo guardar la nota.'];
    }
}

function marcarNotaPaciente(int $id, bool $completada): array
{
    if (!notaGet($id)) {
        return ['ok' => false, 'error' => 'La nota ya no existe (puede haber sido eliminada).'];
    }
    try {
        db()->prepare('UPDATE pacientes_notas SET completada = ?, completada_en = ' . ($completada ? 'NOW()' : 'NULL') . ' WHERE id = ?')
            ->execute([$completada ? 1 : 0, $id]);
        return ['ok' => true, 'nota' => notaGet($id)];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo actualizar la nota.'];
    }
}

function eliminarNotaPaciente(int $id): array
{
    try {
        $st = db()->prepare('DELETE FROM pacientes_notas WHERE id = ?');
        $st->execute([$id]);
        return $st->rowCount() > 0 ? ['ok' => true] : ['ok' => false, 'error' => 'La nota ya no existe.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo eliminar la nota.'];
    }
}

function pacienteEdad(?string $fnac): ?int
{
    if (!$fnac) {
        return null;
    }
    try {
        return (new DateTimeImmutable($fnac))->diff(new DateTimeImmutable('today'))->y;
    } catch (Throwable $e) {
        return null;
    }
}

/** Mayoría de edad en Chile. */
const PACIENTE_MAYORIA_EDAD = 18;

/** ¿El campo apoderado tiene un tutor real? ("No aplica…" o vacío = no). */
function pacienteTieneTutor(?string $apoderado): bool
{
    $a = trim((string) $apoderado);
    return $a !== '' && !preg_match('/^no aplica/i', $a);
}

/**
 * Separa "Claudia Vergara (madre)" en nombre y relación.
 * @return array{0:string,1:string} [nombre, relación]
 */
function pacienteTutorPartes(string $apoderado): array
{
    $a = trim($apoderado);
    if (preg_match('/^(.*?)\s*[\(\[]\s*([^\)\]]+)\s*[\)\]]\s*$/u', $a, $m) && trim($m[1]) !== '') {
        return [trim($m[1]), trim($m[2])];
    }
    if (preg_match('/^(.*?)\s+[-–—]\s+(.+)$/u', $a, $m) && trim($m[1]) !== '') {
        return [trim($m[1]), trim($m[2])];
    }
    return [$a, ''];
}

/**
 * A QUIÉN SE COBRA un documento (boleta/factura) de un paciente.
 * Regla: paciente MENOR de edad -> datos del tutor/apoderado;
 *        paciente MAYOR de edad -> datos del propio paciente.
 * La edad sale de la fecha de nacimiento. Sin fecha: si tiene tutor registrado se
 * cobra al tutor, si no al paciente (y se avisa que falta la fecha).
 * Dirección, comuna y código de prestación siempre son los de la ficha del paciente.
 *
 * @param array|int $p fila de `pacientes` o su id
 * @return array{ok:bool,error?:string,tipo?:string,motivo?:string,edad?:?int,aviso?:string,
 *               nombre?:string,relacion?:string,rut?:string,email?:string,telefono?:string,
 *               direccion?:string,comuna?:string,codigo_prestacion?:string,
 *               paciente_id?:int,paciente_nombre?:string}
 */
function receptorCobroPaciente($p): array
{
    if (!is_array($p)) {
        $p = getPaciente((int) $p);
    }
    if (!$p) {
        return ['ok' => false, 'error' => 'Selecciona un paciente registrado en Pacientes.'];
    }
    if ((int) ($p['activo'] ?? 1) !== 1) {
        return ['ok' => false, 'error' => 'El paciente está inactivo. Reactívalo en su ficha para emitirle documentos.'];
    }
    $t = static fn($v): string => trim((string) ($v ?? ''));
    $edad = pacienteEdad($p['fecha_nacimiento'] ?? null);
    $tieneTutor = pacienteTieneTutor($p['apoderado'] ?? null);

    if ($edad !== null) {
        $menor = $edad < PACIENTE_MAYORIA_EDAD;
        $motivo = $menor ? 'menor' : 'mayor';
    } else {
        $menor = $tieneTutor;
        $motivo = 'sin_fecha';
    }

    $base = [
        'ok' => true,
        'edad' => $edad,
        'motivo' => $motivo,
        'aviso' => $motivo === 'sin_fecha' ? 'La ficha no tiene fecha de nacimiento: la edad no se pudo verificar.' : '',
        'direccion' => $t($p['direccion'] ?? ''),
        'comuna' => $t($p['comuna'] ?? ''),
        'codigo_prestacion' => $t($p['codigo_prestacion'] ?? ''),
        'paciente_id' => (int) $p['id'],
        'paciente_nombre' => $t($p['nombre']),
        'email' => $t($p['email'] ?? ''),
    ];

    if ($menor) {
        if (!$tieneTutor) {
            return ['ok' => false, 'error' => 'El paciente es menor de edad' . ($edad !== null ? " ($edad años)" : '')
                . ' y no tiene tutor/apoderado registrado. Completa los datos del tutor en su ficha para poder cobrarle.'];
        }
        [$nombre, $relacion] = pacienteTutorPartes((string) $p['apoderado']);
        return $base + [
            'tipo' => 'tutor',
            'nombre' => $nombre,
            'relacion' => $relacion,
            'rut' => $t($p['apoderado_rut'] ?? ''),
            'telefono' => $t($p['apoderado_telefono'] ?? ''),
        ];
    }
    return $base + [
        'tipo' => 'paciente',
        'nombre' => $t($p['nombre']),
        'relacion' => '',
        'rut' => $t($p['rut'] ?? ''),
        'telefono' => $t($p['telefono'] ?? ''),
    ];
}

/* ------------------------------------------------- Historial de horas y pagos */

/** Reservas del paciente (historial de horas) con box y profesional. */
function pacienteReservas(int $id): array
{
    try {
        $st = db()->prepare(
            "SELECT id, box_slug, profesional_id, modalidad_label, precio_clp, fecha, TIME_FORMAT(hora,'%H:%i') AS hhmm, estado
               FROM reservas WHERE paciente_id = ? ORDER BY fecha DESC, hora DESC"
        );
        $st->execute([$id]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Boletas (pagos) del paciente. */
function pacienteBoletas(int $id): array
{
    try {
        $st = db()->prepare("SELECT * FROM boletas WHERE paciente_id = ? ORDER BY id DESC");
        $st->execute([$id]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Métricas resumidas del paciente para la cabecera de su ficha. */
function pacienteResumen(int $id): array
{
    $out = ['sesiones' => 0, 'realizadas' => 0, 'facturado' => 0, 'proxima' => null];
    try {
        $out['sesiones'] = (int) db()->query("SELECT COUNT(*) FROM reservas WHERE paciente_id=$id AND estado<>'cancelada'")->fetchColumn();
        $out['realizadas'] = (int) db()->query("SELECT COUNT(*) FROM reservas WHERE paciente_id=$id AND estado='realizada'")->fetchColumn();
        // Facturado neto: boletas emitidas menos sus notas de crédito.
        $out['facturado'] = (int) db()->query("SELECT COALESCE(SUM(total_clp - nc_clp + nd_clp),0) FROM boletas WHERE paciente_id=$id AND estado='emitida'")->fetchColumn();
        $soc = array_keys(profesionalesSocias());
        $out['por_cobrar'] = (int) db()->query("SELECT COUNT(*) FROM reservas WHERE paciente_id=$id AND boleta_id IS NULL AND estado <> 'cancelada'"
            . ($soc ? ' AND profesional_id IN (' . implode(',', array_map('intval', $soc)) . ')' : ''))->fetchColumn();
        $out['proxima'] = db()->query("SELECT CONCAT(fecha,' ',TIME_FORMAT(hora,'%H:%i')) FROM reservas WHERE paciente_id=$id AND estado<>'cancelada' AND fecha>=CURDATE() ORDER BY fecha,hora LIMIT 1")->fetchColumn() ?: null;
    } catch (Throwable $e) {
    }
    return $out;
}

/* ---------------------------------------------------- Fichas y evoluciones */

function pacienteFichas(int $id): array
{
    try {
        $st = db()->prepare(
            "SELECT f.*, e.nombre AS especialidad_nombre, p.nombre AS profesional_nombre
               FROM fichas f
          LEFT JOIN especialidades e ON e.slug = f.especialidad_slug
          LEFT JOIN profesionales p ON p.id = f.profesional_responsable_id
              WHERE f.paciente_id = ? ORDER BY f.abierta_en DESC"
        );
        $st->execute([$id]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function getFicha(int $id): ?array
{
    try {
        $st = db()->prepare("SELECT f.*, e.nombre AS especialidad_nombre FROM fichas f LEFT JOIN especialidades e ON e.slug=f.especialidad_slug WHERE f.id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function crearFicha(array $d): array
{
    $cid = (int) ($d['paciente_id'] ?? 0);
    $esp = trim((string) ($d['especialidad_slug'] ?? ''));
    if ($cid <= 0 || $esp === '' || !array_key_exists($esp, especialidadesLista())) {
        return ['ok' => false, 'error' => 'Paciente y especialidad válidos son obligatorios.'];
    }
    try {
        $st = db()->prepare("INSERT INTO fichas (paciente_id, especialidad_slug, profesional_responsable_id, motivo_consulta, antecedentes, diagnostico, objetivos, abierta_en)
                             VALUES (?,?,?,?,?,?,?,CURDATE())");
        $st->execute([
            $cid, $esp,
            ($d['profesional_responsable_id'] ?? '') !== '' ? (int) $d['profesional_responsable_id'] : null,
            trim((string) ($d['motivo_consulta'] ?? '')) ?: null,
            trim((string) ($d['antecedentes'] ?? '')) ?: null,
            trim((string) ($d['diagnostico'] ?? '')) ?: null,
            trim((string) ($d['objetivos'] ?? '')) ?: null,
        ]);
        return ['ok' => true, 'id' => (int) db()->lastInsertId()];
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'error' => 'Este paciente ya tiene una ficha de esa especialidad.'];
        }
        return ['ok' => false, 'error' => 'No se pudo crear la ficha.'];
    }
}

function actualizarFicha(int $id, array $d): array
{
    try {
        $st = db()->prepare("UPDATE fichas SET profesional_responsable_id=?, motivo_consulta=?, antecedentes=?, diagnostico=?, objetivos=?, estado=? WHERE id=?");
        $st->execute([
            ($d['profesional_responsable_id'] ?? '') !== '' ? (int) $d['profesional_responsable_id'] : null,
            trim((string) ($d['motivo_consulta'] ?? '')) ?: null,
            trim((string) ($d['antecedentes'] ?? '')) ?: null,
            trim((string) ($d['diagnostico'] ?? '')) ?: null,
            trim((string) ($d['objetivos'] ?? '')) ?: null,
            ($d['estado'] ?? 'activa') === 'cerrada' ? 'cerrada' : 'activa',
            $id,
        ]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo guardar la ficha.'];
    }
}

function fichaEvoluciones(int $fichaId): array
{
    try {
        $st = db()->prepare("SELECT ev.*, p.nombre AS profesional_nombre FROM evoluciones ev LEFT JOIN profesionales p ON p.id=ev.profesional_id WHERE ev.ficha_id=? AND ev.eliminado_en IS NULL ORDER BY ev.fecha DESC, ev.id DESC");
        $st->execute([$fichaId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function crearEvolucion(array $d): array
{
    $fichaId = (int) ($d['ficha_id'] ?? 0);
    $nota = trim((string) ($d['nota_progreso'] ?? ''));
    if ($fichaId <= 0 || $nota === '') {
        return ['ok' => false, 'error' => 'La nota de progreso es obligatoria.'];
    }
    try {
        $st = db()->prepare("INSERT INTO evoluciones (ficha_id, profesional_id, fecha, nota_progreso, proxima_accion) VALUES (?,?,?,?,?)");
        $st->execute([
            $fichaId,
            ($d['profesional_id'] ?? '') !== '' ? (int) $d['profesional_id'] : null,
            trim((string) ($d['fecha'] ?? '')) ?: date('Y-m-d'),
            $nota,
            trim((string) ($d['proxima_accion'] ?? '')) ?: null,
        ]);
        return ['ok' => true, 'id' => (int) db()->lastInsertId()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo guardar la evolución.'];
    }
}
