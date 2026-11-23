<?php
/**
 * Notas internas (no clínicas): varias por ficha, con guardado automático, marca
 * con ticket, edición y eliminación; ventana «Ver todas».
 * Por defecto son las del PACIENTE ($paciente, tabla pacientes_notas). Para otra ficha
 * (p. ej. Clientes) se define antes $notasCfg = [id, nombre, api, campo, titulo, ayuda, vacio, notas].
 */
declare(strict_types=1);
$notasCfg = array_merge([
    'id'     => (int) ($paciente['id'] ?? 0),
    'nombre' => (string) ($paciente['nombre'] ?? ''),
    'api'    => '/api/panel/pacientes',
    'campo'  => 'paciente_id',
    'titulo' => 'Notas del paciente',
    'ayuda'  => 'Notas internas, no clínicas (horarios, pagos, avisos). Se guardan solas mientras escribes y no aparecen en boletas ni certificados.',
    'vacio'  => 'Este paciente aún no tiene notas. Escribe la primera arriba.',
    'notas'  => null,
], $notasCfg ?? []);
$notasIni = $notasCfg['notas'] ?? notasPaciente((int) $notasCfg['id']);
?>
<section id="pac-notas" class="min-w-0 rounded-xl border border-cafe/10 bg-white/70 p-4 md:col-span-2 xl:col-span-2">
    <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
        <h4 class="flex items-center gap-2 font-body text-xs font-bold uppercase tracking-wider text-terracota-dark">
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg><?= e($notasCfg['titulo']) ?>
            <span id="pn-badge" class="hidden rounded-full bg-terracota/12 px-2 py-0.5 text-[10px] font-bold normal-case tracking-normal text-terracota-dark"></span>
        </h4>
        <button type="button" id="pn-ver-todas" class="inline-flex items-center gap-1.5 rounded-full border border-terracota/35 px-3 py-1 font-body text-xs font-semibold text-terracota-dark hover:bg-terracota/10">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
            <span>Ver todas</span>
        </button>
    </div>
    <p class="mb-3 font-body text-[11px] leading-snug text-cafe/50"><?= e($notasCfg['ayuda']) ?></p>
    <div data-pn-composer class="rounded-lg border border-cafe/15 bg-crema-light focus-within:border-terracota">
        <textarea rows="2" maxlength="2000" class="pn-comp-input block w-full resize-y rounded-lg bg-transparent px-3 py-2 font-body text-sm leading-relaxed text-cafe outline-none placeholder:text-cafe/40" placeholder="Escribe una nota… se guarda automáticamente"></textarea>
        <div class="flex items-center justify-between gap-2 border-t border-cafe/8 px-3 py-1.5">
            <span class="pn-comp-estado font-body text-[11px] text-cafe/45"></span>
            <button type="button" class="pn-comp-nueva hidden font-body text-[11px] font-semibold text-terracota-dark hover:underline">+ Nueva nota</button>
        </div>
    </div>
    <ul id="pn-lista" class="mt-3 flex flex-col gap-2"></ul>
    <p id="pn-mas" class="mt-2 hidden font-body text-[11px] text-cafe/50"></p>
</section>

