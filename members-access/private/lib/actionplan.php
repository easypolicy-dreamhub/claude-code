<?php
declare(strict_types=1);

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/team.php';
require_once __DIR__ . '/actionplan_seed.php';
require_once __DIR__ . '/actionplan_auto.php';

/**
 * Action Plan: one template, a plan per associate, progress with approval.
 *
 * Statuses of an item for one person: todo -> waiting (agent ticked / reached the count) -> done (approved)
 *                                              \-> followup (sent back with a note)
 * Weeks are a GUIDE: the agent sees "suggested by week N", only approvers see Behind / On track.
 */
const AP_BASE_DAYS = 90;
const AP_TZ_DEFAULT = 'Asia/Kuala_Lumpur';
const AP_MAX_WEEK = 26;
const AP_DISC = ['D', 'I', 'S', 'C', 'DI', 'DS', 'DC', 'ID', 'IS', 'IC', 'SD', 'SI', 'SC', 'CD', 'CI', 'CS'];
const AP_DISC_HINT = [
    'D' => 'Likes targets, speed and freedom. Give clear goals and let them run; keep it brief.',
    'I' => 'Likes people, energy and recognition. Motivate with team activities and praise; help them follow through.',
    'S' => 'Likes steady steps and support. Give a clear routine and reassurance; do not rush big changes.',
    'C' => 'Likes detail, accuracy and reasons. Give data and time to prepare; avoid vague instructions.',
];

/* ---------- small helpers ---------- */

function ap_today(array $config = []): string
{
    $tz = new DateTimeZone((string)($config['timezone'] ?? AP_TZ_DEFAULT));
    return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
}

function ap_date(string $s): string
{
    $s = trim($s);
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
        $s = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s || (int)$d->format('Y') < 2000 || (int)$d->format('Y') > 2100) {
        throw new HttpError(422, 'Enter the date as day/month/year, for example 25/11/2026.');
    }
    return $s;
}

function ap_days_between(string $from, string $to): int
{
    return (int)floor((strtotime($to . ' 00:00:00 UTC') - strtotime($from . ' 00:00:00 UTC')) / 86400);
}

/** 0 = not started yet; otherwise 1, 2, 3 ... counted from the start date. */
function ap_week(string $start, string $today): int
{
    $d = ap_days_between($start, $today);
    return $d < 0 ? 0 : intdiv($d, 7) + 1;
}

function ap_weeks_total(int $lengthDays): int
{
    return (int)ceil($lengthDays / 7);
}

function ap_scale_from(int $w, int $len): int
{
    return max(1, (int)floor(($w - 1) * $len / AP_BASE_DAYS) + 1);
}

function ap_scale_to(int $w, int $len): int
{
    return max(1, (int)ceil($w * $len / AP_BASE_DAYS));
}

function ap_int_range(mixed $v, int $min, int $max, string $what): int
{
    if (!is_numeric($v) || (int)$v != $v || (int)$v < $min || (int)$v > $max) {
        throw new HttpError(422, "$what must be a whole number from $min to $max.");
    }
    return (int)$v;
}

function ap_text(mixed $v, int $max, string $what, bool $required = true): ?string
{
    $s = trim(preg_replace('/[ \t]+/', ' ', (string)($v ?? '')) ?? '');
    if ($s === '') {
        if ($required) {
            throw new HttpError(422, "$what is needed.");
        }
        return null;
    }
    if (mb_strlen($s) > $max) {
        throw new HttpError(422, "$what is too long (max $max characters).");
    }
    return $s;
}

function ap_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function ap_role(array $viewer): string
{
    return tool_is_super($viewer) ? 'super' : (is_team_admin($viewer) ? 'approver' : 'agent');
}

/** Member ids an approver may act on: null = everyone (super admin), otherwise the people below them. */
function ap_scope(array $map, array $viewer): ?array
{
    if (tool_is_super($viewer)) {
        return null;
    }
    return is_team_admin($viewer) ? downline_ids($map, (int)$viewer['id']) : [];
}

function ap_in_scope(?array $scope, int $memberId): bool
{
    return $scope === null || in_array($memberId, $scope, true);
}

/* ---------- template ---------- */

