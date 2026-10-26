<?php
declare(strict_types=1);

require_once __DIR__ . '/candidates.php';
require_once __DIR__ . '/advisor.php';
require_once __DIR__ . '/disc_emails.php';

/**
 * DISC (working-style) questionnaire for candidates. 28 questions; for each one the person picks the word that is
 * MOST like them and the one that is LEAST like them out of four (one word per style). Scoring is server-side:
 * most = +1 for that style, least = -1, so each style runs -28..28 and the four always add up to 0.
 * The questions are original adjective sets; the words are public, which word belongs to which style is not.
 */
const DISC_TYPES = ['D', 'I', 'S', 'C'];
const DISC_QUESTIONS = 28;
const DISC_EXPIRY_DAYS = 14;
const DISC_LIVE_EXPIRY_HOURS = 24;
const DISC_REMIND_DAYS = 3;
const DISC_MAX_WRONG = 5;
const DISC_ELIGIBLE_VERDICTS = ['v-strong', 'v-good', 'v-review'];   // not "v-low"
const DISC_CLOSED_STATUSES = ['not_suitable', 'joined'];            // no invites or reminders for these

function disc_data(): array
{
    static $d = null;
    return $d ??= json_decode((string)file_get_contents(__DIR__ . '/../data/disc.json'), true);
}

function disc_text(): array
{
    static $d = null;
    return $d ??= json_decode((string)file_get_contents(__DIR__ . '/../data/disc_text.json'), true);
}

/** @return array{scores:array<string,int>, most:int[], least:int[]} */
function score_disc(mixed $answers): array
{
    $q = disc_data()['questions'];
    if (!is_array($answers) || !array_is_list($answers) || count($answers) !== DISC_QUESTIONS) {
        throw new HttpError(422, 'Please answer every question.');
    }
    $scores = array_fill_keys(DISC_TYPES, 0);
    $most = $least = [];
    foreach ($answers as $i => $a) {
        $m = is_array($a) ? ($a['most'] ?? null) : null;
        $l = is_array($a) ? ($a['least'] ?? null) : null;
        if (!is_int($m) || !is_int($l) || $m < 0 || $m > 3 || $l < 0 || $l > 3 || $m === $l) {
            throw new HttpError(422, 'Please choose one "most" and one "least" for every question.');
        }
        $scores[$q[$i][$m]]++;
        $scores[$q[$i][$l]]--;
        $most[] = $m;
        $least[] = $l;
    }
    return ['scores' => $scores, 'most' => $most, 'least' => $least];
}

/** @return array<string,string> style => high|mid|low */
function disc_levels(array $scores): array
{
    $h = (int)disc_data()['settings']['high'];
    return array_map(static fn (int $s): string => $s >= $h ? 'high' : ($s <= -$h ? 'low' : 'mid'), $scores);
}

/** "I" or "IS": the strongest style, plus the next one when it is close and not below zero. Ties keep D,I,S,C order. */
function disc_style(array $scores): string
{
    $types = DISC_TYPES;
    usort($types, static fn (string $a, string $b): int => $scores[$b] <=> $scores[$a] ?: array_search($a, DISC_TYPES, true) <=> array_search($b, DISC_TYPES, true));
    $gap = (int)disc_data()['settings']['secondary_gap'];
    return $types[0] . ($scores[$types[1]] >= 0 && $scores[$types[0]] - $scores[$types[1]] <= $gap ? $types[1] : '');
}

/** @return list<array{key:string, n?:int}> things an advisor should double-check about how the test was answered */
function disc_flags(array $most, array $least, array $scores, ?int $duration): array
{
    $s = disc_data()['settings'];
    $flags = [];
    if ($duration !== null && $duration < (int)$s['min_seconds']) {
        $flags[] = ['key' => 'fast', 'n' => $duration];
    }
    foreach ([$most, $least] as $picks) {
        if (max(array_count_values($picks)) / DISC_QUESTIONS >= (float)$s['position_share']) {
            $flags[] = ['key' => 'position'];
            break;
        }
    }
    if (max(array_map('abs', $scores)) >= (int)$s['extreme']) {
        $flags[] = ['key' => 'extreme'];
    } elseif (max(array_map('abs', $scores)) <= (int)$s['flat']) {
        $flags[] = ['key' => 'flat'];
    }
    return $flags;
}

