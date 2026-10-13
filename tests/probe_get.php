<?php
require __DIR__ . '/lib.php';
reseed();
$pages = array_merge(glob('/var/www/html/public/admin/*.php'), glob('/var/www/html/public/admin/ajax/*.php'));
$params = ['id=200', 'course_id=200', 'lesson_id=2001', 'id=2001', 'question_id=20011', 'lesson_id=2001&course_id=200'];
foreach (['admin-a@orga.test', 'red-a@orga.test', 'las-a@orga.test'] as $e) {
  $c = new Client($e);
  echo "\n== $e\n";
  foreach ($pages as $f) {
    $rel = substr($f, strlen('/var/www/html/public'));
    if (preg_match('/(delete|remove|logout|run_cron|trigger|send_|sync_users|regenerate|toggle|import|generate|plan_course|ai_generate|dismiss|set_viewer)/', $rel)) continue; // inga sidoeffekter i GET-provet
    $hits = [];
    foreach ($params as $p) {
      $c->get("$rel?$p");
      if (preg_match('/B1 Kurs i org B|Lektion \d i kurs 20[012]|B3 Kopia|B2 Publik/', $c->body, $m)) $hits[] = "{$p}→{$c->status}:" . $m[0];
    }
    if ($hits) echo "  LÄCKA $rel  " . implode(' | ', array_unique($hits)) . "\n";
  }
}
