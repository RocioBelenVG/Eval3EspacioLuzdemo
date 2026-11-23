/** Dashboard BI: indicadores, 6 visualizaciones con descripción y tabla de datos, narrativa y resumen con IA. */
import { api, ApiError, clp, num, esc, fecha, hoyISO, sumarDias, cargando, fallo, anunciar, leerFormulario } from './nucleo.js';

const $ = (s) => document.querySelector(s);
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const mesCorto = (ym) => { const [a, m] = ym.split('-'); return `${MESES[Number(m) - 1]} ${a.slice(2)}`; };
// Paleta categórica apta para daltonismo (Okabe–Ito) en tonos que contrastan con el fondo blanco.
const C = { azul: '#2D5B86', terracota: '#A4512F', verde: '#2F6B3F', ambar: '#B07A00', gris: '#6B5446', rojo: '#B3261E', morado: '#6A4C93' };
const graficos = [];
let filtros = {};

function valorInd(i, v = i.valor) {
  if (v === null || v === undefined) return 'Sin datos';
  if (i.unidad === 'CLP') return clp(v);
  if (i.unidad === '%') return `${num(v, 1)} %`;
  if (i.unidad === 'días') return `${num(v, 1)} días`;
  return `${num(v)} ${i.unidad}`;
}

function kpis(ind) {
  $('#kpis').innerHTML = ind.map((i) => {
    let delta = '';
    if (i.valor !== null && i.anterior !== null && i.anterior !== undefined && Number(i.anterior) !== 0) {
      const d = (Number(i.valor) - Number(i.anterior)) / Math.abs(Number(i.anterior)) * 100;
      const sube = d >= 0;
      const mejor = (sube && i.mejor === 'alto') || (!sube && i.mejor === 'bajo');
      delta = `<p class="delta" data-signo="${Math.abs(d) < 0.5 ? '' : mejor ? 'mejor' : 'peor'}">${sube ? '▲' : '▼'} ${num(Math.abs(d), 1)} % vs período anterior
        <span class="el-sr">(${mejor ? 'mejora' : 'empeora'}; antes: ${esc(valorInd(i, i.anterior))})</span></p>`;
    }
    return `<article class="el-kpi"><h3>${esc(i.nombre)}</h3><p class="valor">${esc(valorInd(i))}</p>${delta}
      <details><summary>Fórmula y pregunta</summary><p><strong>Pregunta:</strong> ${esc(i.pregunta)}</p><p><strong>Fórmula:</strong> ${esc(i.formula)}</p></details></article>`;
  }).join('');
}

/** Crea un gráfico con título, descripción textual y tabla de datos (WCAG: alternativa al gráfico). */
function figura(id, titulo, descripcion, columnas, filas, config, ancho = '') {
  const tabla = `<details><summary>Ver tabla de datos de «${esc(titulo)}»</summary><div class="el-tabla-envoltura" tabindex="0" role="region" aria-label="Datos de ${esc(titulo)}">
    <table class="el-tabla"><caption>${esc(titulo)}</caption><thead><tr>${columnas.map((c) => `<th scope="col"${c.num ? ' class="num"' : ''}>${esc(c.t)}</th>`).join('')}</tr></thead>
    <tbody>${filas.map((f) => `<tr>${f.map((v, k) => k === 0 ? `<th scope="row" style="background:none;text-transform:none;letter-spacing:0">${esc(v)}</th>` : `<td class="num">${esc(v)}</td>`).join('')}</tr>`).join('')}</tbody></table></div></details>`;
  const art = document.createElement('figure');
  art.className = `el-tarjeta el-grafico ${ancho}`;
  art.setAttribute('aria-labelledby', `${id}-t`);
  art.innerHTML = `<figcaption><h3 id="${id}-t">${esc(titulo)}</h3><p class="el-ayuda" id="${id}-d" style="margin:0">${esc(descripcion)}</p></figcaption>
    <div class="lienzo">${config ? `<canvas id="${id}" role="img" aria-labelledby="${id}-t" aria-describedby="${id}-d"></canvas>` : ''}</div>${tabla}`;
  $('#graficos').appendChild(art);
  if (config && window.Chart) {
    graficos.push(new window.Chart(document.getElementById(id), {
      ...config,
      options: {
        responsive: true, maintainAspectRatio: false, animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 400 },
        plugins: { legend: { position: 'bottom', labels: { color: '#3D2A21', font: { family: 'Inter, system-ui' } } } },
        ...(config.options || {}),
      },
    }));
  }
  return art;
}

