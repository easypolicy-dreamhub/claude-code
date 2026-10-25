<?php
declare(strict_types=1);

require_once __DIR__ . '/candidates.php';
require_once __DIR__ . '/advisor.php';

const PIPELINE = ['new', 'contacted', 'interviewed', 'joined', 'not_suitable'];
const LIST_PAGE_SIZE = 25;

/**
 * Which owners' candidates the viewer may see. Admins: everyone. Everyone else: self + whole downline.
 * @return int[]|null null = no restriction (admin)
 */
function owner_scope(array $map, array $viewer): ?array
{
    return $viewer['is_admin'] ? null : visible_member_ids($map, (int)$viewer['id']);
}

function can_see_candidate(array $map, array $viewer, int $ownerId): bool
{
    $scope = owner_scope($map, $viewer);
    return $scope === null || in_array($ownerId, $scope, true);
}

function iso(?string $utc): ?string
{
    return $utc === null ? null : str_replace(' ', 'T', $utc) . 'Z';
}

/** @param ?array $row joined assessment columns a_scores / a_code (null when unfinished) */
function candidate_summary(array $c, array $map): array
{
    $scores = isset($c['a_scores']) ? json_decode((string)$c['a_scores'], true) : null;
    $verdict = $scores ? assess_candidate($scores, 'en')['verdict'] : null;
    $owner = $map[(int)$c['owner_member_id']] ?? null;
    $rb = isset($c['referred_by_member_id']) && $c['referred_by_member_id'] !== null ? (int)$c['referred_by_member_id'] : null;
    $via = $rb !== null && $owner && $rb !== (int)$owner['id'] && isset($map[$rb]) ? ['id' => $rb, 'name' => $map[$rb]['name']] : null;   // link used by someone else
    return [
        'id' => (int)$c['id'], 'name' => $c['name'], 'mobile' => $c['mobile'], 'email' => $c['email'],
        'purpose' => $c['purpose'], 'source' => $c['source'], 'lang' => $c['lang'],
        'owner' => $owner ? ['id' => (int)$owner['id'], 'name' => $owner['name'], 'active' => (bool)$owner['is_active']] : null,
        'via_link' => $via,
        'status' => $c['pipeline_status'], 'duplicate_of' => $c['duplicate_of'] === null ? null : (int)$c['duplicate_of'],
        'completed' => $c['completed_at'] !== null, 'code' => $c['a_code'] ?? null, 'entry' => ($c['a_mode'] ?? null) === 'manual' ? 'paper' : (($c['a_mode'] ?? null) === null ? null : 'online'),
        'verdict' => $verdict ? ['key' => $verdict['key'], 'title' => $verdict['title']] : null,
        'started_at' => iso($c['started_at']), 'completed_at' => iso($c['completed_at']),
        'result_emailed' => $c['result_emailed_at'] !== null, 'advisor_notified' => $c['notified_at'] !== null,
    ];
}

/**
 * @param array{status?:string,purpose?:string,q?:string,owner_id?:int,mine?:bool,include_unfinished?:bool,page?:int} $f
 * @return array{candidates:array, total:int, page:int, page_size:int}
 */
