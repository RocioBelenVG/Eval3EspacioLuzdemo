        </main>
    </div>
</div>
<script>
    (function () {
        var side = document.getElementById('pnl-sidebar');
        var ov = document.getElementById('pnl-overlay');
        var btn = document.getElementById('pnl-menu');
        function open() { side.classList.remove('-translate-x-full'); ov.classList.remove('hidden'); }
        function close() { side.classList.add('-translate-x-full'); ov.classList.add('hidden'); }
        if (btn) btn.addEventListener('click', open);
        if (ov) ov.addEventListener('click', close);
    })();
    window.__PANEL__ = { base: <?= json_encode(BASE_URL) ?>, csrf: <?= json_encode(csrf_token()) ?> };
</script>
<?php $panelJs = ASSETS_DIR . '/js/panel.js'; $pjv = is_file($panelJs) ? filemtime($panelJs) : time(); ?>
<script src="<?= e(asset('js/panel.js')) ?>?v=<?= $pjv ?>"></script>
</body>
</html>
