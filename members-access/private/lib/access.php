<?php
declare(strict_types=1);

require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/cf_policy.php';

/**
 * Members & Access: who is on the Cloudflare list, what to call them, and which tools they may use.
 * Everything here is for the super admin; api/access.php checks that before calling any of it.
 */

/** Private step log (private/access_trace.log), switched on by api/access.php. No emails, tokens or request bodies. */
function access_trace(string $msg): void
{
    if (!defined('ACCESS_TRACE')) {
        return;
    }
    try {
        $f = dirname(__DIR__) . '/access_trace.log';
        if (is_file($f) && filesize($f) > 200000) {
            @rename($f, $f . '.old');
        }
        @file_put_contents($f, gmdate('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // logging must never break the request
    }
}

function access_find_by_email(array $map, string $email): ?array
{
    foreach ($map as $m) {
        if ($m['email'] === $email) {
            return $m;
        }
    }
    return null;
}

/** Emails that must never be taken off Cloudflare: every active super admin. */
function access_protected_emails(array $map): array
{
    return array_values(array_map(static fn (array $m): string => $m['email'], array_filter($map, static fn (array $m): bool => tool_is_super($m))));
}

function access_clean_email(string $raw): string
{
    $e = strtolower(trim($raw));
    if (!filter_var($e, FILTER_VALIDATE_EMAIL) || strlen($e) > 190) {
        throw new HttpError(422, 'Enter a valid email address.');
    }
    return $e;
}

function access_clean_name(string $raw): string
{
    $n = trim(preg_replace('/\s+/', ' ', $raw) ?? '');
    if ($n === '' || mb_strlen($n) > 120) {
        throw new HttpError(422, 'Enter a name (max 120 characters).');
    }
    return $n;
}

function access_clean_type(string $raw): string
{
    if (!isset(MEMBER_TYPES[$raw])) {
        throw new HttpError(422, 'Choose Team member, Admin or Tester.');
    }
    return $raw;
}

/** The tools a new person starts with: a Team member gets the basic set, an Admin or Tester gets nothing. */
function default_tools_for(string $type): array
{
    return $type === 'team' ? basic_keys() : [];
}

function grant_default_tools(PDO $pdo, int $memberId, string $type = 'team'): void
{
    set_ticked_tools($pdo, $memberId, default_tools_for($type));
}

/**
 * Everything the Members & Access page shows.
 * @return array{people:array,catalog:array,types:array,cloudflare:array}
 */
function access_overview(PDO $pdo, ?CfPolicy $cf, array $map): array
{
    $cfEmails = null;
    $other = false;
    $cfError = null;
    if ($cf !== null) {
        try {
            $policy = $cf->policy();
            $cfEmails = $cf->emails($policy);
            $other = $cf->hasOtherRules($policy);
        } catch (Throwable $e) {
            $cfError = $e->getMessage();
        }
    }
    $ticks = [];
    foreach ($pdo->query('SELECT member_id, tool_key FROM tool_access') as $r) {
        $ticks[(int)$r['member_id']][] = (string)$r['tool_key'];
    }
    $grantable = grantable_keys();
    $switchable = switchable_keys();
    $portalOn = portal_enabled_emails();
    $people = [];
    $seen = [];
    foreach ($map as $m) {
        $inCf = $cfEmails === null ? null : in_array($m['email'], $cfEmails, true);
        if (!$m['is_active'] && !$inCf) {
            continue;   // removed and gone from Cloudflare: nothing to show
        }
        $seen[$m['email']] = true;
        $people[] = [
            'id' => (int)$m['id'], 'email' => $m['email'], 'name' => $m['name'], 'type' => member_type($m),
            'super' => tool_is_super($m), 'active' => (bool)$m['is_active'], 'in_cloudflare' => $inCf, 'suspended' => !empty($m['is_suspended']),
            'tools' => tool_is_super($m) ? $switchable : array_values(array_merge(array_intersect($grantable, $ticks[(int)$m['id']] ?? []), in_array($m['email'], $portalOn, true) ? ['client-portal'] : [])),
            'status' => !$m['is_active'] ? 'removed_still_in_cloudflare' : ($inCf === false ? 'not_in_cloudflare' : 'ok'),
        ];
    }
    foreach ($cfEmails ?? [] as $e) {
        if (!isset($seen[$e])) {
            $people[] = ['id' => null, 'email' => $e, 'name' => '', 'type' => null, 'super' => false, 'active' => false, 'in_cloudflare' => true, 'tools' => [], 'suspended' => false, 'status' => 'new'];
        }
    }
    usort($people, static function (array $a, array $b): int {
        $rank = static fn (array $p): int => $p['super'] ? 0 : ($p['status'] === 'new' ? 1 : ($p['status'] === 'ok' ? 3 : 2));
        return $rank($a) <=> $rank($b) ?: strcasecmp($a['name'] !== '' ? $a['name'] : $a['email'], $b['name'] !== '' ? $b['name'] : $b['email']);
    });
    return [
        'people' => $people,
        'catalog' => array_map(static fn (array $t): array => $t + ['available' => !$t['bridge'] || portal_available()], tool_catalog()),
        'groups' => TOOL_GROUPS,
        'types' => MEMBER_TYPES,
        'basic' => basic_keys(),
        'cloudflare' => ['connected' => $cf !== null && $cfError === null, 'configured' => $cf !== null, 'error' => $cfError, 'other_rules' => $other],
    ];
}

/**
 * Add someone: on Cloudflare first (so a failure leaves nothing half-done), then on this site.
 * Also used to "set up" an email that is already on Cloudflare but has no name yet, and to bring back a removed person.
 * @param array $in email, name, type, tools?, copy_from?
 */
function access_add(PDO $pdo, ?CfPolicy $cf, array $actor, array $in): array
{
    $email = access_clean_email((string)($in['email'] ?? ''));
    $name = access_clean_name((string)($in['name'] ?? ''));
    $type = access_clean_type((string)($in['type'] ?? ''));
    $map = load_member_map($pdo);
    $existing = access_find_by_email($map, $email);
    if ($existing !== null && tool_is_super($existing)) {
        throw new HttpError(409, 'That is you. The super admin already has every tool.');
    }
    if ($existing !== null && $existing['is_active']) {
        throw new HttpError(409, 'That email is already on the list.');
    }
    if (isset($in['copy_from']) && $in['copy_from'] !== '' && $in['copy_from'] !== null) {
        $src = (int)$in['copy_from'];
        if (!isset($map[$src])) {
            throw new HttpError(422, 'The person to copy from was not found.');
        }
        $tools = tool_is_super($map[$src]) ? switchable_keys() : member_tool_keys($pdo, $map[$src]);
    } elseif (isset($in['tools']) && is_array($in['tools'])) {
        $tools = array_values(array_intersect(switchable_keys(), array_map('strval', $in['tools'])));
    } else {
        $tools = default_tools_for($type);
    }

    access_trace('add: checked, type=' . $type . ', tools=' . count($tools));
    $cfState = 'not_connected';
    if ($cf !== null) {
        $cf->change([$email], [], access_protected_emails($map));
        $cfState = 'ok';
        access_trace('add: cloudflare list updated');
    }

    $pdo->beginTransaction();
    try {
        if ($existing === null) {
            $m = create_member($pdo, (int)$actor['id'], [
                'email' => $email, 'name' => $name, 'rank' => 'agent',
                'upline_id' => $type === 'team' ? (int)$actor['id'] : null,
            ]);
            $id = (int)$m['id'];
        } else {
            $id = (int)$existing['id'];
            $up = $existing['upline_id'];
            if ($type === 'team' && $up === null) {
                $up = (int)$actor['id'];
            }
            if ($type !== 'team') {
                $up = null;
            }
            $pdo->prepare('UPDATE members SET name = ?, is_active = 1, deactivated_at = NULL, removal_batch = NULL, upline_id = ? WHERE id = ?')->execute([$name, $up, $id]);
        }
        $pdo->prepare('UPDATE members SET member_type = ? WHERE id = ?')->execute([$type, $id]);
        set_ticked_tools($pdo, $id, $tools);   // keeps only the stored tools; the Client Portal is handled below
        audit($pdo, (int)$actor['id'], 'access.add', $id, ['email' => $email, 'type' => $type, 'tools' => count($tools)]);
        $pdo->commit();
        access_trace('add: saved on the site');
    } catch (Throwable $e) {
        $pdo->rollBack();
        access_trace('add: database step failed: ' . $e->getMessage());
        throw $e;
    }
    $portal = 'unchanged';
    $wantPortal = in_array('client-portal', $tools, true);
    if ($wantPortal || portal_enabled($email)) {
        try {
            portal_set($email, $name, $wantPortal);
            $portal = $wantPortal ? 'on' : 'off';
        } catch (Throwable $e) {
            $portal = 'failed';
            error_log('access_add portal: ' . $e->getMessage());
        }
    }
    access_trace('add: finished, portal=' . $portal);
    return ['id' => $id, 'email' => $email, 'cloudflare' => $cfState, 'portal' => $portal];
}

/**
 * Change the name and/or the type. Team member -> Admin/Tester takes them out of the team tree (only allowed when
 * nobody active is under them); Admin/Tester -> Team member puts them directly under the super admin.
 */
function access_update(PDO $pdo, array $actor, int $id, array $in): void
{
    $map = load_member_map($pdo);
    if (!isset($map[$id])) {
        throw new HttpError(404, 'Person not found.');
    }
    $cur = $map[$id];
    if (tool_is_super($cur)) {
        throw new HttpError(403, 'The super admin cannot be changed here.');
    }
    $set = [];
    if (array_key_exists('name', $in)) {
        $set['name'] = access_clean_name((string)$in['name']);
    }
    if (array_key_exists('type', $in) && $in['type'] !== member_type($cur)) {
        $type = access_clean_type((string)$in['type']);
        if (member_type($cur) === 'team') {
            $under = array_filter(downline_ids($map, $id), static fn (int $k): bool => (bool)$map[$k]['is_active']);
            if ($under) {
                throw new HttpError(422, 'They have ' . count($under) . ' active ' . (count($under) === 1 ? 'person' : 'people') . ' under them in the team. Move those people on the Team page first.');
            }
            $set['upline_id'] = null;
            $set['is_team_admin'] = 0;
        } elseif ($type === 'team') {
            $set['upline_id'] = (int)$actor['id'];
        }
        $set['member_type'] = $type;
    }
    if (!$set) {
        return;
    }
    $cols = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($set)));
    $pdo->prepare("UPDATE members SET $cols WHERE id = ?")->execute([...array_values($set), $id]);
    audit($pdo, (int)$actor['id'], 'access.update', $id, $set);
}

