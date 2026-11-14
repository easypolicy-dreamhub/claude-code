<?php
declare(strict_types=1);

// Run: php tests/access.php   Members & Access: tool ticks, Cloudflare sync (with a pretend Cloudflare), the page gate.
// A throw-away copy of the Client Portal bridge, so the tests never touch a real agents.json.
$bridgeDir = sys_get_temp_dir() . '/bridge_' . getmypid();
mkdir($bridgeDir);
copy(__DIR__ . '/fixtures/team-bridge.php', $bridgeDir . '/team-bridge.php');
putenv('E3_PORTAL_BRIDGE=' . $bridgeDir . '/team-bridge.php');
require_once __DIR__ . '/../private/lib/access.php';
require_once __DIR__ . '/../private/lib/gate.php';
require_once __DIR__ . '/helpers.php';

function db(): PDO {
    $pdo = fresh_db();
    $pdo->exec("ALTER TABLE members ADD COLUMN member_type TEXT NOT NULL DEFAULT 'team'");
    $pdo->exec('ALTER TABLE members ADD COLUMN is_suspended INTEGER NOT NULL DEFAULT 0');
    $pdo->exec('CREATE TABLE tool_access (member_id INTEGER NOT NULL, tool_key TEXT NOT NULL, PRIMARY KEY (member_id, tool_key))');
    return $pdo;
}

/** A pretend Cloudflare: one policy with emails, a deny rule we must not touch, and a log of calls. */
function fake_cf(array $emails, ?array &$log = null, bool $failPut = false): CfPolicy {
    $log = [];
    $state = ['policy' => [
        'id' => 'pid', 'uid' => 'u', 'created_at' => 'x', 'updated_at' => 'y', 'reusable' => true, 'app_count' => 2, 'version' => 3,
        'purpose_justification_prompt' => null, 'weird_new_field' => 'x', 'connection_rules' => new stdClass(), 'approval_groups' => [],
        'name' => 'Email Access', 'decision' => 'allow', 'session_duration' => '24h', 'exclude' => [['email_domain' => ['domain' => 'bad.com']]], 'require' => [],
        'include' => array_map(fn ($e) => ['email' => ['email' => $e]], $emails),
    ]];
    $http = function (string $method, string $url, ?array $body, string $token) use (&$state, &$log, $failPut): array {
        $log[] = [$method, preg_replace('#^.*/client/v4#', '', $url), $body];
        if ($token !== 'tok') { return ['success' => false, 'errors' => [['message' => 'bad token']]]; }
        if ($method === 'GET') { return ['success' => true, 'result' => $state['policy']]; }
        if ($method === 'PUT') {
            if ($failPut) { return ['success' => false, 'errors' => [['message' => 'permission denied']]]; }
            foreach (['id', 'uid', 'created_at', 'updated_at', 'reusable', 'app_count', 'version', 'weird_new_field'] as $k) { if (array_key_exists($k, (array)$body)) { return ['success' => false, 'errors' => [['message' => "read-only key $k sent"]]]; } }
            $state['policy'] = $body + ['id' => 'pid'];
            return ['success' => true, 'result' => $state['policy']];
        }
        return ['success' => true, 'result' => []];
    };
    return new CfPolicy(['token' => 'tok', 'account_id' => 'acc', 'policy_id' => 'pid'], $http);
}

function setup(): array {
    $pdo = db();
    $me = create_member($pdo, null, ['email' => 'you@x.com', 'name' => 'You', 'rank' => 'group_manager', 'is_admin' => true, 'referral_code' => 'you']);
    return [$pdo, load_member_map($pdo)[$me['id']]];
}
function tools_of(PDO $pdo, string $email): array { $m = access_find_by_email(load_member_map($pdo), $email); return ticked_tools($pdo, (int)$m['id']); }

echo "Catalog\n";
check('23 tools in the catalog (your 22 + Members & Access)', count(tool_catalog()) === 23);
check('Site Admin tools are locked and not grantable', !array_intersect(['testimonials', 'events', 'site-settings', 'access'], grantable_keys()));
check('Client Portal is switched through the bridge, not stored as a tick', !in_array('client-portal', grantable_keys(), true) && in_array('client-portal', switchable_keys(), true) && count(switchable_keys()) === 19);
check('basic set = Servicing + Sales + Recruitment (12)', count(basic_keys()) === 12 && !in_array('monthly-report', basic_keys(), true) && !in_array('team', basic_keys(), true));
check('career-code/dashboard maps to Career Code Candidates', tool_for_path('career-code/dashboard/index.html')['key'] === 'career-code-candidates');
check('career-code/live maps to Career Code', tool_for_path('career-code/live/')['key'] === 'career-code');
check('events and event-links do not mix up', tool_for_path('events/')['key'] === 'events' && tool_for_path('event-links/')['key'] === 'event-links');
check('unknown folder is not a tool', tool_for_path('api/') === null && tool_for_path('share/x.html') === null);

