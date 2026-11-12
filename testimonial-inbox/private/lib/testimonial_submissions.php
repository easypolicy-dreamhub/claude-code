<?php
declare(strict_types=1);

/**
 * Testimonials that clients send through the public form (/share-testimonial/).
 * They wait in `testimonial_submissions` as "pending". Nothing here is public.
 * Approving one copies it into `testimonials` through testi_create(), so the existing admin list,
 * the "shown" switch, the consent rule and the audit log all keep working as before.
 */
require_once __DIR__ . '/testimonials.php';

const TSUB_PER_IP_PER_HOUR = 5;      // one person sending more than this in an hour is almost surely a bot
const TSUB_PER_DAY = 100;            // overall safety cap so the inbox can never be flooded
const TSUB_MIN_SECONDS = 4;          // a human cannot read and fill the form faster than this

function tsub_now(): string
{
    return date('Y-m-d H:i:s');
}

function tsub_ip_hash(): string
{
    // client_ip() comes from bootstrap_public.php (the address as Cloudflare saw it).
    $ip = function_exists('client_ip') ? (string)client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return hash('sha256', 'tsub|' . $ip);
}

/**
 * Checks what the public form sent. Uses the same rules as the admin form (testi_validate), so anything
 * accepted here can later be approved without changes.
 * @return array{client_name:string, area:string, rating:int, message:string, phone:?string}
 */
function tsub_validate_public(array $in): array
{
    if (($in['consent'] ?? false) !== true) {
        throw new HttpError(422, 'Please tick the box so we can publish your testimonial.');
    }
    try {
        $clean = testi_validate([
            'message' => $in['message'] ?? '', 'client_name' => $in['client_name'] ?? '', 'area' => $in['area'] ?? '',
            'rating' => $in['rating'] ?? null, 'consent_confirmed' => true, 'is_visible' => false, 'on_homepage' => false,
        ]);
    } catch (HttpError $e) {
        // The admin wording ("Enter the client name.") speaks to the owner; say it to the client instead.
        $map = [
            'Enter the testimonial message.' => 'Please write your testimonial.',
            'Enter the client name.' => 'Please enter your name.',
            'Choose a star rating from 1 to 5.' => 'Please choose a star rating.',
        ];
        $msg = $e->getMessage();
        if (str_starts_with($msg, 'Enter the area')) {
            $msg = 'Please tell us your area or occupation.';
        }
        throw new HttpError($e->status, $map[$msg] ?? $msg);
    }
    $phone = testi_line($in['phone'] ?? '');
    if ($phone !== '' && preg_match('/^[0-9+()\- ]{6,30}$/', $phone) !== 1) {
        throw new HttpError(422, 'Please check your phone number, or leave it empty.');
    }
    return ['client_name' => $clean['client_name'], 'area' => $clean['area'], 'rating' => $clean['rating'],
        'message' => $clean['message'], 'phone' => $phone === '' ? null : $phone];
}

/**
 * Saves a client's testimonial as pending. Returns the new id, or 0 when it was quietly dropped
 * (spam trap, or the same person sending the same text twice). The client sees "Thank you" either way.
 */
function tsub_submit(PDO $pdo, array $in): int
{
    // Spam traps: a hidden field real people never fill, and a form "filled" impossibly fast.
    if (testi_line($in['website'] ?? '') !== '') {
        return 0;
    }
    $elapsed = $in['elapsed'] ?? null;
    if (is_int($elapsed) && $elapsed < TSUB_MIN_SECONDS) {
        return 0;
    }
    $clean = tsub_validate_public($in);
    $ip = tsub_ip_hash();
    $now = time();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM testimonial_submissions WHERE ip_hash = ? AND created_at >= ?');
    $stmt->execute([$ip, date('Y-m-d H:i:s', $now - 3600)]);
    if ((int)$stmt->fetchColumn() >= TSUB_PER_IP_PER_HOUR) {
        throw new HttpError(429, 'We have already received your testimonial. Thank you!');
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM testimonial_submissions WHERE created_at >= ?');
    $stmt->execute([date('Y-m-d H:i:s', $now - 86400)]);
    if ((int)$stmt->fetchColumn() >= TSUB_PER_DAY) {
        throw new HttpError(429, 'We are receiving a lot of messages right now. Please try again tomorrow.');
    }
    // Double tap on Submit, or the same text sent again: keep one copy.
    $stmt = $pdo->prepare('SELECT 1 FROM testimonial_submissions WHERE ip_hash = ? AND message = ? AND created_at >= ?');
    $stmt->execute([$ip, $clean['message'], date('Y-m-d H:i:s', $now - 86400)]);
    if ($stmt->fetchColumn()) {
        return 0;
    }

    $pdo->prepare('INSERT INTO testimonial_submissions (client_name, area, rating, message, phone, status, ip_hash, created_at)
                   VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$clean['client_name'], $clean['area'], $clean['rating'], $clean['message'], $clean['phone'], 'pending', $ip, tsub_now()]);
    $id = (int)$pdo->lastInsertId();
    testi_audit($pdo, null, 'testimonial_submitted', $id, $clean['client_name']);
    return $id;
}

/**
 * Emails the owner that a new testimonial is waiting. Never throws: a mail problem must not lose the testimonial.
 * The address is $config['testimonial_notify_email'] if set, otherwise the contact email in the site settings.
 * @param callable|null $send for tests; defaults to PHP mail()
 */
