<?php
declare(strict_types=1);

/**
 * Team structure rules (ranks, moves, removals). Visibility everywhere else follows the upline tree,
 * so changing the tree here is what changes who sees whose candidates.
 *
 *  - Promotion: rank now HIGHER than the upline's -> breaks away with their whole team to the nearest
 *    leader above with a high enough rank. Group Manager equal to a Group Manager upline -> your choice
 *    (stay, or remove the whole group). Unit Manager equal to a Unit Manager upline -> stays (parallel).
 *  - Demotion: the person stays where they are. Anyone directly under them who now OUTRANKS them moves up;
 *    on dropping to Agent their agents move up too. Below Group Manager -> no longer team admin.
 *  - Nobody returns automatically to a former upline.
 *  - Removals are switched off, not erased; each removal gets a batch id so a group comes back together.
 * Every rank change is a plan: previewed, each move can be unticked, then applied in one transaction.
 */
require_once __DIR__ . '/members.php';
require_once __DIR__ . '/candidate_access.php';

const RANK_LEVEL = ['agent' => 1, 'unit_manager' => 2, 'group_manager' => 3];
const RANK_LABEL = ['agent' => 'Agent', 'unit_manager' => 'Unit Manager', 'group_manager' => 'Group Manager'];

function rank_level(string $rank): int
{
    return RANK_LEVEL[$rank] ?? 0;
}

function is_super_admin(array $m): bool
{
    return (bool)$m['is_admin'] && (bool)$m['is_active'];
}

function is_team_admin(array $m): bool
{
    return !$m['is_admin'] && !empty($m['is_team_admin']) && (bool)$m['is_active'] && $m['rank_label'] === 'group_manager';
}

/** Super admin: anyone. Team admin: only people strictly below them. Everyone else: nobody. */
function can_manage(array $map, array $actor, int $targetId): bool
{
    if (!isset($map[$targetId]) || !$map[$targetId]['is_active']) {
        return false;      // a removed member can only be reactivated, never ranked, moved or removed again
    }
    if (is_super_admin($actor)) {
        return true;
    }
    return is_team_admin($actor) && in_array($targetId, downline_ids($map, (int)$actor['id']), true);
}

/** Where a team admin may put people: themselves or anyone in their group. Super admin: any active member. */
function allowed_destinations(array $map, array $actor): array
{
    $ids = is_super_admin($actor) ? array_keys($map) : visible_member_ids($map, (int)$actor['id']);
    return array_values(array_filter($ids, static fn (int $id): bool => (bool)$map[$id]['is_active']));
}

/** Nearest ACTIVE member, starting at $fromId and walking up, whose rank is at least $level. null = top of the tree. */
function nearest_leader(array $map, ?int $fromId, int $level): ?int
{
    foreach ($fromId === null ? [] : upline_chain($map, $fromId) as $id) {
        if ($map[$id]['is_active'] && rank_level($map[$id]['rank_label']) >= $level) {
            return $id;
        }
    }
    return null;
}

/** Direct reports. Removed people are left out unless asked for: they are history, not part of anyone's live team. */
function children_of(array $map, int $id, bool $includeRemoved = false): array
{
    return array_values(array_map(static fn (array $m): int => $m['id'], array_filter($map, static fn (array $m): bool => $m['upline_id'] === $id && ($includeRemoved || $m['is_active']))));
}

function active_team_size(array $map, int $id): int
{
    return count(array_filter(downline_ids($map, $id), static fn (int $k): bool => (bool)$map[$k]['is_active']));
}

function person(array $map, ?int $id): ?array
{
    return $id === null ? null : ['id' => $id, 'name' => $map[$id]['name'], 'rank' => $map[$id]['rank_label']];
}

