<?php
declare(strict_types=1);

/**
 * Advisor live mode (members only, any active member).
 *   POST {action:'start',  client_id?, name, mobile, email?, age?, edu?, status?, field?, lang, purpose, consent:true}
 *        -> { submit_token, candidate_id, advisor_name, existing, completed }
 *   POST {action:'submit', submit_token, answers}            -> { scores, code, candidate_id }  (server scores it)
 *   POST {action:'manual', client_id?, <details as above>, scores:{R:{a,b,c,d},...}, confirm_d?}
 *        -> { candidate_id, code, scores, already }
 * The candidate is owned by the advisor running the session; uplines are told by email like any other completion.
 */
require_once __DIR__ . '/../../../private/bootstrap.php';
require_once __DIR__ . '/../../../private/lib/live.php';

[$config, $pdo, $me] = boot_member_request();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new HttpError(405, 'Method not allowed.');
    }
    $in = json_body();
    $action = (string)($in['action'] ?? '');
    if ($action === 'start') {
        $r = live_start($pdo, $config, $me, $in);
        audit($pdo, (int)$me['id'], 'live.start', $r['candidate_id'], ['existing' => $r['existing']]);
        json_response(200, $r);
    }
    if ($action === 'submit') {
        $r = submit_assessment($pdo, (string)($in['submit_token'] ?? ''), $in['answers'] ?? null);
        if (!$r['already']) {
            $sent = notify_completion($pdo, $config, make_mailer($config), $r['candidate'], $r['scores'], $r['code'], (int)$me['id']);
        }
        json_response(200, ['scores' => $r['scores'], 'code' => $r['code'], 'candidate_id' => (int)$r['candidate']['id'], 'advisor_name' => $r['advisor_name'],
            'candidate_emailed' => $sent['candidate_sent'] ?? false]);
    }
    if ($action === 'manual') {
        $r = live_manual($pdo, $config, $me, $in);
        $sent = ['candidate_sent' => false];
        if (!$r['already']) {
            audit($pdo, (int)$me['id'], 'live.manual', $r['candidate_id'], []);
            $sent = notify_completion($pdo, $config, make_mailer($config), $r['cand'], $r['scores'], $r['code'], (int)$me['id']);
        }
        json_response(200, ['candidate_id' => $r['candidate_id'], 'code' => $r['code'], 'scores' => $r['scores'], 'already' => $r['already'],
            'candidate_emailed' => $sent['candidate_sent']]);
    }
    throw new HttpError(400, 'Unknown action.');
} catch (HttpError $e) {
    json_response($e->status, ['error' => $e->getMessage()] + $e->data);
} catch (Throwable $e) {
    error_log('live.php: ' . $e->getMessage());
    json_response(500, ['error' => 'Server error.']);
}