echo "\nTicks and the super admin\n";
[$pdo, $you] = setup();
$david = access_add($pdo, null, $you, ['email' => 'David@X.com ', 'name' => 'David', 'type' => 'team']);
check('team member starts with the basic set', tools_of($pdo, 'david@x.com') === basic_keys());
$dm = access_find_by_email(load_member_map($pdo), 'david@x.com');
check('new team member sits under the super admin', $dm['upline_id'] === $you['id'] && member_type($dm) === 'team');
$cindy = access_add($pdo, null, $you, ['email' => 'cindy@x.com', 'name' => 'Cindy', 'type' => 'admin']);
$cm = access_find_by_email(load_member_map($pdo), 'cindy@x.com');
check('admin starts with nothing and has no upline', tools_of($pdo, 'cindy@x.com') === [] && $cm['upline_id'] === null && member_type($cm) === 'admin');
check('super admin can use everything incl. locked', can_use_tool($pdo, $you, 'site-settings') && can_use_tool($pdo, $you, 'monthly-report'));
check('Cindy cannot use Monthly Report yet', !can_use_tool($pdo, $cm, 'monthly-report'));
access_set_tool($pdo, $you, $cindy['id'], 'monthly-report', true);
$cm = load_member_map($pdo)[$cindy['id']];
check('tick Monthly Report -> allowed', can_use_tool($pdo, $cm, 'monthly-report'));
check('...but still not Agency Report (admins do not cross)', !can_use_tool($pdo, $cm, 'agency-report'));
access_set_tool($pdo, $you, $cindy['id'], 'monthly-report', false);
check('untick -> blocked again', !can_use_tool($pdo, load_member_map($pdo)[$cindy['id']], 'monthly-report'));
throws('cannot tick a Site Admin tool for someone', 422, fn () => access_set_tool($pdo, $you, $cindy['id'], 'site-settings', true));
throws('cannot change the super admin\'s ticks', 403, fn () => access_set_tool($pdo, $you, $you['id'], 'team', false));
check('locked tools stay super-admin-only even if a row sneaks into the table',
    (function () use ($pdo, $cindy) { $pdo->exec('INSERT INTO tool_access VALUES (' . $cindy['id'] . ", 'site-settings')"); return !can_use_tool($pdo, load_member_map($pdo)[$cindy['id']], 'site-settings'); })());
check('Client Portal card is hidden until it is switched on', !in_array('client-portal', allowed_tools($pdo, load_member_map($pdo)[$cindy['id']]), true));
check('set_ticked_tools ignores unknown keys', set_ticked_tools($pdo, $cindy['id'], ['team', 'nonsense', 'testimonials']) === ['team']);
$copy = access_add($pdo, null, $you, ['email' => 'amy@x.com', 'name' => 'Amy', 'type' => 'tester', 'copy_from' => $david['id']]);
check('"copy from David" copies his ticks', tools_of($pdo, 'amy@x.com') === tools_of($pdo, 'david@x.com'));
$ex = access_add($pdo, null, $you, ['email' => 'ex@x.com', 'name' => 'Ex', 'type' => 'tester', 'tools' => ['sum-assured', 'bogus']]);
check('chosen tools are kept, junk dropped', tools_of($pdo, 'ex@x.com') === ['sum-assured']);

