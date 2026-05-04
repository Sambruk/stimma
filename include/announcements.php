<?php
/**
 * Stimma — Informationsmeddelanden från superadmin
 *
 * Visas som modal-popup för admin/redaktör i både admin- och användarvyn
 * tills användaren klickar "visa inte igen". När superadmin publicerar ett
 * nytt meddelande nollställs dismiss-statusen automatiskt eftersom
 * dismissals är knutna till announcement_id.
 *
 * Endast ETT meddelande är aktivt åt gången — när nytt skapas/aktiveras
 * inaktiveras alla andra.
 */

/**
 * Hämta det aktuella aktiva meddelandet för en användare. Returnerar null
 * om inget aktivt meddelande finns, eller om användaren redan har klickat
 * "visa inte igen" på det aktuella meddelandet.
 *
 * @param int $userId
 * @return array|null { id, title, body, created_at }
 */
function getActiveAnnouncementForUser($userId) {
    $userId = (int)$userId;
    if ($userId <= 0) return null;

    $row = queryOne(
        "SELECT a.id, a.title, a.body, a.created_at
           FROM " . DB_DATABASE . ".announcements a
           LEFT JOIN " . DB_DATABASE . ".announcement_dismissals d
             ON d.announcement_id = a.id AND d.user_id = ?
          WHERE a.active = 1 AND d.user_id IS NULL
          ORDER BY a.created_at DESC
          LIMIT 1",
        [$userId]
    );
    return $row ?: null;
}

/**
 * Markera meddelande som "visa inte igen" för en användare.
 * Idempotent — INSERT IGNORE.
 */
function dismissAnnouncementForUser($userId, $announcementId) {
    return execute(
        "INSERT IGNORE INTO " . DB_DATABASE . ".announcement_dismissals
         (user_id, announcement_id) VALUES (?, ?)",
        [(int)$userId, (int)$announcementId]
    ) !== null;
}

/**
 * Hämta lista över alla meddelanden (för superadmin-UI), med antal dismissals.
 */
function listAnnouncements() {
    return query(
        "SELECT a.*,
                (SELECT COUNT(*) FROM " . DB_DATABASE . ".announcement_dismissals d
                  WHERE d.announcement_id = a.id) AS dismiss_count
           FROM " . DB_DATABASE . ".announcements a
          ORDER BY a.created_at DESC"
    );
}

function getAnnouncement($id) {
    return queryOne(
        "SELECT * FROM " . DB_DATABASE . ".announcements WHERE id = ?",
        [(int)$id]
    );
}

/**
 * Skapa nytt meddelande. Om $active=1 inaktiveras alla andra först.
 * Returnerar nya id:t.
 */
function createAnnouncement($title, $body, $active, $createdBy) {
    if ($active) {
        execute("UPDATE " . DB_DATABASE . ".announcements SET active = 0 WHERE active = 1", []);
    }
    return execute(
        "INSERT INTO " . DB_DATABASE . ".announcements (title, body, active, created_by)
         VALUES (?, ?, ?, ?)",
        [trim($title), $body, $active ? 1 : 0, $createdBy]
    );
}

/**
 * Uppdatera befintligt meddelande. NOTERA: dismissals nollställs INTE vid
 * redigering — bara ett helt nytt meddelande triggar att alla ser det igen.
 * Detta är medvetet så superadmin kan rätta typos utan att alla störs.
 */
function updateAnnouncement($id, $title, $body) {
    return execute(
        "UPDATE " . DB_DATABASE . ".announcements
            SET title = ?, body = ?
          WHERE id = ?",
        [trim($title), $body, (int)$id]
    ) !== null;
}

/**
 * Sätt active-flagga. Om $active=1 inaktiveras alla andra först.
 * Att aktivera ett gammalt meddelande visar det INTE för dem som redan
 * dismissat det — de behöver ett helt nytt id.
 */
