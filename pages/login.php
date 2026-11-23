<?php
/**
 * Inicio de sesión de administradores. Reemplaza a app/admin/login/page.tsx +
 * components/admin/LoginForm.tsx. Procesa el POST del formulario (sin JS).
 */
declare(strict_types=1);

// Si ya hay sesión, al panel.
if (isAuthenticated()) {
    header('Location: ' . base_path('/admin'));
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada. Vuelve a intentarlo.';
    } elseif (attemptLogin($_POST['email'] ?? '', $_POST['password'] ?? '')) {
        header('Location: ' . base_path('/admin'));
        exit;
    } else {
        $error = 'Correo o contraseña incorrectos.';
    }
}

$hasLogo = hasLogo();
$pageTitle = 'Iniciar sesión — Espacio Luz';
$bodyClass = 'font-body bg-crema-light text-cafe antialiased';
include APP_ROOT . '/partials/head.php';
?>
<div class="flex min-h-screen items-center justify-center bg-crema-light p-4 sm:p-8">
    <div class="flex w-full max-w-5xl overflow-hidden rounded-[2rem] border border-cafe/10 bg-crema shadow-warm">
        <div class="w-full p-8 sm:p-12 lg:w-[40%] lg:shrink-0">
            <?= render_logo(false) ?>

            <h1 class="mt-8 font-heading text-2xl font-semibold text-cafe sm:text-3xl">Bienvenido de vuelta</h1>
            <p class="mt-2 font-body text-sm text-cafe/60">Inicia sesión para editar los contenidos del sitio.</p>

            <form method="post" action="<?= e(base_path('/admin/login')) ?>" class="mt-8 flex flex-col gap-4">
                <?= csrf_field() ?>
                <label class="flex flex-col gap-1.5">
                    <span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/70">Usuario o correo</span>
                    <input type="text" name="email" required autocomplete="username"
                        class="rounded-lg border border-cafe/20 bg-crema-light px-3 py-2.5 font-body text-sm text-cafe outline-none transition-colors focus:border-terracota"
                        placeholder="Tu usuario o correo">
                </label>

                <label class="flex flex-col gap-1.5">
                    <span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/70">Contraseña</span>
                    <input type="password" name="password" required autocomplete="current-password"
                        class="rounded-lg border border-cafe/20 bg-crema-light px-3 py-2.5 font-body text-sm text-cafe outline-none transition-colors focus:border-terracota"
                        placeholder="••••••••••••">
                </label>

                <?php if ($error): ?>
                    <p class="font-body text-sm font-medium text-red-600"><?= e($error) ?></p>
                <?php endif; ?>

                <button type="submit"
                    class="mt-2 inline-flex items-center justify-center gap-2 rounded-full bg-terracota px-6 py-3 font-body text-sm font-semibold text-crema-light shadow-card transition-all hover:bg-terracota-dark">
                    Iniciar sesión
                </button>
            </form>

            <p class="mt-8 font-body text-xs text-cafe/50">
                <a href="<?= e(base_path('/')) ?>" class="transition-colors hover:text-terracota">← Volver al sitio</a>
            </p>
        </div>

        <div class="relative hidden lg:block lg:w-[60%]">
            <img src="<?= e(asset('images/login/login-bg.jpg')) ?>" alt="" class="absolute inset-0 h-full w-full object-cover">
        </div>
    </div>
</div>
<?php include APP_ROOT . '/partials/foot.php'; ?>
