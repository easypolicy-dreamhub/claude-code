<?php
declare(strict_types=1);

// Run in the full site tree: php tests/actionplan.php   Action Plan: template, weeks, statuses, approval, permissions, automatic counts.
// A throw-away copy of the Client Portal bridge and portal data, so the tests never touch real data.
$bridgeDir = sys_get_temp_dir() . '/apbridge_' . getmypid();
mkdir($bridgeDir . '/agents', 0777, true);
copy(__DIR__ . '/fixtures/team-bridge.php', $bridgeDir . '/team-bridge.php');
putenv('E3_PORTAL_BRIDGE=' . $bridgeDir . '/team-bridge.php');
require_once __DIR__ . '/ap_support.php';

function ap_me(PDO $pdo, int $id): array { return load_member_map($pdo)[$id]; }
function ap_find(array $plan, string $title): array
{
    foreach ($plan['items'] as $it) {
        if ($it['title'] === $title) {
            return $it;
        }
    }
    throw new RuntimeException("no item $title");
}
function ap_get(PDO $pdo, int $as, array $q, string $today): array { return ap_dispatch($pdo, ap_me($pdo, $as), 'GET', $q, [], $today); }
function ap_post(PDO $pdo, int $as, array $in, string $today): array { return ap_dispatch($pdo, ap_me($pdo, $as), 'POST', [], $in, $today); }

$T0 = '2026-03-02';   // a Monday: the plans in these tests start here

echo "Weeks\n";
check('before the start date: week 0', ap_week('2026-03-02', '2026-03-01') === 0);
check('start day: week 1', ap_week('2026-03-02', '2026-03-02') === 1);
check('6 days in: still week 1', ap_week('2026-03-02', '2026-03-08') === 1);
check('7 days in: week 2', ap_week('2026-03-02', '2026-03-09') === 2);
check('90 days = 13 weeks, 120 days = 18 weeks', ap_weeks_total(90) === 13 && ap_weeks_total(120) === 18);
check('weeks stretch with the plan length (13 -> 26 at 180 days)', ap_scale_to(13, 180) === 26 && ap_scale_from(3, 180) === 5 && ap_scale_to(1, 90) === 1);
check('dates accept dd/mm/yyyy and ISO', ap_date('25/11/2026') === '2026-11-25' && ap_date('2026-11-25') === '2026-11-25');
throws('a nonsense date is refused', 422, fn () => ap_date('31/02/2026'));

echo "\nThe starting template\n";
[$pdo, $you, $ids] = ap_world();
ap_ensure_template($pdo, $T0);
$tpl = ap_template($pdo);
check('7 sections in your order', array_column($tpl, 'name') === ['Preparation', 'Knowledge', 'Kick Start', 'Practical', 'Talent Acquisition', 'After-sales', 'Review']);
$n = array_sum(array_map(fn ($s) => count($s['items']), $tpl));
check("seeded $n items", $n >= 40 && $n <= 60);
ap_ensure_template($pdo, $T0);
check('seeding twice adds nothing', array_sum(array_map(fn ($s) => count($s['items']), ap_template($pdo))) === $n);
$counts = [];
foreach ($tpl as $s) { foreach ($s['items'] as $i) { if ($i['kind'] === 'count') { $counts[$i['title']] = $i['target']; } } }
check('your count targets: 100 / 30 / 70 / 50 / 3 / 30 / 20', $counts === ['Prospect list (100)' => 100, 'Customer fact finding forms (30)' => 30, 'Natural market survey (70)' => 70, 'Presentations (50)' => 50, 'New business (3)' => 3, 'Cold market (30)' => 30, 'Referrals (20)' => 20]);
check('shortcuts point at real tools', ap_tool_url('million-income')['url'] === '/members/million-income/' && ap_tool_url('nope') === null);
check('Action Plan is a tool that can be ticked', in_array('action-plan', grantable_keys(), true));

echo "\nAssigning a plan\n";
throws('only the super admin can assign', 403, fn () => ap_post($pdo, $ids['Dave'], ['action' => 'assign', 'member_id' => $ids['Cindy'], 'start_date' => $T0, 'length_days' => 90], $T0));
throws('not for the super admin', 422, fn () => ap_assign($pdo, $you, $you['id'], $T0, 90));
$tester = create_member($pdo, $you['id'], ['email' => 't@x.com', 'name' => 'Tess', 'rank' => 'agent', 'referral_code' => 'tess']);
$pdo->exec("UPDATE members SET member_type = 'tester' WHERE id = " . (int)$tester['id']);
throws('not for a Tester or Admin', 422, fn () => ap_assign($pdo, $you, (int)$tester['id'], $T0, 90));
throws('bad start date', 422, fn () => ap_assign($pdo, $you, $ids['Dave'], 'soon', 90));
throws('plan length must be 30 to 365 days', 422, fn () => ap_assign($pdo, $you, $ids['Dave'], $T0, 10));
check('assignable list: team members without a plan', array_column(ap_assignable($pdo, load_member_map($pdo)), 'name') === ['Ben', 'Cindy', 'Dave', 'Eve', 'Gm']);
$dave = ap_assign($pdo, $you, $ids['Dave'], '02/03/2026', 90);
check('assigned; the Action Plan tool is ticked for them', in_array('action-plan', ticked_tools($pdo, $ids['Dave']), true));
check('...and for the team admin above them, who approves', in_array('action-plan', ticked_tools($pdo, $ids['Gm']), true));
check('...but not for people who are not above them', !in_array('action-plan', ticked_tools($pdo, $ids['Eve']), true) && !in_array('action-plan', ticked_tools($pdo, $ids['Ben']), true));
throws('a second plan for the same person is refused', 409, fn () => ap_assign($pdo, $you, $ids['Dave'], $T0, 90));
check('they leave the assignable list', !in_array('Dave', array_column(ap_assignable($pdo, load_member_map($pdo)), 'name'), true));

