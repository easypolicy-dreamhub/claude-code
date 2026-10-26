<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$member = coach_boot();          // existing member login; fails closed
$action = (string) ($_GET['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

const COACH_ADMIN_ACTIONS = ['settings', 'save_instructions', 'restore_instructions', 'upload_pdf', 'delete_pdf', 'usage', 'usage_member'];
const COACH_POST_ACTIONS = ['chat', 'save_instructions', 'restore_instructions', 'upload_pdf', 'delete_pdf'];

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

switch ($action) {
    case 'me':
        $out([
            'name' => $member['name'],
            'is_admin' => $isAdmin,
            'ready' => coach_instr_current() !== null,
            'whatsapp' => preg_replace('/\D/', '', (string) coach_const('COACH_WHATSAPP_NUMBER', '')),
        ]);

    case 'history':
        $chat = coach_with_member($member['id'], fn($dir) => coach_chat_load($dir));
        $msgs = array_slice($chat['messages'], -100);
        $out(['messages' => array_map(fn($m) => ['role' => $m['r'], 'text' => $m['c'], 'ts' => $m['t'], 'handoff' => !empty($m['h'])], $msgs)]);

    case 'chat':
        $instr = coach_instr_current();
        if ($instr === null && !$isAdmin) {
            coach_fail(409, 'The coach is being set up. Check back soon.');
        }
        $text = trim((string) ($json()['message'] ?? ''));
        if ($text === '') {
            coach_fail(422, 'Type a message first.');
        }
        $max = (int) coach_const('COACH_MAX_MSG_CHARS', 4000);
        if (mb_strlen($text) > $max) {
            coach_fail(422, "That message is too long (limit $max characters).");
        }
        $instrText = $instr['text'] ?? 'No instructions have been saved yet. Act as a friendly, practical coach for insurance agents.';

        coach_with_member($member['id'], function ($dir) use ($member, $text, $instrText, $out, $isAdmin) {
            $chat = coach_chat_load($dir);
            $chat['name'] = $member['name'];
            $now = time();
            $chat['recent'] = array_values(array_filter($chat['recent'] ?? [], fn($t) => $t > $now - 3600));
            if (count($chat['recent']) >= (int) coach_const('COACH_RATE_PER_HOUR', 60)) {
                coach_fail(429, 'You have sent a lot of messages this hour. Please try again a little later.');
            }

            $res = coach_reply($member, $chat, $text, $instrText);
            coach_log_question($member, $text, $res['ok'] && $res['handoff'], $res['ok']);
            if (!$res['ok']) {
                $msg = match (true) {
                    $res['status'] === 429 || $res['status'] === 529 => 'The coach is very busy right now. Please try again in a minute.',
                    default => 'The coach could not answer just now. Please try again.',
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
        if ((string) coach_const('ANTHROPIC_API_KEY', '') === '') {
            $warnings[] = 'ANTHROPIC_API_KEY is missing in config.php, so the coach cannot answer yet.';
        }
        if (preg_replace('/\D/', '', (string) coach_const('COACH_WHATSAPP_NUMBER', '')) === '') {
            $warnings[] = 'COACH_WHATSAPP_NUMBER is not set in config.php, so the "Ask my leader" button has no number.';
        }
        $out([
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
        $f = $_FILES['pdf'] ?? null;
        if (!$f) {
            coach_fail(422, 'No file received. The file may be bigger than the server allows (' . ini_get('upload_max_filesize') . ' per file, ' . ini_get('post_max_size') . ' per upload).');
        }
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $why = in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'Bigger than the server upload limit (' . ini_get('upload_max_filesize') . '). Raise upload_max_filesize and post_max_size in cPanel > MultiPHP INI Editor.'
                : 'Upload failed (code ' . $f['error'] . ').';
            $out(['ok' => false, 'name' => (string) $f['name'], 'error' => $why]);
        }
        if (!is_uploaded_file($f['tmp_name'])) {
            coach_fail(400, 'Bad upload.');
        }
        $out(coach_kb_add($f['tmp_name'], (string) $f['name'], $member));

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

    default:
        coach_fail(404, 'Unknown action.');
}
