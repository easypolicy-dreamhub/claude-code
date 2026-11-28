<?php
// Storage for the AI Coach: instructions (5 versions), knowledge PDFs, per-agent
// memory and the usage log. Everything lives in the PRIVATE data folder, never
// under public_html.

/** A setting: a constant if one is defined (local tests), else private/hnw-coach-config.php, else the main config array's 'hnw_coach' entry. */
function coach_const(string $name, $default)
{
    if (defined($name)) {
        return constant($name);
    }
    $cfg = $GLOBALS['coach_cfg'] ?? [];
    return array_key_exists($name, $cfg) ? $cfg[$name] : $default;
}

function coach_fail(int $code, string $message, array $extra = [])
{
    // Some hosts replace the body of any 5xx answer with their own empty error page, which hides the reason.
    // So server-side problems are sent as 424 (a 4xx code passes through untouched); 'status' keeps the real code.
    http_response_code($code >= 500 ? 424 : $code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $message, 'status' => $code] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{"error":"Server error"}';
    exit;
}

/* ---------- Language: the page sends X-Coach-Lang (zh = Simplified Chinese, the default for members; en = English) ---------- */

function coach_lang(): string
{
    return (($_SERVER['HTTP_X_COACH_LANG'] ?? '') === 'zh') ? 'zh' : 'en';
}

/** Messages members can see, in both languages. Admin-only messages stay in English. */
function coach_msg(string $key, array $args = []): string
{
    static $m = [
        'setup'     => ['zh' => '教练正在设置中，请稍后再回来。', 'en' => 'The coach is being set up. Check back soon.'],
        'type'      => ['zh' => '请先输入消息。', 'en' => 'Type a message first.'],
        'too_long'  => ['zh' => '消息太长（上限 %d 字）。', 'en' => 'That message is too long (limit %d characters).'],
        'rate_hour' => ['zh' => '你这个小时发送了很多消息，请稍后再试。', 'en' => 'You have sent a lot of messages this hour. Please try again a little later.'],
        'rate_day'  => ['zh' => '你今天的消息已达上限，请明天再试。', 'en' => "You have reached today's message limit. Please try again tomorrow."],
        'busy'      => ['zh' => '教练现在很忙，请一分钟后再试。', 'en' => 'The coach is very busy right now. Please try again in a minute.'],
        'failed'    => ['zh' => '教练暂时无法回答，请再试一次。', 'en' => 'The coach could not answer just now. Please try again.'],
        'blocked'   => ['zh' => '抱歉，这个问题我无法协助，请向你的团队领袖确认。', 'en' => "Sorry, I can't help with that one. Please check with your team leader."],
        'noanswer'  => ['zh' => '抱歉，我想不出答案，请再问一次。', 'en' => 'Sorry, I could not come up with an answer. Please try asking again.'],
        'cut'       => ['zh' => '（这则回复被截断了，请输入「继续」。）', 'en' => '(This reply was cut short. Ask me to continue.)'],
        'w_key'     => ['zh' => '尚未设置 Gemini API 密钥，教练暂时无法回答。请在下方「连接设置」加入。', 'en' => 'The Gemini API key is not set, so the coach cannot answer yet. Add it under Connection below.'],
        'w_wa'      => ['zh' => '尚未设置团队领袖的 WhatsApp 号码，「询问领袖」按钮没有号码。请在下方「连接设置」加入。', 'en' => 'The leader\'s WhatsApp number is not set, so the "Ask my leader" button has no number. Add it under Connection below.'],
    ];
    $t = $m[$key][coach_lang()] ?? $m[$key]['en'] ?? $key;
    return $args ? vsprintf($t, $args) : $t;
}

/* ---------- Connection settings saved from the Settings tab (Gemini key, leader's WhatsApp number) ---------- */

function coach_settings_read(): array
{
    $d = coach_read_json(coach_data_root() . '/settings.json', []);
    return is_array($d) ? $d : [];
}

/** A setting by name: server file / constant first, else what the super admin saved in Settings. */
function coach_setting(string $name): string
{
    $v = coach_const($name, '');
    if (is_string($v) && $v !== '') {
        return $v;
    }
    return (string) (coach_settings_read()[$name] ?? '');
}

/** The Gemini model. The super admin can change it in Settings > Connection when Google retires one. */
function coach_model(): string
{
    $m = coach_setting('COACH_MODEL');
    return $m !== '' ? $m : 'gemini-3.8-flash';
}

/** The model that writes each member's short coaching notes (defaults to the same model). */
function coach_notes_model(): string
{
    $m = coach_setting('COACH_NOTES_MODEL');
    return $m !== '' ? $m : coach_model();
}

