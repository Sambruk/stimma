<?php
/**
 * Stimma — lektionsvyn C2 (kortformat). Underlag: docs/stimma-c2.zip (SPEC.md).
 *
 * Inkluderas från public/lesson.php EFTER all logik (behörighet, inskrivning och
 * POST-hanterare), när lessonViewFor() säger 'c2'. Mallen ändrar bara
 * presentationen: quizsvar, infosidor och AI-chatt går till samma ändpunkter som
 * den klassiska vyn, så framstegen sparas exakt som förut.
 *
 * Tillgängliga variabler från lesson.php: $lesson, $lessonId, $userId,
 * $isPreviewMode, $isCompleted, $systemName.
 */

require_once __DIR__ . '/lesson_c2_content.php';
require_once __DIR__ . '/quiz.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$courseId = (int)$lesson['course_id'];
$arInfo = ($lesson['lesson_type'] ?? 'lesson') === 'info_page';

// ------------------------------------------------------------ kursens lektioner

$c2Alla = query(
    "SELECT id, title, sort_order, lesson_type FROM " . DB_DATABASE . ".lessons
     WHERE course_id = ? AND status = 'active' ORDER BY sort_order ASC, id ASC",
    [$courseId]
) ?: [];
$c2Klara = [];
foreach (query(
    "SELECT p.lesson_id FROM " . DB_DATABASE . ".progress p JOIN " . DB_DATABASE . ".lessons l ON l.id = p.lesson_id
     WHERE p.user_id = ? AND l.course_id = ? AND p.status = 'completed'",
    [$userId, $courseId]
) ?: [] as $r) $c2Klara[(int)$r['lesson_id']] = true;

$c2Riktiga = array_values(array_filter($c2Alla, fn($l) => ($l['lesson_type'] ?? 'lesson') === 'lesson'));
$c2AntalLektioner = count($c2Riktiga);
$c2LektionsNr = 0;
foreach ($c2Riktiga as $i => $l) if ((int)$l['id'] === $lessonId) $c2LektionsNr = $i + 1;
$c2AntalKlara = count(array_filter($c2Riktiga, fn($l) => isset($c2Klara[(int)$l['id']])));

// Nästa aktiva sida i kursen (lektion eller infosida), samma som POST-hanterarna använder
$c2Nasta = null;
foreach ($c2Alla as $l) {
    if ((int)$l['sort_order'] > (int)$lesson['sort_order']) { $c2Nasta = $l; break; }
}
$c2NastaTillganglig = $c2Nasta ? isLessonAvailableForUser((int)$userId, (int)$c2Nasta['id'], $courseId) : false;

$c2Anvandare = queryOne("SELECT name, email, is_admin, is_editor, access_mode FROM " . DB_DATABASE . ".users WHERE id = ?", [$userId]) ?: [];
$c2Fornamn = trim(explode(' ', trim((string)($c2Anvandare['name'] ?? '')))[0] ?? '');

// ------------------------------------------------------------ sidorna

$c2YouTube = function (string $url): string {
    if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
        return 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0&modestbranding=1';
    }
    return '';
};
$c2Spelare = '';
$c2VideoKalla = '';
if (!empty($lesson['video_url'])) {
    if (($lesson['video_type'] ?? '') === 'local') {
        $fil = basename($lesson['video_url']);
        $typ = strtolower(pathinfo($fil, PATHINFO_EXTENSION)) === 'webm' ? 'video/webm' : 'video/mp4';
        $c2Spelare = '<div class="sl-video"><video controls preload="metadata"><source src="upload/videos/' . $h($fil) . '" type="' . $typ . '">Din webbläsare kan inte spela upp videon.</video></div>';
        $c2VideoKalla = 'Videofil';
    } elseif ($embed = $c2YouTube($lesson['video_url'])) {
        $c2Spelare = '<div class="sl-video"><iframe src="' . $h($embed) . '" title="Video: ' . $h($lesson['title']) . '" allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe></div>';
        $c2VideoKalla = 'YouTube';
    }
}