/** @return array{member:array, from_rank:string, to_rank:string, moves:array, team_admin_off:bool, gm_choice:bool} */
function plan_rank_change(array $map, int $id, string $newRank): array
{
    if (!isset(RANK_LEVEL[$newRank])) {
        throw new HttpError(422, 'Unknown rank.');
    }
    $m = $map[$id];
    $old = rank_level($m['rank_label']);
    $new = rank_level($newRank);
    $up = $m['upline_id'];
    $moves = [];
    $gmChoice = false;

    if ($new > $old && $up !== null) {
        $upLevel = rank_level($map[$up]['rank_label']);
        if ($new > $upLevel) {
            $dest = nearest_leader($map, $map[$up]['upline_id'], $new);
            $moves[] = ['member' => $id, 'from' => $up, 'to' => $dest, 'reason' => 'now outranks their upline'];
            // Lands as a Group Manager directly under a Group Manager: the same "stay or opt out" choice applies.
            $gmChoice = $newRank === 'group_manager' && $dest !== null && $map[$dest]['rank_label'] === 'group_manager';
        } elseif ($new === $upLevel && $newRank === 'group_manager') {
            $gmChoice = true;   // GE: a GM built by a GM breaks away; you decide whether they stay in the system
        }
    } elseif ($new < $old) {
        foreach (children_of($map, $id) as $kid) {
            $kl = rank_level($map[$kid]['rank_label']);
            if ($kl > $new) {
                $moves[] = ['member' => $kid, 'from' => $id, 'to' => nearest_leader($map, $up, $kl), 'reason' => 'now outranks them'];
            } elseif ($new === 1 && $kl === 1) {
                $moves[] = ['member' => $kid, 'from' => $id, 'to' => nearest_leader($map, $up, 1), 'reason' => 'same level as them now'];
            }
        }
    }
    return [
        'member' => person($map, $id), 'from_rank' => $m['rank_label'], 'to_rank' => $newRank,
        'moves' => array_map(static fn (array $mv) => $mv + ['team_size' => active_team_size($map, $mv['member'])], $moves),
        'team_admin_off' => !empty($m['is_team_admin']) && $newRank !== 'group_manager',
        'gm_choice' => $gmChoice,
    ];
}

function describe_plan(array $map, array $plan): array
{
    $plan['moves'] = array_map(static fn (array $mv) => $mv + ['member_p' => person($map, $mv['member']), 'from_p' => person($map, $mv['from']), 'to_p' => person($map, $mv['to'])], $plan['moves']);
    return $plan;
}

