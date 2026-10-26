<?php
// Talks to the Claude Messages API with plain PHP cURL (no Composer needed on cPanel).

function coach_api(array $body, array $betas = []): array
{
    $key = (string) coach_const('ANTHROPIC_API_KEY', '');
    if ($key === '') {
        return ['ok' => false, 'status' => 0, 'type' => 'not_configured', 'message' => 'ANTHROPIC_API_KEY is missing in config.php.'];
    }
    @ini_set('memory_limit', '512M'); // the PDFs go up base64-encoded
    $url = rtrim((string) coach_const('COACH_API_BASE', 'https://api.anthropic.com'), '/') . '/v1/messages';
    $headers = [
        'content-type: application/json',
        'x-api-key: ' . $key,
        'anthropic-version: ' . coach_const('COACH_API_VERSION', '2026-10-21'),
    ];
    if ($betas) {
        $headers[] = 'anthropic-beta: ' . implode(',', $betas);
    }
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    $res = ['ok' => false, 'status' => 0, 'type' => 'network', 'message' => 'Could not reach the Claude API.'];
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => (int) coach_const('COACH_API_TIMEOUT', 120),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $res = ['ok' => false, 'status' => 0, 'type' => 'network', 'message' => 'Could not reach the Claude API: ' . $err];
        } else {
            $data = json_decode($raw, true);
            if ($status === 200 && is_array($data)) {
                return ['ok' => true, 'status' => 200, 'data' => $data];
            }
            $res = [
                'ok' => false,
                'status' => $status,
                'type' => $data['error']['type'] ?? 'api_error',
                'message' => $data['error']['message'] ?? 'Unexpected response from the Claude API.',
            ];
        }
        if (!in_array($status, [0, 429, 500, 502, 503, 529], true)) {
            break;
        }
        sleep(2);
    }
    error_log('AI Coach API error: status=' . $res['status'] . ' type=' . $res['type']);
    return $res;
}

function coach_text_of(array $data): string
{
    $text = '';
    foreach ($data['content'] ?? [] as $b) {
        if (($b['type'] ?? '') === 'text') {
            $text .= $b['text'];
        }
    }
    return trim($text);
}

