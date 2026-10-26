<?php
// LOCAL TESTING ONLY. A fake Claude Messages API that records every request.
$log = getenv('MOCK_LOG');
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$hdr = [
    'x-api-key' => $_SERVER['HTTP_X_API_KEY'] ?? '',
    'anthropic-version' => $_SERVER['HTTP_ANTHROPIC_VERSION'] ?? '',
    'anthropic-beta' => $_SERVER['HTTP_ANTHROPIC_BETA'] ?? '',
];
file_put_contents($log, json_encode(['path' => $_SERVER['REQUEST_URI'], 'headers' => $hdr, 'body' => $body], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');
if ($hdr['x-api-key'] !== 'test-key-123') {
    http_response_code(401);
    exit(json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'bad key']]));
}
$last = $body['messages'][count($body['messages']) - 1]['content'] ?? '';
$last = is_array($last) ? json_encode($last) : $last;

if (strpos($last, 'FAIL529') !== false) {
    http_response_code(529);
    exit(json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded (secret detail)']]));
}
$usage = ['input_tokens' => 100, 'cache_read_input_tokens' => 5000, 'cache_creation_input_tokens' => 0, 'output_tokens' => 20];
if (strpos($body['model'], 'haiku') !== false) {
    $text = '- likes role-play practice';
} elseif (strpos($last, 'UNKNOWN') !== false) {
    $text = "I don't have that in my documents.\n[[HANDOFF]]";
} elseif (preg_match('/[\x{4e00}-\x{9fff}]/u', $last)) {
    $text = '你好！我是你的教练。';
} else {
    $text = 'COACH-REPLY to: ' . substr($last, 0, 40);
}
echo json_encode([
    'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'],
    'content' => [['type' => 'text', 'text' => $text]],
    'stop_reason' => 'end_turn', 'usage' => $usage,
]);
