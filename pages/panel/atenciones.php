<?php
/** Panel · Atenciones (módulo nuevo, consume la API REST /api/v1 con JWT). */
declare(strict_types=1);

requireAuthPage();
$panelTitle = 'Atenciones';
$panelActive = 'atenciones';
$panelCss = ['css/app.css'];
include APP_ROOT . '/partials/panel/top.php';
$hoy = date('Y-m-d');
?>
<div class="el-app" id="atenciones-app">
    <p class="el-ayuda">Agenda de sesiones y arriendos de box. Busca, filtra y ordena; cambia el estado siguiendo el flujo
        <em>pendiente → confirmada → realizada / no asistió</em> o cancela; y cobra las sesiones realizadas con una boleta de demostración.</p>

    <form id="f-filtros" class="el-tarjeta el-no-imprimir" role="search" aria-label="Filtrar atenciones" style="margin-bottom:1rem">
        <div class="el-fila">
            <div class="el-campo" style="flex-basis:220px">
                <label for="f-filtros-q">Buscar</label>
                <input class="el-input" id="f-filtros-q" name="q" type="search" placeholder="Paciente, profesional o código" maxlength="80" autocomplete="off">
            </div>
            <div class="el-campo"><label for="f-filtros-estado">Estado</label>
                <select class="el-input" id="f-filtros-estado" name="estado"><option value="">Todos</option>
                    <option value="pendiente">Pendiente</option><option value="confirmada">Confirmada</option><option value="realizada">Realizada</option>
                    <option value="cancelada">Cancelada</option><option value="no_show">No asistió</option></select></div>
            <div class="el-campo"><label for="f-filtros-desde">Desde</label><input class="el-input" id="f-filtros-desde" name="desde" type="date"></div>
            <div class="el-campo"><label for="f-filtros-hasta">Hasta</label><input class="el-input" id="f-filtros-hasta" name="hasta" type="date"></div>
            <div class="el-campo"><label for="f-filtros-profesional_id">Profesional</label><select class="el-input" id="f-filtros-profesional_id" name="profesional_id"><option value="">Todos</option></select></div>
            <div class="el-campo"><label for="f-filtros-box">Box</label><select class="el-input" id="f-filtros-box" name="box"><option value="">Todos</option></select></div>
            <div class="el-campo"><label for="f-filtros-uso">Uso</label>
                <select class="el-input" id="f-filtros-uso" name="uso"><option value="">Sesiones y arriendos</option><option value="sesion">Solo sesiones</option><option value="arriendo">Solo arriendos</option></select></div>
            <div class="el-campo"><label for="f-filtros-sort">Ordenar por</label>
                <select class="el-input" id="f-filtros-sort" name="sort">
                    <option value="-fecha">Fecha (más reciente)</option><option value="fecha">Fecha (más antigua)</option>
                    <option value="paciente">Paciente (A–Z)</option><option value="profesional">Profesional (A–Z)</option>
                    <option value="box">Box (A–Z)</option><option value="estado">Estado</option><option value="precio">Precio (mayor)</option></select></div>
            <div class="el-campo" style="flex:0 0 auto;flex-direction:row;gap:.5rem">
                <button class="el-btn" type="submit">Filtrar</button>
                <button class="el-btn el-btn--sec" type="reset">Limpiar</button>
            </div>
        </div>
    </form>

    <div class="el-barra el-no-imprimir">
        <p id="resumen" class="el-ayuda" style="margin:0" aria-live="polite"></p>
        <button type="button" class="el-btn" id="btn-nueva" hidden>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> Nueva atención</button>
    </div>
    <div id="lista" aria-live="polite"></div>
    <nav class="el-paginacion" id="paginas" aria-label="Paginación de atenciones"></nav>

    <!-- Nueva atención -->
    <dialog class="el-dialogo" id="dlg-nueva" aria-labelledby="dlg-nueva-t">
        <form id="f-nueva" novalidate>
            <div class="cabeza"><h2 id="dlg-nueva-t">Nueva atención</h2>
                <button type="button" class="el-btn el-btn--sec el-btn--chico cerrar" data-cerrar aria-label="Cerrar">✕</button></div>
            <div class="cuerpo">
                <div id="f-nueva-resumen" class="el-resumen-error" tabindex="-1" hidden></div>
                <p class="el-ayuda">Los campos con <span class="el-req" aria-hidden="true">*</span><span class="el-sr">asterisco</span> son obligatorios.
                    El centro atiende de lunes a sábado; las horas se reservan en bloques de 30 minutos.</p>
                <div class="el-fila">
                    <div class="el-campo"><label for="f-nueva-profesional_id">Profesional <span class="el-req" aria-hidden="true">*</span></label>
                        <select class="el-input" id="f-nueva-profesional_id" name="profesional_id" required aria-describedby="err-f-nueva-profesional_id"><option value="">Selecciona…</option></select>
                        <p class="el-error" id="err-f-nueva-profesional_id"></p></div>
                    <div class="el-campo"><label for="f-nueva-box">Box <span class="el-req" aria-hidden="true">*</span></label>
                        <select class="el-input" id="f-nueva-box" name="box" required aria-describedby="err-f-nueva-box"><option value="">Selecciona…</option></select>
                        <p class="el-error" id="err-f-nueva-box"></p></div>
                </div>
                <div class="el-campo" style="margin-top:.75rem"><label for="f-nueva-paciente_id">Paciente (vacío = arriendo de box)</label>
                    <select class="el-input" id="f-nueva-paciente_id" name="paciente_id" aria-describedby="err-f-nueva-paciente_id ayuda-paciente"><option value="">Sin paciente · arriendo de box</option></select>
                    <p class="el-ayuda" id="ayuda-paciente" style="margin:0">Escribe en «Buscar paciente» para acotar la lista.</p>
                    <input class="el-input" type="search" id="buscar-paciente" aria-label="Buscar paciente por nombre o RUT" placeholder="Buscar paciente por nombre o RUT…">
                    <p class="el-error" id="err-f-nueva-paciente_id"></p></div>
                <div class="el-fila" style="margin-top:.75rem">
                    <div class="el-campo"><label for="f-nueva-fecha">Fecha <span class="el-req" aria-hidden="true">*</span></label>
                        <input class="el-input" id="f-nueva-fecha" name="fecha" type="date" min="<?= e($hoy) ?>" required aria-describedby="err-f-nueva-fecha">
                        <p class="el-error" id="err-f-nueva-fecha"></p></div>
                    <div class="el-campo"><label for="f-nueva-hora">Hora <span class="el-req" aria-hidden="true">*</span></label>
                        <input class="el-input" id="f-nueva-hora" name="hora" type="time" step="1800" min="08:00" max="20:30" required aria-describedby="err-f-nueva-hora">
                        <p class="el-error" id="err-f-nueva-hora"></p></div>
                    <div class="el-campo"><label for="f-nueva-duracion_min">Duración (min)</label>
                        <input class="el-input" id="f-nueva-duracion_min" name="duracion_min" type="number" min="15" max="480" step="15" value="60" inputmode="numeric" aria-describedby="err-f-nueva-duracion_min">
                        <p class="el-error" id="err-f-nueva-duracion_min"></p></div>
                </div>
                <div class="el-fila" style="margin-top:.75rem">
                    <div class="el-campo"><label for="f-nueva-tipo_atencion">Tipo de atención</label>
                        <select class="el-input" id="f-nueva-tipo_atencion" name="tipo_atencion" aria-describedby="err-f-nueva-tipo_atencion"><option value="">—</option>
                            <option value="evaluacion">Evaluación</option><option value="intervencion">Intervención</option><option value="orientacion_familiar">Orientación familiar</option>
                            <option value="etapas_vida">Etapas de la vida</option><option value="otro">Otra</option></select>
                        <p class="el-error" id="err-f-nueva-tipo_atencion"></p></div>
                    <div class="el-campo"><label for="f-nueva-precio_clp">Precio (CLP)</label>
                        <input class="el-input" id="f-nueva-precio_clp" name="precio_clp" type="number" min="0" max="5000000" step="500" inputmode="numeric" aria-describedby="err-f-nueva-precio_clp">
                        <p class="el-error" id="err-f-nueva-precio_clp"></p></div>
                </div>
                <div class="el-campo" style="margin-top:.75rem"><label for="f-nueva-notas">Notas internas</label>
                    <textarea class="el-input" id="f-nueva-notas" name="notas" maxlength="500" aria-describedby="err-f-nueva-notas"></textarea>
                    <p class="el-error" id="err-f-nueva-notas"></p></div>
            </div>
            <div class="pie"><button type="button" class="el-btn el-btn--sec" data-cerrar>Cancelar</button><button type="submit" class="el-btn">Agendar</button></div>
        </form>
    </dialog>

    <!-- Cambiar estado / cobrar -->
    <dialog class="el-dialogo" id="dlg-accion" aria-labelledby="dlg-accion-t">
        <form id="f-accion" novalidate>
            <div class="cabeza"><h2 id="dlg-accion-t">Atención</h2>
                <button type="button" class="el-btn el-btn--sec el-btn--chico cerrar" data-cerrar aria-label="Cerrar">✕</button></div>
            <div class="cuerpo" id="dlg-accion-cuerpo"></div>
            <div class="pie"><button type="button" class="el-btn el-btn--sec" data-cerrar>Cerrar</button><button type="submit" class="el-btn" id="btn-accion">Guardar</button></div>
        </form>
    </dialog>
</div>
<script type="module" src="<?= e(asset('js/app/atenciones.js')) ?>?v=<?= (int) @filemtime(ASSETS_DIR . '/js/app/atenciones.js') ?>"></script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