$c2Ljud = '';
if (!empty($lesson['audio_url'])) {
    $fil = basename($lesson['audio_url']);
    $mime = ['mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4'][strtolower(pathinfo($fil, PATHINFO_EXTENSION))] ?? 'audio/mpeg';
    $c2Ljud = '<div class="sl-audio"><p class="sl-audio-label">Lyssna på lektionen</p><audio controls preload="metadata"><source src="upload/audio/' . $h($fil) . '" type="' . $mime . '">Din webbläsare kan inte spela upp ljud.</audio></div>';
}

$c2Forslag = [];
$c2HarAi = !empty($lesson['ai_chat_enabled']) && (!empty($lesson['ai_instruction']) || !empty($lesson['ai_prompt']));
// Förslagen till AI-läraren flyttas från texten till AI-kortet — men bara när
// AI-läraren finns. Annars står texten kvar som den var.
$c2Bearbeta = function (string $raHtml) use (&$c2Forslag, $c2HarAi): string {
    $html = c2Normalisera(cleanHtml($raHtml));
    if ($c2HarAi) {
        [$html, $f] = c2AiForslag($html);
        $c2Forslag = array_merge($c2Forslag, $f);
    }
    return c2Begreppslistor($html);
};

$c2Ra = (string)($lesson['content'] ?? '');
$c2HarMarkor = (bool)preg_match('/\[video\]/i', $c2Ra);
$c2Textsidor = [];
foreach (preg_split('/<!--\s*pagebreak\s*-->/i', $c2Ra) as $i => $bit) {
    $delar = $c2HarMarkor ? preg_split('/(?:<p>\s*)?\[video\](?:\s*<\/p>)?/i', $bit, 2) : [$bit];
    $html = $c2Bearbeta($delar[0]);
    $harVideo = false;
    if (count($delar) === 2) {
        $harVideo = $c2Spelare !== '';
        $html .= $harVideo ? $c2Spelare : '';
        $html .= $c2Bearbeta($delar[1]);
    }
    $rubrik = null;
    if (count($c2Textsidor) > 0) [$rubrik, $html] = c2LyftRubrik($html);
    if (trim(strip_tags($html)) === '' && !$harVideo) continue;
    $c2Textsidor[] = ['typ' => 'text', 'rubrik' => $rubrik ?? $lesson['title'], 'html' => $html, 'video' => $harVideo];
}
$c2Forslag = array_slice($c2Forslag, 0, 3);
if ($c2Ljud) {
    if ($c2Textsidor) $c2Textsidor[0]['html'] .= $c2Ljud;
    else $c2Textsidor[] = ['typ' => 'text', 'rubrik' => $lesson['title'], 'html' => $c2Ljud, 'video' => false];
}

$c2Sidor = [];
$c2VideoSida = ($c2Spelare && !$c2HarMarkor) ? ['typ' => 'video', 'rubrik' => $lesson['title'], 'html' => $c2Spelare] : null;
if ($c2VideoSida && ($lesson['video_position'] ?? 'bottom') === 'top') $c2Sidor[] = $c2VideoSida;
foreach ($c2Textsidor as $s) $c2Sidor[] = $s;
if ($c2VideoSida && ($lesson['video_position'] ?? 'bottom') !== 'top') $c2Sidor[] = $c2VideoSida;
if ($c2HarAi) $c2Sidor[] = ['typ' => 'ai', 'rubrik' => 'Fråga AI-läraren'];
$c2Fragor = $arInfo ? [] : getQuizQuestionsForLesson($lessonId);
foreach ($c2Fragor as $i => $q) $c2Sidor[] = ['typ' => 'quiz', 'fraga' => $q, 'nr' => $i + 1];
if (!$c2Sidor) $c2Sidor[] = ['typ' => 'text', 'rubrik' => $lesson['title'], 'html' => '<p>Den här lektionen har inget innehåll ännu.</p>', 'video' => false];
$c2Antal = count($c2Sidor);
// Samma kortbredd genom hela lektionen: finns det någon bred sida (bild, video,
// AI-läraren) blir alla kort breda, annars smala. Annars hoppar kortet i storlek.
$c2Bred = (bool)array_filter($c2Sidor, fn($s) => in_array($s['typ'], ['video', 'ai'], true)
    || ($s['typ'] === 'text' && (!empty($s['video']) || !empty($lesson['image_url']))));

