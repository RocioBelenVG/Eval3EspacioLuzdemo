/**
 * Espacio Luz Demo · núcleo de los módulos nuevos del panel (ES modules, sin framework).
 * - Cliente de la API REST /api/v1 con JWT: el token se obtiene canjeando la sesión del
 *   panel (POST /auth/sesion con el token CSRF) y se guarda solo en sessionStorage.
 * - Errores con el formato estándar de la API → ApiError (status, code, message, details).
 * - Utilidades de interfaz accesibles: anuncios ARIA, tablas apilables, paginación,
 *   estados de carga / vacío / error y diálogo modal con foco atrapado y cierre con Esc.
 */

const CFG = window.__PANEL__ || { base: '', csrf: '' };
const API = `${CFG.base}/api/v1`;
const CLAVE_TOKEN = 'el_jwt';

export class ApiError extends Error {
  constructor(status, cuerpo) {
    const e = (cuerpo && cuerpo.error) || {};
    super(e.message || `Error ${status}`);
    this.status = status;
    this.code = e.code || 'ERROR';
    this.details = e.details || [];
    this.requestId = e.request_id || '';
  }
}

let usuarioActual = null;

async function obtenerToken(forzar = false) {
  if (!forzar) {
    try {
      const g = JSON.parse(sessionStorage.getItem(CLAVE_TOKEN) || 'null');
      if (g && g.exp > Date.now() + 30000) { usuarioActual = g.usuario; return g.token; }
    } catch { /* almacenamiento no disponible */ }
  }
  const r = await fetch(`${API}/auth/sesion`, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': CFG.csrf, Accept: 'application/json' } });
  const cuerpo = await r.json().catch(() => null);
  if (!r.ok) throw new ApiError(r.status, cuerpo);
  usuarioActual = cuerpo.usuario;
  try { sessionStorage.setItem(CLAVE_TOKEN, JSON.stringify({ token: cuerpo.token, exp: Date.now() + cuerpo.expires_in * 1000, usuario: cuerpo.usuario })); } catch { /* sin almacenamiento */ }
  return cuerpo.token;
}

export async function usuario() {
  if (!usuarioActual) await obtenerToken();
  return usuarioActual;
}

function conQuery(ruta, query) {
  const q = new URLSearchParams();
  Object.entries(query || {}).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined && v !== false) q.set(k, v); });
  const s = q.toString();
  return `${API}${ruta}${s ? `?${s}` : ''}`;
}

/** Llama a la API. opciones: { metodo, cuerpo, query, blob } */
export async function api(ruta, { metodo = 'GET', cuerpo, query, blob = false } = {}, reintento = true) {
  const token = await obtenerToken();
  const r = await fetch(conQuery(ruta, query), {
    method: metodo,
    headers: { Authorization: `Bearer ${token}`, Accept: blob ? '*/*' : 'application/json', ...(cuerpo ? { 'Content-Type': 'application/json' } : {}) },
    body: cuerpo ? JSON.stringify(cuerpo) : undefined,
  });
  if (r.status === 401 && reintento) {
    try { sessionStorage.removeItem(CLAVE_TOKEN); } catch { /* */ }
    await obtenerToken(true);
    return api(ruta, { metodo, cuerpo, query, blob }, false);
  }
  if (blob && r.ok) {
    const disp = r.headers.get('Content-Disposition') || '';
    const nombre = (disp.match(/filename="([^"]+)"/) || [])[1] || 'reporte';
    return { blob: await r.blob(), nombre, filas: r.headers.get('X-Filas') };
  }
  const datos = r.status === 204 ? null : await r.json().catch(() => null);
  if (!r.ok) throw new ApiError(r.status, datos);
  return datos;
}

/* ---------------------------------------------------------------- formato */
export const clp = (n) => (n === null || n === undefined || n === '') ? '—' : '$' + Math.round(Number(n)).toLocaleString('es-CL');
export const num = (n, d = 0) => (n === null || n === undefined) ? '—' : Number(n).toLocaleString('es-CL', { minimumFractionDigits: d, maximumFractionDigits: d });
export const fecha = (s) => { if (!s) return '—'; const [a, m, d] = String(s).slice(0, 10).split('-'); return `${d}-${m}-${a}`; };
export const hoyISO = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
export const sumarDias = (iso, n) => { const d = new Date(`${iso}T12:00:00`); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); };
export const ESTADOS = { pendiente: 'Pendiente', confirmada: 'Confirmada', realizada: 'Realizada', cancelada: 'Cancelada', no_show: 'No asistió' };
export const chipEstado = (e) => `<span class="el-chip" data-estado="${esc(e)}">${esc(ESTADOS[e] || e)}</span>`;

