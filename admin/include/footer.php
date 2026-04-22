<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 * 
 * This program is free software; licensed under GPL v2.
 * See LICENSE and LICENSE-AND-TRADEMARK.md for details.
 * 
 * The name "Stimma" is a trademark and subject to restrictions.
 */
?>

        </div>
    </div>

    <footer class="py-3 border-top mt-4 text-center">
        <small class="text-muted">
            © <?= date('Y') ?> Stimma —
            <a href="../tillganglighet.php" class="text-muted">
                <i class="bi bi-universal-access me-1"></i>Tillgänglighetsredogörelse
            </a>
        </small>
    </footer>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.13.2/jquery-ui.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    <?= $extra_scripts ?? '' ?>
</body>
</html>
