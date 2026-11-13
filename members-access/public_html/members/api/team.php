<?php
declare(strict_types=1);

/**
 * Team page API. Everyone may read their own branch; changes are checked in private/lib/team.php
 * (super admin: everyone; team admin: only people below them).
 *   GET  [?inactive=1]            the tree the viewer may see
 *   GET  ?export_group=ID         super admin: CSV of that person's group's candidates (copy before removal)
 *   POST {action, ...}            add | edit | preview_rank | apply_rank | move | preview_remove | remove |
 *                                 preview_remove_group | remove_group | preview_reactivate | reactivate | team_admin |
 *                                 portal_toggle (Client Portal on/off, super admin only)
 */
require_once __DIR__ . '/../../../private/bootstrap.php';
require_once __DIR__ . '/../../../private/lib/team.php';
require_once __DIR__ . '/../../../private/lib/access.php';   // Cloudflare sync + default tools

// Client Portal link (optional): if the bridge file is missing or broken, this page works exactly as before.
$e3pbFile = __DIR__ . '/../../../e3-client-portal/team-bridge.php';
if (is_file($e3pbFile)) {
    try { require_once $e3pbFile; } catch (Throwable $e) { error_log('team.php bridge: ' . $e->getMessage()); }
}
$e3pb = static function (string $fn, ...$args) {
    if (!function_exists($fn)) { return null; }
    try { return $fn(...$args); } catch (Throwable $e) { error_log('team.php ' . $fn . ': ' . $e->getMessage()); return null; }
};
$withPortal = static function (array $tree) use ($e3pb): array {
    $t = $e3pb('e3pb_overlay', $tree);
    return is_array($t) ? $t : $tree;
};

[$config, $pdo, $me] = boot_member_request();