export function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/* ---------------------------------------------------------------- anuncios ARIA */
let vivo;
export function anunciar(msg, urgente = false) {
  if (!vivo) {
    vivo = document.createElement('div');
    vivo.className = 'el-sr';
    vivo.setAttribute('aria-live', 'polite');
    vivo.setAttribute('role', 'status');
    document.body.appendChild(vivo);
  }
  vivo.setAttribute('aria-live', urgente ? 'assertive' : 'polite');
  vivo.textContent = '';
  setTimeout(() => { vivo.textContent = msg; }, 60);
}

/** Muestra un aviso breve (además lo anuncia). tipo: ok | error */
export function aviso(msg, tipo = 'ok') {
  anunciar(msg, tipo === 'error');
  const t = document.createElement('div');
  t.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
  t.className = 'el-no-imprimir';
  t.style.cssText = `position:fixed;right:1rem;bottom:1rem;z-index:90;max-width:min(420px,calc(100vw - 2rem));padding:.85rem 1rem;border-radius:.75rem;font:600 .9rem var(--f-cuerpo);color:#fff;background:${tipo === 'error' ? 'var(--c-error)' : 'var(--c-exito)'};box-shadow:var(--sombra)`;
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 5000);
}

/* ---------------------------------------------------------------- estados de contenedor */
export function cargando(el, filas = 5) {
  el.setAttribute('aria-busy', 'true');
  el.innerHTML = `<p class="el-sr">Cargando…</p>${'<div class="el-esqueleto"></div>'.repeat(filas)}`;
}
export function vacio(el, msg = 'No hay registros con estos filtros.') {
  el.removeAttribute('aria-busy');
  el.innerHTML = `<div class="el-vacio"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3 7h18M5 7l1 13h12l1-13M9 11v5M15 11v5M9 4h6"/></svg><p>${esc(msg)}</p></div>`;
}
export function fallo(el, err, reintentar) {
  el.removeAttribute('aria-busy');
  const msg = err instanceof ApiError ? err.message : 'No se pudo conectar con el servidor.';
  el.innerHTML = `<div class="el-fallo" role="alert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16.5v.5"/></svg>
    <p><strong>No se pudieron cargar los datos.</strong> ${esc(msg)}</p>${err?.requestId ? `<p class="el-ayuda">Código de soporte: ${esc(err.requestId)}</p>` : ''}
    ${reintentar ? '<button type="button" class="el-btn el-btn--sec">Reintentar</button>' : ''}</div>`;
  if (reintentar) el.querySelector('button').addEventListener('click', reintentar);
}

/* ---------------------------------------------------------------- tabla + paginación */
/**
 * columnas: [{ clave, etiqueta, num?, render?(fila), orden? }]
 * opciones: { caption, orden, alOrdenar(clave), pie?: {clave: valor} }
 */