echo "\nValidation\n";
throws('duplicate email', 409, fn () => access_add($pdo, null, $you, ['email' => 'david@x.com', 'name' => 'D2', 'type' => 'team']));
throws('your own email', 409, fn () => access_add($pdo, null, $you, ['email' => 'you@x.com', 'name' => 'Me', 'type' => 'team']));
throws('bad email', 422, fn () => access_add($pdo, null, $you, ['email' => 'nope', 'name' => 'N', 'type' => 'team']));
throws('missing name', 422, fn () => access_add($pdo, null, $you, ['email' => 'n@x.com', 'name' => ' ', 'type' => 'team']));
throws('bad type', 422, fn () => access_add($pdo, null, $you, ['email' => 'n@x.com', 'name' => 'N', 'type' => 'boss']));
access_update($pdo, $you, $cindy['id'], ['name' => '  Cindy   Lim ', 'type' => 'tester']);
$cm = load_member_map($pdo)[$cindy['id']];
check('rename + Admin->Tester', $cm['name'] === 'Cindy Lim' && member_type($cm) === 'tester');
access_update($pdo, $you, $cindy['id'], ['type' => 'team']);
$cm = load_member_map($pdo)[$cindy['id']];
check('Tester -> Team member goes under the super admin', member_type($cm) === 'team' && $cm['upline_id'] === $you['id']);
$kid = access_add($pdo, null, $you, ['email' => 'kid@x.com', 'name' => 'Kid', 'type' => 'team']);
$pdo->prepare('UPDATE members SET upline_id = ? WHERE id = ?')->execute([$david['id'], $kid['id']]);
throws('Team member with people under them cannot become Tester yet', 422, fn () => access_update($pdo, $you, $david['id'], ['type' => 'tester']));
$pdo->prepare('UPDATE members SET upline_id = ? WHERE id = ?')->execute([$you['id'], $kid['id']]);
access_update($pdo, $you, $david['id'], ['type' => 'admin']);
$dm = load_member_map($pdo)[$david['id']];
check('Team member -> Admin leaves the tree (no upline), ticks kept', member_type($dm) === 'admin' && $dm['upline_id'] === null && tools_of($pdo, 'david@x.com') === basic_keys());
throws('bad type on update', 422, fn () => access_update($pdo, $you, $david['id'], ['type' => 'king']));
throws('super admin cannot be edited here', 403, fn () => access_update($pdo, $you, $you['id'], ['name' => 'X']));

