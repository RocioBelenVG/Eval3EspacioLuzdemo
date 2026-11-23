/** Módulo Atenciones: listado con filtros/orden/paginación, alta con validación inmediata, flujo de estados y cobro. */
import { api, ApiError, usuario, tabla, paginacion, cargando, fallo, chipEstado, clp, fecha, esc, num, aviso, anunciar,
  leerFormulario, mostrarErrores, abrirDialogo, hoyISO, ESTADOS } from './nucleo.js';

const $ = (s) => document.querySelector(s);
const estado = { query: { sort: '-fecha', page: 1, per_page: 20 }, filas: [], yo: null };
const TIPOS = { evaluacion: 'Evaluación', intervencion: 'Intervención', orientacion_familiar: 'Orientación familiar', etapas_vida: 'Etapas de la vida', otro: 'Otra' };

/* ------------------------------------------------------------ catálogos */
async function catalogos() {
  const [profs, boxes] = await Promise.all([api('/profesionales'), api('/boxes')]);
  const opt = (v, t) => `<option value="${esc(v)}">${esc(t)}</option>`;
  const p = profs.data.filter((x) => Number(x.activo)).map((x) => opt(x.id, `${x.nombre} · ${x.especialidad}`)).join('');
  const b = boxes.data.filter((x) => Number(x.activo)).map((x) => opt(x.slug, `${x.nombre} (${clp(x.precio_hora)}/h)`)).join('');
  $('#f-filtros-profesional_id').insertAdjacentHTML('beforeend', p);
  $('#f-nueva-profesional_id').insertAdjacentHTML('beforeend', p);
  $('#f-filtros-box').insertAdjacentHTML('beforeend', b);
  $('#f-nueva-box').insertAdjacentHTML('beforeend', b);
}

/* ------------------------------------------------------------ listado */
async function cargar() {
  const lista = $('#lista');
  cargando(lista, 8);
  try {
    const r = await api('/atenciones', { query: estado.query });
    estado.filas = r.data;
    $('#resumen').textContent = `${num(r.meta.total)} atenciones encontradas.`;
    const puedeGestionar = ['admin', 'editor'].includes(estado.yo.rol);
    tabla(lista, [
      { clave: 'fecha', etiqueta: 'Fecha', orden: 'fecha', render: (a) => `${fecha(a.fecha)} <span class="el-ayuda" style="display:inline">${esc(a.hora || '')}</span>` },
      { clave: 'paciente', etiqueta: 'Paciente / uso', orden: 'paciente', render: (a) => a.uso === 'arriendo' ? '<em>Arriendo de box</em>' : esc(a.paciente) },
      { clave: 'profesional', etiqueta: 'Profesional', orden: 'profesional', render: (a) => `${esc(a.profesional)}<br><span class="el-ayuda" style="display:inline">${esc(a.especialidad || '')}</span>` },
      { clave: 'box', etiqueta: 'Box', orden: 'box' },
      { clave: 'tipo', etiqueta: 'Tipo', render: (a) => esc(TIPOS[a.tipo_atencion] || '—') },
      { clave: 'estado', etiqueta: 'Estado', orden: 'estado', render: (a) => chipEstado(a.estado) },
      { clave: 'precio_clp', etiqueta: 'Precio', num: true, orden: 'precio', render: (a) => clp(a.precio_clp) },
      { clave: 'cobrada', etiqueta: 'Cobro', render: (a) => Number(a.cobrada) ? '<span class="el-chip" data-estado="realizada">Cobrada</span>' : '—' },
      { clave: 'acciones', etiqueta: 'Acciones', render: (a) => `<button type="button" class="el-btn el-btn--sec el-btn--chico" data-ver="${a.id}"
          aria-label="${puedeGestionar || estado.yo.rol === 'pacientes' ? 'Gestionar' : 'Ver'} atención del ${fecha(a.fecha)} ${esc(a.hora || '')}">${puedeGestionar || estado.yo.rol === 'pacientes' ? 'Gestionar' : 'Ver'}</button>` },
    ], r.data, { caption: 'Atenciones', orden: estado.query.sort, alOrdenar: (o) => {
      estado.query.sort = o; estado.query.page = 1;
      const s = $('#f-filtros-sort'); if ([...s.options].some((x) => x.value === o)) s.value = o;
      cargar();
    } });
    lista.querySelectorAll('[data-ver]').forEach((b) => b.addEventListener('click', () => abrirAccion(Number(b.dataset.ver))));
    paginacion($('#paginas'), r.meta, (p) => { estado.query.page = p; cargar(); $('#lista').focus?.(); });
  } catch (e) {
    fallo(lista, e, cargar);
    if (e instanceof ApiError && e.status === 422) mostrarErrores($('#f-filtros'), e.details.map((d) => ({ ...d, field: d.field })), null);
  }
}