function tsub_notify_admin(PDO $pdo, array $config, int $id, ?callable $send = null): bool
{
    try {
        $to = trim((string)($config['testimonial_notify_email'] ?? ''));
        if ($to === '' && function_exists('site_settings_get')) {
            $to = trim((string)(site_settings_get($pdo, $config)['email'] ?? ''));
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log('testimonial notify: no valid email address to send to');
            return false;
        }
        $stmt = $pdo->prepare('SELECT * FROM testimonial_submissions WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) {
            return false;
        }
        $host = strtolower(preg_replace('/[^A-Za-z0-9.\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?? '');
        $site = $host !== '' ? 'https://' . $host : '';
        $domain = preg_replace('/^www\./', '', $host) ?: 'localhost';
        $name = testi_line($r['client_name']);
        $subject = 'New testimonial from ' . $name . ' is waiting for your approval';
        $body = "A client has sent a testimonial through your website.\n\n"
            . 'Name: ' . $name . "\n"
            . 'Area: ' . testi_line($r['area']) . "\n"
            . 'Rating: ' . (int)$r['rating'] . " out of 5\n"
            . 'Phone given: ' . ($r['phone'] ? 'yes (they would like a WhatsApp when it is published)' : 'no') . "\n\n"
            . "Testimonial:\n" . (string)$r['message'] . "\n\n"
            . "Nothing is on the website yet. Approve or reject it here:\n" . $site . "/members/testimonials/\n";
        $headers = 'From: DreamHub E3 Website <noreply@' . $domain . ">\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
        $send ??= 'mail';
        $ok = (bool)$send($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, $headers);
        if (!$ok) {
            error_log('testimonial notify: mail() returned false');
        }
        return $ok;
    } catch (Throwable $e) {
        error_log('testimonial notify: ' . $e->getMessage());
        return false;
    }
}

/* ---- Admin side. Call these only from admin code that has already checked the admin is logged in. ---- */

/** @return array<string, mixed> */
function tsub_row(array $r): array
{
    return [
        'id' => (int)$r['id'], 'client_name' => (string)$r['client_name'], 'area' => (string)$r['area'],
        'rating' => (int)$r['rating'], 'message' => (string)$r['message'], 'phone' => $r['phone'] === null ? null : (string)$r['phone'],
        'status' => (string)$r['status'], 'testimonial_id' => $r['testimonial_id'] === null ? null : (int)$r['testimonial_id'],
        'created_at' => (string)$r['created_at'],
    ];
}

/** Pending ones, newest first. */
function tsub_list_pending(PDO $pdo): array
{
    return array_map('tsub_row', $pdo->query("SELECT * FROM testimonial_submissions WHERE status = 'pending' ORDER BY created_at DESC, id DESC LIMIT 200")->fetchAll());
}

function tsub_count_pending(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM testimonial_submissions WHERE status = 'pending'")->fetchColumn();
}

/**
 * Approves a pending submission: creates the testimonial (with the admin's corrections, if any) and marks the
 * submission approved. $in may carry edited message / client_name / area / rating, and is_visible / on_homepage.
 * The client ticked consent on the form, so consent_confirmed is always true.
 * @return int the new testimonials.id
 */
function tsub_approve(PDO $pdo, int $id, array $in, int $actorId): int
{
    $stmt = $pdo->prepare("SELECT * FROM testimonial_submissions WHERE id = ? AND status = 'pending'");
    $stmt->execute([$id]);
    $sub = $stmt->fetch();
    if (!$sub) {
        throw new HttpError(404, 'That submission was already handled. Reload the page.');
    }
    $clean = testi_validate([
        'message' => $in['message'] ?? $sub['message'], 'client_name' => $in['client_name'] ?? $sub['client_name'],
        'area' => $in['area'] ?? $sub['area'], 'rating' => $in['rating'] ?? (int)$sub['rating'],
        'consent_confirmed' => true, 'is_visible' => ($in['is_visible'] ?? true) === true, 'on_homepage' => ($in['on_homepage'] ?? false) === true,
    ]);
    // Claim it first so two clicks (or two admins) cannot create it twice.
    $claim = $pdo->prepare("UPDATE testimonial_submissions SET status = 'approved', reviewed_at = ?, reviewed_by = ? WHERE id = ? AND status = 'pending'");
    $claim->execute([tsub_now(), $actorId, $id]);
    if ($claim->rowCount() !== 1) {
        throw new HttpError(409, 'That submission was already handled. Reload the page.');
    }
    try {
        $tid = testi_create($pdo, $clean, $actorId);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE testimonial_submissions SET status = 'pending', reviewed_at = NULL, reviewed_by = NULL WHERE id = ?")->execute([$id]);
        throw $e;
    }
    $pdo->prepare('UPDATE testimonial_submissions SET testimonial_id = ? WHERE id = ?')->execute([$tid, $id]);
    return $tid;
}

function tsub_reject(PDO $pdo, int $id, int $actorId): void
{
    $stmt = $pdo->prepare("UPDATE testimonial_submissions SET status = 'rejected', reviewed_at = ?, reviewed_by = ? WHERE id = ? AND status = 'pending'");
    $stmt->execute([tsub_now(), $actorId, $id]);
    if ($stmt->rowCount() !== 1) {
        throw new HttpError(404, 'That submission was already handled. Reload the page.');
    }
    testi_audit($pdo, $actorId, 'testimonial_submission_reject', $id, '');
}