echo "\nClient Portal switch (same store as the Team page)\n";
[$pdo, $you] = setup();
$pa = access_add($pdo, null, $you, ['email' => 'pa@x.com', 'name' => 'Pa', 'type' => 'team']);
$pm = fn () => load_member_map($pdo)[$pa['id']];
check('portal off by default', !portal_enabled('pa@x.com') && !in_array('client-portal', allowed_tools($pdo, $pm()), true));
access_set_tool($pdo, $you, $pa['id'], 'client-portal', true);
check('tick Client Portal -> on in the portal record', portal_enabled('pa@x.com') && json_decode(file_get_contents(e3pb_file()), true)['members']['pa@x.com']['enabled'] === true);
check('...and the card is offered', in_array('client-portal', allowed_tools($pdo, $pm()), true));
$ov = access_overview($pdo, null, load_member_map($pdo));
check('overview lists it among their tools', in_array('client-portal', array_column($ov['people'], null, 'email')['pa@x.com']['tools'], true));
e3pb_toggle('pa@x.com', 'Pa', false);
$ov = access_overview($pdo, null, load_member_map($pdo));
check('switch flipped on the Team page shows here too (one store)', !in_array('client-portal', array_column($ov['people'], null, 'email')['pa@x.com']['tools'], true));
access_set_tools($pdo, $you, $pa['id'], ['team', 'client-portal']);
check('tools list with Client Portal: stored ticks + portal on', tools_of($pdo, 'pa@x.com') === ['team'] && portal_enabled('pa@x.com'));
access_set_tools($pdo, $you, $pa['id'], []);
check('"None" switches the Client Portal off too', tools_of($pdo, 'pa@x.com') === [] && !portal_enabled('pa@x.com'));
$cp = access_add($pdo, null, $you, ['email' => 'cp@x.com', 'name' => 'Cp', 'type' => 'tester', 'copy_from' => $pa['id']]);
access_set_tool($pdo, $you, $pa['id'], 'client-portal', true);
$cp2 = access_add($pdo, null, $you, ['email' => 'cp2@x.com', 'name' => 'Cp2', 'type' => 'tester', 'copy_from' => $pa['id']]);
check('"copy from" copies the Client Portal too', portal_enabled('cp2@x.com') && !portal_enabled('cp@x.com'));
$r = access_add($pdo, null, $you, ['email' => 'all@x.com', 'name' => 'All', 'type' => 'admin', 'tools' => switchable_keys()]);
$ex1 = access_add($pdo, null, $you, ['email' => 'portaluser@x.com', 'name' => 'PU', 'type' => 'tester', 'tools' => []]);
check('a brand-new person is not on the Client Portal', !portal_enabled('portaluser@x.com'));
e3pb_toggle('already@x.com', 'Already', true);   // someone who already uses the Client Portal, not yet set up here
$ov0 = access_overview($pdo, fake_cf(['you@x.com', 'already@x.com'], $lo0), load_member_map($pdo));
check('a Cloudflare-only email that already uses the Client Portal says so', array_column($ov0['people'], null, 'email')['already@x.com']['tools'] === ['client-portal']);
access_add($pdo, null, $you, ['email' => 'already@x.com', 'name' => 'Already', 'type' => 'tester', 'tools' => []]);
check('setting them up with "Nothing" keeps their Client Portal ON', portal_enabled('already@x.com'));
$cfx = fake_cf(['you@x.com', 'already2@x.com'], $lx);
$rr = access_add($pdo, $cfx, $you, ['email' => 'already2@x.com', 'name' => 'A2', 'type' => 'tester']);
check('already on Cloudflare: reported as not newly added', $rr['cloudflare'] === 'ok' && $rr['cloudflare_added'] === false && !array_filter($lx, fn ($c) => $c[0] === 'PUT'));
$rr = access_add($pdo, $cfx, $you, ['email' => 'fresh@x.com', 'name' => 'Fresh', 'type' => 'tester']);
check('a new email: reported as added to Cloudflare', $rr['cloudflare_added'] === true);
check('add with "all tools" turns the portal on', portal_enabled('all@x.com') && $r['portal'] === 'on' && count(tools_of($pdo, 'all@x.com')) === 18);
access_remove($pdo, null, $you, 'cp2@x.com');
check('removing someone switches the portal off (data kept)', !portal_enabled('cp2@x.com') && isset(e3pb_load()['cp2@x.com']));
// pause also switches it off, resume brings it back
access_set_suspended($pdo, $you, $pa['id'], true);
check('pause switches the Client Portal off', !portal_enabled('pa@x.com'));
access_set_tools($pdo, $you, $pa['id'], ['sum-assured']);
access_set_suspended($pdo, $you, $pa['id'], false);
check('resume switches it back on, ticks kept', portal_enabled('pa@x.com') && tools_of($pdo, 'pa@x.com') === ['sum-assured']);
$pb = access_add($pdo, null, $you, ['email' => 'pb@x.com', 'name' => 'Pb', 'type' => 'team']);
access_set_suspended($pdo, $you, $pb['id'], true); access_set_suspended($pdo, $you, $pb['id'], false);
check('pause/resume leaves a portal that was off, off', !portal_enabled('pb@x.com'));
// bridge missing
putenv('E3_PORTAL_BRIDGE=' . $bridgeDir . '/nope.php');
$ov = access_overview($pdo, null, load_member_map($pdo));
check('bridge missing: switch reported unavailable, nothing else breaks', array_column($ov['catalog'], null, 'key')['client-portal']['available'] === false && array_column($ov['catalog'], null, 'key')['panel-finder']['available'] === true);
throws('bridge missing: switching it says so', 503, fn () => access_set_tool($pdo, $you, $pa['id'], 'client-portal', true));
$r = access_add($pdo, null, $you, ['email' => 'nb@x.com', 'name' => 'Nb', 'type' => 'admin', 'tools' => ['client-portal', 'team']]);
check('bridge missing: person is still added, ticks kept', $r['portal'] === 'failed' && tools_of($pdo, 'nb@x.com') === ['team']);
putenv('E3_PORTAL_BRIDGE=' . $bridgeDir . '/team-bridge.php');

echo "\nPause (suspend)\n";
[$pdo, $you] = setup();
$p1 = access_add($pdo, null, $you, ['email' => 'pause@x.com', 'name' => 'Pause Me', 'type' => 'team']);
$row = fn () => load_member_map($pdo)[$p1['id']];
check('not paused to start', !(int)$row()['is_suspended']);
member_access_check($row() + ['is_active' => 1]);
check('login check lets an active, unpaused member through', true);
access_set_suspended($pdo, $you, $p1['id'], true);
throws('paused member is refused at the login check', 403, fn () => member_access_check($row()));
try { member_access_check($row()); } catch (HttpError $e) { check('...with a plain message', $e->getMessage() === 'Your access is paused. Please contact your admin.'); }
check('paused: ticks and team place are kept', tools_of($pdo, 'pause@x.com') === basic_keys() && $row()['upline_id'] === $you['id'] && (int)$row()['is_active'] === 1);
$ov = access_overview($pdo, null, load_member_map($pdo));
check('overview marks them paused', array_column($ov['people'], null, 'email')['pause@x.com']['suspended'] === true);
access_set_suspended($pdo, $you, $p1['id'], false);
member_access_check($row());
check('resume -> allowed again', !(int)$row()['is_suspended']);
throws('super admin cannot be paused', 403, fn () => access_set_suspended($pdo, $you, $you['id'], true));
throws('unknown person', 404, fn () => access_set_suspended($pdo, $you, 9999, true));
$susAdmin = ['is_active' => 1, 'is_admin' => 1, 'is_suspended' => 1];
member_access_check($susAdmin);
check('a super admin flag is never locked out by a stray pause flag', true);
$old = ['is_active' => 1, 'is_admin' => 0];
member_access_check($old);
check('works before the column exists (older database)', true);
throws('removed member still refused', 403, fn () => member_access_check(['is_active' => 0, 'is_admin' => 0]));

