<?php
declare(strict_types=1);

// Run: php tests/phase3.php   Access rules for the candidate dashboard (SQLite stand-in, no network).
require_once __DIR__ . '/../private/lib/candidate_access.php';
require_once __DIR__ . '/helpers.php';

$pdo = fresh_db();
$cfg = ['default_owner_email' => 'you@x.com', 'ip_salt' => 's', 'rate_limit_per_hour' => 1000];
$me = create_member($pdo, null, ['email' => 'you@x.com', 'name' => 'You', 'rank' => 'unit_manager', 'is_admin' => true, 'referral_code' => 'cf']);
$mk = fn (string $n, int $up, string $rank = 'agent') => create_member($pdo, $me['id'], ['email' => strtolower($n) . '@x.com', 'name' => $n, 'upline_id' => $up, 'rank' => $rank, 'referral_code' => strlen($n) < 2 ? 'ref-' . strtolower($n) : strtolower($n)]);
$A = $mk('A', $me['id'], 'unit_manager'); $B = $mk('B', $me['id']); $C = $mk('C', $me['id']); $D = $mk('D', $me['id']);
$AA = $mk('AA', $A['id']); $BB = $mk('BB', $B['id']);
$ids = ['you' => $me['id'], 'A' => $A['id'], 'B' => $B['id'], 'C' => $C['id'], 'D' => $D['id'], 'AA' => $AA['id'], 'BB' => $BB['id']];
$phone = 100000000;
$cand = function (string $ownerRef, array $over = []) use ($pdo, $cfg, &$phone): int {   // a finished candidate owned via ?ref=
    $phone++;
    $r = start_candidate($pdo, $cfg, valid_start(['ref' => $ownerRef, 'mobile' => '0' . $phone, 'email' => "c$phone@x.com", 'name' => "Cand $ownerRef $phone"] + $over), null);
    submit_assessment($pdo, $r['submit_token'], sample_answers());
    return $r['candidate_id'];
};
$c = ['you' => $cand('cf'), 'A' => $cand('ref-a'), 'B' => $cand('ref-b'), 'C' => $cand('ref-c'), 'D' => $cand('ref-d'), 'AA' => $cand('aa'), 'BB' => $cand('bb')];
$map = fn () => load_member_map($pdo);
$viewer = fn (string $who) => $map()[$ids[$who]];
$seen = function (string $who, array $f = []) use ($pdo, $map, $viewer, $c): array {
    $r = list_candidates($pdo, $map(), $viewer($who), $f + ['include_unfinished' => true]);
    $byId = array_flip($c);
    $names = array_map(fn ($x) => $byId[$x['id']] ?? "other{$x['id']}", $r['candidates']);
    sort($names);
    return $names;
};
$want = function (string ...$x): array { sort($x); return $x; };

echo "Who sees whose candidates (your A/B/AA/BB team)\n";
check('You (admin) see all 7', $seen('you') === $want('you', 'A', 'B', 'C', 'D', 'AA', 'BB'));
check('A sees A + AA', $seen('A') === $want('A', 'AA'));
check('B sees B + BB', $seen('B') === $want('B', 'BB'));
check('C sees only C', $seen('C') === $want('C'));
check('D sees only D', $seen('D') === $want('D'));
check('AA sees only AA', $seen('AA') === $want('AA'));
check('BB sees only BB', $seen('BB') === $want('BB'));
foreach ([['A', 'BB'], ['B', 'AA'], ['C', 'D'], ['D', 'C'], ['AA', 'A'], ['A', 'you'], ['BB', 'B']] as [$v, $o]) {
    throws("$v cannot open $o's candidate (404, existence not revealed)", 404, fn () => get_candidate($pdo, $map(), $viewer($v), $c[$o]));
}
foreach ([['you', 'AA'], ['A', 'AA'], ['A', 'A'], ['B', 'BB']] as [$v, $o]) {
    check("$v can open $o's candidate", get_candidate($pdo, $map(), $viewer($v), $c[$o])['id'] === $c[$o]);
}
throws('unknown candidate id', 404, fn () => get_candidate($pdo, $map(), $viewer('you'), 99999));

