<?php if (function_exists('isLoggedIn') && isLoggedIn()): ?>
        </div><!-- /.rd-main -->
    </div><!-- /.rd-shell -->
<?php endif; ?>

<!-- Ladda JS-bibliotek -->
<script src="include/js/stimma-confetti.js"></script>

<!-- Sidebar-hamburger för mobil — togglar body.rd-sidebar-open -->
<script>
(function() {
    var btn = document.getElementById('rdSidebarToggle');
    var backdrop = document.querySelector('.rd-sidebar-backdrop');
    if (!btn) return;
    function close() {
        document.body.classList.remove('rd-sidebar-open');
        btn.setAttribute('aria-expanded', 'false');
    }
    btn.addEventListener('click', function() {
        var open = document.body.classList.toggle('rd-sidebar-open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    if (backdrop) backdrop.addEventListener('click', close);
    // Stäng på Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.body.classList.contains('rd-sidebar-open')) close();
    });
})();
</script>

<!-- Tema-växlare: knapp i navbaren togglar data-bs-theme + sparar i localStorage -->
<script>
(function() {
    var btn = document.getElementById('stimmaThemeToggle');
    if (!btn) return;
    var icon = btn.querySelector('i');

    function applyIcon(theme) {
        if (!icon) return;
        if (theme === 'dark') {
            icon.classList.remove('bi-moon-stars');
            icon.classList.add('bi-sun');
        } else {
            icon.classList.remove('bi-sun');
            icon.classList.add('bi-moon-stars');
        }
    }
    applyIcon(document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light');

    btn.addEventListener('click', function() {
        var current = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
        var next = current === 'dark' ? 'light' : 'dark';
        if (next === 'dark') {
            document.documentElement.setAttribute('data-bs-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-bs-theme');
        }
        try { localStorage.setItem('stimma_theme', next); } catch (e) {}
        applyIcon(next);
    });
})();
</script>


        <div class="container-fluid footer text-center">
            <p class="text-muted small p-2 mb-0">
                © <?= date('Y') ?> <a href="https://stimma.se" class="text-muted">Stimma.se</a> v2.1.0.
                Tillgänglig under GPL v2-licens.
                <a href="tillganglighet.php" class="text-muted ms-2">
                    <i class="bi bi-universal-access me-1"></i>Tillgänglighetsredogörelse
                </a>
                <a href="https://github.com/Sambruk/stimma" class="text-muted ms-2" target="_blank" rel="noopener"><i class="bi bi-github"></i> Öppen källkod</a>
            </p>
        </div>
</body>
</html>