/** Quizfråga: enkelval, flerval och sant/falskt ritas som hela klickbara rader. */
$c2Svarsrader = function (array $q) use ($h): string {
    $pre = 'q' . (int)$q['id'] . '_';
    $d = $q['data'];
    $flera = $q['question_type'] === 'multiple_choice';
    $alternativ = $q['question_type'] === 'true_false'
        ? [1 => 'Sant', 0 => 'Falskt']
        : array_values($d['answers'] ?? []);
    $ut = '<fieldset class="sl-choices"><legend class="sl-visually-hidden">Svarsalternativ</legend>';
    if ($flera) $ut .= '<p class="sl-hint">Välj ett eller flera svar</p>';
    foreach ($alternativ as $varde => $text) {
        $ut .= '<label class="sl-choice"><input type="' . ($flera ? 'checkbox' : 'radio') . '" name="' . $pre . 'answer' . ($flera ? '[]' : '')
             . '" value="' . (int)$varde . '"><span class="sl-choice-text">' . $h($text) . '</span>'
             . '<span class="sl-choice-status" aria-hidden="true"></span></label>';
    }
    return $ut . '</fieldset>';
};

/** Övriga frågetyper: befintliga verktyg från quiz.php, utan rubrik, frågetext och egen svarsknapp. */
$c2Verktyg = function (array $q, int $nr): string {
    [$dom, $rot] = c2Dom(renderQuizQuestion($q, $nr));
    $xp = new DOMXPath($dom);
    foreach (['.//div[contains(@class,"justify-content-between")][h5]', './/div[contains(@class,"question-text")]',
              './/div[button[contains(@class,"quiz-answer-btn")]]'] as $sokvag) {
        foreach (iterator_to_array($xp->query($sokvag, $rot)) as $n) $n->parentNode->removeChild($n);
    }
    if ($q['question_type'] !== 'hotspot' && $q['question_type'] !== 'image_choice') {
        foreach (iterator_to_array($xp->query('.//img', $rot)) as $n) {
            if ($n->parentNode instanceof DOMElement && $n->parentNode->getAttribute('class') === 'mb-3') $n->parentNode->parentNode->removeChild($n->parentNode);
        }
    }
    return '<div class="sl-quiz-widget">' . c2Html($dom, $rot) . '</div>';
};

$c2Segment = function (int $index) use ($c2Antal): string {
    $ut = '<ol class="sl-segments" aria-label="Sida ' . ($index + 1) . ' av ' . $c2Antal . '">';
    for ($i = 0; $i < $c2Antal; $i++) {
        $ut .= '<li class="' . ($i <= $index ? 'is-done' : '') . '"' . ($i === $index ? ' aria-current="step"' : '') . '></li>';
    }
    return $ut . '</ol>';
};

$c2Knapptext = function (int $i) use ($c2Sidor, $c2Antal, $arInfo, $isCompleted, $isPreviewMode): array {
    $nasta = $c2Sidor[$i + 1]['typ'] ?? null;
    if ($c2Sidor[$i]['typ'] === 'quiz') return ['Svara', 'answer'];
    if ($nasta === 'quiz') return ['Fortsätt till quiz', 'next'];
    if ($i < $c2Antal - 1) return ['Fortsätt', 'next'];
    if ($arInfo) return ['Fortsätt', 'info-complete'];
    if ($isCompleted || $isPreviewMode) return ['Fortsätt', 'done'];
    return ['Tillbaka till kursen', 'close'];
};

