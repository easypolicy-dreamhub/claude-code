<?php
// LOCAL TESTING ONLY. A fake Gemini generateContent API that records every request.
$log = getenv('MOCK_LOG');
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$hdr = ['x-goog-api-key' => $_SERVER['HTTP_X_GOOG_API_KEY'] ?? ''];
file_put_contents($log, json_encode(['path' => strtok($_SERVER['REQUEST_URI'], '?'), 'query' => $_SERVER['QUERY_STRING'] ?? '', 'headers' => $hdr, 'body' => $body], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && str_ends_with(strtok($_SERVER['REQUEST_URI'], '?'), '/v1beta/models')) {
    if (!str_starts_with($hdr['x-goog-api-key'], 'test-key-123')) { http_response_code(403); exit(json_encode(['error' => ['code' => 403, 'message' => 'bad key', 'status' => 'PERMISSION_DENIED']])); }
    if (($_GET['pageToken'] ?? '') === '') {
        exit(json_encode(['models' => [
            ['name' => 'models/gemini-3.8-flash', 'displayName' => 'Gemini 3.8 Flash', 'description' => 'Fast model.', 'supportedGenerationMethods' => ['generateContent', 'countTokens']],
            ['name' => 'models/text-embedding-9', 'displayName' => 'Embedding', 'description' => 'Not a chat model.', 'supportedGenerationMethods' => ['embedContent']],
            ['name' => 'models/antigravity-preview-latest', 'displayName' => 'Antigravity', 'description' => 'An agent.', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/gemini-3.5-pro', 'displayName' => 'Gemini 3.5 Pro', 'description' => 'Strong.', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/gemini-3.8-flash-live', 'displayName' => 'Flash Live', 'description' => 'Voice.', 'supportedGenerationMethods' => ['generateContent']],
        ], 'nextPageToken' => 'page2']));
    }
    exit(json_encode(['models' => [['name' => 'models/gemini-flash-lite-latest', 'displayName' => 'Flash-Lite latest', 'description' => 'Alias.', 'supportedGenerationMethods' => ['generateContent']]]]));
}
if (!str_starts_with($hdr['x-goog-api-key'], 'test-key-123')) {
    http_response_code(403);
    exit(json_encode(['error' => ['code' => 403, 'message' => 'bad key', 'status' => 'PERMISSION_DENIED']]));
}
$contents = $body['contents'] ?? [];
$lastParts = $contents[count($contents) - 1]['parts'] ?? [];
$last = '';
foreach ($lastParts as $p) { $last .= $p['text'] ?? ''; }
$isNotes = strpos(json_encode($body['system_instruction'] ?? []), 'private coaching notes') !== false;

if (strpos($last, 'FAIL429') !== false) {
    http_response_code(429);
    exit(json_encode(['error' => ['code' => 429, 'message' => 'Quota exceeded (secret detail)', 'status' => 'RESOURCE_EXHAUSTED']]));
}
if (strpos($last, 'SLOWREPLY') !== false) { sleep(3); }
if (strpos($last, 'NOTHINK400') !== false && isset($body['generationConfig']['thinkingConfig'])) {
    http_response_code(400);
    exit(json_encode(['error' => ['code' => 400, 'message' => 'Unknown name "thinkingLevel": Cannot find field.', 'status' => 'INVALID_ARGUMENT']]));
}
$finish = 'STOP';
if ($isNotes) {
    $text = '- likes role-play practice';
} elseif (strpos($last, 'BLOCKME') !== false) {
    exit(json_encode(['promptFeedback' => ['blockReason' => 'SAFETY'], 'usageMetadata' => ['promptTokenCount' => 10]]));
} elseif (strpos($last, 'LONGREPLY') !== false) {
    $text = 'partial answer'; $finish = 'MAX_TOKENS';
} elseif (strpos($last, 'UNKNOWN') !== false) {
    $text = "我的数据里没有这个。\n[[HANDOFF]]";
} elseif (preg_match('/[\x{4e00}-\x{9fff}]/u', $last)) {
    $text = '你好！我是你的实战教练。';
} else {
    $text = 'COACH-REPLY to: ' . substr($last, 0, 40);
}
echo json_encode([
    'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'thinking...', 'thought' => true], ['text' => $text]]], 'finishReason' => $finish]],
    'usageMetadata' => ['promptTokenCount' => 5100, 'cachedContentTokenCount' => 5000, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 30],
    'modelVersion' => 'gemini-test',
]);