function setAnnouncementActive($id, $active) {
    if ($active) {
        execute("UPDATE " . DB_DATABASE . ".announcements SET active = 0 WHERE active = 1", []);
    }
    return execute(
        "UPDATE " . DB_DATABASE . ".announcements SET active = ? WHERE id = ?",
        [$active ? 1 : 0, (int)$id]
    ) !== null;
}

/**
 * Radera meddelande (CASCADE tar dismissals).
 */
function deleteAnnouncement($id) {
    return execute(
        "DELETE FROM " . DB_DATABASE . ".announcements WHERE id = ?",
        [(int)$id]
    ) !== null;
}

/**
 * Sanering av announcement-body innan render. Tillåter en begränsad
 * uppsättning HTML-taggar (samma idé som i widget.js men mer tillåtande).
 */
/**
 * Skriver ut Bootstrap-modal med auto-show för det aktuella aktiva
 * meddelandet (om det finns och användaren inte redan dismissat det).
 *
 * Anropas från admin- och user-headers efter att Bootstrap JS laddats.
 * Tomt utskrift om inget meddelande ska visas.
 */
function renderAnnouncementModal($userId, $csrfToken) {
    $userId = (int)$userId;
    if ($userId <= 0) return;

    $ann = getActiveAnnouncementForUser($userId);
    if (!$ann) return;

    $title = htmlspecialchars($ann['title'], ENT_QUOTES, 'UTF-8');
    $body = sanitizeAnnouncementBody($ann['body']);
    $id = (int)$ann['id'];
    $csrf = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
    $created = htmlspecialchars($ann['created_at'], ENT_QUOTES, 'UTF-8');
    ?>
<div class="modal fade" id="stimmaAnnouncementModal" tabindex="-1" aria-labelledby="stimmaAnnouncementLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="stimmaAnnouncementLabel">
                    <i class="bi bi-megaphone me-2"></i><?= $title ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Stäng"></button>
            </div>
            <div class="modal-body">
                <?= $body /* sanerad HTML, se sanitizeAnnouncementBody */ ?>
                <div class="text-muted small mt-3 border-top pt-2">Publicerat <?= $created ?></div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <button type="button" class="btn btn-link text-secondary" data-bs-dismiss="modal">
                    Stäng (visa igen nästa gång)
                </button>
                <button type="button" class="btn btn-primary" id="stimmaAnnouncementDismissBtn"
                        data-announcement-id="<?= $id ?>"
                        data-csrf="<?= $csrf ?>">
                    <i class="bi bi-check2 me-1"></i>Förstått, visa inte igen
                </button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    function init() {
        if (!window.bootstrap || !window.bootstrap.Modal) {
            // Bootstrap inte laddat än — vänta lite
            setTimeout(init, 50);
            return;
        }
        var el = document.getElementById('stimmaAnnouncementModal');
        if (!el) return;
        var modal = new bootstrap.Modal(el);
        modal.show();

        var btn = document.getElementById('stimmaAnnouncementDismissBtn');
        if (btn) {
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-announcement-id');
                var csrf = btn.getAttribute('data-csrf');
                btn.disabled = true;
                var fd = new FormData();
                fd.append('announcement_id', id);
                fd.append('csrf_token', csrf);
                fetch('/admin/ajax/dismiss_announcement.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                }).then(function () {
                    modal.hide();
                }).catch(function () {
                    btn.disabled = false;
                    alert('Kunde inte spara — försök igen.');
                });
            });
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
    <?php
}

function sanitizeAnnouncementBody($html) {
    $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><a><h2><h3><h4><code><pre><blockquote><hr>';
    $clean = strip_tags((string)$html, $allowed);
    // Ta bort eventuella event-handlers och javascript:-länkar
    $clean = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', $clean);
    $clean = preg_replace('/\son\w+\s*=\s*\'[^\']*\'/i', '', $clean);
    $clean = preg_replace('/href\s*=\s*"javascript:[^"]*"/i', 'href="#"', $clean);
    $clean = preg_replace('/href\s*=\s*\'javascript:[^\']*\'/i', "href='#'", $clean);
    return $clean;
}