$c2Bild = !empty($lesson['image_url']) ? 'upload/' . basename($lesson['image_url']) : '';
$c2KursUrl = 'course.php?id=' . $courseId;
$c2Csrf = generateCsrfToken();
$c2Etikett = $arInfo ? $lesson['title'] : ('Lektion ' . $c2LektionsNr . ' av ' . $c2AntalLektioner . ': ' . $lesson['title']);
$c2KortEtikett = $arInfo ? 'Information' : ('Lektion ' . $c2LektionsNr);
$c2ArRedaktor = !empty($c2Anvandare['is_admin']) || !empty($c2Anvandare['is_editor']);

$c2Nav = function (int $i) use ($c2Knapptext, $c2KursUrl, $h): string {
    [$text, $handling] = $c2Knapptext($i);
    $ut = '<div class="sl-nav">';
    $ut .= '<button type="button" class="sl-btn-back" data-sl-prev aria-label="Föregående"' . ($i === 0 ? ' hidden' : '') . '>'
         . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></button>';
    if ($handling === 'close') {
        $ut .= '<a class="sl-btn-primary" href="' . $h($c2KursUrl) . '">' . $h($text) . '</a>';
    } else {
        $ut .= '<button type="button" class="sl-btn-primary" data-sl-primary data-action="' . $handling . '">'
             . '<span data-sl-label>' . $h($text) . '</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></button>';
    }
    return $ut . '</div>';
};
?>
<!DOCTYPE html>
<html lang="sv" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $h($systemName . ' – ' . $lesson['course_title'] . ' – ' . $lesson['title']) ?></title>
    <link rel="icon" href="favicon.ico">
    <link href="fonts/fonts.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="include/css/stimma-lesson-c2.css?v=20261012a" rel="stylesheet">
</head>
<body class="stimma-lesson">

<?php if (isImpersonating()): ?>
<div class="sl-notice sl-notice--danger" role="alert">
    <span><strong>Du visar som <?= $h($_SESSION['user_email']) ?></strong> — allt du ser här är vad användaren ser.</span>
    <form method="post" action="admin/stop_impersonate.php">
        <input type="hidden" name="csrf_token" value="<?= $h($c2Csrf) ?>">
        <button type="submit" class="sl-notice-btn">Sluta visa som</button>
    </form>
</div>
<?php endif; ?>

<?php if (!userHasPubAgreement($_SESSION['user_email']) && ($c2Anvandare['access_mode'] ?? 'domain') !== 'public_only'): ?>
<div class="sl-notice" role="note">
    <span>Er organisation har inte tecknat ett PUB-avtal med Sambruk ännu.
    <?= !empty($c2Anvandare['is_admin']) ? 'Stimma får därför bara användas för att skapa och testa utbildningar.' : 'Kontakta er organisations administratör.' ?></span>
    <?php if (!empty($c2Anvandare['is_admin'])): ?><a href="pub_agreement.php">Teckna PUB-avtal</a><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isPreviewMode): ?>
<div class="sl-notice sl-notice--preview" id="slPreview" role="note">
    <span><strong>Förhandsgranskning</strong> – dina framsteg sparas inte</span>
    <?php if ($c2ArRedaktor): ?><a href="admin/edit_lesson.php?id=<?= (int)$lessonId ?>">Redigera lektion</a><?php endif; ?>
    <button type="button" class="sl-notice-close" aria-label="Dölj förhandsgranskningsraden" data-sl-dismiss="slPreview">×</button>
</div>
<?php endif; ?>