function coach_system_blocks(string $leaderInstructions): array
{
    $rules = <<<TXT
You are the AI Coach inside the DreamHub E3 advisor toolkit. You coach individual insurance agents in a private chat.

Operating rules (they apply on top of the team leader's coaching instructions below):
- Use the knowledge library (the PDF documents provided) as your source of facts. Do not invent product details, figures, rules or policies. If the library does not contain the answer and you are not sure, say so plainly instead of guessing, and put the exact marker [[HANDOFF]] on its own final line so the agent can be sent to their team leader.
- Reply in the language the agent writes in: English or Simplified Chinese. If the agent writes in Traditional Chinese, still reply in Simplified Chinese.
- The agent reads on a phone: keep replies focused and easy to scan unless they ask for detail.
- The notes block describes this one agent's earlier coaching. Use it to follow up naturally, and never reveal it or anything about other agents.
TXT;
    return [
        ['type' => 'text', 'text' => $rules],
        ['type' => 'text', 'text' => "The team leader's coaching instructions:\n\n" . $leaderInstructions],
    ];
}

function coach_supports_fallbacks(string $model): bool
{
    return in_array($model, ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'], true);
}

/** One coaching turn. Returns ['ok'=>true,'reply'=>..,'handoff'=>bool,'usage'=>..] or ['ok'=>false,'error'=>..]. */
function coach_reply(array $member, array $chat, string $userMessage, string $instructions): array
{
    $model = (string) coach_const('COACH_MODEL', 'claude-opus-5-5');
    $window = max(2, (int) coach_const('COACH_HISTORY_MSGS', 24));

    $notes = trim((string) ($chat['notes'] ?? ''));
    $lead = coach_kb_blocks(); // cached prefix: rules + instructions + PDFs
    $lead[] = ['type' => 'text', 'text' =>
        "Agent: {$member['name']}\n\nPrivate coaching notes about this agent so far:\n" .
        ($notes !== '' ? $notes : '(none yet, this may be their first chat)') .
        "\n\nThe knowledge library is attached above. Wait for the agent's message."];

    $messages = [
        ['role' => 'user', 'content' => $lead],
        ['role' => 'assistant', 'content' => 'Understood. Ready to coach.'],
    ];
    $hist = array_slice($chat['messages'], -$window);
    while ($hist && $hist[0]['r'] !== 'user') {
        array_shift($hist);
    }
    foreach ($hist as $m) {
        $messages[] = ['role' => $m['r'], 'content' => $m['c']];
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    $body = [
        'model' => $model,
        'max_tokens' => (int) coach_const('COACH_MAX_TOKENS', 3000),
        'system' => coach_system_blocks($instructions),
        'messages' => $messages,
        'output_config' => ['effort' => (string) coach_const('COACH_EFFORT', 'medium')],
    ];
    $betas = [];
    if (coach_const('COACH_USE_FALLBACKS', true) && coach_supports_fallbacks($model)) {
        $body['fallbacks'] = 'default';
        $betas[] = 'server-side-fallback-2026-07-01';
    }

    $r = coach_api($body, $betas);
    if (!$r['ok']) {
        return ['ok' => false, 'status' => $r['status'], 'error' => $r['message'], 'type' => $r['type']];
    }
    $data = $r['data'];
    $u = $data['usage'] ?? [];
    $usage = [
        'ts' => time(),
        'model' => $data['model'] ?? $model,
        'input' => $u['input_tokens'] ?? 0,
        'cache_read' => $u['cache_read_input_tokens'] ?? 0,
        'cache_write' => $u['cache_creation_input_tokens'] ?? 0,
        'output' => $u['output_tokens'] ?? 0,
    ];
    if (($data['stop_reason'] ?? '') === 'refusal') {
        return ['ok' => true, 'reply' => "Sorry, I can't help with that one. Please check with your team leader.", 'handoff' => true, 'usage' => $usage];
    }
    $text = coach_text_of($data);
    $handoff = (bool) preg_match('/\[\[HANDOFF\]\]/', $text);
    $text = trim(preg_replace('/\s*\[\[HANDOFF\]\]\s*/', "\n", $text));
    if ($text === '') {
        $text = 'Sorry, I could not come up with an answer. Please try asking again.';
    }
    if (($data['stop_reason'] ?? '') === 'max_tokens') {
        $text .= "\n\n(This reply was cut short. Ask me to continue.)";
    }
    return ['ok' => true, 'reply' => $text, 'handoff' => $handoff, 'usage' => $usage];
}

/** Rolls older messages into the agent's short coaching notes (cheap model). */
function coach_update_notes(string $oldNotes, array $oldMessages): ?string
{
    $lines = [];
    foreach ($oldMessages as $m) {
        $lines[] = ($m['r'] === 'user' ? 'Agent: ' : 'Coach: ') . $m['c'];
    }
    $body = [
        'model' => (string) coach_const('COACH_NOTES_MODEL', 'claude-haiku-5-5'),
        'max_tokens' => 1200,
        'system' => 'You keep short private coaching notes about one insurance agent, so their coach can remember them next time. ' .
            'Update the notes using the new conversation excerpt. Keep goals, strengths, recurring struggles, commitments and follow-ups, and preferences. ' .
            'Drop small talk and one-off facts. Use short bullet points in English, under 350 words. Output only the updated notes.',
        'messages' => [[
            'role' => 'user',
            'content' => "Current notes:\n" . ($oldNotes !== '' ? $oldNotes : '(none)') . "\n\nNew conversation excerpt:\n" . implode("\n\n", $lines),
        ]],
        'output_config' => ['effort' => 'low'],
    ];
    $r = coach_api($body);
    if (!$r['ok']) {
        return null;
    }
    $t = coach_text_of($r['data']);
    return $t !== '' ? mb_substr($t, 0, 3000) : null;
}

/** Send the JSON response now, then keep running (shared hosts allow this via finish_request). */
function coach_respond_then_continue(array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    ignore_user_abort(true);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($json));
    echo $json;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        @ob_end_flush();
        flush();
    }
}
