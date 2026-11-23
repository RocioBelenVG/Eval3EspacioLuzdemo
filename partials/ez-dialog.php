<?php
/**
 * Diálogos con el estilo del sitio (reemplazan confirm()/alert()/prompt()
 * nativos del navegador). Expone window.ezConfirm / ezAlert / ezPrompt, todos
 * basados en Promesas. Se incluye en el sitio público (foot.php) y en el panel
 * (panel/top.php). También da estilo propio a TODOS los <select> (sin la flecha
 * por defecto del navegador).
 */
declare(strict_types=1);
?>
<style>
/* Selects con estilo propio (sin la apariencia por defecto del navegador) */
select:not([multiple]){-webkit-appearance:none;-moz-appearance:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%235C4033' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .7rem center;background-size:1.05rem;padding-right:2.4rem!important;cursor:pointer}
select::-ms-expand{display:none}

/* Diálogos (ezConfirm / ezAlert / ezPrompt) */
.ezd-ov{position:fixed;inset:0;z-index:2000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(61,42,33,.55);-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);opacity:0;transition:opacity .18s ease}
.ezd-ov.ezd-show{opacity:1}
.ezd-box{width:100%;max-width:400px;background:#FBF9F4;border-radius:22px;padding:26px 24px;box-shadow:0 24px 60px -18px rgba(61,42,33,.5);transform:translateY(10px) scale(.98);transition:transform .2s ease;font-family:'Nunito',system-ui,sans-serif;color:#5C4033}
.ezd-ov.ezd-show .ezd-box{transform:none}
.ezd-ic{width:46px;height:46px;border-radius:9999px;display:flex;align-items:center;justify-content:center;margin-bottom:14px}
.ezd-ic svg{width:24px;height:24px}
.ezd-ic-info{background:rgba(139,158,143,.2);color:#6D8072}
.ezd-ic-warn{background:rgba(217,119,87,.16);color:#B85C3E}
.ezd-ic-danger{background:rgba(220,38,38,.12);color:#dc2626}
.ezd-title{font-family:'Playfair Display',Georgia,serif;font-size:20px;font-weight:600;margin:0 0 6px;color:#5C4033}
.ezd-msg{font-size:14.5px;line-height:1.55;color:rgba(92,64,51,.8);margin:0;white-space:pre-line;text-align:left}
.ezd-input{margin-top:14px;width:100%;box-sizing:border-box;border:1px solid rgba(92,64,51,.25);background:#fff;border-radius:12px;padding:10px 13px;font:inherit;font-size:15px;color:#5C4033;outline:none}
.ezd-input:focus{border-color:#D97757}
.ezd-actions{margin-top:22px;display:flex;justify-content:flex-end;gap:10px}
.ezd-btn{border:none;border-radius:9999px;padding:10px 20px;font-family:inherit;font-size:14px;font-weight:700;cursor:pointer;transition:background .18s,border-color .18s,color .18s}
.ezd-cancel{background:transparent;border:2px solid rgba(92,64,51,.18);color:#5C4033}
.ezd-cancel:hover{border-color:#D97757;color:#B85C3E}
.ezd-ok{background:#D97757;color:#FBF9F4}
.ezd-ok:hover{background:#B85C3E}
.ezd-ok.ezd-danger{background:#dc2626}.ezd-ok.ezd-danger:hover{background:#b91c1c}

/* Desplegable propio (reemplaza el <select> nativo para estilar también la lista) */
.ezsel{position:relative;display:inline-block;vertical-align:middle;max-width:100%}
.ezsel-btn{display:inline-flex;align-items:center;justify-content:space-between;gap:10px;width:100%;box-sizing:border-box;border:1px solid rgba(92,64,51,.25);background:#FBF9F4;border-radius:12px;padding:9px 13px;font-family:'Nunito',system-ui,sans-serif;font-size:15px;font-weight:600;color:#5C4033;cursor:pointer;transition:border-color .15s}
.ezsel-btn:hover{border-color:rgba(217,119,87,.6)}
.ezsel.ezsel-open .ezsel-btn{border-color:#D97757}
.ezsel-btn .ezsel-lbl{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ezsel-btn svg{width:18px;height:18px;flex:0 0 auto;color:#B85C3E;transition:transform .2s}
.ezsel.ezsel-open .ezsel-btn svg{transform:rotate(180deg)}
.ezsel-list{position:absolute;z-index:1800;top:calc(100% + 6px);left:0;min-width:100%;width:max-content;max-width:min(340px,90vw);max-height:270px;overflow-y:auto;background:#FBF9F4;border:1px solid rgba(92,64,51,.15);border-radius:14px;box-shadow:0 18px 44px -14px rgba(61,42,33,.4);padding:6px;opacity:0;transform:translateY(-6px);pointer-events:none;transition:opacity .15s,transform .15s}
.ezsel.ezsel-open .ezsel-list{opacity:1;transform:none;pointer-events:auto}
.ezsel-opt{padding:9px 12px;border-radius:9px;font-family:'Nunito',system-ui,sans-serif;font-size:14.5px;color:#5C4033;cursor:pointer;white-space:nowrap}
.ezsel-opt:hover,.ezsel-opt.ezsel-active,.ezsel-opt.ezsel-act{background:rgba(217,119,87,.14);color:#3D2A21}
.ezsel-opt.ezsel-act{outline:2px solid #1F5FBF;outline-offset:-2px}
.ezsel-list:focus{outline:none}
.ezsel-opt.ezsel-sel{background:#A4512F;color:#FFFFFF;font-weight:700}
/* Aviso de emisión (ezEmision): enviando -> emitido, con el estado del SII en vivo */
.ezd-box.eze-box{max-width:460px}
.eze-spin{width:46px;height:46px;border-radius:9999px;border:3px solid rgba(217,119,87,.22);border-top-color:#D97757;animation:eze-rot .9s linear infinite;margin-bottom:14px}
@keyframes eze-rot{to{transform:rotate(360deg)}}
.eze-ic-ok{background:rgba(139,158,143,.22);color:#6D8072}
.eze-ic-mal{background:rgba(220,38,38,.12);color:#dc2626}
.eze-seg{margin-top:10px;font-size:12.5px;font-weight:700;color:rgba(92,64,51,.5);font-variant-numeric:tabular-nums}
.eze-sub{font-size:14px;color:rgba(92,64,51,.75);margin:0}
.eze-sii{margin-top:14px;display:flex;align-items:flex-start;gap:9px;border-radius:12px;padding:10px 12px;font-size:13.5px;font-weight:700;line-height:1.4}
.eze-sii.pend{background:#FEF3C7;color:#92400E}
.eze-sii.ok{background:rgba(139,158,143,.2);color:#4F6354}
.eze-sii.mal{background:#FEE2E2;color:#B91C1C}
.eze-sii.int{background:#F5F1E8;color:rgba(92,64,51,.75)}
.eze-sii small{display:block;font-weight:500;font-size:12px;opacity:.85;margin-top:2px}
.eze-mini{width:15px;height:15px;margin-top:1px;border-radius:9999px;border:2px solid rgba(146,64,14,.25);border-top-color:#92400E;animation:eze-rot .8s linear infinite;flex:0 0 auto}
.eze-acc{margin-top:20px;display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-end;gap:8px}
a.ezd-btn{text-decoration:none;display:inline-flex;align-items:center}
.eze-link{margin-right:auto;font-size:13px;font-weight:700;color:rgba(92,64,51,.6);text-decoration:none;cursor:pointer;background:none;border:0;padding:0;font-family:inherit}
.eze-link:hover{color:#B85C3E}
</style>
<script>
(function(){
  if (window.ezConfirm) return;
  var ICONS = {
    info:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>',
    warn:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>',
    danger:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1L5 6"/></svg>'
  };
  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
  function run(opts){
    return new Promise(function(resolve){
      var kind = opts.kind || 'info';
      var icoCls = kind==='danger'?'ezd-ic-danger':(kind==='warn'?'ezd-ic-warn':'ezd-ic-info');
      var icoSvg = kind==='danger'?ICONS.danger:(kind==='warn'?ICONS.warn:ICONS.info);
      var ov = document.createElement('div'); ov.className='ezd-ov';
      var h = '<div class="ezd-box" role="dialog" aria-modal="true">';
      h += '<div class="ezd-ic '+icoCls+'">'+icoSvg+'</div>';
      if (opts.title) h += '<h3 class="ezd-title">'+esc(opts.title)+'</h3>';
      h += '<p class="ezd-msg">'+esc(opts.message)+'</p>';
      if (opts.prompt) h += '<input class="ezd-input" type="text" value="'+esc(opts.value||'')+'" placeholder="'+esc(opts.placeholder||'')+'">';
      h += '<div class="ezd-actions">';
      if (opts.cancel) h += '<button type="button" class="ezd-btn ezd-cancel">'+esc(opts.cancelText||'Cancelar')+'</button>';
      h += '<button type="button" class="ezd-btn ezd-ok'+(kind==='danger'?' ezd-danger':'')+'">'+esc(opts.okText||'Aceptar')+'</button>';
      h += '</div></div>';
      ov.innerHTML = h;
      document.body.appendChild(ov);
      var input = ov.querySelector('.ezd-input');
      var okBtn = ov.querySelector('.ezd-ok');
      var cancelBtn = ov.querySelector('.ezd-cancel');
      var done = false;
      function close(val){ if(done) return; done=true; ov.classList.remove('ezd-show'); document.removeEventListener('keydown', onKey); setTimeout(function(){ ov.remove(); }, 180); resolve(val); }
      okBtn.addEventListener('click', function(){ close(opts.prompt ? (input ? input.value : '') : true); });
      if (cancelBtn) cancelBtn.addEventListener('click', function(){ close(opts.prompt ? null : false); });
      ov.addEventListener('click', function(e){ if(e.target===ov) close(opts.prompt ? null : (opts.cancel ? false : true)); });
      function onKey(e){ if(e.key==='Enter'){ e.preventDefault(); okBtn.click(); } else if(e.key==='Escape'){ e.preventDefault(); if(cancelBtn) cancelBtn.click(); else okBtn.click(); } }
      document.addEventListener('keydown', onKey);
      requestAnimationFrame(function(){ ov.classList.add('ezd-show'); (input||okBtn).focus(); if(input) input.select(); });
    });
  }
  window.ezConfirm = function(message, opts){ opts=opts||{}; return run({ message:message, title:opts.title||'¿Confirmar?', okText:opts.okText||'Confirmar', cancelText:opts.cancelText||'Cancelar', cancel:true, kind:opts.danger?'danger':(opts.kind||'warn') }); };
  window.ezAlert = function(message, opts){ opts=opts||{}; return run({ message:message, title:opts.title||'Aviso', okText:opts.okText||'Entendido', cancel:false, kind:opts.kind||'info' }); };

  /* ---------------------------------------------------------------------------
   * ezEmision: aviso de emisión con el estilo del sistema.
   *   var e = ezEmision.enviando({ titulo: 'Emitiendo boleta…', texto: 'Firmando y enviando al SII…' });
   *   e.cerrar();
   *   ezEmision.listo({ titulo, subtitulo, estado: 'aceptada'|'pendiente'|'rechazada'|'interno', mensaje, trackid,
   *                     ver: {url, texto}, otro: {url, texto} | null, documentos: url | null,
   *                     listo: {texto, accion} | null, revisar: function () -> Promise<{estado, mensaje}> })
   * Si queda 'pendiente', reconsulta sola el estado en el SII cada 3 s hasta tener el veredicto.
   * ------------------------------------------------------------------------- */
  var OK_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
  var X_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';
  function capa(html) {
    var ov = document.createElement('div'); ov.className = 'ezd-ov';
    ov.innerHTML = '<div class="ezd-box eze-box" role="dialog" aria-modal="true" aria-live="polite">' + html + '</div>';
    document.body.appendChild(ov);
    requestAnimationFrame(function () { ov.classList.add('ezd-show'); });
    return ov;
  }
  function quitar(ov) { ov.classList.remove('ezd-show'); setTimeout(function () { ov.remove(); }, 180); }
  function siiHtml(estado, mensaje, trackid) {
    var t = { aceptada: ['ok', OK_SVG.replace('<svg', '<svg style="width:16px;height:16px;flex:0 0 auto"'), 'Aceptada por el SII'],
              pendiente: ['pend', '<span class="eze-mini"></span>', 'Enviada al SII · validando…'],
              rechazada: ['mal', X_SVG.replace('<svg', '<svg style="width:16px;height:16px;flex:0 0 auto"'), 'Rechazada por el SII'],
              interno: ['int', '', 'Documento interno · sin timbre SII'] }[estado] || ['int', '', estado];
    var sub = mensaje ? esc(mensaje) : (estado === 'pendiente' ? 'El SII ya lo recibió. Puedes seguir trabajando: el estado se actualiza solo.' : '');
    if (trackid && estado !== 'interno') sub += (sub ? ' · ' : '') + 'TrackID ' + esc(trackid);
    return '<div class="eze-sii ' + t[0] + '">' + t[1] + '<span>' + t[2] + (sub ? '<small>' + sub + '</small>' : '') + '</span></div>';
  }
  window.ezEmision = {
    nombre: function (t) {
      return ({ 33: 'Factura Electrónica', 34: 'Factura Exenta Electrónica', 39: 'Boleta Electrónica', 41: 'Boleta Exenta Electrónica',
                56: 'Nota de Débito Electrónica', 61: 'Nota de Crédito Electrónica' })[parseInt(t, 10)] || 'Documento';
    },
    enviando: function (o) {
      o = o || {};
      var t0 = Date.now();
      var ov = capa('<div class="eze-spin"></div><h3 class="ezd-title">' + esc(o.titulo || 'Emitiendo documento…') + '</h3>' +
        '<p class="ezd-msg">' + esc(o.texto || 'Firmando y enviando al SII. No cierres esta página.') + '</p><div class="eze-seg">0 s</div>');
      var seg = ov.querySelector('.eze-seg');
      var iv = setInterval(function () { seg.textContent = Math.round((Date.now() - t0) / 1000) + ' s'; }, 500);
      return { cerrar: function () { clearInterval(iv); quitar(ov); } };
    },
    listo: function (o) {
      o = o || {};
      var estado = o.estado || 'aceptada';
      var mal = estado === 'rechazada';
      var h = '<div class="ezd-ic ' + (mal ? 'eze-ic-mal' : 'eze-ic-ok') + '">' + (mal ? X_SVG : OK_SVG) + '</div>' +
        '<h3 class="ezd-title">' + esc(o.titulo || 'Documento emitido') + '</h3>' +
        (o.subtitulo ? '<p class="eze-sub">' + esc(o.subtitulo) + '</p>' : '') +
        '<div class="eze-estado">' + siiHtml(estado, o.mensaje, o.trackid) + '</div><div class="eze-acc">';
      if (o.documentos) h += '<a class="eze-link" href="' + esc(o.documentos) + '">Ir a Documentos</a>';
      if (o.otro) h += '<a class="ezd-btn ezd-cancel" href="' + esc(o.otro.url) + '">' + esc(o.otro.texto || 'Emitir otra') + '</a>';
      if (o.listo) h += '<button type="button" class="ezd-btn ezd-cancel eze-listo">' + esc(o.listo.texto || 'Listo') + '</button>';
      if (o.ver) h += o.ver.accion ? '<button type="button" class="ezd-btn ezd-ok eze-ver">' + esc(o.ver.texto || 'Ver documento') + '</button>'
                                   : '<a class="ezd-btn ezd-ok" href="' + esc(o.ver.url) + '">' + esc(o.ver.texto || 'Ver documento') + '</a>';
      h += '</div>';
      var ov = capa(h);
      var bl = ov.querySelector('.eze-listo');
      if (bl) bl.addEventListener('click', function () { vivo = false; quitar(ov); if (o.listo.accion) o.listo.accion(); });
      var bv = ov.querySelector('.eze-ver');
      if (bv) bv.addEventListener('click', function () { vivo = false; quitar(ov); o.ver.accion(); });
      var foco = ov.querySelector('.ezd-ok') || bl; if (foco) foco.focus();
      // Reconsulta automática mientras el SII valida.
      var vivo = true, n = 0, caja = ov.querySelector('.eze-estado');
      function revisar() {
        if (!vivo || !document.body.contains(ov)) return;
        if (++n > 60) { caja.innerHTML = siiHtml('pendiente', 'El SII sigue validando. Puedes revisar el estado más tarde en Documentos (↻).', o.trackid); caja.querySelector('.eze-mini').style.animation = 'none'; return; }
        o.revisar().then(function (r) {
          if (!vivo) return;
          if (r && (r.estado === 'aceptada' || r.estado === 'rechazada')) {
            caja.innerHTML = siiHtml(r.estado, r.estado === 'rechazada' ? (r.mensaje || '') : '', o.trackid);
            if (r.estado === 'rechazada') {
              var ic = ov.querySelector('.ezd-ic'); ic.className = 'ezd-ic eze-ic-mal'; ic.innerHTML = X_SVG;
              var tt = ov.querySelector('.ezd-title'); tt.textContent = tt.textContent.replace(/ emitida$/, ' rechazada por el SII');
            }
            return;
          }
          setTimeout(revisar, 3000);
        }, function () { setTimeout(revisar, 5000); });
      }
      if (estado === 'pendiente' && typeof o.revisar === 'function') setTimeout(revisar, 2500);
      return ov;
    }
  };
  window.ezPrompt = function(message, opts){ opts=opts||{}; return run({ message:message, title:opts.title||'', okText:opts.okText||'Guardar', cancelText:'Cancelar', cancel:true, prompt:true, value:opts.value||'', placeholder:opts.placeholder||'', kind:'info' }); };

  // --- Desplegable propio: reemplaza visualmente al <select> nativo (que queda
  // oculto para conservar su valor y disparar 'change'). Así la LISTA también
  // lleva el estilo del sitio, cosa imposible con un <select> nativo.
  var CHEV = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
  function fancySelect(sel){
    if (!sel || sel.multiple || sel.dataset.ezFancy === '1' || sel.hasAttribute('data-ez-nofancy')) return;
    if (sel.closest('table')) return; // los selects pequeños de tablas se dejan como están
    if (sel.closest('.el-app')) return; // módulos nuevos: <select> nativo (accesible por defecto)
    sel.dataset.ezFancy = '1';
    var wrap = document.createElement('div'); wrap.className = 'ezsel';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    sel.style.display = 'none';
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'ezsel-btn';
    // Accesibilidad (WCAG 4.1.2): el botón toma el nombre de la etiqueta del <select> y anuncia la lista.
    var uid = sel.id || ('ezsel-' + Math.random().toString(36).slice(2, 8));
    btn.id = uid + '-btn';
    btn.setAttribute('aria-haspopup', 'listbox'); btn.setAttribute('aria-expanded', 'false');
    var etq = sel.id ? document.querySelector('label[for="' + sel.id + '"]') : null;
    if (etq) { if (!etq.id) etq.id = uid + '-lbl'; etq.htmlFor = btn.id; btn.setAttribute('aria-labelledby', etq.id + ' ' + btn.id); }
    else if (sel.getAttribute('aria-label')) { btn.setAttribute('aria-label', sel.getAttribute('aria-label')); }
    if (sel.required) btn.setAttribute('aria-required', 'true');
    var lbl = document.createElement('span'); lbl.className = 'ezsel-lbl';
    var chev = document.createElement('span'); chev.innerHTML = CHEV; chev.style.display = 'inline-flex';
    btn.appendChild(lbl); btn.appendChild(chev);
    var list = document.createElement('div'); list.className = 'ezsel-list'; list.setAttribute('role', 'listbox'); list.id = uid + '-lista'; list.tabIndex = -1;
    if (etq) list.setAttribute('aria-labelledby', etq.id); else list.setAttribute('aria-label', sel.getAttribute('aria-label') || 'Opciones');
    btn.setAttribute('aria-controls', list.id);
    var activo = -1;
    wrap.appendChild(btn); wrap.appendChild(list);
    function render(){
      list.innerHTML = '';
      Array.prototype.forEach.call(sel.options, function(o, i){
        var d = document.createElement('div');
        d.className = 'ezsel-opt' + (i === sel.selectedIndex ? ' ezsel-sel' : '');
        d.textContent = o.textContent; d.setAttribute('role', 'option'); d.id = uid + '-op' + i;
        d.setAttribute('aria-selected', i === sel.selectedIndex ? 'true' : 'false');
        if (o.disabled) d.setAttribute('aria-disabled', 'true');
        d.addEventListener('click', function(){
          if (sel.selectedIndex !== i) { sel.selectedIndex = i; sel.dispatchEvent(new Event('change', { bubbles: true })); }
          sync(); close();
        });
        list.appendChild(d);
      });
    }
    function sync(){
      var o = sel.options[sel.selectedIndex];
      lbl.textContent = o ? o.textContent : '';
      Array.prototype.forEach.call(list.children, function(c, i){ c.classList.toggle('ezsel-sel', i === sel.selectedIndex); c.setAttribute('aria-selected', i === sel.selectedIndex ? 'true' : 'false'); });
    }
    function marcar(i){
      var n = list.children.length; if (!n) return;
      activo = Math.max(0, Math.min(n - 1, i));
      Array.prototype.forEach.call(list.children, function(c, k){ c.classList.toggle('ezsel-act', k === activo); });
      list.setAttribute('aria-activedescendant', list.children[activo].id);
      list.children[activo].scrollIntoView({ block: 'nearest' });
    }
    function elegir(i){ if (sel.options[i] && !sel.options[i].disabled && sel.selectedIndex !== i) { sel.selectedIndex = i; sel.dispatchEvent(new Event('change', { bubbles: true })); } sync(); }
    function open(){ render(); sync(); wrap.classList.add('ezsel-open'); btn.setAttribute('aria-expanded', 'true'); marcar(Math.max(0, sel.selectedIndex)); list.focus(); setTimeout(function(){ document.addEventListener('click', outside, true); }, 0); }
    function close(volver){ wrap.classList.remove('ezsel-open'); btn.setAttribute('aria-expanded', 'false'); list.removeAttribute('aria-activedescendant'); document.removeEventListener('click', outside, true); if (volver) btn.focus(); }
    // Teclado: flechas abren y recorren, Enter/Espacio eligen, Esc y Tab cierran (sin trampa de foco).
    btn.addEventListener('keydown', function(e){
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); open(); }
    });
    list.addEventListener('keydown', function(e){
      if (e.key === 'ArrowDown') { e.preventDefault(); marcar(activo + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); marcar(activo - 1); }
      else if (e.key === 'Home') { e.preventDefault(); marcar(0); }
      else if (e.key === 'End') { e.preventDefault(); marcar(list.children.length - 1); }
      else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); elegir(activo); close(true); }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true); }
      else if (e.key === 'Tab') { close(false); }
    });
    function outside(e){ if (!wrap.contains(e.target)) close(); }
    btn.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); if (wrap.classList.contains('ezsel-open')) close(); else open(); });
    sel.addEventListener('change', sync);
    // Re-sincroniza la etiqueta cuando el value/selectedIndex se cambian por JS
    // (los modales hacen sel.value=... / sel.selectedIndex=... / form.reset(),
    // que NO disparan el evento 'change').
    try {
      var vd = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
      Object.defineProperty(sel, 'value', { configurable: true, get: function(){ return vd.get.call(this); }, set: function(v){ vd.set.call(this, v); sync(); } });
      var sid = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'selectedIndex');
      Object.defineProperty(sel, 'selectedIndex', { configurable: true, get: function(){ return sid.get.call(this); }, set: function(v){ sid.set.call(this, v); sync(); } });
    } catch (e) {}
    if (sel.form) sel.form.addEventListener('reset', function(){ setTimeout(sync, 0); });
    render(); sync();
  }
  window.ezFancySelect = fancySelect;
  function fancyAll(root){ (root || document).querySelectorAll('select:not([multiple])').forEach(fancySelect); }
  window.ezFancyAll = fancyAll;
  if (document.readyState !== 'loading') fancyAll();
  else document.addEventListener('DOMContentLoaded', function(){ fancyAll(); });
})();
</script>