/** Save or (with null) remove settings. The file sits in the private data folder, mode 0600. */
function coach_settings_save(array $changes): void
{
    coach_with_lock(coach_data_root() . '/.settings.lock', function () use ($changes) {
        $s = coach_settings_read();
        foreach ($changes as $k => $v) {
            if ($v === null) {
                unset($s[$k]);
            } else {
                $s[$k] = $v;
            }
        }
        $path = coach_data_root() . '/settings.json';
        coach_write_json($path, $s);
        @chmod($path, 0600);
    });
}

function coach_data_root(): string
{
    static $root = null;
    if ($root !== null) {
        return $root;
    }
    $dir = (string) coach_const('COACH_DATA_DIR', '');
    $doc = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($dir === '') {
        if ($doc === '') {
            coach_fail(500, 'COACH_DATA_DIR is not set in private/hnw-coach-config.php.');
        }
        $dir = dirname(rtrim($doc, '/')) . '/e3-hnw-coach';
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        coach_fail(500, 'Cannot create the private data folder. Set COACH_DATA_DIR in private/hnw-coach-config.php to a writable folder above public_html.');
    }
    $real = realpath($dir);
    $docReal = $doc !== '' ? realpath($doc) : false;
    if ($real === false || ($docReal !== false && ($real === $docReal || str_starts_with($real, $docReal . DIRECTORY_SEPARATOR)))) {
        coach_fail(500, 'The HNW Coach data folder must be outside public_html.');
    }
    foreach (['instructions', 'knowledge', 'members', 'usage'] as $sub) {
        if (!is_dir("$real/$sub")) {
            @mkdir("$real/$sub", 0700, true);
        }
    }
    if (!is_file("$real/.htaccess")) {
        @file_put_contents("$real/.htaccess", "Require all denied\n");
    }
    return $root = $real;
}

function coach_read_json(string $path, $default = null)
{
    if (!is_file($path)) {
        return $default;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return $data === null ? $default : $data;
}

function coach_write_json(string $path, $data): void
{
    $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        coach_fail(500, 'Could not save data. Check that the private folder is writable.');
    }
}

