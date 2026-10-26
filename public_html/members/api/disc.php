<?php
declare(strict_types=1);

/**
 * DISC invites (members only; every call is checked against the upline tree).
 *   POST {action:'invite'|'remind'|'reinvite'|'live', candidate_id, channel?:'email'|'link'}
 *        -> { disc:{...same block as the candidate page}, link, emailed }
 * invite/reinvite/remind with channel 'email' send the link by email; 'link' only creates it so the advisor can WhatsApp it.
 * 'live' makes a one-time link for an in-the-room session (no email, no mobile-digits check, valid 24 hours).
 */
require_once __DIR__ . '/../../../private/bootstrap.php';
require_once __DIR__ . '/../../../private/lib/candidate_access.php';

[$config, $pdo, $me] = boot_member_request();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new HttpError(405, 'Method not allowed.');
    }
    $in = json_body();
    $id = (int)($in['candidate_id'] ?? 0);
    $map = load_member_map($pdo);
    $row = load_candidate_row($pdo, $id);
    if (!$row || !can_see_candidate($map, $me, (int)$row['owner_member_id'])) {
        throw new HttpError(404, 'Candidate not found.');
    }
    $r = disc_advisor_action($pdo, $config, make_mailer($config), $me, (string)($in['action'] ?? ''), $id, ($in['channel'] ?? 'email') === 'link' ? 'link' : 'email');
    $c = get_candidate($pdo, $map, $me, $id, 'en', $config);
    json_response(200, ['disc' => $c['disc'], 'link' => $r['link'], 'emailed' => $r['emailed'], 'created' => $r['created'], 'status' => $c['status']]);
} catch (HttpError $e) {
    json_response($e->status, ['error' => $e->getMessage()] + $e->data);
} catch (Throwable $e) {
    error_log('disc.php: ' . $e->getMessage());
    json_response(500, ['error' => 'Server error.']);
}