function access_set_tool(PDO $pdo, array $actor, int $id, string $key, bool $on): array
{
    $map = load_member_map($pdo);
    if (!isset($map[$id]) || !$map[$id]['is_active']) {
        throw new HttpError(404, 'Person not found.');
    }
    if (tool_is_super($map[$id])) {
        throw new HttpError(403, 'The super admin always has every tool.');
    }
    if (in_array($key, bridge_keys(), true)) {
        portal_set((string)$map[$id]['email'], (string)$map[$id]['name'], $on);   // the Client Portal's own record
        audit($pdo, (int)$actor['id'], 'access.tool', $id, ['tool' => $key, 'on' => $on]);
        return member_tool_keys($pdo, load_member_map($pdo)[$id]);
    }
    if (!in_array($key, grantable_keys(), true)) {
        throw new HttpError(422, 'That tool cannot be switched here.');
    }
    $cur = ticked_tools($pdo, $id);
    $new = $on ? array_values(array_unique(array_merge($cur, [$key]))) : array_values(array_diff($cur, [$key]));
    $res = set_ticked_tools($pdo, $id, $new);
    audit($pdo, (int)$actor['id'], 'access.tool', $id, ['tool' => $key, 'on' => $on]);
    return $res;
}

function access_set_tools(PDO $pdo, array $actor, int $id, array $keys): array
{
    $map = load_member_map($pdo);
    if (!isset($map[$id]) || !$map[$id]['is_active']) {
        throw new HttpError(404, 'Person not found.');
    }
    if (tool_is_super($map[$id])) {
        throw new HttpError(403, 'The super admin always has every tool.');
    }
    $keys = array_map('strval', $keys);
    $wantPortal = in_array('client-portal', $keys, true);
    if ($wantPortal !== portal_enabled((string)$map[$id]['email'])) {
        portal_set((string)$map[$id]['email'], (string)$map[$id]['name'], $wantPortal);
    }
    $res = set_ticked_tools($pdo, $id, $keys);
    audit($pdo, (int)$actor['id'], 'access.tools', $id, ['count' => count($res) + ($wantPortal ? 1 : 0)]);
    return $res;
}

