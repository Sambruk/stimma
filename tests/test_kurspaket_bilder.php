<?php
/**
 * Bilder i lektionstexten i kurspaket (2026-10-29).
 *
 * Visar att:
 *  - <img src="images/…" alt="…" class="…"> i en lektions content godkänns och läses in
 *  - bilden kopieras till upload/ och src skrivs om till redigerarens form ../upload/<namn>
 *  - lektionsbilden (image_file) är oberoende av bilderna i texten
 *  - bilden visas i lektionen
 *  - fel stoppar: saknad fil, saknad alt, extern src, okänd klass, bild i quizfråga, ren JSON
 */
require __DIR__ . '/lib.php';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/database.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/kurspaket.php';
reseed();

$dir = sys_get_temp_dir() . '/kp_test_' . bin2hex(random_bytes(4));
mkdir("$dir/images", 0777, true);
// 1×1 px PNG (testcontainern saknar GD)
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
file_put_contents("$dir/images/lektion.png", $png);
file_put_contents("$dir/images/skarmbild-1.png", $png);
$bilder = ['lektion.png' => "$dir/images/lektion.png", 'skarmbild-1.png' => "$dir/images/skarmbild-1.png"];

$paket = fn(string $content, array $fraga = []) => [
    'format' => 'stimma-kurspaket-1',
    'course' => ['title' => 'Kurs med skärmbilder', 'description' => 'En testkurs med en skärmbild i lektionstexten.'],
    'lessons' => [[
        'key' => 'l1', 'title' => 'Lektion med skärmbild', 'image_file' => 'lektion.png', 'content' => $content,
        'questions' => [$fraga ?: ['question_type' => 'true_false', 'question_text' => 'Sant?', 'quiz_data' => ['correct' => true]]],
    ]],
];
$img = '<img src="images/skarmbild-1.png" alt="Inloggningsrutan med fälten Användarnamn och Lösenord" class="img-lg img-center">';
$ok = $paket("<div class=\"lesson-intro\"><p>Intro.</p></div><!-- pagebreak --><h3>Logga in</h3><p>Så här ser rutan ut:</p><p>$img</p>");

section('Godkänt paket');
$v = kurspaketValidera($ok, $bilder);
check($v['fel'] === [], 'bild i lektionstexten godkänns', implode(' | ', $v['fel']));
check($v['varningar'] === [], 'ingen varning om oanvänd bild', implode(' | ', $v['varningar']));

section('Fel som stoppar');
$fel = fn(string $c, array $q = [], ?array $b = null) => implode(' | ', kurspaketValidera($paket('<p>Text.</p>' . $c, $q), $b ?? $bilder)['fel']);
check(str_contains($fel('<p><img src="images/finns-inte.png" alt="x"></p>'), 'finns inte i ZIP-filen'), 'saknad fil');
check(str_contains($fel('<p><img src="images/skarmbild-1.png"></p>'), 'saknar alt-text'), 'saknad alt-text');
check(str_contains($fel('<p><img src="images/skarmbild-1.png" alt=" "></p>'), 'saknar alt-text'), 'tom alt-text');
check(str_contains($fel('<p><img src="https://example.com/a.png" alt="x"></p>'), 'src ska vara images/'), 'extern bild');
check(str_contains($fel('<p><img src="images/skarmbild-1.png" alt="x" class="stor"></p>'), 'tillåtna:'), 'okänd klass');
check(str_contains($fel('<p><img src="images/skarmbild-1.png" alt="x" style="width:10px"></p>'), 'inte stöds: style'), 'style-attribut');
check(str_contains($fel('<p>Text</p>', ['question_type' => 'true_false', 'question_text' => 'Vad visar bilden? <img src="images/skarmbild-1.png" alt="x">', 'quiz_data' => ['correct' => true]]), 'bara att lägga in i lektionernas content'), 'bild i quizfråga');
check(str_contains(implode(' | ', kurspaketValidera($ok, null)['fel']), 'kräver att paketet är en ZIP-fil'), 'ren JSON-fil');
$utan = $paket('<p>Ingen bild.</p>');
check(str_contains(implode(' | ', kurspaketValidera($utan, $bilder)['varningar']), 'images/skarmbild-1.png används varken'), 'oanvänd bild ger varning');

section('Inläsning');
$a = dbOne("SELECT id, email FROM users WHERE email = 'admin-a@orga.test'");
$res = kurspaketLasIn($ok, ['id' => (int)$a['id'], 'email' => $a['email']], $bilder);
check($res['bilder'] === 2, 'två bilder kopierade (lektionsbild + skärmbild)', json_encode($res));
$l = dbOne("SELECT id, image_url, content FROM lessons WHERE id = ?", [$res['lektioner']['l1']]);
check(preg_match('#<img src="\.\./upload/(kp_[0-9a-f]{24}\.png)" alt="Inloggningsrutan[^"]*" class="img-lg img-center">#', $l['content'], $m) === 1, 'src omskriven till ../upload/kp_…', snippet($l['content'], 300));
$fil = $m[1] ?? '';
check($fil !== '' && is_file(__DIR__ . '/../public/upload/' . $fil), 'skärmbilden finns i upload/');
check($l['image_url'] !== $fil && str_starts_with((string)$l['image_url'], 'kp_'), 'lektionsbilden är en egen fil');

section('Visning');
dbExec("UPDATE courses SET status = 'active' WHERE id = ?", [$res['kurs_id']]);
$c = new Client('admin-a@orga.test');
$c->get('/lesson.php?id=' . $l['id'] . '&preview=1');
check($c->status === 200 && str_contains($c->body, 'src="upload/' . $fil . '"'), 'bilden visas i lektionen', $c->status . ' ' . snippet($c->body));

foreach ([$fil, $l['image_url']] as $f) @unlink(__DIR__ . '/../public/upload/' . $f);
array_map('unlink', glob("$dir/images/*")); rmdir("$dir/images"); rmdir($dir);
done();