function mapaCalor(datos) {
  const dias = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
  const horas = [...new Set(datos.map((d) => Number(d.hora)))].sort((a, b) => a - b);
  const max = Math.max(1, ...datos.map((d) => Number(d.reservas)));
  const v = (dia, h) => Number((datos.find((d) => Number(d.dia) === dia && Number(d.hora) === h) || {}).reservas || 0);
  const art = figura('g-mapa', 'Demanda por día de la semana y hora',
    'Cantidad de reservas vigentes en cada combinación de día y hora; el número aparece en cada celda y el color más oscuro indica más demanda.',
    [{ t: 'Hora' }, ...dias.map((d) => ({ t: d, num: true }))], horas.map((h) => [`${String(h).padStart(2, '0')}:00`, ...dias.map((_, i) => num(v(i + 1, h)))]), null, 'el-span-2');
  art.querySelector('.lienzo').style.height = 'auto';
  art.querySelector('.lienzo').innerHTML = `<div class="el-tabla-envoltura" style="border:0" tabindex="0" role="region" aria-label="Mapa de calor (desplazable)"><table class="el-mapa" aria-hidden="true"><thead><tr><td></td>${dias.map((d) => `<td><strong>${d}</strong></td>`).join('')}</tr></thead><tbody>
    ${horas.map((h) => `<tr><td><strong>${String(h).padStart(2, '0')}</strong></td>${dias.map((_, i) => { const n = v(i + 1, h); const a = n / max;
      return `<td style="background:rgba(164,81,47,${(0.08 + a * 0.85).toFixed(2)});color:${a > 0.55 ? '#fff' : '#3D2A21'}">${n || ''}</td>`; }).join('')}</tr>`).join('')}</tbody></table></div>`;
}

function narrativa(series, ind) {
  const m = series.mensual;
  const by = Object.fromEntries(ind.map((i) => [i.clave, i]));
  const partes = [];
  if (m.length) {
    const top = m.reduce((a, b) => (Number(b.realizadas) > Number(a.realizadas) ? b : a));
    const low = m.reduce((a, b) => (Number(b.realizadas) < Number(a.realizadas) ? b : a));
    partes.push(`El mes con más atenciones realizadas fue <strong>${mesCorto(top.mes)}</strong> (${num(top.realizadas)}) y el más bajo, <strong>${mesCorto(low.mes)}</strong> (${num(low.realizadas)}): la demanda es estacional y baja en vacaciones de verano.`);
  }
  if (series.boxes.length) {
    const b = [...series.boxes].sort((x, y) => Number(x.ocupacion) - Number(y.ocupacion))[0];
    partes.push(`El box con menor ocupación es <strong>${esc(b.box)}</strong> (${num(b.ocupacion, 1)} %): conviene ofrecer sus bloques libres a profesionales externos.`);
  }
  if (by.tasa_asistencia?.valor !== null && by.tasa_asistencia) partes.push(`La asistencia a sesiones es de <strong>${num(by.tasa_asistencia.valor, 1)} %</strong>${by.tasa_asistencia.anterior !== null ? ` (período anterior: ${num(by.tasa_asistencia.anterior, 1)} %)` : ''}; los recordatorios del día previo ayudan a reducir inasistencias.`);
  if (by.por_cobrar) partes.push(`Quedan <strong>${clp(by.por_cobrar.valor)}</strong> por cobrar; los clientes demoran en promedio <strong>${by.dias_pago?.valor !== null ? num(by.dias_pago.valor, 1) : '—'} días</strong> en pagar sus facturas.`);
  $('#narrativa').innerHTML = partes.map((p) => `<p>${p}</p>`).join('');
}