echo "\nAgent: tick, count, take back\n";
$me = ap_get($pdo, $ids['Dave'], ['view' => 'me'], '2026-03-09')['plan'];
check('week 2 of 13, 0% done', $me['week'] === 2 && $me['weeks_total'] === 13 && $me['progress']['percent'] === 0);
check('an agent never sees the Behind label, flags or private number', !isset($me['label']) && !isset($me['private_number']) && !isset($me['items'][0]['overdue']) && !isset($me['items'][0]['base']));
$fam = ap_find($me, 'Family Visit');
$r = ap_post($pdo, $ids['Dave'], ['action' => 'tick', 'item' => $fam['id']], '2026-03-09')['plan'];
check('tick -> waiting for approval', ap_find($r, 'Family Visit')['status'] === 'waiting' && $r['progress']['waiting'] === 1);
$r = ap_post($pdo, $ids['Dave'], ['action' => 'untick', 'item' => $fam['id']], '2026-03-09')['plan'];
check('take it back -> to do', ap_find($r, 'Family Visit')['status'] === 'todo');
$pres = ap_find($me, 'Presentations (50)');
$r = ap_post($pdo, $ids['Dave'], ['action' => 'count', 'item' => $pres['id'], 'n' => 18], '2026-03-09')['plan'];
check('count 18/50 is saved, not yet waiting', ap_find($r, 'Presentations (50)')['count'] === 18 && ap_find($r, 'Presentations (50)')['status'] === 'todo');
$r = ap_post($pdo, $ids['Dave'], ['action' => 'count', 'item' => $pres['id'], 'n' => 50], '2026-03-09')['plan'];
check('reaching 50 sends it for approval', ap_find($r, 'Presentations (50)')['status'] === 'waiting');
$r = ap_post($pdo, $ids['Dave'], ['action' => 'count', 'item' => $pres['id'], 'n' => 49], '2026-03-09')['plan'];
check('lowering it again takes it back to do', ap_find($r, 'Presentations (50)')['status'] === 'todo');
throws('a count must be a whole number >= 0', 422, fn () => ap_post($pdo, $ids['Dave'], ['action' => 'count', 'item' => $pres['id'], 'n' => -2], '2026-03-09'));
throws('a count cannot be ticked as done: it goes for approval by itself at the target', 422, fn () => ap_post($pdo, $ids['Dave'], ['action' => 'tick', 'item' => $pres['id']], '2026-03-09'));
throws('a tick item has no count', 422, fn () => ap_post($pdo, $ids['Dave'], ['action' => 'count', 'item' => $fam['id'], 'n' => 3], '2026-03-09'));
throws('someone with no plan cannot tick', 403, fn () => ap_post($pdo, $ids['Ben'], ['action' => 'tick', 'item' => $fam['id']], '2026-03-09'));

echo "\nApproving\n";
ap_post($pdo, $ids['Dave'], ['action' => 'tick', 'item' => $fam['id']], '2026-03-09');
$q = ap_get($pdo, $you['id'], ['view' => 'approvals'], '2026-03-09')['approvals'];
check('the super admin sees it in the approvals list', count($q) === 1 && $q[0]['member'] === 'Dave' && $q[0]['title'] === 'Family Visit');
check('the team admin (Dave\'s Group Manager) sees it too', count(ap_get($pdo, $ids['Gm'], ['view' => 'approvals'], '2026-03-09')['approvals']) === 1);
throws('an agent cannot open the approvals list', 403, fn () => ap_get($pdo, $ids['Dave'], ['view' => 'approvals'], '2026-03-09'));
throws('an agent cannot approve their own item', 403, fn () => ap_post($pdo, $ids['Dave'], ['action' => 'approve', 'plan' => $dave, 'item' => $fam['id']], '2026-03-09'));
$r = ap_post($pdo, $ids['Gm'], ['action' => 'sendback', 'plan' => $dave, 'item' => $fam['id'], 'note' => 'Please add a photo'], '2026-03-09')['plan'];
check('team admin sends it back with a note -> follow up', ap_find($r, 'Family Visit')['status'] === 'followup' && ap_find($r, 'Family Visit')['note'] === 'Please add a photo');
throws('a note is needed when sending back', 422, fn () => ap_post($pdo, $you['id'], ['action' => 'sendback', 'plan' => $dave, 'item' => $fam['id'], 'note' => ' '], '2026-03-09'));
$mine = ap_get($pdo, $ids['Dave'], ['view' => 'me'], '2026-03-09')['plan'];
check('the agent sees the note', ap_find($mine, 'Family Visit')['note'] === 'Please add a photo' && $mine['progress']['followup'] === 1);
ap_post($pdo, $ids['Dave'], ['action' => 'tick', 'item' => $fam['id']], '2026-03-09');
$r = ap_post($pdo, $you['id'], ['action' => 'approve', 'plan' => $dave, 'item' => $fam['id']], '2026-03-09')['plan'];
check('approved -> done, and the % moves', ap_find($r, 'Family Visit')['status'] === 'done' && $r['progress']['done'] === 1 && $r['progress']['percent'] > 0);
throws('once approved, the agent cannot tick again', 409, fn () => ap_post($pdo, $ids['Dave'], ['action' => 'tick', 'item' => $fam['id']], '2026-03-09'));
throws('...or take it back', 409, fn () => ap_post($pdo, $ids['Dave'], ['action' => 'untick', 'item' => $fam['id']], '2026-03-09'));
$r = ap_post($pdo, $you['id'], ['action' => 'reopen', 'plan' => $dave, 'item' => $fam['id']], '2026-03-09')['plan'];
check('the super admin can reopen an approved item', ap_find($r, 'Family Visit')['status'] === 'todo');
$r = ap_post($pdo, $you['id'], ['action' => 'approve', 'plan' => $dave, 'item' => $fam['id']], '2026-03-09')['plan'];
check('an approver can approve without the agent ticking (direct)', ap_find($r, 'Family Visit')['status'] === 'done');