echo "\nList filters\n";
check('mine=1 for You returns only your own', $seen('you', ['mine' => true]) === $want('you'));
check('owner_id inside the tree works (A views AA)', $seen('A', ['owner_id' => $ids['AA']]) === $want('AA'));
throws('owner_id outside the tree is refused (A asks for BB)', 404, fn () => list_candidates($pdo, $map(), $viewer('A'), ['owner_id' => $ids['BB']]));
check('search by name', $seen('you', ['q' => 'Cand bb']) === $want('BB'));
check('search by mobile fragment', $seen('you', ['q' => (string)(100000000 + 3)]) === $want('B'));
check('search cannot be widened by % or _ (LIKE wildcards are escaped)', $seen('you', ['q' => '%']) === [] && $seen('you', ['q' => '_']) === [] && $seen('you', ['q' => 'Cand%']) === []);
check('search with SQL-looking text is harmless', $seen('you', ["q" => "x' OR '1'='1"]) === []);
check('search respects visibility (A searching "Cand bb")', $seen('A', ['q' => 'Cand bb']) === []);
throws('unknown status filter', 422, fn () => list_candidates($pdo, $map(), $viewer('you'), ['status' => 'hacked']));
$open = start_candidate($pdo, $cfg, valid_start(['ref' => 'ref-a', 'mobile' => '0188880001', 'email' => 'open@x.com']), null);
check('unfinished tests hidden by default, shown with the flag', count(list_candidates($pdo, $map(), $viewer('A'), [])['candidates']) === 2 && count(list_candidates($pdo, $map(), $viewer('A'), ['include_unfinished' => true])['candidates']) === 3);
$rows = list_candidates($pdo, $map(), $viewer('you'), ['include_unfinished' => true]);
check('list rows carry code ESC and a verdict, never tokens or IP hashes', $rows['candidates'][1]['code'] === 'ESC' && isset($rows['candidates'][1]['verdict']['title']) && !str_contains(json_encode($rows), 'token') && !str_contains(json_encode($rows), 'ip_hash'));
check('unfinished row has no code/verdict', array_key_exists('code', $rows['candidates'][0]) && $rows['candidates'][0]['code'] === null && $rows['candidates'][0]['verdict'] === null && !$rows['candidates'][0]['completed']);
check('pagination: 7 rows -> 1 page of 25', $rows['total'] === 8 && $rows['page'] === 1 && $rows['page_size'] === 25);

echo "\nDetail + advisor notes\n";
$d = get_candidate($pdo, $map(), $viewer('A'), $c['AA']);
check('detail has scores, the advisor assessment and the verdict', $d['scores']['E']['total'] === 34 && $d['assessment']['verdict']['key'] === 'v-good' && count($d['assessment']['why']) > 0 && count($d['assessment']['questions']) > 0);
check('owner chain: AA -> A -> You', array_column($d['owner_chain'], 'name') === ['AA', 'A', 'You']);
check('details echo what the candidate entered', $d['details']['field'] === 'Marketing' && $d['details']['edu'] === 3 && $d['consent']['version'] === CONSENT_VERSION);
check('Chinese advisor notes on request', get_candidate($pdo, $map(), $viewer('A'), $c['AA'], 'zh')['assessment']['verdict']['title'] === '合适，需要行政支援');
check('detail never exposes token hash or ip hash', !str_contains(json_encode($d), 'hash'));
check('AA gets reassign options = only AA; admin gets every active member', array_column($d['reassign_options'], 'name') === ['A', 'AA'] && count(get_candidate($pdo, $map(), $viewer('you'), $c['AA'])['reassign_options']) === 7);

echo "\nRepeat applicant: the link that was used is visible\n";
$firstA = $pdo->query('SELECT mobile FROM candidates WHERE id = ' . $c['A'])->fetchColumn();
$rep = start_candidate($pdo, $cfg, valid_start(['ref' => 'ref-b', 'mobile' => $firstA, 'email' => 'viaB2@x.com', 'name' => 'Repeat Rita']), null);
submit_assessment($pdo, $rep['submit_token'], sample_answers());
$rd = get_candidate($pdo, $map(), $viewer('A'), $rep['candidate_id']);
check('Rita used B\'s link but was already A\'s: A (owner) sees "link used: B"', $rd['owner']['name'] === 'A' && $rd['via_link']['name'] === 'B' && $rd['duplicate_of'] !== null);
$rl = array_values(array_filter(list_candidates($pdo, $map(), $viewer('A'), [])['candidates'], fn ($x) => $x['name'] === 'Repeat Rita'))[0];
check('the list row carries it too', ($rl['via_link']['name'] ?? '') === 'B');
throws('B cannot open Rita (she stays with A; B only gets a notice)', 404, fn () => get_candidate($pdo, $map(), $viewer('B'), $rep['candidate_id']));
check('an ordinary candidate has no "link used" line', get_candidate($pdo, $map(), $viewer('A'), $c['A'])['via_link'] === null);
$csvx = export_candidates_csv($pdo, $map(), $viewer('you'));
check('CSV (admin) has a "link used" column, B for Rita', str_contains($csvx, '"link used"') && preg_match('/"Repeat Rita".*?"A","B"/', $csvx) === 1);
$pdo->exec("DELETE FROM assessments WHERE candidate_id = {$rep['candidate_id']}"); $pdo->exec("DELETE FROM candidates WHERE id = {$rep['candidate_id']}");

