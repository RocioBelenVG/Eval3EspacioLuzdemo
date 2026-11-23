<?php
/**
 * Página pública · Agenda tu hora online (solo profesionales con agenda_online).
 * Flujo por pasos: profesional → fecha y hora → datos → confirmación.
 * El pago se integrará como paso final más adelante.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/disponibilidad.php';
require_once APP_ROOT . '/lib/productos.php';
require_once APP_ROOT . '/partials/profesionales-fotos.php';

$content = getContent();
$hasLogo = hasLogo();

$agendables = profesionalesAgendables();
$preSlug = trim((string) ($_GET['prof'] ?? ''));

// Datos para el JS (id, nombre, slug, especialidad, iniciales, foto, servicios)
$profJs = array_map(function ($p) {
    $foto = profesionalFotoUrl($p) ?? '';
    $servs = !empty($p['empresa_id']) ? productosLista((int) $p['empresa_id'], false, (int) $p['id']) : [];
    $servicios = array_map(fn($s) => [
        'id' => (int) $s['id'],
        'nombre' => $s['nombre'],
        'descripcion' => $s['descripcion'] ?? '',
        'precio' => (int) $s['precio_neto'],
    ], $servs);
    return [
        'id' => (int) $p['id'],
        'nombre' => $p['nombre'],
        'slug' => $p['slug'],
        'especialidad' => $p['especialidad_nombre'] ?: '',
        'iniciales' => getIniciales($p['nombre']),
        'foto' => $foto,
        'servicios' => $servicios,
    ];
}, $agendables);

$pageTitle = 'Agenda tu hora — Espacio Luz';
include APP_ROOT . '/partials/head.php';
include APP_ROOT . '/partials/header.php';
?>
<main class="bg-warm-gradient">
<section class="relative overflow-hidden py-16 sm:py-24">
    <div class="pointer-events-none absolute -right-24 top-10 h-72 w-72 rounded-full bg-salvia/10 blur-3xl"></div>
    <div class="pointer-events-none absolute -left-24 bottom-0 h-80 w-80 rounded-full bg-terracota/10 blur-3xl"></div>

    <div class="container relative max-w-3xl">
        <div class="text-center">
            <span class="mb-3 inline-block font-body text-xs font-bold uppercase tracking-[0.2em] text-terracota sm:text-sm">Reserva en línea</span>
            <h1 class="font-heading text-3xl font-semibold leading-tight text-cafe sm:text-4xl">Agenda tu hora</h1>
            <p class="mt-3 font-body text-base text-cafe/75">Reserva una sesión con nuestras profesionales. Te enviaremos el comprobante a tu correo.</p>
        </div>

        <!-- Indicador de pasos -->
        <ol id="ag-steps" class="mx-auto mt-8 flex max-w-xl items-center justify-between gap-1 font-body text-xs font-semibold">
            <?php foreach (['Profesional', 'Fecha y hora', 'Tus datos', 'Listo'] as $si => $sl): ?>
                <li class="ag-step flex flex-1 flex-col items-center gap-1.5 text-cafe/40" data-step="<?= $si + 1 ?>">
                    <span class="ag-dot flex h-8 w-8 items-center justify-center rounded-full border-2 border-cafe/15 bg-crema-light"><?= $si + 1 ?></span>
                    <span class="ag-label hidden text-center sm:block"><?= e($sl) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>

        <div class="mt-8 rounded-3xl border border-cafe/10 bg-white/70 p-6 shadow-card backdrop-blur-sm sm:p-8">
            <?php if (!$agendables): ?>
                <p class="py-10 text-center font-body text-cafe/60">La agenda online no está disponible por el momento.</p>
            <?php else: ?>

            <!-- Paso 1: profesional -->
            <div class="ag-panel" data-panel="1">
                <h2 class="font-heading text-xl font-semibold text-cafe">¿Con quién quieres agendar?</h2>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <?php foreach ($profJs as $p): ?>
                        <button type="button" class="ag-prof group flex items-center gap-4 rounded-2xl border border-cafe/10 bg-crema-light p-4 text-left transition-all hover:-translate-y-0.5 hover:border-terracota hover:shadow-card" data-id="<?= $p['id'] ?>">
                            <span class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-salvia to-salvia-dark">
                                <?php if ($p['foto']): ?><img src="<?= e($p['foto']) ?>" alt="<?= attr($p['nombre']) ?>" class="h-full w-full object-cover"><?php else: ?><span class="font-script text-3xl text-crema/95"><?= e($p['iniciales']) ?></span><?php endif; ?>
                            </span>
                            <span class="min-w-0">
                                <span class="block font-heading text-lg font-semibold text-cafe"><?= e($p['nombre']) ?></span>
                                <span class="block font-body text-sm text-cafe/60"><?= e($p['especialidad']) ?></span>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Paso 2: fecha y hora -->
            <div class="ag-panel hidden" data-panel="2">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-heading text-xl font-semibold text-cafe">Elige servicio y hora</h2>
                    <button type="button" class="ag-back font-body text-sm font-semibold text-terracota-dark hover:text-terracota">← Cambiar</button>
                </div>
                <p id="ag-prof-label" class="mt-1 font-body text-sm text-cafe/60"></p>

                <div class="mt-5">
                    <span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Elige el servicio</span>
                    <div id="ag-servicios" class="mt-2 grid gap-2"></div>
                </div>

                <label class="mt-5 flex flex-col gap-1">
                    <span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Fecha</span>
                    <input type="date" id="ag-fecha" class="w-full rounded-lg border border-cafe/20 bg-crema-light px-3 py-2.5 font-body text-base text-cafe outline-none focus:border-terracota">
                </label>
                <div class="mt-5">
                    <span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Horas disponibles</span>
                    <div id="ag-slots" class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4"></div>
                    <p id="ag-slots-msg" class="mt-2 font-body text-sm text-cafe/50"></p>
                </div>
            </div>

            <!-- Paso 3: datos -->
            <div class="ag-panel hidden" data-panel="3">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-heading text-xl font-semibold text-cafe">Tus datos</h2>
                    <button type="button" class="ag-back font-body text-sm font-semibold text-terracota-dark hover:text-terracota">← Volver</button>
                </div>
                <div id="ag-resumen" class="mt-4 rounded-xl bg-salvia/10 px-4 py-3 font-body text-sm text-cafe/80"></div>
                <form id="ag-form" class="mt-5 space-y-3">
                    <label class="flex flex-col gap-1"><span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Nombre completo *</span><input name="nombre" required class="w-full rounded-lg border border-cafe/20 bg-crema-light px-3 py-2.5 font-body text-base text-cafe outline-none focus:border-terracota"></label>
                    <label class="flex flex-col gap-1"><span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Correo (recibirás el comprobante) *</span><input name="email" type="email" required class="w-full rounded-lg border border-cafe/20 bg-crema-light px-3 py-2.5 font-body text-base text-cafe outline-none focus:border-terracota"></label>
                    <label class="flex flex-col gap-1"><span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Teléfono *</span><input name="telefono" required class="w-full rounded-lg border border-cafe/20 bg-crema-light px-3 py-2.5 font-body text-base text-cafe outline-none focus:border-terracota" placeholder="+56 9 ..."></label>
                    <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">
                    <p id="ag-err" class="hidden font-body text-sm font-medium text-red-600"></p>
                    <p class="font-body text-[11px] text-cafe/45">El pago en línea se habilitará próximamente. Por ahora tu reserva queda registrada como pendiente de confirmación y te enviamos el comprobante por correo.</p>
                    <button type="submit" id="ag-submit" class="w-full rounded-full bg-terracota px-6 py-3 font-body text-sm font-semibold text-crema-light shadow-card transition-colors hover:bg-terracota-dark">Confirmar reserva</button>
                </form>
            </div>

            <!-- Paso 4: listo -->
            <div class="ag-panel hidden" data-panel="4">
                <div class="py-6 text-center">
                    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-salvia/20 text-salvia-dark">
                        <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </div>
                    <h2 class="mt-4 font-heading text-2xl font-semibold text-cafe">¡Reserva registrada!</h2>
                    <p class="mt-2 font-body text-cafe/75">Te enviamos el comprobante a tu correo. Nos pondremos en contacto para confirmar.</p>
                    <p class="mt-4 font-body text-sm text-cafe/60">Código de reserva</p>
                    <p id="ag-codigo" class="font-heading text-xl font-bold tracking-wider text-terracota-dark"></p>
                    <a href="<?= e(base_path('/')) ?>" class="mt-6 inline-flex rounded-full border-2 border-cafe/15 px-6 py-2.5 font-body text-sm font-semibold text-cafe hover:border-terracota">Volver al inicio</a>
                </div>
            </div>

            <?php endif; ?>
        </div>
    </div>
</section>
</main>

<script>
(function () {
    var AG_API = <?= json_encode(base_path('/api/agenda')) ?>;
    var PROFS = <?= json_encode($profJs, JSON_UNESCAPED_UNICODE) ?>;
    var PRESEL = <?= json_encode($preSlug) ?>;
    if (!PROFS.length) return;

    var state = { prof: null, servicio: null, fecha: '', hora: '' };
    var panels = {}, steps = {};
    document.querySelectorAll('.ag-panel').forEach(function (p) { panels[p.dataset.panel] = p; });
    document.querySelectorAll('.ag-step').forEach(function (s) { steps[s.dataset.step] = s; });

    function go(n) {
        Object.keys(panels).forEach(function (k) { panels[k].classList.toggle('hidden', k !== String(n)); });
        Object.keys(steps).forEach(function (k) {
            var on = parseInt(k, 10) <= n, cur = parseInt(k, 10) === n;
            var dot = steps[k].querySelector('.ag-dot');
            steps[k].classList.toggle('text-cafe/40', !on);
            steps[k].classList.toggle('text-cafe', on);
            dot.className = 'ag-dot flex h-8 w-8 items-center justify-center rounded-full border-2 ' +
                (cur ? 'border-terracota bg-terracota text-crema-light' : on ? 'border-salvia-dark bg-salvia/20 text-cafe' : 'border-cafe/15 bg-crema-light');
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function fmtFecha(f) {
        var p = f.split('-'); return p[2] + '-' + p[1] + '-' + p[0];
    }

    // Paso 1
    document.querySelectorAll('.ag-prof').forEach(function (b) {
        b.addEventListener('click', function () {
            state.prof = PROFS.filter(function (p) { return p.id === parseInt(b.dataset.id, 10); })[0];
            document.getElementById('ag-prof-label').textContent = state.prof.nombre + ' · ' + state.prof.especialidad;
            setupFecha();
            go(2);
        });
    });

    // Volver
    document.querySelectorAll('.ag-back').forEach(function (b) {
        b.addEventListener('click', function () {
            var cur = parseInt(b.closest('.ag-panel').dataset.panel, 10);
            go(cur - 1);
        });
    });

    // Paso 2: fecha
    var fechaInput = document.getElementById('ag-fecha');
    var slotsBox = document.getElementById('ag-slots');
    var slotsMsg = document.getElementById('ag-slots-msg');

    function renderServicios() {
        var box = document.getElementById('ag-servicios');
        var servs = (state.prof && state.prof.servicios) || [];
        state.servicio = null;
        if (!servs.length) { box.innerHTML = '<p class="font-body text-sm text-cafe/50">Esta profesional aún no tiene servicios cargados.</p>'; return; }
        box.innerHTML = servs.map(function (s) {
            return '<button type="button" class="ag-serv flex items-center justify-between gap-3 rounded-xl border border-cafe/15 bg-crema-light px-4 py-3 text-left transition-colors hover:border-terracota" data-id="' + s.id + '">' +
                '<span class="min-w-0"><span class="block font-body text-sm font-semibold text-cafe">' + s.nombre + '</span>' +
                (s.descripcion ? '<span class="block font-body text-xs text-cafe/55">' + s.descripcion + '</span>' : '') + '</span>' +
                '<span class="shrink-0 whitespace-nowrap font-body text-sm font-bold text-terracota-dark">$' + Number(s.precio).toLocaleString('es-CL') + '</span></button>';
        }).join('');
        box.querySelectorAll('.ag-serv').forEach(function (b) {
            b.addEventListener('click', function () {
                state.servicio = servs.filter(function (x) { return x.id === parseInt(b.dataset.id, 10); })[0];
                box.querySelectorAll('.ag-serv').forEach(function (x) { x.classList.remove('border-terracota', 'bg-terracota/10'); x.classList.add('border-cafe/15'); });
                b.classList.remove('border-cafe/15'); b.classList.add('border-terracota', 'bg-terracota/10');
            });
        });
    }

    function setupFecha() {
        renderServicios();
        var hoy = new Date(); hoy.setHours(0, 0, 0, 0);
        var min = hoy.toISOString().slice(0, 10);
        fechaInput.min = min;
        fechaInput.value = '';
        slotsBox.innerHTML = '';
        slotsMsg.textContent = 'Elige una fecha para ver las horas disponibles.';
    }

    fechaInput.addEventListener('change', loadSlots);

    function loadSlots() {
        state.fecha = fechaInput.value; state.hora = '';
        slotsBox.innerHTML = ''; slotsMsg.textContent = 'Buscando horas…';
        if (!state.fecha) { slotsMsg.textContent = 'Elige una fecha.'; return; }
        fetch(AG_API + '?prof=' + encodeURIComponent(state.prof.slug) + '&fecha=' + state.fecha)
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.ok && j.cerrado) { slotsMsg.textContent = j.cerrado; return; }
                if (!j.ok || !j.slots || !j.slots.length) { slotsMsg.textContent = 'No hay horas disponibles ese día. Prueba otra fecha.'; return; }
                slotsMsg.textContent = '';
                slotsBox.innerHTML = j.slots.map(function (h) {
                    return '<button type="button" class="ag-slot rounded-lg border border-cafe/20 bg-crema-light px-2 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota hover:bg-terracota/10" data-h="' + h + '">' + h + '</button>';
                }).join('');
                slotsBox.querySelectorAll('.ag-slot').forEach(function (s) {
                    s.addEventListener('click', function () {
                        if (!state.servicio) { slotsMsg.textContent = 'Primero elige el servicio (arriba).'; window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
                        state.hora = s.dataset.h;
                        document.getElementById('ag-resumen').innerHTML =
                            '<strong>' + state.prof.nombre + '</strong> · ' + state.prof.especialidad + '<br>' +
                            state.servicio.nombre + ' — <strong>$' + Number(state.servicio.precio).toLocaleString('es-CL') + '</strong><br>' +
                            fmtFecha(state.fecha) + ' a las <strong>' + state.hora + ' hrs</strong>';
                        go(3);
                    });
                });
            })
            .catch(function () { slotsMsg.textContent = 'No se pudieron cargar las horas. Intenta de nuevo.'; });
    }

    // Paso 3: enviar
    var form = document.getElementById('ag-form');
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var err = document.getElementById('ag-err'); err.classList.add('hidden');
        var btn = document.getElementById('ag-submit'); btn.disabled = true; btn.textContent = 'Reservando…';
        var payload = {
            profesional_id: state.prof.id, producto_id: state.servicio ? state.servicio.id : 0,
            fecha: state.fecha, hora: state.hora,
            nombre: form.nombre.value.trim(), email: form.email.value.trim(), telefono: form.telefono.value.trim(),
            website: form.website.value
        };
        fetch(AG_API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, d: j }; }); })
            .then(function (x) {
                btn.disabled = false; btn.textContent = 'Confirmar reserva';
                if (!x.ok || !x.d.ok) { err.textContent = (x.d && x.d.error) || 'No se pudo reservar.'; err.classList.remove('hidden'); if (x.d && x.d.error && x.d.error.indexOf('disponible') >= 0) { go(2); loadSlots(); } return; }
                document.getElementById('ag-codigo').textContent = (x.d.codigo || '').toUpperCase();
                go(4);
            })
            .catch(function () { btn.disabled = false; btn.textContent = 'Confirmar reserva'; err.textContent = 'No se pudo reservar. Intenta de nuevo.'; err.classList.remove('hidden'); });
    });

    // Preselección por ?prof=
    if (PRESEL) {
        var pre = PROFS.filter(function (p) { return p.slug === PRESEL; })[0];
        if (pre) {
            state.prof = pre;
            document.getElementById('ag-prof-label').textContent = pre.nombre + ' · ' + pre.especialidad;
            setupFecha(); go(2);
        } else { go(1); }
    } else { go(1); }
})();
</script>
<?php
include APP_ROOT . '/partials/footer.php';
include APP_ROOT . '/partials/foot.php';
