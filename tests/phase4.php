<?php
declare(strict_types=1);

// Run: php tests/phase4.php   (advisor live mode + paper-score entry; no network or MySQL)
require_once __DIR__ . '/../private/lib/live.php';
require_once __DIR__ . '/../private/lib/candidate_access.php';
require_once __DIR__ . '/helpers.php';

$pdo = fresh_db();
$me = create_member($pdo, null, ['email' => 'you@x.com', 'name' => 'You', 'rank' => 'unit_manager', 'is_admin' => true, 'referral_code' => 'cf']);
$A  = create_member($pdo, $me['id'], ['email' => 'a@x.com', 'name' => 'Amy A', 'upline_id' => $me['id'], 'referral_code' => 'amy']);
$AA = create_member($pdo, $me['id'], ['email' => 'aa@x.com', 'name' => 'Ah Ah', 'upline_id' => $A['id'], 'referral_code' => 'aa']);
$B  = create_member($pdo, $me['id'], ['email' => 'b@x.com', 'name' => 'Bob B', 'upline_id' => $me['id'], 'referral_code' => 'bob']);
$cfg = ['default_owner_email' => 'you@x.com', 'ip_salt' => 's', 'rate_limit_per_hour' => 1, 'base_url' => 'https://dreamhub-e3.com'];
$cid = fn (string $s) => str_pad(dechex(crc32($s)), 32, '0');

echo "Live test: start\n";
$r = live_start($pdo, $cfg, $AA, valid_start(['client_id' => $cid('one'), 'email' => '']));
check('candidate belongs to the advisor running the session', (int)$pdo->query('SELECT owner_member_id FROM candidates WHERE id = ' . $r['candidate_id'])->fetchColumn() === $AA['id']);
check('source is live, email optional (stored NULL)', $pdo->query('SELECT source || "|" || COALESCE(email, "NULL") FROM candidates WHERE id = ' . $r['candidate_id'])->fetchColumn() === 'live|NULL');
check('conductor recorded as the link-used advisor', (int)$pdo->query('SELECT referred_by_member_id FROM candidates WHERE id = ' . $r['candidate_id'])->fetchColumn() === $AA['id']);
$again = live_start($pdo, $cfg, $AA, valid_start(['client_id' => $cid('one'), 'email' => '']));
check('same client_id (a retry) does not create a second candidate', $again['candidate_id'] === $r['candidate_id'] && $again['existing'] && (int)$pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn() === 1);
check('retry gets a fresh working token', $again['submit_token'] !== $r['submit_token']);
throws('old token no longer works after a retry', 404, fn () => submit_assessment($pdo, $r['submit_token'], sample_answers()));
throws('bad client_id rejected', 422, fn () => live_start($pdo, $cfg, $AA, valid_start(['client_id' => 'xyz'])));
throws('consent still required', 422, fn () => live_start($pdo, $cfg, $AA, valid_start(['consent' => false, 'mobile' => '011-1111 2222'])));
throws('a bad email is still refused when given', 422, fn () => live_start($pdo, $cfg, $AA, valid_start(['email' => 'nope', 'mobile' => '011-1111 2222'])));
for ($i = 0; $i < 4; $i++) { live_start($pdo, $cfg, $AA, valid_start(['mobile' => '011-2222 33' . (10 + $i), 'email' => "x$i@e.com"])); }
check('no IP rate limit for logged-in advisors', true);

echo "\nLive test: finish\n";
$s = submit_assessment($pdo, $again['submit_token'], sample_answers());
check('scored on the server, code ESC', $s['code'] === 'ESC' && !$s['already']);
$mail = new MemoryMailer();
$n = notify_completion($pdo, $cfg, $mail, $s['candidate'], $s['scores'], $s['code'], $AA['id']);
$to = array_column($mail->sent, 'to');
check('uplines told (Amy, You) but not the advisor in the room', sort($to) && $to === ['a@x.com', 'you@x.com'] && $n['notified'] === 2);
check('no candidate email when no address', $n['candidate_sent'] === false);

echo "\nPaper-score entry\n";
$paper = ['R' => ['a' => 0, 'b' => 0, 'c' => 0, 'd' => 2], 'I' => ['a' => 4, 'b' => 5, 'c' => 3, 'd' => 4], 'A' => ['a' => 3, 'b' => 1, 'c' => 0, 'd' => 7],
    'S' => ['a' => 7, 'b' => 6, 'c' => 4, 'd' => 13], 'E' => ['a' => 7, 'b' => 7, 'c' => 7, 'd' => 13], 'C' => ['a' => 4, 'b' => 4, 'c' => 1, 'd' => 9]];
$m = live_manual($pdo, $cfg, $A, valid_start(['name' => 'Paper Pat', 'mobile' => '016-777 8888', 'email' => 'pat@e.com', 'client_id' => $cid('paper'), 'scores' => $paper]));
check('paper scores give ESC like the photo', $m['code'] === 'ESC' && $m['scores']['E']['total'] === 34 && $m['scores']['S']['total'] === 30);
$row = $pdo->query('SELECT mode, entered_by_member_id FROM assessments WHERE candidate_id = ' . $m['candidate_id'])->fetch();
check('stored as manual, remembers who typed it', $row['mode'] === 'manual' && (int)$row['entered_by_member_id'] === $A['id']);
check('candidate marked completed', $pdo->query('SELECT completed_at FROM candidates WHERE id = ' . $m['candidate_id'])->fetchColumn() !== null);
$m2 = live_manual($pdo, $cfg, $A, valid_start(['name' => 'Paper Pat', 'mobile' => '016-777 8888', 'email' => 'pat@e.com', 'client_id' => $cid('paper'), 'scores' => $paper]));
check('retrying the same entry saves nothing twice', $m2['already'] && (int)$pdo->query('SELECT COUNT(*) FROM assessments WHERE candidate_id = ' . $m['candidate_id'])->fetchColumn() === 1);
$mail2 = new MemoryMailer();
$n2 = notify_completion($pdo, $cfg, $mail2, $m['cand'], $m['scores'], $m['code'], $A['id']);
check('candidate gets the email, only You is notified (Amy is the one in the room)', $n2['candidate_sent'] && array_column($mail2->sent, 'to') === ['pat@e.com', 'you@x.com']);