function ap_ensure_template(PDO $pdo, string $today): void
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM ap_sections')->fetchColumn() > 0) {
        return;
    }
    $so = 0;
    $sec = $pdo->prepare('INSERT INTO ap_sections (name, sort_order, active) VALUES (?, ?, 1)');
    $ins = $pdo->prepare('INSERT INTO ap_items (section_id, title, details, week_from, week_to, kind, target, tool_key, auto_key, sort_order, active, archived, created_on) VALUES (?,?,?,?,?,?,?,?,?,?,1,0,?)');
    $pdo->beginTransaction();
    try {
        foreach (ap_seed_data() as $name => $items) {
            $sec->execute([$name, ++$so * 10]);
            $sid = (int)$pdo->lastInsertId();
            $io = 0;
            foreach ($items as $row) {
                [$title, $details, $from, $to, $kind, $target, $tool] = $row;
                $ins->execute([$sid, $title, $details, $from, $to, $kind, $target, $tool, $row[7] ?? null, ++$io * 10, $today]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ap_tool_url(?string $key): ?array
{
    if ($key === null || $key === '' || ($t = tool_by_key($key)) === null) {
        return null;
    }
    $url = $t['dir'] !== '' ? '/members/' . $t['dir'] . '/' : ($key === 'client-portal' ? '/client-portal/' : '');
    return $url === '' ? null : ['key' => $key, 'name' => $t['name'], 'url' => $url];
}

function ap_tool_options(): array
{
    $out = [];
    foreach (tool_catalog() as $t) {
        if (!$t['locked'] && ap_tool_url($t['key'])) {
            $out[] = ['key' => $t['key'], 'name' => $t['name']];
        }
    }
    return $out;
}

/** The whole template for the editor (turned-off items included, archived ones hidden). */
function ap_template(PDO $pdo): array
{
    $secs = $pdo->query('SELECT * FROM ap_sections ORDER BY sort_order, id')->fetchAll();
    $items = $pdo->query('SELECT * FROM ap_items WHERE plan_id IS NULL AND archived = 0 ORDER BY sort_order, id')->fetchAll();
    $out = [];
    foreach ($secs as $s) {
        $out[(int)$s['id']] = ['id' => (int)$s['id'], 'name' => $s['name'], 'active' => (bool)$s['active'], 'items' => []];
    }
    foreach ($items as $i) {
        if (isset($out[(int)$i['section_id']])) {
            $out[(int)$i['section_id']]['items'][] = ap_item_public($i);
        }
    }
    return array_values($out);
}

function ap_item_public(array $i): array
{
    return [
        'id' => (int)$i['id'], 'section_id' => $i['section_id'] === null ? null : (int)$i['section_id'], 'title' => $i['title'], 'details' => (string)($i['details'] ?? ''),
        'week_from' => (int)$i['week_from'], 'week_to' => (int)$i['week_to'], 'kind' => $i['kind'], 'target' => $i['target'] === null ? null : (int)$i['target'],
        'tool_key' => $i['tool_key'], 'auto_key' => $i['auto_key'] ?? null, 'active' => (bool)$i['active'],
    ];
}

function ap_clean_item(array $in, PDO $pdo): array
{
    $kind = ($in['kind'] ?? 'tick') === 'count' ? 'count' : 'tick';
    $from = ap_int_range($in['week_from'] ?? 1, 1, AP_MAX_WEEK, 'The first week');
    $to = ap_int_range($in['week_to'] ?? $from, 1, AP_MAX_WEEK, 'The last week');
    if ($to < $from) {
        throw new HttpError(422, 'The last week cannot be before the first week.');
    }
    $target = null;
    if ($kind === 'count') {
        $target = ap_int_range($in['target'] ?? 0, 1, 100000, 'The target');
    }
    $tool = trim((string)($in['tool_key'] ?? ''));
    if ($tool !== '' && ap_tool_url($tool) === null) {
        throw new HttpError(422, 'Unknown tool for the shortcut.');
    }
    $auto = trim((string)($in['auto_key'] ?? ''));
    if ($auto !== '' && !isset(AP_AUTO[$auto])) {
        throw new HttpError(422, 'Unknown automatic count.');
    }
    return [
        'title' => ap_text($in['title'] ?? '', 160, 'The task'), 'details' => ap_text($in['details'] ?? '', 1000, 'Details', false),
        'week_from' => $from, 'week_to' => $to, 'kind' => $kind, 'target' => $target, 'tool_key' => $tool === '' ? null : $tool, 'auto_key' => $auto === '' ? null : $auto,
    ];
}

function ap_section_add(PDO $pdo, array $actor, string $name): int
{
    $name = ap_text($name, 80, 'The section name');
    $max = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM ap_sections')->fetchColumn();
    $pdo->prepare('INSERT INTO ap_sections (name, sort_order, active) VALUES (?, ?, 1)')->execute([$name, $max + 10]);
    $id = (int)$pdo->lastInsertId();
    audit($pdo, (int)$actor['id'], 'ap.section.add', $id, ['name' => $name]);
    return $id;
}

function ap_section_update(PDO $pdo, array $actor, int $id, array $in): void
{
    $s = $pdo->prepare('SELECT * FROM ap_sections WHERE id = ?');
    $s->execute([$id]);
    if (!$s->fetch()) {
        throw new HttpError(404, 'Section not found.');
    }
    if (array_key_exists('name', $in)) {
        $pdo->prepare('UPDATE ap_sections SET name = ? WHERE id = ?')->execute([ap_text($in['name'], 80, 'The section name'), $id]);
    }
    if (array_key_exists('active', $in)) {
        $pdo->prepare('UPDATE ap_sections SET active = ? WHERE id = ?')->execute([$in['active'] === true ? 1 : 0, $id]);
    }
    audit($pdo, (int)$actor['id'], 'ap.section.update', $id, array_intersect_key($in, ['name' => 1, 'active' => 1]));
}

/** Delete a section only when it has no items at all; otherwise it can be turned off. */
function ap_section_delete(PDO $pdo, array $actor, int $id): void
{
    $n = $pdo->prepare('SELECT COUNT(*) FROM ap_items WHERE section_id = ?');
    $n->execute([$id]);
    if ((int)$n->fetchColumn() > 0) {
        throw new HttpError(422, 'This section still has items. Turn it off instead, or remove its items first.');
    }
    $pdo->prepare('DELETE FROM ap_sections WHERE id = ?')->execute([$id]);
    audit($pdo, (int)$actor['id'], 'ap.section.delete', $id, []);
}

/** Swap with the neighbour above (-1) or below (+1). */
function ap_section_move(PDO $pdo, int $id, int $dir): void
{
    $rows = $pdo->query('SELECT id, sort_order FROM ap_sections ORDER BY sort_order, id')->fetchAll();
    ap_swap($pdo, 'ap_sections', $rows, $id, $dir);
}

function ap_item_move(PDO $pdo, int $id, int $dir): void
{
    $it = $pdo->prepare('SELECT section_id FROM ap_items WHERE id = ? AND plan_id IS NULL');
    $it->execute([$id]);
    $sid = $it->fetchColumn();
    if ($sid === false) {
        throw new HttpError(404, 'Item not found.');
    }
    $rows = $pdo->prepare('SELECT id, sort_order FROM ap_items WHERE plan_id IS NULL AND archived = 0 AND section_id = ? ORDER BY sort_order, id');
    $rows->execute([$sid]);
    ap_swap($pdo, 'ap_items', $rows->fetchAll(), $id, $dir);
}

function ap_swap(PDO $pdo, string $table, array $rows, int $id, int $dir): void
{
    $pos = null;
    foreach ($rows as $i => $r) {
        if ((int)$r['id'] === $id) {
            $pos = $i;
        }
    }
    if ($pos === null) {
        throw new HttpError(404, 'Not found.');
    }
    $other = $pos + ($dir < 0 ? -1 : 1);
    if (!isset($rows[$other])) {
        return;
    }
    // renumber the whole list so ties never matter
    $order = array_column($rows, 'id');
    [$order[$pos], $order[$other]] = [$order[$other], $order[$pos]];
    $up = $pdo->prepare("UPDATE $table SET sort_order = ? WHERE id = ?");
    foreach ($order as $n => $rid) {
        $up->execute([($n + 1) * 10, $rid]);
    }
}

function ap_item_add(PDO $pdo, array $actor, array $in): int
{
    $sid = (int)($in['section_id'] ?? 0);
    $s = $pdo->prepare('SELECT 1 FROM ap_sections WHERE id = ?');
    $s->execute([$sid]);
    if (!$s->fetchColumn()) {
        throw new HttpError(422, 'Choose a section.');
    }
    $c = ap_clean_item($in, $pdo);
    $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM ap_items WHERE section_id = ? AND plan_id IS NULL');
    $max->execute([$sid]);
    $pdo->prepare('INSERT INTO ap_items (section_id, title, details, week_from, week_to, kind, target, tool_key, auto_key, sort_order, active, archived, created_on) VALUES (?,?,?,?,?,?,?,?,?,?,1,0,?)')
        ->execute([$sid, $c['title'], $c['details'], $c['week_from'], $c['week_to'], $c['kind'], $c['target'], $c['tool_key'], $c['auto_key'], (int)$max->fetchColumn() + 10, gmdate('Y-m-d')]);
    $id = (int)$pdo->lastInsertId();
    audit($pdo, (int)$actor['id'], 'ap.item.add', $id, ['title' => $c['title']]);
    return $id;
}

function ap_item_update(PDO $pdo, array $actor, int $id, array $in): void
{
    $cur = $pdo->prepare('SELECT * FROM ap_items WHERE id = ? AND plan_id IS NULL AND archived = 0');
    $cur->execute([$id]);
    $row = $cur->fetch();
    if (!$row) {
        throw new HttpError(404, 'Item not found.');
    }
    if (array_key_exists('active', $in) && !array_key_exists('title', $in)) {   // just turning on / off
        $pdo->prepare('UPDATE ap_items SET active = ? WHERE id = ?')->execute([$in['active'] === true ? 1 : 0, $id]);
        audit($pdo, (int)$actor['id'], $in['active'] === true ? 'ap.item.on' : 'ap.item.off', $id, []);
        return;
    }
    $merged = array_merge(ap_item_public($row), array_intersect_key($in, array_flip(['title', 'details', 'week_from', 'week_to', 'kind', 'target', 'tool_key', 'auto_key', 'section_id'])));
    $c = ap_clean_item($merged, $pdo);
    $sid = (int)($merged['section_id'] ?? $row['section_id']);
    $s = $pdo->prepare('SELECT 1 FROM ap_sections WHERE id = ?');
    $s->execute([$sid]);
    if (!$s->fetchColumn()) {
        throw new HttpError(422, 'Choose a section.');
    }
    $pdo->prepare('UPDATE ap_items SET section_id = ?, title = ?, details = ?, week_from = ?, week_to = ?, kind = ?, target = ?, tool_key = ?, auto_key = ? WHERE id = ?')
        ->execute([$sid, $c['title'], $c['details'], $c['week_from'], $c['week_to'], $c['kind'], $c['target'], $c['tool_key'], $c['auto_key'], $id]);
    audit($pdo, (int)$actor['id'], 'ap.item.update', $id, ['title' => $c['title']]);
}

/** Never started by anyone: deleted. Otherwise archived, so approved work stays in people's history. @return string 'deleted'|'archived' */
function ap_item_remove(PDO $pdo, array $actor, int $id): string
{
    $cur = $pdo->prepare('SELECT 1 FROM ap_items WHERE id = ? AND plan_id IS NULL AND archived = 0');
    $cur->execute([$id]);
    if (!$cur->fetchColumn()) {
        throw new HttpError(404, 'Item not found.');
    }
    $used = $pdo->prepare("SELECT COUNT(*) FROM ap_progress WHERE item_id = ? AND (status <> 'todo' OR cnt > 0)");
    $used->execute([$id]);
    if ((int)$used->fetchColumn() > 0) {
        $pdo->prepare('UPDATE ap_items SET archived = 1, active = 0 WHERE id = ?')->execute([$id]);
        $how = 'archived';
    } else {
        $pdo->prepare('DELETE FROM ap_progress WHERE item_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM ap_overrides WHERE item_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM ap_items WHERE id = ?')->execute([$id]);
        $how = 'deleted';
    }
    audit($pdo, (int)$actor['id'], 'ap.item.remove', $id, ['how' => $how]);
    return $how;
}

/* ---------- plans ---------- */

function ap_plan_row(PDO $pdo, int $planId): array
{
    $s = $pdo->prepare('SELECT * FROM ap_plans WHERE id = ?');
    $s->execute([$planId]);
    $p = $s->fetch();
    if (!$p) {
        throw new HttpError(404, 'Plan not found.');
    }
    return $p;
}

function ap_plan_of_member(PDO $pdo, int $memberId): ?array
{
    $s = $pdo->prepare('SELECT * FROM ap_plans WHERE member_id = ?');
    $s->execute([$memberId]);
    return $s->fetch() ?: null;
}

/** Everyone active, for the "Try the automatic counts" picker. */
function ap_try_members(array $map): array
{
    $out = [];
    foreach ($map as $m) {
        if ($m['is_active']) {
            $out[] = ['id' => (int)$m['id'], 'name' => $m['name']];
        }
    }
    usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    return $out;
}

/** Team members who can still be given a plan. */
function ap_assignable(PDO $pdo, array $map): array
{
    $have = array_map('intval', $pdo->query('SELECT member_id FROM ap_plans WHERE closed = 0')->fetchAll(PDO::FETCH_COLUMN));
    $out = [];
    foreach ($map as $m) {
        if ($m['is_active'] && !tool_is_super($m) && member_type($m) === 'team' && !in_array((int)$m['id'], $have, true)) {
            $out[] = ['id' => (int)$m['id'], 'name' => $m['name'], 'has' => array_values(array_unique(member_tool_keys($pdo, $m)))];
        }
    }
    usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    return $out;
}

/** The tools the template's shortcuts point to, with the items that use each (for the Assign tick-box). */
function ap_plan_tools(PDO $pdo): array
{
    $rows = $pdo->query('SELECT tool_key, title FROM ap_items WHERE plan_id IS NULL AND active = 1 AND archived = 0 AND tool_key IS NOT NULL ORDER BY sort_order, id')->fetchAll();
    $by = [];
    foreach ($rows as $r) {
        $by[(string)$r['tool_key']][] = (string)$r['title'];
    }
    $out = [];
    foreach (tool_catalog() as $t) {
        if (!$t['locked'] && isset($by[$t['key']]) && ap_tool_url($t['key'])) {
            $out[] = ['key' => $t['key'], 'name' => $t['name'], 'items' => $by[$t['key']]];
        }
    }
    return $out;
}

/** Switch on the chosen tools (only ones the template really uses) for a person. Returns the names switched on. */
function ap_switch_on_tools(PDO $pdo, array $m, array $keys): array
{
    $allowed = array_column(ap_plan_tools($pdo), 'name', 'key');
    $done = [];
    $ticked = ticked_tools($pdo, (int)$m['id']);
    $newTicked = $ticked;
    foreach (array_unique(array_map('strval', $keys)) as $k) {
        if (!isset($allowed[$k])) {
            continue;
        }
        if ($k === 'client-portal') {
            if (!portal_enabled((string)$m['email'])) {
                portal_set((string)$m['email'], (string)$m['name'], true);
                $done[] = $allowed[$k];
            }
        } elseif (!in_array($k, $ticked, true)) {
            $newTicked[] = $k;
            $done[] = $allowed[$k];
        }
    }
    if ($newTicked !== $ticked) {
        set_ticked_tools($pdo, (int)$m['id'], $newTicked);
    }
    return $done;
}

function ap_assign(PDO $pdo, array $actor, int $memberId, string $start, int $days, array $tools = []): int
{
    $map = load_member_map($pdo);
    $m = $map[$memberId] ?? null;
    if ($m === null || !$m['is_active'] || tool_is_super($m) || member_type($m) !== 'team') {
        throw new HttpError(422, 'A plan can only be given to an active Team member.');
    }
    $start = ap_date($start);
    $days = ap_int_range($days, 30, 365, 'The plan length (days)');
    $old = ap_plan_of_member($pdo, $memberId);
    if ($old !== null && !(int)$old['closed']) {
        throw new HttpError(409, 'That person already has a plan.');
    }
    $pdo->beginTransaction();
    try {
        if ($old !== null) {   // bring a closed plan back, with its progress
            $pdo->prepare('UPDATE ap_plans SET closed = 0, start_date = ?, length_days = ? WHERE id = ?')->execute([$start, $days, (int)$old['id']]);
            $id = (int)$old['id'];
        } else {
            $pdo->prepare('INSERT INTO ap_plans (member_id, start_date, length_days, assigned_by, closed, created_at) VALUES (?,?,?,?,0,?)')
                ->execute([$memberId, $start, $days, (int)$actor['id'], ap_now()]);
            $id = (int)$pdo->lastInsertId();
        }
        audit($pdo, (int)$actor['id'], 'ap.assign', $memberId, ['plan' => $id, 'start' => $start, 'days' => $days]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    // So they can open the card, switch Action Plan on for them, and for any team admin above them (who approves their items).
    // Best effort: the plan exists either way.
    try {
        $who = [$memberId];
        for ($up = $m['upline_id'], $guard = 0; $up !== null && isset($map[$up]) && $guard < 50; $up = $map[$up]['upline_id'], $guard++) {
            if (is_team_admin($map[$up])) {
                $who[] = (int)$up;
            }
        }
        foreach ($who as $w) {
            $ticked = ticked_tools($pdo, $w);
            if (!in_array('action-plan', $ticked, true)) {
                set_ticked_tools($pdo, $w, array_merge($ticked, ['action-plan']));
            }
        }
    } catch (Throwable $e) {
        error_log('ap_assign tick: ' . $e->getMessage());
    }
    if ($tools) {
        try {
            $done = ap_switch_on_tools($pdo, $m, $tools);
            if ($done) {
                audit($pdo, (int)$actor['id'], 'ap.assign_tools', $memberId, ['tools' => $done]);
            }
        } catch (Throwable $e) {
            error_log('ap_assign tools: ' . $e->getMessage());
        }
    }
    return $id;
}

function ap_plan_update(PDO $pdo, array $actor, int $planId, array $in): void
{
    $plan = ap_plan_row($pdo, $planId);
    $set = [];
    $newBuddy = null;
    if (array_key_exists('buddy_id', $in)) {
        $bv = trim((string)$in['buddy_id']);
        if ($bv === '') {
            $set['buddy_id'] = null;
        } else {
            $bm = load_member_map($pdo)[(int)$bv] ?? null;
            if ($bm === null || !$bm['is_active'] || (int)$bv === (int)$plan['member_id']) {
                throw new HttpError(422, 'Choose another active person as the buddy.');
            }
            $set['buddy_id'] = $newBuddy = (int)$bv;
        }
    }
    if (array_key_exists('start_date', $in)) {
        $set['start_date'] = ap_date((string)$in['start_date']);
    }
    if (array_key_exists('length_days', $in)) {
        $set['length_days'] = ap_int_range($in['length_days'], 30, 365, 'The plan length (days)');
    }
    if (array_key_exists('disc', $in)) {
        $d = strtoupper(trim((string)$in['disc']));
        if ($d !== '' && !in_array($d, AP_DISC, true)) {
            throw new HttpError(422, 'Choose a DISC style.');
        }
        $set['disc'] = $d === '' ? null : $d;
    }
    if (array_key_exists('private_number', $in)) {
        $n = trim((string)$in['private_number']);
        $set['private_number'] = $n === '' ? null : ap_int_range($n, 1, 33, 'The number');
    }
    if (array_key_exists('closed', $in)) {
        $set['closed'] = $in['closed'] === true ? 1 : 0;
    }
    if (!$set) {
        return;
    }
    $cols = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($set)));
    $pdo->prepare("UPDATE ap_plans SET $cols WHERE id = ?")->execute([...array_values($set), $planId]);
    audit($pdo, (int)$actor['id'], 'ap.plan.update', $planId, array_diff_key($set, ['private_number' => 1]));
    if ($newBuddy !== null) {   // so the buddy can open the Action Plan card and see their buddy's progress
        try {
            $ticked = ticked_tools($pdo, $newBuddy);
            if (!in_array('action-plan', $ticked, true)) {
                set_ticked_tools($pdo, $newBuddy, array_merge($ticked, ['action-plan']));
            }
        } catch (Throwable $e) {
            error_log('ap buddy tick: ' . $e->getMessage());
        }
    }
}

/* ---------- WhatsApp nudge (a message the leader opens in their own WhatsApp; nothing is sent from the server) ---------- */

function ap_nudge_draft(PDO $pdo, array $map, array $plan, string $today): array
{
    $m = $map[(int)$plan['member_id']] ?? null;
    if ($m === null) {
        throw new HttpError(404, 'Person not found.');
    }
    $week = ap_week((string)$plan['start_date'], $today);
    $items = ap_plan_items($pdo, $plan, $today);
    $sum = ap_summary($items, $week);
    $label = static fn (array $it): string => $it['title'] . ($it['kind'] === 'count' && $it['target'] ? ': ' . $it['count'] . ' of ' . $it['target'] . ' so far' : '');
    $follow = $due = [];
    foreach ($items as $it) {
        if ($it['skipped']) {
            continue;
        }
        if ($it['status'] === 'followup') {
            $follow[] = '• ' . $label($it) . ($it['note'] !== '' ? ': ' . $it['note'] : '');
        } elseif ($it['status'] === 'todo' && ($it['overdue'] || $it['pace_behind'])) {
            $due[] = '• ' . $label($it);
        }
    }
    $first = trim((string)strtok(trim((string)$m['name']), ' '));
    $t = 'Hi ' . ($first !== '' ? $first : 'there') . ', a quick check-in on your Action Plan.';
    $t .= $week >= 1
        ? ' You are in week ' . min($week, ap_weeks_total((int)$plan['length_days'])) . ' of ' . ap_weeks_total((int)$plan['length_days']) . ' and ' . $sum['percent'] . '% is approved so far.'
        : ' Your plan starts soon.';
    if ($follow) {
        $t .= "\n\nThings I would like you to take another look at:\n" . implode("\n", array_slice($follow, 0, 5)) . (count($follow) > 5 ? "\nand " . (count($follow) - 5) . ' more' : '');
    }
    if ($due) {
        $t .= "\n\nThese are due about now:\n" . implode("\n", array_slice($due, 0, 5)) . (count($due) > 5 ? "\nand " . (count($due) - 5) . ' more' : '');
    }
    if (!$follow && !$due) {
        $t .= "\n\nYou are on track. Keep it up!";
    }
    $t .= "\n\nTell me if you would like to go through anything together.";
    $wa = preg_replace('/\D+/', '', (string)($m['whatsapp'] ?? '')) ?? '';
    return ['name' => $m['name'], 'whatsapp' => $wa === '' ? null : $wa, 'text' => $t, 'last_nudged_at' => $plan['last_nudged_at'] ?? null];
}

function ap_nudge_done(PDO $pdo, array $actor, array $plan): string
{
    $now = ap_now();
    $pdo->prepare('UPDATE ap_plans SET last_nudged_at = ? WHERE id = ?')->execute([$now, (int)$plan['id']]);
    audit($pdo, (int)$actor['id'], 'ap.nudge', (int)$plan['member_id'], ['plan' => (int)$plan['id']]);
    return $now;
}

/* ---------- buddy: a read-only look at the progress of the person you are buddy to ---------- */

function ap_buddy_list(PDO $pdo, array $map, int $viewerId, string $today): array
{
    $st = $pdo->prepare('SELECT * FROM ap_plans WHERE buddy_id = ? AND closed = 0');
    $st->execute([$viewerId]);
    $out = [];
    foreach ($st->fetchAll() as $plan) {
        $mid = (int)$plan['member_id'];
        if (!isset($map[$mid]) || !$map[$mid]['is_active']) {
            continue;
        }
        $week = ap_week((string)$plan['start_date'], $today);
        $sum = ap_summary(ap_plan_items($pdo, $plan, $today), $week);
        $out[] = ['plan_id' => (int)$plan['id'], 'name' => $map[$mid]['name'], 'week' => $week, 'weeks_total' => ap_weeks_total((int)$plan['length_days']),
            'percent' => $sum['percent'], 'done' => $sum['done'], 'total' => $sum['total']];
    }
    usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    return $out;
}

/** Only titles, ticks and counts: no leader notes, labels, DISC, private number or skipped items. */
function ap_buddy_view(PDO $pdo, array $map, int $viewerId, int $planId, string $today): array
{
    $plan = ap_plan_row($pdo, $planId);
    if ((int)($plan['buddy_id'] ?? 0) !== $viewerId || (int)$plan['closed']) {
        throw new HttpError(403, 'You are not the buddy on this plan.');
    }
    $week = ap_week((string)$plan['start_date'], $today);
    $items = ap_plan_items($pdo, $plan, $today);
    $sum = ap_summary($items, $week);
    $secs = [];
    foreach ($items as $it) {
        if ($it['skipped']) {
            continue;
        }
        $state = $it['status'] === 'done' ? 'done' : ($it['status'] === 'waiting' ? 'waiting' : 'todo');
        $secs[$it['section']][] = ['title' => $it['title'], 'kind' => $it['kind'], 'count' => $it['count'], 'target' => $it['target'], 'state' => $state];
    }
    $sections = [];
    foreach ($secs as $name => $its) {
        $sections[] = ['name' => (string)$name, 'done' => count(array_filter($its, static fn (array $i): bool => $i['state'] === 'done')), 'items' => $its];
    }
    return ['plan_id' => $planId, 'name' => $map[(int)$plan['member_id']]['name'] ?? 'Unknown', 'week' => $week, 'weeks_total' => ap_weeks_total((int)$plan['length_days']),
        'start_date' => $plan['start_date'], 'percent' => $sum['percent'], 'done' => $sum['done'], 'total' => $sum['total'], 'sections' => $sections];
}

/* ---------- items of one plan ---------- */

/**
 * Every item that applies to this plan with its status and timing worked out.
 * Rules: weeks are scaled to the plan length; per-person overrides win; an item added to the template after the plan
 * was assigned never starts out overdue (it falls due the week after it was added).
 */
function ap_plan_items(PDO $pdo, array $plan, string $today): array
{
    $pid = (int)$plan['id'];
    $len = (int)$plan['length_days'];
    $start = (string)$plan['start_date'];
    $week = ap_week($start, $today);
    $days = ap_days_between($start, $today);
    $assigned = substr((string)$plan['created_at'], 0, 10);

    $rows = $pdo->prepare("SELECT i.*, s.name AS section_name, s.sort_order AS s_sort, s.active AS s_active FROM ap_items i
        LEFT JOIN ap_sections s ON s.id = i.section_id
        WHERE i.archived = 0 AND i.active = 1 AND (i.plan_id IS NULL OR i.plan_id = ?)");
    $rows->execute([$pid]);
    $prog = [];
    $q = $pdo->prepare('SELECT * FROM ap_progress WHERE plan_id = ?');
    $q->execute([$pid]);
    foreach ($q->fetchAll() as $r) {
        $prog[(int)$r['item_id']] = $r;
    }
    $ovr = [];
    $q = $pdo->prepare('SELECT * FROM ap_overrides WHERE plan_id = ?');
    $q->execute([$pid]);
    foreach ($q->fetchAll() as $r) {
        $ovr[(int)$r['item_id']] = $r;
    }

    // What this person can actually open: a shortcut to a tool they cannot open would only lead to a "no access" page.
    $mrow = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $mrow->execute([(int)$plan['member_id']]);
    $mrow = $mrow->fetch();
    $canOpen = $mrow ? allowed_tools($pdo, $mrow) : [];
    $ctx = null;   // the automatic counts, worked out once and only if some item uses them
    $out = [];
    foreach ($rows->fetchAll() as $i) {
        $extra = $i['plan_id'] !== null;
        if (!$extra && $i['s_active'] !== null && !(int)$i['s_active']) {
            continue;   // its whole section is turned off
        }
        $id = (int)$i['id'];
        $o = $ovr[$id] ?? [];
        $g = $prog[$id] ?? null;
        $from = (int)$i['week_from'];
        $to = (int)$i['week_to'];
        if ($extra) {
            $sf = $from;
            $st = $to;
        } else {
            $sf = ap_scale_from($from, $len);
            $st = ap_scale_to($to, $len);
        }
        $addedWeek = ap_week($start, (string)$i['created_on']);
        if (!$extra && (string)$i['created_on'] > $assigned && $st < $addedWeek) {
            $st = $addedWeek + 1;
            $sf = min($sf, $addedWeek);
        }
        if (isset($o['week_from']) && $o['week_from'] !== null) {
            $sf = (int)$o['week_from'];
        }
        if (isset($o['week_to']) && $o['week_to'] !== null) {
            $st = (int)$o['week_to'];
        }
        if ($st < $sf) {
            $st = $sf;
        }
        $kind = (string)$i['kind'];
        $target = isset($o['target']) && $o['target'] !== null ? (int)$o['target'] : ($i['target'] === null ? null : (int)$i['target']);
        $status = $g ? (string)$g['status'] : 'todo';
        $cnt = $g ? (int)$g['cnt'] : 0;
        // Counted by itself (Client Portal / Career Code), when there is a number to use. Otherwise the agent enters it.
        $auto = null;
        $autoKey = $extra ? '' : (string)($i['auto_key'] ?? '');
        if ($autoKey !== '' && isset(AP_AUTO[$autoKey])) {
            $ctx ??= ap_auto_context($pdo, $plan, $today);
            $av = $ctx['values'][$autoKey] ?? null;
            $auto = ['key' => $autoKey, 'label' => AP_AUTO[$autoKey]['label'], 'source' => AP_AUTO[$autoKey]['source'], 'value' => $av, 'extra' => $ctx['extra'][$autoKey] ?? '',
                'portal_off' => AP_AUTO[$autoKey]['source'] === 'Client Portal' && $av === null && !$ctx['portal']['found']];
            if ($kind === 'count' && $av !== null) {
                $cnt = $av;
                if ($status === 'todo' && $target && $cnt >= $target) {
                    $status = 'waiting';   // reached the target by itself: it goes to the approver
                }
            }
        }
        $skipped = !empty($o['skip']) && (int)$o['skip'] === 1;
        $window = $week === 0 ? 'upcoming' : ($week < $sf ? 'upcoming' : ($week > $st ? 'past' : 'now'));
        $resolved = in_array($status, ['waiting', 'done'], true);

        $overdue = false;
        $paceBehind = false;
        $expected = null;
        if (!$skipped && !$resolved && $week > 0) {
            if ($kind === 'count' && $target) {
                // expected pace counts COMPLETED weeks of the window only, so nobody is "behind" in the first week of a count
                $winWeeks = max(1, $st - $sf + 1);
                $elapsed = max(0, min($winWeeks, $week - $sf));
                $expected = (int)floor($target * $elapsed / $winWeeks);
                if ($cnt < $target && $week > $st) {
                    $overdue = true;
                } elseif ($expected >= 1 && $cnt < (int)floor($expected * 0.8)) {
                    $paceBehind = true;
                }
            } elseif ($week > $st) {
                $overdue = true;
            }
        }
        $out[] = [
            'id' => $id, 'extra' => $extra, 'section_id' => $i['section_id'] === null ? null : (int)$i['section_id'], 'section' => $extra ? 'Extra for this person' : (string)$i['section_name'],
            's_sort' => $extra ? 9999 : (int)$i['s_sort'], 'sort' => (int)$i['sort_order'],
            'title' => $i['title'], 'details' => (string)($i['details'] ?? ''), 'kind' => $kind, 'target' => $target, 'count' => $cnt,
            'status' => $status, 'note' => $g ? (string)($g['note'] ?? '') : '', 'submitted_at' => $g ? $g['submitted_at'] : null, 'approved_at' => $g ? $g['approved_at'] : null,
            'from' => $sf, 'to' => $st, 'window' => $window, 'overdue' => $overdue, 'pace_behind' => $paceBehind, 'expected' => $expected,
            'skipped' => $skipped, 'skip_note' => (string)($o['skip_note'] ?? ''),
            'base' => ['from' => $from, 'to' => $to, 'target' => $i['target'] === null ? null : (int)$i['target']],
            'has_override' => (bool)$o, 'auto' => $auto,
        ] + ap_item_tool($i['tool_key'], $canOpen);
    }
    usort($out, static fn (array $a, array $b): int => [$a['s_sort'], $a['sort'], $a['id']] <=> [$b['s_sort'], $b['sort'], $b['id']]);
    return $out;
}

/** The shortcut button of an item: shown only if this person can open that tool; otherwise the leader is told why it is missing. */
function ap_item_tool(?string $key, array $canOpen): array
{
    $t = ap_tool_url($key);
    if ($t === null) {
        return ['tool' => null, 'tool_denied' => null];
    }
    return in_array($key, $canOpen, true) ? ['tool' => $t, 'tool_denied' => null] : ['tool' => null, 'tool_denied' => $t['name']];
}

/** Counts, percent and the On track / Behind label (the label is for approvers only). */
function ap_summary(array $items, int $week): array
{
    $total = $done = $waiting = $follow = $bad = $considered = 0;
    foreach ($items as $it) {
        if ($it['skipped']) {
            continue;
        }
        $total++;
        $done += $it['status'] === 'done' ? 1 : 0;
        $waiting += $it['status'] === 'waiting' ? 1 : 0;
        $follow += $it['status'] === 'followup' ? 1 : 0;
        $isDue = $it['to'] < $week || ($it['kind'] === 'count' && $it['window'] === 'now');
        if ($isDue || $it['status'] === 'followup') {
            $considered++;
        }
        if ($it['overdue'] || $it['pace_behind']) {
            $bad++;
        }
    }
    if ($total === 0) {
        $label = 'none';
    } elseif ($done === $total) {
        $label = 'complete';
    } elseif ($week === 0) {
        $label = 'not_started';
    } elseif ($bad === 0) {
        $label = 'on_track';
    } elseif ($bad <= max(1, (int)ceil($considered * 0.15))) {
        $label = 'slightly_behind';
    } else {
        $label = 'behind';
    }
    return ['total' => $total, 'done' => $done, 'waiting' => $waiting, 'followup' => $follow, 'behind_items' => $bad,
        'percent' => $total ? (int)round($done * 100 / $total) : 0, 'label' => $label];
}

const AP_LABEL_TEXT = ['none' => 'No items', 'complete' => 'Complete', 'not_started' => 'Not started', 'on_track' => 'On track', 'slightly_behind' => 'A little behind', 'behind' => 'Behind'];

/* ---------- agent actions (own plan) ---------- */

function ap_item_for_plan(PDO $pdo, array $plan, int $itemId, string $today): array
{
    foreach (ap_plan_items($pdo, $plan, $today) as $it) {
        if ($it['id'] === $itemId) {
            return $it;
        }
    }
    throw new HttpError(404, 'That item is not on this plan.');
}

function ap_progress_set(PDO $pdo, int $planId, int $itemId, array $f): void
{
    $cur = $pdo->prepare('SELECT 1 FROM ap_progress WHERE plan_id = ? AND item_id = ?');
    $cur->execute([$planId, $itemId]);
    $f['updated_at'] = ap_now();
    if ($cur->fetchColumn()) {
        $cols = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($f)));
        $pdo->prepare("UPDATE ap_progress SET $cols WHERE plan_id = ? AND item_id = ?")->execute([...array_values($f), $planId, $itemId]);
    } else {
        $f += ['plan_id' => $planId, 'item_id' => $itemId];
        $pdo->prepare('INSERT INTO ap_progress (' . implode(', ', array_keys($f)) . ') VALUES (' . implode(', ', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
    }
}

/** The agent says "I did this". Waits for approval. */
function ap_tick(PDO $pdo, array $plan, int $itemId, string $today): void
{
    $it = ap_item_for_plan($pdo, $plan, $itemId, $today);
    if ($it['skipped']) {
        throw new HttpError(422, 'This item is not needed on your plan.');
    }
    if ($it['status'] === 'done') {
        throw new HttpError(409, 'That one is already approved.');
    }
    if ($it['kind'] === 'count' && $it['status'] === 'todo') {
        throw new HttpError(422, 'This one is a count: it goes for approval by itself when it reaches ' . ($it['target'] ?? 'the target') . '.');
    }
    ap_progress_set($pdo, (int)$plan['id'], $itemId, ['status' => 'waiting', 'submitted_at' => ap_now(), 'note' => null]);
}

/** Take it back while it is still waiting. */
function ap_untick(PDO $pdo, array $plan, int $itemId, string $today): void
{
    $it = ap_item_for_plan($pdo, $plan, $itemId, $today);
    if ($it['status'] !== 'waiting') {
        throw new HttpError(409, $it['status'] === 'done' ? 'That one is already approved, so only your leader can reopen it.' : 'Nothing to take back.');
    }
    ap_progress_set($pdo, (int)$plan['id'], $itemId, ['status' => 'todo', 'submitted_at' => null]);
}

function ap_count(PDO $pdo, array $plan, int $itemId, int $n, string $today): void
{
    $it = ap_item_for_plan($pdo, $plan, $itemId, $today);
    if ($it['kind'] !== 'count') {
        throw new HttpError(422, 'This item is a tick, not a count.');
    }
    if ($it['skipped']) {
        throw new HttpError(422, 'This item is not needed on your plan.');
    }
    if ($it['status'] === 'done') {
        throw new HttpError(409, 'That one is already approved.');
    }
    if ($it['auto'] !== null && $it['auto']['value'] !== null) {
        throw new HttpError(422, 'This one is counted automatically, so there is nothing to enter.');
    }
    $n = ap_int_range($n, 0, 100000, 'The number');
    $f = ['cnt' => $n];
    if ($it['target'] && $n >= $it['target']) {
        $f += ['status' => 'waiting', 'submitted_at' => ap_now(), 'note' => null];
    } elseif ($it['status'] === 'waiting') {
        $f += ['status' => 'todo', 'submitted_at' => null];
    }
    ap_progress_set($pdo, (int)$plan['id'], $itemId, $f);
}

/* ---------- approver actions ---------- */

function ap_approve(PDO $pdo, array $actor, array $plan, int $itemId, string $today): void
{
    $it = ap_item_for_plan($pdo, $plan, $itemId, $today);
    if ($it['skipped']) {
        throw new HttpError(422, 'That item is skipped for this person.');
    }
    ap_progress_set($pdo, (int)$plan['id'], $itemId, ['status' => 'done', 'note' => null, 'approved_by' => (int)$actor['id'], 'approved_at' => ap_now()]);
    audit($pdo, (int)$actor['id'], 'ap.approve', (int)$plan['member_id'], ['item' => $itemId]);
}

function ap_sendback(PDO $pdo, array $actor, array $plan, int $itemId, string $note, string $today): void
{
    ap_item_for_plan($pdo, $plan, $itemId, $today);
    $note = ap_text($note, 500, 'A short note for them');
    ap_progress_set($pdo, (int)$plan['id'], $itemId, ['status' => 'followup', 'note' => $note, 'approved_by' => null, 'approved_at' => null]);
    audit($pdo, (int)$actor['id'], 'ap.sendback', (int)$plan['member_id'], ['item' => $itemId]);
}

/** Undo an approval: back to waiting for approval is wrong, so the item simply returns to To do. */
function ap_reopen(PDO $pdo, array $actor, array $plan, int $itemId, string $today): void
{
    ap_item_for_plan($pdo, $plan, $itemId, $today);
    ap_progress_set($pdo, (int)$plan['id'], $itemId, ['status' => 'todo', 'note' => null, 'submitted_at' => null, 'approved_by' => null, 'approved_at' => null]);
    audit($pdo, (int)$actor['id'], 'ap.reopen', (int)$plan['member_id'], ['item' => $itemId]);
}

/* ---------- adjustments for one person (super admin) ---------- */

function ap_override(PDO $pdo, array $actor, array $plan, int $itemId, array $in, string $today): void
{
    $pid = (int)$plan['id'];
    $it = ap_item_for_plan($pdo, $plan, $itemId, $today);
    if ($it['extra']) {
        throw new HttpError(422, 'Edit an extra item directly instead.');
    }
    $cur = $pdo->prepare('SELECT * FROM ap_overrides WHERE plan_id = ? AND item_id = ?');
    $cur->execute([$pid, $itemId]);
    $row = $cur->fetch() ?: ['skip' => 0, 'skip_note' => null, 'week_from' => null, 'week_to' => null, 'target' => null];
    if (array_key_exists('skip', $in)) {
        $row['skip'] = $in['skip'] === true ? 1 : 0;
        $row['skip_note'] = $row['skip'] ? ap_text($in['skip_note'] ?? '', 200, 'Note', false) : null;
    }
    if (!empty($in['reset_weeks'])) {
        $row['week_from'] = $row['week_to'] = null;
    }
    foreach (['week_from', 'week_to'] as $k) {
        if (array_key_exists($k, $in) && $in[$k] !== null && $in[$k] !== '') {
            $row[$k] = ap_int_range($in[$k], 1, AP_MAX_WEEK, 'The week');
        }
    }
    if ($row['week_from'] !== null && $row['week_to'] !== null && (int)$row['week_to'] < (int)$row['week_from']) {
        throw new HttpError(422, 'The last week cannot be before the first week.');
    }
    if (array_key_exists('target', $in)) {
        if ($it['kind'] !== 'count') {
            throw new HttpError(422, 'Only a count has a target.');
        }
        $row['target'] = ($in['target'] === null || $in['target'] === '') ? null : ap_int_range($in['target'], 1, 100000, 'The target');
    }
    $empty = !(int)$row['skip'] && $row['week_from'] === null && $row['week_to'] === null && $row['target'] === null;
    $pdo->prepare('DELETE FROM ap_overrides WHERE plan_id = ? AND item_id = ?')->execute([$pid, $itemId]);
    if (!$empty) {
        $pdo->prepare('INSERT INTO ap_overrides (plan_id, item_id, skip, skip_note, week_from, week_to, target) VALUES (?,?,?,?,?,?,?)')
            ->execute([$pid, $itemId, (int)$row['skip'], $row['skip_note'], $row['week_from'], $row['week_to'], $row['target']]);
    }
    audit($pdo, (int)$actor['id'], 'ap.override', (int)$plan['member_id'], ['item' => $itemId] + array_intersect_key($in, array_flip(['skip', 'week_from', 'week_to', 'target'])));
}

function ap_extra_add(PDO $pdo, array $actor, array $plan, array $in, string $today): int
{
    $c = ap_clean_item($in + ['week_from' => 1, 'week_to' => 1], $pdo);
    $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM ap_items WHERE plan_id = ?');
    $max->execute([(int)$plan['id']]);
    $pdo->prepare('INSERT INTO ap_items (plan_id, section_id, title, details, week_from, week_to, kind, target, tool_key, sort_order, active, archived, created_on) VALUES (?,NULL,?,?,?,?,?,?,?,?,1,0,?)')
        ->execute([(int)$plan['id'], $c['title'], $c['details'], $c['week_from'], $c['week_to'], $c['kind'], $c['target'], $c['tool_key'], (int)$max->fetchColumn() + 10, $today]);
    $id = (int)$pdo->lastInsertId();
    audit($pdo, (int)$actor['id'], 'ap.extra.add', (int)$plan['member_id'], ['item' => $id, 'title' => $c['title']]);
    return $id;
}

function ap_extra_remove(PDO $pdo, array $actor, array $plan, int $itemId): void
{
    $s = $pdo->prepare('SELECT 1 FROM ap_items WHERE id = ? AND plan_id = ?');
    $s->execute([$itemId, (int)$plan['id']]);
    if (!$s->fetchColumn()) {
        throw new HttpError(404, 'Extra item not found.');
    }
    $pdo->prepare('DELETE FROM ap_progress WHERE item_id = ?')->execute([$itemId]);
    $pdo->prepare('DELETE FROM ap_items WHERE id = ?')->execute([$itemId]);
    audit($pdo, (int)$actor['id'], 'ap.extra.remove', (int)$plan['member_id'], ['item' => $itemId]);
}

/* ---------- views ---------- */

function ap_disc_info(?string $disc, ?string $source = null): ?array
{
    if ($disc === null || $disc === '') {
        return null;
    }
    return ['style' => $disc, 'hint' => AP_DISC_HINT[$disc[0]] ?? '', 'source' => $source];
}

/**
 * One plan as the screen needs it. $forApprover adds the labels, overdue flags and (super admin only) the private number;
 * an agent looking at their own plan gets gentle wording only and never sees the skipped items.
 */
function ap_plan_payload(PDO $pdo, array $map, array $plan, string $today, string $role, bool $own): array
{
    $m = $map[(int)$plan['member_id']] ?? ['name' => 'Unknown'];
    $week = ap_week((string)$plan['start_date'], $today);
    $items = ap_plan_items($pdo, $plan, $today);
    $sum = ap_summary($items, $week);
    $approver = $role === 'super' || ($role === 'approver' && !$own);
    $outItems = [];
    foreach ($items as $it) {
        if ($it['skipped'] && !$approver) {
            continue;
        }
        if (!$approver) {
            unset($it['overdue'], $it['pace_behind'], $it['expected'], $it['base'], $it['has_override'], $it['skip_note'], $it['tool_denied']);
        }
        $outItems[] = $it;
    }
    $p = [
        'id' => (int)$plan['id'], 'member' => ['id' => (int)$plan['member_id'], 'name' => $m['name']],
        'start_date' => $plan['start_date'], 'length_days' => (int)$plan['length_days'], 'week' => $week, 'weeks_total' => ap_weeks_total((int)$plan['length_days']),
        'starts_in_days' => $week === 0 ? max(0, -ap_days_between((string)$plan['start_date'], $today)) : 0,
        'closed' => (bool)$plan['closed'], 'disc' => ap_disc_info($plan['disc'] ?: ap_disc_from_career_code($pdo, (string)($m['email'] ?? ''), AP_DISC), $plan['disc'] ? null : 'Career Code'),
        'progress' => ['total' => $sum['total'], 'done' => $sum['done'], 'waiting' => $sum['waiting'], 'followup' => $sum['followup'], 'percent' => $sum['percent']],
        'items' => $outItems, 'own' => $own,
        'end_date' => gmdate('Y-m-d', strtotime((string)$plan['start_date'] . ' +' . ((int)$plan['length_days'] - 1) . ' days')),
        'buddy' => !empty($plan['buddy_id']) && isset($map[(int)$plan['buddy_id']]) ? ['id' => (int)$plan['buddy_id'], 'name' => $map[(int)$plan['buddy_id']]['name']] : null,
    ];
    if ($approver) {
        $p['last_nudged_at'] = $plan['last_nudged_at'] ?? null;
        $p['label'] = $sum['label'];
        $p['label_text'] = AP_LABEL_TEXT[$sum['label']];
        $p['behind_items'] = $sum['behind_items'];
    }
    if ($role === 'super') {
        $p['private_number'] = $plan['private_number'] === null ? null : (int)$plan['private_number'];
    }
    return $p;
}

function ap_overview(PDO $pdo, array $map, array $viewer, string $today): array
{
    $scope = ap_scope($map, $viewer);
    $rows = [];
    foreach ($pdo->query('SELECT * FROM ap_plans WHERE closed = 0')->fetchAll() as $plan) {
        $mid = (int)$plan['member_id'];
        if (!ap_in_scope($scope, $mid) || !isset($map[$mid])) {
            continue;
        }
        $week = ap_week((string)$plan['start_date'], $today);
        $sum = ap_summary(ap_plan_items($pdo, $plan, $today), $week);
        $rows[] = [
            'plan_id' => (int)$plan['id'], 'member_id' => $mid, 'name' => $map[$mid]['name'], 'disc' => $plan['disc'] ?: ap_disc_from_career_code($pdo, (string)$map[$mid]['email'], AP_DISC),
            'week' => $week, 'weeks_total' => ap_weeks_total((int)$plan['length_days']), 'start_date' => $plan['start_date'],
            'percent' => $sum['percent'], 'waiting' => $sum['waiting'], 'followup' => $sum['followup'], 'label' => $sum['label'], 'label_text' => AP_LABEL_TEXT[$sum['label']],
            'behind_items' => $sum['behind_items'],
        ];
    }
    $rank = ['behind' => 0, 'slightly_behind' => 1, 'on_track' => 2, 'not_started' => 3, 'none' => 4, 'complete' => 5];
    usort($rows, static fn (array $a, array $b): int => [$rank[$a['label']], strcasecmp($a['name'], $b['name'])] <=> [$rank[$b['label']], 0]);
    return $rows;
}

function ap_approvals(PDO $pdo, array $map, array $viewer, string $today): array
{
    $scope = ap_scope($map, $viewer);
    $out = [];
    foreach ($pdo->query('SELECT * FROM ap_plans WHERE closed = 0')->fetchAll() as $plan) {
        $mid = (int)$plan['member_id'];
        if (!ap_in_scope($scope, $mid) || !isset($map[$mid]) || $mid === (int)$viewer['id']) {
            continue;   // nobody approves their own items
        }
        foreach (ap_plan_items($pdo, $plan, $today) as $it) {
            if (!$it['skipped'] && $it['status'] === 'waiting') {
                $out[] = ['plan_id' => (int)$plan['id'], 'member' => $map[$mid]['name'], 'item_id' => $it['id'], 'title' => $it['title'], 'section' => $it['section'],
                    'kind' => $it['kind'], 'count' => $it['count'], 'target' => $it['target'], 'submitted_at' => $it['submitted_at']];
            }
        }
    }
    usort($out, static fn (array $a, array $b): int => strcmp((string)$a['submitted_at'], (string)$b['submitted_at']));
    return $out;
}

/* ---------- the one entry point used by the API (and the tests) ---------- */

/**
 * @param array $q   query string values (view, plan ...)
 * @param array $in  JSON body of a POST (action ...)
 * @return array     what to send back as JSON
 */
function ap_dispatch(PDO $pdo, array $me, string $method, array $q, array $in, string $today): array
{
    ap_ensure_template($pdo, $today);
    $map = load_member_map($pdo);
    $viewer = $map[(int)$me['id']] ?? $me;
    $role = ap_role($viewer);
    $scope = ap_scope($map, $viewer);
    $mine = ap_plan_of_member($pdo, (int)$viewer['id']);
    $mine = $mine && !(int)$mine['closed'] ? $mine : null;

    $needSuper = static function () use ($role): void {
        if ($role !== 'super') {
            throw new HttpError(403, 'Only the super admin can do this.');
        }
    };
    /** a plan the viewer may approve for (never their own) */
    $theirPlan = static function (int $planId) use ($pdo, $role, $scope, $viewer): array {
        $plan = ap_plan_row($pdo, $planId);
        if ($role === 'agent' || (int)$plan['member_id'] === (int)$viewer['id'] || !ap_in_scope($scope, (int)$plan['member_id'])) {
            throw new HttpError(403, 'You cannot do that for this person.');
        }
        return $plan;
    };
    $payload = static fn (array $plan, bool $own) => ap_plan_payload($pdo, load_member_map($pdo), $plan, $today, $role, $own);
    $refresh = static fn (array $plan): array => ['plan' => $payload(ap_plan_row($pdo, (int)$plan['id']), (int)$plan['member_id'] === (int)$viewer['id'])];

    if ($method === 'GET') {
        switch ((string)($q['view'] ?? 'init')) {
            case 'init':
                return [
                    'role' => $role, 'me' => ['id' => (int)$viewer['id'], 'name' => $viewer['name']], 'today' => $today, 'has_plan' => $mine !== null,
                    'waiting' => $role === 'agent' ? 0 : count(ap_approvals($pdo, $map, $viewer, $today)),
                    'assignable' => $role === 'super' ? ap_assignable($pdo, $map) : [],
                    'disc_options' => AP_DISC, 'tools' => ap_tool_options(), 'default_days' => AP_BASE_DAYS,
                    'plan_tools' => $role === 'super' ? ap_plan_tools($pdo) : [],
                    'try_members' => $role === 'super' ? ap_try_members($map) : [],
                    'buddy_count' => count(ap_buddy_list($pdo, $map, (int)$viewer['id'], $today)),
                ];
            case 'buddy':
                return ['people' => ap_buddy_list($pdo, $map, (int)$viewer['id'], $today)];
            case 'buddy_plan':
                return ['plan' => ap_buddy_view($pdo, $map, (int)$viewer['id'], (int)($q['plan'] ?? 0), $today)];
            case 'nudge':
                return ['nudge' => ap_nudge_draft($pdo, $map, $theirPlan((int)($q['plan'] ?? 0)), $today)];
            case 'me':
                return ['plan' => $mine ? $payload($mine, true) : null];
            case 'overview':
                if ($role === 'agent') {
                    throw new HttpError(403, 'Not available.');
                }
                return ['plans' => ap_overview($pdo, $map, $viewer, $today), 'assignable' => $role === 'super' ? ap_assignable($pdo, $map) : [], 'plan_tools' => $role === 'super' ? ap_plan_tools($pdo) : []];
            case 'approvals':
                if ($role === 'agent') {
                    throw new HttpError(403, 'Not available.');
                }
                return ['approvals' => ap_approvals($pdo, $map, $viewer, $today)];
            case 'person':
                return ['plan' => $payload($theirPlan((int)($q['plan'] ?? 0)), false)];
            case 'template':
                $needSuper();
                return ['sections' => ap_template($pdo), 'tools' => ap_tool_options(), 'autos' => ap_auto_options()];
            case 'autocheck':
                $needSuper();
                return ['check' => ap_auto_diagnose($pdo, ap_plan_row($pdo, (int)($q['plan'] ?? 0)), $today)];
            case 'autotry':   // try the automatic counts for anyone, with no plan needed; nothing is saved
                $needSuper();
                $mid = (int)($q['member'] ?? 0);
                if (!isset($map[$mid]) || !$map[$mid]['is_active']) {
                    throw new HttpError(404, 'Person not found.');
                }
                $since = trim((string)($q['since'] ?? ''));
                $since = $since === '' ? date('Y-m-d', strtotime($today . ' -30 days')) : ap_date($since);
                if ($since > $today) {
                    throw new HttpError(422, 'The start date cannot be in the future.');
                }
                return ['check' => ap_auto_diagnose($pdo, ['member_id' => $mid, 'start_date' => $since], $today)];
        }
        throw new HttpError(400, 'Unknown view.');
    }

    $action = (string)($in['action'] ?? '');
    $planId = (int)($in['plan'] ?? 0);
    $item = (int)($in['item'] ?? 0);
    switch ($action) {
        /* the agent, on their own plan */
        case 'tick':
        case 'untick':
        case 'count':
            if ($mine === null) {
                throw new HttpError(403, 'You do not have an Action Plan yet.');
            }
            if ($action === 'tick') {
                ap_tick($pdo, $mine, $item, $today);
            } elseif ($action === 'untick') {
                ap_untick($pdo, $mine, $item, $today);
            } else {
                ap_count($pdo, $mine, $item, (int)($in['n'] ?? -1), $today);
            }
            return $refresh($mine);

        /* approvers */
        case 'approve':
            ap_approve($pdo, $viewer, $theirPlan($planId), $item, $today);
            return $refresh(ap_plan_row($pdo, $planId));
        case 'sendback':
            ap_sendback($pdo, $viewer, $theirPlan($planId), $item, (string)($in['note'] ?? ''), $today);
            return $refresh(ap_plan_row($pdo, $planId));
        case 'reopen':
            ap_reopen($pdo, $viewer, $theirPlan($planId), $item, $today);
            return $refresh(ap_plan_row($pdo, $planId));

        case 'nudge_done':
            return ['last_nudged_at' => ap_nudge_done($pdo, $viewer, $theirPlan($planId))];

        /* super admin: plans */
        case 'assign':
            $needSuper();
            $id = ap_assign($pdo, $viewer, (int)($in['member_id'] ?? 0), (string)($in['start_date'] ?? ''), (int)($in['length_days'] ?? AP_BASE_DAYS), is_array($in['tools'] ?? null) ? $in['tools'] : []);
            return ['plan_id' => $id, 'assignable' => ap_assignable($pdo, load_member_map($pdo))];
        case 'plan_update':
            $needSuper();
            ap_plan_update($pdo, $viewer, $planId, $in);
            return $refresh(ap_plan_row($pdo, $planId));
        case 'override':
            $needSuper();
            ap_override($pdo, $viewer, ap_plan_row($pdo, $planId), $item, $in, $today);
            return $refresh(ap_plan_row($pdo, $planId));
        case 'extra_add':
            $needSuper();
            ap_extra_add($pdo, $viewer, ap_plan_row($pdo, $planId), $in, $today);
            return $refresh(ap_plan_row($pdo, $planId));
        case 'extra_remove':
            $needSuper();
            ap_extra_remove($pdo, $viewer, ap_plan_row($pdo, $planId), $item);
            return $refresh(ap_plan_row($pdo, $planId));

        /* super admin: the template */
        case 'section_add':
        case 'section_update':
        case 'section_delete':
        case 'section_move':
        case 'item_add':
        case 'item_update':
        case 'item_remove':
        case 'item_move':
            $needSuper();
            $res = [];
            switch ($action) {
                case 'section_add':
                    ap_section_add($pdo, $viewer, (string)($in['name'] ?? ''));
                    break;
                case 'section_update':
                    ap_section_update($pdo, $viewer, (int)($in['section'] ?? 0), $in);
                    break;
                case 'section_delete':
                    ap_section_delete($pdo, $viewer, (int)($in['section'] ?? 0));
                    break;
                case 'section_move':
                    ap_section_move($pdo, (int)($in['section'] ?? 0), (int)($in['dir'] ?? 0));
                    break;
                case 'item_add':
                    ap_item_add($pdo, $viewer, $in);
                    break;
                case 'item_update':
                    ap_item_update($pdo, $viewer, $item, $in);
                    break;
                case 'item_remove':
                    $res['how'] = ap_item_remove($pdo, $viewer, $item);
                    break;
                case 'item_move':
                    ap_item_move($pdo, $item, (int)($in['dir'] ?? 0));
                    break;
            }
            return $res + ['sections' => ap_template($pdo), 'tools' => ap_tool_options(), 'autos' => ap_auto_options()];
    }
    throw new HttpError(400, 'Unknown action.');
}
