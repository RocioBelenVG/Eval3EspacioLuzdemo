/*
 * Espacio Luz — Panel administrativo (JS vanilla).
 * Calendario (ver/gestionar/agendar reservas) y cálculos del formulario de boletas.
 */
(function () {
  "use strict";
  var P = window.__PANEL__ || { base: "", csrf: "" };
  function u(p) { return P.base + p; }
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]; }); }
  function clp(n) { n = parseInt(n || 0, 10); return "$" + n.toLocaleString("es-CL"); }

  function api(path, data) {
    return fetch(u(path), {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": P.csrf },
      body: JSON.stringify(data)
    }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, status: r.status, d: d }; }); });
  }

  document.addEventListener("DOMContentLoaded", function () {
    initCalendario();
    initBoletaForm();
    initDocForm();
  });

  /* --------------------------------------------------------------- Calendario */
  function initCalendario() {
    var cfgEl = document.getElementById("ez-cal-config");
    var modal = document.getElementById("ez-cal-modal");
    if (!cfgEl || !modal) return;
    var cfg = {};
    try { cfg = JSON.parse(cfgEl.textContent); } catch (e) { return; }
    var body = document.getElementById("ez-cal-modal-body");

    var CUPO = parseInt(cfg.cupo || 2, 10);
    var modalBox = modal.firstElementChild;
    // wide=true: modal más ancho (gestión en bloque).
    function openModal(html, wide) {
      modalBox.classList.toggle("max-w-md", !wide);
      modalBox.classList.toggle("max-w-xl", !!wide);
      body.innerHTML = html; modal.classList.remove("hidden"); modal.classList.add("flex");
    }
    function closeModal() { modal.classList.add("hidden"); modal.classList.remove("flex"); body.innerHTML = ""; }
    modal.addEventListener("click", function (e) { if (e.target === modal) closeModal(); });
    function avisar(msg) { return (window.ezAlert || window.alert)(msg); }
    function fmtFecha(ymd) {
      var d = new Date(ymd + "T12:00:00");
      if (isNaN(d)) return ymd;
      var s = d.toLocaleDateString("es-CL", { weekday: "short", day: "2-digit", month: "2-digit", year: "numeric" });
      return s.charAt(0).toUpperCase() + s.slice(1);
    }

    document.querySelectorAll(".ez-cal-cell").forEach(function (cell) {
      cell.addEventListener("click", function () {
        if (cell.dataset.taken === "1") verSlot(cell.dataset.box, cell.dataset.fecha, cell.dataset.hora);
        else if (cell.dataset.cerrado === "1") avisar(cell.dataset.motivo || "Ese día el centro no atiende.");
        else nuevaReserva(cell.dataset.fecha, cell.dataset.hora);
      });
    });
    var btnMasivo = document.getElementById("ez-cal-masivo");
    if (btnMasivo) btnMasivo.addEventListener("click", function () { masivoForm({}); });

    /* ---- Feriados: el centro decide si cada feriado se trabaja o no ---- */
    var FERIADOS = cfg.feriados || {};
    function confirmarFer(msg, okText) {
      if (window.ezConfirm) return window.ezConfirm(msg, { title: "Feriado con horas tomadas", okText: okText || "Sí, continuar", cancelText: "Volver" });
      return Promise.resolve(window.confirm(msg));
    }
    function apiFeriado(payload) {
      return api("/api/panel/feriados", payload).then(function (x) {
        if (x.ok && x.d && x.d.needs_confirm) {
          return confirmarFer(x.d.mensaje, "Marcar igual").then(function (si) {
            if (!si) return false;
            payload.force = 1;
            return apiFeriado(payload);
          });
        }
        if (!x.ok) { avisar((x.d && x.d.error) || "No se pudo guardar el feriado."); return false; }
        return true;
      });
    }
    function marcarFeriado(fecha, trabaja) {
      return apiFeriado({ action: "marcar", fecha: fecha, se_trabaja: trabaja ? 1 : 0 });
    }
    document.querySelectorAll(".ez-fer-toggle").forEach(function (b) {
      b.addEventListener("click", function () {
        b.disabled = true;
        marcarFeriado(b.dataset.fecha, b.dataset.trabaja === "1").then(function (ok) { if (ok) location.reload(); else b.disabled = false; });
      });
    });
    var btnFer = document.getElementById("ez-cal-feriados");
    if (btnFer) btnFer.addEventListener("click", feriadosModal);

    function feriadosModal() {
      openModal('<p class="py-6 text-center font-body text-sm text-cafe/50">Cargando feriados…</p>', true);
      fetch(u("/api/panel/feriados")).then(function (r) { return r.json(); }).then(function (res) {
        if (!res.ok) throw new Error();
        var cambios = false;
        var filas = (res.feriados || []).map(function (f) {
          var tags = (f.irrenunciable ? '<span class="rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-bold uppercase text-violet-800">Irrenunciable</span> ' : "") +
            (f.origen === "manual" ? '<span class="rounded-full bg-cafe/10 px-2 py-0.5 text-[10px] font-bold uppercase text-cafe/70">Agregado</span> ' : "") +
            (f.reservas > 0 ? '<span class="rounded-full bg-terracota/15 px-2 py-0.5 text-[10px] font-bold text-terracota-dark">' + f.reservas + (f.reservas === 1 ? " hora tomada" : " horas tomadas") + "</span>" : "");
          var accion;
          if (f.domingo) {
            accion = '<span class="font-body text-xs font-semibold text-cafe/40">Domingo · cerrado</span>';
          } else {
            accion = '<div class="inline-flex overflow-hidden rounded-full border border-violet-300 font-body text-[11px] font-semibold">' +
              '<button type="button" data-fer="' + esc(f.fecha) + '" data-t="1" class="px-3 py-1 ' + (f.se_trabaja ? "bg-salvia-dark text-white" : "text-cafe/60 hover:bg-violet-50") + '">Se trabaja</button>' +
              '<button type="button" data-fer="' + esc(f.fecha) + '" data-t="0" class="px-3 py-1 ' + (!f.se_trabaja ? "bg-violet-700 text-white" : "text-cafe/60 hover:bg-violet-50") + '">No se trabaja</button></div>';
          }
          return '<div class="flex flex-wrap items-center justify-between gap-2 border-b border-cafe/8 py-2.5' + (f.domingo ? " opacity-60" : "") + '">' +
            '<div class="min-w-0"><p class="font-body text-sm font-semibold text-cafe">' + esc(f.nombre) + "</p>" +
            '<p class="font-body text-xs text-cafe/55">' + esc(f.fecha_larga.charAt(0).toUpperCase() + f.fecha_larga.slice(1)) + "</p>" +
            (tags ? '<div class="mt-1">' + tags + "</div>" : "") + "</div>" +
            '<div class="flex items-center gap-2">' + accion +
            (f.origen === "manual" ? '<button type="button" data-quitar="' + esc(f.fecha) + '" class="rounded-full px-2 py-1 font-body text-[11px] font-semibold text-red-700 hover:bg-red-50">Quitar</button>' : "") +
            "</div></div>";
        }).join("");
        openModal(
          '<div class="mb-1 flex items-center justify-between gap-3"><h3 class="font-heading text-xl font-semibold text-cafe">Feriados</h3>' +
          '<button type="button" data-close class="rounded-full px-3 py-1 font-body text-sm font-semibold text-cafe/60 hover:text-terracota">Cerrar ✕</button></div>' +
          '<p class="mb-3 font-body text-sm text-cafe/60">Elige si el centro atiende cada feriado. En los <strong>no trabajados</strong> no se pueden tomar horas (ni en el panel ni en la web) y no cuentan en la ocupación del Panel general. Los domingos el centro siempre está cerrado.</p>' +
          '<div class="max-h-[50vh] overflow-y-auto pr-1">' + (filas || '<p class="py-4 text-center font-body text-sm text-cafe/50">No hay feriados registrados.</p>') + "</div>" +
          '<div class="mt-4 rounded-xl border border-violet-200 bg-violet-50/60 p-3">' +
          '<p class="mb-2 font-body text-xs font-semibold uppercase tracking-wide text-violet-800">Agregar otro feriado (regional, elecciones, etc.)</p>' +
          '<div class="flex flex-wrap items-end gap-2"><input type="date" id="fer-fecha" class="rounded-lg border border-cafe/20 bg-white px-3 py-2 font-body text-sm text-cafe outline-none focus:border-violet-500">' +
          '<input id="fer-nombre" maxlength="120" placeholder="Nombre del feriado" class="min-w-0 flex-1 rounded-lg border border-cafe/20 bg-white px-3 py-2 font-body text-sm text-cafe outline-none focus:border-violet-500">' +
          '<button type="button" id="fer-add" class="rounded-full bg-violet-700 px-4 py-2 font-body text-sm font-semibold text-white hover:bg-violet-800">Agregar</button></div>' +
          '<p id="fer-err" class="mt-2 hidden font-body text-sm font-medium text-red-600"></p></div>',
          true
        );
        function cerrar() { closeModal(); if (cambios) location.reload(); }
        body.querySelector("[data-close]").addEventListener("click", cerrar);
        body.querySelectorAll("[data-fer]").forEach(function (b) {
          b.addEventListener("click", function () {
            b.disabled = true;
            marcarFeriado(b.dataset.fer, b.dataset.t === "1").then(function (ok) { if (ok) { cambios = true; feriadosModalRecargar(); } else b.disabled = false; });
          });
        });
        body.querySelectorAll("[data-quitar]").forEach(function (b) {
          b.addEventListener("click", function () {
            b.disabled = true;
            apiFeriado({ action: "quitar", fecha: b.dataset.quitar }).then(function (ok) { if (ok) { cambios = true; feriadosModalRecargar(); } else b.disabled = false; });
          });
        });
        body.querySelector("#fer-add").addEventListener("click", function () {
          var err = body.querySelector("#fer-err"); err.classList.add("hidden");
          var fe = body.querySelector("#fer-fecha").value, no = body.querySelector("#fer-nombre").value.trim();
          if (!fe || !no) { err.textContent = "Elige la fecha y escribe el nombre del feriado."; err.classList.remove("hidden"); return; }
          apiFeriado({ action: "agregar", fecha: fe, nombre: no }).then(function (ok) { if (ok) { cambios = true; feriadosModalRecargar(); } });
        });
        function feriadosModalRecargar() { feriadosModal(); window.__ezFerCambios = true; }
        if (window.__ezFerCambios) cambios = true;
      }).catch(function () { openModal('<p class="py-6 text-center font-body text-sm text-red-600">No se pudieron cargar los feriados.</p>'); });
    }
    modal.addEventListener("click", function (e) { if (e.target === modal && window.__ezFerCambios) location.reload(); });

    function estadoBadge(e) {
      var m = { pendiente: "bg-amber-100 text-amber-700", confirmada: "bg-salvia/20 text-salvia-dark", realizada: "bg-green-100 text-green-700", cancelada: "bg-red-100 text-red-700", no_show: "bg-cafe/15 text-cafe" };
      return '<span class="rounded-full px-2.5 py-0.5 font-body text-xs font-semibold ' + (m[e] || "bg-cafe/10 text-cafe") + '">' + esc(e) + "</span>";
    }

    // Detalle de una hora (box/fecha/hora): puede haber varias reservas por sobre-agendamiento.
    function verSlot(box, fecha, hora) {
      openModal('<p class="py-6 text-center font-body text-sm text-cafe/50">Cargando…</p>');
      var qs = "box=" + encodeURIComponent(box) + "&fecha=" + encodeURIComponent(fecha) + "&hora=" + encodeURIComponent(hora);
      fetch(u("/api/panel/reservas?" + qs)).then(function (r) { return r.json(); }).then(function (res) {
        if (!res.ok) throw new Error();
        var lista = res.reservas || [];
        // Si hay una sola, abre directamente su detalle completo.
        if (lista.length === 1) { verReserva(lista[0].id); return; }
        var filas = lista.map(function (r) {
          var prof = r.profesional_nombre ? esc(r.profesional_nombre) : '<span class="text-amber-700">Sin asignar</span>';
          var cli = cfg.modo === "box" ? "" : (r.paciente_nombre ? esc(r.paciente_nombre) : (esc(r.nombre) + ' <span class="text-cafe/40">(invitado)</span>'));
          var sub = [cli, r.precio_fmt ? esc(r.precio_fmt) : ""].filter(Boolean).join(" · ");
          return '<button type="button" data-ver="' + r.id + '" class="slot-row flex w-full items-center justify-between gap-3 border-b border-cafe/8 px-1 py-2.5 text-left hover:bg-crema/60">' +
            '<span class="min-w-0"><span class="block truncate font-body text-sm font-semibold text-cafe">' + prof + '</span>' +
            (sub ? '<span class="block truncate font-body text-xs text-cafe/55">' + sub + '</span>' : '') + '</span>' +
            '<span class="flex shrink-0 items-center gap-2">' + estadoBadge(r.estado) +
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-cafe/40"><path d="M9 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>';
        }).join("");
        var cupo = parseInt(res.cupo || CUPO, 10);
        var lleno = lista.length >= cupo;
        openModal(
          '<div class="mb-1 flex items-center justify-between gap-3"><h3 class="font-heading text-xl font-semibold text-cafe">Hora agendada</h3>' +
          '<span class="rounded-full ' + (lleno ? "bg-terracota/20 text-terracota-dark" : "bg-amber-100 text-amber-800") + ' px-2.5 py-0.5 font-body text-xs font-bold">' + lista.length + "/" + cupo + (lleno ? " · completo" : " · queda cupo") + "</span></div>" +
          '<p class="mb-4 font-body text-sm text-cafe/55">' + esc(res.box_nombre || box) + " · " + esc(fecha) + " · " + esc(hora) + " h</p>" +
          '<div class="mb-2">' + filas + "</div>" +
          (lleno ? '<p class="mt-3 rounded-lg bg-terracota/10 px-3 py-2 font-body text-xs text-terracota-dark">El box tiene su cupo completo en esta hora (máximo ' + cupo + " reservas).</p>" : "") +
          '<div class="mt-4 flex flex-wrap justify-end gap-2">' +
          (cfg.modo === "box" && !lleno ? '<button type="button" data-add class="rounded-full border-2 border-terracota/40 px-4 py-2 font-body text-sm font-semibold text-terracota-dark hover:bg-terracota/5">+ Agendar otro aquí</button>' : "") +
          '<button type="button" data-close class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota hover:text-terracota">Cerrar</button></div>'
        );
        body.querySelector("[data-close]").addEventListener("click", closeModal);
        var add = body.querySelector("[data-add]");
        if (add) add.addEventListener("click", function () { nuevaReserva(fecha, hora); });
        body.querySelectorAll("[data-ver]").forEach(function (b) {
          b.addEventListener("click", function () { verReserva(b.dataset.ver); });
        });
      }).catch(function () { openModal('<p class="py-6 text-center font-body text-sm text-red-600">No se pudo cargar la hora.</p>'); });
    }

    function verReserva(id) {
      openModal('<p class="py-6 text-center font-body text-sm text-cafe/50">Cargando…</p>');
      fetch(u("/api/panel/reservas?id=" + encodeURIComponent(id))).then(function (r) { return r.json(); }).then(function (res) {
        if (!res.ok) throw new Error();
        var r = res.reserva;
        var pacienteCell = r.paciente_id
          ? '<a href="' + u('/admin/pacientes/' + r.paciente_id) + '" class="font-semibold text-terracota-dark underline decoration-terracota/40 hover:text-terracota">' + esc(r.paciente_nombre || r.nombre) + '</a>'
          : esc(r.nombre) + ' <span class="text-cafe/40">(invitado)</span>';
        var rows = [
          ["Box", esc(r.box_nombre)],
          ["Profesional", r.profesional_nombre ? esc(r.profesional_nombre) : '<span class="text-amber-700">Sin asignar</span>'],
          ["Fecha", esc(r.fecha) + (r.hora ? " · " + esc(r.hora) + " h" : "")],
          ["Modalidad", esc(r.modalidad_label) + (r.precio_fmt ? " · " + esc(r.precio_fmt) : "")],
          ["Tipo de atención", '<select id="r-tipo" class="rounded-lg border border-cafe/20 bg-crema-light px-2 py-1 font-body text-sm text-cafe outline-none focus:border-terracota"><option value="">Sin especificar</option>' +
            (cfg.tipos || []).map(function (t) { return '<option value="' + esc(t.clave) + '"' + (r.tipo_atencion === t.clave ? " selected" : "") + ">" + esc(t.nombre) + "</option>"; }).join("") +
            '</select> <span id="r-tipo-ok" class="hidden font-body text-xs font-semibold text-salvia-dark">✓</span>'],
          cfg.modo === "box" ? null : ["Paciente", pacienteCell],
          cfg.modo !== "box" && r.telefono ? ["Teléfono", esc(r.telefono)] : null,
          cfg.modo !== "box" && r.email ? ["Correo", esc(r.email)] : null,
          r.cobro && r.cobro.sesion && cfg.modo !== "box" ? ["Pago de la sesión", cobroChip(r.cobro.sesion)] : null,
          r.cobro && r.cobro.arriendo ? ["Arriendo del box", cobroChip(r.cobro.arriendo)] : null
        ].filter(Boolean).map(function (x) {
          return '<div class="flex justify-between gap-4 border-b border-cafe/6 py-2"><span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/45">' + x[0] + '</span><span class="text-right font-body text-sm text-cafe">' + x[1] + "</span></div>";
        }).join("");
        var vigente = r.estado === "pendiente" || r.estado === "confirmada";
        var cupo = parseInt(r.cupo || CUPO, 10), ocup = parseInt(r.ocupacion || 0, 10);
        var lnk = "rounded-full border-2 border-terracota/40 px-4 py-2 font-body text-sm font-semibold text-terracota-dark hover:bg-terracota/5";
        var serieHtml = "";
        if (r.serie && vigente) {
          serieHtml = '<div class="mt-3 rounded-xl border border-salvia/30 bg-salvia/10 p-3">' +
            '<p class="font-body text-sm text-cafe"><strong>Serie de ' + r.serie.total + ' horas</strong> agendadas juntas (' + esc(fmtFecha(r.serie.desde)) + " → " + esc(fmtFecha(r.serie.hasta)) + ").</p>" +
            '<div class="mt-2 flex flex-wrap gap-2">' +
            '<button type="button" data-serie="cancelar" class="rounded-full bg-red-500 px-3.5 py-1.5 font-body text-xs font-semibold text-white hover:bg-red-600">Cancelar la serie</button>' +
            '<button type="button" data-serie="traspasar" class="rounded-full bg-terracota px-3.5 py-1.5 font-body text-xs font-semibold text-crema-light hover:bg-terracota-dark">Traspasar la serie</button>' +
            "</div></div>";
        }
        openModal(
          '<div class="mb-3 flex items-center justify-between"><h3 class="font-heading text-xl font-semibold text-cafe">Reserva</h3>' + estadoBadge(r.estado) + "</div>" +
          rows +
          (r.hora ? '<p class="mt-2 font-body text-xs text-cafe/55">Box en esta hora: <strong>' + ocup + "/" + cupo + "</strong>" + (ocup >= cupo ? " · completo" : " · queda " + (cupo - ocup) + " cupo") + "</p>" : "") +
          '<div class="mt-5 flex flex-wrap gap-2">' +
          (r.estado === "pendiente" ? btn("confirmada", "Confirmar", "bg-salvia-dark text-crema-light") : "") +
          btn("realizada", "Marcar realizada", "bg-green-600 text-white") +
          btn("cancelada", "Cancelar reserva", "bg-red-500 text-white") +
          '<button type="button" data-close class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota hover:text-terracota">Cerrar</button>' +
          "</div>" +
          serieHtml +
          '<div class="mt-3 flex flex-wrap gap-2 border-t border-cafe/10 pt-3">' +
          (!r.profesional_nombre ? '<p class="mb-1 w-full font-body text-xs text-amber-700">Reserva sin profesional asignado.</p>' : '') +
          (vigente ? '<button type="button" data-reagendar class="' + lnk + '">Reagendar</button>' : "") +
          (r.es_admin && r.cobro && r.cobro.sesion && r.cobro.sesion.clave === "por_cobrar" && r.paciente_id && cfg.modo !== "box"
            ? '<a href="' + u("/admin/boletas/nueva?paciente=" + r.paciente_id + "&reserva=" + r.id) + '" class="' + lnk + '">Emitir boleta de esta sesión</a>' : "") +
          (r.es_admin && r.cobro && r.cobro.arriendo && r.cobro.arriendo.clave === "por_facturar" && (r.clientes || []).length
            ? '<a href="' + u("/admin/facturas/nueva?cliente=" + r.clientes[0].id + "&mes=" + String(r.fecha).slice(0, 7)) + '" class="' + lnk + '">Facturar horas a ' + esc(r.clientes[0].razon_social) + "</a>" : "") +
          (r.cobro && r.cobro.arriendo && r.cobro.arriendo.clave === "por_facturar" && !(r.clientes || []).length && cfg.modo === "box"
            ? '<p class="w-full font-body text-[11px] text-cafe/50">Para facturar esta hora, vincula a ' + esc(r.profesional_nombre || "este profesional") + " con un cliente en Clientes.</p>" : "") +
          '<button type="button" data-asignar class="' + lnk + '">' + (cfg.modo === "box" ? "Traspasar o asignar profesional" : "Traspasar o asignar profesional / paciente") + '</button>' +
          (r.profesional_id && vigente ? '<button type="button" data-masivo class="' + lnk + '">Gestionar varias horas de este profesional</button>' : "") +
          (cfg.modo === "box" && r.hora && ocup < cupo ? '<button type="button" data-add-here class="' + lnk + '">+ Agendar otra reserva en esta hora</button>' : "") +
          "</div>"
        );
        body.querySelector("[data-close]").addEventListener("click", closeModal);
        var selTipo = body.querySelector("#r-tipo");
        if (selTipo) selTipo.addEventListener("change", function () {
          var okT = body.querySelector("#r-tipo-ok"); selTipo.disabled = true;
          api("/api/panel/reservas", { action: "tipo_atencion", id: r.id, tipo_atencion: selTipo.value }).then(function (x) {
            selTipo.disabled = false;
            if (!x.ok) { avisar((x.d && x.d.error) || "No se pudo guardar el tipo de atención."); return; }
            okT.classList.remove("hidden"); setTimeout(function () { okT.classList.add("hidden"); }, 1800);
          });
        });
        body.querySelector("[data-asignar]").addEventListener("click", function () { asignarForm(r); });
        var brg = body.querySelector("[data-reagendar]");
        if (brg) brg.addEventListener("click", function () { reagendarForm(r); });
        var bm = body.querySelector("[data-masivo]");
        if (bm) bm.addEventListener("click", function () { masivoForm({ profesional_id: r.profesional_id, desde: r.fecha, box: r.box_slug }); });
        var ba = body.querySelector("[data-add-here]");
        if (ba) ba.addEventListener("click", function () { nuevaReserva(r.fecha, r.hora); });
        body.querySelectorAll("[data-serie]").forEach(function (b) {
          b.addEventListener("click", function () { masivoForm({ serie: r.serie, accion: b.dataset.serie, profesional_id: r.profesional_id }); });
        });
        body.querySelectorAll("[data-estado]").forEach(function (b) {
          b.addEventListener("click", function () { cambiarEstado(id, b.dataset.estado, b); });
        });
      }).catch(function () { openModal('<p class="py-6 text-center font-body text-sm text-red-600">No se pudo cargar la reserva.</p>'); });
    }
    function btn(estado, label, cls) {
      return '<button type="button" data-estado="' + estado + '" class="rounded-full px-4 py-2 font-body text-sm font-semibold ' + cls + '">' + label + "</button>";
    }

    /* ---- Cobro de una hora: chip con su estado (enlaza al documento) ---- */
    function cobroChip(c) {
      if (!c) return "";
      var t = '<span class="inline-flex items-center rounded-full px-2.5 py-0.5 font-body text-xs font-semibold ' + c.clase + '">' + esc(c.texto) + "</span>";
      return c.doc_id ? '<a href="' + u("/admin/boletas/" + c.doc_id) + '" class="hover:opacity-80" title="Ver el documento">' + t + "</a>" : t;
    }

    /* ---- Decisión de cobro al cancelar o traspasar horas ya cobradas ---- */
    var OPC_COBRO = {
      cancelar: [
        ["nota_credito", "Devolver el cobro", "Se emite una nota de crédito por estas horas: dejan de cobrarse y lo pagado se devuelve o queda a favor.", "bg-terracota text-crema-light hover:bg-terracota-dark"],
        ["mantener", "Mantener el cobro", "Cancelación tardía o sin devolución: la hora queda cancelada, pero sigue cobrada.", "border-2 border-cafe/20 text-cafe hover:border-terracota hover:text-terracota"]
      ],
      traspasar: [
        ["ceder", "Ceder la hora (sigue pagada)", "La usa el nuevo profesional, pero sigue pagada por quien la arrendó. No se cobra de nuevo.", "bg-terracota text-crema-light hover:bg-terracota-dark"],
        ["recobrar", "Cobrársela al nuevo profesional", "Se devuelve al anterior con nota de crédito y la hora queda por facturar al nuevo.", "border-2 border-cafe/20 text-cafe hover:border-terracota hover:text-terracota"]
      ]
    };
    function listaCobro(c) {
      return (c && c.docs || []).map(function (x) {
        return '<li class="flex justify-between gap-3 border-b border-cafe/8 py-1.5 font-body text-sm text-cafe"><span>' + x.horas + (x.horas === 1 ? " hora" : " horas") + " en la " + (x.tipo === "boleta" ? "boleta" : "factura") + " N° " + esc(x.folio) +
          ' <span class="text-cafe/55">· ' + esc(x.receptor) + '</span></span><span class="shrink-0 font-semibold">' + clp(x.monto) + "</span></li>";
      }).join("");
    }
    function decidirCobro(d, operacion) {
      return new Promise(function (resolve) {
        var opc = OPC_COBRO[operacion] || OPC_COBRO.cancelar;
        openModal(
          '<h3 class="font-heading text-xl font-semibold text-cafe">' + (operacion === "traspasar" ? "Esta hora ya está facturada" : "Esta hora ya está cobrada") + "</h3>" +
          '<p class="mt-1 font-body text-sm text-cafe/65">' + (operacion === "traspasar" ? "¿Quién paga la hora que se traspasa?" : "¿Qué hacemos con lo que ya se cobró?") + "</p>" +
          '<ul class="mt-3 rounded-xl border border-cafe/10 bg-crema/50 px-3">' + listaCobro(d.cobro) + "</ul>" +
          (operacion === "cancelar" ? '<p class="mt-2 rounded-lg bg-salvia/15 px-3 py-2 font-body text-xs text-salvia-dark">¿Solo cambia la fecha u hora? Vuelve y usa <strong>Reagendar</strong>: la hora conserva su pago y no hay que devolver nada.</p>' : "") +
          '<label class="mt-3 flex flex-col gap-1"><span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Motivo (queda en la nota de crédito y en el historial)</span>' +
          '<input id="dc-motivo" class="rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota" placeholder="' + (operacion === "traspasar" ? "Ej.: traspaso de horas a otra profesional" : "Ej.: la profesional dejó el arriendo a mitad de mes") + '"></label>' +
          '<div class="mt-4 grid gap-2">' + opc.map(function (o) {
            return '<button type="button" data-dc="' + o[0] + '" class="rounded-xl px-4 py-3 text-left font-body text-sm ' + o[3] + '"><strong class="block">' + o[1] + '</strong><span class="block text-xs opacity-80">' + o[2] + "</span></button>";
          }).join("") + "</div>" +
          '<div class="mt-3 text-right"><button type="button" data-dc-x class="font-body text-sm font-semibold text-cafe/55 hover:text-terracota">Volver sin cambios</button></div>', false
        );
        body.querySelectorAll("[data-dc]").forEach(function (bt) {
          bt.addEventListener("click", function () { resolve({ accion: bt.dataset.dc, motivo: (body.querySelector("#dc-motivo").value || "").trim() }); });
        });
        body.querySelector("[data-dc-x]").addEventListener("click", function () { resolve(null); });
      });
    }

    /* ---- Cambiar estado (si la hora está cobrada, pide la decisión primero) ---- */
    function cambiarEstado(id, estado, bt, extra) {
      var p = { action: "estado", id: id, estado: estado };
      if (extra) { p.cobro_accion = extra.accion; p.cobro_motivo = extra.motivo; }
      if (bt) { bt.disabled = true; bt.textContent = "…"; }
      api("/api/panel/reservas", p).then(function (x) {
        if (x.status === 409 && x.d && x.d.requiere_cobro) {
          decidirCobro(x.d, "cancelar").then(function (dec) { if (!dec) { verReserva(id); return; } cambiarEstado(id, estado, null, dec); });
          return;
        }
        if (!x.ok) { avisar(x.d.error || "No se pudo actualizar"); if (bt) bt.disabled = false; return; }
        location.reload();
      });
    }

    /* ---- Reagendar: misma hora, otra fecha/hora/box; conserva su cobro ---- */
    function reagendarForm(r) {
      var horas = (cfg.horas || []).map(function (h) { return '<option value="' + esc(h) + '"' + (h === r.hora ? " selected" : "") + ">" + esc(h) + "</option>"; }).join("");
      var boxes = (cfg.boxes || []).map(function (b) { return '<option value="' + esc(b.slug) + '"' + (b.slug === r.box_slug ? " selected" : "") + ">" + esc(b.nombre) + "</option>"; }).join("");
      var conCobro = r.cobro && ((r.cobro.sesion && r.cobro.sesion.doc_id) || (r.cobro.arriendo && r.cobro.arriendo.doc_id));
      openModal(
        '<h3 class="mb-1 font-heading text-xl font-semibold text-cafe">Reagendar hora</h3>' +
        '<p class="mb-4 font-body text-sm text-cafe/55">Ahora: ' + esc(fmtFecha(r.fecha)) + (r.hora ? " · " + esc(r.hora) + " h" : "") + " · " + esc(r.box_nombre) + (r.profesional_nombre ? " · " + esc(r.profesional_nombre) : "") + "</p>" +
        '<div class="grid grid-cols-1 gap-3 sm:grid-cols-3">' +
        '<label class="flex flex-col gap-1"><span class="lbl">Nueva fecha</span><input type="date" id="rg-fecha" class="inp" value="' + esc(r.fecha) + '"></label>' +
        '<label class="flex flex-col gap-1"><span class="lbl">Hora</span><select id="rg-hora" class="inp">' + horas + "</select></label>" +
        '<label class="flex flex-col gap-1"><span class="lbl">Box</span><select id="rg-box" class="inp">' + boxes + "</select></label></div>" +
        (conCobro ? '<p class="mt-3 rounded-lg bg-salvia/15 px-3 py-2 font-body text-xs text-salvia-dark">Esta hora ya está cobrada: al reagendarla conserva su pago (no se cobra de nuevo).</p>' : "") +
        '<p id="rg-err" class="mt-3 hidden font-body text-sm font-medium text-red-600"></p>' +
        '<div class="mt-4 flex justify-end gap-2"><button type="button" data-back class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota">Volver</button>' +
        '<button type="button" id="rg-ok" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Reagendar</button></div>'
      );
      styleModalInputs();
      body.querySelector("[data-back]").addEventListener("click", function () { verReserva(r.id); });
      body.querySelector("#rg-ok").addEventListener("click", function () {
        var ok = body.querySelector("#rg-ok"), err = body.querySelector("#rg-err"); err.classList.add("hidden");
        ok.disabled = true; ok.textContent = "Reagendando…";
        api("/api/panel/reservas", { action: "reagendar", id: r.id, fecha: body.querySelector("#rg-fecha").value, hora: body.querySelector("#rg-hora").value, box_slug: body.querySelector("#rg-box").value }).then(function (x) {
          if (!x.ok) { err.textContent = x.d.error || "No se pudo reagendar."; err.classList.remove("hidden"); ok.disabled = false; ok.textContent = "Reagendar"; return; }
          var pr = avisar("Hora reagendada: " + x.d.antes + " → " + x.d.despues + "."); if (pr && pr.then) pr.then(function () { location.reload(); }); else location.reload();
        });
      });
    }
    function styleModalInputs() {
      body.querySelectorAll(".inp").forEach(function (el) { el.className = "inp rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota"; });
      body.querySelectorAll(".lbl").forEach(function (el) { el.className = "lbl font-body text-xs font-semibold uppercase tracking-wide text-cafe/60"; });
    }
    function asignarForm(r) {
      var profOpts = '<option value="">— Sin asignar —</option>' + cfg.profesionales.map(function (p) {
        return '<option value="' + p.id + '"' + (String(p.id) === String(r.profesional_id) ? ' selected' : '') + '>' + esc(p.nombre) + "</option>";
      }).join("");
      openModal(
        '<h3 class="mb-1 font-heading text-xl font-semibold text-cafe">' + (cfg.modo === "box" ? "Asignar profesional" : "Asignar profesional / paciente") + '</h3>' +
        '<p class="mb-4 font-body text-sm text-cafe/55">' + esc(r.box_nombre) + " · " + esc(r.fecha) + (r.hora ? " · " + esc(r.hora) + " h" : "") + "</p>" +
        '<div class="flex flex-col gap-3">' +
        '<label class="flex flex-col gap-1"><span class="lbl">Profesional</span><select id="a-prof" class="inp">' + profOpts + "</select></label>" +
        '<label class="flex flex-col gap-1' + (cfg.modo === "box" ? ' hidden' : '') + '"><span class="lbl">Paciente</span><input id="a-cli" class="inp" placeholder="Nombre o RUT…" autocomplete="off" value="' + esc(r.paciente_nombre || "") + '"></label>' +
        '<div id="a-res" class="hidden max-h-36 overflow-y-auto rounded-lg border border-cafe/15 bg-white"></div>' +
        '<input type="hidden" id="a-cid" value="' + esc(r.paciente_id || "") + '">' +
        '<p id="a-err" class="hidden font-body text-sm font-medium text-red-600"></p>' +
        '<div class="mt-1 flex justify-end gap-2"><button type="button" data-back class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota">Volver</button><button type="button" id="a-ok" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Guardar</button></div>' +
        "</div>"
      );
      styleModalInputs();
      body.querySelector("[data-back]").addEventListener("click", function () { verReserva(r.id); });
      var ci = body.querySelector("#a-cli"), cr = body.querySelector("#a-res"), cid = body.querySelector("#a-cid"), t;
      ci.addEventListener("input", function () {
        cid.value = ""; clearTimeout(t); var q = ci.value.trim();
        if (q.length < 2) { cr.classList.add("hidden"); cr.innerHTML = ""; return; }
        t = setTimeout(function () {
          fetch(u("/api/panel/pacientes?q=" + encodeURIComponent(q))).then(function (x) { return x.json(); }).then(function (d) {
            var cs = (d.pacientes || []).slice(0, 8); cr.classList.remove("hidden");
            if (!cs.length) { cr.innerHTML = '<div class="px-3 py-2 font-body text-xs text-cafe/50">Sin resultados</div>'; return; }
            cr.innerHTML = cs.map(function (c) { return '<button type="button" data-id="' + c.id + '" data-n="' + esc(c.nombre) + '" class="cop block w-full px-3 py-2 text-left font-body text-sm text-cafe hover:bg-crema">' + esc(c.nombre) + (c.rut ? " · " + esc(c.rut) : "") + "</button>"; }).join("");
            cr.querySelectorAll(".cop").forEach(function (b) { b.addEventListener("click", function () { cid.value = b.dataset.id; ci.value = b.dataset.n; cr.classList.add("hidden"); }); });
          }).catch(function () { cr.classList.add("hidden"); });
        }, 250);
      });
      function guardarAsignacion(datos, dec) {
        var p = { action: "asignar", id: r.id, profesional_id: datos.prof, paciente_id: datos.cid };
        if (dec) { p.cobro_accion = dec.accion; p.cobro_motivo = dec.motivo; }
        return api("/api/panel/reservas", p).then(function (x) {
          if (x.status === 409 && x.d && x.d.requiere_cobro) {
            return decidirCobro(x.d, "traspasar").then(function (d2) { if (!d2) { verReserva(r.id); return; } return guardarAsignacion(datos, d2); });
          }
          if (!x.ok) { var pr = avisar(x.d.error || "No se pudo asignar."); if (pr && pr.then) pr.then(function () { verReserva(r.id); }); return; }
          location.reload();
        });
      }
      body.querySelector("#a-ok").addEventListener("click", function () {
        var err = body.querySelector("#a-err"); err.classList.add("hidden");
        var ok = body.querySelector("#a-ok"); ok.disabled = true; ok.textContent = "Guardando…";
        guardarAsignacion({ prof: body.querySelector("#a-prof").value, cid: cid.value });
      });
    }

    function nuevaReserva(fecha, hora) {
      var boxField, profField;
      if (cfg.modo === "box") {
        boxField = '<input type="hidden" id="f-box" value="' + esc(cfg.sel) + '">';
        profField = '<div class="flex flex-col gap-1"><span class="lbl">Profesional(es) <span class="font-normal normal-case text-cafe/40">— marca uno o varios</span></span>' +
          '<div id="f-prof-list" class="flex flex-wrap gap-1.5 rounded-lg border border-cafe/15 bg-crema/40 p-2">' +
          cfg.profesionales.map(function (p) {
            return '<label class="inline-flex items-center gap-1.5 rounded-full border border-cafe/20 bg-crema-light px-2.5 py-1 font-body text-[11px] font-semibold text-cafe/75 hover:border-terracota cursor-pointer"><input type="checkbox" class="f-prof-chk h-3 w-3 accent-[#D97757]" value="' + p.id + '">' + esc(p.nombre) + "</label>";
          }).join("") + "</div>" +
          '<span class="font-body text-[11px] text-cafe/45">La hora del box queda a nombre de los profesionales marcados.</span></div>';
      } else {
        boxField = '<label class="flex flex-col gap-1"><span class="lbl">Box</span><select id="f-box" class="inp" required>' +
          cfg.boxes.map(function (b) { return '<option value="' + esc(b.slug) + '">' + esc(b.nombre) + "</option>"; }).join("") + "</select></label>";
        profField = '<input type="hidden" id="f-prof" value="' + esc(cfg.sel) + '">';
      }
      var diasDef = [[1, "Lun"], [2, "Mar"], [3, "Mié"], [4, "Jue"], [5, "Vie"], [6, "Sáb"]];
      var ferHoy = FERIADOS[fecha];
      var ferAviso = ferHoy ? '<div class="mb-3 flex items-start gap-2 rounded-xl border border-violet-300 bg-violet-50 px-3 py-2.5 font-body text-xs text-violet-900">' +
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-px shrink-0"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>' +
        '<span><strong>Ojo, es feriado:</strong> ' + esc(ferHoy.nombre) + '. Está marcado como día trabajado, por eso se puede agendar.</span></div>' : "";
      var diasHtml = diasDef.map(function (d) {
        return '<label class="inline-flex items-center gap-1 rounded-full border border-cafe/20 px-2 py-1 font-body text-[11px] font-semibold text-cafe/70"><input type="checkbox" class="f-dia h-3 w-3 accent-[#D97757]" value="' + d[0] + '" checked>' + d[1] + "</label>";
      }).join("");
      var horasChecks = (cfg.horas || []).map(function (hh) {
        return '<label class="inline-flex items-center gap-1 rounded-full border border-cafe/20 px-2 py-0.5 font-body text-[11px] font-semibold text-cafe/70"><input type="checkbox" class="f-hora h-3 w-3 accent-[#D97757]" value="' + esc(hh) + '"' + (hh === hora ? " checked" : "") + ">" + esc(hh) + "</label>";
      }).join("");
      var hbtn = "flex-1 rounded-lg border-2 px-2 py-1.5 font-body text-[11px] font-semibold ";
      var rangoHtml =
        '<div class="mb-3 flex gap-2">' +
        '<button type="button" id="f-m-uno" class="flex-1 rounded-lg border-2 border-terracota bg-terracota/10 px-3 py-1.5 font-body text-xs font-semibold text-terracota-dark">Un día</button>' +
        '<button type="button" id="f-m-rango" class="flex-1 rounded-lg border-2 border-cafe/15 px-3 py-1.5 font-body text-xs font-semibold text-cafe">Rango de fechas</button>' +
        '</div>' +
        '<div id="f-rango" class="mb-1 hidden flex-col gap-3 rounded-lg border border-cafe/10 bg-crema/40 p-3">' +
        '<div class="grid grid-cols-2 gap-3"><label class="flex flex-col gap-1"><span class="lbl">Desde</span><input type="date" id="f-desde" class="inp" value="' + esc(fecha) + '"></label>' +
        '<label class="flex flex-col gap-1"><span class="lbl">Hasta</span><input type="date" id="f-hasta" class="inp" value="' + esc(fecha) + '"></label></div>' +
        '<div><span class="lbl">Días de la semana</span><div class="mt-1.5 flex flex-wrap gap-1.5">' + diasHtml + "</div></div>" +
        '<div><span class="lbl">Horas</span>' +
        '<div class="mt-1.5 flex gap-2">' +
        '<button type="button" id="f-h-una" class="' + hbtn + 'border-terracota bg-terracota/10 text-terracota-dark">Solo ' + esc(hora) + ' h</button>' +
        '<button type="button" id="f-h-todas" class="' + hbtn + 'border-cafe/15 text-cafe">Toda la jornada</button>' +
        '<button type="button" id="f-h-elegir" class="' + hbtn + 'border-cafe/15 text-cafe">Elegir horas</button>' +
        '</div>' +
        '<div id="f-horas" class="mt-1.5 hidden flex-wrap gap-1.5">' + horasChecks + "</div></div>" +
        '<p class="font-body text-[11px] text-cafe/45">Se crean reservas en cada día marcado del rango según las horas elegidas. Los horarios donde el box ya tiene su cupo completo (' + CUPO + ' por hora) se omiten. Los domingos y los feriados no trabajados también se omiten; si el rango incluye feriados trabajados, te avisaremos.</p></div>';
      openModal(
        '<h3 class="mb-1 font-heading text-xl font-semibold text-cafe">Agendar hora</h3>' +
        '<p class="mb-4 font-body text-sm text-cafe/55">' + esc(fecha) + " · " + esc(hora) + " h</p>" +
        ferAviso + rangoHtml +
        '<div class="flex flex-col gap-3">' + boxField + profField +
        // En el calendario de boxes la hora es del profesional: no se piden datos de paciente.
        '<div class="flex flex-col gap-3' + (cfg.modo === "box" ? ' hidden' : '') + '">' +
        '<label class="flex flex-col gap-1"><span class="lbl">Paciente (busca o deja como invitado)</span><input id="f-cli-buscar" class="inp" placeholder="Nombre o RUT…" autocomplete="off"></label>' +
        '<div id="f-cli-res" class="hidden max-h-36 overflow-y-auto rounded-lg border border-cafe/15 bg-white"></div>' +
        '<input type="hidden" id="f-cli-id">' +
        '<label class="flex flex-col gap-1"><span class="lbl">Nombre *</span><input id="f-nombre" class="inp" required' + (cfg.modo === "box" ? ' value="Arriendo de box"' : '') + '></label>' +
        '<div class="grid grid-cols-2 gap-3"><label class="flex flex-col gap-1"><span class="lbl">Teléfono</span><input id="f-tel" class="inp"></label>' +
        '<label class="flex flex-col gap-1"><span class="lbl">Correo</span><input id="f-email" type="email" class="inp"></label></div>' +
        '</div>' +
        '<label class="flex flex-col gap-1"><span class="lbl">Detalle / modalidad</span><input id="f-mod" class="inp" value="' + (cfg.modo === "box" ? "Arriendo de box" : "Reserva manual") + '"></label>' +
        '<label class="flex flex-col gap-1"><span class="lbl">Tipo de atención <span class="font-normal normal-case text-cafe/40">— opcional, para el Panel general</span></span><select id="f-tipo" class="inp"><option value="">Sin especificar</option>' +
        (cfg.tipos || []).map(function (t) { return '<option value="' + esc(t.clave) + '">' + esc(t.nombre) + "</option>"; }).join("") + "</select></label>" +
        '<p id="f-err" class="hidden font-body text-sm font-medium text-red-600"></p>' +
        '<div class="mt-2 flex justify-end gap-2"><button type="button" data-close class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota">Cancelar</button>' +
        '<button type="button" id="f-ok" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Agendar</button></div></div>'
      );
      body.querySelectorAll(".inp").forEach(function (el) { el.className = "inp rounded-lg border border-cafe/20 bg-crema-light px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota"; });
      body.querySelectorAll(".lbl").forEach(function (el) { el.className = "lbl font-body text-xs font-semibold uppercase tracking-wide text-cafe/60"; });
      body.querySelector("[data-close]").addEventListener("click", closeModal);

      // Selector de modo: un día vs rango de fechas.
      var modo = "uno";
      var mUno = body.querySelector("#f-m-uno"), mRango = body.querySelector("#f-m-rango"), rangoBox = body.querySelector("#f-rango");
      function setModo(m) {
        modo = m;
        rangoBox.classList.toggle("hidden", m !== "rango");
        rangoBox.classList.toggle("flex", m === "rango");
        mUno.className = "flex-1 rounded-lg border-2 px-3 py-1.5 font-body text-xs font-semibold " + (m === "uno" ? "border-terracota bg-terracota/10 text-terracota-dark" : "border-cafe/15 text-cafe");
        mRango.className = "flex-1 rounded-lg border-2 px-3 py-1.5 font-body text-xs font-semibold " + (m === "rango" ? "border-terracota bg-terracota/10 text-terracota-dark" : "border-cafe/15 text-cafe");
      }
      mUno.addEventListener("click", function () { setModo("uno"); });
      mRango.addEventListener("click", function () { setModo("rango"); });

      // Selector de horas dentro del rango: solo esta / toda la jornada / elegir.
      var hhMode = "una";
      var hUna = body.querySelector("#f-h-una"), hTodas = body.querySelector("#f-h-todas"), hElegir = body.querySelector("#f-h-elegir"), horasBox = body.querySelector("#f-horas");
      function setHoras(m) {
        hhMode = m;
        horasBox.classList.toggle("hidden", m !== "elegir");
        horasBox.classList.toggle("flex", m === "elegir");
        [["una", hUna], ["todas", hTodas], ["elegir", hElegir]].forEach(function (p) {
          p[1].className = hbtn + (hhMode === p[0] ? "border-terracota bg-terracota/10 text-terracota-dark" : "border-cafe/15 text-cafe");
        });
      }
      hUna.addEventListener("click", function () { setHoras("una"); });
      hTodas.addEventListener("click", function () { setHoras("todas"); });
      hElegir.addEventListener("click", function () { setHoras("elegir"); });

      // Buscador de paciente (opcional): al elegir uno, la hora queda ligada a su historial.
      var cliInput = body.querySelector("#f-cli-buscar"), cliRes = body.querySelector("#f-cli-res"), cliId = body.querySelector("#f-cli-id"), cliT;
      cliInput.addEventListener("input", function () {
        cliId.value = ""; clearTimeout(cliT);
        var q = cliInput.value.trim();
        if (q.length < 2) { cliRes.classList.add("hidden"); cliRes.innerHTML = ""; return; }
        cliT = setTimeout(function () {
          fetch(u("/api/panel/pacientes?q=" + encodeURIComponent(q))).then(function (r) { return r.json(); }).then(function (d) {
            var cs = (d.pacientes || []).slice(0, 8);
            cliRes.classList.remove("hidden");
            if (!cs.length) { cliRes.innerHTML = '<div class="px-3 py-2 font-body text-xs text-cafe/50">Sin resultados — se guardará como invitado.</div>'; return; }
            cliRes.innerHTML = cs.map(function (c) { return '<button type="button" data-id="' + c.id + '" data-n="' + esc(c.nombre) + '" data-t="' + esc(c.telefono || "") + '" data-e="' + esc(c.email || "") + '" class="cli-op block w-full px-3 py-2 text-left font-body text-sm text-cafe hover:bg-crema">' + esc(c.nombre) + (c.rut ? ' · ' + esc(c.rut) : "") + "</button>"; }).join("");
            cliRes.querySelectorAll(".cli-op").forEach(function (b) { b.addEventListener("click", function () {
              cliId.value = b.dataset.id; cliInput.value = b.dataset.n;
              body.querySelector("#f-nombre").value = b.dataset.n; body.querySelector("#f-tel").value = b.dataset.t; body.querySelector("#f-email").value = b.dataset.e;
              cliRes.classList.add("hidden");
            }); });
          }).catch(function () { cliRes.classList.add("hidden"); });
        }, 250);
      });

      // Lista de profesionales elegidos: checkboxes (modo box) o el fijo (modo prof).
      function profesionalesSel() {
        if (cfg.modo === "box") {
          return Array.prototype.map.call(body.querySelectorAll(".f-prof-chk:checked"), function (c) { return c.value; });
        }
        var v = body.querySelector("#f-prof").value;
        return v ? [v] : [];
      }

      // Texto (plano, con saltos de línea) del aviso de sobre-cupo.
      // El backend entrega `conflictos` como textos ya legibles.
      function textoConflictos(d) {
        var c = d.conflictos || [];
        var n = d.n_conflictos || c.length;
        var muestra = c.slice(0, 8);
        var t = muestra.map(function (x) { return "• " + String(x); }).join("\n");
        var extra = (n > muestra.length) ? "\n…y " + (n - muestra.length) + " más" : "";
        var cupoTxt = d.sin_cupo ? "\n\nAdemás, " + d.sin_cupo + " horario(s) se omitirán porque el box ya tiene su cupo completo (" + CUPO + " por hora)." : "";
        return "Revisa antes de agendar (" + n + (n === 1 ? " aviso" : " avisos") + "):\n\n" + t + extra + cupoTxt + textoFeriados(d) + "\n\n¿Agendar de todas formas?";
      }
      function textoFeriados(d) {
        var t = "";
        if (d.feriados_cerrados && d.feriados_cerrados.length) t += "\n\nNo se agendó en estos feriados no trabajados:\n• " + d.feriados_cerrados.join("\n• ");
        if (d.feriados_trabajados && d.feriados_trabajados.length) t += "\n\nOjo, el rango incluye feriados marcados como trabajados (sí se agendaron):\n• " + d.feriados_trabajados.join("\n• ");
        return t;
      }

      function confirmar(msg) {
        if (window.ezConfirm) {
          return window.ezConfirm(msg, { title: "Antes de agendar", okText: "Agendar igual", cancelText: "Cancelar" });
        }
        return Promise.resolve(window.confirm(msg));
      }

      function enviar(payload, force) {
        var err = body.querySelector("#f-err");
        var ok = body.querySelector("#f-ok"); ok.disabled = true; ok.textContent = "Agendando…";
        if (force) payload.force = 1;
        api("/api/panel/reservas", payload).then(function (x) {
          if (!x.ok) { err.textContent = (x.d && x.d.error) || "No se pudo agendar."; err.classList.remove("hidden"); ok.disabled = false; ok.textContent = "Agendar"; return; }
          // El backend pide confirmación por sobre-cupo.
          if (x.d && x.d.needs_confirm) {
            ok.disabled = false; ok.textContent = "Agendar";
            confirmar(textoConflictos(x.d)).then(function (si) { if (si) enviar(payload, true); });
            return;
          }
          if (modo === "rango" || (x.d && x.d.creadas > 1) || (x.d && x.d.sin_cupo)) {
            var msg = "Se crearon " + (x.d.creadas || 0) + " reserva(s)" +
              (x.d.n_conflictos ? " (" + x.d.n_conflictos + " con el profesional ya ocupado)" : "") +
              (x.d.topado ? " · se alcanzó el máximo por lote" : "") + "." +
              (x.d.sin_cupo ? "\n\nSe omitieron " + x.d.sin_cupo + " horario(s) porque el box ya tenía su cupo completo (" + CUPO + " por hora)." : "") +
              textoFeriados(x.d);
            var p = avisar(msg);
            if (p && p.then) { p.then(function () { location.reload(); }); return; }
          }
          location.reload();
        });
      }

      body.querySelector("#f-ok").addEventListener("click", function () {
        var err = body.querySelector("#f-err"); err.classList.add("hidden");
        var payload = {
          box_slug: body.querySelector("#f-box").value,
          profesionales: profesionalesSel(),
          paciente_id: body.querySelector("#f-cli-id").value,
          hora: hora,
          nombre: body.querySelector("#f-nombre").value.trim(),
          telefono: body.querySelector("#f-tel").value.trim(),
          email: body.querySelector("#f-email").value.trim(),
          modalidad_label: body.querySelector("#f-mod").value.trim(),
          tipo_atencion: body.querySelector("#f-tipo").value
        };
        if (cfg.modo === "box" && !payload.profesionales.length) { err.textContent = "Marca al menos un profesional que toma la hora del box."; err.classList.remove("hidden"); return; }
        if (!payload.nombre) { err.textContent = "El nombre es obligatorio."; err.classList.remove("hidden"); return; }
        if (modo === "rango") {
          payload.action = "crear_rango";
          payload.fecha_desde = body.querySelector("#f-desde").value;
          payload.fecha_hasta = body.querySelector("#f-hasta").value;
          payload.dias = Array.prototype.map.call(body.querySelectorAll(".f-dia:checked"), function (c) { return parseInt(c.value, 10); });
          if (hhMode === "todas") { payload.horas = (cfg.horas || []).slice(); }
          else if (hhMode === "elegir") { payload.horas = Array.prototype.map.call(body.querySelectorAll(".f-hora:checked"), function (c) { return c.value; }); }
          else { payload.horas = [hora]; }
          if (!payload.fecha_desde || !payload.fecha_hasta) { err.textContent = "Elige el rango de fechas."; err.classList.remove("hidden"); return; }
          if (!payload.dias.length) { err.textContent = "Marca al menos un día de la semana."; err.classList.remove("hidden"); return; }
          if (!payload.horas.length) { err.textContent = "Elige al menos una hora."; err.classList.remove("hidden"); return; }
        } else {
          payload.action = "crear";
          payload.fecha = fecha;
        }
        enviar(payload, false);
      });
    }

    /*
     * Gestión en bloque: cancelar o traspasar a otro profesional muchas horas a
     * la vez (por rango de fechas, días, horas y box) o una serie completa.
     * Paso 1 "Revisar" (no modifica nada) -> paso 2 "Confirmar".
     */
    function masivoForm(pre) {
      pre = pre || {};
      var serie = pre.serie || null;
      var hoy = cfg.hoy || new Date().toISOString().slice(0, 10);
      var en90 = (function () { var d = new Date(hoy + "T12:00:00"); d.setDate(d.getDate() + 90); return d.toISOString().slice(0, 10); })();
      var profDef = pre.profesional_id ? String(pre.profesional_id) : (cfg.modo === "prof" ? String(cfg.sel) : "");
      var boxDef = pre.box !== undefined ? pre.box : (cfg.modo === "box" ? cfg.sel : "");
      var accion = pre.accion === "traspasar" ? "traspasar" : "cancelar";
      var profOpts = function (sel, excl) {
        return '<option value="">— Elige —</option>' + cfg.profesionales.filter(function (p) { return String(p.id) !== String(excl || ""); }).map(function (p) {
          return '<option value="' + p.id + '"' + (String(p.id) === String(sel) ? " selected" : "") + ">" + esc(p.nombre) + "</option>";
        }).join("");
      };
      var diasDef = [[1, "Lun"], [2, "Mar"], [3, "Mié"], [4, "Jue"], [5, "Vie"], [6, "Sáb"], [7, "Dom"]];
      var chip = "inline-flex items-center gap-1 rounded-full border border-cafe/20 px-2 py-1 font-body text-[11px] font-semibold text-cafe/70";
      var filtros = serie
        ? '<div class="rounded-xl border border-salvia/30 bg-salvia/10 p-3 font-body text-sm text-cafe">Serie de <strong>' + serie.total + " horas</strong> futuras (" + esc(fmtFecha(serie.desde)) + " → " + esc(fmtFecha(serie.hasta)) + ")" +
          (pre.profesional_id ? " · " + esc((cfg.profesionales.filter(function (p) { return String(p.id) === String(pre.profesional_id); })[0] || {}).nombre || "") : "") + ".</div>"
        : '<div class="grid gap-3 sm:grid-cols-2">' +
          '<label class="flex flex-col gap-1"><span class="lbl">Profesional *</span><select id="m-prof" class="inp">' + profOpts(profDef) + "</select></label>" +
          '<label class="flex flex-col gap-1"><span class="lbl">Box</span><select id="m-box" class="inp"><option value="">Todos los boxes</option>' +
          cfg.boxes.map(function (b) { return '<option value="' + esc(b.slug) + '"' + (b.slug === boxDef ? " selected" : "") + ">" + esc(b.nombre) + "</option>"; }).join("") + "</select></label>" +
          '<label class="flex flex-col gap-1"><span class="lbl">Desde</span><input type="date" id="m-desde" class="inp" min="' + hoy + '" value="' + esc(pre.desde && pre.desde > hoy ? pre.desde : hoy) + '"></label>' +
          '<label class="flex flex-col gap-1"><span class="lbl">Hasta</span><input type="date" id="m-hasta" class="inp" min="' + hoy + '" value="' + esc(pre.hasta || en90) + '"></label>' +
          "</div>" +
          '<div><span class="lbl">Días de la semana</span><div class="mt-1.5 flex flex-wrap gap-1.5">' +
          diasDef.map(function (d) { return '<label class="' + chip + '"><input type="checkbox" class="m-dia h-3 w-3 accent-[#D97757]" value="' + d[0] + '" checked>' + d[1] + "</label>"; }).join("") + "</div></div>" +
          '<div><span class="lbl">Horas</span><div class="mt-1.5 flex flex-wrap gap-1.5">' +
          '<label class="' + chip + '"><input type="checkbox" id="m-todas" class="h-3 w-3 accent-[#D97757]" checked>Todas</label>' +
          (cfg.horas || []).map(function (h) { return '<label class="' + chip + ' m-hora-chip hidden"><input type="checkbox" class="m-hora h-3 w-3 accent-[#D97757]" value="' + esc(h) + '">' + esc(h) + "</label>"; }).join("") +
          "</div></div>";
      var seg = "flex-1 rounded-lg border-2 px-3 py-2 font-body text-xs font-semibold ";
      openModal(
        '<h3 class="mb-1 font-heading text-xl font-semibold text-cafe">Cancelar o traspasar horas</h3>' +
        '<p class="mb-4 font-body text-sm text-cafe/55">Gestiona de una vez las horas futuras de un profesional: cancelarlas o pasarlas a otro profesional. Primero revisas qué horas se afectan; nada cambia hasta que confirmes.</p>' +
        '<div class="flex flex-col gap-3">' + filtros +
        '<div><span class="lbl">¿Qué quieres hacer?</span><div class="mt-1.5 flex gap-2">' +
        '<button type="button" id="m-a-cancelar" class="' + seg + '">Cancelar las horas</button>' +
        '<button type="button" id="m-a-traspasar" class="' + seg + '">Traspasar a otro profesional</button></div></div>' +
        '<label id="m-dest-wrap" class="hidden flex-col gap-1"><span class="lbl">Profesional que recibe las horas *</span><select id="m-dest" class="inp">' + profOpts("", pre.profesional_id) + "</select></label>" +
        '<p id="m-err" class="hidden font-body text-sm font-medium text-red-600"></p>' +
        '<div id="m-prev"></div>' +
        '<div class="flex flex-wrap justify-end gap-2"><button type="button" data-close class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota">Cerrar</button>' +
        '<button type="button" id="m-revisar" class="rounded-full bg-cafe px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-cafe-light">Revisar horas</button></div>' +
        "</div>", true
      );
      styleModalInputs();
      body.querySelector("[data-close]").addEventListener("click", closeModal);
      var err = body.querySelector("#m-err"), prev = body.querySelector("#m-prev"), revisar = body.querySelector("#m-revisar");
      var bC = body.querySelector("#m-a-cancelar"), bT = body.querySelector("#m-a-traspasar"), destWrap = body.querySelector("#m-dest-wrap");
      function limpiar() { prev.innerHTML = ""; err.classList.add("hidden"); revisar.classList.remove("hidden"); }
      function setAccion(a) {
        accion = a;
        bC.className = seg + (a === "cancelar" ? "border-red-400 bg-red-50 text-red-700" : "border-cafe/15 text-cafe");
        bT.className = seg + (a === "traspasar" ? "border-terracota bg-terracota/10 text-terracota-dark" : "border-cafe/15 text-cafe");
        destWrap.classList.toggle("hidden", a !== "traspasar");
        destWrap.classList.toggle("flex", a === "traspasar");
        limpiar();
      }
      bC.addEventListener("click", function () { setAccion("cancelar"); });
      bT.addEventListener("click", function () { setAccion("traspasar"); });
      setAccion(accion);
      // Cualquier cambio de filtro invalida la revisión anterior.
      body.querySelectorAll("select, input").forEach(function (el) { el.addEventListener("change", limpiar); });
      var todas = body.querySelector("#m-todas");
      if (todas) todas.addEventListener("change", function () {
        body.querySelectorAll(".m-hora-chip").forEach(function (c) { c.classList.toggle("hidden", todas.checked); });
      });
      var mProf = body.querySelector("#m-prof");
      if (mProf) mProf.addEventListener("change", function () {
        var d = body.querySelector("#m-dest"), v = d.value;
        d.innerHTML = profOpts(v, mProf.value);
      });

      function payload(action) {
        var p = { action: action, accion: accion };
        if (serie) { p.serie_id = serie.id; }
        else {
          p.profesional_id = body.querySelector("#m-prof").value;
          p.box_slug = body.querySelector("#m-box").value;
          p.desde = body.querySelector("#m-desde").value;
          p.hasta = body.querySelector("#m-hasta").value;
          p.dias = Array.prototype.map.call(body.querySelectorAll(".m-dia:checked"), function (c) { return parseInt(c.value, 10); });
          p.horas = body.querySelector("#m-todas").checked ? [] : Array.prototype.map.call(body.querySelectorAll(".m-hora:checked"), function (c) { return c.value; });
        }
        if (accion === "traspasar") p.destino_id = body.querySelector("#m-dest").value;
        return p;
      }
      function validar(p) {
        if (!serie) {
          if (!p.profesional_id) return "Elige el profesional cuyas horas quieres gestionar.";
          if (!p.desde || !p.hasta || p.hasta < p.desde) return "Elige un rango de fechas válido.";
          if (!p.dias.length) return "Marca al menos un día de la semana.";
          if (!body.querySelector("#m-todas").checked && !p.horas.length) return "Elige al menos una hora o marca «Todas».";
        }
        if (p.accion === "traspasar" && !p.destino_id) return "Elige el profesional que recibirá las horas.";
        return "";
      }
      function filaHtml(f, motivo) {
        return '<li class="flex items-start justify-between gap-3 border-b border-cafe/6 py-1.5 font-body text-xs">' +
          '<span class="min-w-0"><span class="font-semibold text-cafe">' + esc(fmtFecha(f.fecha)) + " · " + esc(f.hora) + " h</span>" +
          '<span class="block truncate text-cafe/55">' + esc(f.box) + (f.profesional ? " · " + esc(f.profesional) : "") + (f.nombre && f.nombre !== f.profesional ? " · " + esc(f.nombre) : "") + "</span></span>" +
          (motivo ? '<span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">' + esc(motivo) + "</span>" : "") + "</li>";
      }

      revisar.addEventListener("click", function () {
        limpiar();
        var p = payload("masivo_preview"), msg = validar(p);
        if (msg) { err.textContent = msg; err.classList.remove("hidden"); return; }
        revisar.disabled = true; revisar.textContent = "Revisando…";
        api("/api/panel/reservas", p).then(function (x) {
          revisar.disabled = false; revisar.textContent = "Revisar horas";
          if (!x.ok) { err.textContent = (x.d && x.d.error) || "No se pudo revisar."; err.classList.remove("hidden"); return; }
          var d = x.d, n = d.aplicables;
          var esT = d.accion === "traspasar";
          var titulo = n
            ? (esT ? "Se traspasarán <strong>" + n + "</strong> hora(s) a <strong>" + esc(d.destino) + "</strong>." : "Se cancelarán <strong>" + n + "</strong> hora(s).")
            : (esT ? "Ninguna hora se puede traspasar." : "No hay horas para cancelar.");
          var lista = (d.muestra || []).map(function (f) { return filaHtml(f); }).join("");
          var mas = n > (d.muestra || []).length ? '<li class="py-1.5 font-body text-xs text-cafe/50">…y ' + (n - d.muestra.length) + " más</li>" : "";
          var omit = d.omitidas
            ? '<div class="mt-3 rounded-lg border border-amber-300/60 bg-amber-50 p-3"><p class="font-body text-xs font-semibold text-amber-800">' + d.omitidas + " hora(s) NO se " + (esT ? "traspasan" : "cancelan") + " (se mantienen como están):</p>" +
              '<ul class="mt-1 max-h-32 overflow-y-auto">' + (d.omitidas_muestra || []).map(function (f) { return filaHtml(f, f.motivo); }).join("") + "</ul></div>"
            : "";
          var cobroHtml = "";
          if (n && d.cobro && d.cobro.requiere) {
            var opcs = OPC_COBRO[esT ? "traspasar" : "cancelar"];
            cobroHtml = '<div class="mt-3 rounded-lg border border-terracota/30 bg-[#FBF3EC] p-3">' +
              '<p class="font-body text-xs font-bold uppercase tracking-wide text-terracota-dark">' + (esT ? "Hay horas ya facturadas" : "Hay horas ya cobradas") + "</p>" +
              '<ul class="mt-1 px-1">' + listaCobro(d.cobro) + "</ul>" +
              '<div class="mt-2 space-y-1.5">' + opcs.map(function (o, i) {
                return '<label class="flex cursor-pointer items-start gap-2 rounded-lg bg-white px-3 py-2"><input type="radio" name="m-cobro" value="' + o[0] + '" class="mt-1 accent-[#D97757]"' + (i === 0 ? " checked" : "") + '><span class="font-body text-sm text-cafe"><strong>' + o[1] + '</strong><span class="block text-xs text-cafe/60">' + o[2] + "</span></span></label>";
              }).join("") + "</div>" +
              '<input id="m-cobro-motivo" class="mt-2 w-full rounded-lg border border-cafe/20 bg-white px-3 py-2 font-body text-sm text-cafe outline-none focus:border-terracota" placeholder="Motivo (queda en la nota de crédito y en el historial)"></div>';
          }
          prev.innerHTML =
            '<div class="rounded-xl border border-cafe/10 bg-crema/50 p-3">' +
            '<p class="font-body text-sm text-cafe">' + titulo + "</p>" +
            (n ? '<ul class="mt-2 max-h-56 overflow-y-auto">' + lista + mas + "</ul>" : "") + omit + cobroHtml +
            (n ? '<button type="button" id="m-ok" class="mt-3 w-full rounded-full px-5 py-2.5 font-body text-sm font-semibold ' +
              (esT ? "bg-terracota text-crema-light hover:bg-terracota-dark" : "bg-red-500 text-white hover:bg-red-600") + '">' +
              (esT ? "Confirmar traspaso de " + n + " hora(s)" : "Confirmar cancelación de " + n + " hora(s)") + "</button>" : "") +
            "</div>";
          revisar.classList.add("hidden");
          var ok = body.querySelector("#m-ok");
          if (ok) ok.addEventListener("click", function () {
            ok.disabled = true; ok.textContent = esT ? "Traspasando…" : "Cancelando…";
            var pa = payload("masivo_aplicar");
            var rc = body.querySelector('input[name="m-cobro"]:checked');
            if (rc) { pa.cobro_accion = rc.value; pa.cobro_motivo = (body.querySelector("#m-cobro-motivo").value || "").trim(); }
            api("/api/panel/reservas", pa).then(function (y) {
              if (!y.ok) { ok.disabled = false; ok.textContent = "Reintentar"; err.textContent = (y.d && y.d.error) || "No se pudo completar."; err.classList.remove("hidden"); return; }
              var res = "Listo: " + y.d.procesadas + " hora(s) " + (esT ? "traspasadas a " + d.destino : "canceladas") + "." +
                (y.d.omitidas ? "\n" + y.d.omitidas + " hora(s) se mantuvieron sin cambios." : "");
              var pr = avisar(res);
              if (pr && pr.then) pr.then(function () { location.reload(); }); else location.reload();
            });
          });
        });
      });
    }
  }

  /* ------------------------------------------------------------ Boleta (form) */
  function initBoletaForm() {
    var form = document.getElementById("ez-boleta-form");
    if (!form) return;
    var tbody = document.getElementById("bol-items");
    var addBtn = document.getElementById("bol-add");

    function fila() {
      var tr = document.createElement("tr");
      tr.className = "border-b border-cafe/6";
      tr.innerHTML =
        '<td class="py-2 pr-2 align-middle"><input class="bi-desc w-full rounded-lg border border-cafe/20 bg-crema-light px-2.5 py-2 font-body text-sm text-cafe outline-none focus:border-terracota" placeholder="Ej: Sesión de psicología"></td>' +
        '<td class="py-2 px-1 align-middle"><input type="number" min="1" value="1" class="bi-cant w-full rounded-lg border border-cafe/20 bg-crema-light px-1.5 py-2 text-center font-body text-sm text-cafe outline-none focus:border-terracota"></td>' +
        '<td class="py-2 px-1 align-middle"><input type="number" min="0" step="1" value="0" class="bi-precio w-full rounded-lg border border-cafe/20 bg-crema-light px-2 py-2 text-right font-body text-sm text-cafe outline-none focus:border-terracota"></td>' +
        '<td class="py-2 px-1 text-center align-middle"><input type="checkbox" class="bi-afecto h-4 w-4 accent-[#D97757]"></td>' +
        '<td class="bi-sub py-2 pl-1 text-right align-middle font-body text-sm font-semibold text-cafe whitespace-nowrap">$0</td>' +
        '<td class="py-2 pl-1 text-center align-middle"><button type="button" class="bi-del text-lg leading-none text-red-500 hover:text-red-700" aria-label="Quitar">&times;</button></td>';
      tbody.appendChild(tr);
      tr.querySelectorAll("input").forEach(function (i) { i.addEventListener("input", recalc); });
      tr.querySelector(".bi-del").addEventListener("click", function () { tr.remove(); recalc(); });
      recalc();
    }

    function recalc() {
      var afecto = 0, exento = 0;
      tbody.querySelectorAll("tr").forEach(function (tr) {
        var c = parseInt(tr.querySelector(".bi-cant").value || 0, 10);
        var p = parseInt(tr.querySelector(".bi-precio").value || 0, 10);
        var sub = Math.max(0, c) * Math.max(0, p);
        tr.querySelector(".bi-sub").textContent = clp(sub);
        if (tr.querySelector(".bi-afecto").checked) afecto += sub; else exento += sub;
      });
      var neto = afecto, iva = Math.round(afecto * 0.19), total = afecto + iva + exento;
      set("t-neto", clp(neto)); set("t-iva", clp(iva)); set("t-exento", clp(exento)); set("t-total", clp(total));
    }
    function set(id, v) { var e = document.getElementById(id); if (e) e.textContent = v; }

    if (addBtn) addBtn.addEventListener("click", fila);
    fila(); // una fila inicial

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var items = [];
      tbody.querySelectorAll("tr").forEach(function (tr) {
        var desc = tr.querySelector(".bi-desc").value.trim();
        var precio = parseInt(tr.querySelector(".bi-precio").value || 0, 10);
        if (desc && precio > 0) items.push({ descripcion: desc, cantidad: parseInt(tr.querySelector(".bi-cant").value || 1, 10), precio_unit: precio, afecto: tr.querySelector(".bi-afecto").checked });
      });
      var err = document.getElementById("bol-err");
      if (!items.length) { err.textContent = "Agrega al menos un ítem con precio."; err.classList.remove("hidden"); return; }
      var payload = {
        receptor_nombre: form.querySelector("[name=receptor_nombre]").value.trim(),
        receptor_rut: form.querySelector("[name=receptor_rut]").value.trim(),
        receptor_email: form.querySelector("[name=receptor_email]").value.trim(),
        paciente_id: form.dataset.paciente || "",
        medio_pago: form.querySelector("[name=medio_pago]").value,
        glosa: form.querySelector("[name=glosa]").value.trim(),
        items: items
      };
      if (!payload.receptor_nombre) { err.textContent = "Falta el nombre del paciente."; err.classList.remove("hidden"); return; }
      var sb = document.getElementById("bol-submit"); sb.disabled = true; sb.textContent = "Emitiendo…";
      api("/api/panel/boletas", payload).then(function (x) {
        if (!x.ok) { err.textContent = x.d.error || "No se pudo emitir."; err.classList.remove("hidden"); sb.disabled = false; sb.textContent = "Emitir boleta"; return; }
        window.location.href = u("/admin/boletas/" + x.d.id);
      });
    });
  }

  /* ---------------------------------------------- Emitir documento (empresa) */
  function initDocForm() {
    var form = document.getElementById("ez-doc-form");
    if (!form || !window.__DOC__) return;
    var DOC = window.__DOC__;
    var empSel = document.getElementById("doc-empresa");
    var badge = document.getElementById("doc-tipo-badge");
    var itemsWrap = document.getElementById("doc-items");
    var addBtn = document.getElementById("doc-add");
    var submit = document.getElementById("doc-submit");
    var emptyProd = document.getElementById("doc-empty-prod");

    function empTipo() {
      var id = parseInt(empSel.value || 0, 10);
      var e = DOC.empresas.filter(function (x) { return x.id === id; })[0];
      return e ? e.tipo : "boleta";
    }
    // Empresa autorizada solo para boleta exenta (41): todos los ítems van exentos.
    function empSoloExento() {
      var id = parseInt(empSel.value || 0, 10);
      var e = DOC.empresas.filter(function (x) { return x.id === id; })[0];
      return !!(e && e.solo_exento);
    }
    // Productos de la empresa, de la socia que emite (más los aún sin profesional asignada).
    function products() {
      var lista = DOC.productos[empSel.value] || DOC.productos[parseInt(empSel.value, 10)] || [];
      var ps = document.getElementById("doc-prof");
      var pid = ps && ps.value ? parseInt(ps.value, 10) : 0;
      return pid ? lista.filter(function (p) { return !p.profesional_id || p.profesional_id === pid; }) : lista;
    }

    function updateBadge() {
      var t = empTipo();
      var esFact = t === "factura";
      badge.textContent = esFact ? "Factura" : (empSoloExento() ? "Boleta exenta (41)" : "Boleta");
      badge.className = "rounded-full px-3 py-1 font-body text-xs font-bold uppercase tracking-wide " + (esFact ? "bg-terracota/15 text-terracota-dark" : "bg-salvia/20 text-salvia-dark");
      submit.textContent = esFact ? "Emitir factura" : "Emitir boleta";
      emptyProd.classList.toggle("hidden", products().length > 0);
    }

    function prodOptions() {
      var opts = '<option value="">— Elige producto —</option>';
      products().forEach(function (p) {
        opts += '<option value="p:' + p.id + '" data-precio="' + p.precio_neto + '" data-afecto="' + p.afecto + '" data-nombre="' + esc(p.nombre) + '">' +
          esc(p.nombre) + (p.profesional_nombre ? " · " + esc(p.profesional_nombre) : "") + " — " + clp(p.precio_neto) + (parseInt(p.afecto, 10) ? " (afecto)" : " (exento)") + "</option>";
      });
      opts += '<option value="m">✎ Escribir manualmente…</option>';
      return opts;
    }

    function row() {
      var tr = document.createElement("div");
      tr.className = "doc-row rounded-lg border border-cafe/10 bg-crema/30 p-3";
      tr.innerHTML =
        '<div class="flex items-start gap-2">' +
          '<select class="di-prod flex-1 rounded-lg border border-cafe/20 bg-crema-light px-2.5 py-2 font-body text-sm text-cafe outline-none focus:border-terracota">' + prodOptions() + '</select>' +
          '<input type="number" min="1" value="1" class="di-cant w-16 rounded-lg border border-cafe/20 bg-crema-light px-1.5 py-2 text-center font-body text-sm text-cafe outline-none focus:border-terracota" title="Cantidad">' +
          '<button type="button" class="di-del px-1 text-lg leading-none text-red-500 hover:text-red-700" aria-label="Quitar">&times;</button>' +
        '</div>' +
        '<div class="di-manual mt-2 hidden grid grid-cols-1 gap-2 sm:grid-cols-3">' +
          '<input class="di-desc rounded-lg border border-cafe/20 bg-crema-light px-2.5 py-2 font-body text-sm text-cafe outline-none focus:border-terracota sm:col-span-1" placeholder="Descripción">' +
          '<input type="number" min="0" value="0" class="di-precio rounded-lg border border-cafe/20 bg-crema-light px-2.5 py-2 text-right font-body text-sm text-cafe outline-none focus:border-terracota" placeholder="Neto">' +
          '<select class="di-afecto rounded-lg border border-cafe/20 bg-crema-light px-2.5 py-2 font-body text-sm text-cafe outline-none focus:border-terracota"><option value="0">Exento</option><option value="1">Afecto IVA</option></select>' +
        '</div>' +
        '<div class="mt-1.5 flex justify-between font-body text-xs"><span class="di-info text-cafe/50"></span><span class="di-sub font-semibold text-cafe">$0</span></div>';
      itemsWrap.appendChild(tr);
      var prod = tr.querySelector(".di-prod");
      prod.addEventListener("change", function () {
        tr.querySelector(".di-manual").classList.toggle("hidden", prod.value !== "m");
        recalc();
      });
      tr.querySelectorAll("input, select").forEach(function (i) { i.addEventListener("input", recalc); });
      tr.querySelector(".di-del").addEventListener("click", function () { tr.remove(); if (!itemsWrap.children.length) row(); recalc(); });
      recalc();
    }

    // Datos de la fila (para producto o manual). El IVA se calcula en vivo a
    // partir del precio, aunque la descripción manual aún esté vacía.
    function rowData(tr) {
      var sel = tr.querySelector(".di-prod").value;
      var cant = Math.max(1, parseInt(tr.querySelector(".di-cant").value || 1, 10));
      if (sel.indexOf("p:") === 0) {
        var opt = tr.querySelector('.di-prod option[value="' + sel + '"]');
        return { descripcion: opt.getAttribute("data-nombre"), cantidad: cant, precio_unit: parseInt(opt.getAttribute("data-precio") || 0, 10), afecto: !empSoloExento() && opt.getAttribute("data-afecto") === "1", manual: false, producto_id: parseInt(sel.slice(2), 10) || 0 };
      }
      if (sel === "m") {
        return { descripcion: tr.querySelector(".di-desc").value.trim(), cantidad: cant, precio_unit: parseInt(tr.querySelector(".di-precio").value || 0, 10), afecto: !empSoloExento() && tr.querySelector(".di-afecto").value === "1", manual: true, reserva: tr.dataset.reserva ? parseInt(tr.dataset.reserva, 10) : 0 };
      }
      return null;
    }

    function recalc() {
      var afecto = 0, exento = 0, soloEx = empSoloExento();
      itemsWrap.querySelectorAll(".doc-row").forEach(function (tr) {
        var sa = tr.querySelector(".di-afecto");
        if (sa) { if (soloEx) sa.value = "0"; sa.disabled = soloEx; sa.title = soloEx ? "Esta empresa emite solo boleta exenta (41)" : ""; }
        var d = rowData(tr);
        var sub = (d && d.precio_unit > 0) ? d.cantidad * d.precio_unit : 0;
        tr.querySelector(".di-sub").textContent = clp(sub);
        tr.querySelector(".di-info").textContent = (d && d.precio_unit > 0) ? (d.afecto ? "Afecto a IVA (+19%)" : "Exento") : "";
        if (sub > 0) { if (d.afecto) afecto += sub; else exento += sub; }
      });
      var iva = Math.round(afecto * 0.19);
      set("t-neto", clp(afecto)); set("t-iva", clp(iva)); set("t-exento", clp(exento)); set("t-total", clp(afecto + iva + exento));
    }
    function set(id, v) { var e = document.getElementById(id); if (e) e.textContent = v; }

    function resetItems() { itemsWrap.innerHTML = ""; sesRows = {}; row(); }

    // ---- Sesiones del paciente por cobrar (se unen a la boleta) ----
    var sesWrap = document.getElementById("doc-ses-wrap"), sesLista = document.getElementById("doc-ses-lista"), sesN = document.getElementById("doc-ses-n");
    var sesRows = {};
    function fechaCorta(ymd) { var p = String(ymd || "").split("-"); return p.length === 3 ? p[2] + "-" + p[1] + "-" + p[0] : ymd; }
    function quitarSesiones() {
      Object.keys(sesRows).forEach(function (k) { sesRows[k].remove(); });
      sesRows = {};
      if (!itemsWrap.children.length) row();
      recalc();
    }
    function toggleSes(s, on) {
      if (!s) return;
      if (!on) {
        if (sesRows[s.id]) { sesRows[s.id].remove(); delete sesRows[s.id]; }
        if (!itemsWrap.children.length) row();
        recalc(); return;
      }
      // Reusa la fila vacía inicial.
      itemsWrap.querySelectorAll(".doc-row").forEach(function (tr) { if (!tr.dataset.reserva && tr.querySelector(".di-prod").value === "") tr.remove(); });
      row();
      var tr = itemsWrap.lastElementChild;
      tr.dataset.reserva = s.id;
      tr.querySelector(".di-prod").value = "m";
      tr.querySelector(".di-manual").classList.remove("hidden");
      tr.querySelector(".di-desc").value = "Sesión" + (s.modalidad_label ? " " + s.modalidad_label : "") + " · " + fechaCorta(s.fecha) + (s.hora ? " " + s.hora : "") + (s.profesional ? " · " + s.profesional : "");
      tr.querySelector(".di-precio").value = s.precio_clp || 0;
      tr.querySelector(".di-afecto").value = "0";
      var cant = tr.querySelector(".di-cant"); cant.value = 1; cant.readOnly = true; cant.title = "Una sesión";
      tr.querySelector(".di-prod").disabled = true;
      tr.querySelector(".di-del").addEventListener("click", function () {
        delete sesRows[s.id];
        var c = sesLista && sesLista.querySelector('[data-ses="' + s.id + '"]'); if (c) c.checked = false;
      });
      sesRows[s.id] = tr;
      var ps = document.getElementById("doc-prof");
      if (ps && !ps.value && s.profesional_id) { ps.value = String(s.profesional_id); ps.dispatchEvent(new Event("change")); }
      recalc();
    }
    function cargarSesiones(pid) {
      quitarSesiones();
      if (!sesWrap) return;
      if (!pid) { sesWrap.classList.add("hidden"); return; }
      sesLista.innerHTML = '<p class="font-body text-xs text-cafe/50">Cargando sesiones…</p>';
      sesWrap.classList.remove("hidden");
      fetch(u("/api/panel/boletas?action=sesiones&paciente_id=" + encodeURIComponent(pid)), { credentials: "same-origin" })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var ses = (d && d.sesiones) || [];
          sesN.textContent = ses.length ? ses.length + (ses.length === 1 ? " sesión por cobrar" : " sesiones por cobrar") : "Todo pagado";
          if (!ses.length) { sesLista.innerHTML = '<p class="font-body text-xs text-cafe/55">Este paciente no tiene sesiones por cobrar. Puedes agregar ítems igual.</p>'; return; }
          sesLista.innerHTML = ses.map(function (s) {
            return '<label class="flex cursor-pointer items-center gap-3 rounded-lg border border-cafe/10 bg-white px-3 py-2 hover:border-salvia">' +
              '<input type="checkbox" data-ses="' + s.id + '" class="h-4 w-4 accent-[#6D8072]">' +
              '<span class="min-w-0 flex-1 font-body text-sm text-cafe"><strong>' + esc(fechaCorta(s.fecha)) + (s.hora ? " · " + esc(s.hora) : "") + "</strong> · " + esc(s.modalidad_label || "Sesión") +
              (s.profesional ? ' <span class="text-cafe/55">· ' + esc(s.profesional) + "</span>" : "") +
              (s.futura ? ' <span class="rounded-full bg-amber-100 px-1.5 text-[10px] font-bold text-amber-800">agendada</span>' : "") +
              (s.estado === "no_show" ? ' <span class="rounded-full bg-cafe/10 px-1.5 text-[10px] font-bold text-cafe/60">no asistió</span>' : "") + "</span>" +
              '<span class="shrink-0 font-body text-sm font-semibold text-cafe">' + clp(s.precio_clp) + "</span></label>";
          }).join("");
          sesLista.querySelectorAll("[data-ses]").forEach(function (c) {
            c.addEventListener("change", function () { toggleSes(ses.filter(function (x) { return String(x.id) === c.dataset.ses; })[0], c.checked); });
          });
          if (DOC.preReserva) {
            var pc = sesLista.querySelector('[data-ses="' + DOC.preReserva + '"]');
            if (pc) { pc.checked = true; toggleSes(ses.filter(function (x) { return String(x.id) === String(DOC.preReserva); })[0], true); }
            DOC.preReserva = 0;
          }
        })
        .catch(function () { sesLista.innerHTML = '<p class="font-body text-xs text-red-600">No se pudieron cargar las sesiones.</p>'; });
    }

    empSel.addEventListener("change", function () { updateBadge(); resetItems(); if (cliSel && cliSel.value) cargarSesiones(cliSel.value); });
    if (addBtn) addBtn.addEventListener("click", row);

    // ---- Paciente: el receptor (a quién se cobra) viene resuelto desde la ficha ----
    // Menor de 18 -> tutor/apoderado; mayor de edad -> el propio paciente.
    var cliSel = document.getElementById("doc-paciente");
    var cliBuscar = document.getElementById("doc-paciente-buscar");
    var receptor = document.getElementById("doc-receptor");
    var rcErr = document.getElementById("doc-rc-error"), rcCard = document.getElementById("doc-rc-card"), rcFicha = document.getElementById("doc-rc-ficha");
    var fId = form.querySelector("[name=paciente_id]"), fNom = form.querySelector("[name=receptor_nombre]"), fRut = form.querySelector("[name=receptor_rut]"), fMail = form.querySelector("[name=receptor_email]");
    var fDir = form.querySelector("[name=receptor_direccion]"), fCom = form.querySelector("[name=receptor_comuna]"), fCod = form.querySelector("[name=receptor_codigo_prestacion]");
    var rcActual = null;

    function setRc(rc) {
      rcActual = rc;
      fNom.value = rc && rc.ok ? rc.nombre : ""; fRut.value = rc && rc.ok ? rc.rut : "";
      fDir.value = rc && rc.ok ? rc.direccion : ""; fCom.value = rc && rc.ok ? rc.comuna : ""; fCod.value = rc && rc.ok ? rc.codigo_prestacion : "";
    }
    function dato(k, v) { return '<dt class="font-semibold uppercase tracking-wide text-cafe/45">' + esc(k) + '</dt><dd class="min-w-0 break-words text-cafe/85">' + (v ? esc(v) : '<span class="text-amber-700">Falta en la ficha</span>') + "</dd>"; }

    function onPaciente() {
      var v = cliSel.value;
      rcErr.classList.add("hidden"); rcCard.classList.add("hidden"); rcFicha.classList.add("hidden");
      if (v === "") { receptor.classList.add("hidden"); fId.value = ""; fMail.value = ""; setRc(null); cargarSesiones(0); return; }
      var c = DOC.pacientes.filter(function (x) { return String(x.id) === String(v); })[0];
      if (!c) return;
      receptor.classList.remove("hidden");
      fId.value = c.id;
      rcFicha.href = u("/admin/pacientes/" + c.id); rcFicha.classList.remove("hidden");
      var rc = c.rc || { ok: false, error: "No se pudieron leer los datos del paciente." };
      setRc(rc);
      cargarSesiones(c.id);
      if (!rc.ok) { rcErr.textContent = rc.error; rcErr.classList.remove("hidden"); fMail.value = ""; return; }
      var tutor = rc.tipo === "tutor";
      var badge = document.getElementById("doc-rc-badge");
      badge.textContent = tutor ? "Tutor / apoderado" : "Paciente";
      badge.className = "rounded-full px-2.5 py-0.5 font-body text-[11px] font-bold " + (tutor ? "bg-terracota/15 text-terracota-dark" : "bg-salvia/25 text-salvia-dark");
      document.getElementById("doc-rc-nombre").textContent = rc.nombre + (rc.relacion ? " (" + rc.relacion + ")" : "");
      document.getElementById("doc-rc-motivo").textContent = tutor
        ? "Paciente menor de edad" + (rc.edad !== null ? " (" + rc.edad + " años)" : "") + ": " + c.nombre
        : "Paciente mayor de edad" + (rc.edad !== null ? " (" + rc.edad + " años)" : "");
      document.getElementById("doc-rc-datos").innerHTML =
        dato(tutor ? "RUT tutor" : "RUT", rc.rut) +
        dato("Dirección", [rc.direccion, rc.comuna].filter(Boolean).join(", ")) +
        dato("Cód. prest.", rc.codigo_prestacion) +
        (rc.telefono ? dato("Teléfono", rc.telefono) : "");
      var aviso = document.getElementById("doc-rc-aviso");
      aviso.textContent = rc.aviso || ""; aviso.classList.toggle("hidden", !rc.aviso);
      fMail.value = rc.email || "";
      rcCard.classList.remove("hidden");
    }
    function filtrarPacientes() {
      var q = (cliBuscar.value || "").trim().toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
      var actual = cliSel.value;
      var lista = DOC.pacientes.filter(function (c) {
        return !q || (c.etiqueta || "").toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").indexOf(q) !== -1 || String(c.rut || "").replace(/[.\-]/g, "").toLowerCase().indexOf(q.replace(/[.\-]/g, "")) !== -1;
      });
      cliSel.innerHTML = '<option value="">' + (lista.length ? "— Selecciona paciente (" + lista.length + ") —" : "Sin resultados") + "</option>" +
        lista.map(function (c) { return '<option value="' + c.id + '">' + esc(c.etiqueta) + "</option>"; }).join("");
      if (lista.some(function (c) { return String(c.id) === actual; })) cliSel.value = actual;
      else if (lista.length === 1) { cliSel.value = String(lista[0].id); onPaciente(); }
    }
    if (cliSel) cliSel.addEventListener("change", onPaciente);
    if (cliBuscar) cliBuscar.addEventListener("input", filtrarPacientes);

    // ---- Profesional que atendió (RUT autocompletado desde su ficha) ----
    var profSel = document.getElementById("doc-prof");
    var profRut = document.getElementById("doc-prof-rut");
    if (profSel && profRut) {
      profSel.addEventListener("change", function () {
        var p = (DOC.profesionales || []).filter(function (x) { return String(x.id) === String(profSel.value); })[0];
        profRut.value = p ? (p.rut || "") : "";
        // Los productos se filtran por la socia que emite: se recargan las opciones (conservando lo elegido si sigue siendo válido).
        itemsWrap.querySelectorAll(".di-prod").forEach(function (s) {
          var v = s.value; s.innerHTML = prodOptions();
          if (s.querySelector('option[value="' + v + '"]')) s.value = v; else { s.value = ""; s.dispatchEvent(new Event("change")); }
        });
        updateBadge(); recalc();
      });
    }

    // ---- Init ----
    updateBadge();
    resetItems();
    if (DOC.preCli && cliSel) { cliSel.value = String(DOC.preCli); onPaciente(); }

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var err = document.getElementById("doc-err"); err.classList.add("hidden");
      var items = [], manualSinDesc = false;
      itemsWrap.querySelectorAll(".doc-row").forEach(function (tr) {
        var d = rowData(tr);
        if (!d || d.precio_unit <= 0) return;
        if (d.manual && !d.descripcion) { manualSinDesc = true; return; }
        items.push({ descripcion: d.descripcion, cantidad: d.cantidad, precio_unit: d.precio_unit, afecto: d.afecto, reserva_ids: d.reserva ? [d.reserva] : [], producto_id: d.producto_id || 0 });
      });
      if (cliSel.value === "") { err.textContent = "Selecciona el paciente."; err.classList.remove("hidden"); return; }
      if (!rcActual || !rcActual.ok) { err.textContent = (rcActual && rcActual.error) || "Completa los datos del paciente en su ficha."; err.classList.remove("hidden"); return; }
      if (manualSinDesc) { err.textContent = "Escribe la descripción del ítem manual (o quítalo)."; err.classList.remove("hidden"); return; }
      if (profSel && !profSel.value) { err.textContent = "Elige qué socia emite la boleta."; err.classList.remove("hidden"); return; }
      if (!items.length) { err.textContent = "Agrega al menos un ítem con precio."; err.classList.remove("hidden"); return; }
      if (empTipo() === "factura" && !fRut.value.trim()) { err.textContent = rcActual.tipo === "tutor" ? "La factura requiere el RUT del tutor/apoderado. Complétalo en la ficha del paciente." : "La factura requiere el RUT del paciente. Complétalo en su ficha."; err.classList.remove("hidden"); return; }
      var payload = {
        empresa_id: empSel.value,
        paciente_id: fId.value, receptor_nombre: fNom.value.trim(), receptor_rut: fRut.value.trim(), receptor_email: fMail.value.trim(),
        receptor_direccion: fDir ? fDir.value.trim() : "",
        receptor_comuna: fCom ? fCom.value.trim() : "",
        receptor_codigo_prestacion: fCod ? fCod.value.trim() : "",
        profesional_id: profSel ? profSel.value : "",
        profesional_nombre: (profSel && profSel.value) ? profSel.options[profSel.selectedIndex].text : "",
        profesional_rut: profRut ? profRut.value.trim() : "",
        medio_pago: form.querySelector("[name=medio_pago]").value,
        glosa: form.querySelector("[name=glosa]").value.trim(),
        items: items
      };
      submit.disabled = true; var old = submit.textContent; submit.textContent = "Emitiendo…";
      var EZ = window.ezEmision;
      var esp = EZ ? EZ.enviando({ titulo: "Emitiendo " + (empSoloExento() ? "boleta exenta" : "boleta") + "…", texto: "Preparando el documento y enviándolo al SII. No cierres esta página." }) : null;
      function fallo(m) { if (esp) esp.cerrar(); err.textContent = m; err.classList.remove("hidden"); err.scrollIntoView({ block: "nearest", behavior: "smooth" }); submit.disabled = false; submit.textContent = old; }
      api("/api/panel/boletas", payload).then(function (x) {
        if (!x.ok) { fallo(x.d.error || "No se pudo emitir."); return; }
        var d = x.d, verUrl = u("/admin/boletas/" + d.id);
        if (esp) esp.cerrar();
        if (!EZ) { window.location.href = verUrl; return; }
        // Aviso en la misma pantalla: documento emitido y su estado en el SII (se actualiza solo).
        var tot = document.getElementById("t-total");
        EZ.listo({
          titulo: EZ.nombre(d.tipo_dte || (empSoloExento() ? 41 : 39)) + " N° " + String(d.folio).padStart(6, "0") + " emitida",
          subtitulo: payload.receptor_nombre + (tot ? " · " + tot.textContent : ""),
          estado: d.interno ? "interno" : (d.sii_estado || "aceptada"),
          trackid: d.trackid || "",
          ver: { url: verUrl, texto: "Ver documento" },
          otro: { url: u("/admin/boletas/nueva?empresa=" + encodeURIComponent(empSel.value)), texto: "Emitir otra" },
          documentos: u("/admin/documentos?empresa=" + encodeURIComponent(empSel.value)),
          revisar: function () {
            return api("/api/panel/boletas", { action: "sii_recheck", id: d.id, rapido: 1 })
              .then(function (y) { return y.ok ? { estado: y.d.estado, mensaje: y.d.mensaje } : { estado: "pendiente" }; });
          }
        });
      }).catch(function () { fallo("Se perdió la conexión mientras se emitía. Revisa en Documentos si quedó emitida antes de reintentar."); });
    });
  }
})();