$('#f-filtros').addEventListener('submit', (ev) => {
  ev.preventDefault();
  const d = leerFormulario(ev.target);
  if (d.desde && d.hasta && d.desde > d.hasta) { aviso('La fecha «Hasta» debe ser posterior a «Desde».', 'error'); $('#f-filtros-hasta').focus(); return; }
  estado.query = { sort: estado.query.sort, per_page: 20, page: 1, ...d };
  cargar();
});
$('#f-filtros').addEventListener('reset', () => { estado.query = { sort: '-fecha', page: 1, per_page: 20 }; setTimeout(cargar, 0); });

/* ------------------------------------------------------------ nueva atención (validación inmediata) */
const reglas = {
  profesional_id: (v) => v ? '' : 'Elige un profesional.',
  box: (v) => v ? '' : 'Elige un box.',
  fecha: (v) => {
    if (!v) return 'Indica la fecha.';
    if (v < hoyISO()) return 'No se pueden agendar atenciones en fechas pasadas.';
    if (new Date(`${v}T12:00:00`).getDay() === 0) return 'El centro no atiende los domingos.';
    return '';
  },
  hora: (v) => {
    if (!v) return 'Indica la hora.';
    if (!/:(00|30)$/.test(v)) return 'Usa bloques de 30 minutos (por ejemplo 10:00 o 10:30).';
    if (v < '08:00' || v > '20:30') return 'Fuera del horario del centro (08:00 a 21:00).';
    return '';
  },
  duracion_min: (v) => (!v || (Number(v) >= 15 && Number(v) <= 480)) ? '' : 'La duración debe estar entre 15 y 480 minutos.',
  precio_clp: (v) => (!v || (Number(v) >= 0 && Number(v) <= 5000000)) ? '' : 'El precio debe estar entre $0 y $5.000.000.',
  tipo_atencion: (v, d) => (v && !d.paciente_id) ? 'El tipo de atención solo aplica a sesiones con paciente.' : '',
};
function validarCampo(form, nombre) {
  const d = leerFormulario(form);
  const msg = reglas[nombre]?.(d[nombre], d) || '';
  const input = form.elements.namedItem(nombre);
  const caja = $(`#err-f-nueva-${nombre}`);
  if (caja) caja.textContent = msg;
  if (input) msg ? input.setAttribute('aria-invalid', 'true') : input.removeAttribute('aria-invalid');
  return msg;
}
const fNueva = $('#f-nueva');
Object.keys(reglas).forEach((n) => {
  const i = fNueva.elements.namedItem(n);
  i?.addEventListener('blur', () => validarCampo(fNueva, n));
  i?.addEventListener('change', () => validarCampo(fNueva, n));
});

let busquedaT;
$('#buscar-paciente').addEventListener('input', (ev) => {
  clearTimeout(busquedaT);
  busquedaT = setTimeout(() => cargarPacientes(ev.target.value), 300);
});
async function cargarPacientes(q = '') {
  const sel = $('#f-nueva-paciente_id');
  try {
    const r = await api('/pacientes', { query: { q, activo: 1, per_page: 50, sort: 'nombre' } });
    sel.innerHTML = '<option value="">Sin paciente · arriendo de box</option>' + r.data.map((p) => `<option value="${p.id}">${esc(p.nombre)} · ${esc(p.rut || '')}</option>`).join('');
    anunciar(`${r.data.length} pacientes en la lista.`);
  } catch (e) { aviso(e.message, 'error'); }
}

