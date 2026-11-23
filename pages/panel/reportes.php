<?php
/** Panel · Reportes: operativo, gerencial y de documentos (rango de fechas). Exportación CSV, Excel y PDF e impresión. */
declare(strict_types=1);

requireAuthPage();
if (!in_array(currentAdminRol(), ['admin', 'editor', 'gerencia'], true)) {
    header('Location: ' . base_path('/admin/sin-permiso'));
    exit;
}
$panelTitle = 'Reportes';
$panelActive = 'reportes';
$panelCss = ['css/app.css'];
include APP_ROOT . '/partials/panel/top.php';
?>
<div class="el-app" id="rep-app">
    <p class="el-ayuda el-no-imprimir">Elige un reporte, aplica filtros y expórtalo a <strong>Excel (.xlsx)</strong>, <strong>PDF</strong> o <strong>CSV</strong>
        (UTF-8, separador punto y coma «;», decimales con coma) con esos mismos filtros, o imprímelo. Cada exportación queda registrada con tu usuario.</p>

    <div class="el-pestanas el-no-imprimir" role="tablist" aria-label="Reportes disponibles" id="pestanas"></div>

    <section id="panel-rep" role="tabpanel" tabindex="0" aria-labelledby="">
        <!-- Encabezado que solo aparece al imprimir -->
        <div class="el-solo-impresion" id="encabezado-impresion"></div>

        <form id="f-rep" class="el-tarjeta el-no-imprimir" style="margin-bottom:1rem" aria-label="Filtros del reporte" novalidate>
            <div class="el-fila" id="f-rep-campos"></div>
            <div id="f-rep-resumen" class="el-resumen-error" tabindex="-1" hidden style="margin-top:.75rem"></div>
            <div class="el-fila" style="margin-top:.75rem">
                <button class="el-btn" type="submit">Ver reporte</button>
                <span style="flex:1"></span>
                <div role="group" aria-label="Exportar con los filtros aplicados" class="el-fila">
                    <button type="button" class="el-btn el-btn--sec" data-exportar="xlsx">Excel</button>
                    <button type="button" class="el-btn el-btn--sec" data-exportar="pdf">PDF</button>
                    <button type="button" class="el-btn el-btn--sec" data-exportar="csv">CSV</button>
                    <button type="button" class="el-btn el-btn--sec" id="btn-imprimir">Imprimir</button>
                </div>
            </div>
            <p class="el-ayuda" id="rep-estado" aria-live="polite" style="margin:.5rem 0 0"></p>
        </form>

        <div id="rep-nota" class="el-tarjeta" hidden style="margin-bottom:1rem"></div>
        <div id="rep-tabla" aria-live="polite"></div>
        <nav class="el-paginacion" id="rep-paginas" aria-label="Paginación del reporte"></nav>
    </section>
</div>
<script type="module" src="<?= e(asset('js/app/reportes.js')) ?>?v=<?= (int) @filemtime(ASSETS_DIR . '/js/app/reportes.js') ?>"></script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