<header class="sl-topbar">
    <?php $c2OrgLogga = getHeaderOrganizationIcon($userId); ?>
    <div class="sl-topbar-left">
    <a class="sl-logo" href="index.php">
        <?php if ($c2OrgLogga): ?>
        <img src="upload/org_icons/<?= $h(basename($c2OrgLogga['url'])) ?>" alt="<?= $h($c2OrgLogga['name']) ?> — till översikten">
        <?php else: ?>
        <img src="images/stimma-logo-transparent.png" alt="Stimma — till översikten">
        <?php endif; ?>
    </a>
        <button type="button" class="sl-topbtn" data-sl-toc aria-controls="slSide" aria-expanded="false">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M5 12h14M5 17h9"/></svg><span>Innehåll</span>
        </button>
    </div>
    <p class="sl-where">
        <span class="sl-course"><?= $h($lesson['course_title']) ?></span>
        <span class="sl-sep" aria-hidden="true">·</span>
        <span class="sl-lesson sl-wide"><?= $h($c2Etikett) ?></span>
        <span class="sl-lesson sl-narrow"><?= $h($c2KortEtikett) ?> · <span data-sl-counter>1/<?= $c2Antal ?></span></span>
    </p>
    <div class="sl-topbar-actions">
        <a class="sl-topbtn" href="<?= $h($c2KursUrl) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg><span>Stäng</span>
        </a>
    </div>
</header>

<div class="sl-stage" id="slStage">
<aside class="sl-side" id="slSide" aria-labelledby="slTocRubrik" hidden>
<div class="sl-side-panel">
    <div class="sl-toc-head">
        <h2 id="slTocRubrik">Innehåll</h2>
        <button type="button" class="sl-topbtn" data-sl-toc-close aria-label="Dölj innehållsförteckningen">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </div>
    <ol class="sl-toc-list">
        <?php $nr = 0; foreach ($c2Alla as $l):
            $info = ($l['lesson_type'] ?? 'lesson') === 'info_page';
            if (!$info) $nr++;
            $aktuell = (int)$l['id'] === $lessonId;
            $klar = isset($c2Klara[(int)$l['id']]);
            $tillg = $aktuell || (isLessonAvailableForUser((int)$userId, (int)$l['id'], $courseId)
                && ($isPreviewMode || !lessonOrderBlocker($userId, (int)$l['id'], $courseId)));
            $status = $aktuell ? 'Pågår' : ($klar ? 'Klar' : ($tillg ? '' : 'Låst'));
        ?>
        <li class="<?= $aktuell ? 'is-current' : '' ?><?= $klar ? ' is-done' : '' ?><?= !$tillg ? ' is-locked' : '' ?>">
            <?php if ($tillg && !$aktuell): ?>
            <a href="lesson.php?id=<?= (int)$l['id'] ?><?= $isPreviewMode ? '&amp;preview=1' : '' ?>">
            <?php else: ?><span<?= $aktuell ? ' aria-current="page"' : '' ?>><?php endif; ?>
                <span class="sl-toc-nr" aria-hidden="true"><?= $info ? 'i' : $nr ?></span>
                <span class="sl-toc-title"><?= $info ? '' : '<span class="sl-visually-hidden">Lektion ' . $nr . ': </span>' ?><?= $h($l['title']) ?></span>
                <?php if ($status): ?><span class="sl-toc-status"><?= $status ?></span><?php endif; ?>
            <?= ($tillg && !$aktuell) ? '</a>' : '</span>' ?>
        </li>
        <?php endforeach; ?>
    </ol>
</div>
</aside>
<div class="sl-backdrop" data-sl-backdrop hidden></div>

<main class="sl-main <?= $c2Bred ? 'sl-lesson--wide' : 'sl-lesson--narrow' ?>" id="slMain"
      data-lesson-id="<?= (int)$lessonId ?>"
      data-csrf="<?= $h($c2Csrf) ?>"
      data-preview="<?= $isPreviewMode ? '1' : '0' ?>"
      data-completed="<?= $isCompleted ? '1' : '0' ?>"
      data-info="<?= $arInfo ? '1' : '0' ?>"
      data-ai-prompt="<?= $h(cleanHtml($lesson['ai_prompt'] ?? '')) ?>">

<?php if (isset($_SESSION['flash_message'])): ?>
    <div class="sl-flash sl-flash--<?= $h($_SESSION['flash_type'] ?? 'info') ?>" role="status"><?= $h($_SESSION['flash_message']) ?></div>
    <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