try {
    $map = load_member_map($pdo);
    $me = $map[$me['id']];
    $inactive = ($_GET['inactive'] ?? '') === '1';

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['export_group'])) {
            $gid = (int)$_GET['export_group'];
            $plan = plan_remove_group($pdo, $map, $me, $gid);
            $csv = export_candidates_csv($pdo, $map, $me, array_column($plan['people'], 'id'));
            audit($pdo, $me['id'], 'group.export', $gid, ['members' => count($plan['people'])]);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="career-code-group-' . $gid . '-' . gmdate('Y-m-d') . '.csv"');
            header('Cache-Control: no-store');
            echo $csv;
            exit;
        }
        json_response(200, $withPortal(team_tree($pdo, $map, $me, $inactive)));
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new HttpError(405, 'Method not allowed.');
    }

    $in = json_body();
    $id = (int)($in['id'] ?? 0);
    $opt = static fn (string $k): ?int => isset($in[$k]) && $in[$k] !== '' && $in[$k] !== null ? (int)$in[$k] : null;
    $result = null;
    switch ((string)($in['action'] ?? '')) {
        case 'add':
            if (!is_super_admin($me)) { throw new HttpError(403, 'Only the super admin can add members.'); }
            $result = create_member($pdo, $me['id'], [
                'email' => $in['email'] ?? '', 'name' => $in['name'] ?? '', 'rank' => $in['rank'] ?? 'agent',
                'upline_id' => $opt('upline_id'), 'referral_code' => $in['referral_code'] ?? '', 'whatsapp' => $in['whatsapp'] ?? '',
            ]);
            grant_default_tools($pdo, (int)$result['id'], 'team');
            $result['cloudflare'] = cf_sync_try($config, [(string)$result['email']], []);   // also puts them on the Cloudflare list
            break;
        case 'edit':
            if (!is_super_admin($me)) { throw new HttpError(403, 'Only the super admin can edit details.'); }
            $oldEmail = (string)($map[$id]['email'] ?? '');
            $result = update_member($pdo, $me['id'], $id, array_intersect_key($in, array_flip(['name', 'email', 'referral_code', 'whatsapp'])));
            if ($oldEmail !== '' && isset($in['email'])) { $e3pb('e3pb_rename', $oldEmail, (string)$in['email']); }
            if ($oldEmail !== '' && isset($in['email']) && strtolower(trim((string)$in['email'])) !== $oldEmail) {
                $result = ['cloudflare' => cf_sync_try($config, [(string)$in['email']], [$oldEmail], access_protected_emails($map))];   // new email on, old email off
            }
            break;
        case 'preview_rank':
            if (!can_manage($map, $me, $id)) { throw new HttpError(403, 'You cannot change this member\'s rank.'); }
            $result = describe_plan($map, plan_rank_change($map, $id, (string)($in['rank'] ?? '')));
            break;
        case 'apply_rank':
            $accept = isset($in['accept']) && is_array($in['accept']) ? array_map('intval', $in['accept']) : null;
            $result = apply_rank_change($pdo, $me, $id, (string)($in['rank'] ?? ''), $accept);
            break;
        case 'move':
            move_member($pdo, $me, $id, $opt('to'), ($in['with_team'] ?? true) !== false);
            break;
        case 'preview_remove':
            $result = plan_remove_member($pdo, $map, $me, $id);
            break;
        case 'remove':
            $gone = (string)($map[$id]['email'] ?? '');
            remove_member($pdo, $me, $id, $opt('team_to'), ($in['move_candidates'] ?? true) !== false);
            if ($gone !== '') { $e3pb('e3pb_revoke', $gone); }
            $result = ['cloudflare' => $gone === '' ? 'ok' : cf_sync_try($config, [], [$gone], access_protected_emails($map))];   // off Cloudflare and signed out
            break;
        case 'preview_remove_group':
            $result = plan_remove_group($pdo, $map, $me, $id);
            break;
        case 'remove_group':
            $result = remove_group($pdo, $me, $id, (string)($in['confirm'] ?? ''), $config);
            foreach ((array)($result['emails'] ?? []) as $gone) { $e3pb('e3pb_revoke', (string)$gone); }
            $result['cloudflare'] = cf_sync_try($config, [], array_map('strval', (array)($result['emails'] ?? [])), access_protected_emails($map));
            break;
        case 'preview_reactivate':
            $result = plan_reactivate($map, $me, $id);
            break;
        case 'reactivate':
            $back = plan_reactivate($map, $me, $id);
            reactivate_member($pdo, $me, $id, $opt('to'), ($in['with_team'] ?? true) !== false);
            $emails = [(string)$map[$id]['email']];
            if (($in['with_team'] ?? true) !== false) {
                foreach ($back['team'] as $p) { $emails[] = (string)$map[(int)$p['id']]['email']; }
            }
            $result = ['cloudflare' => cf_sync_try($config, $emails, [])];   // back on the Cloudflare list
            break;
        case 'portal_toggle':
            if (!is_super_admin($me)) { throw new HttpError(403, 'Only the super admin can switch the Client Portal on or off.'); }
            if (!isset($map[$id]) || empty($map[$id]['email'])) { throw new HttpError(404, 'Member not found.'); }
            if (!function_exists('e3pb_toggle')) { throw new HttpError(503, 'The Client Portal link is not installed on the server.'); }
            e3pb_toggle((string)$map[$id]['email'], (string)($map[$id]['name'] ?? ''), ($in['on'] ?? false) === true);
            break;
        case 'team_admin':
            set_team_admin($pdo, $me, $id, ($in['on'] ?? false) === true);
            break;
        default:
            throw new HttpError(400, 'Unknown action.');
    }
    $map = load_member_map($pdo);
    json_response(200, ['result' => $result, 'tree' => $withPortal(team_tree($pdo, $map, $map[$me['id']], $inactive))]);
} catch (HttpError $e) {
    json_response($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('team.php: ' . $e->getMessage());
    json_response(500, ['error' => 'Server error.']);
}
