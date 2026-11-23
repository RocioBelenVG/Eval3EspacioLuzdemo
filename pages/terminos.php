<?php
/** Página "Términos y condiciones". Contenido editable (tipo 'pagina', key 'terminos'). */
declare(strict_types=1);

$content = getContent();
$key = 'terminos';
$p = $content['paginas'][$key];
$t = fn(string $f) => ['type' => 'pagina', 'key' => $key, 'field' => $f];

require_once APP_ROOT . '/partials/page-icons.php';

$pageTitle = 'Términos y condiciones — Espacio Luz';
$pageDescription = 'Condiciones de uso del sitio y del servicio de agendamiento de Espacio Luz.';

include APP_ROOT . '/partials/head.php';
include APP_ROOT . '/partials/header.php';
?>
<main class="bg-crema">
    <div class="container max-w-3xl py-28 sm:py-36">
        <article class="rounded-3xl border border-cafe/10 bg-crema-light p-7 shadow-card sm:p-12">
            <h1 class="font-heading text-3xl font-semibold text-cafe sm:text-4xl">
                <?= ez_editable($p['titulo'], $t('titulo')) ?>
            </h1>
            <p class="mt-2 font-body text-sm text-cafe/50">
                <?= ez_editable($p['actualizado'], $t('actualizado')) ?>
            </p>
            <p class="mt-6 font-body text-base leading-relaxed text-cafe/80">
                <?= ez_editable($p['intro'], $t('intro'), true) ?>
            </p>
            <div class="mt-8 space-y-4">
                <?php for ($n = 1; isset($p["s{$n}t"]); $n++): ?>
                    <?php $res = $p["s{$n}r"] ?? ''; ?>
                    <section class="ez-acc overflow-hidden rounded-2xl border border-cafe/10 bg-crema-light shadow-card transition-shadow hover:shadow-warm">
                        <div class="ez-acc-head flex cursor-pointer items-start gap-4 p-5 sm:p-6" role="button" tabindex="0" aria-expanded="false">
                            <span class="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-terracota/10 text-terracota ring-1 ring-terracota/15">
                                <?= page_section_icon($key, $n) ?>
                            </span>
                            <div class="min-w-0 flex-1">
                                <h2 class="font-heading text-lg font-semibold text-cafe">
                                    <?= ez_editable($p["s{$n}t"], $t("s{$n}t")) ?>
                                </h2>
                                <?php if ($res !== ''): ?>
                                    <p class="mt-1 font-body text-sm text-cafe/55">
                                        <?= ez_editable($res, $t("s{$n}r")) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <span class="ez-acc-ico mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-terracota text-crema-light transition-transform duration-200" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" class="h-4 w-4"><path d="M12 5v14M5 12h14"/></svg>
                            </span>
                        </div>
                        <div class="ez-acc-panel hidden px-5 pb-5 sm:px-6 sm:pb-6">
                            <div class="border-t border-cafe/10 pt-4 font-body text-sm leading-relaxed text-cafe/75">
                                <?= ez_editable($p["s{$n}b"], $t("s{$n}b"), true) ?>
                            </div>
                        </div>
                    </section>
                <?php endfor; ?>
            </div>
        </article>
    </div>
</main>
<?php
include APP_ROOT . '/partials/footer.php';
include APP_ROOT . '/partials/foot.php';