/** How well the working style fits the interest profile: consistent | partly | differs | none. */
function disc_alignment(string $style, ?string $hollandCode): string
{
    if ($hollandCode === null || strlen($hollandCode) < 2) {
        return 'none';
    }
    $aff = disc_data()['settings']['affinity'][$style[0]];
    if (in_array($hollandCode[0], $aff, true)) {
        return 'consistent';
    }
    return in_array($hollandCode[1], $aff, true) ? 'partly' : 'differs';
}

function disc_label(string $style, string $lang): string
{
    $t = disc_text()[$lang === 'zh' ? 'zh' : 'en'];
    $a = $t['type'][$style[0]]['name'];
    return strlen($style) === 2 ? strtr($t['ui']['blend'], ['{a}' => $a, '{b}' => $t['type'][$style[1]]['name']]) : $a;
}

/** What the candidate is shown and emailed: positive, nothing about suitability. */
function disc_candidate_view(string $style, string $lang): array
{
    $lang = $lang === 'zh' ? 'zh' : 'en';
    $t = disc_text()[$lang]['type'];
    $p = $t[$style[0]];
    $desc = $p['desc'] . (strlen($style) === 2 ? ' ' . $t[$style[1]]['also'] : '');
    return ['style' => $style, 'label' => disc_label($style, $lang), 'tag' => $p['tag'], 'desc' => $desc, 'strengths' => $p['strengths'], 'work' => $p['work'], 'tips' => $p['tips']];
}

const DISC_LABELS = [
    'en' => ['scores' => 'Scores', 'talk' => 'How to talk to them', 'pressure' => 'Under pressure', 'role' => 'For an advisory role', 'questions' => 'Interview questions',
        'flags' => 'Check these', 'align' => 'Compared with Career Code', 'also' => 'Also shows', 'style' => 'Style', 'done' => 'Completed', 'mode' => 'How', 'live' => 'In the room', 'email' => 'Invite link'],
    'zh' => ['scores' => '分数', 'talk' => '如何与对方沟通', 'pressure' => '压力下', 'role' => '作为顾问', 'questions' => '面谈问题',
        'flags' => '请留意', 'align' => '与职业代码对照', 'also' => '同时具备', 'style' => '风格', 'done' => '完成时间', 'mode' => '方式', 'live' => '现场进行', 'email' => '邀请链接'],
];

/** Everything an advisor sees for one finished DISC (only ever sent to someone who may see the candidate). */
function disc_advisor_view(array $row, ?string $hollandCode, string $lang): array
{
    $lang = $lang === 'zh' ? 'zh' : 'en';
    $t = disc_text()[$lang];
    $scores = json_decode((string)$row['scores_json'], true);
    $levels = disc_levels($scores);
    $style = (string)$row['style'];
    $p = $t['type'][$style[0]];
    $flags = [];
    foreach (json_decode((string)($row['flags_json'] ?? '[]'), true) ?: [] as $f) {
        $flags[] = strtr($t['ui']['flag'][$f['key']], ['{n}' => (string)($f['n'] ?? '')]);
    }
    $align = disc_alignment($style, $hollandCode);
    $rows = [];
    foreach (DISC_TYPES as $ty) {
        $rows[] = ['type' => $ty, 'name' => $t['type'][$ty]['name'], 'score' => $scores[$ty], 'level' => $t['ui']['level'][$levels[$ty]], 'level_key' => $levels[$ty]];
    }
    return [
        'style' => $style, 'label' => disc_label($style, $lang), 'tag' => $p['tag'], 'rows' => $rows,
        'talk' => $p['talk'], 'pressure' => $p['pressure'], 'role' => $p['role'], 'questions' => $p['questions'],
        'also' => strlen($style) === 2 ? $t['type'][$style[1]]['also'] : null,
        'flags' => $flags, 'align' => ['key' => $align, 'text' => strtr($t['ui']['align'][$align], ['{code}' => (string)$hollandCode, '{style}' => $style])],
        'created_at' => iso((string)$row['created_at']), 'labels' => DISC_LABELS[$lang], 'lang' => $lang,
        'duration_sec' => $row['duration_sec'] === null ? null : (int)$row['duration_sec'],
    ];
}

