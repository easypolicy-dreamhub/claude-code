<?php
declare(strict_types=1);

/**
 * Upline-tree visibility. Rank labels play no part: a member sees their own data
 * and the data of everyone below them in the upline chain, nothing else.
 *
 * All functions take the full member list as [id => row] (a few hundred rows at most),
 * so they behave the same on every MySQL/MariaDB version.
 */

/** @return array<int, array> id => row (rows include inactive members) */
function load_member_map(PDO $pdo): array
{
    $map = [];
    foreach ($pdo->query('SELECT * FROM members') as $row) {
        $row['id'] = (int)$row['id'];
        $row['upline_id'] = $row['upline_id'] === null ? null : (int)$row['upline_id'];
        $map[$row['id']] = $row;
    }
    return $map;
}

/** Everyone below $id at any depth, excluding $id. @return int[] */
function downline_ids(array $map, int $id): array
{
    $children = [];
    foreach ($map as $m) {
        if ($m['upline_id'] !== null) {
            $children[$m['upline_id']][] = $m['id'];
        }
    }
    $out = [];
    $seen = [$id => true];
    $queue = [$id];
    while ($queue) {
        $cur = array_shift($queue);
        foreach ($children[$cur] ?? [] as $kid) {
            if (!isset($seen[$kid])) {   // guard: a corrupted loop can never hang a request
                $seen[$kid] = true;
                $out[] = $kid;
                $queue[] = $kid;
            }
        }
    }
    return $out;
}

/** Member ids whose candidates/activity $viewerId may see: self + whole downline. @return int[] */
function visible_member_ids(array $map, int $viewerId): array
{
    if (!isset($map[$viewerId])) {
        return [];
    }
    return array_merge([$viewerId], downline_ids($map, $viewerId));
}

function can_view_owner(array $map, int $viewerId, int $ownerId): bool
{
    return in_array($ownerId, visible_member_ids($map, $viewerId), true);
}

/** True if making $newUplineId the upline of $memberId would create a loop. */
function would_create_cycle(array $map, int $memberId, ?int $newUplineId): bool
{
    if ($newUplineId === null) {
        return false;
    }
    return $newUplineId === $memberId || in_array($newUplineId, downline_ids($map, $memberId), true);
}

/** The member and every upline above them, nearest first (stops on a loop). @return int[] */
function upline_chain(array $map, int $id): array
{
    $out = [];
    $seen = [];
    $cur = $id;
    while ($cur !== null && isset($map[$cur]) && !isset($seen[$cur])) {
        $seen[$cur] = true;
        $out[] = $cur;
        $cur = $map[$cur]['upline_id'];
    }
    return $out;
}