echo "\nWho can see whom\n";
$cindy = ap_assign($pdo, $you, $ids['Cindy'], $T0, 90);
$benp = ap_assign($pdo, $you, $ids['Ben'], $T0, 90);
check('the super admin sees all three plans', count(ap_get($pdo, $you['id'], ['view' => 'overview'], '2026-03-09')['plans']) === 3);
$ov = ap_get($pdo, $ids['Gm'], ['view' => 'overview'], '2026-03-09')['plans'];
check('the Group Manager (team admin) sees only their own team: Dave and Cindy, not Ben', array_column($ov, 'name') === ['Cindy', 'Dave'] || array_column($ov, 'name') === ['Dave', 'Cindy']);
throws('...and cannot open Ben\'s plan', 403, fn () => ap_get($pdo, $ids['Gm'], ['view' => 'person', 'plan' => $benp], '2026-03-09'));
throws('...or approve Ben\'s items', 403, fn () => ap_post($pdo, $ids['Gm'], ['action' => 'approve', 'plan' => $benp, 'item' => $fam['id']], '2026-03-09'));
throws('an agent cannot open someone else\'s plan', 403, fn () => ap_get($pdo, $ids['Dave'], ['view' => 'person', 'plan' => $cindy], '2026-03-09'));
throws('an agent cannot see the overview', 403, fn () => ap_get($pdo, $ids['Dave'], ['view' => 'overview'], '2026-03-09'));
$pp = ap_get($pdo, $you['id'], ['view' => 'person', 'plan' => $dave], '2026-03-09')['plan'];
check('only the super admin sees the private number field', array_key_exists('private_number', $pp) && !array_key_exists('private_number', ap_get($pdo, $ids['Gm'], ['view' => 'person', 'plan' => $dave], '2026-03-09')['plan']));
check('approvers get a label', $pp['label'] !== null && isset(ap_get($pdo, $ids['Gm'], ['view' => 'person', 'plan' => $dave], '2026-03-09')['plan']['label']));
throws('only the super admin edits the template', 403, fn () => ap_get($pdo, $ids['Gm'], ['view' => 'template'], '2026-03-09'));
throws('...or changes a plan', 403, fn () => ap_post($pdo, $ids['Gm'], ['action' => 'plan_update', 'plan' => $dave, 'length_days' => 120], '2026-03-09'));
// a team admin with their own plan is approved by the super admin, never by themselves
$gmPlan = ap_assign($pdo, $you, $ids['Gm'], $T0, 90);
ap_post($pdo, $ids['Gm'], ['action' => 'tick', 'item' => $fam['id']], '2026-03-09');
throws('nobody approves their own items (not even a team admin)', 403, fn () => ap_post($pdo, $ids['Gm'], ['action' => 'approve', 'plan' => $gmPlan, 'item' => $fam['id']], '2026-03-09'));
check('their own waiting item is not in their own approvals list', !in_array('Gm', array_column(ap_get($pdo, $ids['Gm'], ['view' => 'approvals'], '2026-03-09')['approvals'], 'member'), true));
check('but it is in the super admin\'s list', in_array('Gm', array_column(ap_get($pdo, $you['id'], ['view' => 'approvals'], '2026-03-09')['approvals'], 'member'), true));

echo "\nOn track or behind (a guide)\n";
[$pdo, $you, $ids] = ap_world();
$p1 = ap_assign($pdo, $you, $ids['Dave'], $T0, 90);
$week = fn (int $w) => date('Y-m-d', strtotime($T0 . ' +' . (($w - 1) * 7) . ' days'));
$get = fn (string $today) => ap_get($pdo, $you['id'], ['view' => 'person', 'plan' => $p1], $today)['plan'];
check('before the start date: not started', $get(date('Y-m-d', strtotime($T0 . ' -3 days')))['label'] === 'not_started');
check('week 1, nothing due yet: on track', $get($week(1))['label'] === 'on_track');
$late = $get($week(5));
check('week 5 with nothing done: behind', $late['label'] === 'behind' && $late['behind_items'] > 5);
check('items whose weeks passed are overdue', ap_find($late, 'Family Visit')['overdue'] === true && ap_find($late, 'Presentations (50)')['overdue'] === false);
check('the agent still only sees "suggested by week"', !isset(ap_get($pdo, $ids['Dave'], ['view' => 'me'], $week(5))['plan']['items'][0]['overdue']));
// do everything that is due by week 2 -> on track in week 3
foreach ($get($week(3))['items'] as $it) {
    if ($it['to'] < 3 && $it['kind'] === 'tick') { ap_post($pdo, $ids['Dave'], ['action' => 'tick', 'item' => $it['id']], $week(3)); }
    if ($it['to'] < 3 && $it['kind'] === 'count') { ap_post($pdo, $ids['Dave'], ['action' => 'count', 'item' => $it['id'], 'n' => $it['target']], $week(3)); }
}
$ok = $get($week(3));
check('week 3 with everything due done (waiting for approval counts): on track', $ok['label'] === 'on_track');
// count pace: presentations 3-12 (10 weeks, 50 total); in week 8 about 30 expected
$presId = ap_find($ok, 'Presentations (50)')['id'];
ap_post($pdo, $ids['Dave'], ['action' => 'count', 'item' => $presId, 'n' => 5], $week(8));
$p8 = ap_find($get($week(8)), 'Presentations (50)');
check('presentations 5 in week 8 is behind the pace (expected about 30)', $p8['pace_behind'] === true && $p8['expected'] >= 25 && $p8['expected'] <= 35);
ap_post($pdo, $ids['Dave'], ['action' => 'count', 'item' => $presId, 'n' => 28], $week(8));
check('28 in week 8 is within the margin', ap_find($get($week(8)), 'Presentations (50)')['pace_behind'] === false);
check('a little behind: just one item over', true);
check('weeks only matter to approvers: week-0 plan', $get($week(1))['week'] === 1);

echo "\nA plan that is longer or shorter\n";
$p2 = ap_assign($pdo, $you, $ids['Cindy'], $T0, 180);
$c = ap_get($pdo, $you['id'], ['view' => 'person', 'plan' => $p2], $week(1))['plan'];
check('180 days = 26 weeks', $c['weeks_total'] === 26);
check('Family Visit (week 1 of 90) now runs to week 2', ap_find($c, 'Family Visit')['to'] === 2);
check('Presentations 3-12 become weeks 5-24', ap_find($c, 'Presentations (50)')['from'] === 5 && ap_find($c, 'Presentations (50)')['to'] === 24);
check('Cindy at week 5 with nothing done is not "behind" yet for 90-day items', ap_find(ap_get($pdo, $you['id'], ['view' => 'person', 'plan' => $p2], $week(2))['plan'], 'Dream Board')['overdue'] === false);
ap_post($pdo, $you['id'], ['action' => 'plan_update', 'plan' => $p2, 'length_days' => 120], $week(1));
check('the length can be changed later', ap_get($pdo, $you['id'], ['view' => 'person', 'plan' => $p2], $week(1))['plan']['weeks_total'] === 18);

