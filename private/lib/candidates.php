<?php
declare(strict_types=1);

require_once __DIR__ . '/scoring.php';
require_once __DIR__ . '/tree.php';
require_once __DIR__ . '/members.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/emails.php';

const CONSENT_VERSION = '2026-10-v1';
const SUBMIT_WINDOW_SECONDS = 86400;   // a started test may be finished within 24 hours
const PURPOSES = ['career', 'internship'];

/** Malaysian mobile (012-345 6789, +6012 345 6789, 6012...) -> +60123456789, else null. */
function normalise_mobile(string $raw): ?string
{
    $d = preg_replace('/[\s().-]+/', '', $raw) ?? '';
    return preg_match('/^(?:\+?60|0)(1\d{8,9})$/', $d, $m) === 1 ? '+60' . $m[1] : null;
}

function ip_hash(array $config, ?string $ip): ?string
{
    return $ip === null || $ip === '' ? null : hash('sha256', ($config['ip_salt'] ?? '') . '|' . $ip);
}

/** Who owns a new public candidate: the ?ref= agent if valid, otherwise the default owner (you). @return array{0:array,1:string} [member, source] */
function resolve_owner(PDO $pdo, array $config, ?string $ref): array
{
    if ($ref !== null && $ref !== '') {
        $stmt = $pdo->prepare('SELECT * FROM members WHERE referral_code = ? AND is_active = 1');
        $stmt->execute([strtolower(trim($ref))]);
        if ($m = $stmt->fetch()) {
            return [$m, 'referral'];     // a stale or mistyped link silently falls back below
        }
    }
    $email = strtolower((string)($config['default_owner_email'] ?? ''));
    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT * FROM members WHERE email = ? AND is_active = 1');
        $stmt->execute([$email]);
        if ($m = $stmt->fetch()) {
            return [$m, 'landing'];
        }
    }
    $m = $pdo->query('SELECT * FROM members WHERE is_admin = 1 AND is_active = 1 ORDER BY id LIMIT 1')->fetch();
    if (!$m) {
        throw new HttpError(500, 'No default advisor is configured.');
    }
    return [$m, 'landing'];
}

function opt_index(mixed $v, int $count, string $label): ?int
{
    if ($v === null || $v === '') {
        return null;
    }
    if (!is_numeric($v) || (int)$v < 0 || (int)$v >= $count || (string)(int)$v !== (string)$v) {
        throw new HttpError(422, "Invalid $label.");
    }
    return (int)$v;
}

/**
 * Step 1 of the public flow: details + consent -> a candidate row and a one-time token for submitting answers.
 * @return array{submit_token:string, advisor_name:string, candidate_id:int}
 */
function start_candidate(PDO $pdo, array $config, array $in, ?string $ip): array
{
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 120) {
        throw new HttpError(422, 'Enter your full name.');
    }
    $mobile = normalise_mobile((string)($in['mobile'] ?? ''));
    if ($mobile === null) {
        throw new HttpError(422, 'Enter a valid Malaysian mobile number.');
    }
    $email = strtolower(trim((string)($in['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        throw new HttpError(422, 'Enter a valid email address.');
    }
    if (($in['consent'] ?? false) !== true) {
        throw new HttpError(422, 'Consent is required to continue.');
    }
    $lang = ($in['lang'] ?? 'en') === 'zh' ? 'zh' : 'en';
    $purpose = (string)($in['purpose'] ?? 'career');
    if (!in_array($purpose, PURPOSES, true)) {
        throw new HttpError(422, 'Invalid purpose.');
    }
    $age = opt_index($in['age'] ?? null, 7, 'age group');
    $edu = opt_index($in['edu'] ?? null, 7, 'qualification');
    $status = opt_index($in['status'] ?? null, 5, 'status');
    $field = trim((string)($in['field'] ?? ''));
    if (mb_strlen($field) > 190) {
        throw new HttpError(422, 'Field is too long.');
    }
    $ref = isset($in['ref']) && is_string($in['ref']) ? substr($in['ref'], 0, 32) : null;

    $ipH = ip_hash($config, $ip);
    $limit = (int)($config['rate_limit_per_hour'] ?? 10);
    if ($ipH !== null) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM candidates WHERE ip_hash = ? AND started_at >= ?');
        $stmt->execute([$ipH, gmdate('Y-m-d H:i:s', time() - 3600)]);
        if ((int)$stmt->fetchColumn() >= $limit) {
            throw new HttpError(429, 'Too many attempts from this connection. Please try again later.');
        }
    }

    [$owner, $source] = resolve_owner($pdo, $config, $ref);
    $dupOf = null;
    $stmt = $pdo->prepare('SELECT id, owner_member_id, duplicate_of FROM candidates WHERE mobile = ? OR email = ? ORDER BY id LIMIT 1');
    $stmt->execute([$mobile, $email]);
    if ($first = $stmt->fetch()) {           // first advisor keeps the candidate (Fix 12)
        $dupOf = (int)($first['duplicate_of'] ?? $first['id']);
        $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ?');
        $stmt->execute([(int)$first['owner_member_id']]);
        $owner = $stmt->fetch() ?: $owner;
    }

    $token = bin2hex(random_bytes(32));
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO candidates (owner_member_id, purpose, source, name, mobile, email, age_group, education, work_status, field, lang,
            consent_at, consent_version, duplicate_of, submit_token_hash, ip_hash, started_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$owner['id'], $purpose, $source, $name, $mobile, $email, $age, $edu, $status, $field === '' ? null : $field, $lang,
            $now, CONSENT_VERSION, $dupOf, hash('sha256', $token), $ipH, $now]);
    return ['submit_token' => $token, 'advisor_name' => $owner['name'], 'candidate_id' => (int)$pdo->lastInsertId()];
}