<?php endif; ?>

<?php foreach ($c2Sidor as $i => $s):
    $typ = $s['typ'];
    $medBild = $typ === 'text' && $c2Bild && empty($s['video']);
    $klass = 'sl-card sl-page sl-card--' . $typ . ($medBild ? ' sl-card--image' : '') . (($typ === 'text' && !$medBild && empty($s['video'])) ? ' sl-card--narrow' : '');
?>
<section class="<?= $klass ?>" data-sl-page="<?= $i ?>" data-kind="<?= $typ ?>"<?= $i === 0 ? '' : ' hidden' ?>
         aria-labelledby="slRubrik<?= $i ?>">
<?php if ($medBild): ?>
    <div class="sl-media"><img src="<?= $h($c2Bild) ?>" alt="<?= $h($lesson['title']) ?>"></div>
<?php endif; ?>

<?php if ($typ === 'ai'): ?>
    <div class="sl-ai-side">
        <?= $c2Segment($i) ?>
        <p class="sl-meta">Fördjupning · <?= $i + 1 ?> / <?= $c2Antal ?></p>
        <h1 class="sl-title" id="slRubrik<?= $i ?>" tabindex="-1">Fråga AI-läraren</h1>
        <?php if (trim(strip_tags($lesson['ai_instruction'] ?? '')) !== ''): ?>
        <div class="sl-ai-intro"><?= cleanHtml($lesson['ai_instruction']) ?></div>
        <?php endif; ?>
        <?php if ($c2Forslag): ?>
        <p class="sl-ai-suggest-label">Förslag att börja med</p>
        <ul class="sl-ai-suggestions">
            <?php foreach ($c2Forslag as $f): ?>
            <li><button type="button" class="sl-suggestion" data-sl-suggest><?= $h($f) ?></button></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?= $c2Nav($i) ?>
    </div>
    <div class="sl-ai-chat">
        <div class="sl-chat-log" data-sl-chatlog aria-live="polite" aria-label="Samtal med AI-läraren"></div>
        <form class="sl-chat-form" data-sl-chatform>
            <label for="slChatInput" class="sl-visually-hidden">Skriv din fråga till AI-läraren</label>
            <textarea id="slChatInput" rows="1" placeholder="Skriv din fråga…" required></textarea>
            <button type="submit" class="sl-chat-send" aria-label="Skicka frågan">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
        </form>
    </div>

<?php elseif ($typ === 'quiz'):
    $q = $s['fraga'];
    $text = trim(strip_tags($q['question_text'] ?? ''));
    $enkelRubrik = $text !== '' && mb_strlen($text) <= 220 && !preg_match('/<(img|ul|ol|table|p\b[^>]*>.*<p)/is', $q['question_text'] ?? '');
?>
    <div class="sl-body">
        <?= $c2Segment($i) ?>
        <p class="sl-meta">Kunskapskoll · Fråga <?= $s['nr'] ?> av <?= count($c2Fragor) ?></p>
        <div class="sl-question" data-sl-question="<?= (int)$q['id'] ?>" data-type="<?= $h($q['question_type']) ?>">
            <h1 class="sl-title" id="slRubrik<?= $i ?>" tabindex="-1"><?= $enkelRubrik ? $h($text) : 'Fråga ' . $s['nr'] ?></h1>
            <?php if (!$enkelRubrik && $text !== ''): ?><div class="sl-question-text"><?= cleanHtml($q['question_text']) ?></div><?php endif; ?>
            <?php if (!empty($q['question_image']) && !in_array($q['question_type'], ['hotspot', 'image_choice'], true)): ?>
            <img class="sl-question-img" src="upload/<?= $h(basename($q['question_image'])) ?>" alt="">
            <?php endif; ?>
            <?= in_array($q['question_type'], ['single_choice', 'multiple_choice', 'true_false'], true) ? $c2Svarsrader($q) : $c2Verktyg($q, $s['nr']) ?>
            <?php $forklaring = $q['data']['explanation'] ?? ($q['data']['forklaring'] ?? ''); ?>
            <div class="sl-feedback" data-sl-feedback role="status" aria-live="polite"></div>
            <?php if (is_string($forklaring) && trim($forklaring) !== ''): ?>
            <div class="sl-explanation" data-sl-explanation hidden><?= $h($forklaring) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($isCompleted || $isPreviewMode): ?>
        <p class="sl-skip"><button type="button" class="sl-linkbtn" data-sl-skip>Hoppa över frågan<?= $isPreviewMode ? ' (bara i förhandsgranskning)' : '' ?></button></p>
        <?php endif; ?>
        <?= $c2Nav($i) ?>
    </div>