function coach_with_lock(string $lockFile, callable $fn)
{
    $fh = fopen($lockFile, 'c');
    if (!$fh) {
        coach_fail(500, 'Could not open a lock file in the private folder.');
    }
    flock($fh, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/* ---------- Instructions: keep the last N versions ---------- */

function coach_instr_files(): array
{
    $files = glob(coach_data_root() . '/instructions/v*.json') ?: [];
    rsort($files);
    return $files;
}

function coach_instr_list(): array
{
    $out = [];
    foreach (coach_instr_files() as $f) {
        $d = coach_read_json($f);
        if (is_array($d)) {
            $out[] = $d;
        }
    }
    return $out;
}

function coach_instr_current(): ?array
{
    $files = coach_instr_files();
    return $files ? coach_read_json($files[0]) : null;
}

function coach_instr_save(string $text, array $member, ?int $restoredFrom = null): array
{
    $text = trim(str_replace("\r\n", "\n", $text));
    if ($text === '') {
        coach_fail(422, 'The instructions cannot be empty.');
    }
    if (mb_strlen($text) > 60000) {
        coach_fail(422, 'The instructions are too long (limit 60,000 characters).');
    }
    $dir = coach_data_root() . '/instructions';
    return coach_with_lock("$dir/.lock", function () use ($text, $member, $restoredFrom, $dir) {
        $files = coach_instr_files();
        $cur = $files ? coach_read_json($files[0]) : null;
        if ($cur && ($cur['text'] ?? null) === $text && $restoredFrom === null) {
            return $cur; // unchanged, no new version
        }
        $latest = $files ? (int) preg_replace('/\D/', '', basename($files[0])) : 0;
        $rec = [
            'version' => $latest + 1,
            'text' => $text,
            'saved_at' => time(),
            'saved_by' => $member['name'],
        ];
        if ($restoredFrom !== null) {
            $rec['restored_from'] = $restoredFrom;
        }
        coach_write_json(sprintf('%s/v%06d.json', $dir, $rec['version']), $rec);
        $keep = max(1, (int) coach_const('COACH_INSTR_KEEP', 5));
        foreach (array_slice(coach_instr_files(), $keep) as $old) {
            @unlink($old);
        }
        return $rec;
    });
}

/* ---------- Knowledge PDFs ---------- */

function coach_kb_dir(): string
{
    return coach_data_root() . '/knowledge';
}

function coach_kb_index(): array
{
    return coach_read_json(coach_kb_dir() . '/index.json', []);
}

function coach_kb_clean_name(string $orig): ?string
{
    $name = trim(basename(str_replace('\\', '/', $orig)));
    $name = preg_replace('/[^\p{L}\p{N}._ \-()]+/u', '_', $name);
    $name = trim((string) $name);
    if (!preg_match('/\.pdf$/i', $name)) {
        return null;
    }
    $name = substr($name, 0, -4) . '.pdf';
    if ($name === '.pdf' || $name[0] === '.' || mb_strlen($name) > 120) {
        return null;
    }
    return $name;
}

function coach_kb_list(): array
{
    $index = coach_kb_index();
    $out = [];
    foreach (glob(coach_kb_dir() . '/*.pdf') ?: [] as $f) {
        $name = basename($f);
        $out[] = [
            'name' => $name,
            'size' => filesize($f),
            'pages' => $index[$name]['pages'] ?? null,
            'uploaded_at' => $index[$name]['uploaded_at'] ?? filemtime($f),
            'uploaded_by' => $index[$name]['by'] ?? '',
        ];
    }
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

function coach_pdf_meta(string $data): array
{
    return [
        'pages' => ($n = preg_match_all('~/Type\s*/Page(?![A-Za-z])~', $data)) ? $n : null,
        'encrypted' => strpos($data, '/Encrypt') !== false,
    ];
}

/** Adds or replaces one PDF. Returns ['ok'=>true,...] or ['ok'=>false,'error'=>...]. */
function coach_kb_add(string $tmpPath, string $origName, array $member): array
{
    $name = coach_kb_clean_name($origName);
    if ($name === null) {
        return ['ok' => false, 'name' => $origName, 'error' => 'Only .pdf files are accepted.'];
    }
    $size = filesize($tmpPath);
    $maxPdf = (int) coach_const('COACH_MAX_PDF_MB', 20) * 1048576;
    if ($size === false || $size < 100 || $size > $maxPdf) {
        return ['ok' => false, 'name' => $name, 'error' => 'File is empty or larger than ' . (int) coach_const('COACH_MAX_PDF_MB', 20) . ' MB.'];
    }
    $data = (string) file_get_contents($tmpPath);
    if (strpos(substr($data, 0, 1024), '%PDF-') === false) {
        return ['ok' => false, 'name' => $name, 'error' => 'This does not look like a PDF.'];
    }
    $meta = coach_pdf_meta($data);
    if ($meta['encrypted']) {
        return ['ok' => false, 'name' => $name, 'error' => 'This PDF is password-protected. Remove the password and upload it again.'];
    }
    unset($data);

    return coach_with_lock(coach_kb_dir() . '/.lock', function () use ($tmpPath, $name, $size, $meta, $member) {
        $existing = null;
        $others = 0;
        foreach (glob(coach_kb_dir() . '/*.pdf') ?: [] as $f) {
            if (strcasecmp(basename($f), $name) === 0) {
                $existing = basename($f);
            } else {
                $others += filesize($f);
            }
        }
        $maxTotal = (int) coach_const('COACH_MAX_TOTAL_MB', 14) * 1048576;
        if ($others + $size > $maxTotal) {
            return ['ok' => false, 'name' => $name, 'error' => 'The knowledge library would be over ' . (int) coach_const('COACH_MAX_TOTAL_MB', 14) . ' MB in total (a Gemini request limit). Delete or shrink a PDF first.'];
        }
        $dest = coach_kb_dir() . '/' . $name;
        if ($existing !== null && $existing !== $name) {
            @unlink(coach_kb_dir() . '/' . $existing);
        }
        $stage = $dest . '.part' . bin2hex(random_bytes(3));
        if (!copy($tmpPath, $stage) || !rename($stage, $dest)) {
            @unlink($stage);
            return ['ok' => false, 'name' => $name, 'error' => 'Could not save the file. Check the private folder is writable.'];
        }
        $index = coach_kb_index();
        if ($existing !== null) {
            unset($index[$existing]);
        }
        $index[$name] = ['pages' => $meta['pages'], 'uploaded_at' => time(), 'by' => $member['name']];
        coach_write_json(coach_kb_dir() . '/index.json', $index);
        return ['ok' => true, 'name' => $name, 'status' => $existing !== null ? 'replaced' : 'added', 'pages' => $meta['pages']];
    });
}

function coach_kb_delete(string $name): bool
{
    return coach_with_lock(coach_kb_dir() . '/.lock', function () use ($name) {
        foreach (coach_kb_list() as $f) { // match against the real listing, never build a path from input
            if ($f['name'] === $name) {
                @unlink(coach_kb_dir() . '/' . $f['name']);
                $index = coach_kb_index();
                unset($index[$f['name']]);
                coach_write_json(coach_kb_dir() . '/index.json', $index);
                return true;
            }
        }
        return false;
    });
}

/** PDF parts for the Gemini request. Stable order so Gemini's own caching can reuse them. */
function coach_kb_parts(): array
{
    $parts = [];
    foreach (coach_kb_list() as $f) {
        $parts[] = ['text' => 'Document: ' . pathinfo($f['name'], PATHINFO_FILENAME)];
        $parts[] = ['inline_data' => [
            'mime_type' => 'application/pdf',
            'data' => base64_encode((string) file_get_contents(coach_kb_dir() . '/' . $f['name'])),
        ]];
    }
    return $parts;
}

function coach_kb_status(): array
{
    $list = coach_kb_list();
    $bytes = array_sum(array_column($list, 'size'));
    $pages = 0;
    foreach ($list as $f) {
        $pages += $f['pages'] ?? max(1, (int) round($f['size'] / 80000));
    }
    $tokens = $pages * 1200; // rough: Gemini counts about 260 tokens per page plus the text on it
    return [
        'pdf_count' => count($list),
        'total_bytes' => $bytes,
        'est_pages' => $pages,
        'est_tokens' => $tokens,
        'warn_too_big' => $tokens > 600000 || $pages > 900,
        'last_usage' => coach_read_json(coach_data_root() . '/usage/last_usage.json'),
    ];
}

/* ---------- Agents: private memory ---------- */

function coach_mid(string $memberId): string
{
    return substr(hash('sha256', $memberId), 0, 32);
}

function coach_member_dir(string $memberId): string
{
    $dir = coach_data_root() . '/members/' . coach_mid($memberId);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function coach_with_member(string $memberId, callable $fn)
{
    $dir = coach_member_dir($memberId);
    return coach_with_lock("$dir/.lock", fn() => $fn($dir));
}

function coach_chat_load(string $dir): array
{
    return coach_read_json("$dir/chat.json", ['messages' => [], 'notes' => '', 'notes_upto' => 0, 'recent' => []]);
}

/* ---------- Usage log (questions only, never the answers) ---------- */

function coach_log_question(array $member, string $question, bool $handoff, bool $ok): void
{
    $line = json_encode([
        'ts' => time(),
        'mid' => coach_mid($member['id']),
        'name' => $member['name'],
        'q' => $question,
        'handoff' => $handoff,
        'ok' => $ok,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    file_put_contents(coach_data_root() . '/usage/questions.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
}

function coach_log_read(): array
{
    $path = coach_data_root() . '/usage/questions.jsonl';
    $rows = [];
    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
            $r = json_decode($l, true);
            if (is_array($r)) {
                $rows[] = $r;
            }
        }
    }
    return $rows;
}

function coach_range_start(string $range): int
{
    return match ($range) {
        '7' => time() - 7 * 86400,
        '30' => time() - 30 * 86400,
        default => 0,
    };
}

function coach_usage_summary(string $range): array
{
    $since = coach_range_start($range);
    $nudgeBefore = time() - 7 * 86400;
    $people = [];
    foreach (coach_log_read() as $r) {
        $mid = $r['mid'];
        $p = &$people[$mid];
        $p ??= ['mid' => $mid, 'name' => $r['name'], 'questions' => 0, 'handoffs' => 0, 'last_active' => 0];
        $p['name'] = $r['name'];
        $p['last_active'] = max($p['last_active'], $r['ts']);
        if ($r['ts'] >= $since) {
            $p['questions']++;
            $p['handoffs'] += !empty($r['handoff']) ? 1 : 0;
        }
        unset($p);
    }
    foreach (coach_list_members() as $m) { // optional roster: lets never-used agents show up
        $mid = coach_mid((string) $m['id']);
        $people[$mid] ??= ['mid' => $mid, 'name' => $m['name'], 'questions' => 0, 'handoffs' => 0, 'last_active' => 0];
    }
    $people = array_values($people);
    usort($people, fn($a, $b) => [$b['questions'], $b['last_active']] <=> [$a['questions'], $a['last_active']]);
    $nudge = array_values(array_filter($people, fn($p) => $p['last_active'] < $nudgeBefore));
    return [
        'tiles' => [
            'questions' => array_sum(array_column($people, 'questions')),
            'agents_using' => count(array_filter($people, fn($p) => $p['questions'] > 0)),
            'handoffs' => array_sum(array_column($people, 'handoffs')),
        ],
        'needs_nudge' => $nudge,
        'people' => $people,
    ];
}

function coach_usage_member(string $mid, string $range): array
{
    $since = coach_range_start($range);
    $out = [];
    foreach (array_reverse(coach_log_read()) as $r) {
        if ($r['mid'] === $mid && $r['ts'] >= $since) {
            $out[] = ['ts' => $r['ts'], 'q' => $r['q'], 'handoff' => !empty($r['handoff'])];
            if (count($out) >= 200) {
                break;
            }
        }
    }
    return $out;
}
