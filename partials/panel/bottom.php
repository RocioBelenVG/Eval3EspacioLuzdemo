        </main>
    </div>
</div>
<script>
    (function () {
        var side = document.getElementById('pnl-sidebar');
        var ov = document.getElementById('pnl-overlay');
        var btn = document.getElementById('pnl-menu');
        function open() { side.classList.remove('-translate-x-full'); ov.classList.remove('hidden'); if (btn) btn.setAttribute('aria-expanded', 'true'); var l = side.querySelector('a'); if (l) l.focus(); }
        function close() { side.classList.add('-translate-x-full'); ov.classList.add('hidden'); if (btn) { btn.setAttribute('aria-expanded', 'false'); } }
        if (btn) btn.addEventListener('click', open);
        if (ov) ov.addEventListener('click', close);
        // Menú móvil: Esc lo cierra y devuelve el foco al botón (sin trampas de foco).
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && btn && btn.getAttribute('aria-expanded') === 'true') { close(); btn.focus(); } });
    })();
    window.__PANEL__ = { base: <?= json_encode(BASE_URL) ?>, csrf: <?= json_encode(csrf_token()) ?> };
</script>
<?php $panelJs = ASSETS_DIR . '/js/panel.js'; $pjv = is_file($panelJs) ? filemtime($panelJs) : time(); ?>
<script src="<?= e(asset('js/panel.js')) ?>?v=<?= $pjv ?>"></script>
</body>
</html>
