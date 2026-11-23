<?php
/**
 * Profesionales y especialidades — capa de datos (tabla `profesionales`).
 * Fuente única para panel, agenda y sitio público.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';

/** Especialidades [slug => nombre]. */
function especialidadesLista(): array
{
    try {
        $rows = db()->query("SELECT slug, nombre FROM especialidades WHERE activo = 1 ORDER BY orden, nombre")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['slug']] = $r['nombre'];
        }
        return $out;
    } catch (Throwable $e) {
        return ['fonoaudiologia' => 'Fonoaudiología', 'terapia-ocupacional' => 'Terapia Ocupacional', 'psicologia' => 'Psicología'];
    }
}

/** Todos los profesionales (por defecto solo activos). */
function profesionalesTodos(bool $incluirInactivos = false): array
{
    try {
        $sql = "SELECT p.*, e.nombre AS especialidad_nombre
                  FROM profesionales p
             LEFT JOIN especialidades e ON e.slug = p.especialidad_slug
                 WHERE p.eliminado_at IS NULL";
        if (!$incluirInactivos) {
            $sql .= " AND p.activo = 1";
        }
        $sql .= " ORDER BY p.orden, p.id";
        return db()->query($sql)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Profesionales visibles en el sitio público. */
function profesionalesPublicos(): array
{
    try {
        return db()->query("SELECT * FROM profesionales WHERE activo = 1 AND publico = 1 ORDER BY orden, id")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Profesionales para la grilla del sitio. Para un admin con sesión incluye los
 * ocultos (publico=0) para poder editarlos/reactivarlos; para el público, solo
 * los visibles.
 */
function profesionalesParaSitio(bool $incluirOcultos): array
{
    if (!$incluirOcultos) {
        return profesionalesPublicos();
    }
    try {
        return db()->query("SELECT * FROM profesionales WHERE activo = 1 ORDER BY orden, id")->fetchAll();
    } catch (Throwable $e) {
        return profesionalesPublicos();
    }
}

function getProfesional(int $id): ?array
{
    try {
        $st = db()->prepare("SELECT * FROM profesionales WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Profesional (activo) por slug, con nombre de especialidad. Para la página pública. */
function getProfesionalBySlug(string $slug): ?array
{
    try {
        $st = db()->prepare("SELECT p.*, e.nombre AS especialidad_nombre FROM profesionales p LEFT JOIN especialidades e ON e.slug = p.especialidad_slug WHERE p.slug = ? AND p.activo = 1");
        $st->execute([$slug]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Decodifica el perfil (JSON) de un profesional a arreglo. */
function perfilProfesional(?array $prof): array
{
    if (!$prof || empty($prof['perfil'])) {
        return [];
    }
    $d = json_decode((string) $prof['perfil'], true);
    return is_array($d) ? $d : [];
}

/** Campos simples editables inline de un profesional (columnas de la tabla). */
const PROF_CAMPOS_EDIT = ['nombre', 'titulo', 'descripcion', 'enfoque', 'duracion_texto'];
/** Claves de texto editables dentro del perfil JSON. */
const PROF_PERFIL_CAMPOS = ['subtitulo', 'sobre_mi', 'enfoque', 'cita', 'acompanamiento', 'universidad', 'experiencia'];
/** Claves de lista editables dentro del perfil JSON. */
const PROF_PERFIL_LISTAS = ['chips', 'areas', 'formacion', 'atiende'];

/** Actualiza UNA columna de un profesional (edición inline). No toca el slug. */
function actualizarCampoProfesional(int $id, string $campo, string $valor): array
{
    if (!in_array($campo, PROF_CAMPOS_EDIT, true)) {
        return ['ok' => false, 'error' => 'Campo no editable.'];
    }
    $valor = trim($valor);
    if ($campo === 'nombre' && $valor === '') {
        return ['ok' => false, 'error' => 'El nombre no puede quedar vacío.'];
    }
    $max = $campo === 'descripcion' || $campo === 'enfoque' ? 500 : ($campo === 'titulo' ? 120 : ($campo === 'duracion_texto' ? 60 : 200));
    try {
        $st = db()->prepare("UPDATE profesionales SET `$campo` = ? WHERE id = ?");
        $st->execute([$valor !== '' ? mb_substr($valor, 0, $max, 'UTF-8') : null, $id]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo guardar.'];
    }
}

/** Actualiza una clave de texto del perfil JSON (edición inline del detalle). */
function actualizarPerfilCampoProfesional(int $id, string $clave, string $valor): array
{
    if (!in_array($clave, PROF_PERFIL_CAMPOS, true)) {
        return ['ok' => false, 'error' => 'Campo de perfil no editable.'];
    }
    try {
        $prof = getProfesional($id);
        if (!$prof) {
            return ['ok' => false, 'error' => 'Profesional no encontrado.'];
        }
        $perfil = perfilProfesional($prof);
        $perfil[$clave] = mb_substr(trim($valor), 0, 2000, 'UTF-8');
        $st = db()->prepare("UPDATE profesionales SET perfil = ? WHERE id = ?");
        $st->execute([json_encode($perfil, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo guardar el perfil.'];
    }
}

/** Reemplaza una lista del perfil JSON (chips, areas, formacion, atiende). */
function actualizarPerfilListaProfesional(int $id, string $clave, array $items): array
{
    if (!in_array($clave, PROF_PERFIL_LISTAS, true)) {
        return ['ok' => false, 'error' => 'Lista de perfil no editable.'];
    }
    try {
        $prof = getProfesional($id);
        if (!$prof) {
            return ['ok' => false, 'error' => 'Profesional no encontrado.'];
        }
        $limpios = [];
        foreach ($items as $it) {
            $t = mb_substr(trim((string) $it), 0, 200, 'UTF-8');
            if ($t !== '') {
                $limpios[] = $t;
            }
            if (count($limpios) >= 20) {
                break;
            }
        }
        $perfil = perfilProfesional($prof);
        $perfil[$clave] = $limpios;
        $st = db()->prepare("UPDATE profesionales SET perfil = ? WHERE id = ?");
        $st->execute([json_encode($perfil, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo guardar la lista.'];
    }
}

/** Actualiza el nombre de archivo de la foto de un profesional. */
function actualizarFotoProfesional(int $id, string $basename): bool
{
    try {
        $st = db()->prepare("UPDATE profesionales SET foto = ? WHERE id = ?");
        return $st->execute([$basename !== '' ? basename($basename) : null, $id]);
    } catch (Throwable $e) {
        return false;
    }
}

/** Genera un slug único a partir del nombre. */
function slugProfesional(string $nombre): string
{
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) ?: $nombre;
    $s = trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $s)), '-');
    $base = $s !== '' ? $s : 'profesional';
    $sl = $base; $i = 2;
    try {
        while (true) {
            $c = db()->prepare("SELECT COUNT(*) FROM profesionales WHERE slug = ?");
            $c->execute([$sl]);
            if ((int) $c->fetchColumn() === 0) {
                break;
            }
            $sl = $base . '-' . $i++;
        }
    } catch (Throwable $e) {
    }
    return $sl;
}

/** Crea un profesional. Devuelve ['ok'=>bool,'id'|'error']. */
function crearProfesional(array $d): array
{
    $nombre = trim((string) ($d['nombre'] ?? ''));
    $esp = trim((string) ($d['especialidad_slug'] ?? ''));
    if ($nombre === '' || $esp === '') {
        return ['ok' => false, 'error' => 'Nombre y especialidad son obligatorios.'];
    }
    if (!array_key_exists($esp, especialidadesLista())) {
        return ['ok' => false, 'error' => 'Especialidad inválida.'];
    }
    try {
        $orden = (int) db()->query("SELECT COALESCE(MAX(orden),0)+1 FROM profesionales")->fetchColumn();
        $slug = slugProfesional($nombre);
        $st = db()->prepare("INSERT INTO profesionales (nombre, slug, especialidad_slug, descripcion, enfoque, duracion_texto, activo, publico, orden)
                             VALUES (?,?,?,?,?,?,1,?,?)");
        $st->execute([
            mb_substr($nombre, 0, 200, 'UTF-8'),
            $slug,
            $esp,
            trim((string) ($d['descripcion'] ?? '')) ?: null,
            trim((string) ($d['enfoque'] ?? '')) ?: null,
            trim((string) ($d['duracion_texto'] ?? '')) ?: null,
            !empty($d['publico']) ? 1 : 0,
            $orden,
        ]);
        return ['ok' => true, 'id' => (int) db()->lastInsertId(), 'slug' => $slug];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo crear el profesional.'];
    }
}

function actualizarProfesional(int $id, array $d): array
{
    $nombre = trim((string) ($d['nombre'] ?? ''));
    $esp = trim((string) ($d['especialidad_slug'] ?? ''));
    if ($nombre === '' || $esp === '' || !array_key_exists($esp, especialidadesLista())) {
        return ['ok' => false, 'error' => 'Nombre y especialidad válidos son obligatorios.'];
    }
    // RUT (opcional): se valida el dígito verificador y se guarda con formato 12.345.678-9.
    // Se usa automáticamente en boletas/facturas y en los certificados de atención.
    require_once APP_ROOT . '/lib/rut.php';
    $rut = trim((string) ($d['rut'] ?? ''));
    if ($rut !== '' && !rutValido($rut)) {
        return ['ok' => false, 'error' => 'El RUT no es válido. Revisa el número y el dígito verificador.'];
    }
    $rut = $rut !== '' ? formatoRut($rut) : null;
    try {
        $st = db()->prepare("UPDATE profesionales SET nombre=?, especialidad_slug=?, descripcion=?, enfoque=?, duracion_texto=?, publico=?, rut=? WHERE id=?");
        $st->execute([
            mb_substr($nombre, 0, 200, 'UTF-8'), $esp,
            trim((string) ($d['descripcion'] ?? '')) ?: null,
            trim((string) ($d['enfoque'] ?? '')) ?: null,
            trim((string) ($d['duracion_texto'] ?? '')) ?: null,
            !empty($d['publico']) ? 1 : 0, $rut, $id,
        ]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo actualizar.'];
    }
}

function setProfesionalActivo(int $id, bool $activo): bool
{
    try { $st = db()->prepare("UPDATE profesionales SET activo=? WHERE id=?"); return $st->execute([$activo ? 1 : 0, $id]); }
    catch (Throwable $e) { return false; }
}

/** ¿El profesional tiene reservas o fichas asociadas? */
function profesionalTieneVinculos(int $id): bool
{
    try {
        $r = (int) db()->query("SELECT (SELECT COUNT(*) FROM reservas WHERE profesional_id=$id) + (SELECT COUNT(*) FROM fichas WHERE profesional_responsable_id=$id) + (SELECT COUNT(*) FROM evoluciones WHERE profesional_id=$id) + (SELECT COUNT(*) FROM boletas WHERE profesional_id=$id) + (SELECT COUNT(*) FROM certificados_atencion WHERE profesional_id=$id)")->fetchColumn();
        return $r > 0;
    } catch (Throwable $e) {
        return true; // por seguridad, no permitir borrado físico si no se puede verificar
    }
}

/**
 * Elimina un profesional. Si tiene vínculos (reservas/fichas), solo lo
 * desactiva (soft delete) para no romper el historial. Si no, lo borra.
 */
function eliminarProfesional(int $id): array
{
    try {
        if (profesionalTieneVinculos($id)) {
            // Con historial (reservas, fichas, boletas…): se archiva. Sale de la lista del
            // panel, del sitio y de los selectores, pero el historial clínico y contable
            // conserva su nombre. Sus horas FUTURAS aún vigentes impiden archivarlo.
            $fut = db()->prepare("SELECT COUNT(*) FROM reservas WHERE profesional_id=? AND estado<>'cancelada' AND fecha>=CURDATE()");
            $fut->execute([$id]);
            if (($n = (int) $fut->fetchColumn()) > 0) {
                return ['ok' => false, 'error' => "Tiene $n hora(s) agendada(s) desde hoy en adelante. Cancélalas o traspásalas a otro profesional (Calendario de profesionales → gestión en bloque) y luego elimínalo."];
            }
            db()->prepare("UPDATE profesionales SET activo=0, publico=0, agenda_online=0, eliminado_at=NOW() WHERE id=?")->execute([$id]);
            return ['ok' => true, 'modo' => 'archivado'];
        }
        // Sin historial (reservas/fichas/evoluciones): se puede borrar de verdad.
        // Antes se quitan las filas NO históricas que referencian al profesional
        // (su disponibilidad/agenda), para que el DELETE no falle por clave foránea.
        try { db()->prepare("DELETE FROM disponibilidad WHERE profesional_id=?")->execute([$id]); } catch (Throwable $e) { /* la tabla puede no existir */ }
        db()->prepare("DELETE FROM profesionales WHERE id=?")->execute([$id]);
        return ['ok' => true, 'modo' => 'eliminado'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo eliminar (puede tener registros asociados).'];
    }
}