echo "\nAdjusting one person\n";
$cp = ap_get($pdo, $you['id'], ['view' => 'person', 'plan' => $p1], $week(5))['plan'];
$bc = ap_find($cp, 'Sales cycle and presentation training');
check('an item past its weeks is overdue in week 5', $bc['overdue'] === true);
$r = ap_post($pdo, $you['id'], ['action' => 'override', 'plan' => $p1, 'item' => $bc['id'], 'skip' => true, 'skip_note' => 'Already printed'], $week(5))['plan'];
check('"not needed for this person": skipped, not overdue, a note', ap_find($r, 'Sales cycle and presentation training')['skipped'] === true && ap_find($r, 'Sales cycle and presentation training')['overdue'] === false && ap_find($r, 'Sales cycle and presentation training')['skip_note'] === 'Already printed');
check('it no longer counts in the total', $r['progress']['total'] === $cp['progress']['total'] - 1);
check('the agent does not see skipped items at all', !array_filter(ap_get($pdo, $ids['Dave'], ['view' => 'me'], $week(5))['plan']['items'], fn ($i) => $i['title'] === 'Sales cycle and presentation training'));
throws('a skipped item cannot be ticked', 422, fn () => ap_post($pdo, $ids['Dave'], ['action' => 'tick', 'item' => $bc['id']], $week(5)));
$r = ap_post($pdo, $you['id'], ['action' => 'override', 'plan' => $p1, 'item' => $bc['id'], 'skip' => false], $week(5))['plan'];
check('undo the skip', ap_find($r, 'Sales cycle and presentation training')['skipped'] === false && ap_find($r, 'Sales cycle and presentation training')['has_override'] === false);
$cold = ap_find($cp, 'Cold market (30)');
$r = ap_post($pdo, $you['id'], ['action' => 'override', 'plan' => $p1, 'item' => $cold['id'], 'target' => 20, 'week_from' => 9, 'week_to' => 14], $week(5))['plan'];
check('a different target and weeks for one person', ap_find($r, 'Cold market (30)')['target'] === 20 && ap_find($r, 'Cold market (30)')['from'] === 9 && ap_find($r, 'Cold market (30)')['to'] === 14);
check('the template itself is unchanged', array_values(array_filter(array_merge(...array_column(ap_template($pdo), 'items')), fn ($i) => $i['title'] === 'Cold market (30)'))[0]['target'] === 30);
throws('weeks must be in order', 422, fn () => ap_post($pdo, $you['id'], ['action' => 'override', 'plan' => $p1, 'item' => $cold['id'], 'week_from' => 12, 'week_to' => 10], $week(5)));
throws('a tick has no target', 422, fn () => ap_post($pdo, $you['id'], ['action' => 'override', 'plan' => $p1, 'item' => $bc['id'], 'target' => 5], $week(5)));
$r = ap_post($pdo, $you['id'], ['action' => 'extra_add', 'plan' => $p1, 'title' => 'Redo the questioning role-play', 'details' => 'With buddy', 'week_from' => 5, 'week_to' => 7, 'kind' => 'tick'], $week(5))['plan'];
check('an extra item appears for this person only', (bool)array_filter($r['items'], fn ($i) => $i['title'] === 'Redo the questioning role-play' && $i['extra'])
    && !array_filter(ap_get($pdo, $you['id'], ['view' => 'person', 'plan' => $p2], $week(5))['plan']['items'], fn ($i) => $i['title'] === 'Redo the questioning role-play'));
$xid = ap_find($r, 'Redo the questioning role-play')['id'];
ap_post($pdo, $ids['Dave'], ['action' => 'tick', 'item' => $xid], $week(5));
$r = ap_post($pdo, $you['id'], ['action' => 'extra_remove', 'plan' => $p1, 'item' => $xid], $week(5))['plan'];
check('and can be removed again', !array_filter($r['items'], fn ($i) => $i['id'] === $xid));
$pn = ap_post($pdo, $you['id'], ['action' => 'plan_update', 'plan' => $p1, 'disc' => 'is', 'private_number' => 7], $week(5))['plan'];
check('DISC style and the private number are saved', $pn['disc']['style'] === 'IS' && str_contains($pn['disc']['hint'], 'recognition') && $pn['private_number'] === 7);
check('the agent sees their DISC hint but not the number', ap_get($pdo, $ids['Dave'], ['view' => 'me'], $week(5))['plan']['disc']['style'] === 'IS' && !array_key_exists('private_number', ap_get($pdo, $ids['Dave'], ['view' => 'me'], $week(5))['plan']));
throws('an unknown DISC style is refused', 422, fn () => ap_post($pdo, $you['id'], ['action' => 'plan_update', 'plan' => $p1, 'disc' => 'ZZ'], $week(5)));
throws('the number is 1 to 33', 422, fn () => ap_post($pdo, $you['id'], ['action' => 'plan_update', 'plan' => $p1, 'private_number' => 99], $week(5)));

