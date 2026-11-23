<?php
/**
 * Página pública de detalle de un profesional ("Ver más").
 * El router define $slug. Sigue los estilos del sitio.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/profesionales.php';
require_once APP_ROOT . '/lib/productos.php';
require_once APP_ROOT . '/partials/profesionales-fotos.php';

$prof = getProfesionalBySlug($slug ?? '');
if (!$prof) {
    require APP_ROOT . '/pages/404.php';
    return;
}
// Un profesional oculto (publico=0) solo es visible para un admin con sesión.
if ((int) ($prof['publico'] ?? 0) !== 1 && !isAuthenticated()) {
    require APP_ROOT . '/pages/404.php';
    return;
}

$content = getContent();
$hasLogo = hasLogo();
$perfil = perfilProfesional($prof);
$centro = $content['centro'];

$fotoUrl = profesionalFotoUrl($prof) ?? '';
$hasFoto = $fotoUrl !== '';
$titulo = $prof['titulo'] ?: ($prof['especialidad_nombre'] ?? '');
$subtitulo = $perfil['subtitulo'] ?? '';
// Agenda online -> flujo de reserva. Con "cta_whatsapp" en el perfil -> WhatsApp para
// consultar horas (como en los boxes). El resto no muestra botón de agendar.
$ctaWhatsapp = empty($prof['agenda_online']) && !empty(perfilProfesional($prof)['cta_whatsapp']);
if (!empty($prof['agenda_online'])) {
    $agendaHref = '/agenda?prof=' . $prof['slug'];
} elseif ($ctaWhatsapp) {
    $agendaHref = whatsapp_href('Hola, quiero agendar una hora con ' . $prof['nombre'] . ' en Espacio Luz. ¿Qué horarios tiene disponibles?');
} else {
    $agendaHref = '/#contacto';
}
// Servicios y valores: productos activos de la empresa de la profesional.
$servicios = !empty($prof['empresa_id']) ? productosLista((int) $prof['empresa_id'], false, (int) $prof['id']) : [];
$pid = (int) $prof['id'];
$ezAuth = puedeEditarSitio(); // el rol Pacientes no edita
// Atajos para editar campos del profesional/perfil en línea.
$ezCampo = fn(string $v, string $f, bool $ml, string $tag, string $cls, string $ph = '') => ez_editable($v, ['type' => 'profesional', 'id' => $pid, 'field' => $f], $ml, $tag, $cls, $ph);
$ezPerfil = fn(string $v, string $k, bool $ml, string $tag, string $cls, string $ph = '') => ez_editable($v, ['type' => 'profesional-perfil', 'id' => $pid, 'key' => $k], $ml, $tag, $cls, $ph);

// CSS propio de la ficha: estado "skeleton" de los campos vacíos (placeholder +
// alto mínimo para que no se solapen las etiquetas) y el deslizador de duración.
$extraHead = <<<'CSS'
<style>
  .ez-editing .ez-editable[data-ez-ph]:empty { display: block; min-height: 1.6em; }
  .ez-editing .ez-editable[data-ez-ph][data-ez-ml="1"]:empty { min-height: 3.4em; }
  .ez-editing .ez-editable[data-ez-ph]:empty::before {
    content: attr(data-ez-ph);
    color: rgba(92,64,51,.4); font-style: italic; font-weight: 400; pointer-events: none;
  }
  .ez-dur-edit { display: none; }
  .ez-editing .ez-dur-edit { display: block; }
  .ez-editing .ez-dur-view { display: none; }
  .ez-dur-range { -webkit-appearance: none; appearance: none; height: 6px; border-radius: 9999px; background: rgba(217,119,87,.22); outline: none; }
  .ez-dur-range::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 20px; height: 20px; border-radius: 50%; background: #D97757; cursor: pointer; border: 2px solid #fff; box-shadow: 0 2px 6px rgba(92,64,51,.3); }
  .ez-dur-range::-moz-range-thumb { width: 20px; height: 20px; border-radius: 50%; background: #D97757; cursor: pointer; border: 2px solid #fff; box-shadow: 0 2px 6px rgba(92,64,51,.3); }
</style>
CSS;

$pageTitle = $prof['nombre'] . ($titulo ? ' — ' . $titulo : '') . ' | Espacio Luz';
$pageDescription = 'Conoce a ' . $prof['nombre'] . ', ' . strtolower($titulo) . ' de Espacio Luz. ' . ($perfil['sobre_mi'] ?? '');
include APP_ROOT . '/partials/head.php';
include APP_ROOT . '/partials/header.php';

// Instagram personal por profesional (por nombre normalizado, robusto a acentos).
// Quien no tenga cuenta propia cae al Instagram del centro.
$IG_PROFESIONALES = [
    'andrea-beltran-venegas'    => 'psicopedagoga.andreabeltran',
    'angela-castillo-cadiz'     => 'flga.angecastillo',
    'gabriela-valenzuela'       => 'ps.gabivalenzuela',
    'paulina-urriola-henriquez' => 'pauli.mama.to',
    'valentina-leiva-weisser'   => 'psicologa_valentina',
];
$normaliza = static function (string $s): string {
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s;
    return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $s)), '-');
};
$igUser = $IG_PROFESIONALES[$normaliza((string) ($prof['nombre'] ?? ''))] ?? null;
if ($igUser !== null) {
    $igHref  = 'https://instagram.com/' . $igUser;
    $igLabel = '@' . $igUser;
} else {
    $igHref  = 'https://instagram.com/' . str_replace('@', '', $centro['instagram'] ?? 'espacioluz');
    $igLabel = $centro['instagram'] ?? '@espacioluz';
}
?>
<main class="bg-crema">
    <section class="relative overflow-hidden bg-warm-gradient pt-28 pb-14 sm:pt-32">
        <div class="pointer-events-none absolute -right-24 top-10 h-72 w-72 rounded-full bg-salvia/10 blur-3xl"></div>
        <div class="pointer-events-none absolute -left-20 bottom-0 h-64 w-64 rounded-full bg-terracota/10 blur-3xl"></div>
        <div class="container relative">
<?php if ($ezAuth && isset($_GET['nuevo'])): ?>
            <div id="ez-nuevo-guia" class="mb-6 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border border-terracota/25 bg-crema-light/80 px-4 py-3 shadow-card backdrop-blur-sm">
                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-terracota px-3 py-1 font-body text-xs font-bold uppercase tracking-wide text-crema-light">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    Ficha creada
                </span>
                <p class="min-w-0 flex-1 font-body text-sm leading-snug text-cafe/85">
                    Estás en <strong class="font-semibold">modo edición</strong>. Tocá cada sección —Sobre mí, Áreas de atención, Formación, Atención a…— para completar la ficha de <?= e(strtok($prof['nombre'], ' ')) ?>. Los cambios se guardan solos; al terminar, tocá <strong class="font-semibold">“Salir de edición”</strong>.
                </p>
                <button type="button" onclick="var g=document.getElementById('ez-nuevo-guia'); if(g) g.remove();" class="shrink-0 rounded-full border border-cafe/20 px-3 py-1.5 font-body text-xs font-semibold text-cafe transition-colors hover:border-terracota hover:text-terracota">Entendido</button>
            </div>
<?php endif; ?>
            <a href="<?= e(base_path('/#profesionales')) ?>" class="mb-6 inline-flex items-center gap-1.5 font-body text-sm font-semibold text-cafe/60 transition-colors hover:text-terracota">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>
                Volver a profesionales
            </a>
            <div class="grid items-center gap-8 md:grid-cols-[320px_1fr]">
                <div class="mx-auto w-full max-w-[320px]">
                    <?php
                    ob_start();
                    if ($hasFoto): ?>
                        <img src="<?= e($fotoUrl) ?>" alt="<?= attr($prof['nombre']) ?>" class="absolute inset-0 h-full w-full object-cover">
                    <?php else: ?>
                        <div class="flex h-full w-full items-center justify-center">
                            <span class="font-script text-[7rem] leading-none text-crema/95"><?= e(getIniciales($prof['nombre'])) ?></span>
                        </div>
                    <?php endif;
                    $detailFoto = ob_get_clean();
                    ?>
                    <div class="relative aspect-[4/5] w-full overflow-hidden rounded-[2.5rem] shadow-warm <?= $hasFoto ? '' : 'bg-gradient-to-br from-salvia to-salvia-dark' ?>">
                        <?= ez_prof_photo((int) $prof['id'], $detailFoto) ?>
                    </div>
                </div>
                <div>
                    <?php if ($prof['especialidad_nombre']): ?>
                        <span class="mb-3 inline-block rounded-full bg-terracota/12 px-4 py-1.5 font-body text-xs font-bold uppercase tracking-[0.18em] text-terracota-dark"><?= e($prof['especialidad_nombre']) ?></span>
                    <?php endif; ?>
                    <?= $ezCampo($prof['nombre'], 'nombre', false, 'h1', 'font-heading text-4xl font-semibold leading-tight text-cafe sm:text-5xl') ?>
                    <?php if (($prof['titulo'] ?? '') !== '' || $ezAuth): ?><?= $ezCampo($prof['titulo'] ?? '', 'titulo', false, 'p', 'mt-2 font-body text-lg font-semibold text-cafe/80', 'Ej.: Psicóloga Clínica') ?><?php endif; ?>
                    <?php if ($subtitulo !== '' || $ezAuth): ?><?= $ezPerfil($subtitulo, 'subtitulo', false, 'p', 'mt-1 font-body text-base text-cafe/60', 'Ej.: Psicoterapia para adolescentes y adultos') ?><?php endif; ?>
                    <?php if (!empty($perfil['chips']) || $ezAuth): ?>
                        <div class="mt-5 flex flex-wrap items-center gap-2 ez-list" data-ez-list="chips" data-ez-prof="<?= $pid ?>" data-ez-label="Datos destacados">
                            <?php foreach (($perfil['chips'] ?? []) as $chip): ?>
                                <span class="ez-li inline-flex items-center gap-1.5 rounded-full border border-cafe/15 bg-crema-light px-3.5 py-1.5 font-body text-xs font-semibold text-cafe/80"><?= e($chip) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="mt-7 flex flex-wrap items-center gap-3">
                        <?php if (!empty($prof['agenda_online']) || $ctaWhatsapp): ?><?= render_button('Agenda tu hora', $agendaHref, 'primary', 'lg') ?><?php endif; ?>
                        <a href="<?= e($igHref) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-full border-2 border-cafe/20 px-5 py-3 font-body text-sm font-semibold text-cafe transition-colors hover:border-terracota hover:text-terracota">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="2" width="20" height="20" rx="5.5"/><circle cx="12" cy="12" r="4.2"/><circle cx="17.6" cy="6.4" r="1.1" fill="currentColor" stroke="none"/></svg>
                            <?= e($igLabel) ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-14 sm:py-16">
        <div class="container">
            <div class="grid gap-8 lg:grid-cols-3">
                <!-- Columna principal -->
                <div class="lg:col-span-2 space-y-6">
                    <?php if (!empty($perfil['sobre_mi']) || $ezAuth): ?>
                        <div class="rounded-[2rem] border border-cafe/8 bg-crema-light p-7 shadow-card">
                            <h2 class="font-heading text-2xl font-semibold text-cafe">Sobre mí</h2>
                            <?= $ezPerfil($perfil['sobre_mi'] ?? '', 'sobre_mi', true, 'p', 'mt-3 font-body text-base leading-relaxed text-cafe/80', 'Breve presentación: formación, enfoque y a quiénes acompañas.') ?>
                            <?php if (!empty($perfil['enfoque']) || $ezAuth): ?>
                                <?= $ezPerfil($perfil['enfoque'] ?? '', 'enfoque', true, 'p', 'mt-4 block rounded-2xl bg-salvia/10 p-4 font-body text-sm leading-relaxed text-cafe/75', 'Una frase que resuma tu enfoque de trabajo.') ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($perfil['areas']) || $ezAuth): ?>
                        <div class="rounded-[2rem] border border-cafe/8 bg-crema-light p-7 shadow-card ez-list" data-ez-list="areas" data-ez-prof="<?= $pid ?>" data-ez-label="Áreas de atención">
                            <h2 class="font-heading text-2xl font-semibold text-cafe">Áreas de atención</h2>
                            <div class="mt-4 grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                                <?php foreach (($perfil['areas'] ?? []) as $area): ?>
                                    <div class="ez-li flex items-center gap-2.5 rounded-xl bg-crema/50 px-4 py-2.5 font-body text-sm text-cafe/80">
                                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-terracota/15 text-terracota-dark">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                        </span>
                                        <span class="ez-li-text"><?= e($area) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php
                    // Detalle de evaluaciones/servicios (perfil.servicios_detalle). El precio se
                    // toma del producto vinculado (fuente única), para que no se desfase.
                    $sdLista = is_array($perfil['servicios_detalle'] ?? null) ? $perfil['servicios_detalle'] : [];
                    $sdPrecios = [];
                    foreach ($servicios as $sdServ) {
                        $sdPrecios[(int) $sdServ['id']] = (int) $sdServ['precio_neto'];
                    }
                    ?>
                    <?php if ($sdLista): ?>
                        <div class="rounded-[2rem] border border-cafe/8 bg-crema-light p-7 shadow-card">
                            <h2 class="font-heading text-2xl font-semibold text-cafe">Evaluaciones y servicios</h2>
                            <div class="mt-5 space-y-4">
                                <?php foreach ($sdLista as $sdItem):
                                    $sdPrecio = $sdPrecios[(int) ($sdItem['producto_id'] ?? 0)] ?? null;
                                    $sdPasos = $sdItem['pasos'] ?? [];
                                    $sdIncluye = $sdItem['incluye'] ?? [];
                                ?>
                                    <article class="rounded-2xl border border-cafe/10 bg-crema/40 p-4 sm:p-5">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <h3 class="font-heading text-lg font-semibold text-cafe"><?= e($sdItem['titulo'] ?? '') ?></h3>
                                                <?php if (!empty($sdItem['modalidad'])): ?><p class="mt-1 font-body text-xs font-semibold uppercase tracking-wide text-salvia-dark"><?= e($sdItem['modalidad']) ?></p><?php endif; ?>
                                            </div>
                                            <?php if ($sdPrecio !== null): ?><span class="shrink-0 rounded-full bg-terracota/10 px-3 py-1 font-body text-sm font-bold text-terracota-dark">$<?= number_format($sdPrecio, 0, ',', '.') ?></span><?php endif; ?>
                                        </div>
                                        <?php if (!empty($sdItem['resumen'])): ?><p class="mt-3 font-body text-sm leading-relaxed text-cafe/80"><?= e($sdItem['resumen']) ?></p><?php endif; ?>
                                        <?php if ($sdPasos || $sdIncluye || !empty($sdItem['cierre'])): ?>
                                            <details class="group mt-3">
                                                <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 font-body text-sm font-semibold text-terracota-dark hover:text-terracota [&::-webkit-details-marker]:hidden">
                                                    <span class="group-open:hidden">Ver detalle</span><span class="hidden group-open:inline">Ocultar detalle</span>
                                                    <svg class="h-4 w-4 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                </summary>
                                                <div class="mt-4 space-y-5">
                                                    <?php if ($sdPasos): ?>
                                                        <ol class="space-y-3">
                                                            <?php foreach ($sdPasos as $sdN => $sdPaso): ?>
                                                                <li class="flex gap-3">
                                                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-cafe font-body text-xs font-bold text-crema"><?= $sdN + 1 ?></span>
                                                                    <div class="min-w-0">
                                                                        <p class="font-body text-sm font-semibold text-cafe"><?= e($sdPaso['titulo'] ?? '') ?></p>
                                                                        <p class="mt-0.5 font-body text-sm leading-relaxed text-cafe/75"><?= e($sdPaso['texto'] ?? '') ?></p>
                                                                    </div>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ol>
                                                    <?php endif; ?>
                                                    <?php if ($sdIncluye): ?>
                                                        <div>
                                                            <p class="font-body text-xs font-semibold uppercase tracking-wide text-cafe/50">La evaluación incluye</p>
                                                            <ul class="mt-2 grid gap-1.5 sm:grid-cols-2">
                                                                <?php foreach ($sdIncluye as $sdInc): ?>
                                                                    <li class="flex items-start gap-2 font-body text-sm text-cafe/80">
                                                                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-salvia-dark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                                                        <span><?= e($sdInc) ?></span>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($sdItem['cierre'])): ?><p class="font-body text-sm italic leading-relaxed text-cafe/70"><?= e($sdItem['cierre']) ?></p><?php endif; ?>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($perfil['formacion']) || $ezAuth): ?>
                        <div class="rounded-[2rem] border border-cafe/8 bg-crema-light p-7 shadow-card ez-list" data-ez-list="formacion" data-ez-prof="<?= $pid ?>" data-ez-label="Formación y certificaciones">
                            <h2 class="font-heading text-2xl font-semibold text-cafe">Formación y certificaciones</h2>
                            <ul class="mt-4 space-y-3">
                                <?php foreach (($perfil['formacion'] ?? []) as $f): ?>
                                    <li class="ez-li flex items-start gap-3 font-body text-sm leading-relaxed text-cafe/80">
                                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-terracota"></span>
                                        <span class="ez-li-text"><?= e($f) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($perfil['cita']) || $ezAuth): ?>
                        <blockquote class="rounded-[2rem] bg-cafe p-7 shadow-warm">
                            <svg class="h-8 w-8 text-terracota-light" viewBox="0 0 24 24" fill="currentColor"><path d="M7 7h4v6H7a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2zm0 0c0-1.5.5-2.5 2-3M15 7h4v6h-4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2zm0 0c0-1.5.5-2.5 2-3" stroke="currentColor" stroke-width="0" /></svg>
                            <p class="mt-2 font-heading text-xl font-medium italic leading-relaxed text-crema">“<?= $ezPerfil($perfil['cita'] ?? '', 'cita', true, 'span', '') ?>”</p>
                            <p class="mt-3 font-body text-sm font-semibold text-crema/70">— <?= e($prof['nombre']) ?></p>
                        </blockquote>
                    <?php endif; ?>
                </div>

                <!-- Columna lateral -->
                <aside class="space-y-6">
                    <?php if (!empty($perfil['atiende']) || $ezAuth): ?>
                        <div class="rounded-[2rem] border border-cafe/8 bg-crema-light p-6 shadow-card ez-list" data-ez-list="atiende" data-ez-prof="<?= $pid ?>" data-ez-label="Atención a">
                            <h3 class="font-heading text-lg font-semibold text-cafe">Atención a</h3>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <?php foreach (($perfil['atiende'] ?? []) as $a): ?>
                                    <span class="ez-li rounded-full bg-salvia/15 px-3 py-1.5 font-body text-xs font-semibold text-salvia-dark"><?= e($a) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($perfil['acompanamiento']) || $ezAuth): ?>
                        <div class="rounded-[2rem] border border-cafe/8 bg-crema-light p-6 shadow-card">
                            <h3 class="font-heading text-lg font-semibold text-cafe">Acompañamiento clínico</h3>
                            <?= $ezPerfil($perfil['acompanamiento'] ?? '', 'acompanamiento', true, 'p', 'mt-2 font-body text-sm leading-relaxed text-cafe/75', 'Cómo acompañas (sesiones, informes, trabajo con la familia…).') ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($perfil['universidad']) || !empty($perfil['experiencia']) || !empty($prof['duracion_texto']) || $ezAuth): ?>
                        <div class="rounded-[2rem] border border-cafe/8 bg-crema-light p-6 shadow-card space-y-3">
                            <?php if (!empty($perfil['universidad']) || $ezAuth): ?>
                                <div><p class="font-body text-[11px] font-semibold uppercase tracking-wide text-cafe/45">Formación de base</p><?= $ezPerfil($perfil['universidad'] ?? '', 'universidad', false, 'p', 'font-body text-sm font-semibold text-cafe', 'Ej.: Psicología · Universidad de Chile') ?></div>
                            <?php endif; ?>
                            <?php if (!empty($perfil['experiencia']) || $ezAuth): ?>
                                <div><p class="font-body text-[11px] font-semibold uppercase tracking-wide text-cafe/45">Experiencia</p><?= $ezPerfil($perfil['experiencia'] ?? '', 'experiencia', false, 'p', 'font-body text-sm font-semibold text-cafe', 'Ej.: 8 años en el área clínica.') ?></div>
                            <?php endif; ?>
                            <?php if (!empty($prof['duracion_texto']) || $ezAuth): ?>
                                <?php
                                // Duración: texto para el público; deslizador (en nuestros colores)
                                // en modo edición. Guarda en la misma columna duracion_texto.
                                $durNum = 50;
                                if (preg_match('/\d+/', (string) ($prof['duracion_texto'] ?? ''), $mDur)) { $durNum = (int) $mDur[0]; }
                                $durNum = max(15, min(120, (int) (round($durNum / 5) * 5)));
                                $durView = ($prof['duracion_texto'] ?? '') !== '' ? $prof['duracion_texto'] : '—';
                                ?>
                                <div class="ez-dur" data-prof="<?= $pid ?>">
                                    <p class="font-body text-[11px] font-semibold uppercase tracking-wide text-cafe/45">Duración de sesión</p>
                                    <p class="ez-dur-view font-body text-sm font-semibold text-cafe"><?= e($durView) ?></p>
                                    <?php if ($ezAuth): ?>
                                    <div class="ez-dur-edit mt-1.5">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-body text-xs text-cafe/55">Arrastra para ajustar</span>
                                            <span class="ez-dur-out rounded-full bg-terracota/10 px-2.5 py-0.5 font-body text-sm font-bold text-terracota-dark"><?= $durNum ?> min</span>
                                        </div>
                                        <input type="range" class="ez-dur-range mt-2 w-full" min="15" max="120" step="5" value="<?= $durNum ?>" aria-label="Duración de la sesión en minutos">
                                        <div class="mt-1 flex justify-between font-body text-[10px] text-cafe/40"><span>15 min</span><span>120 min</span></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($servicios): ?>
                    <div class="rounded-[2rem] border border-cafe/8 bg-crema-light p-6 shadow-card">
                        <h3 class="font-heading text-lg font-semibold text-cafe">Servicios y valores</h3>
                        <ul class="mt-3 divide-y divide-cafe/8">
                            <?php foreach ($servicios as $s): ?>
                                <li class="flex items-start justify-between gap-3 py-2.5">
                                    <div class="min-w-0">
                                        <p class="font-body text-sm font-semibold text-cafe"><?= e($s['nombre']) ?></p>
                                        <?php if (!empty($s['descripcion'])): ?><p class="mt-0.5 font-body text-xs leading-snug text-cafe/55"><?= e($s['descripcion']) ?></p><?php endif; ?>
                                    </div>
                                    <span class="shrink-0 whitespace-nowrap font-body text-sm font-bold text-terracota-dark">$<?= number_format((int) $s['precio_neto'], 0, ',', '.') ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="mt-3 font-body text-[11px] text-cafe/45">Valores por servicio · exentos de IVA (prestación de salud).</p>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($prof['agenda_online'])): ?>
                    <div class="rounded-[2rem] bg-terracota/10 p-6 text-center shadow-card">
                        <p class="font-heading text-lg font-semibold text-cafe">¿Agendamos tu hora?</p>
                        <p class="mt-1 font-body text-sm text-cafe/70">Reserva y paga tu sesión en línea.</p>
                        <?= render_button('Agenda tu hora', $agendaHref, 'primary', 'md', 'mt-4 w-full justify-center') ?>
                    </div>
                    <?php elseif ($ctaWhatsapp): ?>
                    <!-- Agendamiento por WhatsApp (mismo formato que el arriendo de boxes) -->
                    <div class="rounded-[2rem] bg-salvia/12 p-6 text-cafe shadow-card ring-1 ring-salvia/25">
                        <span class="inline-flex items-center gap-2 rounded-full bg-salvia/25 px-3 py-1 font-body text-xs font-semibold uppercase tracking-wide text-salvia-dark">Agenda tu hora</span>
                        <p class="mt-3 font-heading text-lg font-semibold text-cafe">¿Agendamos tu hora?</p>
                        <p class="mt-1 font-body text-sm leading-relaxed text-cafe/70">
                            Escríbenos por WhatsApp y te enviamos las horas disponibles con <?= e(strtok($prof['nombre'], ' ')) ?>. Coordinamos todo de forma personalizada.
                        </p>
                        <a href="<?= e($agendaHref) ?>" target="_blank" rel="noopener noreferrer"
                            class="mt-5 inline-flex w-full items-center justify-center gap-2.5 rounded-full bg-salvia-dark px-6 py-3.5 font-body text-base font-semibold text-crema-light shadow-card transition-all hover:-translate-y-0.5 hover:bg-salvia">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.29-.15-1.71-.84-1.97-.94-.26-.1-.46-.15-.65.15-.19.29-.75.94-.92 1.13-.17.19-.34.21-.63.07-.29-.15-1.22-.45-2.33-1.44-.86-.77-1.44-1.72-1.61-2.01-.17-.29-.02-.45.13-.6.13-.13.29-.34.44-.51.15-.17.19-.29.29-.48.1-.19.05-.36-.02-.51-.07-.15-.65-1.57-.89-2.15-.24-.57-.48-.49-.65-.5l-.56-.01c-.19 0-.51.07-.77.36-.26.29-1.01.99-1.01 2.41 0 1.42 1.04 2.79 1.18 2.98.15.19 2.04 3.12 4.95 4.37.69.3 1.23.48 1.65.62.69.22 1.32.19 1.82.12.56-.08 1.71-.7 1.95-1.37.24-.67.24-1.25.17-1.37-.07-.12-.26-.19-.55-.34zM12.05 21.5h-.01a9.44 9.44 0 0 1-4.81-1.32l-.34-.2-3.57.94.95-3.48-.22-.36a9.42 9.42 0 0 1-1.44-5.02c0-5.2 4.24-9.44 9.46-9.44 2.53 0 4.9.99 6.69 2.78a9.38 9.38 0 0 1 2.77 6.67c0 5.2-4.24 9.44-9.46 9.44zm8.04-17.48A11.36 11.36 0 0 0 12.05.5C5.8.5.72 5.58.72 11.83c0 2.08.54 4.11 1.58 5.9L.62 23.5l5.9-1.55a11.3 11.3 0 0 0 5.52 1.41h.01c6.25 0 11.33-5.08 11.33-11.33 0-3.03-1.18-5.87-3.29-8.01z"/></svg>
                            Ver horas disponibles
                        </a>
                        <p class="mt-3 text-center font-body text-xs text-cafe/50">Te respondemos por WhatsApp a la brevedad.</p>
                    </div>
                    <?php endif; ?>
                </aside>
            </div>
        </div>
    </section>
</main>
<?php if ($ezAuth && isset($_GET['nuevo'])): ?>
<script>
(function () {
    // Ficha recién creada: entra solo al modo edición para completar todas las
    // secciones. Reutiliza el botón global (#ez-edit-btn) para no duplicar lógica.
    var tries = 0;
    function entrarEdicion() {
        if (document.body.classList.contains('ez-editing')) return;
        var btn = document.getElementById('ez-edit-btn');
        if (btn) { btn.click(); return; }
        if (tries++ < 40) setTimeout(entrarEdicion, 100);
    }
    if (document.readyState === 'complete') entrarEdicion();
    else window.addEventListener('load', entrarEdicion);
})();
</script>
<?php endif; ?>
<?php if ($ezAuth): ?>
<script>
(function () {
    // Deslizador de "Duración de sesión": guarda en duracion_texto vía /api/content.
    var P = window.__EZ__ || {};
    document.querySelectorAll('.ez-dur').forEach(function (box) {
        var range = box.querySelector('.ez-dur-range');
        if (!range) return;
        var out = box.querySelector('.ez-dur-out');
        var view = box.querySelector('.ez-dur-view');
        var pid = parseInt(box.dataset.prof, 10);
        function txt(v) { return v + ' min'; }
        range.addEventListener('input', function () { if (out) out.textContent = txt(range.value); });
        range.addEventListener('change', function () {
            var val = txt(range.value);
            if (out) out.textContent = val;
            if (view) view.textContent = val;
            fetch(P.base + '/api/content', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf },
                body: JSON.stringify({ target: { type: 'profesional', id: pid, field: 'duracion_texto' }, value: val })
            }).catch(function () {});
        });
    });
})();
</script>
<?php endif; ?>
<?php
include APP_ROOT . '/partials/footer.php';
include APP_ROOT . '/partials/foot.php';
