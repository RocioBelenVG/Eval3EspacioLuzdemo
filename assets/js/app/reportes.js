/** Reportes: pestañas, filtros por reporte, tabla paginada con totales, exportación (blob, sin bloquear) e impresión. */
import { api, ApiError, usuario, tabla, paginacion, cargando, fallo, clp, num, fecha, esc, aviso, anunciar, leerFormulario,
  mostrarErrores, descargar, hoyISO, sumarDias, chipEstado } from './nucleo.js';

const $ = (s) => document.querySelector(s);
const hoy = hoyISO();
const OPC = { estados: '', profesionales: '', boxes: '', especialidades: '' };

const campo = (id, etiqueta, control, req = false) => `<div class="el-campo"><label for="f-rep-${id}">${etiqueta}${req ? ' <span class="el-req" aria-hidden="true">*</span>' : ''}</label>${control}<p class="el-error" id="err-f-rep-${id}"></p></div>`;
const fechaC = (id, etq, valor, req = false) => campo(id, etq, `<input class="el-input" type="date" id="f-rep-${id}" name="${id}" value="${valor}" ${req ? 'required' : ''} aria-describedby="err-f-rep-${id}">`, req);
const sel = (id, etq, opciones) => campo(id, etq, `<select class="el-input" id="f-rep-${id}" name="${id}" aria-describedby="err-f-rep-${id}">${opciones}</select>`);

const DEF = {
  atenciones: {
    descripcion: 'Detalle de cada atención (operativo).',
    campos: () => fechaC('desde', 'Desde', sumarDias(hoy, -30)) + fechaC('hasta', 'Hasta', hoy)
      + sel('estado', 'Estado', '<option value="">Todos</option><option value="pendiente">Pendiente</option><option value="confirmada">Confirmada</option><option value="realizada">Realizada</option><option value="cancelada">Cancelada</option><option value="no_show">No asistió</option>')
      + sel('profesional_id', 'Profesional', `<option value="">Todos</option>${OPC.profesionales}`) + sel('box', 'Box', `<option value="">Todos</option>${OPC.boxes}`)
      + sel('especialidad', 'Especialidad', `<option value="">Todas</option>${OPC.especialidades}`)
      + sel('uso', 'Uso', '<option value="">Todos</option><option value="sesion">Sesiones</option><option value="arriendo">Arriendos</option>'),
    paginado: true,
  },
  gerencial: {
    descripcion: 'Resumen mensual con totales, indicadores y gráfico (gerencial).',
    campos: () => fechaC('desde', 'Desde', sumarDias(hoy, -364), true) + fechaC('hasta', 'Hasta', hoy, true)
      + sel('especialidad', 'Especialidad', `<option value="">Todas</option>${OPC.especialidades}`) + sel('box', 'Box', `<option value="">Todos</option>${OPC.boxes}`),
    paginado: false,
  },
  documentos: {
    descripcion: 'Boletas y facturas de demostración por rango de fechas (parametrizable).',
    campos: () => fechaC('desde', 'Desde', sumarDias(hoy, -90), true) + fechaC('hasta', 'Hasta', hoy, true)
      + sel('tipo', 'Tipo', '<option value="">Boletas y facturas</option><option value="boleta">Boletas</option><option value="factura">Facturas</option>')
      + sel('pago_estado', 'Estado de pago', '<option value="">Todos</option><option value="pagado">Pagado</option><option value="parcial">Abono parcial</option><option value="pendiente">Por cobrar</option>'),
    paginado: true,
  },
};

const estado = { clave: null, query: {}, columnas: [], titulo: '' };

function formatear(tipo, v) {
  if (v === null || v === undefined || v === '') return '—';
  switch (tipo) {
    case 'clp': return clp(v);
    case 'fecha': return fecha(v);
    case 'entero': return num(v);
    case 'decimal': return num(v, 1);
    case 'pct': return `${num(v, 1)} %`;
    default: return esc(v);
  }
}