echo "\nStatus changes\n";
update_candidate($pdo, $map(), $viewer('A'), $c['AA'], ['pipeline_status' => 'contacted']);
check('upline A moves AA\'s candidate to contacted', get_candidate($pdo, $map(), $viewer('AA'), $c['AA'])['status'] === 'contacted');
update_candidate($pdo, $map(), $viewer('AA'), $c['AA'], ['pipeline_status' => 'interviewed']);
check('AA can update their own candidate', get_candidate($pdo, $map(), $viewer('you'), $c['AA'])['status'] === 'interviewed');
throws('unknown status value', 422, fn () => update_candidate($pdo, $map(), $viewer('A'), $c['AA'], ['pipeline_status' => 'rich']));
throws('B cannot change AA\'s candidate', 404, fn () => update_candidate($pdo, $map(), $viewer('B'), $c['AA'], ['pipeline_status' => 'joined']));
check('...and it did not change', get_candidate($pdo, $map(), $viewer('you'), $c['AA'])['status'] === 'interviewed');

echo "\nReassigning\n";
throws('AA cannot give a candidate to their upline A', 403, fn () => update_candidate($pdo, $map(), $viewer('AA'), $c['AA'], ['owner_member_id' => $ids['A']]));
throws('A cannot hand a candidate to B (not in A\'s team)', 403, fn () => update_candidate($pdo, $map(), $viewer('A'), $c['A'], ['owner_member_id' => $ids['B']]));
throws('A cannot hand a candidate to You (upline)', 403, fn () => update_candidate($pdo, $map(), $viewer('A'), $c['A'], ['owner_member_id' => $ids['you']]));
throws('A cannot hand a candidate to someone who does not exist', 403, fn () => update_candidate($pdo, $map(), $viewer('A'), $c['A'], ['owner_member_id' => 9999]));
update_candidate($pdo, $map(), $viewer('A'), $c['A'], ['owner_member_id' => $ids['AA']]);
check('A hands A\'s candidate down to AA; AA now sees it', in_array('A', $seen('AA'), true));
update_candidate($pdo, $map(), $viewer('you'), $c['BB'], ['owner_member_id' => $ids['C']]);
check('You (admin) move BB\'s candidate to C; C sees it, B no longer does', in_array('BB', $seen('C'), true) && !in_array('BB', $seen('B'), true) && !in_array('BB', $seen('BB'), true));
update_member($pdo, $me['id'], $D['id'], ['is_active' => false]);
throws('cannot assign to a deactivated member (even as admin)', 403, fn () => update_candidate($pdo, $map(), $viewer('you'), $c['C'], ['owner_member_id' => $ids['D']]));
check('a deactivated member\'s existing candidates stay visible to You', in_array('D', $seen('you'), true));
check('reassign + status written to the audit log', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('candidate.reassign','candidate.status')")->fetchColumn() === 4);
check('reassigning to the current owner is a no-op', (function () use ($pdo, $map, $viewer, $c, $ids) { $n0 = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='candidate.reassign'")->fetchColumn();
    update_candidate($pdo, $map(), $viewer('you'), $c['C'], ['owner_member_id' => $ids['C']]); return (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='candidate.reassign'")->fetchColumn() === $n0; })());

echo "\nDelete (PDPA) and CSV export (admin only)\n";
throws('an agent cannot delete even their own candidate', 403, fn () => delete_candidate($pdo, $map(), $viewer('C'), $c['C']));
throws('...nor export', 403, fn () => export_candidates_csv($pdo, $map(), $viewer('A')));
$before = (int)$pdo->query('SELECT COUNT(*) FROM assessments')->fetchColumn();
delete_candidate($pdo, $map(), $viewer('you'), $c['D']);
check('admin deletes a candidate and their results', (int)$pdo->query('SELECT COUNT(*) FROM candidates WHERE id = ' . $c['D'])->fetchColumn() === 0 && (int)$pdo->query('SELECT COUNT(*) FROM assessments')->fetchColumn() === $before - 1);
$aud = $pdo->query("SELECT detail FROM audit_log WHERE action='candidate.delete'")->fetchColumn();
check('delete audit entry holds no personal data', !preg_match('/Cand|@|\+60/', (string)$aud));
throws('deleting twice', 404, fn () => delete_candidate($pdo, $map(), $viewer('you'), $c['D']));
$evil = start_candidate($pdo, $cfg, valid_start(['name' => '=HYPERLINK("http://evil","click"), "quoted"', 'mobile' => '0177770001', 'email' => 'evil@x.com', 'ref' => 'ref-a']), null);
$csv = export_candidates_csv($pdo, $map(), $viewer('you'));
check('CSV starts with a BOM and a header row', str_starts_with($csv, "\xEF\xBB\xBF\"id\",") );
check('CSV neutralises a formula in a name and escapes quotes', str_contains($csv, "\"'=HYPERLINK(\"\"http://evil\"\",\"\"click\"\"), \"\"quoted\"\"\""));
check('CSV has one row per candidate + header', count(explode("\r\n", trim($csv))) === 1 + (int)$pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn());
check('CSV contains the verdict and advisor name', str_contains($csv, 'Good fit, needs admin support') && str_contains($csv, '"AA"'));

finish();