function list_candidates(PDO $pdo, array $map, array $viewer, array $f): array
{
    $where = [];
    $args = [];
    $scope = owner_scope($map, $viewer);
    if (!empty($f['mine'])) {
        $scope = [(int)$viewer['id']];
    }
    if (!empty($f['owner_id'])) {
        $oid = (int)$f['owner_id'];
        if (!can_see_candidate($map, $viewer, $oid)) {
            throw new HttpError(404, 'Not found.');
        }
        $scope = [$oid];
    }
    if ($scope !== null) {
        if (!$scope) {
            return ['candidates' => [], 'total' => 0, 'page' => 1, 'page_size' => LIST_PAGE_SIZE];
        }
        $where[] = 'c.owner_member_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
        array_push($args, ...$scope);
    }
    if (empty($f['include_unfinished'])) {
        $where[] = 'c.completed_at IS NOT NULL';
    }
    if (!empty($f['status'])) {
        if (!in_array($f['status'], PIPELINE, true)) { throw new HttpError(422, 'Unknown status.'); }
        $where[] = 'c.pipeline_status = ?'; $args[] = $f['status'];
    }
    if (!empty($f['purpose'])) {
        if (!in_array($f['purpose'], PURPOSES, true)) { throw new HttpError(422, 'Unknown purpose.'); }
        $where[] = 'c.purpose = ?'; $args[] = $f['purpose'];
    }
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        // '!' is the LIKE escape character: a backslash would mean different things to MySQL and SQLite.
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($q, 0, 60)) . '%';
        $where[] = "(c.name LIKE ? ESCAPE '!' OR c.mobile LIKE ? ESCAPE '!' OR c.email LIKE ? ESCAPE '!')";
        array_push($args, $like, $like, $like);
    }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM candidates c $w");
    $stmt->execute($args);
    $total = (int)$stmt->fetchColumn();

    $page = max(1, (int)($f['page'] ?? 1));
    $stmt = $pdo->prepare("SELECT c.*, a.code AS a_code, a.scores_json AS a_scores, a.mode AS a_mode FROM candidates c
        LEFT JOIN assessments a ON a.id = (SELECT MAX(id) FROM assessments WHERE candidate_id = c.id)
        $w ORDER BY c.id DESC LIMIT " . LIST_PAGE_SIZE . ' OFFSET ' . (($page - 1) * LIST_PAGE_SIZE));
    $stmt->execute($args);
    return [
        'candidates' => array_map(static fn (array $c) => candidate_summary($c, $map), $stmt->fetchAll()),
        'total' => $total, 'page' => $page, 'page_size' => LIST_PAGE_SIZE,
    ];
}