/** @param ?int[] $accept member ids whose suggested move to apply (null = all) */
function apply_rank_change(PDO $pdo, array $actor, int $id, string $newRank, ?array $accept = null): array
{
    $map = load_member_map($pdo);
    if (!can_manage($map, $actor, $id)) {
        throw new HttpError(403, 'You cannot change this member\'s rank.');
    }
    if ($map[$id]['rank_label'] === $newRank) {
        throw new HttpError(422, 'That is already their rank.');
    }
    $plan = plan_rank_change($map, $id, $newRank);
    $moves = array_values(array_filter($plan['moves'], static fn (array $mv) => $accept === null || in_array($mv['member'], $accept, true)));
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE members SET rank_label = ?' . ($plan['team_admin_off'] ? ', is_team_admin = 0' : '') . ' WHERE id = ?')->execute([$newRank, $id]);
        foreach ($moves as $mv) {
            $pdo->prepare('UPDATE members SET upline_id = ? WHERE id = ?')->execute([$mv['to'], $mv['member']]);
        }
        audit($pdo, (int)$actor['id'], 'member.rank', $id, ['from' => $plan['from_rank'], 'to' => $newRank,
            'moves' => array_map(static fn ($mv) => [$mv['member'], $mv['from'], $mv['to']], $moves), 'skipped' => count($plan['moves']) - count($moves)]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return describe_plan($map, ['moves' => $moves] + $plan);
}

/** Manual placement. $withTeam=false leaves the member's own agents with the old upline. */
function move_member(PDO $pdo, array $actor, int $id, ?int $to, bool $withTeam = true): void
{
    $map = load_member_map($pdo);
    if (!can_manage($map, $actor, $id)) {
        throw new HttpError(403, 'You cannot move this member.');
    }
    if ($to === null ? !is_super_admin($actor) : !in_array($to, allowed_destinations($map, $actor), true)) {
        throw new HttpError(403, 'You cannot place someone there.');
    }
    if ($to !== null && $map[$id]['is_admin']) {
        throw new HttpError(422, 'The super admin always stays at the top: anyone above you could see your candidates.');
    }
    if ($to !== null && ($to === $id || in_array($to, downline_ids($map, $id), true))) {
        throw new HttpError(422, 'Someone cannot be placed under their own team.');
    }
    $old = $map[$id]['upline_id'];
    $pdo->beginTransaction();
    try {
        if (!$withTeam) {
            $pdo->prepare('UPDATE members SET upline_id = ? WHERE upline_id = ?')->execute([$old, $id]);
        }
        $pdo->prepare('UPDATE members SET upline_id = ? WHERE id = ?')->execute([$to, $id]);
        audit($pdo, (int)$actor['id'], 'member.move', $id, ['from' => $old, 'to' => $to, 'with_team' => $withTeam]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function count_candidates(PDO $pdo, array $ownerIds): int
{
    if (!$ownerIds) {
        return 0;
    }
    return (int)$pdo->query('SELECT COUNT(*) FROM candidates WHERE owner_member_id IN (' . implode(',', array_map('intval', $ownerIds)) . ')')->fetchColumn();
}

/** Someone who left: their agents (and by default their candidates) go to their upline. */
function plan_remove_member(PDO $pdo, array $map, array $actor, int $id): array
{
    if (isset($map[$id]) && !$map[$id]['is_active']) {
        throw new HttpError(422, 'This member is already removed.');
    }
    if (!can_manage($map, $actor, $id) || $id === (int)$actor['id']) {
        throw new HttpError(403, 'You cannot remove this member.');
    }
    if ($map[$id]['is_admin']) {
        throw new HttpError(422, 'The super admin cannot be removed here.');
    }
    // Default: the nearest leader above who is at least as senior as the most senior person in their team,
    // so a demoted upline never ends up proposed as the new upline of someone who outranks them.
    $kids = children_of($map, $id);
    $need = $kids ? max(array_map(static fn (int $k): int => rank_level($map[$k]['rank_label']), $kids)) : 0;
    $default = nearest_leader($map, $map[$id]['upline_id'], $need);
    return [
        'member' => person($map, $id), 'agents' => array_map(static fn ($k) => person($map, $k), children_of($map, $id)),
        'candidates' => count_candidates($pdo, [$id]), 'default_to' => person($map, $default),
        'destinations' => array_map(static fn ($d) => person($map, $d), array_values(array_filter(allowed_destinations($map, $actor), static fn ($d) => $d !== $id && !in_array($d, downline_ids($map, $id), true)))),
    ];
}

function remove_member(PDO $pdo, array $actor, int $id, ?int $teamTo, bool $moveCandidates = true): void
{
    $map = load_member_map($pdo);
    $plan = plan_remove_member($pdo, $map, $actor, $id);
    $allowed = array_column($plan['destinations'], 'id');
    if ($teamTo === null ? ($plan['agents'] && !is_super_admin($actor)) : !in_array($teamTo, $allowed, true)) {
        throw new HttpError(403, 'You cannot move their team there.');
    }
    $pdo->beginTransaction();
    try {
        // Only the live team moves; people who were removed earlier stay under them as history.
        $pdo->prepare('UPDATE members SET upline_id = ? WHERE upline_id = ? AND is_active = 1')->execute([$teamTo, $id]);
        if ($moveCandidates && $teamTo !== null) {
            $pdo->prepare('UPDATE candidates SET owner_member_id = ? WHERE owner_member_id = ?')->execute([$teamTo, $id]);
        }
        $pdo->prepare('UPDATE members SET is_active = 0, is_team_admin = 0, deactivated_at = ?, removal_batch = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), bin2hex(random_bytes(8)), $id]);
        audit($pdo, (int)$actor['id'], 'member.remove', $id, ['team_to' => $teamTo, 'candidates_moved' => $moveCandidates && $teamTo !== null ? $plan['candidates'] : 0]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** A Group Manager opting out: everyone in their group, and how many candidates would be deleted. Super admin only. */
function plan_remove_group(PDO $pdo, array $map, array $actor, int $id): array
{
    if (!is_super_admin($actor) || !isset($map[$id]) || $id === (int)$actor['id'] || $map[$id]['is_admin']) {
        throw new HttpError(403, 'Only the super admin can remove a group.');
    }
    $ids = array_merge([$id], downline_ids($map, $id));
    return [
        'member' => person($map, $id),
        'people' => array_map(static fn ($k) => person($map, $k) + ['email' => $map[$k]['email'], 'active' => (bool)$map[$k]['is_active']], $ids),
        'candidates' => count_candidates($pdo, $ids),
    ];
}

/** Deactivates the whole group (so it can be reactivated later) and deletes their candidates for their privacy. */
function remove_group(PDO $pdo, array $actor, int $id, string $confirm): array
{
    if ($confirm !== 'REMOVE') {
        throw new HttpError(422, 'Type REMOVE to confirm.');
    }
    $map = load_member_map($pdo);
    $plan = plan_remove_group($pdo, $map, $actor, $id);
    $ids = array_column($plan['people'], 'id');
    $in = implode(',', array_map('intval', $ids));
    $now = gmdate('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $cids = $pdo->query("SELECT id FROM candidates WHERE owner_member_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if ($cids) {
            $cin = implode(',', array_map('intval', $cids));
            $pdo->exec("UPDATE candidates SET duplicate_of = NULL WHERE duplicate_of IN ($cin)");
            $pdo->exec("DELETE FROM assessments WHERE candidate_id IN ($cin)");
            $pdo->exec("DELETE FROM candidates WHERE id IN ($cin)");
        }
        // One batch id for this removal, set only on people still active: "reactivate with team" then brings
        // back exactly this group, never someone who had left earlier (even within the same second).
        $pdo->prepare("UPDATE members SET is_active = 0, is_team_admin = 0, deactivated_at = ?, removal_batch = ? WHERE id IN ($in) AND is_active = 1")->execute([$now, bin2hex(random_bytes(8))]);
        audit($pdo, (int)$actor['id'], 'group.remove', $id, ['members' => count($ids), 'candidates_deleted' => count($cids)]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['removed' => count($ids), 'candidates_deleted' => count($cids), 'emails' => array_column($plan['people'], 'email')];
}

/** Bringing someone back. "With team" = the people removed in the same action (same removal batch). */
function plan_reactivate(array $map, array $actor, int $id): array
{
    if (!is_super_admin($actor) || !isset($map[$id]) || $map[$id]['is_active']) {
        throw new HttpError(403, 'Only the super admin can reactivate an inactive member.');
    }
    $batch = $map[$id]['removal_batch'] ?? null;
    $together = $batch === null ? [] : array_values(array_filter(downline_ids($map, $id), static fn ($k) => !$map[$k]['is_active'] && ($map[$k]['removal_batch'] ?? null) === $batch));
    // Default: the nearest active leader from their old upline upwards who is at least as senior as they are;
    // if nobody qualifies, top level (never a placement under someone they outrank).
    $need = rank_level($map[$id]['rank_label']);
    $dest = nearest_leader($map, $map[$id]['upline_id'], $need);
    if ($dest === null && rank_level($actor['rank_label']) >= $need) {
        $dest = (int)$actor['id'];
    }
    return [
        'member' => person($map, $id), 'team' => array_map(static fn ($k) => person($map, $k), $together),
        'default_to' => person($map, $dest),
    ];
}

function reactivate_member(PDO $pdo, array $actor, int $id, ?int $to, bool $withTeam): void
{
    $map = load_member_map($pdo);
    $plan = plan_reactivate($map, $actor, $id);
    if ($to !== null && (!isset($map[$to]) || !$map[$to]['is_active'] || $to === $id || in_array($to, downline_ids($map, $id), true))) {
        throw new HttpError(422, 'Choose an active member outside their own team.');
    }
    $ids = array_merge([$id], $withTeam ? array_column($plan['team'], 'id') : []);
    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE members SET is_active = 1, deactivated_at = NULL, removal_batch = NULL WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
        $pdo->prepare('UPDATE members SET upline_id = ? WHERE id = ?')->execute([$to, $id]);
        audit($pdo, (int)$actor['id'], 'member.reactivate', $id, ['to' => $to, 'with_team' => count($ids) - 1]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function set_team_admin(PDO $pdo, array $actor, int $id, bool $on): void
{
    $map = load_member_map($pdo);
    if (!is_super_admin($actor) || !isset($map[$id]) || $map[$id]['is_admin']) {
        throw new HttpError(403, 'Only the super admin can change team admins.');
    }
    if ($on && ($map[$id]['rank_label'] !== 'group_manager' || !$map[$id]['is_active'])) {
        throw new HttpError(422, 'Only an active Group Manager can be a team admin.');
    }
    $pdo->prepare('UPDATE members SET is_team_admin = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
    audit($pdo, (int)$actor['id'], 'member.team_admin', $id, ['on' => $on]);
}

/** The tree as the viewer may see it, with what they may do to each person. */
function team_tree(PDO $pdo, array $map, array $viewer, bool $includeInactive): array
{
    $ids = is_super_admin($viewer) ? array_keys($map) : visible_member_ids($map, (int)$viewer['id']);
    $counts = [];
    foreach ($pdo->query('SELECT owner_member_id, COUNT(*) AS n FROM candidates GROUP BY owner_member_id') as $r) {
        $counts[(int)$r['owner_member_id']] = (int)$r['n'];
    }
    $nodes = [];
    foreach ($ids as $id) {
        $m = $map[$id];
        if (!$m['is_active'] && !$includeInactive) {
            continue;
        }
        $up = $m['upline_id'];
        $nodes[] = public_member($m) + [
            'candidates' => $counts[$id] ?? 0,
            // parent as the viewer sees it: someone above the viewer's own branch is shown as top-level
            'parent' => $up !== null && in_array($up, $ids, true) ? $up : null,
            'rank_warning' => $up !== null && rank_level($m['rank_label']) > rank_level($map[$up]['rank_label']),
            'can_manage' => can_manage($map, $viewer, $id),
            'deactivated_at' => iso($m['deactivated_at']),
        ];
    }
    return [
        'members' => $nodes,
        'viewer' => ['id' => (int)$viewer['id'], 'name' => $viewer['name'], 'super_admin' => is_super_admin($viewer), 'team_admin' => is_team_admin($viewer)],
        'ranks' => RANK_LABEL,
    ];
}
