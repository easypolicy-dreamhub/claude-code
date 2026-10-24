<?php
declare(strict_types=1);

// Run: php tests/team.php   Team rules: your promotion / demotion scenarios, manual moves, removals, team admin.
require_once __DIR__ . '/../private/lib/team.php';
require_once __DIR__ . '/helpers.php';

$pdo = null; $me = null; $ids = [];
function setup(array $people): void {   // [name => [rank, upline-name|null]]
    global $pdo, $me, $ids;
    $pdo = fresh_db(); $ids = [];
    $me = create_member($pdo, null, ['email' => 'you@x.com', 'name' => 'You', 'rank' => 'group_manager', 'is_admin' => true, 'referral_code' => 'cf']);
    $ids['You'] = $me['id'];
    foreach ($people as $n => [$rank, $up]) {
        $ids[$n] = create_member($pdo, $me['id'], ['email' => strtolower($n) . '@x.com', 'name' => $n, 'rank' => $rank, 'upline_id' => $ids[$up ?? 'You'], 'referral_code' => 'r-' . strtolower($n)])['id'];
    }
}
function m(): array { global $pdo; return load_member_map($pdo); }
function who(string $n): array { global $ids; return m()[$ids[$n]]; }
function upline(string $n): ?string { global $ids; $u = m()[$ids[$n]]['upline_id']; return $u === null ? null : array_search($u, $ids, true); }
function sees(string $viewer, string $other): bool { global $ids; return can_see_candidate(m(), who($viewer), $ids[$other]); }
function rank(string $n, string $r, ?array $accept = null): array { global $pdo, $ids; return apply_rank_change($pdo, who('You'), $ids[$n], $r, $accept === null ? null : array_map(fn ($x) => $ids[$x], $accept)); }
function plan(string $n, string $r): array { global $ids; return plan_rank_change(m(), $ids[$n], $r); }
function moved(array $plan): array { global $ids; $f = array_flip($ids); return array_map(fn ($mv) => $f[$mv['member']] . '->' . ($mv['to'] === null ? 'top' : $f[$mv['to']]), $plan['moves']); }

echo "Agent A recruits agent AA; AA is promoted to Unit Manager first\n";
setup(['A' => ['agent', null], 'AA' => ['agent', 'A'], 'AAA' => ['agent', 'AA']]);
check('A sees AA while both are agents', sees('A', 'AA'));
check('plan: AA breaks away from A to You', moved(plan('AA', 'unit_manager')) === ['AA->You']);
rank('AA', 'unit_manager');
check('AA is now under You, with his own agent AAA still under him', upline('AA') === 'You' && upline('AAA') === 'AA');
check('A can no longer see AA or AAA', !sees('A', 'AA') && !sees('A', 'AAA'));
rank('AA', 'agent');
check('AA demoted later: stays with You, does NOT go back to A', upline('AA') === 'You' && !sees('A', 'AA'));

echo "\nA promoted to Unit Manager first, then AA too (parallel)\n";
setup(['A' => ['agent', null], 'AA' => ['agent', 'A'], 'AB' => ['agent', 'A']]);
check('A promoted to Unit Manager: no moves (You are above)', moved(plan('A', 'unit_manager')) === []);
rank('A', 'unit_manager');
check('AA promoted to Unit Manager under Unit Manager A: stays (parallel)', moved(plan('AA', 'unit_manager')) === [] && !plan('AA', 'unit_manager')['gm_choice']);
rank('AA', 'unit_manager');
check('A sees parallel AA and AB', sees('A', 'AA') && sees('A', 'AB'));
check('AA demoted back to agent: stays under A', moved(plan('AA', 'agent')) === []);
$p = plan('A', 'agent');
check('A demoted to agent: AA (outranks) and AB (same level) both move up to You', moved($p) === ['AA->You', 'AB->You']);
rank('A', 'agent');
check('...applied: A stays under You, sees neither', upline('A') === 'You' && !sees('A', 'AA') && !sees('A', 'AB'));

