<?php
/**
 * Stimma — Underhållssida
 *
 * Visas när systemet är under uppdatering. Returnerar HTTP 503 med Retry-After
 * så sökmotorer och övervakning förstår att det är tillfälligt.
 *
 * Filen fungerar både som standalone underhållssida (direkt URL-anrop) och
 * som include från include/auth.php när underhållsläget är aktivt — då har
 * functions.php redan laddats så getMaintenanceMode() finns tillgänglig.
 */

http_response_code(503);
header('Retry-After: 600');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$currentDate = date('Y-m-d H:i');

// Hämta eventuellt custom-meddelande från flagg-filen
$maintenanceMessage = null;
$maintenanceSince = null;
if (function_exists('getMaintenanceMode')) {
    $modeData = getMaintenanceMode();
    if ($modeData) {
        $maintenanceMessage = $modeData['message'] ?? null;
        $maintenanceSince = $modeData['since'] ?? null;
    }
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Underhåll pågår – Stimma</title>
    <link rel="icon" href="favicon.ico">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #0F3B5F 0%, #1a5a8a 100%);
            color: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 16px;
            padding: 48px 40px;
            max-width: 560px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        .icon {
            font-size: 72px;
            margin-bottom: 16px;
            display: inline-block;
            animation: spin 4s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
        }
        h1 {
            font-size: 28px;
            margin: 0 0 12px 0;
            font-weight: 600;
        }
        p {
            font-size: 16px;
            line-height: 1.6;
            margin: 0 0 16px 0;
            opacity: 0.92;
        }
        .meta {
            margin-top: 32px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.15);
            font-size: 13px;
            opacity: 0.7;
        }
        .meta strong {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="card" role="alert" aria-live="polite">
        <div class="icon" aria-hidden="true">⚙️</div>
        <h1>Underhåll pågår</h1>
        <?php if (!empty($maintenanceMessage)): ?>
        <p><?= nl2br(htmlspecialchars($maintenanceMessage)) ?></p>
        <?php else: ?>
        <p>Stimma uppdateras just nu. Vi är snart tillbaka.</p>
        <p>Tack för ditt tålamod.</p>
        <?php endif; ?>
        <div class="meta">
            <strong>Stimma</strong> · Sambruk<br>
            <?php if ($maintenanceSince): ?>
            Underhåll inleddes <?= htmlspecialchars($maintenanceSince) ?>
            <?php else: ?>
            <?= htmlspecialchars($currentDate) ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