function pintar(r) {
  estado.columnas = r.reporte.columnas;
  estado.titulo = r.reporte.titulo;
  const cols = r.reporte.columnas.map((c) => ({ clave: c.clave, etiqueta: c.etiqueta, num: ['clp', 'entero', 'decimal', 'pct'].includes(c.tipo),
    render: (f) => (c.clave === 'estado' && f.estado_clave) ? chipEstado(f.estado_clave) : formatear(c.tipo, f[c.clave]) }));
  const pie = {};
  Object.entries(r.totales || {}).forEach(([k, v]) => { const c = r.reporte.columnas.find((x) => x.clave === k); if (c) pie[k] = formatear(c.tipo, v); });
  if (estado.clave === 'atenciones' && r.totales) pie.precio_clp = clp(r.totales.precio_clp);
  tabla($('#rep-tabla'), cols, r.data, { caption: `${r.reporte.titulo}${r.meta.total_pages > 1 ? ` · página ${r.meta.page} de ${r.meta.total_pages}` : ''}`, pie: Object.keys(pie).length ? pie : null });
  paginacion($('#rep-paginas'), DEF[estado.clave].paginado ? r.meta : null, (p) => { estado.query.page = p; cargar(); });
  const nota = $('#rep-nota');
  if (r.nota || r.grafico) {
    nota.hidden = false;
    const g = r.grafico;
    const max = g ? Math.max(1, ...g.valores) : 1;
    nota.innerHTML = `${r.nota ? `<p class="el-ayuda" style="margin:0 0 .75rem">${esc(r.nota)}</p>` : ''}${g ? `<figure class="el-grafico" style="margin:0"><figcaption><h3>${esc(g.titulo)}</h3>
      <p class="el-ayuda" style="margin:0">Barras con el ingreso neto de cada mes; los valores exactos están en la columna «Ingresos netos» de la tabla.</p></figcaption>
      <div role="img" aria-label="${esc(g.titulo)}" style="display:flex;align-items:flex-end;gap:4px;height:160px;padding-top:1rem">${g.valores.map((v, i) => `
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;min-width:0">
          <span style="font-size:.65rem;white-space:nowrap">${num(v / 1e6, 1)}M</span>
          <div style="width:100%;background:var(--c-primario);height:${(100 * v / max).toFixed(1)}%;border-radius:3px 3px 0 0"></div>
          <span style="font-size:.65rem">${esc(g.etiquetas[i])}</span></div>`).join('')}</div></figure>` : ''}`;
  } else {
    nota.hidden = true;
  }
}

async function cargar() {
  cargando($('#rep-tabla'), 8);
  try {
    const r = await api(`/reportes/${estado.clave}`, { query: { ...estado.query, per_page: estado.query.per_page || 25 } });
    pintar(r);
    $('#rep-estado').textContent = `${num(r.meta.total)} filas con los filtros aplicados.`;
  } catch (e) {
    fallo($('#rep-tabla'), e, cargar);
    if (e instanceof ApiError && e.details.length) mostrarErrores($('#f-rep'), e.details, $('#f-rep-resumen'));
  }
}

function abrir(clave) {
  estado.clave = clave;
  document.querySelectorAll('#pestanas [role=tab]').forEach((t) => {
    const activo = t.dataset.clave === clave;
    t.setAttribute('aria-selected', String(activo));
    t.tabIndex = activo ? 0 : -1;
  });
  $('#panel-rep').setAttribute('aria-labelledby', `tab-${clave}`);
  $('#f-rep-campos').innerHTML = DEF[clave].campos();
  mostrarErrores($('#f-rep'), [], $('#f-rep-resumen'));
  estado.query = { ...leerFormulario($('#f-rep')), page: 1 };
  cargar();
}

function validar() {
  const d = leerFormulario($('#f-rep'));
  const errores = [];
  $('#f-rep').querySelectorAll('[required]').forEach((i) => { if (!i.value) errores.push({ field: i.name, message: 'Este reporte requiere un rango de fechas.' }); });
  if (d.desde && d.hasta && d.desde > d.hasta) errores.push({ field: 'hasta', message: 'La fecha final debe ser igual o posterior a la inicial.' });
  mostrarErrores($('#f-rep'), errores, $('#f-rep-resumen'));
  return errores.length ? null : d;
}

$('#f-rep').addEventListener('submit', (ev) => {
  ev.preventDefault();
  const d = validar();
  if (!d) return;
  estado.query = { ...d, page: 1 };
  cargar();
});
$('#f-rep').addEventListener('change', (ev) => { if (ev.target.type === 'date') validar(); });

