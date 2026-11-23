<?php
/**
 * Ficha clínica del paciente (una por área): próxima sesión agendada, evoluciones
 * con editar/eliminar, «Ver ficha completa» y PDF con secciones a elección.
 * Requiere $paciente, $cid, $fichas (desde pages/panel/paciente-ver.php).
 */
declare(strict_types=1);
require_once APP_ROOT . '/lib/ficha_clinica.php';
require_once APP_ROOT . '/lib/informes.php';
$informesPorFicha = $informesPorFicha ?? [];

$fcPuedeAgendar = !esRolPacientes();
$fF = static fn($v) => $v ? date('d-m-Y', strtotime((string) $v)) : '';
$evoHtml = static function (array $ev) use ($fF): string {
    $json = e(json_encode(['id' => (int) $ev['id'], 'ficha_id' => (int) $ev['ficha_id'], 'fecha' => $ev['fecha'], 'profesional_id' => $ev['profesional_id'] !== null ? (int) $ev['profesional_id'] : '', 'nota_progreso' => $ev['nota_progreso'], 'proxima_accion' => (string) $ev['proxima_accion']], JSON_UNESCAPED_UNICODE));
    ob_start(); ?>
    <li class="group relative">
        <span class="absolute -left-[21px] top-1.5 h-2.5 w-2.5 rounded-full bg-terracota"></span>
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="font-body text-xs font-semibold text-cafe"><?= e($fF($ev['fecha'])) ?><?= $ev['profesional_nombre'] ? ' · ' . e($ev['profesional_nombre']) : '' ?>
                    <?php if (!empty($ev['editado_en'])): ?><span class="font-normal text-cafe/40" title="Editada por <?= e((string) $ev['editado_por']) ?>"> · editada <?= e(date('d-m-Y', strtotime((string) $ev['editado_en']))) ?></span><?php endif; ?></div>
                <div class="mt-0.5 whitespace-pre-line break-words font-body text-sm text-cafe/80"><?= e($ev['nota_progreso']) ?></div>
                <?php if ($ev['proxima_accion']): ?><div class="mt-1 whitespace-pre-line font-body text-xs text-cafe/55"><strong>Próximo:</strong> <?= e($ev['proxima_accion']) ?></div><?php endif; ?>
            </div>
            <div class="flex shrink-0 items-center gap-0.5 opacity-60 transition-opacity group-hover:opacity-100">
                <button type="button" data-evo-edit='<?= $json ?>' class="rounded-md p-1.5 text-cafe/50 hover:bg-cafe/5 hover:text-terracota-dark" title="Editar evolución" aria-label="Editar evolución"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></button>
                <button type="button" data-evo-del="<?= (int) $ev['id'] ?>" data-evo-fecha="<?= e($fF($ev['fecha'])) ?>" data-ficha="<?= (int) $ev['ficha_id'] ?>" class="rounded-md p-1.5 text-cafe/50 hover:bg-red-50 hover:text-red-600" title="Eliminar evolución" aria-label="Eliminar evolución"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/></svg></button>
            </div>
        </div>
    </li>
    <?php return (string) ob_get_clean();
};
$proxHtml = static function (?array $prox) use ($fcPuedeAgendar): string {
    ob_start(); ?>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border <?= $prox ? 'border-salvia/30 bg-salvia/10' : 'border-dashed border-cafe/15 bg-white/50' ?> px-4 py-2.5">
        <svg class="h-4 w-4 shrink-0 <?= $prox ? 'text-salvia-dark' : 'text-cafe/40' ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>
        <span class="font-body text-[11px] font-bold uppercase tracking-wide text-cafe/55">Próxima sesión</span>
        <?php if ($prox): ?>
            <span class="font-body text-sm font-semibold text-cafe"><?= e(proximaSesionTexto($prox)) ?></span>
            <span class="rounded-full px-2 py-0.5 font-body text-[11px] font-semibold <?= $prox['estado'] === 'confirmada' ? 'bg-salvia/25 text-salvia-dark' : 'bg-amber-100 text-amber-700' ?>"><?= $prox['estado'] === 'confirmada' ? 'Confirmada' : 'Pendiente' ?></span>
        <?php else: ?>
            <span class="font-body text-sm text-cafe/50">Sin sesión agendada</span>
            <?php if ($fcPuedeAgendar): ?><a href="<?= e(base_path('/admin/calendario-profesionales')) ?>" class="font-body text-xs font-semibold text-terracota-dark hover:underline">Agendar →</a><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php return (string) ob_get_clean();
};
// Informes adjuntos a la ficha de un área (se suben y gestionan en «Informes clínicos»).
$infFichaHtml = static function (array $lista, int $fichaId) use ($fF): string {
    ob_start(); ?>
    <div class="mt-4">
        <div class="flex items-center justify-between gap-2">
            <div class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/50">Informes adjuntos (<?= count($lista) ?>)</div>
            <button type="button" data-inf-nuevo data-ficha="<?= $fichaId ?>" class="font-body text-xs font-semibold text-terracota-dark hover:underline">+ Adjuntar informe</button>
        </div>
        <?php if (!$lista): ?>
            <p class="mt-2 font-body text-sm text-cafe/40">Sin informes adjuntos en esta área.</p>
        <?php else: ?>
            <ul class="mt-2 flex flex-col gap-1.5">
                <?php foreach ($lista as $inf): ?>
                    <li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-lg border border-cafe/10 bg-white/70 px-3 py-2">
                        <span class="min-w-0 font-body text-sm text-cafe"><strong class="font-semibold"><?= e($inf['titulo']) ?></strong>
                            <span class="text-xs text-cafe/50"> · <?= e(INFORME_TIPOS[$inf['tipo']] ?? $inf['tipo']) ?> · <?= e($fF($inf['fecha_informe'])) ?><?= $inf['profesional_nombre'] ? ' · ' . e($inf['profesional_nombre']) : '' ?></span></span>
                        <span class="flex shrink-0 items-center gap-3 font-body text-xs font-semibold">
                            <?php if (informeVisible($inf)): ?><a href="<?= e(informeUrl($inf, true)) ?>" target="_blank" rel="noopener" class="text-cafe/60 hover:text-terracota">Ver</a><?php endif; ?>
                            <a href="<?= e(informeUrl($inf)) ?>" class="text-terracota-dark hover:underline">Descargar</a>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<!-- Ficha clínica -->
<div class="mt-6 flex items-center justify-between">
    <h3 class="font-heading text-xl font-semibold text-cafe">Ficha clínica</h3>
    <button type="button" id="ficha-nueva" class="inline-flex items-center gap-1.5 rounded-full bg-cafe px-4 py-2 font-body text-sm font-semibold text-crema-light hover:bg-cafe-light">+ Nueva ficha (área)</button>
</div>
<?php if (!$fichas): ?>
    <div class="mt-3 rounded-2xl border border-dashed border-cafe/20 bg-crema-light p-8 text-center font-body text-sm text-cafe/50">Sin ficha clínica todavía. Crea una por cada área (psicología, fonoaudiología, T.O.) que atienda a este paciente.</div>
<?php else: foreach ($fichas as $f):
    $evs = fichaEvoluciones((int) $f['id']);
    $prox = proximaSesionFicha($f);
    $area = (string) ($f['especialidad_nombre'] ?? $f['especialidad_slug']);
    $pdfData = ['id' => (int) $f['id'], 'area' => $area, 'url' => base_path('/admin/pacientes/' . $cid . '/fichas/' . (int) $f['id'] . '/pdf'),
        'evos' => array_map(static fn($ev) => ['id' => (int) $ev['id'], 'fecha' => $fF($ev['fecha']), 'prof' => (string) $ev['profesional_nombre'], 'txt' => mb_strimwidth((string) $ev['nota_progreso'], 0, 90, '…', 'UTF-8')], $evs)];
    $pdfJson = e(json_encode($pdfData, JSON_UNESCAPED_UNICODE)); ?>
    <div class="mt-3 rounded-2xl border border-cafe/8 bg-crema-light p-5 shadow-card" id="ficha-<?= (int) $f['id'] ?>">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <span class="rounded-full bg-terracota/12 px-3 py-1 font-body text-xs font-bold text-terracota-dark"><?= e($area) ?></span>
                <span class="ml-2 font-body text-xs text-cafe/55"><?= $f['profesional_nombre'] ? 'Responsable: ' . e($f['profesional_nombre']) : 'Sin profesional asignado' ?> · <?= $f['estado'] === 'cerrada' ? 'Cerrada' : 'Activa' ?> · desde <?= e($fF($f['abierta_en'])) ?></span>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" data-fc-ver="<?= (int) $f['id'] ?>" class="inline-flex items-center gap-1.5 rounded-lg border border-cafe/15 bg-white px-2.5 py-1 font-body text-xs font-semibold text-cafe hover:border-terracota hover:text-terracota">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>Ver ficha completa</button>
                <button type="button" data-fc-pdf='<?= $pdfJson ?>' class="inline-flex items-center gap-1.5 rounded-lg border border-terracota/40 bg-terracota/5 px-2.5 py-1 font-body text-xs font-semibold text-terracota-dark hover:bg-terracota/10">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M12 12v6M9 15l3 3 3-3"/></svg>PDF</button>
                <button type="button" class="ficha-edit rounded-lg border border-cafe/15 px-2.5 py-1 font-body text-xs font-semibold text-cafe hover:border-terracota hover:text-terracota" data-json='<?= e(json_encode(['id'=>(int)$f['id'],'especialidad_slug'=>$f['especialidad_slug'],'profesional_responsable_id'=>$f['profesional_responsable_id'],'motivo_consulta'=>$f['motivo_consulta'],'antecedentes'=>$f['antecedentes'],'diagnostico'=>$f['diagnostico'],'objetivos'=>$f['objetivos'],'estado'=>$f['estado']], JSON_UNESCAPED_UNICODE)) ?>'>Editar ficha</button>
                <button type="button" class="evo-nueva rounded-lg bg-salvia-dark px-2.5 py-1 font-body text-xs font-semibold text-crema-light hover:bg-salvia" data-ficha="<?= (int) $f['id'] ?>" data-prof="<?= (int) ($f['profesional_responsable_id'] ?? 0) ?>">+ Evolución</button>
            </div>
        </div>
        <div class="mt-3"><?= $proxHtml($prox) ?></div>
        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <?php foreach (FICHA_CAMPOS as $campo => $lbl): ?>
                <div class="rounded-xl bg-crema/40 px-4 py-3">
                    <div class="font-body text-[11px] font-semibold uppercase tracking-wide text-cafe/50"><?= $lbl ?></div>
                    <div class="mt-1 whitespace-pre-line font-body text-sm text-cafe/80"><?= $f[$campo] ? e($f[$campo]) : '<span class="text-cafe/35">—</span>' ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <!-- Evoluciones (las 3 más recientes; todas en «Ver ficha completa») -->
        <div class="mt-4">
            <div class="flex items-center justify-between gap-2">
                <div class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/50">Progreso / evoluciones (<?= count($evs) ?>)</div>
                <?php if (count($evs) > 3): ?><button type="button" data-fc-ver="<?= (int) $f['id'] ?>" class="font-body text-xs font-semibold text-terracota-dark hover:underline">Ver las <?= count($evs) ?> evoluciones →</button><?php endif; ?>
            </div>
            <?php if (!$evs): ?>
                <p class="mt-2 font-body text-sm text-cafe/40">Aún no hay notas de evolución.</p>
            <?php else: ?>
                <ol class="mt-3 space-y-3 border-l-2 border-cafe/10 pl-4"><?php foreach (array_slice($evs, 0, 3) as $ev) echo $evoHtml($ev); ?></ol>
            <?php endif; ?>
        </div>
        <?= $infFichaHtml($informesPorFicha[(int) $f['id']] ?? [], (int) $f['id']) ?>

        <!-- Contenido de «Ver ficha completa» -->
        <template id="fc-tpl-<?= (int) $f['id'] ?>">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full bg-terracota/12 px-3 py-1 font-body text-xs font-bold text-terracota-dark"><?= e($area) ?></span>
                <span class="font-body text-xs text-cafe/55"><?= $f['profesional_nombre'] ? 'Responsable: ' . e($f['profesional_nombre']) : 'Sin profesional asignado' ?> · <?= $f['estado'] === 'cerrada' ? 'Cerrada' : 'Activa' ?> · abierta el <?= e($fF($f['abierta_en'])) ?></span>
            </div>
            <div class="mt-3"><?= $proxHtml($prox) ?></div>
            <div class="mt-4 space-y-3">
                <?php foreach (FICHA_CAMPOS as $campo => $lbl): ?>
                    <section>
                        <h4 class="font-body text-[11px] font-bold uppercase tracking-wider text-terracota-dark"><?= $lbl ?></h4>
                        <p class="mt-1 whitespace-pre-line break-words font-body text-sm leading-relaxed text-cafe/85"><?= $f[$campo] ? e($f[$campo]) : '<span class="text-cafe/35">Sin registro.</span>' ?></p>
                    </section>
                <?php endforeach; ?>
            </div>
            <div class="mt-5 border-t border-cafe/10 pt-4">
                <h4 class="font-body text-[11px] font-bold uppercase tracking-wider text-terracota-dark">Progreso / evoluciones (<?= count($evs) ?>)</h4>
                <?php if (!$evs): ?>
                    <p class="mt-2 font-body text-sm text-cafe/40">Aún no hay notas de evolución.</p>
                <?php else: ?>
                    <ol class="mt-3 space-y-4 border-l-2 border-cafe/10 pl-4"><?php foreach ($evs as $ev) echo $evoHtml($ev); ?></ol>
                <?php endif; ?>
            </div>
            <div class="border-t border-cafe/10 pt-1"><?= $infFichaHtml($informesPorFicha[(int) $f['id']] ?? [], (int) $f['id']) ?></div>
        </template>
    </div>
<?php endforeach; endif; ?>

<!-- Ventana «Ver ficha completa» -->
<div id="fc-modal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-cafe/60 p-3 backdrop-blur-sm sm:p-6" role="dialog" aria-modal="true" aria-labelledby="fc-titulo">
    <div class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-crema-light shadow-warm">
        <div class="flex items-start justify-between gap-4 border-b border-cafe/10 px-5 py-4 sm:px-6">
            <div class="min-w-0">
                <p class="font-body text-[11px] font-bold uppercase tracking-[0.14em] text-terracota-dark">Ficha clínica</p>
                <h3 id="fc-titulo" class="truncate font-heading text-xl font-semibold text-cafe"><?= e($paciente['nombre']) ?></h3>
            </div>
            <button type="button" data-fc-cerrar class="-mr-1 rounded-full p-2 text-cafe/50 hover:bg-cafe/5 hover:text-cafe" aria-label="Cerrar"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>
        <div id="fc-cuerpo" class="min-h-0 flex-1 overflow-y-auto px-5 py-4 sm:px-6"></div>
        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-cafe/10 bg-white/60 px-5 py-3 font-body sm:px-6">
            <button type="button" id="fc-evo" class="rounded-full border-2 border-salvia/50 px-4 py-2 text-sm font-semibold text-salvia-dark hover:bg-salvia/10">+ Evolución</button>
            <button type="button" id="fc-pdf" class="rounded-full border-2 border-terracota/40 px-4 py-2 text-sm font-semibold text-terracota-dark hover:bg-terracota/10">PDF de la ficha</button>
            <button type="button" data-fc-cerrar class="rounded-full bg-cafe px-5 py-2 text-sm font-semibold text-crema-light hover:bg-cafe-light">Cerrar</button>
        </div>
    </div>
</div>

<!-- Ventana «PDF de la ficha»: elegir qué mostrar + vista previa -->
<div id="fp-modal" class="fixed inset-0 z-[65] hidden items-center justify-center bg-cafe/60 p-3 backdrop-blur-sm sm:p-6" role="dialog" aria-modal="true" aria-labelledby="fp-titulo">
    <div class="flex h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-crema-light shadow-warm">
        <div class="flex items-start justify-between gap-4 border-b border-cafe/10 px-5 py-4 sm:px-6">
            <div class="min-w-0">
                <p class="font-body text-[11px] font-bold uppercase tracking-[0.14em] text-terracota-dark">PDF de la ficha clínica</p>
                <h3 id="fp-titulo" class="truncate font-heading text-xl font-semibold text-cafe"></h3>
            </div>
            <button type="button" data-fp-cerrar class="-mr-1 rounded-full p-2 text-cafe/50 hover:bg-cafe/5 hover:text-cafe" aria-label="Cerrar"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>
        <div class="flex min-h-0 flex-1 flex-col lg:flex-row">
            <!-- Opciones -->
            <div class="min-h-0 overflow-y-auto border-cafe/10 px-5 py-4 sm:px-6 lg:w-[360px] lg:shrink-0 lg:border-r">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-body text-xs font-bold uppercase tracking-wide text-cafe/60">Qué incluir</span>
                    <span class="font-body text-xs"><button type="button" id="fp-todo" class="font-semibold text-terracota-dark hover:underline">Ficha completa</button> · <button type="button" id="fp-nada" class="font-semibold text-cafe/55 hover:underline">Ninguna</button></span>
                </div>
                <div class="mt-2 space-y-1">
                    <?php foreach (FICHA_PDF_SECCIONES as $k => $lbl): ?>
                        <label class="flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 font-body text-sm text-cafe hover:bg-white"><input type="checkbox" class="fp-sec h-4 w-4 accent-[#D97757]" value="<?= $k ?>" checked> <?= e($lbl) ?></label>
                    <?php endforeach; ?>
                </div>
                <div id="fp-evo-opts" class="mt-4 rounded-xl border border-cafe/10 bg-white/70 p-3">
                    <span class="font-body text-xs font-bold uppercase tracking-wide text-cafe/60">Evoluciones a incluir</span>
                    <div class="mt-2 space-y-1.5 font-body text-sm text-cafe">
                        <label class="flex items-center gap-2"><input type="radio" name="fp-ev" value="todas" class="accent-[#D97757]" checked> Todas</label>
                        <label class="flex items-center gap-2"><input type="radio" name="fp-ev" value="rango" class="accent-[#D97757]"> Por rango de fechas</label>
                        <div id="fp-rango" class="ml-6 hidden grid-cols-2 gap-2">
                            <label class="flex flex-col gap-0.5 text-[11px] text-cafe/55">Desde<input type="date" id="fp-desde" class="rounded-lg border border-cafe/20 bg-white px-2 py-1 text-sm text-cafe"></label>
                            <label class="flex flex-col gap-0.5 text-[11px] text-cafe/55">Hasta<input type="date" id="fp-hasta" class="rounded-lg border border-cafe/20 bg-white px-2 py-1 text-sm text-cafe"></label>
                        </div>
                        <label class="flex items-center gap-2"><input type="radio" name="fp-ev" value="sel" class="accent-[#D97757]"> Elegir una por una</label>
                        <div id="fp-lista" class="ml-6 hidden max-h-56 space-y-1 overflow-y-auto pr-1"></div>
                    </div>
                </div>
                <p id="fp-aviso" class="mt-3 hidden rounded-lg bg-amber-50 px-3 py-2 font-body text-xs text-amber-800">Elige al menos una sección.</p>
            </div>
            <!-- Vista previa -->
            <div class="flex min-h-0 flex-1 flex-col bg-cafe/5">
                <div class="relative min-h-0 flex-1">
                    <iframe id="fp-iframe" title="Vista previa del PDF" class="absolute inset-0 hidden h-full w-full bg-white lg:block"></iframe>
                    <div id="fp-cargando" class="pointer-events-none absolute inset-0 hidden items-center justify-center bg-white/60 font-body text-sm font-semibold text-cafe/60">Generando vista previa…</div>
                    <div class="flex h-full items-center justify-center p-6 text-center font-body text-sm text-cafe/55 lg:hidden">En el celular, usa «Ver PDF» para abrirlo a pantalla completa.</div>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-cafe/10 bg-white/60 px-5 py-3 font-body sm:px-6">
            <a id="fp-abrir" href="#" target="_blank" rel="noopener" class="rounded-full border-2 border-cafe/15 px-4 py-2 text-sm font-semibold text-cafe hover:border-terracota hover:text-terracota">Ver PDF</a>
            <a id="fp-descargar" href="#" class="inline-flex items-center gap-1.5 rounded-full bg-terracota px-5 py-2 text-sm font-semibold text-crema-light hover:bg-terracota-dark">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>Descargar PDF</a>
        </div>
    </div>
</div>

<script>
(function () {
    function $(id) { return document.getElementById(id); }
    function mostrar(m) { m.classList.remove('hidden'); m.classList.add('flex'); document.body.style.overflow = 'hidden'; }
    function ocultar(m) { m.classList.add('hidden'); m.classList.remove('flex'); if ($('fc-modal').classList.contains('hidden') && $('fp-modal').classList.contains('hidden')) document.body.style.overflow = ''; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }

    /* ---- Ver ficha completa ---- */
    var fc = $('fc-modal'), fichaAbierta = null;
    function abrirFicha(id) {
        var tpl = $('fc-tpl-' + id); if (!tpl) return;
        fichaAbierta = id;
        $('fc-cuerpo').innerHTML = ''; $('fc-cuerpo').appendChild(tpl.content.cloneNode(true)); $('fc-cuerpo').scrollTop = 0;
        mostrar(fc);
    }
    document.querySelectorAll('[data-fc-ver]').forEach(function (b) { b.addEventListener('click', function () { abrirFicha(b.dataset.fcVer); }); });
    fc.querySelectorAll('[data-fc-cerrar]').forEach(function (b) { b.addEventListener('click', function () { ocultar(fc); }); });
    fc.addEventListener('click', function (e) { if (e.target === fc) ocultar(fc); });
    $('fc-evo').addEventListener('click', function () { var b = document.querySelector('.evo-nueva[data-ficha="' + fichaAbierta + '"]'); if (b) b.click(); });
    $('fc-pdf').addEventListener('click', function () { var b = document.querySelector('[data-fc-pdf]') && [].find.call(document.querySelectorAll('[data-fc-pdf]'), function (x) { return JSON.parse(x.dataset.fcPdf).id === Number(fichaAbierta); }); if (b) b.click(); });
    // Tras editar/eliminar se recarga la página y se vuelve a abrir la ficha (#ver-ficha-ID).
    var m = location.hash.match(/^#ver-ficha-(\d+)$/);
    if (m) { history.replaceState(null, '', location.pathname + location.search); abrirFicha(m[1]); }
    window.__fichaAbierta = function () { return fc.classList.contains('hidden') ? null : fichaAbierta; };

    /* ---- PDF de la ficha ---- */
    var fp = $('fp-modal'), datos = null, timer = null;
    function opciones() {
        var s = [].filter.call(document.querySelectorAll('.fp-sec'), function (c) { return c.checked; }).map(function (c) { return c.value; });
        var ev = (document.querySelector('input[name="fp-ev"]:checked') || {}).value || 'todas';
        var q = ['s=' + encodeURIComponent(s.join(','))];
        if (s.indexOf('evoluciones') !== -1) {
            q.push('ev=' + ev);
            if (ev === 'rango') { if ($('fp-desde').value) q.push('desde=' + $('fp-desde').value); if ($('fp-hasta').value) q.push('hasta=' + $('fp-hasta').value); }
            if (ev === 'sel') q.push('ids=' + [].filter.call(document.querySelectorAll('.fp-evo'), function (c) { return c.checked; }).map(function (c) { return c.value; }).join(','));
        }
        return { secciones: s, query: q.join('&') };
    }
    function refrescar(inmediato) {
        clearTimeout(timer);
        var o = opciones(), vacio = o.secciones.length === 0;
        $('fp-aviso').classList.toggle('hidden', !vacio);
        $('fp-evo-opts').classList.toggle('opacity-50', o.secciones.indexOf('evoluciones') === -1);
        var ev = (document.querySelector('input[name="fp-ev"]:checked') || {}).value;
        $('fp-rango').classList.toggle('hidden', ev !== 'rango'); $('fp-rango').classList.toggle('grid', ev === 'rango');
        $('fp-lista').classList.toggle('hidden', ev !== 'sel');
        ['fp-abrir', 'fp-descargar'].forEach(function (id) { $(id).classList.toggle('pointer-events-none', vacio); $(id).classList.toggle('opacity-40', vacio); });
        if (vacio) return;
        var url = datos.url + '?' + o.query;
        $('fp-abrir').href = url + '&ver=1';
        $('fp-descargar').href = url;
        timer = setTimeout(function () {
            if (getComputedStyle($('fp-iframe')).display === 'none') return;   // celular: sin vista previa incrustada
            $('fp-cargando').classList.remove('hidden'); $('fp-cargando').classList.add('flex');
            $('fp-iframe').src = url + '&ver=1#toolbar=1&navpanes=0&view=FitH';
        }, inmediato ? 0 : 650);
    }
    $('fp-iframe').addEventListener('load', function () { $('fp-cargando').classList.add('hidden'); $('fp-cargando').classList.remove('flex'); });
    document.querySelectorAll('[data-fc-pdf]').forEach(function (b) {
        b.addEventListener('click', function () {
            datos = JSON.parse(b.dataset.fcPdf);
            $('fp-titulo').textContent = <?= json_encode($paciente['nombre'], JSON_UNESCAPED_UNICODE) ?> + ' · ' + datos.area;
            document.querySelectorAll('.fp-sec').forEach(function (c) { c.checked = true; });
            document.querySelector('input[name="fp-ev"][value="todas"]').checked = true;
            $('fp-desde').value = ''; $('fp-hasta').value = '';
            $('fp-lista').innerHTML = datos.evos.length ? datos.evos.map(function (e) {
                return '<label class="flex cursor-pointer items-start gap-2 rounded-lg px-1.5 py-1 text-xs hover:bg-crema"><input type="checkbox" class="fp-evo mt-0.5 h-3.5 w-3.5 shrink-0 accent-[#D97757]" value="' + e.id + '" checked><span><strong>' + esc(e.fecha) + '</strong>' + (e.prof ? ' · ' + esc(e.prof) : '') + '<br><span class="text-cafe/60">' + esc(e.txt) + '</span></span></label>';
            }).join('') : '<p class="text-xs text-cafe/45">Esta ficha aún no tiene evoluciones.</p>';
            document.querySelectorAll('.fp-evo').forEach(function (c) { c.addEventListener('change', function () { refrescar(); }); });
            mostrar(fp); refrescar(true);
        });
    });
    document.querySelectorAll('.fp-sec, input[name="fp-ev"]').forEach(function (c) { c.addEventListener('change', function () { refrescar(); }); });
    ['fp-desde', 'fp-hasta'].forEach(function (id) { $(id).addEventListener('change', function () { refrescar(); }); });
    $('fp-todo').addEventListener('click', function () { document.querySelectorAll('.fp-sec').forEach(function (c) { c.checked = true; }); refrescar(); });
    $('fp-nada').addEventListener('click', function () { document.querySelectorAll('.fp-sec').forEach(function (c) { c.checked = false; }); refrescar(); });
    fp.querySelectorAll('[data-fp-cerrar]').forEach(function (b) { b.addEventListener('click', function () { ocultar(fp); $('fp-iframe').removeAttribute('src'); }); });
    fp.addEventListener('click', function (e) { if (e.target === fp) { ocultar(fp); $('fp-iframe').removeAttribute('src'); } });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (!fp.classList.contains('hidden')) { ocultar(fp); $('fp-iframe').removeAttribute('src'); }
        else if (!fc.classList.contains('hidden') && document.querySelector('#evo-modal.hidden, #evo-modal:not(.flex)')) ocultar(fc);
    });
})();
</script>