$('#btn-nueva').addEventListener('click', () => {
  fNueva.reset();
  mostrarErrores(fNueva, [], $('#f-nueva-resumen'));
  cargarPacientes();
  abrirDialogo($('#dlg-nueva'));
});
fNueva.addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const errores = Object.keys(reglas).map((n) => ({ field: n, message: validarCampo(fNueva, n) })).filter((e) => e.message);
  mostrarErrores(fNueva, errores, $('#f-nueva-resumen'));
  if (errores.length) return;
  const d = leerFormulario(fNueva);
  ['profesional_id', 'paciente_id', 'duracion_min', 'precio_clp'].forEach((k) => { if (d[k] !== undefined) d[k] = Number(d[k]); });
  const btn = fNueva.querySelector('[type=submit]');
  btn.disabled = true; btn.textContent = 'Agendando…';
  try {
    const r = await api('/atenciones', { metodo: 'POST', cuerpo: d });
    $('#dlg-nueva').close();
    aviso(`Atención agendada para el ${fecha(r.data.fecha)} a las ${r.data.hora}.`);
    cargar();
  } catch (e) {
    if (e instanceof ApiError && e.details.length) mostrarErrores(fNueva, e.details, $('#f-nueva-resumen'));
    else mostrarErrores(fNueva, [{ field: '_', message: e.message }], $('#f-nueva-resumen'));
  } finally { btn.disabled = false; btn.textContent = 'Agendar'; }
});

