<?php
declare(strict_types=1);

require_once __DIR__ . '/candidate_access.php';

const ACTIVITY_PERIODS = ['week' => 7, 'month' => 30, 'all' => null];
const FOLLOW_UP_DAYS = 3;
const FEED_LIMIT = 30;

/**
 * Counts and names only (no scores, no advisor notes). Scope is the same as the candidate list:
 * admin sees everyone, anyone else sees themselves and their downline.
 * @return array<string,mixed>
 */
function activity_summary(PDO $pdo, array $map, array $viewer, string $period, ?int $now = null): array
{
    if (!array_key_exists($period, ACTIVITY_PERIODS)) {
        throw new HttpError(422, 'Unknown period.');
    }
    $now ??= time();
    $days = ACTIVITY_PERIODS[$period];
    $since = $days === null ? null : gmdate('Y-m-d H:i:s', $now - $days * 86400);
    $scope = owner_scope($map, $viewer);                         // null = everyone
    $inScope = static fn (int $ownerId): bool => $scope === null || in_array($ownerId, $scope, true);

    $cands = [];
    foreach ($pdo->query('SELECT c.id, c.owner_member_id, c.name, c.pipeline_status, c.source, c.purpose, c.started_at, c.completed_at, a.scores_json AS a_scores FROM candidates c
        LEFT JOIN assessments a ON a.id = (SELECT MAX(id) FROM assessments WHERE candidate_id = c.id) ORDER BY c.id') as $c) {
        if ($inScope((int)$c['owner_member_id'])) {
            $cands[(int)$c['id']] = $c;
        }
    }
    $in = static fn (?string $t): bool => $t !== null && ($since === null || $t >= $since);

    $perOwner = [];
    $statusNow = array_fill_keys(PIPELINE, 0);
    $totals = ['added' => 0, 'completed' => 0, 'joined' => 0];
    $followUp = [];
    $cutoff = gmdate('Y-m-d H:i:s', $now - FOLLOW_UP_DAYS * 86400);
    foreach ($cands as $c) {
        $o = (int)$c['owner_member_id'];
        $perOwner[$o] ??= ['added' => 0, 'completed' => 0, 'joined' => 0, 'last' => null];
        $row = &$perOwner[$o];
        if ($in($c['started_at'])) {
            $row['added']++;
            $totals['added']++;
        }
        if ($in($c['completed_at'])) {
            $row['completed']++;
            $totals['completed']++;
        }
        foreach ([$c['started_at'], $c['completed_at']] as $t) {
            if ($t !== null && ($row['last'] === null || $t > $row['last'])) {
                $row['last'] = $t;
            }
        }
        if ($c['completed_at'] !== null) {
            $statusNow[$c['pipeline_status']]++;
            if ($c['pipeline_status'] === 'joined') {
                $row['joined']++;
                $totals['joined']++;
            }
            if ($c['pipeline_status'] === 'new' && $c['completed_at'] <= $cutoff) {
                $followUp[] = ['id' => (int)$c['id'], 'name' => $c['name'], 'owner' => $map[$o]['name'] ?? '', 'since' => iso($c['completed_at']),
                    'days' => (int)floor(($now - strtotime($c['completed_at'] . ' UTC')) / 86400)];
            }
        }
        unset($row);
    }
    usort($followUp, static fn (array $a, array $b): int => $b['days'] <=> $a['days'] ?: $a['id'] <=> $b['id']);

    // DISC step: who is ready to be invited, and who was invited but has not done it. Same tags as the candidate list.
    $open = array_filter($cands, static fn (array $c): bool => $c['completed_at'] !== null && !in_array($c['pipeline_status'], DISC_CLOSED_STATUSES, true) && $c['a_scores'] !== null);
    $latestDisc = disc_latest($pdo, array_keys($open));
    $ready = [];
    $waiting = [];
    foreach ($open as $cid => $c) {
        $l = $latestDisc[$cid];
        $st = disc_state(assess_candidate(json_decode((string)$c['a_scores'], true), 'en')['verdict']['key'], true, (string)$c['pipeline_status'], $l['invite'], $l['result'], $now);
        $owner = $map[(int)$c['owner_member_id']]['name'] ?? '';
        if ($st['tag'] === 'ready') {
            $ready[] = ['id' => $cid, 'name' => $c['name'], 'owner' => $owner, 'since' => iso($c['completed_at']), 'days' => (int)floor(($now - strtotime($c['completed_at'] . ' UTC')) / 86400)];
        } elseif ($st['tag'] === 'overdue' || $st['tag'] === 'expired') {
            $from = $l['invite']['last_sent_at'] ?? $l['invite']['created_at'];
            $waiting[] = ['id' => $cid, 'name' => $c['name'], 'owner' => $owner, 'tag' => $st['tag'], 'since' => iso($from), 'days' => (int)floor(($now - strtotime($from . ' UTC')) / 86400)];
        }
    }
    $byDays = static fn (array $a, array $b): int => $b['days'] <=> $a['days'] ?: $a['id'] <=> $b['id'];
    usort($ready, $byDays);
    usort($waiting, $byDays);

    // Staff actions on candidates (status changes, reassigns), only on candidates this viewer may see.
    $events = [];
    $stmt = $pdo->query("SELECT actor_id, action, subject_id, detail, created_at FROM audit_log WHERE action IN ('candidate.status','candidate.reassign') ORDER BY id DESC LIMIT 400");
    foreach ($stmt as $a) {
        $cid = (int)$a['subject_id'];
        if (!isset($cands[$cid])) {
            continue;
        }
        $actor = $a['actor_id'] === null ? null : (int)$a['actor_id'];
        if ($actor !== null && isset($map[$actor]) && $inScope($actor)) {
            $perOwner[$actor] ??= ['added' => 0, 'completed' => 0, 'joined' => 0, 'last' => null];
            if ($perOwner[$actor]['last'] === null || $a['created_at'] > $perOwner[$actor]['last']) {
                $perOwner[$actor]['last'] = $a['created_at'];
            }
        }
        $d = json_decode((string)$a['detail'], true) ?: [];
        $isStatus = $a['action'] === 'candidate.status';
        $events[] = ['at' => $a['created_at'], 'who' => $map[$actor]['name'] ?? 'Someone', 'cid' => $cid, 'cand' => $cands[$cid]['name'], 'kind' => $isStatus ? 'status' : 'reassign',
            'to' => $isStatus ? ($d['to'] ?? null) : (isset($d['to'], $map[(int)$d['to']]) ? $map[(int)$d['to']]['name'] : null)];
    }
    foreach ($cands as $c) {
        $who = $map[(int)$c['owner_member_id']]['name'] ?? 'Someone';
        $events[] = ['at' => $c['started_at'], 'who' => $who, 'cid' => (int)$c['id'], 'cand' => $c['name'], 'kind' => 'added', 'to' => $c['source']];
        if ($c['completed_at'] !== null) {
            $events[] = ['at' => $c['completed_at'], 'who' => $who, 'cid' => (int)$c['id'], 'cand' => $c['name'], 'kind' => 'completed', 'to' => null];
        }
    }
    usort($events, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']) ?: $b['cid'] <=> $a['cid']);
    $feed = array_map(static fn (array $e): array => ['at' => iso($e['at'])] + array_diff_key($e, ['at' => 1]), array_slice($events, 0, FEED_LIMIT));

    // One row per person in the viewer's tree, laid out as the tree (depth for indentation).
    $rows = [];
    $roots = $scope === null ? array_keys(array_filter($map, static fn (array $m): bool => $m['upline_id'] === null)) : [(int)$viewer['id']];
    $walk = static function (int $id, int $depth) use (&$walk, $map, $perOwner, &$rows): void {
        $m = $map[$id];
        $p = $perOwner[$id] ?? ['added' => 0, 'completed' => 0, 'joined' => 0, 'last' => null];
        if ($m['is_active'] || $p['added'] || $p['completed'] || $p['joined']) {
            $rows[] = ['id' => $id, 'name' => $m['name'], 'rank' => $m['rank_label'], 'active' => (bool)$m['is_active'], 'depth' => $depth,
                'added' => $p['added'], 'completed' => $p['completed'], 'joined' => $p['joined'], 'last_active' => iso($p['last'])];
        }
        $kids = array_filter($map, static fn (array $k): bool => $k['upline_id'] === $id);
        usort($kids, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        foreach ($kids as $k) {
            $walk($k['id'], $depth + 1);
        }
    };
    foreach ($roots as $r) {
        $walk($r, 0);
    }

    return ['period' => $period, 'totals' => $totals, 'status_now' => $statusNow, 'people' => $rows, 'follow_up' => array_slice($followUp, 0, 20),
        'follow_up_days' => FOLLOW_UP_DAYS, 'ready_to_invite' => array_slice($ready, 0, 20), 'disc_waiting' => array_slice($waiting, 0, 20), 'feed' => $feed, 'viewer' => ['id' => (int)$viewer['id'], 'name' => $viewer['name'], 'is_admin' => (bool)$viewer['is_admin']]];
}