export function tabla(el, columnas, filas, opciones = {}) {
  el.removeAttribute('aria-busy');
  if (!filas.length) return vacio(el, opciones.vacio);
  const th = columnas.map((c) => {
    const ord = opciones.orden && c.orden ? (opciones.orden === c.orden ? 'ascending' : opciones.orden === `-${c.orden}` ? 'descending' : 'none') : null;
    const contenido = ord ? `<button type="button" class="el-orden" data-orden="${esc(c.orden)}">${esc(c.etiqueta)} <span aria-hidden="true">${ord === 'ascending' ? '▲' : ord === 'descending' ? '▼' : '↕'}</span></button>` : esc(c.etiqueta);
    return `<th scope="col"${ord ? ` aria-sort="${ord}"` : ''}${c.num ? ' class="num"' : ''}>${contenido}</th>`;
  }).join('');
  const tr = filas.map((f) => `<tr>${columnas.map((c, i) => {
    const v = c.render ? c.render(f) : esc(f[c.clave] ?? '—');
    return i === 0 ? `<th scope="row" data-label="${esc(c.etiqueta)}" style="font-weight:600;text-transform:none;letter-spacing:0;background:none;white-space:normal">${v}</th>`
      : `<td data-label="${esc(c.etiqueta)}"${c.num ? ' class="num"' : ''}>${v}</td>`;
  }).join('')}</tr>`).join('');
  const pie = opciones.pie ? `<tfoot><tr>${columnas.map((c, i) => `<td data-label="${esc(c.etiqueta)}"${c.num ? ' class="num"' : ''}>${i === 0 ? 'Totales' : (opciones.pie[c.clave] ?? '')}</td>`).join('')}</tr></tfoot>` : '';
  el.innerHTML = `<div class="el-tabla-envoltura" tabindex="0" role="region" aria-label="${esc(opciones.caption || 'Tabla')} (desplazable)">
    <table class="el-tabla el-tabla--apilable"><caption>${esc(opciones.caption || '')}</caption><thead><tr>${th}</tr></thead><tbody>${tr}</tbody>${pie}</table></div>`;
  el.querySelectorAll('.el-orden').forEach((b) => b.addEventListener('click', () => {
    const c = b.dataset.orden;
    opciones.alOrdenar?.(opciones.orden === c && !opciones.sinDescendente ? `-${c}` : c);
  }));
}

export function paginacion(el, meta, alCambiar) {
  if (!meta || meta.total_pages <= 1) { el.innerHTML = meta ? `<span>${num(meta.total)} registros</span>` : ''; return; }
  el.innerHTML = `<span>Página ${num(meta.page)} de ${num(meta.total_pages)} · ${num(meta.total)} registros</span>
    <span class="el-fila"><button type="button" class="el-btn el-btn--sec el-btn--chico" data-p="${meta.page - 1}" ${meta.page <= 1 ? 'disabled' : ''}>← Anterior</button>
    <button type="button" class="el-btn el-btn--sec el-btn--chico" data-p="${meta.page + 1}" ${meta.page >= meta.total_pages ? 'disabled' : ''}>Siguiente →</button></span>`;
  el.querySelectorAll('button[data-p]').forEach((b) => b.addEventListener('click', () => alCambiar(Number(b.dataset.p))));
}

/* ---------------------------------------------------------------- formularios */
/** Lee un formulario a objeto (los vacíos quedan fuera). */
export function leerFormulario(form) {
  const o = {};
  new FormData(form).forEach((v, k) => { if (String(v).trim() !== '') o[k] = String(v).trim(); });
  return o;
}

/** Pinta errores por campo (aria-invalid + aria-describedby) y un resumen enfocable. */
export function mostrarErrores(form, errores, resumen) {
  form.querySelectorAll('[aria-invalid="true"]').forEach((i) => i.removeAttribute('aria-invalid'));
  form.querySelectorAll('.el-error').forEach((e) => { e.textContent = ''; });
  if (!errores.length) { if (resumen) { resumen.hidden = true; resumen.innerHTML = ''; } return; }
  errores.forEach(({ field, message }) => {
    const input = form.elements.namedItem(field);
    const caja = form.querySelector(`#err-${CSS.escape(form.id)}-${CSS.escape(field)}`);
    if (input && input.setAttribute) input.setAttribute('aria-invalid', 'true');
    if (caja) caja.textContent = message;
  });
  if (resumen) {
    resumen.hidden = false;
    resumen.innerHTML = `<strong>Revisa ${errores.length === 1 ? 'el siguiente campo' : `los ${errores.length} campos marcados`}:</strong><ul>${errores.map((e) => `<li><a href="#${esc(form.id)}-${esc(e.field)}">${esc(e.message)}</a></li>`).join('')}</ul>`;
    resumen.focus();
  }
}

/* ---------------------------------------------------------------- diálogo */
/** Abre un <dialog> nativo (foco atrapado por el navegador, Esc cierra) y devuelve el foco al cerrar. */
export function abrirDialogo(dialogo) {
  const anterior = document.activeElement;
  dialogo.showModal();
  const primero = dialogo.querySelector('[autofocus], input, select, textarea, button:not(.cerrar)');
  primero?.focus();
  dialogo.addEventListener('close', () => anterior?.focus?.(), { once: true });
}

/** Descarga un Blob con su nombre. */
export function descargar({ blob, nombre }) {
  const url = URL.createObjectURL(blob);
  const a = Object.assign(document.createElement('a'), { href: url, download: nombre });
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