/* ------------------------------------------------------------ gestionar una atención */
async function abrirAccion(id) {
  const cuerpo = $('#dlg-accion-cuerpo');
  const btn = $('#btn-accion');
  cargando(cuerpo, 4);
  btn.hidden = true;
  abrirDialogo($('#dlg-accion'));
  try {
    const { data: a } = await api(`/atenciones/${id}`);
    const rol = estado.yo.rol;
    let trans = a.transiciones;
    if (rol === 'pacientes') trans = trans.filter((t) => ['realizada', 'no_show'].includes(t));
    if (rol === 'gerencia') trans = [];
    const futura = a.fecha > hoyISO();
    const puedeCobrar = ['admin', 'editor'].includes(rol) && a.estado === 'realizada' && a.uso === 'sesion' && !Number(a.cobrada);
    $('#dlg-accion-t').textContent = `Atención del ${fecha(a.fecha)} · ${a.hora}`;
    cuerpo.innerHTML = `
      <div id="f-accion-resumen" class="el-resumen-error" tabindex="-1" hidden></div>
      <dl style="display:grid;grid-template-columns:auto 1fr;gap:.35rem 1rem;margin:0 0 1rem">
        <dt>Estado</dt><dd style="margin:0">${chipEstado(a.estado)}</dd>
        <dt>Paciente</dt><dd style="margin:0">${a.uso === 'arriendo' ? 'Arriendo de box' : esc(a.paciente)}</dd>
        <dt>Profesional</dt><dd style="margin:0">${esc(a.profesional)}</dd>
        <dt>Box</dt><dd style="margin:0">${esc(a.box)}</dd>
        <dt>Precio</dt><dd style="margin:0">${clp(a.precio_clp)}${Number(a.cobrada) ? ' · cobrada' : ''}</dd>
      </dl>
      ${trans.length ? `<fieldset class="el-campo" style="border:0;padding:0;margin:0 0 1rem"><legend>Cambiar estado a</legend>
        <div class="el-fila">${trans.map((t) => `<label style="display:flex;gap:.4rem;align-items:center;min-height:44px">
          <input type="radio" name="estado" value="${t}" ${futura && ['realizada', 'no_show'].includes(t) ? 'disabled aria-describedby="nota-futura"' : ''}> ${esc(ESTADOS[t])}</label>`).join('')}</div>
        ${futura ? '<p class="el-ayuda" id="nota-futura">«Realizada» y «No asistió» se habilitan cuando llega la fecha de la atención.</p>' : ''}
        <p class="el-error" id="err-f-accion-estado"></p></fieldset>
        <div class="el-campo"><label for="f-accion-motivo">Motivo (opcional)</label><input class="el-input" id="f-accion-motivo" name="motivo" maxlength="300"></div>`
        : `<p class="el-ayuda">${a.estado === 'realizada' || a.estado === 'no_show' ? 'Atención cerrada: ya no admite cambios de estado.' : 'Tu rol no puede cambiar el estado de esta atención.'}</p>`}
      ${puedeCobrar ? `<fieldset class="el-tarjeta" style="margin-top:1rem"><legend style="font-weight:700">Cobrar con boleta (demo)</legend>
        <div class="el-aviso-demo" role="note">Boleta de demostración: no se envía al SII ni tiene validez tributaria.</div>
        <div class="el-fila"><div class="el-campo"><label for="f-accion-medio_pago">Medio de pago</label>
          <select class="el-input" id="f-accion-medio_pago" name="medio_pago"><option value="transferencia">Transferencia</option><option value="tarjeta">Tarjeta</option><option value="efectivo">Efectivo</option><option value="otro">Otro</option></select></div>
          <label style="display:flex;gap:.4rem;align-items:center;min-height:44px"><input type="checkbox" name="pagado" value="1" checked> Pagada al emitir</label>
          <button type="button" class="el-btn" id="btn-cobrar">Emitir boleta demo</button></div></fieldset>` : ''}
      ${a.movimientos?.length ? `<h3 style="margin-top:1rem">Historial</h3><ul class="el-ayuda">${a.movimientos.map((m) => `<li>${fecha(m.creado_en)} · ${esc(m.accion)} · ${esc(m.usuario_nombre || '')} ${m.detalle ? '· ' + esc(m.detalle) : ''}</li>`).join('')}</ul>` : ''}`;
    cuerpo.removeAttribute('aria-busy');
    btn.hidden = !trans.length;
    btn.textContent = 'Guardar estado';
    $('#f-accion').onsubmit = async (ev) => {
      ev.preventDefault();
      const d = leerFormulario(ev.target);
      if (!d.estado) { mostrarErrores(ev.target, [{ field: 'estado', message: 'Elige el nuevo estado.' }], $('#f-accion-resumen')); return; }
      btn.disabled = true;
      try {
        await api(`/atenciones/${id}/estado`, { metodo: 'PATCH', cuerpo: { estado: d.estado, motivo: d.motivo || null } });
        $('#dlg-accion').close();
        aviso(`Estado actualizado a «${ESTADOS[d.estado]}».`);
        cargar();
      } catch (e) {
        mostrarErrores(ev.target, e.details?.length ? e.details : [{ field: 'estado', message: e.message }], $('#f-accion-resumen'));
      } finally { btn.disabled = false; }
    };
    $('#btn-cobrar')?.addEventListener('click', async (ev) => {
      const f = $('#f-accion');
      ev.target.disabled = true; ev.target.textContent = 'Emitiendo…';
      try {
        const r = await api(`/atenciones/${id}/boleta`, { metodo: 'POST', cuerpo: { medio_pago: f.elements.medio_pago.value, pagado: f.elements.pagado.checked } });
        $('#dlg-accion').close();
        aviso(`Boleta demo N° ${r.data.folio} emitida por ${clp(r.data.total_clp)}.`);
        cargar();
      } catch (e) {
        mostrarErrores(f, [{ field: 'medio_pago', message: e.message }], $('#f-accion-resumen'));
        ev.target.disabled = false; ev.target.textContent = 'Emitir boleta demo';
      }
    });
  } catch (e) { fallo(cuerpo, e); }
}
document.querySelectorAll('[data-cerrar]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));

/* ------------------------------------------------------------ inicio */
(async () => {
  try {
    estado.yo = await usuario();
    $('#btn-nueva').hidden = !['admin', 'editor'].includes(estado.yo.rol);
    await catalogos();
  } catch (e) { fallo($('#lista'), e); return; }
  cargar();
})();
