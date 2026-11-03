<?php
/**
 * Falsk OpenAI-kompatibel inferensserver för testerna (2026-11-03).
 * Startas med php -S 127.0.0.1:2626 tests/ai_stub.php (se aiStubStart() i lib.php).
 * Sparar varje anrop i /tmp/ai-stub/ och svarar som Berget AI:s chat/completions.
 */
@mkdir('/tmp/ai-stub', 0777, true);
$body = file_get_contents('php://input');
file_put_contents(sprintf('/tmp/ai-stub/%s.json', microtime(true)), json_encode([
    'path' => $_SERVER['REQUEST_URI'],
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    'body' => json_decode($body, true),
]));
header('Content-Type: application/json');
echo json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Svar från testtutorn']]],
    'usage' => ['prompt_tokens' => 1000000, 'completion_tokens' => 2000000, 'total_tokens' => 3000000],
]);