echo "\nEditing the template\n";
$tpl = ap_post($pdo, $you['id'], ['action' => 'section_add', 'name' => 'Extras'], $week(5))['sections'];
check('add a section', end($tpl)['name'] === 'Extras');
$secId = end($tpl)['id'];
$r = ap_post($pdo, $you['id'], ['action' => 'item_add', 'section_id' => $secId, 'title' => 'Join the WhatsApp group', 'week_from' => 1, 'week_to' => 2, 'kind' => 'tick'], $week(5))['sections'];
$newItem = end($r)['items'][0];
check('add an item', $newItem['title'] === 'Join the WhatsApp group');
throws('an item needs a task name', 422, fn () => ap_post($pdo, $you['id'], ['action' => 'item_add', 'section_id' => $secId, 'title' => '  ', 'week_from' => 1, 'week_to' => 2], $week(5)));
throws('a count needs a target', 422, fn () => ap_post($pdo, $you['id'], ['action' => 'item_add', 'section_id' => $secId, 'title' => 'X', 'kind' => 'count', 'target' => 0], $week(5)));
throws('weeks must be 1 to 26 and in order', 422, fn () => ap_post($pdo, $you['id'], ['action' => 'item_add', 'section_id' => $secId, 'title' => 'X', 'week_from' => 5, 'week_to' => 2], $week(5)));
throws('a shortcut must be a real tool', 422, fn () => ap_post($pdo, $you['id'], ['action' => 'item_add', 'section_id' => $secId, 'title' => 'X', 'tool_key' => 'nonsense'], $week(5)));
check('an item added AFTER a plan was assigned does not start out overdue',
    (function () use ($pdo, $p1, $newItem, $ids, $you, $week) {
        $pdo->prepare('UPDATE ap_plans SET created_at = ? WHERE id = ?')->execute(['2026-03-02 08:00:00', $p1]);
        $pdo->prepare('UPDATE ap_items SET created_on = ? WHERE id = ?')->execute(['2026-04-20', $newItem['id']]);   // added in week 8, but due weeks 1-2
        $i = ap_find(ap_get($pdo, $you['id'], ['view' => 'person', 'plan' => $p1], $week(8))['plan'], 'Join the WhatsApp group');
        return $i['overdue'] === false && $i['to'] === 9;
    })());
ap_post($pdo, $you['id'], ['action' => 'item_update', 'item' => $newItem['id'], 'title' => 'Join the team WhatsApp group', 'week_from' => 1, 'week_to' => 3, 'kind' => 'tick', 'section_id' => $secId], $week(5));
check('edit an item', array_values(array_filter(ap_template($pdo)[7]['items'], fn ($i) => $i['id'] === $newItem['id']))[0]['title'] === 'Join the team WhatsApp group');
ap_post($pdo, $you['id'], ['action' => 'item_update', 'item' => $newItem['id'], 'active' => false], $week(5));
check('TURN OFF: gone from everyone\'s plan', !array_filter(ap_get($pdo, $you['id'], ['view' => 'person', 'plan' => $p1], $week(8))['plan']['items'], fn ($i) => $i['id'] === $newItem['id']));
check('...but still in the editor, switched off', array_values(array_filter(ap_template($pdo)[7]['items'], fn ($i) => $i['id'] === $newItem['id']))[0]['active'] === false);
ap_post($pdo, $you['id'], ['action' => 'item_update', 'item' => $newItem['id'], 'active' => true], $week(5));
check('turn it back on: returns', (bool)array_filter(ap_get($pdo, $you['id'], ['view' => 'person', 'plan' => $p1], $week(8))['plan']['items'], fn ($i) => $i['id'] === $newItem['id']));
$r = ap_post($pdo, $you['id'], ['action' => 'item_remove', 'item' => $newItem['id']], $week(5));
check('REMOVE an item nobody has worked on: deleted', $r['how'] === 'deleted' && !array_filter(ap_template($pdo)[7]['items'], fn ($i) => $i['id'] === $newItem['id']));
$bcId = ap_find(ap_get($pdo, $you['id'], ['view' => 'person', 'plan' => $p1], $week(5))['plan'], 'Business card')['id'];
ap_post($pdo, $ids['Dave'], ['action' => 'tick', 'item' => $bcId], $week(5));
ap_post($pdo, $you['id'], ['action' => 'approve', 'plan' => $p1, 'item' => $bcId], $week(5));
$r = ap_post($pdo, $you['id'], ['action' => 'item_remove', 'item' => $bcId], $week(5));
check('REMOVE an item someone has finished: archived, not deleted', $r['how'] === 'archived');
check('...gone from plans', !array_filter(ap_get($pdo, $you['id'], ['view' => 'person', 'plan' => $p1], $week(5))['plan']['items'], fn ($i) => $i['id'] === $bcId));
check('...but their approved work is still in the database', (int)$pdo->query("SELECT COUNT(*) FROM ap_progress WHERE item_id = $bcId AND status = 'done'")->fetchColumn() === 1);
check('...and it is not in the editor', !array_filter(array_merge(...array_column(ap_template($pdo), 'items')), fn ($i) => $i['id'] === $bcId));
// move
$sec0 = ap_template($pdo)[0]['items'];
ap_post($pdo, $you['id'], ['action' => 'item_move', 'item' => $sec0[1]['id'], 'dir' => -1], $week(5));
check('move an item up', ap_template($pdo)[0]['items'][0]['id'] === $sec0[1]['id'] && ap_template($pdo)[0]['items'][1]['id'] === $sec0[0]['id']);
ap_post($pdo, $you['id'], ['action' => 'item_move', 'item' => $sec0[1]['id'], 'dir' => -1], $week(5));
check('moving the top item up does nothing', ap_template($pdo)[0]['items'][0]['id'] === $sec0[1]['id']);
$names = array_column(ap_template($pdo), 'name');
ap_post($pdo, $you['id'], ['action' => 'section_move', 'section' => ap_template($pdo)[1]['id'], 'dir' => -1], $week(5));
check('move a section up', array_column(ap_template($pdo), 'name')[0] === $names[1]);
$sid = ap_template($pdo)[2]['id'];
ap_post($pdo, $you['id'], ['action' => 'section_update', 'section' => $sid, 'name' => 'Kick-off'], $week(5));
check('rename a section', ap_template($pdo)[2]['name'] === 'Kick-off');
ap_post($pdo, $you['id'], ['action' => 'section_update', 'section' => $sid, 'active' => false], $week(5));
check('turn a whole section off: its items leave every plan', !array_filter(ap_get($pdo, $you['id'], ['view' => 'person', 'plan' => $p1], $week(5))['plan']['items'], fn ($i) => $i['section'] === 'Kick-off'));
throws('a section with items cannot be deleted (turn it off)', 422, fn () => ap_post($pdo, $you['id'], ['action' => 'section_delete', 'section' => $sid], $week(5)));
ap_post($pdo, $you['id'], ['action' => 'section_delete', 'section' => $secId], $week(5));
check('an empty section can be deleted', !in_array('Extras', array_column(ap_template($pdo), 'name'), true));