echo "\nCloudflare\n";
[$pdo, $you] = setup();
$cf = fake_cf(['you@x.com', 'old@x.com'], $log);
$r = access_add($pdo, $cf, $you, ['email' => 'New@X.com', 'name' => 'New', 'type' => 'admin']);
$put = array_values(array_filter($log, fn ($c) => $c[0] === 'PUT'));
check('add -> one PUT with the new email on the end', count($put) === 1 && array_column(array_column($put[0][2]['include'], 'email'), 'email') === ['you@x.com', 'old@x.com', 'new@x.com']);
check('add keeps exclude rule, name, decision, session', $put[0][2]['exclude'] === [['email_domain' => ['domain' => 'bad.com']]] && $put[0][2]['name'] === 'Email Access' && $put[0][2]['decision'] === 'allow' && $put[0][2]['session_duration'] === '24h');
check('add sends no null fields and no unknown fields', !array_key_exists('purpose_justification_prompt', $put[0][2]) && !array_key_exists('weird_new_field', $put[0][2]));
check('an empty {} setting stays an object (not turned into [])', json_encode($put[0][2]['connection_rules']) === '{}' && json_encode(['x' => $put[0][2]['approval_groups']]) === '{"x":[]}');
check('add sends no read-only fields', !array_intersect(array_keys($put[0][2]), ['id', 'uid', 'created_at', 'updated_at', 'reusable', 'app_count', 'version']));
check('result says Cloudflare ok', $r['cloudflare'] === 'ok');
check('emails() lists them lowercase', $cf->emails() === ['you@x.com', 'old@x.com', 'new@x.com']);

$r = access_remove($pdo, $cf, $you, 'new@x.com');
check('remove -> off Cloudflare', $cf->emails() === ['you@x.com', 'old@x.com']);
check('remove -> signed out (revoke call made)', (bool)array_filter($log, fn ($c) => $c[0] === 'POST' && str_ends_with($c[1], '/access/organizations/revoke_user') && $c[2] === ['email' => 'new@x.com']));
check('remove -> blocked on the site', !load_member_map($pdo)[access_find_by_email(load_member_map($pdo), 'new@x.com')['id']]['is_active']);
throws('cannot remove yourself', 422, fn () => access_remove($pdo, $cf, $you, 'you@x.com'));
check('you are still on Cloudflare', in_array('you@x.com', $cf->emails(), true));
$tm = access_add($pdo, $cf, $you, ['email' => 'tm@x.com', 'name' => 'TM', 'type' => 'team']);
throws('Team member must be removed on the Team page', 422, fn () => access_remove($pdo, $cf, $you, 'tm@x.com'));
$r = access_remove($pdo, $cf, $you, 'old@x.com');
check('an email only on Cloudflare (no member row) can be removed', $cf->emails() === ['you@x.com', 'tm@x.com']);

$cf2 = new CfPolicy(['token' => 'tok', 'account_id' => 'a', 'policy_id' => 'p'], fn ($m, $u, $b, $t) => ['success' => true, 'result' => ['include' => [['email' => ['email' => 'you@x.com']]], 'exclude' => []]]);
throws('never leaves the list empty', 422, fn () => $cf2->change([], ['you@x.com'], []));
$cf3 = fake_cf(['you@x.com', 'a@x.com'], $l3);
$cf3->change([], ['you@x.com'], ['you@x.com']);
check('"keep" emails are never removed', in_array('you@x.com', $cf3->emails(), true));
$cf4 = fake_cf(['you@x.com'], $l4); $before = count($l4);
$cf4->change(['you@x.com'], [], []);
check('adding someone already there makes no PUT', !array_filter($l4, fn ($c) => $c[0] === 'PUT'));
$cfList = new CfPolicy(['token' => 'tok', 'account_id' => 'a', 'policy_id' => 'p'], function ($m, $u, $b, $t) use (&$saved) {
    if ($m === 'PUT') { $saved = $b; return ['success' => true, 'result' => $b]; }
    return ['success' => true, 'result' => ['include' => [['email_list' => ['id' => 'L1']], ['email' => ['email' => 'you@x.com']]], 'exclude' => []]];
});
$cfList->change(['z@x.com'], [], []);
check('rules that are not plain emails (a list) are kept', $saved['include'][0] === ['email_list' => ['id' => 'L1']] && count($saved['include']) === 3);
check('...and flagged', $cfList->hasOtherRules());