/**
 * Remove an Admin / Tester (or an email that is only on Cloudflare): blocked on the site at once, then taken off
 * Cloudflare and signed out. Team members are removed on the Team page, which also handles their team and candidates.
 */
function access_remove(PDO $pdo, ?CfPolicy $cf, array $actor, string $emailRaw): array
{
    $email = access_clean_email($emailRaw);
    $map = load_member_map($pdo);
    if ($email === $actor['email']) {
        throw new HttpError(422, 'You cannot remove yourself.');
    }
    $m = access_find_by_email($map, $email);
    if ($m !== null && tool_is_super($m)) {
        throw new HttpError(403, 'The super admin cannot be removed.');
    }
    if ($m !== null && $m['is_active'] && member_type($m) === 'team') {
        throw new HttpError(422, 'Remove Team members on the Team page, so their team and candidates are looked after. It also takes them off Cloudflare for you.');
    }
    if ($m !== null && $m['is_active']) {
        $pdo->prepare('UPDATE members SET is_active = 0, deactivated_at = ?, removal_batch = NULL WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), (int)$m['id']]);
        audit($pdo, (int)$actor['id'], 'access.remove', (int)$m['id'], ['email' => $email]);
    }
    portal_off($email);   // the Client Portal keeps their data but switches them off
    $cfState = 'not_connected';
    if ($cf !== null) {
        try {
            $cf->change([], [$email], access_protected_emails($map));
            try {
                $cf->revoke($email);
            } catch (Throwable $e) {
                error_log('access revoke: ' . $e->getMessage());   // already off the list and blocked on the site
            }
            $cfState = 'ok';
        } catch (Throwable $e) {
            throw new HttpError(424, ($m !== null ? 'They are blocked on this site already, but ' : '') . 'Cloudflare could not be updated (' . $e->getMessage() . '). Press Remove again to retry.');
        }
    }
    return ['email' => $email, 'cloudflare' => $cfState];
}

/** For someone who is on this site but missing from Cloudflare (so they cannot log in yet). */
function access_add_to_cloudflare(PDO $pdo, ?CfPolicy $cf, array $actor, string $emailRaw): array
{
    $email = access_clean_email($emailRaw);
    $map = load_member_map($pdo);
    $m = access_find_by_email($map, $email);
    if ($m === null || !$m['is_active']) {
        throw new HttpError(404, 'Person not found.');
    }
    if ($cf === null) {
        throw new HttpError(503, 'Cloudflare is not connected yet. See the setup steps.');
    }
    $cf->change([$email], [], access_protected_emails($map));
    audit($pdo, (int)$actor['id'], 'access.cf_add', (int)$m['id'], ['email' => $email]);
    return ['email' => $email, 'cloudflare' => 'ok'];
}

/** Pause or resume someone completely. Blocked at the login check, so every page and every data call stops at once. */
function access_set_suspended(PDO $pdo, array $actor, int $id, bool $on): void
{
    $map = load_member_map($pdo);
    if (!isset($map[$id]) || !$map[$id]['is_active']) {
        throw new HttpError(404, 'Person not found.');
    }
    if (tool_is_super($map[$id]) || $id === (int)$actor['id']) {
        throw new HttpError(403, 'The super admin cannot be paused.');
    }
    $pdo->prepare('UPDATE members SET is_suspended = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
    // The Client Portal has its own login, so pausing also switches it off; resuming switches it back on if it was on.
    $email = (string)$map[$id]['email'];
    if ($on && portal_enabled($email)) {
        $pdo->prepare("INSERT INTO tool_access (member_id, tool_key) VALUES (?, 'portal-was-on')")->execute([$id]);
        portal_off($email);
    } elseif (!$on) {
        $st = $pdo->prepare("SELECT 1 FROM tool_access WHERE member_id = ? AND tool_key = 'portal-was-on'");
        $st->execute([$id]);
        if ($st->fetchColumn()) {
            portal_set($email, (string)$map[$id]['name'], true);
            $pdo->prepare("DELETE FROM tool_access WHERE member_id = ? AND tool_key = 'portal-was-on'")->execute([$id]);
        }
    }
    audit($pdo, (int)$actor['id'], $on ? 'access.pause' : 'access.resume', $id, []);
}

/**
 * Step-by-step Cloudflare check for the "Run Cloudflare check" button. Never throws.
 * @return array<int,array{ok:bool,label:string,detail:string}>
 */
function access_diagnose(array $config, ?callable $http = null): array
{
    $c = $config['cf_api'] ?? null;
    $steps = [];
    $add = static function (bool $ok, string $label, string $detail) use (&$steps): void {
        $steps[] = ['ok' => $ok, 'label' => $label, 'detail' => $detail];
    };
    if (!is_array($c)) {
        $add(false, 'Settings in config.php', 'There is no cf_api block in private/config.php.');
        return $steps;
    }
    $missing = array_values(array_filter(['token', 'account_id', 'policy_id'], static fn (string $k): bool => trim((string)($c[$k] ?? '')) === ''));
    if ($missing) {
        $add(false, 'Settings in config.php', 'These are empty: ' . implode(', ', $missing) . '.');
        return $steps;
    }
    $acc = (string)$c['account_id'];
    $pol = (string)$c['policy_id'];
    $accOk = preg_match('/^[0-9a-f]{32}$/', $acc) === 1;
    $polOk = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $pol) === 1;
    $tokOdd = $c['token'] !== trim((string)$c['token']) || preg_match('/\s/', (string)$c['token']) === 1;
    $add($accOk && $polOk && !$tokOdd, 'Settings in config.php',
        ($accOk ? '' : 'The account_id is not 32 letters/numbers (it has ' . strlen($acc) . ' characters). ')
        . ($polOk ? '' : 'The policy_id does not look right (it should be like 8-4-4-4-12 letters/numbers; yours has ' . strlen($pol) . ' characters). ')
        . ($tokOdd ? 'The token has a space or line break in it. ' : '')
        . ($accOk && $polOk && !$tokOdd ? 'The three settings are present and look well-formed.' : ''));
    $cf = new CfPolicy($c, $http);
    $t = $cf->checkToken();
    $add($t['ok'], 'Is the token accepted?', $t['detail']);
    if (!$t['ok']) {
        return $steps;
    }
    $l = $cf->listPolicies();
    $add($l['ok'], 'Can the token see the account\'s policies?', $l['ok'] ? $l['detail'] : $l['detail'] . ' Usually the account_id is wrong, or the token was limited to a different account.');
    if ($l['ok']) {
        $names = array_map(static fn (array $p): string => $p['name'] . ' (' . $p['id'] . ')', $l['policies']);
        $found = array_values(array_filter($l['policies'], static fn (array $p): bool => $p['id'] === $pol));
        $add((bool)$found, 'Is your policy_id one of them?', $found
            ? 'Yes: "' . $found[0]['name'] . '".'
            : 'No. Policies in this account: ' . ($names ? implode('; ', $names) : 'none') . '. Use the id of the one called Email Access.');
    }
    try {
        $emails = $cf->emails();
        $add(true, 'Can it read the email list?', count($emails) . ' email' . (count($emails) === 1 ? '' : 's') . ' on the policy.');
    } catch (Throwable $e) {
        $add(false, 'Can it read the email list?', $e->getMessage());
    }
    return $steps;
}
