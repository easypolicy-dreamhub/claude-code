<?php
declare(strict_types=1);

// Run: php tests/phase5.php   Activity page numbers and who may see them.
require_once __DIR__ . '/../private/lib/activity.php';
require_once __DIR__ . '/../private/lib/live.php';
require_once __DIR__ . '/helpers.php';

$pdo = fresh_db();
$me = create_member($pdo, null, ['email' => 'you@x.com', 'name' => 'You', 'rank' => 'group_manager', 'is_admin' => true, 'referral_code' => 'cf']);
$A  = create_member($pdo, $me['id'], ['email' => 'a@x.com', 'name' => 'Amy', 'rank' => 'unit_manager', 'upline_id' => $me['id'], 'referral_code' => 'amy']);
$AA = create_member($pdo, $me['id'], ['email' => 'aa@x.com', 'name' => 'Alice', 'upline_id' => $A['id'], 'referral_code' => 'alice']);
$B  = create_member($pdo, $me['id'], ['email' => 'b@x.com', 'name' => 'Ben', 'upline_id' => $me['id'], 'referral_code' => 'ben']);
$cfg = ['default_owner_email' => 'you@x.com', 'ip_salt' => 's', 'rate_limit_per_hour' => 1000];
$n = 0;
$add = function (string $ref, string $name, bool $finish = true) use ($pdo, $cfg, &$n): int {
    $n++;
    $r = start_candidate($pdo, $cfg, valid_start(['ref' => $ref, 'name' => $name, 'mobile' => '0112000' . str_pad((string)$n, 4, '0', STR_PAD_LEFT), 'email' => "c$n@e.com"]), null);
    if ($finish) { submit_assessment($pdo, $r['submit_token'], sample_answers()); }
    return $r['candidate_id'];
};
$c1 = $add('amy', 'Cara');          // Amy's
$c2 = $add('alice', 'Dina');        // Alice's
$c3 = $add('ben', 'Eddy');          // Ben's
$c4 = $add('cf', 'Zed');            // You
$c5 = $add('alice', 'Una', false);  // Alice's, unfinished
$map = load_member_map($pdo);
$S = fn (array $v, string $p = 'all') => activity_summary($pdo, $map, $v, $p);
$names = fn (array $s) => array_column($s['people'], 'name');
$row = fn (array $s, string $nm) => array_values(array_filter($s['people'], fn ($r) => $r['name'] === $nm))[0] ?? null;

echo "Who sees whom\n";
check('admin sees everyone', $names($S($me)) === ['You', 'Amy', 'Alice', 'Ben']);
check('Amy sees herself and Alice only', $names($S($A)) === ['Amy', 'Alice']);
check('Alice sees only herself', $names($S($AA)) === ['Alice']);
check('Ben (other branch) sees only himself', $names($S($B)) === ['Ben']);
check('indentation follows the tree (Alice is below Amy)', $row($S($me), 'Alice')['depth'] === 2 && $row($S($me), 'Amy')['depth'] === 1 && $row($S($A), 'Amy')['depth'] === 0);
$feedNames = fn (array $s) => array_unique(array_column($s['feed'], 'cand'));
check('Amy’s feed never mentions Ben’s or Your candidates', !array_intersect($feedNames($S($A)), ['Eddy', 'Zed']));
check('Alice’s feed mentions only her own', !array_diff($feedNames($S($AA)), ['Dina', 'Una']));
check('Ben’s feed shows only Eddy', $feedNames($S($B)) === ['Eddy']);

echo "\nNumbers\n";
$s = $S($me);
check('totals: 5 added, 4 finished', $s['totals']['added'] === 5 && $s['totals']['completed'] === 4);
check('Alice: 2 added (one unfinished), 1 finished', $row($s, 'Alice')['added'] === 2 && $row($s, 'Alice')['completed'] === 1);
check('Amy’s own row counts only her candidate, not her team’s', $row($s, 'Amy')['added'] === 1);
check('Amy’s page totals include her team (2 added, 2 finished)', $S($A)['totals']['added'] === 3 && $S($A)['totals']['completed'] === 2);
check('status snapshot counts finished candidates only', $s['status_now']['new'] === 4 && array_sum($s['status_now']) === 4);
$pdo->prepare("UPDATE candidates SET pipeline_status = 'joined' WHERE id = ?")->execute([$c2]);
check('a joined candidate shows in totals and on the owner’s row', $S($me)['totals']['joined'] === 1 && $row($S($me), 'Alice')['joined'] === 1 && $S($me)['status_now']['joined'] === 1);
try { activity_summary($pdo, $map, $me, 'forever'); check('bad period refused', false); } catch (HttpError $e) { check('bad period refused', $e->status === 422); }

