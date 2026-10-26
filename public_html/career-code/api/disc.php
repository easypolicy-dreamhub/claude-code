<?php
declare(strict_types=1);

/**
 * The candidate's side of DISC (no login: the secret link code is the key, plus the last 4 digits of their mobile).
 *   POST {action:'info',   t}                                                -> { first_name, lang, needs_digits, state: ok|expired|done|locked }
 *   POST {action:'verify', t, last4?}                                         -> { ok:true }  (403 with tries left when the digits are wrong)
 *   POST {action:'submit', t, last4?, consent:true, answers:[{most,least}x28], duration_sec?, lang} -> { view, candidate_emailed }
 */
require_once __DIR__ . '/../../../private/bootstrap_public.php';
require_once __DIR__ . '/../../../private/lib/disc.php';

[$config, $pdo] = boot_public_request();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new HttpError(405, 'Method not allowed.');
    }
    $in = json_body();
    $token = (string)($in['t'] ?? '');
    $action = (string)($in['action'] ?? '');
    if ($action === 'info') {
        json_response(200, disc_public_info($pdo, $token));
    }
    if ($action === 'verify') {
        disc_verify($pdo, $token, (string)($in['last4'] ?? ''));
        json_response(200, ['ok' => true]);
    }
    if ($action === 'submit') {
        $duration = isset($in['duration_sec']) && is_int($in['duration_sec']) && $in['duration_sec'] >= 0 ? min($in['duration_sec'], 86400) : null;
        $done = disc_submit($pdo, $token, (string)($in['last4'] ?? ''), ($in['consent'] ?? false) === true, $in['answers'] ?? null, $duration, (string)($in['lang'] ?? 'en'));
        $sent = notify_disc_completion($pdo, $config, make_mailer($config), $done);
        json_response(200, ['view' => $done['view'], 'candidate_emailed' => $sent['candidate_sent']]);
    }
    throw new HttpError(400, 'Unknown action.');
} catch (HttpError $e) {
    json_response($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('career-code disc: ' . $e->getMessage());
    json_response(500, ['error' => 'Server error.']);
}