$base = fn (array $over) => valid_start(['mobile' => '017-111 2222', 'email' => '', 'scores' => array_replace_recursive($paper, $over)]);
throws('A above 11', 422, fn () => live_manual($pdo, $cfg, $A, $base(['I' => ['a' => 12]])));
throws('C above 14', 422, fn () => live_manual($pdo, $cfg, $A, $base(['I' => ['c' => 15]])));
throws('D below 2', 422, fn () => live_manual($pdo, $cfg, $A, $base(['R' => ['d' => 1]])));
throws('negative', 422, fn () => live_manual($pdo, $cfg, $A, $base(['R' => ['a' => -1]])));
throws('not a number', 422, fn () => live_manual($pdo, $cfg, $A, $base(['R' => ['a' => 'abc']])));
throws('missing type', 422, fn () => live_manual($pdo, $cfg, $A, valid_start(['mobile' => '017-111 2222', 'scores' => array_diff_key($paper, ['C' => 1])])));
check('nothing was created by the failed attempts', $pdo->query("SELECT COUNT(*) FROM candidates WHERE mobile = '+60171112222'")->fetchColumn() == 0);
try { live_manual($pdo, $cfg, $A, $base(['R' => ['d' => 14]])); check('D total outside 42-54 asks for confirmation', false); }
catch (HttpError $e) { check('D total outside 42-54 asks for confirmation', $e->status === 422 && ($e->data['needs_confirm'] ?? '') === 'd_sum'); }
$ok = live_manual($pdo, $cfg, $A, $base(['R' => ['d' => 14]]) + ['confirm_d' => true]);
check('...and saves once confirmed', $ok['already'] === false && $ok['scores']['R']['d'] === 14);
check('numbers typed as text are accepted (form fields)', live_manual($pdo, $cfg, $A, valid_start(['mobile' => '017-333 4444', 'email' => '', 'scores' => array_map(fn ($r) => array_map('strval', $r), $paper)]))['code'] === 'ESC');

echo "\nWho can see live candidates\n";
$map = load_member_map($pdo);
$vis = fn (array $v, int $candId) => can_see_candidate($map, $v, (int)$pdo->query("SELECT owner_member_id FROM candidates WHERE id = $candId")->fetchColumn());
check('Amy sees Pat (hers)', $vis($A, $m['candidate_id']));
check('You (admin) see Pat', $vis($me, $m['candidate_id']));
check('Bob (different branch) does not', !$vis($B, $m['candidate_id']));
check('Ah Ah (below Amy) does not see Amy’s candidate', !$vis($AA, $m['candidate_id']));
check('Amy sees Ah Ah’s live candidate (downline)', $vis($A, $r['candidate_id']));

echo "\nRepeat person met in a live session\n";
$dupe = live_start($pdo, $cfg, $B, valid_start(['mobile' => '016-777 8888', 'email' => 'pat@e.com']));
$o = $pdo->query('SELECT owner_member_id, referred_by_member_id, duplicate_of FROM candidates WHERE id = ' . $dupe['candidate_id'])->fetch();
check('stays with the first advisor (Amy); Bob recorded as the one who met them', (int)$o['owner_member_id'] === $A['id'] && (int)$o['referred_by_member_id'] === $B['id'] && (int)$o['duplicate_of'] === $m['candidate_id']);
$mail3 = new MemoryMailer();
$sd = submit_assessment($pdo, $dupe['submit_token'], sample_answers());
notify_completion($pdo, $cfg, $mail3, $sd['candidate'], $sd['scores'], $sd['code'], $B['id']);
$t = array_column($mail3->sent, 'to'); sort($t);
check('Amy and You are told; Bob (in the room) is not nagged', in_array('a@x.com', $t, true) && in_array('you@x.com', $t, true) && !in_array('b@x.com', $t, true));

echo "\nChange the Career / Internship label\n";
$map = load_member_map($pdo);
$cand = $m['candidate_id'];
update_candidate($pdo, $map, $A, $cand, ['purpose' => 'internship']);
check('owner can relabel to internship', $pdo->query("SELECT purpose FROM candidates WHERE id = $cand")->fetchColumn() === 'internship');
check('the change is audited', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'candidate.purpose' AND subject_id = $cand")->fetchColumn() === 1);
update_candidate($pdo, $map, $A, $cand, ['purpose' => 'internship']);
check('setting the same value again writes no second audit row', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'candidate.purpose' AND subject_id = $cand")->fetchColumn() === 1);
throws('unknown purpose refused', 422, fn () => update_candidate($pdo, $map, $A, $cand, ['purpose' => 'sales']));
throws('someone who cannot see the candidate gets 404', 404, fn () => update_candidate($pdo, $map, $B, $cand, ['purpose' => 'career']));
update_candidate($pdo, $map, $me, $cand, ['purpose' => 'career']);
check('upline/admin can relabel back', $pdo->query("SELECT purpose FROM candidates WHERE id = $cand")->fetchColumn() === 'career');
finish();