/**
 * Step 2: answers -> server-side scores, saved once.
 * @return array{candidate:array, scores:array, code:string, advisor_name:string, already:bool}
 */
function submit_assessment(PDO $pdo, string $token, mixed $answers): array
{
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        throw new HttpError(404, 'This test link is not valid. Please start again.');
    }
    $stmt = $pdo->prepare('SELECT * FROM candidates WHERE submit_token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $cand = $stmt->fetch();
    if (!$cand) {
        throw new HttpError(404, 'This test link is not valid. Please start again.');
    }
    $owner = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $owner->execute([(int)$cand['owner_member_id']]);
    $owner = $owner->fetch();

    if ($cand['completed_at'] !== null) {     // double-tap or retry: return what was saved, send nothing again
        $a = $pdo->prepare('SELECT scores_json, code FROM assessments WHERE candidate_id = ? ORDER BY id DESC LIMIT 1');
        $a->execute([(int)$cand['id']]);
        $row = $a->fetch();
        return ['candidate' => $cand, 'scores' => json_decode($row['scores_json'], true), 'code' => $row['code'], 'advisor_name' => $owner['name'], 'already' => true];
    }
    if (strtotime($cand['started_at'] . ' UTC') < time() - SUBMIT_WINDOW_SECONDS) {
        throw new HttpError(410, 'This test has expired. Please start again.');
    }

    $scores = score_test($answers);
    $code = holland_code($scores);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO assessments (candidate_id, mode, answers_json, scores_json, code) VALUES (?,?,?,?,?)')
            ->execute([(int)$cand['id'], 'test', json_encode($answers), json_encode($scores), $code]);
        $pdo->prepare('UPDATE candidates SET completed_at = ? WHERE id = ? AND completed_at IS NULL')
            ->execute([gmdate('Y-m-d H:i:s'), (int)$cand['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['candidate' => $cand, 'scores' => $scores, 'code' => $code, 'advisor_name' => $owner['name'], 'already' => false];
}

/**
 * Sends the candidate their result and tells the advisor + uplines. Never throws: a mail problem must not
 * lose a candidate. Timestamps record what was actually sent so the dashboard can show "email not sent".
 * @return array{candidate_sent:bool, notified:int}
 */
function notify_completion(PDO $pdo, array $config, Mailer $mailer, array $cand, array $scores, string $code): array
{
    $result = ['candidate_sent' => false, 'notified' => 0];
    $map = load_member_map($pdo);
    $owner = $map[(int)$cand['owner_member_id']] ?? null;
    if ($owner === null) {
        return $result;
    }
    try {
        if (!empty($cand['email'])) {
            $m = build_candidate_email($cand, $scores, $owner, $config);
            $mailer->send($cand['email'], $m['subject'], $m['html'], $m['text']);
            $result['candidate_sent'] = true;
            $pdo->prepare('UPDATE candidates SET result_emailed_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), (int)$cand['id']]);
        }
    } catch (Throwable $e) {
        error_log('career-code: candidate email failed: ' . $e->getMessage());
    }

    $duplicate = !empty($cand['duplicate_of']);
    $sentTo = [];
    foreach (upline_chain($map, (int)$owner['id']) as $mid) {
        $r = $map[$mid];
        if (!$r['is_active'] || isset($sentTo[$r['email']])) {
            continue;
        }
        try {
            $m = build_advisor_notice($cand, $code, $owner, $r, $config, $duplicate);
            $mailer->send($r['email'], $m['subject'], $m['html'], $m['text']);
            $sentTo[$r['email']] = true;
            $result['notified']++;
        } catch (Throwable $e) {
            error_log('career-code: advisor notice failed: ' . $e->getMessage());
        }
    }
    if ($result['notified'] > 0) {
        $pdo->prepare('UPDATE candidates SET notified_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), (int)$cand['id']]);
    }
    return $result;
}
