<?php
// LOCAL TESTING ONLY. A fake Gemini generateContent API that records every request.
$log = getenv('MOCK_LOG');
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$hdr = ['x-goog-api-key' => $_SERVER['HTTP_X_GOOG_API_KEY'] ?? ''];
file_put_contents($log, json_encode(['path' => strtok($_SERVER['REQUEST_URI'], '?'), 'query' => $_SERVER['QUERY_STRING'] ?? '', 'headers' => $hdr, 'body' => $body], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');
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