<!-- Ventana «Ver todas» -->
<div id="pn-modal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-cafe/60 p-3 backdrop-blur-sm sm:p-6" role="dialog" aria-modal="true" aria-labelledby="pn-modal-titulo">
    <div class="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-crema-light shadow-warm">
        <div class="flex items-start justify-between gap-4 border-b border-cafe/10 px-5 py-4 sm:px-6">
            <div class="min-w-0">
                <p class="font-body text-[11px] font-bold uppercase tracking-[0.14em] text-terracota-dark"><?= e($notasCfg['titulo']) ?></p>
                <h3 id="pn-modal-titulo" class="truncate font-heading text-xl font-semibold text-cafe"><?= e($notasCfg['nombre']) ?></h3>
            </div>
            <button type="button" id="pn-modal-x" class="-mr-1 rounded-full p-2 text-cafe/50 hover:bg-cafe/5 hover:text-cafe" aria-label="Cerrar">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="space-y-3 border-b border-cafe/10 px-5 py-4 sm:px-6">
            <div data-pn-composer class="rounded-lg border border-cafe/15 bg-white focus-within:border-terracota">
                <textarea rows="2" maxlength="2000" class="pn-comp-input block w-full resize-y rounded-lg bg-transparent px-3 py-2 font-body text-sm leading-relaxed text-cafe outline-none placeholder:text-cafe/40" placeholder="Escribe una nota nueva… se guarda automáticamente"></textarea>
                <div class="flex items-center justify-between gap-2 border-t border-cafe/8 px-3 py-1.5">
                    <span class="pn-comp-estado font-body text-[11px] text-cafe/45"></span>
                    <button type="button" class="pn-comp-nueva hidden font-body text-[11px] font-semibold text-terracota-dark hover:underline">+ Nueva nota</button>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="inline-flex rounded-full border border-cafe/10 bg-white p-1 font-body" role="tablist" aria-label="Filtrar notas">
                    <button type="button" class="pn-filtro rounded-full px-3 py-1 text-xs font-bold" data-f="todas"></button>
                    <button type="button" class="pn-filtro rounded-full px-3 py-1 text-xs font-bold" data-f="pendientes"></button>
                    <button type="button" class="pn-filtro rounded-full px-3 py-1 text-xs font-bold" data-f="completadas"></button>
                </div>
                <input id="pn-buscar" type="search" placeholder="Buscar en las notas…" class="w-full min-w-0 rounded-full border border-cafe/15 bg-white px-3.5 py-1.5 font-body text-sm text-cafe outline-none focus:border-terracota sm:w-56">
            </div>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4 sm:px-6">
            <ul id="pn-modal-lista" class="flex flex-col gap-2"></ul>
            <p id="pn-modal-vacio" class="hidden py-10 text-center font-body text-sm text-cafe/45"></p>
        </div>
        <div class="flex items-center justify-between gap-3 border-t border-cafe/10 bg-white/60 px-5 py-3 sm:px-6">
            <span class="font-body text-[11px] text-cafe/45">Clic en el texto para editar · ✓ para marcar como hecha</span>
            <button type="button" id="pn-modal-cerrar" class="rounded-full bg-cafe px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-cafe-light">Cerrar</button>
        </div>
    </div>
</div>