echo "\nGroup Manager cases (GE circular)\n";
setup(['A' => ['unit_manager', null], 'B' => ['unit_manager', 'A'], 'AB' => ['agent', 'A'], 'BA' => ['agent', 'B']]);
$p = plan('A', 'group_manager');
check('A promoted to Group Manager under You (Group Manager): no automatic move, you choose stay/opt out', moved($p) === [] && $p['gm_choice']);
rank('A', 'group_manager');
set_team_admin($pdo, who('You'), $ids['A'], true);
check('A stays and is made team admin', is_team_admin(who('A')));
check('You still see A\'s whole group; A cannot see you', sees('You', 'BA') && !sees('A', 'You'));
$p = plan('A', 'unit_manager');
check('A relegated to Unit Manager: his Unit Manager B stays under him as parallel, nobody moves', moved($p) === [] && $p['team_admin_off']);
rank('A', 'unit_manager');
check('...A loses team admin; B and AB still under A', !is_team_admin(who('A')) && who('A')['is_team_admin'] === 0 && upline('B') === 'A' && upline('AB') === 'A');
setup(['A' => ['unit_manager', null], 'AA' => ['agent', 'A'], 'AAA' => ['agent', 'AA']]);
$p = plan('AA', 'group_manager');
check('agent AA jumps to Group Manager: breaks away to You AND you are asked stay/opt out', moved($p) === ['AA->You'] && $p['gm_choice']);
rank('AA', 'group_manager');
check('...his agent AAA goes with him', upline('AAA') === 'AA' && !sees('A', 'AAA'));
setup(['A' => ['agent', null]]);
check('top-level: promoting You or someone with no upline moves nobody', moved(plan('You', 'group_manager')) === []);

echo "\nYour overrides\n";
setup(['A' => ['agent', null], 'AA' => ['agent', 'A']]);
rank('AA', 'unit_manager', []);   // untick the suggested move
check('unticked move: rank changes, AA stays under A', who('AA')['rank_label'] === 'unit_manager' && upline('AA') === 'A');
$tree = team_tree($pdo, m(), who('You'), false);
check('...and the tree flags AA with a rank warning', (bool)array_values(array_filter($tree['members'], fn ($n) => $n['name'] === 'AA'))[0]['rank_warning']);
setup(['B' => ['unit_manager', null], 'C' => ['unit_manager', null], 'CA' => ['agent', 'C'], 'CAA' => ['agent', 'CA']]);
move_member($pdo, who('You'), $ids['CA'], $ids['B']);
check('move CA from C to B: B sees CA and CAA, C sees neither', sees('B', 'CA') && sees('B', 'CAA') && !sees('C', 'CA') && !sees('C', 'CAA'));
move_member($pdo, who('You'), $ids['CA'], $ids['C'], false);
check('"move only CA" back to C: CAA stays with B', upline('CA') === 'C' && upline('CAA') === 'B');
throws('cannot put someone under their own team', 422, fn () => move_member($pdo, who('You'), $ids['B'], $ids['CAA']));
throws('cannot put someone under themselves', 422, fn () => move_member($pdo, who('You'), $ids['B'], $ids['B']));
move_member($pdo, who('You'), $ids['C'], null);
check('super admin can make someone top-level', upline('C') === null);

echo "\nSomeone leaves the business\n";
setup(['A' => ['unit_manager', null], 'AB' => ['agent', 'A'], 'ABA' => ['agent', 'AB'], 'ABB' => ['agent', 'AB']]);
$cfg = ['default_owner_email' => 'you@x.com', 'ip_salt' => 's', 'rate_limit_per_hour' => 1000];
$c1 = start_candidate($pdo, $cfg, valid_start(['ref' => 'r-ab', 'mobile' => '0121110001', 'email' => 'k1@x.com']), null)['candidate_id'];
$p = plan_remove_member($pdo, m(), who('You'), $ids['AB']);
check('preview: AB\'s 2 agents and 1 candidate go to A by default', count($p['agents']) === 2 && $p['candidates'] === 1 && $p['default_to']['name'] === 'A');
remove_member($pdo, who('You'), $ids['AB'], $ids['A']);
check('AB deactivated; ABA and ABB now under A', !who('AB')['is_active'] && upline('ABA') === 'A' && upline('ABB') === 'A');
check('AB\'s candidate now belongs to A', (int)$pdo->query("SELECT owner_member_id FROM candidates WHERE id = $c1")->fetchColumn() === $ids['A']);
throws('you cannot remove yourself', 403, fn () => plan_remove_member($pdo, m(), who('You'), $ids['You']));

