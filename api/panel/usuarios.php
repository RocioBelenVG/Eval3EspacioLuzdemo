<?php
/**
 * API del panel · usuarios y roles. Solo administradores. Requiere CSRF.
 * Acciones (POST JSON): crear, rol, activo, password, eliminar.
 */
declare(strict_types=1);

requireAdminJson();

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!csrf_check($csrf)) {
    json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
}

$body = read_json_body() ?? [];
$action = $body['action'] ?? '';
$yo = (int) ($_SESSION['admin_id'] ?? 0);

/** Nº de administradores activos. */
function adminsActivos(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM usuarios WHERE rol='admin' AND activo=1")->fetchColumn();
}
/** ¿El usuario es admin activo? */
function esAdminActivo(int $id): bool
{
    $s = db()->prepare("SELECT COUNT(*) FROM usuarios WHERE id=? AND rol='admin' AND activo=1");
    $s->execute([$id]);
    return (int) $s->fetchColumn() > 0;
}

try {
    if ($action === 'crear') {
        $nombre = trim((string) ($body['nombre'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $pass = (string) ($body['password'] ?? '');
        $rol = rolValido($body['rol'] ?? 'editor'); // admin | editor | pacientes
        if ($nombre === '' || $email === '') {
            json_response(['error' => 'Nombre y usuario/correo son obligatorios.'], 400);
        }
        if (strlen($pass) < 6) {
            json_response(['error' => 'La contraseña debe tener al menos 6 caracteres.'], 400);
        }
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        try {
            $st = db()->prepare("INSERT INTO usuarios (nombre, email, password_hash, rol, activo) VALUES (?,?,?,?,1)");
            $st->execute([mb_substr($nombre, 0, 120, 'UTF-8'), mb_substr($email, 0, 190, 'UTF-8'), $hash, $rol]);
            json_response(['ok' => true, 'id' => (int) db()->lastInsertId()]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                json_response(['error' => 'Ya existe un usuario con ese correo/usuario.'], 409);
            }
            throw $e;
        }
    }

    if ($action === 'rol') {
        $id = (int) ($body['id'] ?? 0);
        $rol = rolValido($body['rol'] ?? ''); // admin | editor | pacientes
        if ($rol !== 'admin' && esAdminActivo($id) && adminsActivos() <= 1) {
            json_response(['error' => 'Debe quedar al menos un administrador activo.'], 400);
        }
        db()->prepare("UPDATE usuarios SET rol = ? WHERE id = ?")->execute([$rol, $id]);
        json_response(['ok' => true]);
    }

    if ($action === 'activo') {
        $id = (int) ($body['id'] ?? 0);
        $activo = !empty($body['activo']) ? 1 : 0;
        if (!$activo && esAdminActivo($id) && adminsActivos() <= 1) {
            json_response(['error' => 'Debe quedar al menos un administrador activo.'], 400);
        }
        db()->prepare("UPDATE usuarios SET activo = ? WHERE id = ?")->execute([$activo, $id]);
        json_response(['ok' => true]);
    }

    if ($action === 'password') {
        $id = (int) ($body['id'] ?? 0);
        $pass = (string) ($body['password'] ?? '');
        if (strlen($pass) < 6) {
            json_response(['error' => 'La contraseña debe tener al menos 6 caracteres.'], 400);
        }
        db()->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")->execute([password_hash($pass, PASSWORD_BCRYPT), $id]);
        json_response(['ok' => true]);
    }

    if ($action === 'eliminar') {
        $id = (int) ($body['id'] ?? 0);
        if (esAdminActivo($id) && adminsActivos() <= 1) {
            json_response(['error' => 'Debe quedar al menos un administrador activo.'], 400);
        }
        db()->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
        json_response(['ok' => true]);
    }

    json_response(['error' => 'Acción inválida'], 400);
} catch (Throwable $e) {
    json_response(['error' => 'No se pudo completar la operación.'], 500);
}