/* ------------------------------------------------------------------------------------------------ invites */

/** @return array<int, array{invite:?array, result:?array}> latest invite and latest result per candidate */
function disc_latest(PDO $pdo, array $candidateIds): array
{
    $out = [];
    foreach ($candidateIds as $id) {
        $out[(int)$id] = ['invite' => null, 'result' => null];
    }
    if (!$out) {
        return $out;
    }
    $in = implode(',', array_map('intval', array_keys($out)));
    try {
        foreach ($pdo->query("SELECT * FROM disc_invites WHERE id IN (SELECT MAX(id) FROM disc_invites WHERE candidate_id IN ($in) GROUP BY candidate_id)") as $r) {
            $out[(int)$r['candidate_id']]['invite'] = $r;
        }
        foreach ($pdo->query("SELECT * FROM disc_results WHERE id IN (SELECT MAX(id) FROM disc_results WHERE candidate_id IN ($in) GROUP BY candidate_id)") as $r) {
            $out[(int)$r['candidate_id']]['result'] = $r;
        }
    } catch (PDOException $e) {
        error_log('disc tables missing? ' . $e->getMessage());   // before schema_phase7.sql has been run: the candidate list keeps working, just without DISC tags
    }
    return $out;
}

function disc_ts(?string $utc): ?int
{
    return $utc === null ? null : (int)strtotime($utc . ' UTC');
}

/**
 * The DISC tag and what the advisor may do, from the career result, the follow-up status and the invite history.
 * tag: ready | invited | overdue | expired | done | null.   Not suitable / Joined switch off invites and reminders.
 * @return array{tag:?string, can_invite:bool, eligible:bool}
 */
function disc_state(?string $verdictKey, bool $completed, string $status, ?array $invite, ?array $result, ?int $now = null): array
{
    $now ??= time();
    $eligible = $completed && in_array($verdictKey, DISC_ELIGIBLE_VERDICTS, true);
    $closed = in_array($status, DISC_CLOSED_STATUSES, true);
    $pending = $invite !== null && $invite['completed_at'] === null;
    $tag = null;
    if ($pending) {
        if (!$closed) {
            if (disc_ts($invite['expires_at']) <= $now) {
                $tag = 'expired';
            } else {
                $since = disc_ts($invite['last_sent_at'] ?? null) ?? disc_ts($invite['created_at']);
                $tag = $now - $since >= DISC_REMIND_DAYS * 86400 ? 'overdue' : 'invited';
            }
        }
    } elseif ($result !== null) {
        $tag = 'done';
    } elseif ($eligible && !$closed) {
        $tag = 'ready';
    }
    return ['tag' => $tag, 'can_invite' => $eligible && !$closed, 'eligible' => $eligible];
}

function disc_link(array $config, string $token): string
{
    return rtrim((string)($config['base_url'] ?? 'https://dreamhub-e3.com'), '/') . '/career-code/disc/?t=' . $token;
}

/** A pending invite that has not expired, if any. */
function disc_active_invite(PDO $pdo, int $candidateId, ?int $now = null): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM disc_invites WHERE candidate_id = ? AND completed_at IS NULL ORDER BY id DESC LIMIT 1');
    $stmt->execute([$candidateId]);
    $r = $stmt->fetch();
    return $r && disc_ts($r['expires_at']) > ($now ?? time()) ? $r : null;
}

