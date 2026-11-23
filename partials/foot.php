<?php
/**
 * Pie común del documento: botón flotante de edición (solo admin) y scripts.
 * Reemplaza a EditToggleButton.tsx + los "use client" globales.
 */
declare(strict_types=1);
?>
<?php if (puedeEditarSitio()): /* el rol Pacientes no edita el sitio */ ?>
    <div id="ez-edit-toggle" class="fixed right-6 z-[100] flex flex-col items-end gap-2"
        style="bottom: calc(1.5rem + env(safe-area-inset-bottom, 0px));">
        <span id="ez-edit-hint" class="hidden rounded-full bg-cafe px-3 py-1 font-body text-xs font-semibold text-crema shadow-card">
            Editando contenidos — toca un texto para cambiarlo
        </span>
        <div class="flex items-center gap-2">
            <button type="button" id="ez-save-all"
                class="rounded-full px-5 py-3 font-body text-sm font-semibold shadow-warm transition-colors bg-salvia-dark text-crema-light hover:bg-salvia">
                Guardar cambios
            </button>
            <button type="button" id="ez-edit-btn"
                class="rounded-full px-5 py-3 font-body text-sm font-semibold shadow-warm transition-colors bg-terracota text-crema-light hover:bg-terracota-dark">
                Editar contenidos
            </button>
        </div>
    </div>
    <style>
        #ez-save-all { display: none; }
        body.ez-editing #ez-save-all { display: inline-flex; }
        .ez-field-save { position: fixed; z-index: 110; border: none; cursor: pointer; border-radius: 9999px; background: #6D8072; color: #FBF9F4; font-family: var(--font-body, inherit); font-size: 12px; font-weight: 600; line-height: 1.2; padding: 5px 12px; box-shadow: 0 6px 16px -6px rgba(92,64,51,.5); }
        .ez-field-save:hover { background: #8B9E8F; }
        .ez-toast { position: fixed; left: 50%; bottom: 5.5rem; z-index: 120; transform: translate(-50%, 8px); border-radius: 9999px; background: #6D8072; color: #FBF9F4; font-family: var(--font-body, inherit); font-size: 13px; font-weight: 600; padding: 8px 18px; box-shadow: 0 10px 30px -10px rgba(92,64,51,.5); opacity: 0; transition: opacity .25s ease, transform .25s ease; pointer-events: none; }
    </style>
<?php endif; ?>

<?php
// Cache-busting: añade la fecha de modificación del archivo como versión, para
// que el navegador nunca sirva una copia antigua del JS tras una actualización.
$mainJsPath = ASSETS_DIR . '/js/main.js';
$mainJsVer = is_file($mainJsPath) ? filemtime($mainJsPath) : time();
?>
<?php include APP_ROOT . '/partials/ez-dialog.php'; ?>
<script src="<?= e(asset('js/main.js')) ?>?v=<?= $mainJsVer ?>"></script>
<?php if (puedeEditarSitio()): ?>
<script>
(function () {
    // Refuerzo de guardado (el editor ya auto-guarda al salir de cada campo):
    //  (1) un botón "Guardar" flotante junto al campo que se está editando, y
    //  (2) el botón global "Guardar cambios" junto a "Salir de edición".
    // No reemplaza el auto-guardado: ambos botones solo confirman/fuerzan el blur.
    var EZ = window.__EZ__ || {};
    if (!EZ.auth) return;

    var fieldBtn = null, fieldNode = null;
    function place() {
        if (!fieldBtn || !fieldNode) return;
        var r = fieldNode.getBoundingClientRect();
        fieldBtn.style.top = Math.max(8, r.top - 14) + 'px';
        fieldBtn.style.left = Math.min(window.innerWidth - 96, r.right - 72) + 'px';
    }
    function removeFieldBtn() { if (fieldBtn) { fieldBtn.remove(); fieldBtn = null; fieldNode = null; } }

    document.addEventListener('focusin', function (e) {
        var node = e.target && e.target.closest ? e.target.closest('.ez-editable') : null;
        if (!node || node.getAttribute('contenteditable') !== 'true' || node === fieldNode) return;
        removeFieldBtn();
        fieldNode = node;
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'ez-field-save';
        b.textContent = 'Guardar';
        // Al presionar NO robamos el foco: salimos del campo (blur) y eso dispara
        // el guardado automático existente (con su badge "Guardado ✓").
        b.addEventListener('mousedown', function (ev) { ev.preventDefault(); node.blur(); });
        document.body.appendChild(b);
        fieldBtn = b;
        place();
    });
    document.addEventListener('focusout', function (e) {
        var node = e.target && e.target.closest ? e.target.closest('.ez-editable') : null;
        if (node && node === fieldNode) removeFieldBtn();
    });
    window.addEventListener('scroll', place, { passive: true });
    window.addEventListener('resize', place);

    function toast(msg) {
        var t = document.createElement('div');
        t.className = 'ez-toast';
        t.textContent = msg;
        document.body.appendChild(t);
        requestAnimationFrame(function () { t.style.opacity = '1'; t.style.transform = 'translate(-50%, 0)'; });
        setTimeout(function () { t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 300); }, 1800);
    }
    var saveAll = document.getElementById('ez-save-all');
    if (saveAll) saveAll.addEventListener('click', function () {
        var a = document.activeElement;
        if (a && a.classList && a.classList.contains('ez-editable') && a.getAttribute('contenteditable') === 'true') {
            a.blur(); // confirma el campo en edición (dispara su guardado)
        }
        toast('Cambios guardados ✓');
    });
})();
</script>
<?php endif; ?>
</body>
</html>