[$pdo, $you] = setup();
$failing = fake_cf(['you@x.com'], $lf, true);
throws('Cloudflare refuses -> add fails and nothing is created', 424, fn () => access_add($pdo, $failing, $you, ['email' => 'f@x.com', 'name' => 'F', 'type' => 'admin']));
check('...no member row was made', access_find_by_email(load_member_map($pdo), 'f@x.com') === null);
$ok = fake_cf(['you@x.com'], $lo);
access_add($pdo, $ok, $you, ['email' => 'g@x.com', 'name' => 'G', 'type' => 'admin']);
$g = access_find_by_email(load_member_map($pdo), 'g@x.com');
$failing2 = fake_cf(['you@x.com', 'g@x.com'], $lf2, true);
try { access_remove($pdo, $failing2, $you, 'g@x.com'); check('remove with Cloudflare down should fail', false); } catch (HttpError $e) { check('Cloudflare down on remove -> clear error, retryable', $e->status === 424 && str_contains($e->getMessage(), 'blocked on this site already')); }
check('...but they are already blocked on the site', !load_member_map($pdo)[$g['id']]['is_active']);

$ov = access_overview($pdo, fake_cf(['you@x.com', 'g@x.com', 'stranger@x.com'], $lv), load_member_map($pdo));
$byEmail = array_column($ov['people'], null, 'email');
check('overview: you first, pinned', $ov['people'][0]['email'] === 'you@x.com' && $ov['people'][0]['super']);
check('overview: unknown Cloudflare email shows as "new"', $byEmail['stranger@x.com']['status'] === 'new' && $byEmail['stranger@x.com']['id'] === null);
check('overview: removed person still on Cloudflare is flagged', $byEmail['g@x.com']['status'] === 'removed_still_in_cloudflare');
$tmember = access_add($pdo, null, $you, ['email' => 'solo@x.com', 'name' => 'Solo', 'type' => 'team']);
$ov = access_overview($pdo, fake_cf(['you@x.com'], $lv2), load_member_map($pdo));
check('overview: on the site but not on Cloudflare is flagged', array_column($ov['people'], null, 'email')['solo@x.com']['status'] === 'not_in_cloudflare');
$cfe = new CfPolicy(['token' => 'bad', 'account_id' => 'a', 'policy_id' => 'p'], fn () => ['success' => false, 'errors' => [['message' => 'bad token']]]);
$ov = access_overview($pdo, $cfe, load_member_map($pdo));
check('overview: Cloudflare error is reported, page still has people', $ov['cloudflare']['connected'] === false && str_contains((string)$ov['cloudflare']['error'], 'bad token') && count($ov['people']) >= 2);
$ov = access_overview($pdo, null, load_member_map($pdo));
check('overview: Cloudflare not set up', $ov['cloudflare']['configured'] === false);
check('fromConfig: missing settings -> not connected', CfPolicy::fromConfig([]) === null && CfPolicy::fromConfig(['cf_api' => ['token' => 'x']]) === null);
check('cf_sync_try: not connected is not an error', cf_sync_try([], ['a@x.com'], []) === 'not_connected');


