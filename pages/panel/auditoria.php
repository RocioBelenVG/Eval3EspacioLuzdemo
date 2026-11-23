<?php
/** Panel · Auditoría: quién creó o modificó qué y cuándo (panel, API y sistema), y registro de exportaciones. Solo Administración. */
declare(strict_types=1);

requireAdminPage();
$panelTitle = 'Auditoría';
$panelActive = 'auditoria';
$panelCss = ['css/app.css'];
include APP_ROOT . '/partials/panel/top.php';
?>
<div class="el-app">
    <p class="el-ayuda">Cada alta, cambio o eliminación en las entidades principales queda registrada por la base de datos (disparadores), venga del panel,
        de la API o de un proceso del sistema. Se muestran solo los campos que cambiaron.</p>
    <form id="f-aud" class="el-tarjeta" style="margin-bottom:1rem" aria-label="Filtrar el registro de auditoría">
        <div class="el-fila">
            <div class="el-campo"><label for="f-aud-tabla">Entidad</label>
                <select class="el-input" id="f-aud-tabla" name="tabla"><option value="">Todas</option>
                    <?php foreach (['reservas' => 'Atenciones', 'pacientes' => 'Pacientes', 'fichas' => 'Fichas', 'evoluciones' => 'Evoluciones', 'boletas' => 'Boletas y facturas',
                        'documento_pagos' => 'Pagos', 'notas_credito' => 'Notas de crédito', 'clientes' => 'Clientes', 'profesionales' => 'Profesionales', 'usuarios' => 'Usuarios',
                        'boxes' => 'Boxes', 'empresas' => 'Empresas', 'productos' => 'Productos'] as $k => $v): ?>
                        <option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="el-campo"><label for="f-aud-accion">Acción</label>
                <select class="el-input" id="f-aud-accion" name="accion"><option value="">Todas</option><option value="crear">Crear</option><option value="modificar">Modificar</option><option value="eliminar">Eliminar</option></select></div>
            <div class="el-campo"><label for="f-aud-origen">Origen</label>
                <select class="el-input" id="f-aud-origen" name="origen"><option value="">Todos</option><option value="panel">Panel</option><option value="api">API</option><option value="sistema">Sistema</option></select></div>
            <div class="el-campo"><label for="f-aud-registro_id">ID del registro</label><input class="el-input" id="f-aud-registro_id" name="registro_id" inputmode="numeric" maxlength="40"></div>
            <div class="el-campo"><label for="f-aud-desde">Desde</label><input class="el-input" type="date" id="f-aud-desde" name="desde"></div>
            <div class="el-campo"><label for="f-aud-hasta">Hasta</label><input class="el-input" type="date" id="f-aud-hasta" name="hasta"></div>
            <div class="el-campo" style="flex:0 0 auto"><button class="el-btn" type="submit">Filtrar</button></div>
        </div>
    </form>
    <div id="aud-lista" aria-live="polite"></div>
    <nav class="el-paginacion" id="aud-paginas" aria-label="Paginación de la auditoría"></nav>

    <section class="el-tarjeta" style="margin-top:1.5rem" aria-labelledby="t-exp">
        <h2 id="t-exp">Últimas exportaciones de reportes</h2>
        <div id="exp-lista"></div>
    </section>
</div>
<script type="module">
import { api, tabla, paginacion, cargando, fallo, esc, fecha, leerFormulario } from '<?= e(asset('js/app/nucleo.js')) ?>';
const $ = (s) => document.querySelector(s);
let query = { page: 1, per_page: 25 };
const cambios = (f) => {
    if (f.accion === 'crear') return '<em>Registro creado</em>';
    if (f.accion === 'eliminar') return '<em>Registro eliminado</em>';
    const c = Object.entries(f.cambios || {}).filter(([k]) => !['actualizado_en', 'updated_at'].includes(k));
    return c.length ? `<ul style="margin:0;padding-left:1rem">${c.slice(0, 6).map(([k, v]) => `<li><strong>${esc(k)}</strong>: ${esc(v.antes ?? '∅')} → ${esc(v.despues ?? '∅')}</li>`).join('')}</ul>` : '—';
};
async function cargar() {
    cargando($('#aud-lista'), 6);
    try {
        const r = await api('/auditoria', { query });
        tabla($('#aud-lista'), [
            { clave: 'creado_en', etiqueta: 'Fecha y hora', render: (f) => `${fecha(f.creado_en)} ${esc(String(f.creado_en).slice(11, 19))}` },
            { clave: 'usuario_nombre', etiqueta: 'Usuario', render: (f) => esc(f.usuario_nombre || 'Sistema') },
            { clave: 'origen', etiqueta: 'Origen' },
            { clave: 'tabla', etiqueta: 'Entidad', render: (f) => `${esc(f.tabla)} #${esc(f.registro_id)}` },
            { clave: 'accion', etiqueta: 'Acción' },
            { clave: 'cambios', etiqueta: 'Cambios', render: cambios },
        ], r.data, { caption: 'Registro de auditoría', vacio: 'No hay cambios registrados con estos filtros.' });
        paginacion($('#aud-paginas'), r.meta, (p) => { query.page = p; cargar(); });
    } catch (e) { fallo($('#aud-lista'), e, cargar); }
}
$('#f-aud').addEventListener('submit', (ev) => { ev.preventDefault(); query = { ...leerFormulario(ev.target), page: 1, per_page: 25 }; cargar(); });
cargar();
api('/auditoria/exportaciones').then((r) => tabla($('#exp-lista'), [
    { clave: 'creado_en', etiqueta: 'Fecha', render: (f) => `${fecha(f.creado_en)} ${esc(String(f.creado_en).slice(11, 16))}` },
    { clave: 'usuario', etiqueta: 'Usuario' }, { clave: 'reporte', etiqueta: 'Reporte' }, { clave: 'formato', etiqueta: 'Formato', render: (f) => esc(String(f.formato).toUpperCase()) },
    { clave: 'filas', etiqueta: 'Filas', num: true }, { clave: 'filtros', etiqueta: 'Filtros', render: (f) => `<code style="font-size:.75rem">${esc(f.filtros)}</code>` },
], r.data, { caption: 'Exportaciones', vacio: 'Aún no se han exportado reportes.' })).catch((e) => fallo($('#exp-lista'), e));
</script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