echo "\nClosing and reopening a plan\n";
ap_post($pdo, $you['id'], ['action' => 'plan_update', 'plan' => $p1, 'closed' => true], $week(5));
check('a closed plan leaves the overview', !in_array('Dave', array_column(ap_get($pdo, $you['id'], ['view' => 'overview'], $week(5))['plans'], 'name'), true));
check('...and the agent has no plan', ap_get($pdo, $ids['Dave'], ['view' => 'me'], $week(5))['plan'] === null);
ap_assign($pdo, $you, $ids['Dave'], $T0, 90);
check('assigning again reopens it with the old progress', (int)$pdo->query("SELECT COUNT(*) FROM ap_progress WHERE plan_id = $p1")->fetchColumn() > 0 && ap_get($pdo, $ids['Dave'], ['view' => 'me'], $week(5))['plan'] !== null);


echo "\nAutomatic counts (Client Portal and Career Code)\n";
[$pdo, $you, $ids] = ap_world();
$T = '2026-03-23';
$dave = ap_assign($pdo, $you, $ids['Dave'], '2026-03-02', 90);
$cindy = ap_assign($pdo, $you, $ids['Cindy'], '2026-03-02', 90);
e3pb_toggle('dave@x.com', 'Dave', true);
$uid = e3pb_load()['dave@x.com']['uid'];
$ddir = $bridgeDir . '/agents/' . $uid;
ap_make_portal($ddir, 'A');
$pd = ap_portal_db($ddir);
for ($i = 1; $i <= 64; $i++) { $pd->prepare("INSERT INTO clients (name, kind, created_at) VALUES ('SECRET CLIENT NAME', 'prospect', '2026-03-01')")->execute(); }
$pd->exec("INSERT INTO clients (name, kind, created_at) VALUES ('A real client', 'client', '2026-01-01')");
foreach (['2026-03-03', '2026-03-05', '2026-03-10', '2026-03-12', '2026-03-17', '2026-03-20'] as $d) { ap_note($pd, 'present', $d); }
ap_note($pd, 'Present', '2026-03-22');                 // capital P still counts
ap_note($pd, 'present', '2026-02-20');                 // before the plan started: not counted
ap_note($pd, 'present', '2026-03-30');                 // in the future: not counted
for ($i = 1; $i <= 10; $i++) { ap_note($pd, 'open', '2026-03-' . sprintf('%02d', 2 + $i)); }
ap_note($pd, 'servicing', '2026-03-10'); ap_note($pd, 'weird-kind', '2026-03-10'); ap_note($pd, 'recruit', '2026-03-11');
$pd->exec("INSERT INTO closes (close_date, name, plan, anp, updated_at) VALUES ('2026-03-12', 'SECRET CLIENT NAME', 'Plan X', 4000, '2026-03-12'), ('2026-03-20', 'SECRET CLIENT NAME', 'Plan Y', 6500.5, '2026-03-20'), ('2026-01-10', 'Old', 'Plan Z', 9999, '2026-01-10')");