echo "\nCloudflare check (diagnose)\n";
$cfgOk = ['cf_api' => ['token' => 'tok', 'account_id' => str_repeat('a', 32), 'policy_id' => 'bd462e1a-ff22-4975-8662-fc3c8801ac26']];
$mk = function (array $opts) { return function (string $m, string $u, ?array $b, string $t) use ($opts): array {
    if (str_ends_with($u, '/user/tokens/verify')) { return $opts['verify'] ?? ['success' => true, 'result' => ['status' => 'active']]; }
    if (str_ends_with($u, '/access/policies')) { return $opts['list'] ?? ['success' => true, 'result' => [['id' => 'bd462e1a-ff22-4975-8662-fc3c8801ac26', 'name' => 'Email Access']]]; }
    return $opts['get'] ?? ['success' => true, 'result' => ['include' => [['email' => ['email' => 'a@x.com']]]]];
}; };
$d = access_diagnose([]);
check('no cf_api block is reported', !$d[0]['ok'] && str_contains($d[0]['detail'], 'no cf_api'));
$d = access_diagnose(['cf_api' => ['token' => '', 'account_id' => 'x', 'policy_id' => '']]);
check('empty settings are named', !$d[0]['ok'] && str_contains($d[0]['detail'], 'token') && str_contains($d[0]['detail'], 'policy_id'));
$d = access_diagnose(['cf_api' => ['token' => 'tok', 'account_id' => 'abc', 'policy_id' => 'bd462e1a-ff22-4975-8662-fc3c8801ac2']], $mk([]));
check('badly formed IDs are explained with their lengths', !$d[0]['ok'] && str_contains($d[0]['detail'], '3 characters') && str_contains($d[0]['detail'], '35 characters'));
$d = access_diagnose($cfgOk, $mk(['verify' => ['success' => false, 'errors' => [['message' => 'Invalid API Token']]]]));
check('a bad token stops at the token step', count($d) === 2 && !$d[1]['ok'] && str_contains($d[1]['detail'], 'Invalid API Token'));
$d = access_diagnose($cfgOk, $mk(['list' => ['success' => false, 'errors' => [['message' => 'access.api.error.not_found']]]]));
check('cannot list policies -> account id suspected', !$d[2]['ok'] && str_contains($d[2]['detail'], 'account_id is wrong'));
$d = access_diagnose($cfgOk, $mk(['list' => ['success' => true, 'result' => [['id' => '11111111-2222-3333-4444-555555555555', 'name' => 'Email Access']]]]));
check('policy id not in the account -> lists the real ones', !$d[3]['ok'] && str_contains($d[3]['detail'], 'Email Access (11111111-2222-3333-4444-555555555555)'));
$d = access_diagnose($cfgOk, $mk([]));
check('everything fine -> all steps OK', count($d) === 6 && !array_filter($d, fn ($x) => !$x['ok']));
$d = access_diagnose($cfgOk, $mk(['get' => ['success' => false, 'errors' => [['message' => 'access.api.error.not_found']]]]));
check('policy readable by list but not by id is reported on the last step', !end($d)['ok']);

$d = access_diagnose($cfgOk, function (string $m, string $u, ?array $b, string $t): array {
    if (str_ends_with($u, '/user/tokens/verify')) { return ['success' => true, 'result' => ['status' => 'active']]; }
    if ($m === 'PUT') { return ['success' => false, 'errors' => [['code' => 12130, 'message' => 'access.api.error.invalid_request', 'source' => ['pointer' => '/include/0'], 'error_chain' => [['message' => 'bad rule']]]]]; }
    if (str_ends_with($u, '/access/policies')) { return ['success' => true, 'result' => [['id' => 'bd462e1a-ff22-4a75-8662-fc3c8801ac26', 'name' => 'Email Access']]]; }
    return ['success' => true, 'result' => ['include' => [['email' => ['email' => 'a@x.com']]]]];
});
check('a refused save is reported with Cloudflare\'s full detail', !end($d)['ok'] && str_contains(end($d)['detail'], 'invalid_request') && str_contains(end($d)['detail'], 'code 12130') && str_contains(end($d)['detail'], '/include/0') && str_contains(end($d)['detail'], 'bad rule'));
check('decode keeps {} and []', json_encode(CfPolicy::decode('{"a":{},"b":[],"c":[{"everyone":{}}],"d":null}')) === '{"a":{},"b":[],"c":[{"everyone":{}}],"d":null}');

echo "\nReturning person\n";
[$pdo, $you] = setup();
$cf = fake_cf(['you@x.com'], $lr);
$a = access_add($pdo, $cf, $you, ['email' => 'back@x.com', 'name' => 'Back', 'type' => 'tester', 'tools' => ['team']]);
access_remove($pdo, $cf, $you, 'back@x.com');
$b = access_add($pdo, $cf, $you, ['email' => 'back@x.com', 'name' => 'Back Again', 'type' => 'admin', 'tools' => ['agency-report']]);
$bm = load_member_map($pdo)[$a['id']];
check('re-adding a removed email reuses the person, active again', $b['id'] === $a['id'] && $bm['is_active'] === 1 && $bm['name'] === 'Back Again' && member_type($bm) === 'admin');
check('...with the newly chosen tools', tools_of($pdo, 'back@x.com') === ['agency-report'] && in_array('back@x.com', $cf->emails(), true));