echo "\nPeriods\n";
$old = gmdate('Y-m-d H:i:s', time() - 20 * 86400);
$pdo->prepare('UPDATE candidates SET started_at = ?, completed_at = ? WHERE id = ?')->execute([$old, $old, $c3]);
check('Eddy (20 days old) is outside the 7-day window', $S($me, 'week')['totals']['added'] === 4);
check('...inside 30 days', $S($me, 'month')['totals']['added'] === 5);
check('...and in all time', $S($me, 'all')['totals']['added'] === 5);

echo "\nNeeds follow-up\n";
check('nobody is overdue yet except Eddy (20 days, still New)', array_column($S($me)['follow_up'], 'name') === ['Eddy'] && $S($me)['follow_up'][0]['days'] === 20);
check('Amy does not see Eddy in her follow-ups', $S($A)['follow_up'] === []);
$pdo->prepare("UPDATE candidates SET pipeline_status = 'contacted' WHERE id = ?")->execute([$c3]);
check('once contacted he drops off the list', $S($me)['follow_up'] === []);
$pdo->prepare('UPDATE candidates SET completed_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 2 * 86400), $c1]);
check('2 days old is not yet overdue', !in_array('Cara', array_column($S($me)['follow_up'], 'name'), true));
$pdo->prepare('UPDATE candidates SET completed_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 4 * 86400), $c1]);
check('4 days old is overdue, and Amy (the owner) sees it', in_array('Cara', array_column($S($A)['follow_up'], 'name'), true));
check('unfinished tests are never follow-ups', !in_array('Una', array_column($S($me)['follow_up'], 'name'), true));

echo "\nRecent activity\n";
$map = load_member_map($pdo);
update_candidate($pdo, $map, $A, $c1, ['pipeline_status' => 'interviewed']);
$f = $S($A)['feed'];
$st = array_values(array_filter($f, fn ($e) => $e['kind'] === 'status'))[0] ?? [];
check('a status change appears with who and the new status', ($st['who'] ?? '') === 'Amy' && ($st['cand'] ?? '') === 'Cara' && ($st['to'] ?? '') === 'interviewed');
check('Amy’s row shows she was active just now', $row($S($A), 'Amy')['last_active'] !== null);
check('feed is newest first', (function () use ($f) { $t = array_column($f, 'at'); $s = $t; rsort($s); return $t === $s; })());
check('feed is capped', count($S($me)['feed']) <= FEED_LIMIT);
$json = json_encode($S($me));
check('no scores, notes, mobiles or emails anywhere in the data', !preg_match('/scores|verdict|criteria|mobile|@e\.com|\+60/i', $json));

echo "\nPeople who were removed\n";
$pdo->prepare('UPDATE members SET is_active = 0 WHERE id = ?')->execute([$B['id']]);
$map = load_member_map($pdo);
check('a removed member with candidates still shows (greyed), without them they vanish', in_array('Ben', array_column(activity_summary($pdo, $map, $me, 'all')['people'], 'name'), true));
$pdo->prepare('UPDATE members SET is_active = 0 WHERE id = ?')->execute([$AA['id']]);
$pdo->prepare('DELETE FROM assessments WHERE candidate_id IN (SELECT id FROM candidates WHERE owner_member_id = ?)')->execute([$AA['id']]);
$pdo->prepare('DELETE FROM candidates WHERE owner_member_id = ?')->execute([$AA['id']]);
$map = load_member_map($pdo);
check('a removed member with no candidates is hidden', !in_array('Alice', array_column(activity_summary($pdo, $map, $me, 'all')['people'], 'name'), true));
finish();
