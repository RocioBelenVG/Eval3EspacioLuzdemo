<?php
/**
 * Panel · Boxes (solo admin). Detalle, precios y estado de cada box.
 * Es la misma información que muestra la página principal (sección Boxes) y la
 * página de cada box: lo que se edita aquí se ve al instante en el sitio, en el
 * calendario y en la facturación.
 */
declare(strict_types=1);

requireAdminPage();
require_once APP_ROOT . '/lib/boxes.php';

$boxes = boxesPanel();
$archivados = boxesPanel(true);
$uso = boxesUso();
$cambios = boxesUltimoCambioPrecio();

$datos = [];
foreach (array_merge($boxes, $archivados) as $b) {
    $datos[$b['id']] = [
        'id' => $b['id'], 'slug' => $b['slug'], 'nombre' => $b['nombre'], 'ave_slug' => $b['aveSlug'], 'ubicacion' => $b['ubicacion'],
        'publico_objetivo' => $b['publicoObjetivo'], 'orden' => $b['orden'], 'descripcion' => $b['descripcion'], 'el_espacio' => $b['elEspacioDescripcion'],
        'equipamiento' => $b['equipamiento'], 'publico' => $b['publico'], 'activo' => $b['activo'], 'foto' => boxMainImage($b), 'foto_subida' => boxMainImage($b),
        'ave' => $b['ave'], 'ave_img' => asset('images/boxes/aves/' . $b['aveSlug'] . '.png'), 'uso' => $uso[$b['slug']] ?? ['futuras' => 0, 'mes' => 0],
        'tarifas' => array_map(fn($t) => ['label' => $t['label'], 'sub' => $t['sub'] ?? '', 'precio' => boxPrecioInt($t['precio']), 'gc' => boxPrecioInt($t['gastosComunes'] ?? '')], $b['tarifas']),
    ];
}
$aves = [];
foreach (BOX_AVES as $slug => $nom) {
    $aves[] = ['slug' => $slug, 'nombre' => $nom, 'img' => asset('images/boxes/aves/' . $slug . '.png')];
}

$panelTitle = 'Boxes';
$panelActive = 'boxes';
include APP_ROOT . '/partials/panel/top.php';
$chip = static fn(string $t, string $c) => '<span class="inline-flex items-center rounded-full px-2.5 py-0.5 font-body text-[11px] font-bold ' . $c . '">' . $t . '</span>';
?>
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <p class="max-w-3xl font-body text-sm leading-relaxed text-cafe/60">
        Detalle y precios de cada box. Es la <strong class="text-cafe">misma información de la página principal</strong> (sección Boxes y la página de cada box):
        lo que cambies aquí se ve al instante en el sitio, en el Calendario de Boxes y en la facturación de arriendos.
    </p>
    <button type="button" id="bx-nuevo" class="inline-flex shrink-0 items-center gap-2 rounded-full bg-terracota px-5 py-2.5 font-body text-sm font-semibold text-crema-light shadow-card hover:bg-terracota-dark">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg> Nuevo box
    </button>
</div>

<?php if (!$boxes): ?>
    <div class="rounded-2xl border border-dashed border-cafe/20 bg-crema-light p-8 text-center font-body text-sm text-cafe/55">Aún no hay boxes. Crea el primero con «Nuevo box».</div>
<?php endif; ?>

