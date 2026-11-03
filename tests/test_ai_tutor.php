<?php
/**
 * AI-tutorn via egen inferensleverantör, Berget AI (2026-11-03).
 *
 * Visar att:
 *  - med AI_TUTOR_API_KEY satt går tutorns anrop till AI_TUTOR_SERVER med Bearer-nyckeln
 *    och AI_TUTOR_MODEL – oavsett modellvalet för chat i superadmins vy
 *  - användningen loggas med Berget-modellen och priset från migration 055
 *  - andra anrop (anslutningstestet utan feature) går inte till tutorleverantören
 *  - utan AI_TUTOR_API_KEY används AI_* som förut
 *  - superadmins modellvy visar att tutorn går via Berget
 */
require __DIR__ . '/lib.php';
reseed();
db()->exec(file_get_contents(__DIR__ . '/../migrations/055_ai_pricing_berget.sql'));
dbExec("UPDATE lessons SET ai_chat_enabled = 1, ai_prompt = 'Svara om lektionen.' WHERE id = 1001");
dbExec("DELETE FROM ai_feature_models WHERE feature = 'chat'");
dbExec("INSERT INTO ai_feature_models (feature, model) VALUES ('chat', 'gpt-4o')");
dbExec("DELETE FROM ai_usage_log");
aiStubStart();

section('Tutorn anropar Berget');
$u = new Client('anv-a@orga.test');
$u->request('POST', '/ai_chat.php', json_encode(['lesson_id' => 1001, 'message' => 'Vad handlar lektionen om?']));
$svar = $u->json();
check(isset($svar['response']) && strpos($svar['response'], 'Svar från testtutorn') !== false, 'svaret kommer från tutorleverantören', $u->status . ' ' . snippet($u->body, 200));
$anrop = aiStubRequests();
check(count($anrop) === 1, 'ett anrop till leverantören', (string)count($anrop));
$a = $anrop[0] ?? [];
check(($a['path'] ?? '') === '/v1/chat/completions', 'rätt sökväg (chat/completions)', $a['path'] ?? '');
check(($a['authorization'] ?? '') === 'Bearer sk_ber_testnyckel', 'AI_TUTOR_API_KEY skickas som Bearer');
check(($a['body']['model'] ?? '') === 'mistralai/Mistral-Small-3.2-24B-Instruct-2506', 'AI_TUTOR_MODEL används, inte modellvalet gpt-4o', $a['body']['model'] ?? '');
check(($a['body']['messages'][0]['role'] ?? '') === 'system' && strpos($a['body']['messages'][0]['content'] ?? '', 'Lektion 1 i kurs 100') !== false, 'systemprompten med lektionen följer med');
check(($a['body']['messages'][1]['content'] ?? '') === 'Vad handlar lektionen om?', 'användarens fråga följer med');

$logg = dbOne("SELECT * FROM ai_usage_log WHERE feature = 'chat' ORDER BY id DESC LIMIT 1");
check($logg && $logg['model'] === 'mistralai/Mistral-Small-3.2-24B-Instruct-2506' && $logg['status'] === 'ok' && (int)$logg['course_id'] === 100, 'användningen loggas med Berget-modellen', json_encode($logg));
check($logg && (int)$logg['cost_cents'] === 105, 'kostnaden räknas med Berget-priset (1M in à 35 + 2M ut à 35 = 105 cent)', (string)($logg['cost_cents'] ?? ''));

section('Andra anrop går inte till tutorleverantören');
aiStubStart();
$ut = shell_exec('cd /var/www/html/public && php -r ' . escapeshellarg('
    $_SERVER["HTTP_HOST"] = "stimma.test"; $_SESSION = [];
    require "include/config.php"; require "include/database.php"; require "include/functions.php";
    try { sendOpenAIRequest([["role" => "user", "content" => "test"]]); echo "svar"; } catch (Exception $e) { echo "fel: ", $e->getMessage(); }
    echo "|";
    putenv("AI_TUTOR_API_KEY=");
    echo getAiTutorProvider() === null ? "ingen tutorleverantör" : "TUTORLEVERANTÖR";
') . ' 2>&1');
check(count(aiStubRequests()) === 0 && strpos((string)$ut, 'API-nyckel saknas') !== false, 'anslutningstestet (utan feature) använder AI_*', (string)$ut);
check(strpos((string)$ut, '|ingen tutorleverantör') !== false, 'utan AI_TUTOR_API_KEY: som förut', (string)$ut);

section('Superadmins modellvy');
$s = new Client('super@sambruk.se');
$s->get('/admin/ai_quotas.php');
check(strpos($s->body, 'AI-tutorn går via') !== false && strpos($s->body, '127.0.0.1') !== false && strpos($s->body, 'mistralai/Mistral-Small-3.2-24B-Instruct-2506') !== false, 'visar leverantör och modell', snippet($s->body, 200));

done();