function disc_candidate_ctx(PDO $pdo, int $candidateId): array
{
    $stmt = $pdo->prepare('SELECT c.*, a.code AS a_code, a.scores_json AS a_scores FROM candidates c
        LEFT JOIN assessments a ON a.id = (SELECT MAX(id) FROM assessments WHERE candidate_id = c.id) WHERE c.id = ?');
    $stmt->execute([$candidateId]);
    $c = $stmt->fetch();
    if (!$c) {
        throw new HttpError(404, 'Candidate not found.');
    }
    $verdict = $c['a_scores'] ? assess_candidate(json_decode((string)$c['a_scores'], true), 'en')['verdict']['key'] : null;
    $latest = disc_latest($pdo, [$candidateId])[$candidateId];
    return [$c, disc_state($verdict, $c['completed_at'] !== null, (string)$c['pipeline_status'], $latest['invite'], $latest['result']), $latest];
}

/**
 * Advisor actions. $action: invite | remind | reinvite | live. $channel (invite/reinvite): email | link.
 * Returns the current invite, its link and whether an email went out. Mail trouble never loses the invite.
 * @return array{invite:array, link:string, emailed:bool, created:bool}
 */
function disc_advisor_action(PDO $pdo, array $config, Mailer $mailer, array $actor, string $action, int $candidateId, string $channel = 'email', ?int $now = null): array
{
    $now ??= time();
    [$cand, $state] = disc_candidate_ctx($pdo, $candidateId);
    if (!in_array($action, ['invite', 'remind', 'reinvite', 'live'], true)) {
        throw new HttpError(400, 'Unknown action.');
    }
    if (!$state['can_invite']) {
        throw new HttpError(409, $state['eligible'] ? 'This candidate is marked ' . ($cand['pipeline_status'] === 'joined' ? 'Joined' : 'Not suitable') . ', so no DISC invite can be sent.' : 'This candidate is not ready for the DISC step.');
    }
    $withEmail = $channel === 'email';
    if ($withEmail && $action !== 'live' && empty($cand['email'])) {
        throw new HttpError(422, 'This candidate has no email address. Use Copy link and send it yourself.');
    }
    $active = disc_active_invite($pdo, $candidateId, $now);
    $created = false;
    if ($action === 'remind' && $active === null) {
        throw new HttpError(409, 'There is no open invite to remind. Use Re-invite.');
    }
    if ($action === 'invite' && $active !== null) {
        $action = 'remind';          // already invited: the same link is simply sent again
    }
    if ($action === 'live' || $action === 'reinvite' || ($action === 'invite' && $active === null)) {
        $pdo->prepare('UPDATE disc_invites SET expires_at = ? WHERE candidate_id = ? AND completed_at IS NULL AND expires_at > ?')
            ->execute([gmdate('Y-m-d H:i:s', $now), $candidateId, gmdate('Y-m-d H:i:s', $now)]);
        $mode = $action === 'live' ? 'live' : 'email';
        $exp = $mode === 'live' ? $now + DISC_LIVE_EXPIRY_HOURS * 3600 : $now + DISC_EXPIRY_DAYS * 86400;
        $pdo->prepare('INSERT INTO disc_invites (candidate_id, token, mode, created_by_member_id, created_at, expires_at) VALUES (?,?,?,?,?,?)')
            ->execute([$candidateId, bin2hex(random_bytes(32)), $mode, (int)$actor['id'], gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $exp)]);
        $created = true;
        $stmt = $pdo->prepare('SELECT * FROM disc_invites WHERE id = ?');
        $stmt->execute([(int)$pdo->lastInsertId()]);
        $inv = $stmt->fetch();
        audit($pdo, (int)$actor['id'], $mode === 'live' ? 'disc.live' : 'disc.invite', $candidateId, ['channel' => $channel]);
    } else {
        $inv = $active;
        audit($pdo, (int)$actor['id'], 'disc.remind', $candidateId, []);
    }

    $emailed = false;
    if ($withEmail && $inv['mode'] === 'email') {
        try {
            $m = build_disc_invite_email($cand, disc_link($config, $inv['token']), !$created, $actor, $config, (string)$inv['expires_at']);
            $mailer->send($cand['email'], $m['subject'], $m['html'], $m['text']);
            $emailed = true;
            $pdo->prepare('UPDATE disc_invites SET last_sent_at = ?, sent_count = sent_count + 1 WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', $now), (int)$inv['id']]);
            $inv['last_sent_at'] = gmdate('Y-m-d H:i:s', $now);
        } catch (Throwable $e) {
            error_log('disc invite email failed: ' . $e->getMessage());
        }
    }
    return ['invite' => $inv, 'link' => disc_link($config, $inv['token']), 'emailed' => $emailed, 'created' => $created];
}