<div class="grid grid-cols-1 gap-4 lg:grid-cols-2 2xl:grid-cols-3">
    <?php foreach ($boxes as $b):
        $u = $uso[$b['slug']] ?? ['futuras' => 0, 'mes' => 0];
        $porHora = null; $otras = [];
        foreach ($b['tarifas'] as $t) { if ($porHora === null && $t['label'] === 'Por hora') $porHora = $t; else $otras[] = $t; } ?>
        <article class="bx-card flex flex-col rounded-2xl border border-cafe/10 bg-white p-4 shadow-card <?= $b['activo'] ? '' : 'opacity-75' ?>" data-box="<?= (int) $b['id'] ?>">
            <div class="flex items-start gap-3.5">
                <span class="bx-ave-circ relative flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full bg-crema ring-2 ring-white" title="<?= e($b['ave']) ?>">
                    <img src="<?= e(asset('images/boxes/aves/' . $b['aveSlug'] . '.png')) ?>" alt="<?= e($b['ave']) ?>" class="h-[86%] w-[86%] object-contain" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'font-heading text-xl text-cafe/50',textContent:<?= e(json_encode(mb_substr($b['nombre'], 0, 1, 'UTF-8'))) ?>}))">
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="truncate font-heading text-lg font-semibold leading-tight text-cafe"><?= e($b['nombre']) ?></h2>
                        <?php if ($porHora): ?><span class="shrink-0 rounded-full bg-terracota/10 px-2.5 py-0.5 font-body text-sm font-bold tabular-nums text-terracota-dark"><?= e($porHora['precio']) ?><span class="font-medium text-terracota-dark/70"> /h</span></span><?php endif; ?>
                    </div>
                    <p class="truncate font-body text-xs text-cafe/55"><?= e(trim($b['ubicacion'] . ($b['publicoObjetivo'] !== '' ? ' · ' . $b['publicoObjetivo'] : ''), ' ·')) ?></p>
                    <div class="mt-1.5 flex flex-wrap gap-1">
                        <?= $b['publico'] ? $chip('Visible en el sitio', 'bg-salvia/20 text-salvia-dark') : $chip('Oculto en el sitio', 'bg-cafe/10 text-cafe/60') ?>
                        <?= $b['activo'] ? $chip('Reservable', 'bg-salvia/20 text-salvia-dark') : $chip('Reservas pausadas', 'bg-amber-100 text-amber-800') ?>
                    </div>
                </div>
            </div>

            <p class="mt-3 line-clamp-2 font-body text-[13px] leading-snug text-cafe/70"><?= e($b['descripcion']) ?></p>

            <?php if ($otras): ?>
                <details class="bx-tar mt-2.5 rounded-xl bg-crema/50 px-3 py-2">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-2 font-body text-xs font-semibold text-cafe/75">
                        <span>Planes mensuales y jornadas (<?= count($otras) ?>)</span>
                        <span class="tabular-nums text-cafe/50">desde <?= e(boxPrecioTexto(min(array_map(fn($t) => boxPrecioInt($t['precio']), $otras)))) ?></span>
                    </summary>
                    <table class="mt-1.5 w-full font-body text-xs">
                        <tbody>
                            <?php foreach ($otras as $t): ?>
                                <tr class="border-t border-cafe/8">
                                    <td class="py-1 pr-2 text-cafe/80"><?= e($t['label']) ?><?php if (!empty($t['sub'])): ?><span class="text-cafe/45"> · <?= e($t['sub']) ?></span><?php endif; ?></td>
                                    <td class="py-1 text-right font-semibold tabular-nums text-cafe whitespace-nowrap"><?= e($t['precio']) ?><?php if (!empty($t['gastosComunes'])): ?><span class="block text-[10px] font-normal text-cafe/45">+ <?= e($t['gastosComunes']) ?> g. comunes</span><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>

            <?php if ($b['equipamiento']): ?>
                <p class="mt-2 line-clamp-1 font-body text-[11px] text-cafe/55" title="<?= e(implode(' · ', $b['equipamiento'])) ?>"><span class="font-semibold text-cafe/65">Equipamiento:</span> <?= e(implode(' · ', $b['equipamiento'])) ?></p>
            <?php endif; ?>

            <p class="mt-2 font-body text-[11px] text-cafe/55"><strong class="text-cafe"><?= (int) $u['futuras'] ?></strong> h agendadas desde hoy · <strong class="text-cafe"><?= (int) $u['mes'] ?></strong> este mes</p>
            <?php if (isset($cambios[$b['id']])): $cp = $cambios[$b['id']]; ?>
                <p class="mt-0.5 font-body text-[10.5px] leading-snug text-cafe/45">Precios vigentes desde el <?= e(date('d-m-Y', strtotime((string) $cp['creado_en']))) ?><?= (int) $cp['por_hora_antes'] !== (int) $cp['por_hora_despues'] ? ' (por hora ' . e(boxPrecioTexto((int) $cp['por_hora_antes'])) . ' → ' . e(boxPrecioTexto((int) $cp['por_hora_despues'])) . ')' : '' ?> · lo ya facturado mantiene su precio.</p>
            <?php endif; ?>

            <div class="mt-auto flex flex-wrap items-center gap-1 border-t border-cafe/8 pt-2.5">
                <button type="button" data-accion="editar" class="bx-btn">Editar</button>
                <button type="button" data-accion="ver" class="bx-btn">Ver</button>
                <button type="button" data-accion="publico" data-valor="<?= $b['publico'] ? 0 : 1 ?>" class="bx-btn"><?= $b['publico'] ? 'Ocultar' : 'Mostrar' ?></button>
                <button type="button" data-accion="activo" data-valor="<?= $b['activo'] ? 0 : 1 ?>" class="bx-btn"><?= $b['activo'] ? 'Pausar' : 'Reanudar' ?></button>
                <button type="button" data-accion="eliminar" class="ml-auto rounded-lg px-2 py-1 font-body text-xs font-semibold text-red-600/80 hover:bg-red-50 hover:text-red-700">Eliminar</button>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<style>
    .bx-card { margin-top: 0; }
    .bx-ave-circ { box-shadow: 0 0 0 1px rgba(92,64,51,.12), 0 6px 14px -8px rgba(92,64,51,.45); background: radial-gradient(circle at 50% 35%, #FBF9F4, #EAE3D3); }
    .bx-btn { border-radius: .5rem; border: 1px solid rgba(92,64,51,.15); padding: .25rem .6rem; font-family: var(--font-body); font-size: .75rem; font-weight: 600; color: #5C4033; transition: border-color .15s, color .15s; }
    .bx-btn:hover { border-color: #D97757; color: #D97757; }
    .bx-tar[open] summary { margin-bottom: .1rem; }
</style>

<?php if ($archivados): ?>
    <details class="mt-6 rounded-2xl border border-cafe/10 bg-crema-light p-4">
        <summary class="cursor-pointer font-body text-sm font-semibold text-cafe">Boxes archivados (<?= count($archivados) ?>) · conservan su historial de reservas</summary>
        <ul class="mt-3 space-y-2">
            <?php foreach ($archivados as $b): ?>
                <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-white px-4 py-2.5" data-box="<?= (int) $b['id'] ?>">
                    <span class="font-body text-sm text-cafe"><strong><?= e($b['nombre']) ?></strong> <span class="text-cafe/50">· archivado el <?= e(date('d-m-Y', strtotime((string) $b['eliminado_at']))) ?></span></span>
                    <button type="button" data-accion="restaurar" class="rounded-lg border border-cafe/15 px-3 py-1.5 font-body text-sm font-semibold text-cafe hover:border-terracota hover:text-terracota">Restaurar</button>
                </li>
            <?php endforeach; ?>
        </ul>
    </details>
<?php endif; ?>

<!-- Ventana: ver el detalle completo de un box (en la misma página) -->
<div id="bx-ver" class="fixed inset-0 z-[60] hidden items-center justify-center bg-cafe/55 p-3 backdrop-blur-sm sm:p-6" role="dialog" aria-modal="true" aria-labelledby="bxv-nombre">
    <div class="flex max-h-[94vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-crema-light shadow-warm">
        <div class="relative h-44 shrink-0 bg-crema sm:h-56">
            <img id="bxv-foto" alt="" class="h-full w-full object-cover">
            <span id="bxv-sinfoto" class="absolute inset-0 hidden items-start justify-center pt-8 font-body text-xs font-semibold uppercase tracking-[0.18em] text-cafe/45">Sin foto subida</span>
            <div class="absolute inset-0 bg-gradient-to-t from-cafe/70 via-cafe/10 to-transparent"></div>
            <button type="button" data-ver-cerrar class="absolute right-3 top-3 rounded-full bg-white/85 p-2 text-cafe shadow hover:bg-white" aria-label="Cerrar"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
            <div class="absolute bottom-3 left-4 right-4 flex items-end gap-3">
                <span class="bx-ave-circ flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full ring-2 ring-white"><img id="bxv-ave" alt="" class="h-[86%] w-[86%] object-contain"></span>
                <div class="min-w-0 pb-0.5">
                    <h3 id="bxv-nombre" class="truncate font-heading text-2xl font-semibold leading-tight text-white drop-shadow"></h3>
                    <p id="bxv-sub" class="truncate font-body text-xs text-white/85"></p>
                </div>
            </div>
        </div>
        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4 sm:px-6">
            <div id="bxv-chips" class="flex flex-wrap gap-1.5"></div>
            <p id="bxv-desc" class="font-body text-sm text-cafe/80"></p>
            <p id="bxv-espacio" class="font-body text-sm leading-relaxed text-cafe/65"></p>
            <div>
                <p class="mb-1 font-body text-[11px] font-bold uppercase tracking-[0.12em] text-cafe/50">Tarifas</p>
                <table class="w-full font-body text-sm"><tbody id="bxv-tarifas"></tbody></table>
            </div>
            <div id="bxv-eq-wrap">
                <p class="mb-1.5 font-body text-[11px] font-bold uppercase tracking-[0.12em] text-cafe/50">Equipamiento</p>
                <div id="bxv-eq" class="flex flex-wrap gap-1.5"></div>
            </div>
            <p id="bxv-uso" class="font-body text-xs text-cafe/60"></p>
        </div>
        <div class="flex shrink-0 items-center justify-end gap-2 border-t border-cafe/10 px-5 py-3 sm:px-6">
            <button type="button" data-ver-cerrar class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota">Cerrar</button>
            <button type="button" id="bxv-editar" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Editar este box</button>
        </div>
    </div>
</div>

<!-- Ventana: nuevo / editar box -->
<div id="bx-modal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-cafe/55 p-3 backdrop-blur-sm sm:p-6" role="dialog" aria-modal="true" aria-labelledby="bx-titulo">
    <form id="bx-form" novalidate class="flex max-h-[94vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-crema-light shadow-warm">
        <div class="flex items-start justify-between gap-4 border-b border-cafe/10 px-5 py-4 sm:px-6">
            <div><p class="font-body text-[11px] font-bold uppercase tracking-[0.14em] text-terracota-dark">Boxes · se ve en el sitio</p><h3 id="bx-titulo" class="font-heading text-xl font-semibold text-cafe">Nuevo box</h3></div>
            <button type="button" data-cerrar class="-mr-1 rounded-full p-2 text-cafe/50 hover:bg-cafe/5 hover:text-cafe" aria-label="Cerrar"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>
        <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5 sm:px-6">
            <input type="hidden" name="id">
            <section>
                <h4 class="bx-h mb-2">Datos del box</h4>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="flex flex-col gap-1 sm:col-span-2"><span class="bx-l">Nombre *</span><input name="nombre" class="bx-i" placeholder="Ej.: Box Picaflor"></label>
                    <label class="flex flex-col gap-1"><span class="bx-l">Ubicación</span><input name="ubicacion" class="bx-i" placeholder="Ej.: Segundo piso"></label>
                    <label class="flex flex-col gap-1"><span class="bx-l">Público objetivo</span><input name="publico_objetivo" class="bx-i" placeholder="Ej.: Niños / Adolescentes y adultos"></label>
                    <div class="flex flex-col gap-1 sm:col-span-2"><span class="bx-l">Ilustración (ave) *</span>
                        <div id="bx-aves" class="grid grid-cols-3 gap-2 sm:grid-cols-5"></div></div>
                    <label class="flex flex-col gap-1"><span class="bx-l">Orden en el sitio</span><input name="orden" type="number" min="1" class="bx-i" placeholder="1"></label>
                    <span id="bx-slug" class="self-end pb-2 font-body text-[11px] text-cafe/50"></span>
                </div>
            </section>
            <section>
                <h4 class="bx-h mb-2">Textos del sitio</h4>
                <div class="grid grid-cols-1 gap-3">
                    <label class="flex flex-col gap-1"><span class="bx-l">Descripción breve * <span class="font-normal normal-case text-cafe/45">(tarjeta de la página principal y encabezado del box)</span></span><textarea name="descripcion" rows="2" maxlength="600" class="bx-i resize-y"></textarea></label>
                    <label class="flex flex-col gap-1"><span class="bx-l">El espacio <span class="font-normal normal-case text-cafe/45">(detalle en la página del box)</span></span><textarea name="el_espacio" rows="3" maxlength="1200" class="bx-i resize-y"></textarea></label>
                    <label class="flex flex-col gap-1"><span class="bx-l">Equipamiento <span class="font-normal normal-case text-cafe/45">(uno por línea)</span></span><textarea name="equipamiento" rows="4" class="bx-i resize-y" placeholder="2 sillones individuales&#10;Estufa"></textarea></label>
                </div>
            </section>
            <section>
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h4 class="bx-h">Tarifas *</h4>
                    <div class="flex gap-2">
                        <button type="button" id="bx-tar-ind" class="rounded-full border border-cafe/15 px-3 py-1 font-body text-xs font-semibold text-cafe hover:border-terracota hover:text-terracota">Usar tarifas de box individual</button>
                        <button type="button" id="bx-tar-add" class="rounded-full border border-cafe/15 px-3 py-1 font-body text-xs font-semibold text-cafe hover:border-terracota hover:text-terracota">+ Agregar tarifa</button>
                    </div>
                </div>
                <p class="mb-2 font-body text-[11px] text-cafe/50">Precios con IVA incluido, tal como se publican. La tarifa «Por hora» es obligatoria: valora las horas del Calendario de Boxes y su facturación.</p>
                <p class="mb-2 rounded-lg bg-salvia/15 px-3 py-2 font-body text-[11px] text-salvia-dark"><strong>Si cambias un precio:</strong> rige desde la próxima factura. Las facturas ya emitidas (y sus devoluciones) mantienen el precio con que se cobraron; las horas agendadas aún sin facturar pasan al precio nuevo.</p>
                <div id="bx-tarifas" class="space-y-2"></div>
            </section>
            <section>
                <h4 class="bx-h mb-2">Foto principal</h4>
                <div class="flex flex-wrap items-center gap-4">
                    <div class="relative h-24 w-32 shrink-0 overflow-hidden rounded-lg bg-crema ring-1 ring-cafe/10">
                        <img id="bx-foto" alt="" class="hidden h-full w-full object-cover">
                        <span id="bx-foto-vacia" class="absolute inset-0 flex flex-col items-center justify-center gap-1 font-body text-[11px] font-semibold text-cafe/45"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h3l2-3h6l2 3h3a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1z"/><circle cx="12" cy="13" r="3.5"/></svg>Sin foto</span>
                    </div>
                    <label class="cursor-pointer rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota hover:text-terracota">Elegir foto<input id="bx-foto-file" type="file" accept="image/jpeg,image/png,image/webp" class="hidden"></label>
                    <span class="font-body text-[11px] text-cafe/50">JPG, PNG o WEBP, máx. 8 MB. La galería de fotos se maneja desde la página del box en modo edición.</span>
                </div>
            </section>
            <section class="flex flex-wrap gap-4">
                <label class="flex items-center gap-2 font-body text-sm text-cafe"><input type="checkbox" name="publico" class="h-4 w-4 accent-[#D97757]" checked> Visible en el sitio</label>
                <label class="flex items-center gap-2 font-body text-sm text-cafe"><input type="checkbox" name="activo" class="h-4 w-4 accent-[#D97757]" checked> Disponible para reservar (calendario)</label>
            </section>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-cafe/10 bg-white/60 px-5 py-4 sm:px-6">
            <p id="bx-err" class="hidden min-w-0 flex-1 font-body text-sm font-medium text-red-600"></p>
            <span class="flex-1"></span>
            <div class="flex gap-2">
                <button type="button" data-cerrar class="rounded-full border-2 border-cafe/15 px-4 py-2 font-body text-sm font-semibold text-cafe hover:border-terracota">Cancelar</button>
                <button type="submit" id="bx-guardar" class="rounded-full bg-terracota px-5 py-2 font-body text-sm font-semibold text-crema-light hover:bg-terracota-dark">Crear box</button>
            </div>
        </div>
    </form>
</div>
<style>
    .bx-h { font-family: var(--font-body); font-size: 11px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: #B85C3E; }
    .bx-l { font-family: var(--font-body); font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: rgba(92,64,51,.62); }
    .bx-i { min-width: 0; width: 100%; border-radius: .6rem; border: 1px solid rgba(92,64,51,.2); background: #FBF9F4; padding: .55rem .75rem; font-family: var(--font-body); font-size: .875rem; color: #5C4033; outline: none; }
    .bx-i:focus { border-color: #D97757; box-shadow: 0 0 0 3px rgba(217,119,87,.14); }
    .bx-ave[aria-pressed="true"] { border-color: #D97757; box-shadow: 0 0 0 3px rgba(217,119,87,.18); background: #FBF3EC; }
</style>
<script>
(function () {
    var BASE = <?= json_encode(BASE_URL) ?>, CSRF = <?= json_encode(csrf_token()) ?>;
    var DATOS = <?= json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var AVES = <?= json_encode($aves, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var IND = <?= json_encode(BOX_TARIFAS_INDIVIDUAL, JSON_UNESCAPED_UNICODE) ?>;
    var modal = document.getElementById('bx-modal'), f = document.getElementById('bx-form'), err = document.getElementById('bx-err');
    var tarWrap = document.getElementById('bx-tarifas'), avesWrap = document.getElementById('bx-aves'), foto = document.getElementById('bx-foto'), fotoFile = document.getElementById('bx-foto-file');
    var aveSel = '';
    // Solo se muestra la foto real del box (la subida). Un box nuevo parte «Sin foto»: nada precargado.
    function ponerFoto(url) { foto.classList.toggle('hidden', !url); document.getElementById('bx-foto-vacia').classList.toggle('hidden', !!url); if (url) foto.src = url; else foto.removeAttribute('src'); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function post(d) {
        return fetch(BASE + '/api/panel/boxes', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(d) })
            .then(function (r) { return r.text().then(function (t) { var j = {}; try { j = JSON.parse(t); } catch (e) { j = { error: 'Respuesta inesperada del servidor.' }; } return { ok: r.ok && j.ok, d: j }; }); });
    }

    // ---- Ave ----
    avesWrap.innerHTML = AVES.map(function (a) {
        return '<button type="button" class="bx-ave flex flex-col items-center gap-1 rounded-xl border-2 border-cafe/10 bg-white p-2" data-ave="' + a.slug + '" aria-pressed="false">' +
            '<img src="' + esc(a.img) + '" alt="" class="h-12 w-full object-contain" onerror="this.style.visibility=\'hidden\'"><span class="font-body text-[11px] font-semibold text-cafe/70">' + esc(a.nombre) + '</span></button>';
    }).join('');
    function elegirAve(s) {
        aveSel = s;
        avesWrap.querySelectorAll('[data-ave]').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.ave === s ? 'true' : 'false'); });
    }
    avesWrap.addEventListener('click', function (e) { var b = e.target.closest('[data-ave]'); if (b) elegirAve(b.dataset.ave); });

    // ---- Tarifas ----
    function filaTarifa(t) {
        var row = document.createElement('div');
        row.className = 'grid grid-cols-2 gap-2 rounded-xl border border-cafe/10 bg-white p-2.5 sm:grid-cols-[1.2fr_1.4fr_0.8fr_0.8fr_auto]';
        row.innerHTML = '<input data-k="label" class="bx-i" placeholder="Nombre (ej.: Por hora)" value="' + esc(t.label) + '">' +
            '<input data-k="sub" class="bx-i" placeholder="Detalle (opcional)" value="' + esc(t.sub) + '">' +
            '<input data-k="precio" type="number" min="0" step="500" class="bx-i text-right" placeholder="Precio" value="' + (t.precio || '') + '">' +
            '<input data-k="gc" type="number" min="0" step="500" class="bx-i text-right" placeholder="Gastos comunes" value="' + (t.gc || '') + '">' +
            '<button type="button" data-quitar class="rounded-lg px-2 text-lg leading-none text-cafe/40 hover:bg-red-50 hover:text-red-600" aria-label="Quitar tarifa">&times;</button>';
        row.querySelector('[data-quitar]').addEventListener('click', function () { row.remove(); });
        tarWrap.appendChild(row);
    }
    function ponerTarifas(lista) { tarWrap.innerHTML = ''; (lista && lista.length ? lista : [{ label: 'Por hora', sub: '', precio: 8000, gc: 0 }]).forEach(filaTarifa); }
    document.getElementById('bx-tar-add').addEventListener('click', function () { filaTarifa({ label: '', sub: '', precio: '', gc: '' }); });
    document.getElementById('bx-tar-ind').addEventListener('click', function () { ponerTarifas(IND); });

    fotoFile.addEventListener('change', function () { if (fotoFile.files[0]) ponerFoto(URL.createObjectURL(fotoFile.files[0])); });

    function abrir(d) {
        f.reset(); err.classList.add('hidden'); fotoFile.value = '';
        var nuevo = !d;
        d = d || { tarifas: null, equipamiento: [], publico: 1, activo: 1, ave_slug: 'loica' };
        document.getElementById('bx-titulo').textContent = nuevo ? 'Nuevo box' : 'Editar ' + d.nombre;
        document.getElementById('bx-guardar').textContent = nuevo ? 'Crear box' : 'Guardar cambios';
        document.getElementById('bx-slug').textContent = nuevo ? '' : 'Dirección: /boxes/' + d.slug + ' (no cambia)';
        ['id', 'nombre', 'ubicacion', 'publico_objetivo', 'orden', 'descripcion', 'el_espacio'].forEach(function (k) { if (f[k]) f[k].value = d[k] == null ? '' : d[k]; });
        f.equipamiento.value = (d.equipamiento || []).join('\n');
        f.publico.checked = String(d.publico) === '1'; f.activo.checked = String(d.activo) === '1';
        ponerFoto(d.foto_subida || '');
        elegirAve(d.ave_slug || 'loica');
        ponerTarifas(d.tarifas);
        modal.classList.remove('hidden'); modal.classList.add('flex'); document.body.style.overflow = 'hidden';
        setTimeout(function () { f.nombre.focus(); }, 30);
    }
    function cerrar() { modal.classList.add('hidden'); modal.classList.remove('flex'); document.body.style.overflow = ''; }
    modal.querySelectorAll('[data-cerrar]').forEach(function (b) { b.addEventListener('click', cerrar); });
    modal.addEventListener('mousedown', function (e) { if (e.target === modal) cerrar(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.classList.contains('hidden')) cerrar(); });
    document.getElementById('bx-nuevo').addEventListener('click', function () { abrir(null); });

    f.addEventListener('submit', function (e) {
        e.preventDefault(); err.classList.add('hidden');
        var tarifas = Array.prototype.map.call(tarWrap.children, function (row) {
            var o = {}; row.querySelectorAll('[data-k]').forEach(function (i) { o[i.dataset.k] = i.value.trim(); }); return o;
        });
        var d = { action: f.id.value ? 'actualizar' : 'crear', id: f.id.value, nombre: f.nombre.value.trim(), ave_slug: aveSel, ubicacion: f.ubicacion.value.trim(),
            publico_objetivo: f.publico_objetivo.value.trim(), orden: f.orden.value, descripcion: f.descripcion.value.trim(), el_espacio: f.el_espacio.value.trim(),
            equipamiento: f.equipamiento.value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean), tarifas: tarifas,
            publico: f.publico.checked ? 1 : 0, activo: f.activo.checked ? 1 : 0 };
        var btn = document.getElementById('bx-guardar'), txt = btn.textContent; btn.disabled = true; btn.textContent = 'Guardando…';
        function fallo(m) { err.textContent = m; err.classList.remove('hidden'); btn.disabled = false; btn.textContent = txt; }
        post(d).then(function (x) {
            if (!x.ok) return fallo(x.d.error || 'No se pudo guardar.');
            if (x.d.horas_actualizadas) { ezAlert('Precios actualizados. ' + x.d.horas_actualizadas + ' hora(s) agendadas aún sin facturar pasan al nuevo precio por hora; lo ya facturado no cambia.'); }
            if (!fotoFile.files[0]) { setTimeout(function () { location.reload(); }, x.d.horas_actualizadas ? 2500 : 0); return; }
            var fd = new FormData(); fd.append('file', fotoFile.files[0]); fd.append('boxSlug', x.d.slug); fd.append('main', '1');
            fetch(BASE + '/api/content/upload-gallery-image', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: fd })
                .then(function (r) { return r.json().catch(function () { return { error: 'No se pudo subir la foto.' }; }); })
                .then(function (j) { if (j && j.error) { fallo('El box se guardó, pero la foto no: ' + j.error); return; } location.reload(); })
                .catch(function () { fallo('El box se guardó, pero la foto no se pudo subir.'); });
        }).catch(function () { fallo('Sin conexión. Intenta de nuevo.'); });
    });

    // ---- Ver detalle (en la misma página) ----
    var ver = document.getElementById('bx-ver'), verActual = null;
    function clpV(n) { return '$' + (parseInt(n, 10) || 0).toLocaleString('es-CL'); }
    function cerrarVer() { ver.classList.add('hidden'); ver.classList.remove('flex'); document.body.style.overflow = ''; }
    function abrirVer(d) {
        verActual = d;
        var vf = document.getElementById('bxv-foto'), vs = document.getElementById('bxv-sinfoto');
        if (d.foto) { vf.src = d.foto; vf.classList.remove('hidden'); vs.classList.add('hidden'); vs.classList.remove('flex'); }
        else { vf.removeAttribute('src'); vf.classList.add('hidden'); vs.classList.remove('hidden'); vs.classList.add('flex'); }
        document.getElementById('bxv-ave').src = d.ave_img || '';
        document.getElementById('bxv-ave').alt = d.ave || '';
        document.getElementById('bxv-nombre').textContent = d.nombre;
        document.getElementById('bxv-sub').textContent = [d.ubicacion, d.publico_objetivo].filter(Boolean).join(' · ');
        var chip = function (t, c) { return '<span class="inline-flex items-center rounded-full px-2.5 py-0.5 font-body text-[11px] font-bold ' + c + '">' + esc(t) + '</span>'; };
        document.getElementById('bxv-chips').innerHTML = (parseInt(d.publico, 10) ? chip('Visible en el sitio', 'bg-salvia/20 text-salvia-dark') : chip('Oculto en el sitio', 'bg-cafe/10 text-cafe/60')) +
            (parseInt(d.activo, 10) ? chip('Reservable', 'bg-salvia/20 text-salvia-dark') : chip('Reservas pausadas', 'bg-amber-100 text-amber-800')) + (d.ave ? chip('Ave: ' + d.ave, 'bg-crema text-cafe/70') : '');
        document.getElementById('bxv-desc').textContent = d.descripcion || '';
        var esp = document.getElementById('bxv-espacio'); esp.textContent = d.el_espacio || ''; esp.classList.toggle('hidden', !d.el_espacio);
        document.getElementById('bxv-tarifas').innerHTML = (d.tarifas || []).map(function (t) {
            return '<tr class="border-t border-cafe/8"><td class="py-1.5 pr-2 text-cafe/85">' + esc(t.label) + (t.sub ? '<span class="block text-[11px] text-cafe/50">' + esc(t.sub) + '</span>' : '') + '</td>' +
                '<td class="py-1.5 text-right font-semibold tabular-nums text-cafe whitespace-nowrap">' + clpV(t.precio) + (t.gc ? '<span class="block text-[11px] font-normal text-cafe/50">+ ' + clpV(t.gc) + ' gastos comunes</span>' : '') + '</td></tr>';
        }).join('');
        var eq = d.equipamiento || [];
        document.getElementById('bxv-eq-wrap').classList.toggle('hidden', !eq.length);
        document.getElementById('bxv-eq').innerHTML = eq.map(function (x) { return '<span class="rounded-full bg-crema px-2.5 py-0.5 font-body text-xs text-cafe/75">' + esc(x) + '</span>'; }).join('');
        var u = d.uso || {};
        document.getElementById('bxv-uso').innerHTML = '<strong class="text-cafe">' + (parseInt(u.futuras, 10) || 0) + '</strong> hora(s) agendadas desde hoy · <strong class="text-cafe">' + (parseInt(u.mes, 10) || 0) + '</strong> este mes';
        ver.classList.remove('hidden'); ver.classList.add('flex'); document.body.style.overflow = 'hidden';
    }
    ver.querySelectorAll('[data-ver-cerrar]').forEach(function (b) { b.addEventListener('click', cerrarVer); });
    ver.addEventListener('click', function (e) { if (e.target === ver) cerrarVer(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !ver.classList.contains('hidden')) cerrarVer(); });
    document.getElementById('bxv-editar').addEventListener('click', function () { var d = verActual; cerrarVer(); if (d) abrir(d); });

    // ---- Acciones de cada tarjeta ----
    document.querySelectorAll('[data-box]').forEach(function (card) {
        var id = card.dataset.box, d = DATOS[id];
        card.querySelectorAll('[data-accion]').forEach(function (b) {
            b.addEventListener('click', function () {
                var a = b.dataset.accion;
                if (a === 'editar') return abrir(d);
                if (a === 'ver') return abrirVer(d);
                var cfg = {
                    publico: [b.dataset.valor === '1' ? '¿Mostrar ' + d.nombre + ' en el sitio?' : '¿Ocultar ' + d.nombre + ' del sitio? Seguirá en el calendario y en la facturación.', b.dataset.valor === '1' ? 'Mostrar' : 'Ocultar'],
                    activo: [b.dataset.valor === '1' ? '¿Reanudar las reservas de ' + d.nombre + '?' : '¿Pausar las reservas de ' + d.nombre + '? Deja de aparecer en el Calendario de Boxes (el historial se mantiene).', b.dataset.valor === '1' ? 'Reanudar' : 'Pausar'],
                    eliminar: ['¿Eliminar ' + d.nombre + '? Si tiene reservas pasadas se archiva (conserva el historial); si no, se borra. Sale del sitio y del calendario.', 'Eliminar'],
                    restaurar: ['¿Restaurar ' + d.nombre + '? Vuelve al calendario; en el sitio queda oculto hasta que lo muestres.', 'Restaurar']
                }[a];
                ezConfirm(cfg[0], { title: d.nombre, okText: cfg[1], danger: a === 'eliminar' }).then(function (ok) {
                    if (!ok) return;
                    post({ action: a, id: id, valor: b.dataset.valor }).then(function (x) {
                        if (!x.ok) { ezAlert(x.d.error || 'No se pudo completar.'); return; }
                        if (a === 'eliminar' && x.d.modo === 'archivado') { ezAlert(d.nombre + ' quedó archivado (tenía historial de reservas). Puedes restaurarlo abajo.').then(function () { location.reload(); }); return; }
                        location.reload();
                    });
                });
            });
        });
    });
})();
</script>
<?php include APP_ROOT . '/partials/panel/bottom.php'; ?>