function load_candidate_row(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT c.*, a.code AS a_code, a.scores_json AS a_scores, a.mode AS a_mode FROM candidates c
        LEFT JOIN assessments a ON a.id = (SELECT MAX(id) FROM assessments WHERE candidate_id = c.id) WHERE c.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Candidates the viewer may not see are reported as 404, so their existence is not revealed. */
function get_candidate(PDO $pdo, array $map, array $viewer, int $id, string $lang = 'en'): array
{
    $row = load_candidate_row($pdo, $id);
    if (!$row || !can_see_candidate($map, $viewer, (int)$row['owner_member_id'])) {
        throw new HttpError(404, 'Candidate not found.');
    }
    $out = candidate_summary($row, $map);
    foreach (['age_group' => 'age', 'education' => 'edu', 'work_status' => 'status'] as $col => $key) {
        $out['details'][$key] = $row[$col] === null ? null : (int)$row[$col];
    }
    $out['details']['field'] = $row['field'];
    $out['consent'] = ['at' => iso($row['consent_at']), 'version' => $row['consent_version']];
    $out['scores'] = $row['a_scores'] ? json_decode($row['a_scores'], true) : null;
    $out['assessment'] = $out['scores'] ? assess_candidate($out['scores'], $lang) : null;
    $out['advisor_lang'] = $lang === 'zh' ? 'zh' : 'en';

    $chain = [];
    foreach (upline_chain($map, (int)$row['owner_member_id']) as $mid) {
        $chain[] = ['id' => $mid, 'name' => $map[$mid]['name'], 'rank' => $map[$mid]['rank_label']];
    }
    $out['owner_chain'] = $chain;
    if ($row['duplicate_of'] !== null) {
        $orig = load_candidate_row($pdo, (int)$row['duplicate_of']);
        $out['duplicate'] = $orig && can_see_candidate($map, $viewer, (int)$orig['owner_member_id'])
            ? ['id' => (int)$orig['id'], 'started_at' => iso($orig['started_at']), 'code' => $orig['a_code']] : ['id' => (int)$row['duplicate_of']];
    }
    $out['reassign_options'] = reassign_options($map, $viewer);
    $out['pipeline'] = PIPELINE;
    return $out;
}

/** Members the viewer may hand a candidate to: admin -> any active member; others -> themselves and their downline. */
function reassign_options(array $map, array $viewer): array
{
    $ids = $viewer['is_admin'] ? array_keys($map) : visible_member_ids($map, (int)$viewer['id']);
    $opts = [];
    foreach ($ids as $id) {
        if ($map[$id]['is_active']) {
            $opts[] = ['id' => $id, 'name' => $map[$id]['name'], 'rank' => $map[$id]['rank_label']];
        }
    }
    usort($opts, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
    return $opts;
}

/** @param array $in pipeline_status? owner_member_id? purpose? */
function update_candidate(PDO $pdo, array $map, array $viewer, int $id, array $in): void
{
    $row = load_candidate_row($pdo, $id);
    if (!$row || !can_see_candidate($map, $viewer, (int)$row['owner_member_id'])) {
        throw new HttpError(404, 'Candidate not found.');
    }
    $set = [];
    $audit = [];
    if (array_key_exists('pipeline_status', $in)) {
        if (!in_array($in['pipeline_status'], PIPELINE, true)) {
            throw new HttpError(422, 'Unknown status.');
        }
        $set['pipeline_status'] = $in['pipeline_status'];
        $audit[] = ['candidate.status', ['from' => $row['pipeline_status'], 'to' => $in['pipeline_status']]];
    }
    if (array_key_exists('purpose', $in)) {
        if (!in_array($in['purpose'], PURPOSES, true)) {
            throw new HttpError(422, 'Unknown purpose.');
        }
        if ($in['purpose'] !== $row['purpose']) {
            $set['purpose'] = $in['purpose'];
            $audit[] = ['candidate.purpose', ['from' => $row['purpose'], 'to' => $in['purpose']]];
        }
    }
    if (array_key_exists('owner_member_id', $in)) {
        $to = (int)$in['owner_member_id'];
        $allowed = array_column(reassign_options($map, $viewer), 'id');
        if (!in_array($to, $allowed, true)) {
            throw new HttpError(403, 'You can only assign a candidate to yourself or someone in your team.');
        }
        if ($to !== (int)$row['owner_member_id']) {
            $set['owner_member_id'] = $to;
            $audit[] = ['candidate.reassign', ['from' => (int)$row['owner_member_id'], 'to' => $to]];
        }
    }
    if (!$set) {
        return;
    }
    $cols = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($set)));
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE candidates SET $cols WHERE id = ?")->execute([...array_values($set), $id]);
        foreach ($audit as [$action, $detail]) {
            audit($pdo, (int)$viewer['id'], $action, $id, $detail);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** PDPA: an admin can erase a candidate (and their results) on request. The audit entry keeps no personal data. */
function delete_candidate(PDO $pdo, array $map, array $viewer, int $id): void
{
    if (!$viewer['is_admin']) {
        throw new HttpError(403, 'Admins only.');
    }
    $row = load_candidate_row($pdo, $id);
    if (!$row) {
        throw new HttpError(404, 'Candidate not found.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE candidates SET duplicate_of = NULL WHERE duplicate_of = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM assessments WHERE candidate_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM candidates WHERE id = ?')->execute([$id]);
        audit($pdo, (int)$viewer['id'], 'candidate.delete', $id, ['owner_member_id' => (int)$row['owner_member_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Spreadsheet formulas in names ("=HYPERLINK(...)") must not run when an admin opens the CSV. */
function csv_cell(mixed $v): string
{
    $s = (string)($v ?? '');
    if ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) {
        $s = "'" . $s;
    }
    return '"' . str_replace('"', '""', $s) . '"';
}

/** @param ?int[] $ownerIds limit to these owners (used for "download a copy" before removing a group) */
function export_candidates_csv(PDO $pdo, array $map, array $viewer, ?array $ownerIds = null): string
{
    if (!$viewer['is_admin']) {
        throw new HttpError(403, 'Admins only.');
    }
    $hdr = ['id', 'started (UTC)', 'completed (UTC)', 'name', 'mobile', 'email', 'purpose', 'source', 'advisor', 'link used', 'code', 'verdict', 'status', 'duplicate of'];
    $lines = [implode(',', array_map('csv_cell', $hdr))];
    $where = $ownerIds === null ? '' : ($ownerIds ? 'WHERE c.owner_member_id IN (' . implode(',', array_map('intval', $ownerIds)) . ')' : 'WHERE 1 = 0');
    $stmt = $pdo->query("SELECT c.*, a.code AS a_code, a.scores_json AS a_scores, a.mode AS a_mode FROM candidates c
        LEFT JOIN assessments a ON a.id = (SELECT MAX(id) FROM assessments WHERE candidate_id = c.id) $where ORDER BY c.id");
    foreach ($stmt as $c) {
        $s = candidate_summary($c, $map);
        $lines[] = implode(',', array_map('csv_cell', [$s['id'], $c['started_at'], $c['completed_at'], $s['name'], $s['mobile'], $s['email'], $s['purpose'],
            $s['source'], $s['owner']['name'] ?? '', $s['via_link']['name'] ?? '', $s['code'], $s['verdict']['title'] ?? '', $s['status'], $s['duplicate_of']]));
    }
    return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";   // BOM so Excel shows Chinese names correctly
}