echo "\nThe page gate\n";
$dir = sys_get_temp_dir() . '/gate_' . getmypid();
foreach (['team', 'monthly-report', 'career-code/dashboard', 'career-code/live', 'api', 'events', 'php-tool', 'site-settings', 'access'] as $d) { mkdir("$dir/$d", 0777, true); }
foreach (['team/index.html', 'monthly-report/index.html', 'career-code/dashboard/index.html', 'career-code/live/index.html', 'events/index.html', 'team/team.js', 'site-settings/index.html', 'access/index.html'] as $f) { file_put_contents("$dir/$f", "<p>$f</p>"); }
file_put_contents("$dir/api/x.html", 'secret');
check('team/ resolves to the Team tool', gate_resolve($dir, 'team/')['tool']['key'] === 'team');
check('team/index.html resolves too', gate_resolve($dir, 'team/index.html')['tool']['key'] === 'team');
check('dashboard resolves to Candidates, not Career Code', gate_resolve($dir, 'career-code/dashboard/')['tool']['key'] === 'career-code-candidates');
check('Site Settings and Members & Access are two separate tools with two separate files',
    gate_resolve($dir, 'site-settings/')['tool']['key'] === 'site-settings' && gate_resolve($dir, 'access/')['tool']['key'] === 'access'
    && gate_resolve($dir, 'site-settings/')['file'] !== gate_resolve($dir, 'access/')['file']);
check('both are locked to the super admin', gate_resolve($dir, 'site-settings/')['tool']['locked'] && gate_resolve($dir, 'access/')['tool']['locked']);
check('JS files are not served by the gate', gate_resolve($dir, 'team/team.js') === null);
check('folders that are not tools are not served', gate_resolve($dir, 'api/x.html') === null);
check('.. is refused', gate_resolve($dir, 'team/../api/x.html') === null && gate_resolve($dir, '../etc/passwd.html') === null);
check('missing page -> null', gate_resolve($dir, 'monthly-report/other.html') === null);
check('empty path -> null', gate_resolve($dir, '') === null);
[$pdo, $you] = setup();
$cin = access_add($pdo, null, $you, ['email' => 'cin@x.com', 'name' => 'Cin', 'type' => 'admin', 'tools' => ['monthly-report']]);
$cm = load_member_map($pdo)[$cin['id']];
$mr = gate_resolve($dir, 'monthly-report/')['tool']; $tm = gate_resolve($dir, 'team/')['tool']; $ev = gate_resolve($dir, 'events/')['tool'];
check('gate: ticked -> in', gate_decide($pdo, $cm, $mr)['ok']);
$d = gate_decide($pdo, $cm, $tm);
check('gate: not ticked -> 403 with the standard wording', !$d['ok'] && $d['status'] === 403 && $d['headline'] === "You don't have access to Team" && $d['detail'] === 'Ask the super admin if you need it.');
check('gate: Site Admin page for a non-admin -> super admin only', gate_decide($pdo, $cm, $ev)['detail'] === 'This page is for the super admin only.');
check('gate: super admin -> in everywhere', gate_decide($pdo, $you, $ev)['ok'] && gate_decide($pdo, $you, $tm)['ok']);
$inactive = $cm; $inactive['is_active'] = 0;
check('gate: removed person -> blocked even with ticks', !gate_decide($pdo, $inactive, $mr)['ok']);

check('gate adds the tab helper before </body>', gate_inject('<html><body><a href="/members/">x</a></body></html>') === '<html><body><a href="/members/">x</a><script src="/members/tool-tab.js" defer></script></body></html>');
check('...once only', substr_count(gate_inject(gate_inject('<body></body>')), 'tool-tab.js') === 1);
check('...and at the end when a page has no </body>', gate_inject('<p>hi</p>') === '<p>hi</p><script src="/members/tool-tab.js" defer></script>');
check('...using the LAST </body>', substr_count(gate_inject('<body>a</body> text </BODY>'), 'tool-tab.js') === 1 && str_ends_with(gate_inject('<body>a</body> text </BODY>'), '</BODY>'));
exec('rm -rf ' . escapeshellarg($dir));
exec('rm -rf ' . escapeshellarg($bridgeDir));
finish();
