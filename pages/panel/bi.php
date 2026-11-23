<?php
/** Panel · Dashboard de análisis (BI): indicadores, gráficos accesibles y resumen con IA. Roles: Administración y Gerencia. */
declare(strict_types=1);

requireAuthPage();
if (!in_array(currentAdminRol(), ['admin', 'gerencia'], true)) {
    header('Location: ' . base_path('/admin/sin-permiso'));
    exit;
}
$panelTitle = 'Dashboard e indicadores';
$panelActive = 'bi';
$panelCss = ['css/app.css'];
include APP_ROOT . '/partials/panel/top.php';
?>
<div class="el-app" id="bi-app">
    <p class="el-ayuda">Indicadores y gráficos calculados en vivo desde la base de datos (vía la API). Cambia los filtros para analizar un período,
        una especialidad, un box o un profesional; cada indicador se compara con el período anterior de igual duración.</p>

    <form id="f-bi" class="el-tarjeta el-no-imprimir" aria-label="Filtros del dashboard" style="margin-bottom:1rem">
        <div class="el-fila">
            <div class="el-campo"><label for="f-bi-desde">Desde</label><input class="el-input" type="date" id="f-bi-desde" name="desde" required></div>
            <div class="el-campo"><label for="f-bi-hasta">Hasta</label><input class="el-input" type="date" id="f-bi-hasta" name="hasta" required></div>
            <div class="el-campo"><label for="f-bi-especialidad">Especialidad</label><select class="el-input" id="f-bi-especialidad" name="especialidad"><option value="">Todas</option></select></div>
            <div class="el-campo"><label for="f-bi-box">Box</label><select class="el-input" id="f-bi-box" name="box"><option value="">Todos</option></select></div>
            <div class="el-campo"><label for="f-bi-profesional_id">Profesional</label><select class="el-input" id="f-bi-profesional_id" name="profesional_id"><option value="">Todos</option></select></div>
            <div class="el-campo" style="flex:0 0 auto"><button class="el-btn" type="submit">Aplicar filtros</button></div>
        </div>
        <div class="el-fila" style="margin-top:.75rem" role="group" aria-label="Períodos rápidos">
            <button type="button" class="el-btn el-btn--sec el-btn--chico" data-rango="30">Últimos 30 días</button>
            <button type="button" class="el-btn el-btn--sec el-btn--chico" data-rango="90">Últimos 3 meses</button>
            <button type="button" class="el-btn el-btn--sec el-btn--chico" data-rango="183">Últimos 6 meses</button>
            <button type="button" class="el-btn el-btn--sec el-btn--chico" data-rango="365">Últimos 12 meses</button>
            <span class="el-ayuda" id="bi-estado" aria-live="polite" style="margin:0 0 0 auto"></span>
        </div>
        <p class="el-error" id="err-f-bi" role="alert"></p>
    </form>

    <section aria-labelledby="t-ind">
        <h2 id="t-ind">Indicadores del período</h2>
        <div id="kpis" class="el-kpis" aria-live="polite"></div>
    </section>

    <section class="el-tarjeta" aria-labelledby="t-narr" style="margin-bottom:1rem">
        <h2 id="t-narr">Lectura del período</h2>
        <div id="narrativa" class="el-ayuda" style="margin:0"></div>
    </section>

    <div class="el-rejilla el-rejilla--2" id="graficos"></div>

    <section class="el-tarjeta el-no-imprimir" aria-labelledby="t-ia" style="margin-top:1rem">
        <h2 id="t-ia">Resumen ejecutivo con IA</h2>
        <p class="el-ayuda">Envía solo los indicadores agregados del período (sin nombres ni datos de pacientes) a un modelo de lenguaje externo y recibe un
            resumen con hallazgos y recomendaciones. Revisa siempre el resultado antes de tomar decisiones.</p>
        <div class="el-fila">
            <div class="el-campo" style="flex:0 1 260px"><label for="ia-enfoque">Enfoque</label>
                <select class="el-input" id="ia-enfoque"><option value="general">Desempeño general</option><option value="ocupacion">Ocupación y asistencia</option><option value="cobranza">Ingresos y cobranza</option></select></div>
            <div class="el-campo" style="flex:0 0 auto"><button type="button" class="el-btn" id="btn-ia">Generar resumen</button></div>
        </div>
        <div id="ia-resultado" aria-live="polite" style="margin-top:1rem"></div>
    </section>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>
<script type="module" src="<?= e(asset('js/app/bi.js')) ?>?v=<?= (int) @filemtime(ASSETS_DIR . '/js/app/bi.js') ?>"></script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