echo "\nA Group Manager opts out: remove the whole group\n";
setup(['A' => ['group_manager', null], 'B' => ['unit_manager', 'A'], 'BA' => ['agent', 'B'], 'X' => ['agent', null]]);
$ca = start_candidate($pdo, $cfg, valid_start(['ref' => 'r-ba', 'mobile' => '0121110002', 'email' => 'k2@x.com']), null);
submit_assessment($pdo, $ca['submit_token'], sample_answers());
$cx = start_candidate($pdo, $cfg, valid_start(['ref' => 'r-x', 'mobile' => '0121110003', 'email' => 'k3@x.com']), null)['candidate_id'];
$p = plan_remove_group($pdo, m(), who('You'), $ids['A']);
check('preview lists A, B, BA and 1 candidate', count($p['people']) === 3 && $p['candidates'] === 1);
$csv = export_candidates_csv($pdo, m(), who('You'), array_column($p['people'], 'id'));
check('download-a-copy CSV contains only the group\'s candidate', substr_count(trim($csv), "\r\n") === 1 && !str_contains($csv, 'k3@x.com'));
throws('needs the word REMOVE', 422, fn () => remove_group($pdo, who('You'), $ids['A'], 'yes'));
$r = remove_group($pdo, who('You'), $ids['A'], 'REMOVE');
check('group deactivated, their candidate (and result) deleted, others untouched', !who('A')['is_active'] && !who('BA')['is_active'] && $r['candidates_deleted'] === 1
    && (int)$pdo->query('SELECT COUNT(*) FROM assessments')->fetchColumn() === 0 && (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE id = $cx")->fetchColumn() === 1);
check('returns the emails to delete from the Cloudflare list', $r['emails'] === ['a@x.com', 'b@x.com', 'ba@x.com']);

echo "\nA comes back after relegation (reactivate)\n";
$p = plan_reactivate(m(), who('You'), $ids['A']);
check('reactivate preview offers his group of 2 removed together, under You', count($p['team']) === 2 && $p['default_to']['name'] === 'You');
reactivate_member($pdo, who('You'), $ids['A'], $ids['You'], true);
check('A, B and BA active again, A under You, structure intact', who('A')['is_active'] && who('BA')['is_active'] && upline('A') === 'You' && upline('BA') === 'B');
setup(['A' => ['unit_manager', null], 'AA' => ['agent', 'A'], 'AB' => ['agent', 'A']]);
remove_member($pdo, who('You'), $ids['AA'], $ids['A']);
move_member($pdo, who('You'), $ids['AB'], $ids['A']);
$pdo->exec("UPDATE members SET upline_id = {$ids['A']} WHERE id = {$ids['AA']}");   // AA (left earlier) still sits under A
remove_group($pdo, who('You'), $ids['A'], 'REMOVE');
$p = plan_reactivate(m(), who('You'), $ids['A']);
check('reactivating A\'s group does NOT revive AA, who had left earlier', array_column($p['team'], 'name') === ['AB']);

echo "\nTeam admin limits\n";
setup(['A' => ['group_manager', null], 'B' => ['unit_manager', 'A'], 'BA' => ['agent', 'B'], 'C' => ['unit_manager', null], 'CA' => ['agent', 'C']]);
set_team_admin($pdo, who('You'), $ids['A'], true);
$A = fn () => who('A');
check('team admin A can manage B and BA', can_manage(m(), $A(), $ids['B']) && can_manage(m(), $A(), $ids['BA']));
check('...but not himself, You, or C\'s group', !can_manage(m(), $A(), $ids['A']) && !can_manage(m(), $A(), $ids['You']) && !can_manage(m(), $A(), $ids['CA']));
apply_rank_change($pdo, $A(), $ids['BA'], 'unit_manager');
check('A promotes BA to Unit Manager (parallel under B, stays)', who('BA')['rank_label'] === 'unit_manager' && upline('BA') === 'B');
throws('A cannot change his own rank', 403, fn () => apply_rank_change($pdo, $A(), $ids['A'], 'unit_manager'));
throws('A cannot change C\'s agent', 403, fn () => apply_rank_change($pdo, $A(), $ids['CA'], 'unit_manager'));
throws('A cannot move BA into C\'s group', 403, fn () => move_member($pdo, $A(), $ids['BA'], $ids['C']));
throws('A cannot make someone top-level', 403, fn () => move_member($pdo, $A(), $ids['BA'], null));
move_member($pdo, $A(), $ids['BA'], $ids['A']);
check('A can move BA within his own group', upline('BA') === 'A');
throws('A cannot remove a whole group', 403, fn () => plan_remove_group($pdo, m(), $A(), $ids['B']));
throws('A cannot grant team admin', 403, fn () => set_team_admin($pdo, $A(), $ids['B'], true));
throws('A cannot export', 403, fn () => export_candidates_csv($pdo, m(), $A()));
remove_member($pdo, $A(), $ids['BA'], $ids['A']);
check('A can remove someone who left from his group', !who('BA')['is_active']);
throws('team admin needs a Group Manager', 422, fn () => set_team_admin($pdo, who('You'), $ids['C'], true));
check('a plain Unit Manager can manage nobody', !can_manage(m(), who('C'), $ids['CA']));

echo "\nWhat each person sees on the Team page\n";
$t = team_tree($pdo, m(), $A(), true);
check('A sees only his group, with himself as the top', array_column($t['members'], 'name') === ['A', 'B', 'BA'] && $t['members'][0]['parent'] === null);
check('C sees only C and CA, and may manage nobody', array_column(team_tree($pdo, m(), who('C'), false)['members'], 'name') === ['C', 'CA'] && !array_filter(array_column(team_tree($pdo, m(), who('C'), false)['members'], 'can_manage')));
check('super admin sees everyone', count(team_tree($pdo, m(), who('You'), true)['members']) === 6);
check('inactive members hidden unless asked', !in_array('BA', array_column(team_tree($pdo, m(), who('You'), false)['members'], 'name'), true));

echo "\nReview fixes (found by the stress test and adversarial probes)\n";
setup(['Amy' => ['group_manager', null], 'Bob' => ['agent', 'Amy']]);
move_member($pdo, who('You'), $ids['Amy'], null);   // Amy becomes top-level, NOT under You
$cfg2 = ['default_owner_email' => 'you@x.com', 'ip_salt' => 's', 'rate_limit_per_hour' => 99];
$yc = start_candidate($pdo, $cfg2, valid_start(['mobile' => '0123000001', 'email' => 'p1@x.com']), null)['candidate_id'];
throws('the super admin can never be placed under another member', 422, fn () => move_member($pdo, who('You'), $ids['You'], $ids['Amy']));
check('...so a top-level Group Manager still cannot read the super admin\'s candidates', !can_see_candidate(m(), who('Amy'), (int)$pdo->query("SELECT owner_member_id FROM candidates WHERE id = $yc")->fetchColumn()));
throws('the member editor refuses to give the super admin an upline too', 422, fn () => update_member($pdo, $me['id'], $ids['You'], ['upline_id' => $ids['Amy']]));
setup(['A' => ['unit_manager', null], 'AA' => ['agent', 'A'], 'AB' => ['agent', 'A']]);
remove_member($pdo, who('You'), $ids['AB'], $ids['A']);
$batch = who('AB')['removal_batch'];
throws('a removed member cannot be removed again', 422, fn () => remove_member($pdo, who('You'), $ids['AB'], $ids['A']));
check('...and the removal batch id is untouched', who('AB')['removal_batch'] === $batch && $batch !== null);
throws('a removed member cannot be given a new rank', 403, fn () => apply_rank_change($pdo, who('You'), $ids['AB'], 'group_manager'));
throws('a removed member cannot be moved', 403, fn () => move_member($pdo, who('You'), $ids['AB'], $ids['AA']));
check('a removed member shows no Manage rights in the tree', !array_values(array_filter(team_tree($pdo, m(), who('You'), true)['members'], fn ($n) => $n['name'] === 'AB'))[0]['can_manage']);
$pdo->exec("UPDATE members SET upline_id = {$ids['A']} WHERE id = {$ids['AB']}");
$p = plan_rank_change(m(), $ids['A'], 'agent');
check('demotion preview leaves removed people out (only AA moves)', moved($p) === ['AA->You']);
throws('choosing the rank someone already has is refused', 422, fn () => apply_rank_change($pdo, who('You'), $ids['A'], 'unit_manager'));
setup(['A' => ['agent', null], 'AA' => ['agent', 'A'], 'AAx' => ['agent', 'AA'], 'AAy' => ['agent', 'AA']]);
remove_member($pdo, who('You'), $ids['AAx'], $ids['AA']);
$pdo->exec("UPDATE members SET upline_id = {$ids['AA']} WHERE id = {$ids['AAx']}");   // AAx left earlier but still hangs under AA as history
$bp = plan_rank_change(m(), $ids['AA'], 'unit_manager');
check('breakaway preview counts only live people under them (1 of 2)', moved($bp) === ['AA->You'] && $bp['moves'][0]['team_size'] === 1, json_encode($bp['moves']));
setup(['P' => ['agent', null], 'X' => ['agent', 'P'], 'Y' => ['agent', 'X']]);
$pdo->exec("UPDATE members SET rank_label = 'unit_manager' WHERE id = {$ids['Y']}");   // Y outranks X and P (placed by hand)
check('remove-someone default skips an upline who is too junior for the team (goes to You, not P)', plan_remove_member($pdo, m(), who('You'), $ids['X'])['default_to']['name'] === 'You');
setup(['P' => ['unit_manager', null], 'G' => ['group_manager', 'P']]);
$pdo->exec("UPDATE members SET rank_label = 'group_manager' WHERE id = {$ids['P']}");
setup(['P' => ['unit_manager', null], 'G' => ['unit_manager', 'P']]);
remove_member($pdo, who('You'), $ids['G'], $ids['P']);
$pdo->exec("UPDATE members SET rank_label = 'group_manager' WHERE id = {$ids['G']}");   // G was promoted elsewhere while removed
check('reactivate default skips an old upline who is junior to them (goes to You, not P)', plan_reactivate(m(), who('You'), $ids['G'])['default_to']['name'] === 'You');

finish();