/* ------------------------------------------------------------------------------------------------ the candidate's side */

function disc_find_invite(PDO $pdo, string $token): array
{
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        throw new HttpError(404, 'This link is not valid.');
    }
    $stmt = $pdo->prepare('SELECT i.*, c.name AS c_name, c.mobile AS c_mobile, c.lang AS c_lang, c.email AS c_email FROM disc_invites i JOIN candidates c ON c.id = i.candidate_id WHERE i.token = ?');
    $stmt->execute([$token]);
    $r = $stmt->fetch();
    if (!$r) {
        throw new HttpError(404, 'This link is not valid.');
    }
    return $r;
}

function disc_first_name(string $name): string
{
    $n = trim(preg_split('/\s+/', trim($name))[0] ?? '');
    return $n === '' ? 'you' : $n;
}

/** What the first screen needs, before any digits are entered. */
function disc_public_info(PDO $pdo, string $token, ?int $now = null): array
{
    $now ??= time();
    $i = disc_find_invite($pdo, $token);
    $state = $i['completed_at'] !== null ? 'done' : (disc_ts($i['expires_at']) <= $now ? 'expired' : ((int)$i['wrong_tries'] >= DISC_MAX_WRONG && $i['mode'] === 'email' ? 'locked' : 'ok'));
    return ['first_name' => disc_first_name((string)$i['c_name']), 'lang' => $i['c_lang'], 'needs_digits' => $i['mode'] === 'email', 'state' => $state];
}

function disc_last4(string $mobile): string
{
    return substr(preg_replace('/\D/', '', $mobile) ?? '', -4);
}

/** Is this link still usable, and (for emailed links) are these the right last 4 digits? Throws the message the candidate sees. */
function disc_gate(PDO $pdo, array $i, string $last4, int $now): void
{
    if ($i['completed_at'] !== null) {
        throw new HttpError(410, 'This questionnaire has already been completed. Thank you.');
    }
    if (disc_ts($i['expires_at']) <= $now) {
        throw new HttpError(410, 'This link has expired. Please ask your advisor for a new one.');
    }
    if ($i['mode'] === 'email') {
        if ((int)$i['wrong_tries'] >= DISC_MAX_WRONG) {
            throw new HttpError(423, 'Too many wrong attempts. Please ask your advisor to send a new link.');
        }
        if (!hash_equals(disc_last4((string)$i['c_mobile']), trim($last4))) {
            $pdo->prepare('UPDATE disc_invites SET wrong_tries = wrong_tries + 1 WHERE id = ?')->execute([(int)$i['id']]);
            $left = DISC_MAX_WRONG - 1 - (int)$i['wrong_tries'];
            throw new HttpError(403, $left > 0 ? "Those digits do not match. $left tries left." : 'Too many wrong attempts. Please ask your advisor to send a new link.');
        }
    }
}

/** Checked on the first screen so a typo is found before the 28 questions, not after. */
function disc_verify(PDO $pdo, string $token, string $last4, ?int $now = null): void
{
    disc_gate($pdo, disc_find_invite($pdo, $token), $last4, $now ?? time());
}

/**
 * @return array{result_id:int, style:string, view:array, cand:array, invite:array}
 */
