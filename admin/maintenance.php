<?php
/**
 * Stimma — Underhållsläge (superadmin)
 *
 * Aktiverar/avaktiverar globalt underhållsläge. När aktivt visar systemet
 * maintenance.php (HTTP 503) för alla utom superadmin. Flaggan lagras som
 * data/maintenance.json och kontrolleras via gate i include/auth.php.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';

require_once 'include/auth_check.php';

// Endast superadmin
$currentUser = queryOne("SELECT email, role FROM " . DB_DATABASE . ".users WHERE email = ?", [$_SESSION['user_email']]);
if (!$currentUser || $currentUser['role'] !== 'super_admin') {
    $_SESSION['message'] = 'Endast superadministratörer kan hantera underhållsläge.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

$page_title = 'Underhållsläge';

// Hantera POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['message'] = 'Ogiltig säkerhetstoken. Försök igen.';
        $_SESSION['message_type'] = 'danger';
        header('Location: maintenance.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'enable') {
        $message = trim($_POST['message'] ?? '');
        if (enableMaintenanceMode($currentUser['email'], $message ?: null)) {
            $_SESSION['message'] = 'Underhållsläget är nu aktivt. Endast superadministratörer kan logga in.';
            $_SESSION['message_type'] = 'success';
            logActivity($currentUser['email'], 'Aktiverade underhållsläge', [
                'action' => 'maintenance_enable',
                'message' => $message,
            ]);
        } else {
            $_SESSION['message'] = 'Kunde inte aktivera underhållsläget. Kontrollera filrättigheter på data/.';
            $_SESSION['message_type'] = 'danger';
        }
    } elseif ($action === 'disable') {
        if (disableMaintenanceMode()) {
            $_SESSION['message'] = 'Underhållsläget är avstängt. Systemet är åter tillgängligt för alla.';
            $_SESSION['message_type'] = 'success';
            logActivity($currentUser['email'], 'Avaktiverade underhållsläge', [
                'action' => 'maintenance_disable',
            ]);
        } else {
            $_SESSION['message'] = 'Kunde inte avaktivera underhållsläget.';
            $_SESSION['message_type'] = 'danger';
        }
    }

    header('Location: maintenance.php');
    exit;
}

$mode = getMaintenanceMode();
$isActive = $mode !== null;

require_once 'include/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow mb-4">
            <div class="card-header py-3 <?= $isActive ? 'bg-warning text-dark' : 'bg-primary text-white' ?>">
                <h6 class="m-0 font-weight-bold">
                    <i class="bi bi-tools me-2"></i>Underhållsläge
                    <?php if ($isActive): ?>
                    <span class="badge bg-danger ms-2">AKTIVT</span>
                    <?php endif; ?>
                </h6>
            </div>
            <div class="card-body">

                <?php if ($isActive): ?>
                <!-- Aktivt läge: visa status + avaktivera-knapp -->
                <div class="alert alert-warning">
                    <h5 class="alert-heading">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>Underhållsläge är aktivt
                    </h5>
                    <p class="mb-2">Stimma är just nu otillgängligt för alla utom superadministratörer. Användare ser maintenance-sidan med HTTP 503.</p>
                    <hr>
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Aktiverat</dt>
                        <dd class="col-sm-9"><?= htmlspecialchars($mode['since'] ?? '–') ?></dd>

                        <dt class="col-sm-3">Av</dt>
                        <dd class="col-sm-9"><?= htmlspecialchars($mode['by_email'] ?? '–') ?></dd>

                        <?php if (!empty($mode['message'])): ?>
                        <dt class="col-sm-3">Meddelande</dt>
                        <dd class="col-sm-9"><em><?= nl2br(htmlspecialchars($mode['message'])) ?></em></dd>
                        <?php endif; ?>
                    </dl>
                </div>

                <form method="POST" action="maintenance.php" onsubmit="return confirm('Avaktivera underhållsläget och släppa in alla användare igen?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="disable">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-play-circle-fill me-2"></i>Avaktivera underhållsläge
                    </button>
                </form>

                <?php else: ?>
                <!-- Inaktivt läge: visa formulär för aktivering -->
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Vad händer när du aktiverar underhållsläget?</strong>
                    <ul class="mb-0 mt-2">
                        <li>Alla icke-superadministratörer omdirigeras till en underhållssida (HTTP 503).</li>
                        <li>Inloggade användare blir utlåsta direkt vid nästa sidladdning.</li>
                        <li>Superadministratörer kan fortsätta att navigera fritt för att underhålla systemet.</li>
                        <li>Inloggning, verifiering och utloggning är fortfarande möjliga så superadmin kan logga in.</li>
                    </ul>
                </div>

                <form method="POST" action="maintenance.php" onsubmit="return confirm('Aktivera underhållsläget? Alla icke-superadministratörer låses ute direkt.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="enable">

                    <div class="mb-3">
                        <label for="message" class="form-label">Meddelande till användare (valfritt)</label>
                        <textarea name="message" id="message" class="form-control" rows="4"
                                  placeholder="Exempel: Stimma uppdateras med en ny version. Vi är tillbaka klockan 14:00."></textarea>
                        <div class="form-text">Lämna tomt för att visa standardmeddelandet "Stimma uppdateras just nu. Vi är snart tillbaka."</div>
                    </div>

                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-tools me-2"></i>Aktivera underhållsläge
                    </button>
                </form>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<?php require_once 'include/footer.php'; ?>
