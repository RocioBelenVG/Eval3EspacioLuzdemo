<?php
/** Panel · Usuarios y Roles (solo admin). */
declare(strict_types=1);

requireAdminPage();
require_once APP_ROOT . '/lib/panel.php';

$usuarios = [];
try {
    $usuarios = db()->query("SELECT id, nombre, email, rol, activo, ultimo_acceso FROM usuarios ORDER BY id")->fetchAll();
} catch (Throwable $e) {
    $usuarios = [];
}
$yo = (int) ($_SESSION['admin_id'] ?? 0);

$panelTitle = 'Usuarios y Roles';
$panelActive = 'usuarios';
include APP_ROOT . '/partials/panel/top.php';
?>
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <!-- Crear usuario -->
    <div class="lg:col-span-1 rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card">
        <h2 class="font-heading text-lg font-semibold text-cafe">Nuevo usuario</h2>
        <form id="u-nuevo" class="mt-3 space-y-3">
            <label class="flex flex-col gap-1"><span class="ulbl">Nombre</span><input name="nombre" required class="uinp"></label>
            <label class="flex flex-col gap-1"><span class="ulbl">Usuario o correo</span><input name="email" required class="uinp"></label>
            <label class="flex flex-col gap-1"><span class="ulbl">Contraseña</span><input name="password" type="text" minlength="6" required class="uinp" placeholder="mín. 6 caracteres"></label>
            <label class="flex flex-col gap-1"><span class="ulbl">Rol</span>
                <select name="rol" class="uinp">
                    <option value="editor">Editor (solo contenidos)</option>
                    <option value="pacientes">Pacientes (fichas y certificados)</option>
                    <option value="admin">Administrador (acceso total)</option>
                </select>
            </label>
            <p id="u-err" class="hidden font-body text-sm font-medium text-red-600"></p>
            <button type="submit" class="w-full rounded-full bg-terracota px-5 py-2.5 font-body text-sm font-semibold text-crema-light transition-colors hover:bg-terracota-dark">Crear usuario</button>
        </form>
        <p class="mt-3 font-body text-[11px] text-cafe/45"><strong>Admin:</strong> puede todo (usuarios, boletas, calendarios). <strong>Editor:</strong> solo edita contenidos del sitio. <strong>Pacientes:</strong> solo ve el módulo Pacientes (fichas, evoluciones y certificados de atención con la firma del profesional).</p>
    </div>

    <!-- Lista -->
    <div class="lg:col-span-2 ez-tabla-card">
        <h2 class="px-5 py-4 font-heading text-lg font-semibold text-cafe">Usuarios (<?= count($usuarios) ?>)</h2>
        <div class="overflow-x-auto">
            <table class="ez-tabla">
                <thead><tr>
                    <th class="px-5 py-2.5">Usuario</th>
                    <th class="px-5 py-2.5">Rol</th>
                    <th class="px-5 py-2.5">Estado</th>
                    <th class="px-5 py-2.5 text-right">Acciones</th>
                </tr></thead>
                <tbody class="font-body text-sm text-cafe/80">
                    <?php foreach ($usuarios as $u): $mio = ((int) $u['id'] === $yo); ?>
                        <tr data-id="<?= (int) $u['id'] ?>">
                            <td class="px-5 py-3">
                                <div class="font-semibold text-cafe"><?= e($u['nombre']) ?><?= $mio ? ' <span class="font-body text-[10px] font-semibold text-terracota-dark">(tú)</span>' : '' ?></div>
                                <div class="text-xs text-cafe/50"><?= e($u['email']) ?></div>
                            </td>
                            <td class="px-5 py-3">
                                <select class="u-rol rounded-lg border border-cafe/20 bg-crema-light px-2 py-1.5 font-body text-xs font-semibold text-cafe outline-none focus:border-terracota" <?= $mio ? '' : '' ?>>
                                    <option value="editor" <?= $u['rol'] === 'editor' ? 'selected' : '' ?>>Editor</option>
                                    <option value="pacientes" <?= $u['rol'] === 'pacientes' ? 'selected' : '' ?>>Pacientes</option>
                                    <option value="admin" <?= $u['rol'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                                </select>
                            </td>
                            <td class="px-5 py-3">
                                <?php if ($u['activo']): ?>
                                    <span class="rounded-full bg-salvia/20 px-2.5 py-0.5 text-xs font-semibold text-salvia-dark">Activo</span>
                                <?php else: ?>
                                    <span class="rounded-full bg-cafe/15 px-2.5 py-0.5 text-xs font-semibold text-cafe/70">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex flex-wrap items-center justify-end gap-1.5">
                                    <button type="button" class="u-pass rounded-lg border border-cafe/15 px-2.5 py-1 text-xs font-semibold text-cafe hover:border-terracota hover:text-terracota">Clave</button>
                                    <button type="button" class="u-activo rounded-lg border border-cafe/15 px-2.5 py-1 text-xs font-semibold text-cafe hover:border-terracota hover:text-terracota" data-activo="<?= (int) $u['activo'] ?>"><?= $u['activo'] ? 'Desactivar' : 'Activar' ?></button>
                                    <button type="button" class="u-del rounded-lg border border-red-200 px-2.5 py-1 text-xs font-semibold text-red-600 hover:bg-red-50">Eliminar</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.uinp').forEach(function (el) { el.className = 'uinp rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota'; });
    document.querySelectorAll('.ulbl').forEach(function (el) { el.className = 'ulbl font-body text-xs font-semibold uppercase tracking-wide text-cafe/60'; });
    var P = window.__PANEL__ || {};
    function post(data) {
        return fetch(P.base + '/api/panel/usuarios', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf }, body: JSON.stringify(data) })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); });
    }
    var form = document.getElementById('u-nuevo');
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var err = document.getElementById('u-err'); err.classList.add('hidden');
        post({ action: 'crear', nombre: form.nombre.value.trim(), email: form.email.value.trim(), password: form.password.value, rol: form.rol.value })
            .then(function (x) { if (!x.ok) { err.textContent = x.d.error || 'Error'; err.classList.remove('hidden'); return; } location.reload(); });
    });
    document.querySelectorAll('tr[data-id]').forEach(function (tr) {
        var id = parseInt(tr.dataset.id, 10);
        var rol = tr.querySelector('.u-rol');
        if (rol) rol.addEventListener('change', function () { post({ action: 'rol', id: id, rol: rol.value }).then(function (x) { if (!x.ok) { ezAlert(x.d.error || 'No se pudo.'); location.reload(); } }); });
        var act = tr.querySelector('.u-activo');
        if (act) act.addEventListener('click', function () { post({ action: 'activo', id: id, activo: act.dataset.activo === '1' ? 0 : 1 }).then(function (x) { if (!x.ok) ezAlert(x.d.error || 'No se pudo.'); location.reload(); }); });
        var del = tr.querySelector('.u-del');
        if (del) del.addEventListener('click', function () { ezConfirm('¿Eliminar este usuario? No se puede deshacer.', { title: 'Eliminar usuario', okText: 'Eliminar', danger: true }).then(function (ok) { if (!ok) return; post({ action: 'eliminar', id: id }).then(function (x) { if (!x.ok) { ezAlert(x.d.error || 'No se pudo.'); return; } location.reload(); }); }); });
        var pass = tr.querySelector('.u-pass');
        if (pass) pass.addEventListener('click', function () { ezPrompt('Nueva contraseña (mín. 6 caracteres):', { title: 'Cambiar contraseña', okText: 'Guardar', placeholder: 'Nueva contraseña' }).then(function (np) { if (np === null) return; if (np.length < 6) { ezAlert('Debe tener al menos 6 caracteres.'); return; } post({ action: 'password', id: id, password: np }).then(function (x) { ezAlert(x.ok ? 'Contraseña actualizada.' : (x.d.error || 'No se pudo.')); }); }); });
    });
});
</script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