function disc_submit(PDO $pdo, string $token, string $last4, bool $consent, mixed $answers, ?int $duration, string $lang, ?int $now = null): array
{
    $now ??= time();
    $i = disc_find_invite($pdo, $token);
    disc_gate($pdo, $i, $last4, $now);
    if (!$consent) {
        throw new HttpError(422, 'Consent is required to continue.');
    }
    $s = score_disc($answers);
    $style = disc_style($s['scores']);
    $flags = disc_flags($s['most'], $s['least'], $s['scores'], $duration);
    $lang = $lang === 'zh' ? 'zh' : 'en';
    $when = gmdate('Y-m-d H:i:s', $now);
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare('UPDATE disc_invites SET completed_at = ? WHERE id = ? AND completed_at IS NULL');
        $claim->execute([$when, (int)$i['id']]);
        if ($claim->rowCount() !== 1) {             // a double tap or two tabs: only one wins
            throw new HttpError(410, 'This questionnaire has already been completed. Thank you.');
        }
        $pdo->prepare('INSERT INTO disc_results (candidate_id, invite_id, answers_json, scores_json, style, lang, duration_sec, flags_json, consent_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([(int)$i['candidate_id'], (int)$i['id'], json_encode($answers), json_encode($s['scores']), $style, $lang, $duration, json_encode($flags), $when, $when]);
        $rid = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $stmt = $pdo->prepare('SELECT * FROM candidates WHERE id = ?');      // read AFTER saving: the owner at this moment gets the news
    $stmt->execute([(int)$i['candidate_id']]);
    return ['result_id' => $rid, 'style' => $style, 'view' => disc_candidate_view($style, $lang), 'cand' => $stmt->fetch(), 'invite' => $i, 'lang' => $lang];
}

/**
 * Result to the candidate (if they have an email), notice to the advisor chain (link only). Never throws.
 * The advisor who ran an in-the-room session is not emailed about it.
 */
function notify_disc_completion(PDO $pdo, array $config, Mailer $mailer, array $done): array
{
    $out = ['candidate_sent' => false, 'notified' => 0];
    $cand = $done['cand'];
    $map = load_member_map($pdo);
    $owner = $map[(int)$cand['owner_member_id']] ?? null;
    if ($owner === null) {
        return $out;
    }
    if (!empty($cand['email'])) {
        try {
            $m = build_disc_candidate_email($cand, $done['view'], $done['lang'], $config);
            $mailer->send($cand['email'], $m['subject'], $m['html'], $m['text']);
            $out['candidate_sent'] = true;
        } catch (Throwable $e) {
            error_log('disc candidate email failed: ' . $e->getMessage());
        }
    }
    $skip = $done['invite']['mode'] === 'live' ? (int)$done['invite']['created_by_member_id'] : 0;
    $sent = [];
    foreach (upline_chain($map, (int)$owner['id']) as $mid) {
        $r = $map[$mid];
        if (!$r['is_active'] || isset($sent[$r['email']]) || $mid === $skip) {
            continue;
        }
        try {
            $m = build_disc_advisor_notice($cand, $done['style'], $owner, $r, $config);
            $mailer->send($r['email'], $m['subject'], $m['html'], $m['text']);
            $sent[$r['email']] = true;
            $out['notified']++;
        } catch (Throwable $e) {
            error_log('disc advisor notice failed: ' . $e->getMessage());
        }
    }
    return $out;
}

/** The DISC block on a candidate's page: state, the open invite (with its link) and, when finished, the advisor's view. */
function disc_detail(array $config, array $cand, array $latest, string $lang, ?int $now = null): array
{
    $verdict = !empty($cand['a_scores']) ? assess_candidate(json_decode((string)$cand['a_scores'], true), 'en')['verdict']['key'] : null;
    $st = disc_state($verdict, $cand['completed_at'] !== null, (string)$cand['pipeline_status'], $latest['invite'], $latest['result'], $now);
    $inv = $latest['invite'];
    $open = $inv !== null && $inv['completed_at'] === null && disc_ts($inv['expires_at']) > ($now ?? time());
    return [
        'tag' => $st['tag'], 'can_invite' => $st['can_invite'], 'eligible' => $st['eligible'], 'has_email' => !empty($cand['email']),
        'closed' => in_array($cand['pipeline_status'], DISC_CLOSED_STATUSES, true),
        'invite' => $inv === null || $inv['completed_at'] !== null ? null : [
            'mode' => $inv['mode'], 'created_at' => iso($inv['created_at']), 'expires_at' => iso($inv['expires_at']), 'last_sent_at' => iso($inv['last_sent_at'] ?? null),
            'sent_count' => (int)$inv['sent_count'], 'open' => $open, 'link' => $open ? disc_link($config, $inv['token']) : null,
        ],
        'result' => $latest['result'] === null ? null : disc_advisor_view($latest['result'], $cand['a_code'] ?? null, $lang),
    ];
}