async function cargar() {
  graficos.splice(0).forEach((g) => g.destroy());
  $('#graficos').innerHTML = '';
  cargando($('#kpis'), 3);
  $('#bi-estado').textContent = 'Actualizando…';
  try {
    const [ind, ser] = await Promise.all([api('/dashboard/indicadores', { query: filtros }), api('/dashboard/series', { query: filtros })]);
    kpis(ind.data);
    const s = ser.data;
    const m = s.mensual;
    figura('g-mensual', 'Atenciones por mes según resultado',
      'Barras apiladas con las atenciones realizadas, canceladas y las inasistencias de cada mes del período.',
      [{ t: 'Mes' }, { t: 'Realizadas', num: true }, { t: 'Canceladas', num: true }, { t: 'No asistió', num: true }, { t: 'Asistencia %', num: true }],
      m.map((r) => [mesCorto(r.mes), num(r.realizadas), num(r.canceladas), num(r.no_show), r.tasa_asistencia === null ? '—' : num(r.tasa_asistencia, 1)]),
      { type: 'bar', data: { labels: m.map((r) => mesCorto(r.mes)), datasets: [
        { label: 'Realizadas', data: m.map((r) => Number(r.realizadas)), backgroundColor: C.verde },
        { label: 'Canceladas', data: m.map((r) => Number(r.canceladas)), backgroundColor: C.gris },
        { label: 'No asistió', data: m.map((r) => Number(r.no_show)), backgroundColor: C.rojo }] },
        options: { scales: { x: { stacked: true }, y: { stacked: true, title: { display: true, text: 'Atenciones' } } } } });
    figura('g-ingresos', 'Ingresos netos por mes',
      'Línea con el total facturado cada mes (boletas y facturas emitidas menos notas de crédito), en pesos chilenos.',
      [{ t: 'Mes' }, { t: 'Ingresos', num: true }, { t: 'Documentos', num: true }],
      m.map((r) => [mesCorto(r.mes), clp(r.ingresos), num(r.documentos)]),
      { type: 'line', data: { labels: m.map((r) => mesCorto(r.mes)), datasets: [{ label: 'Ingresos netos (CLP)', data: m.map((r) => Number(r.ingresos)), borderColor: C.terracota,
        backgroundColor: 'rgba(164,81,47,.15)', fill: true, tension: 0.25, pointRadius: 3 }] },
        options: { scales: { y: { ticks: { callback: (v) => `$${(v / 1e6).toLocaleString('es-CL')}M` } } } } });
    const esp = s.especialidades;
    figura('g-especialidad', 'Horas de box por especialidad',
      'Barras horizontales con las horas reservadas (no canceladas) por especialidad del profesional, ordenadas de mayor a menor.',
      [{ t: 'Especialidad' }, { t: 'Horas', num: true }, { t: 'Realizadas', num: true }], esp.map((r) => [r.especialidad, num(r.horas, 1), num(r.realizadas)]),
      { type: 'bar', data: { labels: esp.map((r) => r.especialidad), datasets: [{ label: 'Horas', data: esp.map((r) => Number(r.horas)), backgroundColor: C.azul }] },
        options: { indexAxis: 'y', plugins: { legend: { display: false } } } });
    const bx = s.boxes;
    figura('g-boxes', 'Ocupación por box',
      'Porcentaje de las horas de apertura del período en que cada box tuvo al menos una reserva vigente.',
      [{ t: 'Box' }, { t: 'Ocupación %', num: true }, { t: 'Horas ocupadas', num: true }, { t: 'Sesiones', num: true }, { t: 'Arriendos', num: true }],
      bx.map((r) => [r.box, num(r.ocupacion, 1), num(r.horas_ocupadas), num(r.sesiones), num(r.arriendos)]),
      { type: 'bar', data: { labels: bx.map((r) => r.box), datasets: [{ label: 'Ocupación (%)', data: bx.map((r) => Number(r.ocupacion)), backgroundColor: C.terracota }] },
        options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { min: 0, max: 100, title: { display: true, text: '%' } } } } });
    const es = s.estados.filter((e) => e.n > 0);
    figura('g-estados', 'Distribución de las atenciones por estado',
      'Anillo con la proporción de atenciones en cada estado del flujo dentro del período.',
      [{ t: 'Estado' }, { t: 'Atenciones', num: true }, { t: '%', num: true }],
      es.map((e) => [e.etiqueta, num(e.n), num(100 * e.n / Math.max(1, es.reduce((a, b) => a + b.n, 0)), 1)]),
      { type: 'doughnut', data: { labels: es.map((e) => e.etiqueta), datasets: [{ data: es.map((e) => e.n),
        backgroundColor: es.map((e) => ({ realizada: C.verde, confirmada: C.azul, pendiente: C.ambar, cancelada: C.gris, no_show: C.rojo }[e.estado])) }] } });
    mapaCalor(s.dia_hora);
    narrativa(s, ind.data);
    $('#bi-estado').textContent = `Actualizado ${new Date().toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' })} · ${fecha(filtros.desde)} a ${fecha(filtros.hasta)}`;
    anunciar('Dashboard actualizado.');
  } catch (e) {
    fallo($('#kpis'), e, cargar);
    $('#bi-estado').textContent = '';
    if (e instanceof ApiError && e.status === 422) $('#err-f-bi').textContent = e.details.map((d) => d.message).join(' ');
  }
}

