<?php
/** Panel · vista de error 403 (el rol no tiene acceso a la pantalla pedida). */
declare(strict_types=1);

requireAuthPage();
http_response_code(403);
$panelTitle = 'Sin permisos';
$panelActive = '';
$panelCss = ['css/app.css'];
include APP_ROOT . '/partials/panel/top.php';
$inicio = esRolGerencia() ? '/admin/bi' : (esRolPacientes() ? '/admin/pacientes' : '/admin');
?>
<div class="el-app">
    <section class="el-tarjeta" style="max-width:640px;margin:2rem auto;text-align:center" aria-labelledby="t403">
        <svg viewBox="0 0 24 24" width="56" height="56" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true" style="color:var(--c-primario)">
            <rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3"/><path d="M12 14v3"/></svg>
        <h2 id="t403" style="margin-top:.75rem">Tu rol no tiene acceso a esta pantalla</h2>
        <p class="el-ayuda" style="margin:0 auto 1.25rem">Ingresaste como <strong><?= e(rolEtiqueta(currentAdminRol())) ?></strong>.
            Si necesitas este acceso, pídeselo a Administración. (Error 403)</p>
        <a class="el-btn" href="<?= e(base_path($inicio)) ?>">Ir a mi inicio</a>
    </section>
</div>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