document.querySelectorAll('[data-exportar]').forEach((b) => b.addEventListener('click', async () => {
  const d = validar();
  if (!d) return;
  const formato = b.dataset.exportar;
  const etiqueta = b.textContent;
  b.disabled = true;
  b.textContent = 'Generando…';
  $('#rep-estado').textContent = `Generando el archivo ${formato.toUpperCase()} en segundo plano; puedes seguir usando la página.`;
  try {
    const archivo = await api(`/reportes/${estado.clave}/export`, { query: { ...d, formato }, blob: true });
    descargar(archivo);
    aviso(`Descargado ${archivo.nombre} (${num(archivo.filas)} filas).`);
    $('#rep-estado').textContent = `Archivo ${archivo.nombre} listo.`;
  } catch (e) {
    aviso(e.message, 'error');
    $('#rep-estado').textContent = '';
    if (e instanceof ApiError && e.details.length) mostrarErrores($('#f-rep'), e.details, $('#f-rep-resumen'));
  } finally {
    b.disabled = false;
    b.textContent = etiqueta;
  }
}));

/** Impresión: carga hasta 1.000 filas con los filtros, arma el encabezado (título, fecha, filtros) y abre el diálogo del navegador. */
$('#btn-imprimir').addEventListener('click', async (ev) => {
  const d = validar();
  if (!d) return;
  ev.target.disabled = true;
  try {
    const yo = await usuario();
    let filas = [];
    let r;
    for (let p = 1; p <= 10; p++) {
      r = await api(`/reportes/${estado.clave}`, { query: { ...d, page: p, per_page: 100 } });
      filas = filas.concat(r.data);
      if (p >= r.meta.total_pages) break;
    }
    pintar({ ...r, data: filas, meta: { ...r.meta, page: 1, total_pages: 1 } });
    const filtros = Object.entries(d).map(([k, v]) => `${esc(k)}: ${esc(['desde', 'hasta'].includes(k) ? fecha(v) : v)}`).join(' · ') || 'ninguno';
    $('#encabezado-impresion').innerHTML = `<h2 style="margin:0">${esc(estado.titulo)}</h2>
      <p style="margin:.25rem 0">Espacio Luz Demo · emitido el ${new Date().toLocaleString('es-CL')} por ${esc(yo.nombre)} (${esc(yo.rol_etiqueta)})</p>
      <p style="margin:0 0 .5rem">Filtros: ${filtros}${r.meta.total > filas.length ? ` · se imprimen las primeras ${num(filas.length)} de ${num(r.meta.total)} filas (exporta a PDF para el total)` : ''}</p>`;
    anunciar('Vista de impresión lista.');
    window.print();
  } catch (e) {
    aviso(e.message, 'error');
  } finally {
    ev.target.disabled = false;
  }
});

/* Pestañas accesibles (flechas izquierda/derecha, Inicio y Fin). */
$('#pestanas').addEventListener('keydown', (ev) => {
  const tabs = [...document.querySelectorAll('#pestanas [role=tab]')];
  const i = tabs.indexOf(document.activeElement);
  if (i < 0) return;
  const destino = { ArrowRight: tabs[(i + 1) % tabs.length], ArrowLeft: tabs[(i - 1 + tabs.length) % tabs.length], Home: tabs[0], End: tabs[tabs.length - 1] }[ev.key];
  if (destino) { ev.preventDefault(); destino.focus(); abrir(destino.dataset.clave); }
});

(async () => {
  try {
    const [lista, profs, boxes, opc] = await Promise.all([api('/reportes'), api('/profesionales'), api('/boxes'), api('/opciones')]);
    OPC.profesionales = profs.data.map((p) => `<option value="${p.id}">${esc(p.nombre)}</option>`).join('');
    OPC.boxes = boxes.data.map((b) => `<option value="${esc(b.slug)}">${esc(b.nombre)}</option>`).join('');
    OPC.especialidades = opc.data.especialidades.map((e) => `<option value="${esc(e.slug)}">${esc(e.nombre)}</option>`).join('');
    $('#pestanas').innerHTML = lista.data.map((r) => `<button type="button" role="tab" id="tab-${r.clave}" data-clave="${r.clave}" aria-controls="panel-rep" aria-selected="false" tabindex="-1"
      title="${esc(DEF[r.clave].descripcion)}">${esc(r.titulo)}</button>`).join('');
    document.querySelectorAll('#pestanas [role=tab]').forEach((t) => t.addEventListener('click', () => abrir(t.dataset.clave)));
    const inicial = new URLSearchParams(location.search).get('r');
    abrir(lista.data.some((r) => r.clave === inicial) ? inicial : lista.data[0].clave);
  } catch (e) { fallo($('#rep-tabla'), e); }
})();