<?php else: /* text och video */ ?>
    <div class="sl-body">
        <?= $c2Segment($i) ?>
        <p class="sl-meta"><?= $typ === 'video' ? 'Video · ' . $h($c2VideoKalla) . ' · ' : '' ?><?= $i + 1 ?> / <?= $c2Antal ?></p>
        <h1 class="sl-title" id="slRubrik<?= $i ?>" tabindex="-1"><?= $h($s['rubrik']) ?></h1>
        <div class="sl-content"><?= $s['html'] ?></div>
        <?= $c2Nav($i) ?>
    </div>
<?php endif; ?>
</section>
<?php endforeach; ?>

<?php if (!$arInfo): ?>
<section class="sl-card sl-card--done" data-sl-done hidden aria-labelledby="slKlarRubrik">
    <div class="sl-done-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
    <p class="sl-meta" data-sl-done-meta>Lektion <?= $c2LektionsNr ?> av <?= $c2AntalLektioner ?> klar</p>
    <h1 class="sl-title" id="slKlarRubrik" tabindex="-1"><?= $c2Fornamn !== '' ? 'Bra jobbat, ' . $h($c2Fornamn) . '!' : 'Bra jobbat!' ?></h1>
    <p class="sl-done-lead">Du har gått igenom ”<?= $h($lesson['title']) ?>”.</p>
    <div class="sl-course-progress">
        <div class="sl-course-progress-head"><span>Kursen</span><span data-sl-done-count></span></div>
        <div class="sl-bar" role="progressbar" aria-label="Kursens framsteg" aria-valuemin="0" aria-valuemax="<?= $c2AntalLektioner ?>" data-sl-done-bar><span></span></div>
    </div>
    <div class="sl-next" data-sl-done-next hidden>
        <p class="sl-next-label" data-sl-done-next-label>Nästa lektion</p>
        <p class="sl-next-title" data-sl-done-next-title></p>
    </div>
    <p class="sl-done-note" data-sl-done-note hidden></p>
    <div class="sl-done-actions">
        <a class="sl-btn-secondary" href="<?= $h($c2KursUrl) ?>">Tillbaka till kursen</a>
        <a class="sl-btn-primary" data-sl-done-go hidden href="#"><span data-sl-done-go-label>Starta nästa</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></a>
    </div>
</section>
<?php endif; ?>

<p class="sl-keyhint" aria-hidden="true">Bläddra med piltangenterna ← →</p>
</main>
</div>


<script>
window.STIMMA_LESSON = <?= json_encode([
    'lessonId' => (int)$lessonId,
    'courseUrl' => $c2KursUrl,
    'totalLessons' => $c2AntalLektioner,
    'doneLessons' => $c2AntalKlara,
    'thisDoneBefore' => (bool)$isCompleted,
    'next' => $c2Nasta ? [
        'id' => (int)$c2Nasta['id'],
        'title' => $c2Nasta['title'],
        'info' => ($c2Nasta['lesson_type'] ?? 'lesson') === 'info_page',
        'available' => (bool)$c2NastaTillganglig,
    ] : null,
    'preview' => (bool)$isPreviewMode,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="include/js/quiz-client.js?v=20261001c" defer></script>
<script src="include/js/lesson-c2.js?v=20261012a" defer></script>
</body>
</html>
