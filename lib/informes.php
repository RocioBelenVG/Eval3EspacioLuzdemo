<?php
/**
 * Informes clínicos del paciente (archivos adjuntos a la ficha clínica).
 *
 * Cada informe que entregan las profesionales (evaluación y diagnóstico,
 * orientación familiar, avance, alta…) se adjunta al paciente, opcionalmente a
 * la ficha de un área, con su tipo, fecha y profesional. Se puede ver y
 * descargar desde la ficha del paciente.
 *
 * Archivos: storage/informes/p<paciente>/inf-<aleatorio>.<ext> (sin acceso web;
 * se sirven solo con sesión por /admin/pacientes/<id>/informes/<informe>).
 * Eliminación: la Ley 20.584 exige conservar la ficha clínica, por eso un
 * informe «eliminado» se oculta, pero el registro y el archivo se conservan con
 * quién y cuándo lo eliminó (igual que las evoluciones).
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';

const INFORME_TIPOS = [
    'evaluacion'           => 'Evaluación y diagnóstico',
    'avance'               => 'Avance / intervención terapéutica',
    'orientacion_familiar' => 'Orientación familiar',
    'alta'                 => 'Alta / cierre de proceso',
    'derivacion'           => 'Derivación / interconsulta',
    'externo'              => 'Informe externo (otro especialista)',
    'otro'                 => 'Otro',
];
const INFORME_MAX_BYTES = 20 * 1024 * 1024;
/** Extensión => tipos MIME aceptados (detectados por contenido, no por el nombre). */
const INFORME_FORMATOS = [
    'pdf'  => ['application/pdf'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'webp' => ['image/webp'],
    'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
];
/** Tipo MIME con que se entrega cada extensión al descargar. */
const INFORME_MIME_SALIDA = [
    'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];

function informesDir(int $pacienteId): string
{
    return APP_ROOT . '/storage/informes/p' . $pacienteId;
}

function informeUsuario(): ?string
{
    return isset($_SESSION['admin_nombre']) ? mb_substr((string) $_SESSION['admin_nombre'], 0, 120, 'UTF-8') : null;
}

/** Informes visibles del paciente (más recientes primero). */
function pacienteInformes(int $pacienteId): array
{
    try {
        $st = db()->prepare(
            "SELECT i.*, p.nombre AS profesional_nombre, f.especialidad_slug, e.nombre AS especialidad_nombre
               FROM paciente_informes i
          LEFT JOIN profesionales p ON p.id = i.profesional_id
          LEFT JOIN fichas f ON f.id = i.ficha_id
          LEFT JOIN especialidades e ON e.slug = f.especialidad_slug
              WHERE i.paciente_id = ? AND i.eliminado_en IS NULL
           ORDER BY i.fecha_informe DESC, i.id DESC"
        );
        $st->execute([$pacienteId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function getInforme(int $id): ?array
{
    try {
        $st = db()->prepare('SELECT * FROM paciente_informes WHERE id = ? AND eliminado_en IS NULL');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Ruta absoluta del archivo de un informe (validada), o null. */
function informeRuta(array $inf): ?string
{
    $arch = (string) $inf['archivo'];
    if (!preg_match('/^inf-[0-9a-f]{24}\.(pdf|jpe?g|png|webp|docx?)$/', $arch)) {
        return null;
    }
    $p = informesDir((int) $inf['paciente_id']) . '/' . $arch;
    return is_file($p) ? $p : null;
}

function informeUrl(array $inf, bool $ver = false): string
{
    return base_path('/admin/pacientes/' . (int) $inf['paciente_id'] . '/informes/' . (int) $inf['id']) . ($ver ? '?ver=1' : '');
}

/** ¿Se puede mostrar en el navegador (PDF o imagen)? */
function informeVisible(array $inf): bool
{
    return in_array(strtolower(pathinfo((string) $inf['archivo'], PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true);
}

function informeTamanoTexto(int $bytes): string
{
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    return max(1, (int) round($bytes / 1024)) . ' KB';
}

/** Valida tipo, ficha, profesional y fecha. @return array{ok:bool, error?:string, datos?:array} */
function informeValidarDatos(int $pacienteId, array $d): array
{
    $tipo = (string) ($d['tipo'] ?? '');
    if (!isset(INFORME_TIPOS[$tipo])) {
        return ['ok' => false, 'error' => 'Elige el tipo de informe.'];
    }
    $fecha = trim((string) ($d['fecha_informe'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4))) {
        return ['ok' => false, 'error' => 'La fecha del informe no es válida.'];
    }
    if ($fecha > date('Y-m-d')) {
        return ['ok' => false, 'error' => 'La fecha del informe no puede ser futura.'];
    }
    $fichaId = ($d['ficha_id'] ?? '') !== '' ? (int) $d['ficha_id'] : null;
    if ($fichaId !== null) {
        $st = db()->prepare('SELECT COUNT(*) FROM fichas WHERE id = ? AND paciente_id = ?');
        $st->execute([$fichaId, $pacienteId]);
        if (!(int) $st->fetchColumn()) {
            return ['ok' => false, 'error' => 'El área elegida no corresponde a este paciente.'];
        }
    }
    $profId = ($d['profesional_id'] ?? '') !== '' ? (int) $d['profesional_id'] : null;
    if ($profId !== null) {
        $st = db()->prepare('SELECT COUNT(*) FROM profesionales WHERE id = ?');
        $st->execute([$profId]);
        if (!(int) $st->fetchColumn()) {
            return ['ok' => false, 'error' => 'Profesional no encontrado.'];
        }
    }
    $titulo = trim(preg_replace('/\s+/u', ' ', (string) ($d['titulo'] ?? '')));
    if ($titulo === '') {
        $titulo = INFORME_TIPOS[$tipo];
    }
    $desc = trim((string) ($d['descripcion'] ?? ''));
    return ['ok' => true, 'datos' => [
        'tipo' => $tipo, 'fecha_informe' => $fecha, 'ficha_id' => $fichaId, 'profesional_id' => $profId,
        'titulo' => mb_substr($titulo, 0, 200, 'UTF-8'), 'descripcion' => $desc !== '' ? mb_substr($desc, 0, 2000, 'UTF-8') : null,
    ]];
}

/**
 * Sube un informe. $file es la entrada de $_FILES.
 * @return array{ok:bool, id?:int, error?:string}
 */
function informeSubir(int $pacienteId, array $d, array $file): array
{
    if (!getPacienteExiste($pacienteId)) {
        return ['ok' => false, 'error' => 'Paciente no encontrado.'];
    }
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'error' => 'El archivo supera los 20 MB.'];
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return ['ok' => false, 'error' => 'Adjunta el archivo del informe.'];
    }
    return informeGuardar($pacienteId, $d, (string) $file['tmp_name'], (string) ($file['name'] ?? ''));
}

function getPacienteExiste(int $id): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM pacientes WHERE id = ?');
    $st->execute([$id]);
    return (bool) $st->fetchColumn();
}

/** Valida y guarda un archivo ya recibido en $tmp (lo copia; no lo mueve). */
function informeGuardar(int $pacienteId, array $d, string $tmp, string $nombreOriginal): array
{
    $v = informeValidarDatos($pacienteId, $d);
    if (!$v['ok']) {
        return $v;
    }
    $tam = is_file($tmp) ? (int) filesize($tmp) : 0;
    if ($tam <= 0) {
        return ['ok' => false, 'error' => 'El archivo está vacío.'];
    }
    if ($tam > INFORME_MAX_BYTES) {
        return ['ok' => false, 'error' => 'El archivo supera los 20 MB.'];
    }
    $nombreOriginal = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', ' ', $nombreOriginal)) ?: 'informe';
    $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
    if (!isset(INFORME_FORMATOS[$ext])) {
        return ['ok' => false, 'error' => 'Formato no admitido. Sube el informe en PDF, Word (DOC/DOCX) o imagen (JPG, PNG, WEBP).'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    $mimeOk = in_array($mime, INFORME_FORMATOS[$ext], true);
    if ($mimeOk && $ext === 'docx' && $mime !== INFORME_FORMATOS['docx'][0]) {
        // Algunos servidores detectan el .docx como ZIP genérico: confirmar que es un documento de Word.
        $mimeOk = false;
        if (class_exists('ZipArchive')) {
            $z = new ZipArchive();
            if ($z->open($tmp) === true) { $mimeOk = $z->locateName('word/document.xml') !== false; $z->close(); }
        }
    }
    if (!$mimeOk) {
        return ['ok' => false, 'error' => 'El contenido del archivo no corresponde a un ' . strtoupper($ext) . ' válido.'];
    }
    $dir = informesDir($pacienteId);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        return ['ok' => false, 'error' => 'No se pudo preparar la carpeta de informes.'];
    }
    $archivo = 'inf-' . bin2hex(random_bytes(12)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    $dest = $dir . '/' . $archivo;
    if (!@copy($tmp, $dest)) {
        return ['ok' => false, 'error' => 'No se pudo guardar el archivo.'];
    }
    @chmod($dest, 0640);
    $x = $v['datos'];
    try {
        db()->prepare('INSERT INTO paciente_informes (paciente_id, ficha_id, tipo, titulo, fecha_informe, profesional_id, descripcion, archivo, nombre_original, mime, tamano, sha256, subido_por)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$pacienteId, $x['ficha_id'], $x['tipo'], $x['titulo'], $x['fecha_informe'], $x['profesional_id'], $x['descripcion'],
                $archivo, mb_substr($nombreOriginal, 0, 255, 'UTF-8'), $mime, $tam, hash_file('sha256', $dest), informeUsuario()]);
        return ['ok' => true, 'id' => (int) db()->lastInsertId(), 'archivo' => $archivo];
    } catch (Throwable $e) {
        @unlink($dest);
        error_log('[informes] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo registrar el informe.'];
    }
}

/** Edita los datos del informe (el archivo no se reemplaza: se sube uno nuevo). */
function informeActualizar(int $id, array $d): array
{
    $inf = getInforme($id);
    if (!$inf) {
        return ['ok' => false, 'error' => 'El informe ya no existe.'];
    }
    $v = informeValidarDatos((int) $inf['paciente_id'], $d);
    if (!$v['ok']) {
        return $v;
    }
    $x = $v['datos'];
    try {
        db()->prepare('UPDATE paciente_informes SET tipo=?, titulo=?, fecha_informe=?, ficha_id=?, profesional_id=?, descripcion=?, editado_en=NOW(), editado_por=? WHERE id=?')
            ->execute([$x['tipo'], $x['titulo'], $x['fecha_informe'], $x['ficha_id'], $x['profesional_id'], $x['descripcion'], informeUsuario(), $id]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo guardar el informe.'];
    }
}

/** Elimina (oculta) un informe. El registro y el archivo se conservan por la Ley 20.584. */
function informeEliminar(int $id): array
{
    try {
        $st = db()->prepare('UPDATE paciente_informes SET eliminado_en = NOW(), eliminado_por = ? WHERE id = ? AND eliminado_en IS NULL');
        $st->execute([informeUsuario(), $id]);
        return $st->rowCount() > 0 ? ['ok' => true] : ['ok' => false, 'error' => 'El informe ya no existe.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo eliminar el informe.'];
    }
}