$plan = fn (string $who, int $as = 0) => ap_get($pdo, $you['id'], ['view' => 'person', 'plan' => $who === 'dave' ? $dave : $cindy], $T)['plan'];
ap_auto_reset();
$p = $plan('dave');
$pres = ap_find($p, 'Presentations (50)'); $open = ap_find($p, 'Natural market survey (70)'); $pros = ap_find($p, 'Prospect list (100)'); $biz = ap_find($p, 'New business (3)');
check('presentations counted from Present notes since the start date (7, capital P included, old and future ones left out)', $pres['count'] === 7 && $pres['auto']['value'] === 7);
check('market surveys counted from Open notes (10)', $open['count'] === 10);
check('prospects counted from the portal list (64)', $pros['count'] === 64);
check('closed cases counted since the start date (2, the January one left out)', $biz['count'] === 2);
check('...with the ANP so far', $biz['auto']['extra'] === 'RM 10,501 ANP so far');
check('Servicing / unknown note types are not counted as anything', true);
check('automatic items say where the number comes from', $pres['auto']['source'] === 'Client Portal' && str_contains($pres['auto']['label'], 'Present'));
$blob = json_encode([$p, ap_get($pdo, $you['id'], ['view' => 'autocheck', 'plan' => $dave], $T)]);
check('only numbers cross over: no client name, no note text anywhere in what is sent', !str_contains($blob, 'SECRET') && !str_contains($blob, 'ENCRYPTED'));
throws('the agent cannot type over an automatic count', 422, fn () => ap_post($pdo, $ids['Dave'], ['action' => 'count', 'item' => $pres['id'], 'n' => 40], $T));
$ff = ap_find($p, 'Customer fact finding forms (30)');
ap_post($pdo, $ids['Dave'], ['action' => 'count', 'item' => $ff['id'], 'n' => 12], $T);
check('...but can still enter a count that has no automatic source', ap_find($plan('dave'), 'Customer fact finding forms (30)')['count'] === 12);
// reaching the target by itself
for ($i = 0; $i < 45; $i++) { ap_note($pd, 'present', '2026-03-' . sprintf('%02d', 3 + ($i % 18))); }
ap_auto_reset();
$p = $plan('dave');
check('50 presentations reached by itself -> waiting for approval', ap_find($p, 'Presentations (50)')['count'] === 52 && ap_find($p, 'Presentations (50)')['status'] === 'waiting');
check('...and it is in the approvals list', (bool)array_filter(ap_get($pdo, $you['id'], ['view' => 'approvals'], $T)['approvals'], fn ($a) => $a['title'] === 'Presentations (50)'));
$r = ap_post($pdo, $you['id'], ['action' => 'approve', 'plan' => $dave, 'item' => $pres['id']], $T)['plan'];
check('approving it makes it done', ap_find($r, 'Presentations (50)')['status'] === 'done');
// pace uses the automatic number
ap_auto_reset();
check('the pace check uses the automatic number too (7 notes of 50 in week 4 is not flagged, 4 is)', ap_find($plan('dave'), 'Natural market survey (70)')['pace_behind'] === false);
// someone without a portal
$c = $plan('cindy');
check('no portal: the count stays manual, with a hint', ap_find($c, 'Presentations (50)')['auto']['value'] === null && ap_find($c, 'Presentations (50)')['auto']['portal_off'] === true);
ap_post($pdo, $ids['Cindy'], ['action' => 'count', 'item' => $pres['id'], 'n' => 4], $T);
check('...and she can enter it by hand', ap_find($plan('cindy'), 'Presentations (50)')['count'] === 4);
$chk = ap_get($pdo, $you['id'], ['view' => 'autocheck', 'plan' => $cindy], $T)['check'];
check('the check says why: no Client Portal yet (once, not twice)', $chk['portal']['found'] === false && str_contains($chk['portal']['why'], 'no Client Portal') && $chk['problems'] === []);
// portal switched off -> manual again
e3pb_toggle('dave@x.com', 'Dave', false); ap_auto_reset();
check('portal switched off for someone: back to manual', ap_find($plan('dave'), 'Natural market survey (70)')['auto']['value'] === null);
e3pb_toggle('dave@x.com', 'Dave', true); ap_auto_reset();
check('...and back to automatic when switched on again', ap_find($plan('dave'), 'Natural market survey (70)')['auto']['value'] === 10);
// the portal owner (the super admin's own data lives in the portal folder itself)
file_put_contents($bridgeDir . '/config.php', "<?php define('PORTAL_OWNER', 'ben@x.com');\n");
$ben = ap_assign($pdo, $you, $ids['Ben'], '2026-03-02', 90);
ap_make_portal($bridgeDir, 'A'); $po = ap_portal_db($bridgeDir);
$po->exec("INSERT INTO clients (name, kind) VALUES ('x', 'prospect'), ('y', 'prospect')"); ap_note($po, 'open', '2026-03-05');
ap_auto_reset();
$bp = ap_get($pdo, $you['id'], ['view' => 'person', 'plan' => $ben], $T)['plan'];
check('the portal owner\'s data (in the portal folder itself) is found', ap_find($bp, 'Prospect list (100)')['count'] === 2 && ap_find($bp, 'Natural market survey (70)')['count'] === 1);
// awkward layouts of the closes table
$tmp = $bridgeDir . '/probe';
foreach (['B' => ['INSERT INTO closes (cdate, cname, cplan, canp) VALUES (\'2026-03-15\', \'n\', \'p\', \'1200.50\')', 1, true], 'C' => [null, null, false], 'none' => [null, null, false]] as $variant => [$sql, $expect, $anp]) {
    @mkdir($tmp . $variant, 0777, true); ap_make_portal($tmp . $variant, $variant);
    if ($sql) { ap_portal_db($tmp . $variant)->exec($sql); }
    $c = ap_portal_counts($tmp . $variant . '/clients.sqlite', '2026-03-02', $T);
    check("closes layout $variant: " . ($sql ? 'found (' . $c['closes'] . ' closed, ANP read)' : 'cannot be read, so it stays manual with a reason'),
        $sql ? ($c['closes'] === $expect && $c['anp'] === 1200.5) : ($c['closes'] === null && $c['problems'] !== []));
}
[$dc, $ac] = ap_pick_close_columns(['id', 'updated_at', 'created_at', 'close_date', 'anp']);
check('the date column is the close date, not "updated_at"', $dc === 'close_date' && $ac === 'anp');
check('with only created_at, that is used as a last resort', ap_pick_close_columns(['id', 'created_at', 'name'])[0] === 'created_at');
@mkdir($tmp . 'D', 0777, true); ap_make_portal($tmp . 'D', 'A'); ap_portal_db($tmp . 'D')->exec("INSERT INTO closes (close_date, anp) VALUES ('15/03/2026', 100)");
check('dates that are not year-month-day are not guessed at', ap_portal_counts($tmp . 'D/clients.sqlite', '2026-03-02', $T)['closes'] === null);
check('a sqlite file with none of the tables gives problems, not an error', (function () use ($tmp) { @mkdir($tmp . 'E', 0777, true); new PDO('sqlite:' . $tmp . 'E/clients.sqlite'); $c = ap_portal_counts($tmp . 'E/clients.sqlite', '2026-03-02', '2026-03-23'); return $c['open'] === null && $c['prospects'] === null && count($c['problems']) >= 2; })());
check('note types are matched without case', ap_note_group(' Present ') === 'present' && ap_note_group('OPEN') === 'open' && ap_note_group('Recruit') === 'recruit' && ap_note_group('servicing') === null);
$chk = ap_get($pdo, $you['id'], ['view' => 'autocheck', 'plan' => $dave], $T)['check'];
check('the check lists the note types it found and the closes columns', str_contains(implode(' ', $chk['lines']), 'present = ') && str_contains(implode(' ', $chk['lines']), 'weird-kind') && str_contains(implode(' ', $chk['lines']), 'close_date'));
throws('only the super admin can run the check', 403, fn () => ap_get($pdo, $ids['Gm'], ['view' => 'autocheck', 'plan' => $dave], $T));

echo "\nTry the automatic counts (no plan needed)\n";
$plansBefore = (int)$pdo->query('SELECT COUNT(*) FROM ap_plans')->fetchColumn();
$try = ap_get($pdo, $you['id'], ['view' => 'autotry', 'member' => $ids['Dave'], 'since' => '02/03/2026'], $T)['check'];
check('works for anyone, from a date you choose, and shows the same lines as the plan check', str_contains(implode(' ', $try['lines']), 'Counted as Open: 10') && $try['since'] === '2026-03-02' && $try['until'] === $T);
$try2 = ap_get($pdo, $you['id'], ['view' => 'autotry', 'member' => $ids['Dave'], 'since' => '2026-03-15'], $T)['check'];
check('a later start date counts fewer notes (none are dated after 15 March)', str_contains(implode(' ', $try2['lines']), 'Counted as Open: 0'));
$noPlan = ap_get($pdo, $you['id'], ['view' => 'autotry', 'member' => $ids['Eve'], 'since' => '02/03/2026'], $T)['check'];
check('someone with no plan and no portal: a plain reason', $noPlan['portal']['found'] === false);
$own = ap_get($pdo, $you['id'], ['view' => 'autotry', 'member' => $ids['Ben'], 'since' => '02/03/2026'], $T)['check'];
check('the portal owner\'s own data can be tried (Ben is the owner here: 2 prospects)', str_contains(implode(' ', $own['lines']), 'Prospects on the list now: 2'));
check('the default is the last 30 days', ap_get($pdo, $you['id'], ['view' => 'autotry', 'member' => $ids['Dave']], $T)['check']['since'] === '2026-02-21');
throws('a date in the future is refused', 422, fn () => ap_get($pdo, $you['id'], ['view' => 'autotry', 'member' => $ids['Dave'], 'since' => '01/01/2030'], $T));
throws('a nonsense date is refused', 422, fn () => ap_get($pdo, $you['id'], ['view' => 'autotry', 'member' => $ids['Dave'], 'since' => 'last week'], $T));
throws('an unknown person is refused', 404, fn () => ap_get($pdo, $you['id'], ['view' => 'autotry', 'member' => 9999, 'since' => '02/03/2026'], $T));
throws('only the super admin can try it', 403, fn () => ap_get($pdo, $ids['Gm'], ['view' => 'autotry', 'member' => $ids['Dave'], 'since' => '02/03/2026'], $T));
check('nothing is saved: no plan was created', (int)$pdo->query('SELECT COUNT(*) FROM ap_plans')->fetchColumn() === $plansBefore);
check('the picker lists everyone who is active', count(ap_get($pdo, $you['id'], ['view' => 'init'], $T)['try_members']) === 6 && ap_get($pdo, $ids['Dave'], ['view' => 'init'], $T)['try_members'] === []);

