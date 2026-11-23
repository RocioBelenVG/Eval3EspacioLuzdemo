/*
 * Espacio Luz — interactividad del cliente (versión PHP).
 * Reemplaza los componentes "use client" de Next: Header, Boxes (carrusel),
 * MapaUbicacion, BoxGallery/Lightbox, ReservaWidget y el panel de edición inline.
 */
(function () {
  "use strict";
  var EZ = window.__EZ__ || { base: "", auth: false };
  function url(p) { return EZ.base + p; }
  // Diálogos con estilo del sitio (ver partials/ez-dialog.php); con respaldo nativo.
  function alertUI(m) { (window.ezAlert || window.alert)(m); }
  function confirmUI(m, o) { return window.ezConfirm ? window.ezConfirm(m, o) : Promise.resolve(window.confirm(m)); }
  function el(tag, cls, html) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (html != null) n.innerHTML = html;
    return n;
  }
  function loadScript(src) {
    return new Promise(function (res, rej) {
      var s = document.createElement("script");
      s.src = src; s.onload = res; s.onerror = rej;
      document.head.appendChild(s);
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    initHeader();
    initBoxCarousel();
    initMap();
    initGallery();
    initReserva();
    initAccordion();
    if (EZ.auth) { initEditMode(); initBoxMain(); }
  });

  /* -------------------------------------------------- Acordeón (páginas legales) */
  function accSetOpen(acc, open) {
    var panel = acc.querySelector(".ez-acc-panel");
    var ico = acc.querySelector(".ez-acc-ico");
    var head = acc.querySelector(".ez-acc-head");
    if (!panel) return;
    panel.classList.toggle("hidden", !open);
    if (ico) ico.classList.toggle("rotate-45", open);
    if (head) head.setAttribute("aria-expanded", open ? "true" : "false");
    acc.classList.toggle("is-open", open);
  }

  // En modo edición abrimos todos los paneles (para poder editar el detalle);
  // al salir, dejamos solo el primero abierto. Lo usa initEditMode.
  function accApplyEditing(on) {
    var accs = document.querySelectorAll(".ez-acc");
    for (var i = 0; i < accs.length; i++) accSetOpen(accs[i], on ? true : i === 0);
  }

  function initAccordion() {
    var accs = document.querySelectorAll(".ez-acc");
    accs.forEach(function (acc, i) {
      var head = acc.querySelector(".ez-acc-head");
      if (!head) return;
      accSetOpen(acc, i === 0); // primer panel abierto por defecto
      function toggle(e) {
        if (document.body.classList.contains("ez-editing")) return; // en edición no colapsa
        if (e && e.target && e.target.closest("a")) return;
        accSetOpen(acc, !acc.classList.contains("is-open"));
      }
      head.addEventListener("click", toggle);
      head.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") { e.preventDefault(); toggle(e); }
      });
    });
  }

  /* ------------------------------------------------------------------ Header */
  function initHeader() {
    var header = document.getElementById("site-header");
    var toggle = document.getElementById("ez-menu-toggle");
    var menu = document.getElementById("ez-mobile-menu");
    var open = false;

    function updateSolid() {
      if (!header) return;
      header.classList.toggle("solid", window.scrollY > 40 || open);
    }
    if (header) {
      window.addEventListener("scroll", updateSolid, { passive: true });
      updateSolid();
    }
    if (toggle && menu) {
      var bars = toggle.querySelectorAll(".hdr-bar");
      toggle.addEventListener("click", function () {
        open = !open;
        menu.style.maxHeight = open ? "24rem" : "0";
        toggle.setAttribute("aria-expanded", String(open));
        document.body.style.overflow = open ? "hidden" : "";
        if (bars.length === 3) {
          bars[0].style.transform = open ? "translateY(8px) rotate(45deg)" : "";
          bars[1].style.opacity = open ? "0" : "";
          bars[2].style.transform = open ? "translateY(-8px) rotate(-45deg)" : "";
        }
        updateSolid();
      });
      menu.querySelectorAll("[data-close-menu]").forEach(function (a) {
        a.addEventListener("click", function () {
          open = false; menu.style.maxHeight = "0"; document.body.style.overflow = "";
          if (bars.length === 3) { bars[0].style.transform = ""; bars[1].style.opacity = ""; bars[2].style.transform = ""; }
          updateSolid();
        });
      });
    }
  }

  /* ----------------------------------------------------------- Boxes carrusel */
  function initBoxCarousel() {
    var track = document.getElementById("ez-box-track");
    var dots = document.getElementById("ez-box-dots");
    var prev = document.getElementById("ez-box-prev");
    var next = document.getElementById("ez-box-next");
    if (!track) return;
    var slides = Array.prototype.slice.call(track.querySelectorAll(".ez-box-slide"));
    if (!slides.length) return;

    function slideW() { return slides[0].getBoundingClientRect().width; }

    slides.forEach(function (_, i) {
      var b = el("button", "h-2 rounded-full transition-all duration-300");
      b.setAttribute("aria-label", "Ir al box " + (i + 1));
      b.addEventListener("click", function () { pauseAwhile(); track.scrollTo({ left: i * slideW(), behavior: "smooth" }); });
      dots.appendChild(b);
    });

    function update() {
      var x = track.scrollLeft, w = slideW();
      var idx = Math.round(x / w);
      Array.prototype.forEach.call(dots.children, function (d, i) {
        d.className = "h-2 rounded-full transition-all duration-300 " +
          (i === idx ? "w-7 bg-terracota" : "w-2 bg-crema/30 hover:bg-crema/50");
      });
      if (prev) prev.disabled = x <= 2;
      if (next) next.disabled = x + track.clientWidth >= track.scrollWidth - 2;
    }
    if (prev) prev.addEventListener("click", function () { pauseAwhile(); track.scrollBy({ left: -slideW(), behavior: "smooth" }); });
    if (next) next.addEventListener("click", function () { pauseAwhile(); track.scrollBy({ left: slideW(), behavior: "smooth" }); });

    var ticking = false;
    track.addEventListener("scroll", function () {
      if (!ticking) { window.requestAnimationFrame(function () { update(); ticking = false; }); ticking = true; }
    });
    window.addEventListener("resize", update);
    update();

    // --- Autoplay: da movimiento al carrusel; se pausa al interactuar o pasar el mouse.
    var autoTimer = null, paused = false, resumeTimer = null;
    function hasOverflow() { return track.scrollWidth > track.clientWidth + 4; }
    function atEnd() { return track.scrollLeft + track.clientWidth >= track.scrollWidth - 4; }
    function advance() {
      if (paused || !hasOverflow()) return;
      // No mover el carrusel mientras un administrador edita contenidos.
      if (document.body.classList.contains("ez-editing")) return;
      if (atEnd()) track.scrollTo({ left: 0, behavior: "smooth" });
      else track.scrollBy({ left: slideW(), behavior: "smooth" });
    }
    function startAuto() { stopAuto(); if (hasOverflow()) autoTimer = window.setInterval(advance, 4500); }
    function stopAuto() { if (autoTimer) { window.clearInterval(autoTimer); autoTimer = null; } }
    function pauseAwhile() {
      paused = true; stopAuto();
      window.clearTimeout(resumeTimer);
      resumeTimer = window.setTimeout(function () { paused = false; startAuto(); }, 9000);
    }
    track.addEventListener("mouseenter", function () { paused = true; stopAuto(); });
    track.addEventListener("mouseleave", function () { paused = false; startAuto(); });
    track.addEventListener("touchstart", pauseAwhile, { passive: true });
    track.addEventListener("wheel", pauseAwhile, { passive: true });
    // Pausar cuando la pestaña no está visible (ahorra recursos).
    document.addEventListener("visibilitychange", function () {
      if (document.hidden) stopAuto(); else if (!paused) startAuto();
    });
    startAuto();
  }

  /* --------------------------------------------------------------------- Mapa */
  function initMap() {
    var mapEl = document.getElementById("ez-map");
    if (!mapEl) return;
    var run = function () {
      if (!window.maplibregl) return;
      var lng = parseFloat(mapEl.dataset.lng), lat = parseFloat(mapEl.dataset.lat);
      mapEl.innerHTML = "";
      var map = new maplibregl.Map({
        container: mapEl,
        style: mapEl.dataset.style,
        center: [lng, lat],
        zoom: 15,
        cooperativeGestures: true,
        locale: {
          "CooperativeGesturesHandler.WindowsHelpText": "Usa Ctrl + scroll para hacer zoom",
          "CooperativeGesturesHandler.MacHelpText": "Usa ⌘ + scroll para hacer zoom",
          "CooperativeGesturesHandler.MobileHelpText": "Usa dos dedos para mover el mapa"
        }
      });
      map.addControl(new maplibregl.NavigationControl({ showCompass: false }), "top-left");
      var pin = el("div", null,
        '<svg width="40" height="52" viewBox="0 0 40 52" xmlns="http://www.w3.org/2000/svg">' +
        '<path d="M20 0C9 0 0 9 0 20c0 14 20 32 20 32s20-18 20-32C40 9 31 0 20 0Z" fill="#D97757" stroke="#5C4033" stroke-width="1.5"/>' +
        '<circle cx="20" cy="19" r="7" fill="#F5F1E8"/></svg>');
      pin.style.cursor = "pointer";
      var popup = el("div", null,
        "<strong>" + escapeHtml(mapEl.dataset.nombre) + "</strong><br>" + escapeHtml(mapEl.dataset.direccion));
      new maplibregl.Marker({ element: pin, anchor: "bottom" })
        .setLngLat([lng, lat])
        .setPopup(new maplibregl.Popup({ offset: 40 }).setDOMContent(popup))
        .addTo(map);
    };
    if (window.maplibregl) run();
    else loadScript("https://cdnjs.cloudflare.com/ajax/libs/maplibre-gl/4.7.1/maplibre-gl.min.js").then(run).catch(function () {
      mapEl.innerHTML = '<div class="flex h-full w-full items-center justify-center bg-crema-dark/40"><span class="font-body text-sm text-cafe/50">No se pudo cargar el mapa.</span></div>';
    });
  }

  /* ------------------------------------------------------------------ Galería */
  function initGallery() {
    var g = document.getElementById("ez-gallery");
    if (!g) return;
    var images = safeJSON(g.dataset.images, []);
    var alt = g.dataset.alt || "";
    var main = document.getElementById("ez-gallery-main");
    var thumbs = document.getElementById("ez-gallery-thumbs");
    var prev = document.getElementById("ez-gallery-prev");
    var next = document.getElementById("ez-gallery-next");
    var idx = 0;

    function render() {
      main.style.opacity = "0";
      setTimeout(function () { main.src = images[idx]; main.style.opacity = "1"; }, 120);
      Array.prototype.forEach.call(thumbs.children, function (t, i) {
        t.className = "h-2.5 rounded-full transition-all duration-300 " +
          (i === idx ? "w-6 bg-terracota" : "w-2.5 bg-cafe/25 hover:bg-cafe/40");
      });
    }
    // Con una sola foto no hay nada que navegar: sin flechas ni puntos.
    if (images.length <= 1) { if (prev) prev.style.display = "none"; if (next) next.style.display = "none"; if (thumbs) thumbs.style.display = "none"; }
    images.forEach(function (_, i) {
      var d = el("button", "h-2.5 w-2.5 rounded-full bg-cafe/25");
      d.setAttribute("aria-label", "Imagen " + (i + 1));
      d.addEventListener("click", function () { idx = i; render(); });
      thumbs.appendChild(d);
    });
    if (prev) prev.addEventListener("click", function () { idx = (idx - 1 + images.length) % images.length; render(); });
    if (next) next.addEventListener("click", function () { idx = (idx + 1) % images.length; render(); });
    main.addEventListener("click", function () { openLightbox(images, idx, alt, function (i) { idx = i; render(); }); });
    render();
  }

  function openLightbox(images, start, alt, onChange) {
    var i = start;
    var ov = el("div", "fixed inset-0 z-[200] flex items-center justify-center bg-cafe/90 p-4");
    var img = el("img", "max-h-[85vh] max-w-[90vw] rounded-xl object-contain shadow-warm");
    img.src = images[i]; img.alt = alt;
    var close = el("button", "absolute right-5 top-5 flex h-11 w-11 items-center justify-center rounded-full bg-crema/15 text-crema hover:bg-terracota", "&times;");
    close.style.fontSize = "24px";
    function nav(step) { i = (i + step + images.length) % images.length; img.src = images[i]; if (onChange) onChange(i); }
    var p = el("button", "absolute left-5 top-1/2 flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full bg-crema/15 text-crema hover:bg-terracota",
      '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>');
    var n = el("button", "absolute right-5 top-1/2 flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full bg-crema/15 text-crema hover:bg-terracota",
      '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>');
    if (images.length <= 1) { p.style.display = "none"; n.style.display = "none"; }
    p.addEventListener("click", function (e) { e.stopPropagation(); nav(-1); });
    n.addEventListener("click", function (e) { e.stopPropagation(); nav(1); });
    function done() { document.removeEventListener("keydown", onKey); ov.remove(); }
    close.addEventListener("click", done);
    ov.addEventListener("click", function (e) { if (e.target === ov) done(); });
    function onKey(e) { if (e.key === "Escape") done(); else if (e.key === "ArrowLeft") nav(-1); else if (e.key === "ArrowRight") nav(1); }
    document.addEventListener("keydown", onKey);
    ov.append(img, close, p, n);
    document.body.appendChild(ov);
  }

  /* ------------------------------------------------------------ Reserva widget */
  var HORAS = ["08:00","09:00","10:00","11:00","12:00","13:00","14:00","15:00","16:00","17:00","18:00","19:00","20:00"];
  function hoyISO() { return new Date().toISOString().slice(0, 10); }

  function initReserva() {
    var root = document.getElementById("ez-reserva");
    if (!root) return;
    var data = safeJSON(root.dataset.reserva, null);
    if (!data) return;

    var st = { paso: "modalidad", modalidad: null, fecha: hoyISO(), hora: null, occupied: [], cerrado: null, loading: false, nombre: "", email: "", telefono: "", enviando: false, error: null };

    function esPorHora() { return st.modalidad && st.modalidad.label === "Por hora"; }

    function fetchHoras() {
      st.loading = true; renderStep();
      fetch(url("/api/reservas?box=" + encodeURIComponent(data.slug) + "&fecha=" + st.fecha))
        .then(function (r) { return r.json(); })
        .then(function (d) { st.occupied = d.occupiedHours || []; st.cerrado = d.cerrado || null; })
        .catch(function () { st.occupied = []; st.cerrado = null; })
        .finally(function () { st.loading = false; renderStep(); });
    }

    function progress() {
      var pasos = [["modalidad","1 · Modalidad"],["detalle","2 · Detalle"],["pago","3 · Pago"]];
      var idx = pasos.findIndex(function (p) { return p[0] === st.paso; });
      var w = el("div", "mt-5 flex gap-2");
      pasos.forEach(function (p, i) {
        var cls = i < idx ? "bg-salvia/25 text-salvia-dark" : i === idx ? "bg-terracota text-crema-light" : "bg-cafe/10 text-cafe/40";
        w.appendChild(el("span", "flex-1 rounded-full px-3 py-1.5 text-center font-body text-xs font-semibold " + cls, p[1]));
      });
      return w;
    }

    function fmtFecha(f) {
      try { return new Date(f + "T00:00:00").toLocaleDateString("es-CL", { weekday: "long", day: "numeric", month: "long" }); }
      catch (e) { return f; }
    }

    function renderStep() {
      root.innerHTML = "";
      if (st.paso !== "confirmado") root.appendChild(progress());

      if (st.paso === "modalidad") {
        var box = el("div", "mt-6 divide-y divide-cafe/10");
        data.tarifas.forEach(function (t) {
          var b = el("button", "flex w-full items-center justify-between gap-4 py-3 text-left transition-colors hover:bg-terracota/5");
          b.type = "button";
          var right = t.label + (t.sub ? "<br>" + escapeHtml(t.sub) : "") + (t.gastosComunes ? '<br><span class="text-cafe/45">+ ' + escapeHtml(t.gastosComunes) + " gastos comunes</span>" : "");
          b.innerHTML = '<span class="font-heading text-lg font-semibold text-terracota">' + escapeHtml(t.precio) + '</span>' +
            '<span class="text-right font-body text-xs text-cafe/60">' + right + '</span>';
          b.addEventListener("click", function () { st.modalidad = t; st.hora = null; st.paso = "detalle"; fetchHoras(); });
          box.appendChild(b);
        });
        root.appendChild(box);
      }

      else if (st.paso === "detalle") {
        var m = st.modalidad;
        var c = el("div", "mt-6");
        c.appendChild(el("p", "font-body text-sm text-cafe/70",
          '<strong class="text-cafe">' + escapeHtml(m.precio) + "</strong> · " + escapeHtml(m.label) + (m.sub ? " · " + escapeHtml(m.sub) : "")));

        var lab = el("label", "mt-4 flex flex-col gap-1.5");
        lab.appendChild(el("span", "font-body text-xs font-semibold uppercase tracking-wide text-cafe/70", "Fecha"));
        var date = el("input", "w-fit rounded-lg border border-cafe/20 bg-crema-light px-3 py-2.5 font-body text-sm text-cafe outline-none transition-colors focus:border-terracota");
        date.type = "date"; date.min = hoyISO(); date.value = st.fecha;
        date.addEventListener("change", function () { st.fecha = date.value; st.hora = null; fetchHoras(); });
        lab.appendChild(date); c.appendChild(lab);

        if (st.cerrado && !st.loading) {
          c.appendChild(el("p", "mt-4 rounded-lg border border-violet-300 bg-violet-50 p-3 font-body text-sm font-semibold text-violet-800", escapeHtml(st.cerrado)));
        } else if (esPorHora()) {
          var leg = el("div", "mt-5");
          leg.appendChild(el("div", "mb-3 flex items-center gap-4 font-body text-xs text-cafe/60",
            '<span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded border border-cafe/20 bg-crema-light"></span> Disponible</span>' +
            '<span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded bg-cafe/15"></span> Ocupado</span>'));
          var grid = el("div", "grid grid-cols-3 gap-2 sm:grid-cols-4");
          HORAS.forEach(function (h) {
            var ocup = st.occupied.indexOf(h) !== -1;
            var sel = st.hora === h;
            var cls = "rounded-lg py-2 font-body text-sm font-semibold transition-colors " +
              (ocup ? "cursor-not-allowed bg-cafe/10 text-cafe/30 line-through" : sel ? "bg-terracota text-crema-light" : "bg-crema-light text-cafe hover:bg-terracota/10");
            var hb = el("button", cls, h); hb.type = "button"; hb.disabled = ocup || st.loading;
            hb.addEventListener("click", function () { st.hora = h; renderStep(); });
            grid.appendChild(hb);
          });
          leg.appendChild(grid); c.appendChild(leg);
        } else {
          c.appendChild(el("p", "mt-4 rounded-lg bg-salvia/10 p-3 font-body text-sm text-cafe/70",
            "Este plan se coordina por jornada o mes completo — al confirmar, un miembro del equipo te contactará para afinar el detalle exacto."));
        }

        var nav = el("div", "mt-6 flex items-center justify-between");
        var back = el("button", "font-body text-sm font-semibold text-cafe/60 hover:text-terracota", "← Cambiar modalidad"); back.type = "button";
        back.addEventListener("click", function () { st.paso = "modalidad"; renderStep(); });
        var cont = el("button", "rounded-full bg-terracota px-6 py-2.5 font-body text-sm font-semibold text-crema-light transition-colors hover:bg-terracota-dark disabled:opacity-40", "Siguiente →");
        cont.type = "button"; cont.disabled = !!st.cerrado || st.loading || (esPorHora() ? !st.hora : !st.fecha);
        cont.addEventListener("click", function () { st.paso = "pago"; renderStep(); });
        nav.append(back, cont); c.appendChild(nav);
        root.appendChild(c);
      }

      else if (st.paso === "pago") {
        var mm = st.modalidad;
        var c2 = el("div", "mt-6");
        c2.appendChild(el("div", "rounded-lg bg-crema-light p-4 font-body text-sm text-cafe/80",
          '<p class="font-semibold text-cafe">' + escapeHtml(data.nombre) + "</p>" +
          "<p>" + escapeHtml(mm.precio) + " · " + escapeHtml(mm.label) + (mm.sub ? " · " + escapeHtml(mm.sub) : "") + "</p>" +
          "<p>" + fmtFecha(st.fecha) + (st.hora ? " · " + st.hora + " h" : "") + "</p>"));

        var fields = el("div", "mt-5 flex flex-col gap-4");
        fields.appendChild(inputField("Nombre completo", "text", st.nombre, function (v) { st.nombre = v; syncConfirm(); }));
        fields.appendChild(inputField("Correo", "email", st.email, function (v) { st.email = v; syncConfirm(); }));
        fields.appendChild(inputField("Teléfono", "tel", st.telefono, function (v) { st.telefono = v; syncConfirm(); }));
        c2.appendChild(fields);

        c2.appendChild(el("p", "mt-5 rounded-lg border border-terracota/30 bg-terracota/5 p-3 font-body text-xs text-cafe/70",
          "El pago en línea todavía está en desarrollo. Al confirmar, tu solicitud queda registrada y te contactaremos para coordinar el pago y confirmar la reserva."));

        if (st.error) c2.appendChild(el("p", "mt-3 font-body text-sm font-medium text-red-600", escapeHtml(st.error)));

        var nav2 = el("div", "mt-6 flex items-center justify-between");
        var back2 = el("button", "font-body text-sm font-semibold text-cafe/60 hover:text-terracota", "← Cambiar detalle"); back2.type = "button";
        back2.addEventListener("click", function () { st.paso = "detalle"; fetchHoras(); });
        var conf = el("button", "rounded-full bg-terracota px-6 py-2.5 font-body text-sm font-semibold text-crema-light transition-colors hover:bg-terracota-dark disabled:opacity-40",
          st.enviando ? "Confirmando…" : "Confirmar reserva");
        conf.type = "button"; conf.id = "ez-reserva-confirm";
        conf.disabled = !st.nombre || !st.email || !st.telefono || st.enviando;
        conf.addEventListener("click", confirmar);
        nav2.append(back2, conf); c2.appendChild(nav2);
        root.appendChild(c2);
      }

      else if (st.paso === "confirmado") {
        var ok = el("div", "mt-6 text-center");
        ok.innerHTML =
          '<div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-salvia/20 text-salvia-dark">' +
          '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/></svg></div>' +
          '<h3 class="mt-4 font-heading text-lg font-semibold text-cafe">¡Solicitud registrada!</h3>' +
          '<p class="mt-2 font-body text-sm text-cafe/70">Te contactaremos a ' + escapeHtml(st.email) + " para coordinar el pago y confirmar tu reserva de " +
          escapeHtml(data.nombre) + (st.hora ? " el " + st.fecha + " a las " + st.hora : " el " + st.fecha) + ".</p>";
        var again = el("button", "mt-5 rounded-full border-2 border-cafe/20 px-6 py-2.5 font-body text-sm font-semibold text-cafe transition-colors hover:border-terracota hover:text-terracota", "Hacer otra reserva");
        again.type = "button";
        again.addEventListener("click", function () { st = { paso: "modalidad", modalidad: null, fecha: hoyISO(), hora: null, occupied: [], cerrado: null, loading: false, nombre: "", email: "", telefono: "", enviando: false, error: null }; renderStep(); });
        ok.appendChild(again);
        root.appendChild(ok);
      }
    }

    function syncConfirm() {
      var c = document.getElementById("ez-reserva-confirm");
      if (c) c.disabled = !st.nombre || !st.email || !st.telefono || st.enviando;
    }

    function inputField(label, type, value, onInput) {
      var l = el("label", "flex flex-col gap-1.5");
      l.appendChild(el("span", "font-body text-xs font-semibold uppercase tracking-wide text-cafe/70", label));
      var inp = el("input", "rounded-lg border border-cafe/20 bg-crema-light px-3 py-2.5 font-body text-sm text-cafe outline-none transition-colors focus:border-terracota");
      inp.type = type; inp.value = value; inp.required = true;
      inp.addEventListener("input", function () { onInput(inp.value); });
      l.appendChild(inp);
      return l;
    }

    function confirmar() {
      if (!st.modalidad) return;
      st.enviando = true; st.error = null; renderStep();
      fetch(url("/api/reservas"), {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          boxSlug: data.slug, modalidadLabel: st.modalidad.label, modalidadSub: st.modalidad.sub,
          precio: st.modalidad.precio, fecha: st.fecha, hora: esPorHora() ? st.hora : undefined,
          nombre: st.nombre, email: st.email, telefono: st.telefono
        })
      }).then(function (res) {
        return res.json().then(function (d) { return { ok: res.ok, status: res.status, d: d }; });
      }).then(function (r) {
        if (!r.ok) { st.error = r.d.error || "No se pudo confirmar la reserva."; st.enviando = false; if (r.status === 409) { st.paso = "detalle"; fetchHoras(); return; } renderStep(); return; }
        st.paso = "confirmado"; st.enviando = false; renderStep();
      }).catch(function () { st.error = "No se pudo conectar con el servidor. Intenta de nuevo."; st.enviando = false; renderStep(); });
    }

    renderStep();
  }

  /* --------------------------------------------------------- Panel de edición */
  // Mantiene el botón flotante siempre visible, incluso cuando la barra del
  // navegador móvil aparece al desplazar hacia arriba (usa el viewport visible).
  function pinEditToggle() {
    var tog = document.getElementById("ez-edit-toggle");
    var vv = window.visualViewport;
    if (!tog || !vv) return;
    function reposition() {
      var gap = window.innerHeight - (vv.height + vv.offsetTop);
      if (gap < 0) gap = 0;
      tog.style.bottom = "calc(1.5rem + env(safe-area-inset-bottom, 0px) + " + gap + "px)";
    }
    vv.addEventListener("resize", reposition);
    vv.addEventListener("scroll", reposition);
    reposition();
  }

  function initEditMode() {
    pinEditToggle();
    var btn = document.getElementById("ez-edit-btn");
    var hint = document.getElementById("ez-edit-hint");
    var editing = false;

    if (btn) {
      btn.addEventListener("click", function () {
        editing = !editing;
        document.body.classList.toggle("ez-editing", editing);
        btn.textContent = editing ? "Salir de edición" : "Editar contenidos";
        btn.className = "rounded-full px-5 py-3 font-body text-sm font-semibold shadow-warm transition-colors " +
          (editing ? "bg-salvia-dark text-crema-light hover:bg-salvia" : "bg-terracota text-crema-light hover:bg-terracota-dark");
        if (hint) hint.classList.toggle("hidden", !editing);
        if (editing) { enableImageSlots(); enableProfPhotos(); enablePerfilLists(); } else { disableImageSlots(); disableProfPhotos(); disablePerfilLists(); }
        accApplyEditing(editing);
        renderGalleryEditor(editing);
      });
    }

    // --- Texto editable inline ---
    document.addEventListener("click", function (e) {
      if (!editing) return;
      var node = e.target.closest(".ez-editable");
      if (!node || node.dataset.ezActive === "1") return;
      startTextEdit(node);
    });

    // En modo edición los enlaces no navegan: así se pueden editar los textos
    // que viven dentro de un <a> (correo, Instagram, teléfono) sin salir de la
    // página. Se usa la fase de captura para adelantarse a la navegación.
    document.addEventListener("click", function (e) {
      if (!editing) return;
      if (e.target.closest("a")) e.preventDefault();
    }, true);
  }

  // Edición en el propio elemento (contenteditable): conserva exactamente el
  // color, la fuente y el tamaño del texto de la página, sin sustituirlo por un
  // campo de formulario. Al terminar, guarda el cambio en la base de datos.
  function startTextEdit(node) {
    if (node.isContentEditable) return;
    var target = safeJSON(node.dataset.ezTarget, null);
    if (!target) return;
    var multiline = node.dataset.ezMl === "1";
    var original = node.textContent;

    node.setAttribute("contenteditable", "true");
    node.setAttribute("spellcheck", "false");
    node.focus();
    placeCaretEnd(node);

    function onKey(e) {
      if (e.key === "Enter" && !multiline) { e.preventDefault(); node.blur(); }
      else if (e.key === "Escape") { e.preventDefault(); node.textContent = original; node.blur(); }
    }
    function onBlur() {
      node.removeEventListener("keydown", onKey);
      node.removeEventListener("blur", onBlur);
      node.removeAttribute("contenteditable");
      node.removeAttribute("spellcheck");
      var val = (node.textContent || "").replace(/ /g, " ").replace(/\s+/g, " ").trim();
      if (!val) { node.textContent = original; return; }
      node.textContent = val;
      if (val === original.replace(/\s+/g, " ").trim()) return;
      saveEdit(node, target, val);
    }
    node.addEventListener("keydown", onKey);
    node.addEventListener("blur", onBlur);
  }

  function saveEdit(node, target, val) {
    var badge = el("span", "ez-save-badge", "Guardando…");
    badge.style.background = "#F5F1E8"; badge.style.color = "rgba(92,64,51,.7)";
    var prevPos = node.style.position;
    if (getComputedStyle(node).position === "static") node.style.position = "relative";
    node.appendChild(badge);
    fetch(url("/api/content"), {
      method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": (window.__EZ__ || {}).csrf || "" },
      body: JSON.stringify({ target: target, value: val })
    }).then(function (r) {
      if (!r.ok) throw new Error();
      badge.textContent = "Guardado ✓";
      badge.style.background = "#6D8072"; badge.style.color = "#FBF9F4";
      setTimeout(function () { badge.remove(); node.style.position = prevPos; }, 1500);
    }).catch(function () {
      badge.textContent = "Error al guardar";
      badge.style.background = "#ef4444"; badge.style.color = "#fff";
      setTimeout(function () { badge.remove(); node.style.position = prevPos; }, 2500);
    });
  }

  function placeCaretEnd(node) {
    try {
      var range = document.createRange();
      range.selectNodeContents(node);
      range.collapse(false);
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
    } catch (e) { /* sin soporte de selección: se ignora */ }
  }

  // --- Imágenes de slots ---
  function enableImageSlots() {
    document.querySelectorAll(".ez-image-slot").forEach(function (slot) {
      if (slot.dataset.ezReady === "1") { slot.querySelector(".ez-slot-ui").style.display = ""; return; }
      slot.dataset.ezReady = "1";
      var ui = el("div", "ez-slot-ui");
      var btn = el("button", "absolute left-2 top-2 z-10 inline-flex items-center gap-1.5 rounded-full bg-cafe/80 px-3 py-1.5 font-body text-xs font-semibold text-crema shadow-card backdrop-blur-sm transition-colors hover:bg-terracota",
        '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z" stroke-linecap="round" stroke-linejoin="round"/></svg> Cambiar imagen');
      btn.type = "button";
      var size = el("span", "pointer-events-none absolute bottom-2 right-2 z-10 rounded bg-cafe/70 px-1.5 py-0.5 font-body text-[10px] text-crema/90", "Sugerido: " + (slot.dataset.ezSize || ""));
      var input = el("input"); input.type = "file"; input.accept = "image/*"; input.className = "hidden";
      btn.addEventListener("click", function () { input.click(); });
      input.addEventListener("change", function () {
        var f = input.files[0]; input.value = ""; if (!f) return;
        btn.textContent = "Subiendo…"; btn.disabled = true;
        var form = new FormData(); form.append("slot", slot.dataset.ezSlot); form.append("file", f);
        fetch(url("/api/content/upload-image"), { method: "POST", body: form, headers: { "X-CSRF-Token": (window.__EZ__ || {}).csrf || "" } })
          .then(function (r) { if (!r.ok) throw new Error(); location.reload(); })
          .catch(function () { alertUI("No se pudo subir la imagen. Intenta de nuevo."); btn.disabled = false; });
      });
      ui.append(btn, size, input);
      slot.appendChild(ui);
    });
  }
  function disableImageSlots() {
    document.querySelectorAll(".ez-image-slot .ez-slot-ui").forEach(function (ui) { ui.style.display = "none"; });
  }

  // --- Foto de profesional (subir/cambiar) ---
  function enableProfPhotos() {
    document.querySelectorAll(".ez-prof-photo").forEach(function (slot) {
      if (slot.dataset.ezReady === "1") { var u = slot.querySelector(".ez-slot-ui"); if (u) u.style.display = ""; return; }
      slot.dataset.ezReady = "1";
      var ui = el("div", "ez-slot-ui");
      var btn = el("button", "absolute left-1/2 top-3 z-10 inline-flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-cafe/80 px-3 py-1.5 font-body text-xs font-semibold text-crema shadow-card backdrop-blur-sm transition-colors hover:bg-terracota",
        '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2Z" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="13" r="4"/></svg> Cambiar foto');
      btn.type = "button";
      var input = el("input"); input.type = "file"; input.accept = "image/jpeg,image/png,image/webp"; input.className = "hidden";
      btn.addEventListener("click", function () { input.click(); });
      input.addEventListener("change", function () {
        var f = input.files[0]; input.value = ""; if (!f) return;
        btn.disabled = true; var prev = btn.innerHTML; btn.textContent = "Subiendo…";
        var form = new FormData(); form.append("id", slot.dataset.ezProf); form.append("file", f);
        fetch(url("/api/content/upload-professional-photo"), { method: "POST", body: form, headers: { "X-CSRF-Token": (window.__EZ__ || {}).csrf || "" } })
          .then(function (r) { if (!r.ok) throw new Error(); location.reload(); })
          .catch(function () { alertUI("No se pudo subir la foto. Usa JPG, PNG o WebP de hasta 8 MB."); btn.disabled = false; btn.innerHTML = prev; });
      });
      ui.append(btn, input);
      slot.appendChild(ui);
    });
  }
  function disableProfPhotos() {
    document.querySelectorAll(".ez-prof-photo .ez-slot-ui").forEach(function (ui) { ui.style.display = "none"; });
  }

  // --- Listas del perfil (chips/areas/formacion/atiende) ---
  var ezListModal = null;
  function ezGetListItems(listEl) {
    return Array.prototype.map.call(listEl.querySelectorAll(".ez-li"), function (li) {
      var t = li.querySelector(".ez-li-text");
      return ((t ? t.textContent : li.textContent) || "").replace(/\s+/g, " ").trim();
    }).filter(function (s) { return s !== ""; });
  }
  function ensureListModal() {
    if (ezListModal) return ezListModal;
    var ov = el("div", "fixed inset-0 z-[120] hidden items-center justify-center bg-cafe/60 p-4 backdrop-blur-sm");
    ov.innerHTML = '<div class="w-full max-w-md rounded-3xl bg-crema-light p-6 shadow-warm">' +
      '<h3 class="mb-1 font-heading text-xl font-semibold text-cafe" data-ez-ml-title>Editar lista</h3>' +
      '<p class="mb-3 font-body text-xs text-cafe/55">Un elemento por línea.</p>' +
      '<textarea data-ez-ml-ta rows="7" class="w-full rounded-lg border border-cafe/20 bg-white/70 px-3 py-2.5 font-body text-sm text-cafe outline-none focus:border-terracota"></textarea>' +
      '<p data-ez-ml-err class="mt-2 hidden font-body text-sm font-medium text-red-600"></p>' +
      '<div class="mt-3 flex justify-end gap-2"><button type="button" data-ez-ml-cancel class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota">Cancelar</button>' +
      '<button type="button" data-ez-ml-save class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Guardar</button></div></div>';
    document.body.appendChild(ov);
    ov.addEventListener("click", function (e) { if (e.target === ov) hideListModal(); });
    ov.querySelector("[data-ez-ml-cancel]").addEventListener("click", hideListModal);
    ezListModal = ov;
    return ov;
  }
  function hideListModal() { if (ezListModal) { ezListModal.classList.add("hidden"); ezListModal.classList.remove("flex"); } }
  function openListModal(listEl) {
    var m = ensureListModal();
    var key = listEl.dataset.ezList, id = listEl.dataset.ezProf, label = listEl.dataset.ezLabel || "lista";
    m.querySelector("[data-ez-ml-title]").textContent = "Editar: " + label;
    var ta = m.querySelector("[data-ez-ml-ta]");
    ta.value = ezGetListItems(listEl).join("\n");
    var err = m.querySelector("[data-ez-ml-err]"); err.classList.add("hidden");
    var saveBtn = m.querySelector("[data-ez-ml-save]");
    var fresh = saveBtn.cloneNode(true); saveBtn.parentNode.replaceChild(fresh, saveBtn);
    fresh.addEventListener("click", function () {
      var items = ta.value.split("\n").map(function (s) { return s.replace(/\s+/g, " ").trim(); }).filter(function (s) { return s !== ""; });
      fresh.disabled = true; fresh.textContent = "Guardando…";
      fetch(url("/api/content"), { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": (window.__EZ__ || {}).csrf || "" },
        body: JSON.stringify({ target: { type: "profesional-perfil-lista", id: parseInt(id, 10), key: key }, items: items }) })
        .then(function (r) { if (!r.ok) throw new Error(); location.reload(); })
        .catch(function () { err.textContent = "No se pudo guardar."; err.classList.remove("hidden"); fresh.disabled = false; fresh.textContent = "Guardar"; });
    });
    m.classList.remove("hidden"); m.classList.add("flex");
  }
  function enablePerfilLists() {
    document.querySelectorAll(".ez-list").forEach(function (listEl) {
      if (listEl.dataset.ezReady === "1") { var b = listEl.querySelector(".ez-list-edit"); if (b) b.style.display = ""; return; }
      listEl.dataset.ezReady = "1";
      var btn = el("button", "ez-list-edit mt-3 inline-flex items-center gap-1.5 rounded-full border border-terracota/40 bg-terracota/5 px-3 py-1.5 font-body text-xs font-semibold text-terracota-dark hover:bg-terracota/10",
        '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z" stroke-linecap="round" stroke-linejoin="round"/></svg> Editar lista');
      btn.type = "button";
      btn.addEventListener("click", function () { openListModal(listEl); });
      listEl.appendChild(btn);
    });
  }
  function disablePerfilLists() {
    document.querySelectorAll(".ez-list .ez-list-edit").forEach(function (b) { b.style.display = "none"; });
  }

  // --- Foto principal del box (hero): subir / eliminar, estilo profesionales ---
  function initBoxMain() {
    document.querySelectorAll(".ez-boxmain").forEach(function (root) {
      var slug = root.dataset.box;
      var csrf = (window.__EZ__ || {}).csrf || "";
      var changeBtn = root.querySelector(".ez-boxmain-change");
      var delBtn = root.querySelector(".ez-boxmain-del");
      var input = root.querySelector(".ez-boxmain-input");
      if (changeBtn && input) {
        changeBtn.addEventListener("click", function (e) { e.preventDefault(); e.stopPropagation(); input.click(); });
        input.addEventListener("change", function () {
          var f = input.files[0]; input.value = ""; if (!f) return;
          changeBtn.disabled = true;
          var form = new FormData(); form.append("boxSlug", slug); form.append("main", "1"); form.append("file", f);
          fetch(url("/api/content/upload-gallery-image"), { method: "POST", body: form, headers: { "X-CSRF-Token": csrf } })
            .then(function (r) { if (!r.ok) throw new Error(); location.reload(); })
            .catch(function () { alertUI("No se pudo subir la foto. Usa JPG, PNG o WebP de hasta 8 MB."); changeBtn.disabled = false; });
        });
      }
      if (delBtn) delBtn.addEventListener("click", function (e) {
        e.preventDefault(); e.stopPropagation();
        if (root.dataset.hasupload !== "1") { alertUI("Este box aún no tiene foto. Usa «Cambiar foto» para subir una."); return; }
        confirmUI("¿Eliminar la foto principal? El box quedará sin foto hasta que subas otra.", { title: "Eliminar foto", okText: "Eliminar", danger: true }).then(function (ok) {
          if (!ok) return;
          fetch(url("/api/content/upload-gallery-image"), { method: "DELETE", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify({ boxSlug: slug, main: true }) })
            .then(function (r) { if (!r.ok) throw new Error(); location.reload(); })
            .catch(function () { alertUI("No se pudo eliminar la foto."); });
        });
      });
    });
  }

  // --- Galería del box (admin): fotos adicionales, con cambiar/eliminar por foto ---
  var GAL_CAM = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2Z"/><circle cx="12" cy="13" r="4"/></svg>';
  var GAL_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1L5 6"/></svg>';
  function renderGalleryEditor(editing) {
    var host = document.getElementById("ez-gallery-editor");
    if (!host) return;
    if (!editing) { host.innerHTML = ""; return; }
    var slug = host.dataset.box;
    var extra = safeJSON(host.dataset.extra, []);
    var extraRaw = safeJSON(host.dataset.extraRaw, []);
    var csrf = (window.__EZ__ || {}).csrf || "";
    function galUpload(file) { var form = new FormData(); form.append("boxSlug", slug); form.append("file", file); return fetch(url("/api/content/upload-gallery-image"), { method: "POST", body: form, headers: { "X-CSRF-Token": csrf } }); }
    function galDelete(path) { return fetch(url("/api/content/upload-gallery-image"), { method: "DELETE", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify({ boxSlug: slug, path: path }) }); }
    function galSaveOrder(order) { return fetch(url("/api/content/upload-gallery-image"), { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify({ action: "orden", boxSlug: slug, orden: order }) }); }

    host.innerHTML = "";
    var wrap = el("div", "mt-4 rounded-xl border-2 border-dashed border-terracota/40 bg-terracota/5 p-4");
    var row = el("div", "flex flex-wrap items-center gap-3");

    // Orden actual de las celdas (para arrastrar y reordenar).
    var origOrder = extraRaw.slice();
    function currentOrder() { return Array.prototype.map.call(row.querySelectorAll(".ez-galcell"), function (c) { return c.dataset.raw; }); }
    function saveOrderIfChanged() {
      var now = currentOrder();
      var changed = now.length !== origOrder.length || now.some(function (v, i) { return v !== origOrder[i]; });
      if (changed) { galSaveOrder(now).then(function (r) { if (r.ok) { location.reload(); } }); }
    }

    extra.forEach(function (imgUrl, i) {
      var cell = el("div", "ez-galcell relative h-24 w-24 cursor-move overflow-hidden rounded-xl shadow-card");
      cell.draggable = true;
      cell.dataset.raw = extraRaw[i];
      cell.addEventListener("dragstart", function (e) { cell.classList.add("ez-dragging"); cell.style.opacity = "0.4"; try { e.dataTransfer.effectAllowed = "move"; } catch (x) {} });
      cell.addEventListener("dragend", function () { cell.classList.remove("ez-dragging"); cell.style.opacity = ""; if (add && add.parentNode === row) { row.appendChild(add); } saveOrderIfChanged(); });
      cell.addEventListener("dragover", function (e) {
        e.preventDefault();
        var dragging = row.querySelector(".ez-dragging");
        if (!dragging || dragging === cell) return;
        var rect = cell.getBoundingClientRect();
        var after = (e.clientX - rect.left) > rect.width / 2;
        row.insertBefore(dragging, after ? cell.nextSibling : cell);
      });
      cell.innerHTML = '<img src="' + escapeAttr(imgUrl) + '" alt="" draggable="false" class="h-full w-full object-cover pointer-events-none">';
      var ov = el("div", "ez-ovl absolute inset-0 flex items-center justify-center gap-1.5 rounded-xl");
      var chg = el("button", "ez-ovb", GAL_CAM); chg.type = "button"; chg.title = "Cambiar";
      var chgIn = el("input"); chgIn.type = "file"; chgIn.accept = "image/jpeg,image/png,image/webp"; chgIn.className = "hidden";
      chg.addEventListener("click", function () { chgIn.click(); });
      chgIn.addEventListener("change", function () {
        var f = chgIn.files[0]; chgIn.value = ""; if (!f) return; chg.disabled = true;
        galUpload(f).then(function (r) { if (!r.ok) throw new Error(); return galDelete(extraRaw[i]); })
          .then(function () { location.reload(); })
          .catch(function () { alertUI("No se pudo cambiar la imagen."); chg.disabled = false; });
      });
      var del = el("button", "ez-ovb ez-ovb-danger", GAL_TRASH); del.type = "button"; del.title = "Eliminar";
      del.addEventListener("click", function () {
        confirmUI("¿Quitar esta foto de la galería?", { title: "Quitar foto", okText: "Quitar", danger: true }).then(function (ok) {
          if (!ok) return;
          galDelete(extraRaw[i]).then(function (r) { if (!r.ok) throw new Error(); location.reload(); })
            .catch(function () { alertUI("No se pudo quitar la imagen."); });
        });
      });
      ov.append(chg, del, chgIn);
      cell.appendChild(ov);
      row.appendChild(cell);
    });

    var add = el("button", "flex h-24 w-24 flex-col items-center justify-center gap-0.5 rounded-xl border-2 border-dashed border-cafe/30 font-body text-xs font-semibold text-cafe/50 transition-colors hover:border-terracota hover:text-terracota");
    add.type = "button"; add.innerHTML = '<span class="text-2xl leading-none">+</span>Agregar';
    var input = el("input"); input.type = "file"; input.accept = "image/*"; input.multiple = true; input.className = "hidden";
    add.addEventListener("click", function () { input.click(); });
    input.addEventListener("change", function () {
      var files = Array.prototype.slice.call(input.files); input.value = ""; if (!files.length) return;
      add.innerHTML = '<span class="text-base">Subiendo…</span>';
      // Sube una por una (en secuencia) y recarga al terminar.
      (function next(i) {
        if (i >= files.length) { location.reload(); return; }
        add.innerHTML = '<span class="text-base leading-tight">Subiendo</span><span class="text-[11px]">' + (i + 1) + ' / ' + files.length + '</span>';
        galUpload(files[i])
          .then(function (r) { if (!r.ok) throw new Error(); next(i + 1); })
          .catch(function () {
            alertUI(i === 0 ? "No se pudo subir la imagen. Intenta de nuevo." : ("Se subieron " + i + " de " + files.length + " imágenes."));
            location.reload();
          });
      })(0);
    });
    row.append(add, input);
    wrap.appendChild(row);
    host.appendChild(wrap);
  }

  /* ------------------------------------------------------------------ Utils */
  function safeJSON(s, fallback) { try { return JSON.parse(s); } catch (e) { return fallback; } }
  function escapeHtml(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]; }); }
  function escapeAttr(s) { return escapeHtml(s); }
})();