async function iniciar() {
  const hoy = hoyISO();
  $('#f-bi-hasta').value = hoy;
  $('#f-bi-desde').value = sumarDias(hoy, -182);
  try {
    const [o, p, b] = await Promise.all([api('/opciones'), api('/profesionales'), api('/boxes')]);
    $('#f-bi-especialidad').insertAdjacentHTML('beforeend', o.data.especialidades.map((e) => `<option value="${esc(e.slug)}">${esc(e.nombre)}</option>`).join(''));
    $('#f-bi-profesional_id').insertAdjacentHTML('beforeend', p.data.map((x) => `<option value="${x.id}">${esc(x.nombre)}</option>`).join(''));
    $('#f-bi-box').insertAdjacentHTML('beforeend', b.data.map((x) => `<option value="${esc(x.slug)}">${esc(x.nombre)}</option>`).join(''));
  } catch (e) { fallo($('#kpis'), e, iniciar); return; }
  filtros = leerFormulario($('#f-bi'));
  // Chart.js se carga con defer: esperar a que esté disponible antes del primer dibujo.
  if (!window.Chart) await new Promise((r) => window.addEventListener('load', r, { once: true }));
  cargar();
  setInterval(() => { if (!document.hidden) cargar(); }, 5 * 60 * 1000); // los datos siguen a los del sistema
}

$('#f-bi').addEventListener('submit', (ev) => {
  ev.preventDefault();
  $('#err-f-bi').textContent = '';
  const d = leerFormulario(ev.target);
  if (!d.desde || !d.hasta || d.desde > d.hasta) { $('#err-f-bi').textContent = 'Revisa el rango: «Desde» debe ser anterior o igual a «Hasta».'; $('#f-bi-desde').focus(); return; }
  filtros = d;
  cargar();
});
document.querySelectorAll('[data-rango]').forEach((b) => b.addEventListener('click', () => {
  const hoy = hoyISO();
  $('#f-bi-hasta').value = hoy;
  $('#f-bi-desde').value = sumarDias(hoy, -Number(b.dataset.rango) + 1);
  $('#f-bi').requestSubmit();
}));

$('#btn-ia').addEventListener('click', async (ev) => {
  const caja = $('#ia-resultado');
  ev.target.disabled = true;
  ev.target.textContent = 'Generando…';
  cargando(caja, 3);
  try {
    const r = await api('/ia/resumen', { metodo: 'POST', cuerpo: { desde: filtros.desde, hasta: filtros.hasta, enfoque: $('#ia-enfoque').value, ...(filtros.especialidad ? { especialidad: filtros.especialidad } : {}) } });
    const d = r.data;
    caja.removeAttribute('aria-busy');
    caja.innerHTML = `<div class="el-aviso-demo" role="note">${esc(d.aviso)} Modelo: ${esc(d.modelo)} · ${num(d.latencia_ms)} ms.</div>
      <p>${esc(d.resumen)}</p>${d.hallazgos.length ? `<h3>Hallazgos</h3><ul>${d.hallazgos.map((h) => `<li>${esc(h)}</li>`).join('')}</ul>` : ''}
      <h3>Recomendaciones</h3><ol>${d.recomendaciones.map((h) => `<li>${esc(h)}</li>`).join('')}</ol>`;
    anunciar('Resumen con IA generado.');
  } catch (e) {
    fallo(caja, e);
  } finally {
    ev.target.disabled = false;
    ev.target.textContent = 'Generar resumen';
  }
});

iniciar();