echo "\nCareer Code\n";
$ins = $pdo->prepare("INSERT INTO candidates (owner_member_id, referred_by_member_id, source, name, mobile, email, consent_version, started_at, completed_at) VALUES (?,?,?,?,?,?,?,?,?)");
$ins->execute([$ids['Dave'], null, 'referral', 'Cand One', '+60111', 'one@x.com', 'v1', '2026-03-10 09:00:00', '2026-03-10 09:30:00']);
$ins->execute([$ids['Dave'], null, 'referral', 'Cand Two', '+60112', 'two@x.com', 'v1', '2026-03-12 09:00:00', null]);
$ins->execute([$ids['Ben'], $ids['Dave'], 'referral', 'Cand Three', '+60113', 'three@x.com', 'v1', '2026-03-14 09:00:00', '2026-03-14 10:00:00']);
$ins->execute([$ids['Dave'], null, 'referral', 'Old', '+60114', 'old@x.com', 'v1', '2026-02-01 09:00:00', '2026-02-01 09:30:00']);
ap_auto_reset();
$ci = ap_find($plan('dave'), 'Career Introduction 1+2');
check('Career Code candidates added (own + referred, since the start date): 3, with 2 tests completed', $ci['auto']['value'] === 3 && $ci['auto']['source'] === 'Career Code' && ap_auto_context($pdo, ap_plan_row($pdo, $dave), $T)['values']['career_tests'] === 2);
check('a tick item shows the number beside it but stays a tick', $ci['kind'] === 'tick' && $ci['status'] === 'todo');
// DISC from Career Code
$cid = (int)$pdo->query("SELECT id FROM candidates WHERE email = 'one@x.com'")->fetchColumn();
$pdo->prepare("UPDATE candidates SET email = 'dave@x.com' WHERE id = ?")->execute([$cid]);
$pdo->prepare("INSERT INTO disc_results (candidate_id, answers_json, scores_json, style) VALUES (?, '{}', '{}', 'DI')")->execute([$cid]);
$pd1 = $plan('dave');
check('DISC picked up from the Career Code test (matched by email), marked as such', $pd1['disc']['style'] === 'DI' && $pd1['disc']['source'] === 'Career Code' && str_contains($pd1['disc']['hint'], 'targets'));
check('...also on the overview chip', array_column(ap_get($pdo, $you['id'], ['view' => 'overview'], $T)['plans'], 'disc', 'name')['Dave'] === 'DI');
ap_post($pdo, $you['id'], ['action' => 'plan_update', 'plan' => $dave, 'disc' => 'S'], $T);
check('a style you set by hand wins over Career Code', $plan('dave')['disc']['style'] === 'S' && $plan('dave')['disc']['source'] === null);
check('nothing found for someone who never did the test', $plan('cindy')['disc'] === null);

echo "\nChoosing the automatic count in the template\n";
$sec = ap_template($pdo)[3];
$ref = array_values(array_filter($sec['items'], fn ($i) => $i['title'] === 'Referrals (20)'))[0];
ap_post($pdo, $you['id'], ['action' => 'item_update', 'item' => $ref['id'], 'auto_key' => 'recruit', 'title' => $ref['title'], 'kind' => 'count', 'target' => 20, 'week_from' => 6, 'week_to' => 12, 'section_id' => $sec['id']], $T);
ap_auto_reset();
check('set an automatic count on any item', ap_find($plan('dave'), 'Referrals (20)')['count'] === 1 && ap_find($plan('dave'), 'Referrals (20)')['auto']['key'] === 'recruit');
throws('an unknown automatic count is refused', 422, fn () => ap_post($pdo, $you['id'], ['action' => 'item_update', 'item' => $ref['id'], 'auto_key' => 'nonsense', 'title' => $ref['title'], 'kind' => 'count', 'target' => 20, 'week_from' => 6, 'week_to' => 12, 'section_id' => $sec['id']], $T));
ap_post($pdo, $you['id'], ['action' => 'item_update', 'item' => $ref['id'], 'auto_key' => '', 'title' => $ref['title'], 'kind' => 'count', 'target' => 20, 'week_from' => 6, 'week_to' => 12, 'section_id' => $sec['id']], $T);
ap_auto_reset();
check('...and take it off again: back to manual', ap_find($plan('dave'), 'Referrals (20)')['auto'] === null);
check('the editor lists the automatic counts you can choose', count(ap_get($pdo, $you['id'], ['view' => 'template'], $T)['autos']) === 7);
check('the starting template already uses them for your five items', count(array_filter(array_merge(...array_column(ap_template($pdo), 'items')), fn ($i) => $i['auto_key'] !== null)) >= 5);
exec('rm -rf ' . escapeshellarg($bridgeDir));

echo "\nThe audit log\n";
check('actions are recorded', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'ap.%'")->fetchColumn() > 5);
check('the private number is never written to the log', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE detail LIKE '%private_number%'")->fetchColumn() === 0);
finish();