<script>
(function () {
    var PID = <?= (int) $notasCfg['id'] ?>, API = <?= json_encode($notasCfg['api']) ?>, CAMPO = <?= json_encode($notasCfg['campo']) ?>;
    var VACIO = <?= json_encode($notasCfg['vacio'], JSON_UNESCAPED_UNICODE) ?>;
    // Base y token del panel en línea (no depende de que window.__PANEL__ ya exista: se define al final de la página).
    var BASE = <?= json_encode(BASE_URL) ?>, CSRF = <?= json_encode(csrf_token()) ?>;
    var NOTAS = <?= json_encode($notasIni, JSON_UNESCAPED_UNICODE) ?>;
    var filtro = 'todas', busqueda = '';
    var modal = document.getElementById('pn-modal');

    function api(d) {
        var P = window.__PANEL__ || {};
        return fetch((P.base != null ? P.base : BASE) + API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf || CSRF }, body: JSON.stringify(d) })
            .then(function (r) {
                return r.text().then(function (t) {
                    var j = null; try { j = JSON.parse(t); } catch (e) {}
                    if (!j) throw new Error(r.status === 401 || r.status === 419 ? 'Tu sesión expiró: recarga la página' : 'No se pudo conectar con el servidor');
                    if (!r.ok) throw new Error(j.error || 'No se pudo guardar');
                    return j;
                });
            });
    }
    function fecha(s) { if (!s) return ''; var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/); return m ? m[3] + '-' + m[2] + '-' + m[1] + ' ' + m[4] + ':' + m[5] : s; }
    function hora() { var d = new Date(); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
    function porId(id) { for (var i = 0; i < NOTAS.length; i++) if (NOTAS[i].id === id) return NOTAS[i]; return null; }
    function poner(n) { var i = NOTAS.findIndex(function (x) { return x.id === n.id; }); if (i >= 0) NOTAS[i] = n; else NOTAS.unshift(n); }
    function ordenar() { NOTAS.sort(function (a, b) { return (a.completada - b.completada) || (b.creado_en > a.creado_en ? 1 : b.creado_en < a.creado_en ? -1 : b.id - a.id); }); }

    // Guardado automático: espera una pausa al escribir; nunca envía dos pedidos a la vez.
    // ahora() devuelve una promesa que se cumple cuando no queda nada por guardar.
    function Guardador(fn, estado) {
        var t = null, actual = null, otraVez = false;
        function correr() {
            t = null;
            if (actual) { otraVez = true; return actual; }
            estado('guardando');
            actual = fn().then(function (r) { estado(r === false ? '' : 'ok'); }, function (e) { estado('error', e && e.message); })
                .then(function () { actual = null; if (otraVez) { otraVez = false; return correr(); } });
            return actual;
        }
        return {
            programar: function () { clearTimeout(t); estado('escribiendo'); t = setTimeout(correr, 700); },
            ahora: function () { if (t) { clearTimeout(t); return correr(); } return actual || Promise.resolve(); }
        };
    }
    var AYUDA = 'Enter para guardar · Shift + Enter para un salto de línea';
    function pintarEstado(el, e, msg) {
        el.className = el.className.replace(/\btext-(red-600|salvia-dark|cafe\/45)\b/g, '') + ' ' + (e === 'error' ? 'text-red-600' : (e === 'ok' || e === 'final') ? 'text-salvia-dark' : 'text-cafe/45');
        el.textContent = e === 'escribiendo' ? 'Escribiendo… (borrador)' : e === 'guardando' ? 'Guardando…' : e === 'ok' ? '✓ Borrador guardado ' + hora() + ' · Enter para terminar la nota'
            : e === 'final' ? '✓ Nota guardada ' + hora() + '. Puedes escribir la siguiente.' : e === 'error' ? (msg || 'No se pudo guardar') + ' · reintenta' : AYUDA;
    }

    // Cajas para escribir notas nuevas (en la tarjeta y en la ventana).
    // Mientras se escribe, el borrador se guarda solo; con Enter, «Guardar nota» o al salir
    // de la caja, la nota queda lista y la caja se vacía para escribir la siguiente.
    var composers = [];
    document.querySelectorAll('[data-pn-composer]').forEach(function (box) {
        var inp = box.querySelector('.pn-comp-input'), est = box.querySelector('.pn-comp-estado'), boton = box.querySelector('.pn-comp-nueva');
        var c = { notaId: null, inp: inp, error: false };
        boton.textContent = 'Guardar nota';
        function botonVisible() { boton.classList.toggle('hidden', inp.value.trim() === ''); }
        var g = Guardador(function () {
            var txt = inp.value.trim();
            if (txt === '') return Promise.resolve(false);
            var req = c.notaId ? api({ action: 'nota_actualizar', id: c.notaId, texto: txt, borrador: 1 }) : api((function () { var d = { action: 'nota_crear', texto: txt }; d[CAMPO] = PID; return d; })());
            return req.then(function (j) { c.notaId = j.nota.id; poner(j.nota); ordenar(); render(); });
        }, function (e, m) { c.error = e === 'error'; pintarEstado(est, e, m); });
        function terminar(enfocar) {
            if (inp.value.trim() === '' && !c.notaId) return;
            g.ahora().then(function () {
                if (c.error) return;                       // si falló, el texto se conserva para reintentar
                if (c.notaId) { c.notaId = null; inp.value = ''; botonVisible(); pintarEstado(est, 'final'); if (enfocar) inp.focus(); }
            });
        }
        inp.addEventListener('input', function () { botonVisible(); g.programar(); });
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); terminar(true); }
        });
        inp.addEventListener('blur', function () { terminar(false); });
        boton.addEventListener('mousedown', function (e) { e.preventDefault(); });   // no quitar el foco antes del clic
        boton.addEventListener('click', function () { terminar(true); });
        c.reset = function () { c.notaId = null; inp.value = ''; botonVisible(); pintarEstado(est, ''); };
        pintarEstado(est, '');
        composers.push(c);
    });

    // Una nota de la lista (checkbox, texto editable, fecha/autor, eliminar).
    function item(n, enModal) {
        var li = document.createElement('li');
        li.dataset.id = n.id;
        li.className = 'group flex items-start gap-3 rounded-xl border px-3 py-2.5 transition-colors ' + (n.completada ? 'border-cafe/8 bg-crema/40' : 'border-cafe/10 bg-white');
        var chk = document.createElement('button');
        chk.type = 'button'; chk.setAttribute('role', 'checkbox'); chk.setAttribute('aria-checked', n.completada ? 'true' : 'false');
        chk.title = n.completada ? 'Marcar como pendiente' : 'Marcar como hecha';
        chk.className = 'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md border-2 transition-colors ' + (n.completada ? 'border-salvia bg-salvia text-white' : 'border-cafe/25 bg-white text-transparent hover:border-salvia');
        chk.innerHTML = '<svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"/></svg>';
        chk.addEventListener('click', function () {
            chk.disabled = true;
            api({ action: 'nota_marcar', id: n.id, completada: n.completada ? 0 : 1 }).then(function (j) { poner(j.nota); ordenar(); render(); }, function (e) { chk.disabled = false; ezAlert(e.message); });
        });
        var cuerpo = document.createElement('div'); cuerpo.className = 'min-w-0 flex-1';
        var txt = document.createElement('p');
        txt.className = 'cursor-text whitespace-pre-line break-words font-body text-sm leading-relaxed ' + (n.completada ? 'text-cafe/45 line-through decoration-cafe/30' : 'text-cafe/90');
        txt.textContent = n.texto; txt.title = 'Clic para editar';
        var meta = document.createElement('p'); meta.className = 'mt-1 font-body text-[11px] text-cafe/45';
        function pintarMeta(extra) {
            meta.textContent = fecha(n.creado_en) + (n.autor ? ' · ' + n.autor : '') + (n.editado_en ? ' · editada ' + fecha(n.editado_en) : '') + (n.completada && n.completada_en ? ' · hecha ' + fecha(n.completada_en) : '') + (extra ? ' · ' + extra : '');
        }
        pintarMeta();
        cuerpo.appendChild(txt); cuerpo.appendChild(meta);
        // Edición en el lugar con guardado automático
        txt.addEventListener('click', function () {
            if (li.querySelector('textarea')) return;
            var ta = document.createElement('textarea');
            ta.maxLength = 2000; ta.rows = Math.min(8, Math.max(2, n.texto.split('\n').length + 1)); ta.value = n.texto;
            ta.className = 'block w-full resize-y rounded-lg border border-terracota/50 bg-crema-light px-2.5 py-1.5 font-body text-sm leading-relaxed text-cafe outline-none';
            txt.replaceWith(ta); ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length);
            var g = Guardador(function () {
                var v = ta.value.trim();
                if (v === '' || v === n.texto) return Promise.resolve(false);
                return api({ action: 'nota_actualizar', id: n.id, texto: v }).then(function (j) { n = j.nota; poner(n); });
            }, function (e, m) { pintarMeta(e === 'escribiendo' ? 'escribiendo…' : e === 'guardando' ? 'guardando…' : e === 'ok' ? '✓ guardado' : e === 'error' ? (m || 'no se pudo guardar') : ''); });
            ta.addEventListener('input', g.programar);
            ta.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); ta.blur(); } });
            ta.addEventListener('blur', function () { g.ahora(); setTimeout(function () { ordenar(); render(); }, 900); });
        });
        var acc = document.createElement('div'); acc.className = 'flex shrink-0 items-center gap-0.5 opacity-60 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100';
        var ed = document.createElement('button'); ed.type = 'button'; ed.title = 'Editar'; ed.setAttribute('aria-label', 'Editar nota');
        ed.className = 'rounded-md p-1.5 text-cafe/45 hover:bg-cafe/5 hover:text-terracota-dark';
        ed.innerHTML = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>';
        ed.addEventListener('click', function () { txt.click(); });
        var del = document.createElement('button'); del.type = 'button'; del.title = 'Eliminar'; del.setAttribute('aria-label', 'Eliminar nota');
        del.className = 'rounded-md p-1.5 text-cafe/45 hover:bg-red-50 hover:text-red-600';
        del.innerHTML = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/></svg>';
        del.addEventListener('click', function () {
            var corto = n.texto.length > 80 ? n.texto.slice(0, 80) + '…' : n.texto;
            ezConfirm('¿Eliminar esta nota? «' + corto + '». No se puede deshacer.', { title: 'Eliminar nota', okText: 'Eliminar', danger: true }).then(function (ok) {
                if (!ok) return;
                api({ action: 'nota_eliminar', id: n.id }).then(function () {
                    NOTAS = NOTAS.filter(function (x) { return x.id !== n.id; });
                    composers.forEach(function (c) { if (c.notaId === n.id) c.reset(); });
                    render();
                }, function (e) { ezAlert(e.message); });
            });
        });
        acc.appendChild(ed); acc.appendChild(del);
        li.appendChild(chk); li.appendChild(cuerpo); li.appendChild(acc);
        return li;
    }

    function render() {
        var pend = NOTAS.filter(function (n) { return !n.completada; }).length, hechas = NOTAS.length - pend;
        // Tarjeta: hasta 3 notas (pendientes primero)
        var lista = document.getElementById('pn-lista'); lista.innerHTML = '';
        NOTAS.slice(0, 3).forEach(function (n) { lista.appendChild(item(n, false)); });
        var mas = document.getElementById('pn-mas');
        mas.textContent = NOTAS.length > 3 ? '+ ' + (NOTAS.length - 3) + ' nota(s) más · usa «Ver todas»' : '';
        mas.classList.toggle('hidden', NOTAS.length <= 3);
        var badge = document.getElementById('pn-badge');
        badge.textContent = pend + (pend === 1 ? ' pendiente' : ' pendientes'); badge.classList.toggle('hidden', pend === 0);
        document.querySelector('#pn-ver-todas span').textContent = 'Ver todas (' + NOTAS.length + ')';
        // Ventana
        var etiquetas = { todas: 'Todas ' + NOTAS.length, pendientes: 'Pendientes ' + pend, completadas: 'Hechas ' + hechas };
        document.querySelectorAll('.pn-filtro').forEach(function (b) {
            b.textContent = etiquetas[b.dataset.f]; var on = b.dataset.f === filtro;
            b.setAttribute('aria-selected', on ? 'true' : 'false');
            b.className = 'pn-filtro rounded-full px-3 py-1 text-xs font-bold ' + (on ? 'bg-cafe text-crema-light' : 'text-cafe/60 hover:text-terracota-dark');
        });
        var q = busqueda.toLowerCase();
        var vis = NOTAS.filter(function (n) {
            return (filtro === 'todas' || (filtro === 'pendientes' ? !n.completada : n.completada)) && (!q || n.texto.toLowerCase().indexOf(q) !== -1);
        });
        var ml = document.getElementById('pn-modal-lista'); ml.innerHTML = '';
        vis.forEach(function (n) { ml.appendChild(item(n, true)); });
        var vacio = document.getElementById('pn-modal-vacio');
        vacio.textContent = NOTAS.length === 0 ? VACIO : 'No hay notas que coincidan con el filtro.';
        vacio.classList.toggle('hidden', vis.length > 0);
    }

    function abrirModal() { modal.classList.remove('hidden'); modal.classList.add('flex'); document.body.style.overflow = 'hidden'; render(); }
    function cerrarModal() {
        var a = document.activeElement; if (a && a.blur) a.blur();
        modal.classList.add('hidden'); modal.classList.remove('flex'); document.body.style.overflow = ''; render();
    }
    document.getElementById('pn-ver-todas').addEventListener('click', abrirModal);
    document.getElementById('pn-modal-x').addEventListener('click', cerrarModal);
    document.getElementById('pn-modal-cerrar').addEventListener('click', cerrarModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) cerrarModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.classList.contains('hidden')) cerrarModal(); });
    document.querySelectorAll('.pn-filtro').forEach(function (b) { b.addEventListener('click', function () { filtro = b.dataset.f; render(); }); });
    document.getElementById('pn-buscar').addEventListener('input', function (e) { busqueda = e.target.value.trim(); render(); });
    // Avisar si se intenta salir con una nota sin guardar
    window.addEventListener('beforeunload', function (e) {
        var sucio = composers.some(function (c) { return c.inp.value.trim() !== '' && (!c.notaId || (porId(c.notaId) || {}).texto !== c.inp.value.trim()); });
        if (sucio) { e.preventDefault(); e.returnValue = ''; }
    });
    ordenar(); render();
})();
</script>
