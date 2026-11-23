// Genera el PDF del informe técnico con Chrome (protocolo DevTools), sin dependencias:
// encabezado con el logo del Instituto Profesional Virginio Gómez en la esquina superior derecha
// de cada página y pie con «Página X de Y».
//   1. Servir la raíz del repositorio:  python -m http.server 8767 --bind 127.0.0.1
//   2. node docs/informe/generar_pdf.mjs "<ruta a chrome.exe>" "<salida.pdf>"
//      Copia para el docente con la contraseña demo: definir antes EL_CLAVE_DEMO (no se guarda en el repositorio).
import { spawn } from 'node:child_process';
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const [chrome, salida] = process.argv.slice(2);
const raiz = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const logo = readFileSync(join(raiz, 'assets/images/partners/virginio-gomez.png')).toString('base64');
const puerto = 9333;
const perfil = mkdtempSync(join(tmpdir(), 'informe-'));
const proc = spawn(chrome, ['--headless=new', '--disable-gpu', '--no-first-run', `--remote-debugging-port=${puerto}`, `--user-data-dir=${perfil}`, 'about:blank'], { stdio: 'ignore' });

const esperar = (ms) => new Promise((r) => setTimeout(r, ms));
let ws;
for (let i = 0; i < 50 && !ws; i++) {
  try {
    const t = await (await fetch(`http://127.0.0.1:${puerto}/json/list`)).json();
    const pag = t.find((x) => x.type === 'page');
    if (pag) ws = new WebSocket(pag.webSocketDebuggerUrl);
  } catch { /* Chrome aún no responde */ }
  if (!ws) await esperar(200);
}
await new Promise((r) => ws.addEventListener('open', r, { once: true }));
let id = 0;
const pendientes = new Map();
ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.id && pendientes.has(m.id)) { pendientes.get(m.id)(m); pendientes.delete(m.id); } });
const cdp = (method, params = {}) => new Promise((r) => { const n = ++id; pendientes.set(n, r); ws.send(JSON.stringify({ id: n, method, params })); });

await cdp('Page.enable');
await cdp('Page.navigate', { url: 'http://127.0.0.1:8767/docs/informe/informe.html?v=' + Date.now() });
for (let i = 0; i < 100; i++) {   // espera a que el informe termine de componer los documentos
  const r = await cdp('Runtime.evaluate', { expression: 'document.body && document.body.dataset.listo === "1" && [...document.images].every(i => i.complete)', returnByValue: true });
  if (r.result?.result?.value) break;
  await esperar(300);
}
// Copia para el docente: la contraseña de las cuentas demo se inyecta solo si se pasa en EL_CLAVE_DEMO
// (nunca se escribe en el repositorio).
if (process.env.EL_CLAVE_DEMO) {
  await cdp('Runtime.evaluate', { expression: `document.getElementById('clave-demo').innerHTML = '<code>' + ${JSON.stringify(process.env.EL_CLAVE_DEMO)}.replace(/[<&]/g, '') + '</code>'` });
}
await esperar(800);
const encabezado = `<div style="width:100%;padding:0 16mm;display:flex;justify-content:flex-end;align-items:center">
  <img src="data:image/png;base64,${logo}" style="height:15mm"></div>`;
const pie = `<div style="width:100%;padding:0 16mm;font:8px Arial,sans-serif;color:#6B5446;display:flex;justify-content:space-between">
  <span>Informe técnico Espacio Luz Demo, Rocío Riquelme Torres</span><span>Página <span class="pageNumber"></span> de <span class="totalPages"></span></span></div>`;
const r = await cdp('Page.printToPDF', {
  printBackground: true, preferCSSPageSize: false, paperWidth: 8.27, paperHeight: 11.69,
  marginTop: 1.0, marginBottom: 0.75, marginLeft: 0.63, marginRight: 0.63,
  displayHeaderFooter: true, headerTemplate: encabezado, footerTemplate: pie,
});
writeFileSync(salida, Buffer.from(r.result.data, 'base64'));
console.log('PDF generado:', salida);
ws.close();
proc.kill();
