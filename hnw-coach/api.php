<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$member = coach_boot();          // existing member login; fails closed
$action = (string) ($_GET['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

const COACH_ADMIN_ACTIONS = ['test_connection', 'settings', 'save_settings', 'save_instructions', 'restore_instructions', 'upload_pdf', 'delete_pdf', 'usage', 'usage_member'];
const COACH_POST_ACTIONS = ['test_connection', 'chat', 'save_settings', 'save_instructions', 'restore_instructions', 'upload_pdf', 'delete_pdf'];

// Admin actions are checked on the SERVER before anything else happens.
if (in_array($action, COACH_ADMIN_ACTIONS, true)) {
    coach_require_super_admin($member);
}
if (in_array($action, COACH_POST_ACTIONS, true)) {
    if (!$isPost) {
        coach_fail(405, 'Use POST.');
    }
    coach_check_csrf();
}

$json = static function (): array {
    $d = json_decode((string) file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
};
$out = static function (array $d): void {
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$isAdmin = coach_is_super_admin($member);
$GLOBALS['coach_is_admin'] = $isAdmin;
// If PHP dies with a fatal error, answer with JSON (the technical reason only for the super admin) instead of a bare server error page.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
        error_log('HNW Coach fatal: ' . $e['message'] . ' in ' . basename((string) $e['file']) . ':' . $e['line']);
        http_response_code(424); // not 500: hosts may replace the body of 5xx answers and hide the reason
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => coach_msg('failed')] + (!empty($GLOBALS['coach_is_admin']) ? ['detail' => $e['message']] : []), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
});

/** What the Connection box shows. Never includes the key itself, only whether it is set and its last 4 characters. */
function coach_connection_info(): array
{
    $fromFile = coach_const('GEMINI_API_KEY', '') !== '';
    $key = coach_setting('GEMINI_API_KEY');
    return [
        'key_set' => $key !== '',
        'key_last4' => $key !== '' ? substr($key, -4) : '',
        'key_from_server_file' => $fromFile,
        'whatsapp' => preg_replace('/\D/', '', coach_setting('COACH_WHATSAPP_NUMBER')),
        'model' => coach_model(),
        'model_custom' => coach_setting('COACH_MODEL'),
    ];
}

switch ($action) {
    case 'me':
        $out([
            'name' => $member['name'],
            'is_admin' => $isAdmin,
            'ready' => coach_instr_current() !== null,
            'whatsapp' => preg_replace('/\D/', '', coach_setting('COACH_WHATSAPP_NUMBER')),
        ]);

    case 'history':
        $chat = coach_with_member($member['id'], fn($dir) => coach_chat_load($dir));
        $msgs = array_slice($chat['messages'], -100);
        $out(['messages' => array_map(fn($m) => ['role' => $m['r'], 'text' => $m['c'], 'ts' => $m['t'], 'handoff' => !empty($m['h'])], $msgs)]);

    case 'chat':
        @set_time_limit(180); // a reply with all the PDFs attached can take a while
        $instr = coach_instr_current();
        if ($instr === null && !$isAdmin) {
            coach_fail(409, coach_msg('setup'));
        }
        $text = trim((string) ($json()['message'] ?? ''));
        if ($text === '') {
            coach_fail(422, coach_msg('type'));
        }
        $max = (int) coach_const('COACH_MAX_MSG_CHARS', 4000);
        if (mb_strlen($text) > $max) {
            coach_fail(422, coach_msg('too_long', [$max]));
        }
        $instrText = $instr['text'] ?? 'No instructions have been saved yet. Act as a friendly, practical coach for insurance agents.';

        coach_with_member($member['id'], function ($dir) use ($member, $text, $instrText, $out, $isAdmin) {
            $chat = coach_chat_load($dir);
            $chat['name'] = $member['name'];
            $now = time();
            $chat['recent'] = array_values(array_filter($chat['recent'] ?? [], fn($t) => $t > $now - 86400));
            $lastHour = count(array_filter($chat['recent'], fn($t) => $t > $now - 3600));
            if ($lastHour >= (int) coach_const('COACH_RATE_PER_HOUR', 30)) {
                coach_fail(429, coach_msg('rate_hour'));
            }
            if (count($chat['recent']) >= (int) coach_const('COACH_RATE_PER_DAY', 120)) {
                coach_fail(429, coach_msg('rate_day'));
            }

            $res = coach_reply($member, $chat, $text, $instrText);
            coach_log_question($member, $text, $res['ok'] && $res['handoff'], $res['ok']);
            if (!$res['ok']) {
                $msg = match (true) {
                    $res['status'] === 429 || $res['status'] === 529 => coach_msg('busy'),
                    default => coach_msg('failed'),
                };
                coach_fail(502, $msg, $isAdmin ? ['detail' => $res['error']] : []);
            }

            $chat['recent'][] = $now;
            $chat['messages'][] = ['r' => 'user', 'c' => $text, 't' => $now];
            $chat['messages'][] = ['r' => 'assistant', 'c' => $res['reply'], 't' => time(), 'h' => $res['handoff']];
            if (count($chat['messages']) > 500) {
                $chat['messages'] = array_slice($chat['messages'], 100);
                $chat['notes_upto'] = max(0, ($chat['notes_upto'] ?? 0) - 100);
            }
            coach_write_json("$dir/chat.json", $chat);
            coach_write_json(coach_data_root() . '/usage/last_usage.json', $res['usage']);

            coach_respond_then_continue(['reply' => $res['reply'], 'handoff' => $res['handoff'], 'ts' => time()]);

            // After the reply is on its way: fold older messages into the agent's coaching notes.
            $window = max(2, (int) coach_const('COACH_HISTORY_MSGS', 24));
            $keepFrom = count($chat['messages']) - $window;
            $keepFrom -= $keepFrom % 2;
            if ($keepFrom - ($chat['notes_upto'] ?? 0) >= 8) {
                $slice = array_slice($chat['messages'], (int) $chat['notes_upto'], $keepFrom - (int) $chat['notes_upto']);
                $notes = coach_update_notes((string) $chat['notes'], $slice);
                if ($notes !== null) {
                    $chat['notes'] = $notes;
                    $chat['notes_upto'] = $keepFrom;
                    coach_write_json("$dir/chat.json", $chat);
                }
            }
            exit;
        });
        break;

    case 'settings':
        $cur = coach_instr_current();
        $warnings = [];
        if (coach_setting('GEMINI_API_KEY') === '') {
            $warnings[] = coach_msg('w_key');
        }
        if (preg_replace('/\D/', '', coach_setting('COACH_WHATSAPP_NUMBER')) === '') {
            $warnings[] = coach_msg('w_wa');
        }
        $out([
            'connection' => coach_connection_info(),
            'instructions' => $cur,
            'versions' => array_map(fn($v) => [
                'version' => $v['version'], 'saved_at' => $v['saved_at'], 'saved_by' => $v['saved_by'],
                'preview' => mb_substr($v['text'], 0, 140),
                'restored_from' => $v['restored_from'] ?? null,
            ], coach_instr_list()),
            'knowledge' => coach_kb_list(),
            'status' => coach_kb_status(),
            'warnings' => $warnings,
            'keep_versions' => (int) coach_const('COACH_INSTR_KEEP', 5),
            'php_limits' => ['upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size')],
        ]);

    case 'save_settings':
        $in = $json();
        $changes = [];
        $key = trim((string) ($in['gemini_api_key'] ?? ''));
        if (!empty($in['clear_key'])) {
            $changes['GEMINI_API_KEY'] = null;
        } elseif ($key !== '') {
            // Any run of 20-300 visible ASCII characters with no spaces or line breaks (Google's newer key formats contain dots and more).
            if (!preg_match('/^[\x21-\x7E]{20,300}$/', $key)) {
                coach_fail(422, 'That does not look like an API key. Copy the whole key from Google AI Studio, with no spaces.');
            }
            $changes['GEMINI_API_KEY'] = $key;
        }
        if (array_key_exists('model', $in)) {
            $mdl = trim((string) $in['model']);
            $mdl = preg_replace('#^models/#', '', $mdl);
            if ($mdl !== '' && !preg_match('/^[A-Za-z0-9._\-]{3,80}$/', $mdl)) {
                coach_fail(422, 'That does not look like a model name. Use a name such as gemini-3.8-flash, or leave it empty for the default.');
            }
            $changes['COACH_MODEL'] = $mdl === '' ? null : $mdl;
        }
        if (array_key_exists('whatsapp', $in)) {
            $wa = preg_replace('/\D/', '', (string) $in['whatsapp']);
            if ($wa !== '' && (strlen($wa) < 8 || strlen($wa) > 15)) {
                coach_fail(422, 'The WhatsApp number should be 8 to 15 digits, country code first (for example 60123456789).');
            }
            $changes['COACH_WHATSAPP_NUMBER'] = $wa === '' ? null : $wa;
        }
        if ($changes) {
            coach_settings_save($changes);
        }
        $out(['ok' => true, 'connection' => coach_connection_info()]);

    case 'save_instructions':
        $rec = coach_instr_save((string) ($json()['text'] ?? ''), $member);
        $out(['ok' => true, 'version' => $rec['version'], 'saved_at' => $rec['saved_at']]);

    case 'restore_instructions':
        $want = (int) ($json()['version'] ?? 0);
        foreach (coach_instr_list() as $v) {
            if ($v['version'] === $want) {
                $rec = coach_instr_save($v['text'], $member, $want);
                $out(['ok' => true, 'version' => $rec['version']]);
            }
        }
        coach_fail(404, 'That version is no longer kept.');

    case 'upload_pdf':
        // The members system only accepts JSON writes, so the page sends each PDF as base64 inside JSON.
        $in = $json();
        $fname = (string) ($in['name'] ?? '');
        $raw = base64_decode((string) ($in['data'] ?? ''), true);
        if ($raw === false || $raw === '') {
            $out(['ok' => false, 'name' => $fname, 'error' => 'The file did not arrive intact. If it is large, raise post_max_size in cPanel > MultiPHP INI Editor (now ' . ini_get('post_max_size') . ').']);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'hnw');
        if ($tmp === false || file_put_contents($tmp, $raw) === false) {
            coach_fail(500, 'Could not write a temporary file on the server.');
        }
        unset($raw);
        try {
            $res = coach_kb_add($tmp, $fname, $member);
        } finally {
            @unlink($tmp);
        }
        $out($res);

    case 'delete_pdf':
        $ok = coach_kb_delete((string) ($json()['name'] ?? ''));
        $ok ? $out(['ok' => true]) : coach_fail(404, 'File not found.');

    case 'usage':
        $range = in_array($_GET['range'] ?? '7', ['7', '30', 'all'], true) ? (string) $_GET['range'] : '7';
        $out(coach_usage_summary($range));

    case 'usage_member':
        $mid = (string) ($_GET['m'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $mid)) {
            coach_fail(422, 'Bad agent id.');
        }
        $range = in_array($_GET['range'] ?? '7', ['7', '30', 'all'], true) ? (string) $_GET['range'] : '7';
        $out(['questions' => coach_usage_member($mid, $range)]);

    case 'test_connection':
        // Super admin only: checks each part in turn so a failure shows exactly where it is. Never returns the key.
        $step = (string) ($json()['step'] ?? 'basic'); // 'basic' (quick checks + a tiny Google call) or 'pdf' (one call with every PDF): two short requests, not one long one
        $serverMaxExec = ini_get('max_execution_time'); // read before we raise it for this test
        @set_time_limit(180);
        $steps = [];
        $add = static function (string $name, bool $ok, string $detail) use (&$steps): void {
            $steps[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        };
        $kb = coach_kb_list();
        $model = coach_model();
        $hasKey = coach_setting('GEMINI_API_KEY') !== '';
        if ($step === 'basic') {
            $add('PHP version', PHP_VERSION_ID >= 80100, PHP_VERSION);
            $add('cURL (needed to reach Google)', function_exists('curl_init'), function_exists('curl_init') ? 'available' : 'NOT available: switch on the curl extension in cPanel');
            $add('PHP limits', true, 'memory_limit ' . ini_get('memory_limit') . ', max_execution_time ' . $serverMaxExec . 's, post_max_size ' . ini_get('post_max_size'));
            $add('Time allowed for one request', (int) ini_get('max_execution_time') === 0 || (int) ini_get('max_execution_time') >= 120, "server default {$serverMaxExec}s; after asking for more: " . ini_get('max_execution_time') . 's');
            $add('Private data folder', is_writable(coach_data_root()), is_writable(coach_data_root()) ? 'writable, outside public_html' : 'NOT writable');
            $add('PDFs', true, count($kb) . ' file(s), ' . round(array_sum(array_column($kb, 'size')) / 1048576, 2) . ' MB');
            $add('Gemini key saved', $hasKey, $hasKey ? 'yes' : 'no: add it above');
        }
        if ($step === 'basic' && $hasKey && function_exists('curl_init')) {
            $t0 = microtime(true);
            $r = coach_api($model, ['contents' => [['role' => 'user', 'parts' => [['text' => 'Reply with the single word OK.']]]], 'generationConfig' => ['maxOutputTokens' => 256]]);
            $secs = round(microtime(true) - $t0, 1);
            $add("Google answers a tiny question ($model)", $r['ok'], $r['ok'] ? "yes, in {$secs}s" : 'HTTP ' . $r['status'] . ' ' . $r['type'] . ': ' . $r['message']);
        }
        if ($step === 'pdf' && $hasKey && function_exists('curl_init')) {
            if (!$kb) {
                $add('Google answers with all PDFs attached', false, 'no PDFs uploaded yet');
            } else {
                $t0 = microtime(true);
                $parts = coach_kb_parts();
                $parts[] = ['text' => 'Reply with the single word OK.'];
                $r2 = coach_api($model, ['contents' => [['role' => 'user', 'parts' => $parts]], 'generationConfig' => ['maxOutputTokens' => 256]]);
                $secs = round(microtime(true) - $t0, 1);
                $tok = (int) ($r2['data']['usageMetadata']['promptTokenCount'] ?? 0);
                $add('Google answers with all PDFs attached', $r2['ok'], $r2['ok'] ? "yes, in {$secs}s ($tok input tokens)" : 'HTTP ' . $r2['status'] . ' ' . $r2['type'] . ': ' . $r2['message'] . " (after {$secs}s)");
            }
        }
        $out(['steps' => $steps]);

    case 'whoami':
        // Setup help only: shows the signed-in person which fields their own member record has (no values except role flags).
        $raw = $member['raw'];
        $out([
            'fields' => array_keys($raw),
            'is_admin' => $raw['is_admin'] ?? null,
            'is_team_admin' => $raw['is_team_admin'] ?? null,
            'treated_as_super_admin' => $isAdmin,
        ]);

    default:
        coach_fail(404, 'Unknown action.');
}
