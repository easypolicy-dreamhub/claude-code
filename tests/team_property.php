<?php
declare(strict_types=1);

// Run: php tests/team_property.php [runs] [seed]
// Randomised stress test of the team rules: random teams, random operations, invariants checked after every step.
require_once __DIR__ . '/../private/lib/team.php';
require_once __DIR__ . '/helpers.php';

$runs = (int)($argv[1] ?? 400);
$seed = (int)($argv[2] ?? 20261024);
mt_srand($seed);
$ranks = ['agent', 'unit_manager', 'group_manager'];
$lvl = fn (array $m) => rank_level($m['rank_label']);

/** @return string[] human-readable violations (empty = all good). $strictRanks: also demand no active member outranks their upline. */
function invariants(PDO $pdo, bool $strictRanks): array
{
    $map = load_member_map($pdo);
    $bad = [];
    $n = count($map);
    foreach ($map as $id => $m) {
        $cur = $m['upline_id']; $steps = 0;
        while ($cur !== null && $steps++ <= $n) { $cur = $map[$cur]['upline_id'] ?? null; }
        if ($steps > $n) { $bad[] = "loop in the tree at {$m['name']}"; }
        if ($m['upline_id'] !== null && !isset($map[$m['upline_id']])) { $bad[] = "{$m['name']} has a missing upline"; }
        if ($m['is_admin'] && $m['upline_id'] !== null) { $bad[] = "super admin {$m['name']} has an upline"; }
        if ($m['is_admin']) { foreach ($map as $o) { if ($o['id'] !== $id && in_array($id, downline_ids($map, $o['id']), true)) { $bad[] = "{$o['name']} has the super admin in their downline (can see your candidates)"; } } }
        if ($m['is_active'] && $m['upline_id'] !== null && !$map[$m['upline_id']]['is_active']) { $bad[] = "active {$m['name']} sits under removed {$map[$m['upline_id']]['name']}"; }
        if ($strictRanks && $m['is_active'] && $m['upline_id'] !== null && rank_level($m['rank_label']) > rank_level($map[$m['upline_id']]['rank_label'])) {
            $bad[] = "{$m['name']} ({$m['rank_label']}) outranks upline {$map[$m['upline_id']]['name']} ({$map[$m['upline_id']]['rank_label']})";
        }
        if ($m['is_team_admin'] && ($m['rank_label'] !== 'group_manager' || !$m['is_active'])) { $bad[] = "{$m['name']} is team admin but not an active Group Manager"; }
    }
    return $bad;
}

$failures = [];
$ops = ['rank' => 0, 'move' => 0, 'remove' => 0, 'group' => 0, 'react' => 0, 'refused' => 0];
for ($run = 0; $run < $runs; $run++) {
    $manual = $run % 2 === 1;                     // odd runs also use manual moves (which may break rank order by design)
    $pdo = fresh_db();
    $me = create_member($pdo, null, ['email' => 'you@x.com', 'name' => 'You', 'rank' => 'group_manager', 'is_admin' => true, 'referral_code' => 'cf']);
    $ids = [$me['id']];
    $size = mt_rand(5, 12);
    for ($i = 1; $i <= $size; $i++) {                       // random valid tree: nobody outranks their upline
        $up = $ids[array_rand($ids)];
        $upRank = rank_level(load_member_map($pdo)[$up]['rank_label']);
        $rank = $ranks[mt_rand(0, $upRank - 1)];
        $ids[] = create_member($pdo, $me['id'], ['email' => "m$i@x.com", 'name' => "M$i", 'rank' => $rank, 'upline_id' => $up, 'referral_code' => "m-$i"])['id'];
    }
    $log = [];
    for ($step = 0; $step < 12; $step++) {
        $map = load_member_map($pdo);
        $admins = array_values(array_filter($map, fn ($m) => $m['is_active'] && ($m['is_admin'] || is_team_admin($m))));
        // occasionally promote a Group Manager to team admin
        if (mt_rand(0, 5) === 0) { $gms = array_values(array_filter($map, fn ($m) => $m['is_active'] && !$m['is_admin'] && $m['rank_label'] === 'group_manager')); if ($gms) { set_team_admin($pdo, $map[$me['id']], $gms[array_rand($gms)]['id'], true); $log[] = 'team-admin'; continue; } }
        $actor = mt_rand(0, 3) === 0 && count($admins) > 1 ? $admins[array_rand($admins)] : $map[$me['id']];
        $all = array_keys($map);
        $target = $all[array_rand($all)];
        $op = ['rank', 'rank', 'rank', 'move', 'remove', 'group', 'react'][mt_rand(0, 6)];
        try {
            if ($op === 'rank') {
                $new = $ranks[mt_rand(0, 2)];
                apply_rank_change($pdo, $actor, $target, $new);                    // accept every suggested move
                $log[] = "{$actor['name']}: {$map[$target]['name']} -> $new"; $ops['rank']++;
            } elseif ($op === 'move' && $manual) {
                $dests = array_merge([null], array_keys($map));
                $to = $dests[array_rand($dests)];
                move_member($pdo, $actor, $target, $to, (bool)mt_rand(0, 1));
                $log[] = "{$actor['name']}: move {$map[$target]['name']} under " . ($to === null ? 'top' : $map[$to]['name']); $ops['move']++;
            } elseif ($op === 'remove') {
                $tos = array_keys(array_filter($map, fn ($m) => $m['is_active']));
                // strict runs use the destination the Team page proposes (the leaver's upline); manual runs pick any member
                $to = $manual ? $tos[array_rand($tos)] : (plan_remove_member($pdo, $map, $actor, $target)['default_to']['id'] ?? null);
                remove_member($pdo, $actor, $target, $to);
                $log[] = "{$actor['name']}: remove {$map[$target]['name']}"; $ops['remove']++;
            } elseif ($op === 'group') {
                remove_group($pdo, $actor, $target, 'REMOVE');
                $log[] = "{$actor['name']}: remove group of {$map[$target]['name']}"; $ops['group']++;
            } elseif ($op === 'react') {
                $cands = array_keys(array_filter($map, fn ($m) => $m['is_active']));
                $to = $manual ? $cands[array_rand($cands)] : (plan_reactivate($map, $actor, $target)['default_to']['id'] ?? null);
                reactivate_member($pdo, $actor, $target, $to, (bool)mt_rand(0, 1));
                $log[] = "{$actor['name']}: reactivate {$map[$target]['name']}"; $ops['react']++;
            }
        } catch (HttpError $e) { $ops['refused']++; continue; }
        $bad = invariants($pdo, !$manual);
        if ($bad) { $failures[] = ['run' => $run, 'step' => $step, 'bad' => $bad, 'log' => $log]; break; }
    }
}
check("$runs random teams, " . array_sum(array_slice($ops, 0, 5)) . " operations ({$ops['rank']} rank changes, {$ops['move']} moves, {$ops['remove']} removals, {$ops['group']} group removals, {$ops['react']} reactivations; {$ops['refused']} refused)", true);
check('every rule held after every step (no loops, super admin never under anyone, no active member under a removed one, no one outranks their upline after rank/removal operations, team admins are active Group Managers)', !$failures);
foreach (array_slice($failures, 0, 5) as $f) { echo "  --- run {$f['run']} step {$f['step']}: " . implode('; ', array_slice($f['bad'], 0, 3)) . "\n      steps: " . implode(' | ', array_slice($f['log'], -5)) . "\n"; }
if ($failures) { echo '  total failing runs: ' . count($failures) . "\n"; }
finish();
