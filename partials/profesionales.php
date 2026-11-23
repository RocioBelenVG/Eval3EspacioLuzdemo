<?php
/**
 * Sección Profesionales (sitio público). Ahora lee desde la tabla `profesionales`
 * (activos y con publico=1), de modo que el módulo del panel controla qué se ve.
 * Espera $content (para el encabezado de sección).
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/profesionales.php';
require_once APP_ROOT . '/partials/profesionales-fotos.php';

$esAdminProf = isAuthenticated() && isAdmin();
$profesionales = profesionalesParaSitio($esAdminProf);
$especialidades = especialidadesLista();
$seccion = $content['secciones']['profesionales'];

$avatarThemes = ['from-salvia to-salvia-dark', 'from-terracota to-terracota-dark', 'from-cafe-light to-cafe'];

$n = count($profesionales);
// Columnas automáticas: se adaptan al nº de profesionales para que la última fila
// no quede coja. Entre 3 y 4 columnas elige la que deje menos huecos en la última
// fila (empate -> 4, más ancho y menos filas). Ej.: 8 -> 4 (2x4), 9 -> 3 (3x3),
// 6 -> 3 (2x3), 7 -> 4 (4+3), 10 -> 4 (4+4+2).
if ($n <= 2) {
    $gridCls = 'mt-16 grid gap-10 sm:grid-cols-2 max-w-3xl mx-auto';
} else {
    $vac4 = ($n % 4 === 0) ? 0 : 4 - ($n % 4);
    $vac3 = ($n % 3 === 0) ? 0 : 3 - ($n % 3);
    $lgCls = ($n >= 4 && $vac4 <= $vac3) ? 'lg:grid-cols-4' : 'lg:grid-cols-3';
    $gridCls = 'mt-16 grid gap-10 sm:grid-cols-2 ' . $lgCls;
}

// Iconos (estáticos, estilo Lucide) para los controles de admin sobre las tarjetas.
$svgOpen = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
$icoCam = $svgOpen . '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2Z"/><circle cx="12" cy="13" r="4"/></svg>';
$icoX = $svgOpen . '<path d="M18 6 6 18M6 6l12 12"/></svg>';
$icoEye = $svgOpen . '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>';
$icoEyeOff = $svgOpen . '<path d="M17.94 17.94A10 10 0 0 1 12 20C5 20 1 12 1 12a18 18 0 0 1 5.06-5.94M9.9 4.24A9 9 0 0 1 12 4c7 0 11 8 11 8a18 18 0 0 1-2.16 3.19M1 1l22 22M9.9 9.9a3 3 0 1 0 4.2 4.2"/></svg>';
$icoTrash = $svgOpen . '<path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1L5 6"/></svg>';
?>
<section id="profesionales" class="bg-crema py-12 sm:py-16 lg:py-20">
    <div class="container">
        <div class="max-w-2xl mx-auto text-center">
            <span class="mb-3 inline-block font-body text-xs sm:text-sm font-bold uppercase tracking-[0.2em] text-terracota-dark">
                <?= ez_editable($seccion['eyebrow'], ['type' => 'seccion', 'id' => 'profesionales', 'field' => 'eyebrow']) ?>
            </span>
            <h2 class="font-heading text-3xl sm:text-4xl md:text-[2.75rem] font-semibold leading-tight text-balance text-cafe">
                <?= ez_editable($seccion['titulo'], ['type' => 'seccion', 'id' => 'profesionales', 'field' => 'titulo']) ?>
            </h2>
            <p class="mt-4 font-body text-base sm:text-lg leading-relaxed text-balance text-cafe/85">
                <?= ez_editable($seccion['descripcion'], ['type' => 'seccion', 'id' => 'profesionales', 'field' => 'descripcion'], true) ?>
            </p>
        </div>

        <?php if (!$profesionales): ?>
            <p class="mt-12 text-center font-body text-cafe/50">Pronto publicaremos a nuestro equipo.</p>
        <?php else: ?>
        <div class="<?= e($gridCls) ?>">
            <?php foreach ($profesionales as $i => $prof): ?>
                <?php
                $fotoUrl = profesionalFotoUrl($prof) ?? '';
                $hasFoto = $fotoUrl !== '';
                $theme = $avatarThemes[$i % count($avatarThemes)];
                $espNombre = $especialidades[$prof['especialidad_slug']] ?? '';
                if ($hasFoto) {
                    $inner = '<img src="' . e($fotoUrl) . '" alt="' . attr($prof['nombre']) . '" class="absolute inset-0 h-full w-full object-cover">';
                } else {
                    $inner = '<div class="relative flex h-full w-full items-center justify-center">'
                        . '<span class="font-script text-8xl leading-none text-crema/95">' . e(getIniciales($prof['nombre'])) . '</span></div>';
                }
                $photoWrap = 'relative aspect-[4/5] w-full overflow-hidden rounded-[2rem] shadow-card ' . ($hasFoto ? '' : 'bg-gradient-to-br ' . $theme);
                ?>
                <?php $oculto = $esAdminProf && (int) $prof['publico'] !== 1; $pub = (int) $prof['publico'] === 1; $pid = (int) $prof['id']; $ezAuth = isAuthenticated(); ?>
                <div class="ez-prof-card group flex flex-col animate-fade-up <?= $oculto ? 'opacity-60' : '' ?>" data-prof-id="<?= (int) $prof['id'] ?>" data-prof-nombre="<?= attr($prof['nombre']) ?>" data-publico="<?= (int) $prof['publico'] ?>" style="animation-delay: <?= $i * 120 ?>ms; animation-fill-mode: backwards;">
                    <div class="relative">
                        <div class="<?= e($photoWrap) ?>">
                            <?= $inner ?>
                            <?php if ($esAdminProf): ?>
                            <div class="ez-prof-overlay absolute inset-0 z-[5] flex flex-col items-center justify-center gap-2.5 rounded-[2rem] p-4">
                                <div class="flex items-center justify-center gap-2">
                                    <button type="button" class="ez-prof-foto-change ez-ov-btn"><?= $icoCam ?> Cambiar foto</button>
                                    <button type="button" class="ez-prof-foto-del ez-ov-btn"><?= $icoX ?> Eliminar foto</button>
                                    <input type="file" class="ez-prof-foto-input hidden" accept="image/jpeg,image/png,image/webp">
                                </div>
                                <div class="flex items-center justify-center gap-2">
                                    <button type="button" class="ez-prof-vis ez-ov-btn"><?= $pub ? $icoEyeOff : $icoEye ?> <span class="ez-vis-lbl"><?= $pub ? 'Ocultar' : 'Mostrar' ?></span></button>
                                    <button type="button" class="ez-prof-del ez-ov-btn ez-ov-danger"><?= $icoTrash ?> Dar de baja</button>
                                </div>
                            </div>
                            <?php endif; ?>
                            <!-- Velo + especialidad + nombre sobre la foto (más visible sin tapar la imagen) -->
                            <div class="pointer-events-none absolute inset-0 z-[4]" style="background:linear-gradient(to top,rgba(61,42,33,.9) 0%,rgba(61,42,33,.42) 20%,rgba(92,64,51,.05) 42%,transparent 58%)"></div>
                            <div class="absolute inset-x-0 bottom-0 z-[6] p-5 [text-shadow:0_1px_8px_rgba(30,18,10,.5)]">
                                <?php if ($espNombre): ?>
                                    <span class="mb-1.5 inline-flex rounded-full bg-crema/90 px-2.5 py-0.5 font-body text-[11px] font-bold text-cafe [text-shadow:none]"><?= e($espNombre) ?></span>
                                <?php endif; ?>
                                <?= ez_editable($prof['nombre'], ['type' => 'profesional', 'id' => $pid, 'field' => 'nombre'], false, 'h3', 'font-heading text-[1.45rem] font-bold leading-tight text-white [text-shadow:0_2px_10px_rgba(30,18,10,.6)]') ?>
                            </div>
                        </div>
                        <?php if ($esAdminProf && $oculto): ?>
                            <span class="absolute left-4 top-4 z-[6] rounded-full bg-cafe/85 px-2.5 py-1 font-body text-[10px] font-bold uppercase tracking-wide text-crema">Oculta</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($prof['descripcion']) || $ezAuth): ?><?= ez_editable($prof['descripcion'] ?? '', ['type' => 'profesional', 'id' => $pid, 'field' => 'descripcion'], true, 'p', 'mt-5 font-body text-sm leading-relaxed text-cafe/75') ?><?php endif; ?>
                    <?php if (!empty($prof['enfoque']) || $ezAuth): ?><?= ez_editable($prof['enfoque'] ?? '', ['type' => 'profesional', 'id' => $pid, 'field' => 'enfoque'], true, 'p', 'mt-1 font-body text-sm italic leading-relaxed text-cafe/60') ?><?php endif; ?>
                    <?php if (!empty($prof['duracion_texto']) || $ezAuth): ?>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <?= ez_editable($prof['duracion_texto'] ?? '', ['type' => 'profesional', 'id' => $pid, 'field' => 'duracion_texto'], false, 'span', 'rounded-full bg-terracota/10 px-3 py-1 font-body text-xs font-semibold text-terracota-dark') ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($prof['slug'])): ?>
                        <a href="<?= e(base_path('/profesionales/' . $prof['slug'])) ?>" class="group/vm mt-5 inline-flex items-center gap-1.5 self-start rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe transition-colors hover:border-terracota hover:text-terracota">
                            Ver más
                            <svg class="h-4 w-4 transition-transform group-hover/vm:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($esAdminProf): ?>
        <div class="ez-admin-ctl mt-10 flex justify-center">
            <button type="button" id="ez-prof-add" class="inline-flex items-center gap-2 rounded-full border-2 border-dashed border-terracota/50 bg-terracota/5 px-6 py-3 font-body text-sm font-semibold text-terracota-dark transition-colors hover:border-terracota hover:bg-terracota/10">
                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-terracota text-lg leading-none text-crema-light">+</span>
                Agregar profesional
            </button>
        </div>

        <!-- Modal alta profesional -->
        <div id="ez-prof-modal" class="fixed inset-0 z-[120] hidden items-center justify-center bg-cafe/60 p-4 backdrop-blur-sm">
            <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-3xl bg-crema-light p-6 shadow-warm">
                <h3 class="font-heading text-xl font-semibold text-cafe">Nuevo profesional</h3>
                <p class="mb-4 mt-1 font-body text-xs text-cafe/55">Con el nombre y la especialidad basta para crear. Al guardar te llevamos a su ficha para completar todo lo demás (Sobre mí, áreas, formación…) tocando cada sección.</p>
                <form id="ez-prof-form" class="space-y-3">
                    <div class="flex items-center gap-4">
                        <div class="relative h-24 w-20 shrink-0 overflow-hidden rounded-xl bg-cafe/10">
                            <img id="ez-prof-foto-prev" alt="" class="hidden h-full w-full object-cover">
                            <span id="ez-prof-foto-ph" class="absolute inset-0 flex items-center justify-center text-cafe/35 [&>svg]:h-7 [&>svg]:w-7"><?= $icoCam ?></span>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="inline-flex cursor-pointer items-center justify-center rounded-full border-2 border-cafe/15 px-4 py-1.5 font-body text-sm font-semibold text-cafe hover:border-terracota">
                                <span id="ez-prof-foto-lbl">Subir foto</span>
                                <input type="file" id="ez-prof-foto" accept="image/jpeg,image/png,image/webp" class="hidden">
                            </label>
                            <span class="font-body text-[11px] text-cafe/45">JPG, PNG o WEBP · vertical 4:5 (ej. 960×1200)</span>
                        </div>
                    </div>
                    <label class="flex flex-col gap-1"><span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Nombre completo *</span><input name="nombre" required class="rounded-lg border border-cafe/20 bg-white/70 px-3 py-2.5 font-body text-base text-cafe outline-none focus:border-terracota"></label>
                    <label class="flex flex-col gap-1"><span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Especialidad *</span>
                        <select name="especialidad_slug" class="rounded-lg border border-cafe/20 bg-white/70 px-3 py-2.5 font-body text-base text-cafe outline-none focus:border-terracota">
                            <?php foreach ($especialidades as $slug => $nom): ?><option value="<?= e($slug) ?>"><?= e($nom) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1"><span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Descripción</span><input name="descripcion" class="rounded-lg border border-cafe/20 bg-white/70 px-3 py-2.5 font-body text-base text-cafe outline-none focus:border-terracota" placeholder="Especialista en…"></label>
                    <label class="flex flex-col gap-1"><span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Enfoque</span><input name="enfoque" class="rounded-lg border border-cafe/20 bg-white/70 px-3 py-2.5 font-body text-base text-cafe outline-none focus:border-terracota" placeholder="A quién atiende…"></label>
                    <div class="flex flex-col gap-1">
                        <div class="flex items-center justify-between">
                            <span class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/60">Duración de sesión</span>
                            <span id="ez-dur-out" class="rounded-full bg-terracota/10 px-2.5 py-0.5 font-body text-sm font-bold text-terracota-dark">50 min</span>
                        </div>
                        <input type="range" id="ez-dur-range" class="ez-dur-range w-full" min="15" max="120" step="5" value="50" aria-label="Duración de la sesión en minutos">
                        <div class="flex justify-between font-body text-[10px] text-cafe/40"><span>15 min</span><span>120 min</span></div>
                        <input type="hidden" name="duracion_texto" id="ez-dur-hidden" value="50 min">
                    </div>
                    <label class="flex items-center gap-2"><input type="checkbox" name="publico" class="h-4 w-4 accent-[#D97757]"><span class="font-body text-sm text-cafe/70">Mostrar en el sitio de inmediato</span></label>
                    <p id="ez-prof-err" class="hidden font-body text-sm font-medium text-red-600"></p>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" id="ez-prof-cancel" class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota">Cancelar</button>
                        <button type="submit" id="ez-prof-submit" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Crear profesional</button>
                    </div>
                </form>
            </div>
        </div>

        <style>
        .ez-admin-ctl{display:none}.ez-editing .ez-admin-ctl{display:flex}
        .ez-prof-overlay{opacity:0;pointer-events:none;background:rgba(55,50,46,.55);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);transition:opacity .25s ease}
        .ez-editing .ez-prof-card:hover .ez-prof-overlay{opacity:1;pointer-events:auto}
        .ez-ov-btn{display:inline-flex;align-items:center;gap:6px;border-radius:9999px;background:rgba(251,249,244,.96);padding:7px 13px;font-family:var(--font-body,inherit);font-size:12px;font-weight:600;color:#5C4033;box-shadow:0 10px 30px -10px rgba(92,64,51,.25);transition:background .2s,color .2s;cursor:pointer}
        .ez-ov-btn:hover{background:#fff}
        .ez-ov-btn svg{width:16px;height:16px}
        .ez-ov-danger{color:#dc2626}.ez-ov-danger:hover{background:#fef2f2}
        .ez-dur-range{-webkit-appearance:none;appearance:none;height:6px;border-radius:9999px;background:rgba(217,119,87,.22);outline:none}
        .ez-dur-range::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;width:20px;height:20px;border-radius:50%;background:#D97757;cursor:pointer;border:2px solid #fff;box-shadow:0 2px 6px rgba(92,64,51,.3)}
        .ez-dur-range::-moz-range-thumb{width:20px;height:20px;border-radius:50%;background:#D97757;cursor:pointer;border:2px solid #fff;box-shadow:0 2px 6px rgba(92,64,51,.3)}
        </style>
        <script>
        (function () {
            var P = window.__EZ__ || {}; if (!P.admin) return;
            var api = P.base + '/api/panel/profesionales';
            function post(d) { return fetch(api, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf }, body: JSON.stringify(d) }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, d: j }; }); }); }
            function upload(id, file) { var fd = new FormData(); fd.append('action', 'foto'); fd.append('id', id); fd.append('file', file); return fetch(api, { method: 'POST', headers: { 'X-CSRF-Token': P.csrf }, body: fd }); }

            document.querySelectorAll('.ez-prof-card').forEach(function (card) {
                var id = card.dataset.profId, nombre = card.dataset.profNombre;
                var vis = card.querySelector('.ez-prof-vis');
                var del = card.querySelector('.ez-prof-del');
                var fChange = card.querySelector('.ez-prof-foto-change');
                var fDel = card.querySelector('.ez-prof-foto-del');
                var fInput = card.querySelector('.ez-prof-foto-input');
                if (vis) vis.addEventListener('click', function () {
                    var ocultar = card.dataset.publico === '1';
                    function go() { post({ action: 'publico', id: id, publico: ocultar ? 0 : 1 }).then(function (x) { if (x.ok) location.reload(); }); }
                    if (!ocultar) { go(); return; }
                    ezConfirm('¿Ocultar a ' + nombre + ' del sitio? Dejará de verse para el público; puedes volver a mostrarlo cuando quieras.', { title: 'Ocultar profesional', okText: 'Ocultar' }).then(function (ok) { if (ok) go(); });
                });
                if (del) del.addEventListener('click', function () {
                    ezConfirm('¿Dar de baja a ' + nombre + '? Dejará de verse en el sitio. Su historial (reservas/fichas) se conserva.', { title: 'Dar de baja', okText: 'Dar de baja', danger: true }).then(function (ok) {
                        if (!ok) return;
                        post({ action: 'eliminar', id: id }).then(function (x) { if (x.ok) location.reload(); else ezAlert((x.d && x.d.error) || 'No se pudo.'); });
                    });
                });
                if (fChange && fInput) {
                    fChange.addEventListener('click', function () { fInput.click(); });
                    fInput.addEventListener('change', function () {
                        var file = fInput.files[0]; fInput.value = ''; if (!file) return;
                        upload(id, file).then(function (r) { if (!r.ok) throw new Error(); location.reload(); })
                            .catch(function () { ezAlert('No se pudo subir la foto. Usa JPG, PNG o WEBP de hasta 8 MB.'); });
                    });
                }
                if (fDel) fDel.addEventListener('click', function () {
                    ezConfirm('¿Eliminar la foto de ' + nombre + '? Volverá al avatar con iniciales.', { title: 'Eliminar foto', okText: 'Eliminar', danger: true }).then(function (ok) {
                        if (!ok) return;
                        post({ action: 'borrar-foto', id: id }).then(function (x) { if (x.ok) location.reload(); else ezAlert((x.d && x.d.error) || 'No se pudo.'); });
                    });
                });
            });

            var modal = document.getElementById('ez-prof-modal'), f = document.getElementById('ez-prof-form');
            if (modal && f) {
                var addBtn = document.getElementById('ez-prof-add');
                var fotoIn = document.getElementById('ez-prof-foto');
                // Deslizador de duración: refleja el valor en el badge y en el input oculto.
                var durRange = document.getElementById('ez-dur-range'), durOut = document.getElementById('ez-dur-out'), durHidden = document.getElementById('ez-dur-hidden');
                if (durRange) durRange.addEventListener('input', function () { var t = durRange.value + ' min'; if (durOut) durOut.textContent = t; if (durHidden) durHidden.value = t; });
                function show() { modal.classList.remove('hidden'); modal.classList.add('flex'); }
                function hide() { modal.classList.add('hidden'); modal.classList.remove('flex'); }
                if (addBtn) addBtn.addEventListener('click', show);
                document.getElementById('ez-prof-cancel').addEventListener('click', hide);
                modal.addEventListener('click', function (e) { if (e.target === modal) hide(); });
                if (fotoIn) fotoIn.addEventListener('change', function () {
                    var file = fotoIn.files[0]; var prev = document.getElementById('ez-prof-foto-prev'), ph = document.getElementById('ez-prof-foto-ph'), lbl = document.getElementById('ez-prof-foto-lbl');
                    if (file) { prev.src = URL.createObjectURL(file); prev.classList.remove('hidden'); if (ph) ph.classList.add('hidden'); lbl.textContent = 'Cambiar foto'; }
                });
                f.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var err = document.getElementById('ez-prof-err'); err.classList.add('hidden');
                    var btn = document.getElementById('ez-prof-submit'); btn.disabled = true; var t = btn.textContent; btn.textContent = 'Creando…';
                    post({ action: 'crear', nombre: f.nombre.value.trim(), especialidad_slug: f.especialidad_slug.value, descripcion: f.descripcion.value.trim(), enfoque: f.enfoque.value.trim(), duracion_texto: f.duracion_texto.value.trim(), publico: f.publico.checked ? 1 : 0 })
                        .then(function (x) {
                            if (!x.ok) { err.textContent = (x.d && x.d.error) || 'No se pudo crear.'; err.classList.remove('hidden'); btn.disabled = false; btn.textContent = t; return; }
                            var slug = x.d && x.d.slug;
                            function irAFicha() { if (slug) { window.location.href = P.base + '/profesionales/' + encodeURIComponent(slug) + '?nuevo=1'; } else { location.reload(); } }
                            var file = fotoIn && fotoIn.files[0];
                            if (file) { btn.textContent = 'Subiendo foto…'; upload(x.d.id, file).then(irAFicha).catch(irAFicha); }
                            else { irAFicha(); }
                        });
                });
            }
        })();
        </script>
        <?php endif; ?>
    </div>
</section>
