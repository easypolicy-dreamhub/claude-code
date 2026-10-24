<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/tree.php';

const RANKS = ['agent', 'unit_manager', 'group_manager'];

function audit(PDO $pdo, ?int $actorId, string $action, ?int $subjectId, array $detail = []): void
{
    $pdo->prepare('INSERT INTO audit_log (actor_id, action, subject_id, detail) VALUES (?,?,?,?)')
        ->execute([$actorId, $action, $subjectId, json_encode($detail, JSON_UNESCAPED_UNICODE)]);
}

function public_member(array $m): array
{
    return [
        'id' => (int)$m['id'], 'email' => $m['email'], 'name' => $m['name'],
        'rank' => $m['rank_label'], 'upline_id' => $m['upline_id'] === null ? null : (int)$m['upline_id'],
        'is_admin' => (bool)$m['is_admin'], 'is_team_admin' => (bool)($m['is_team_admin'] ?? 0), 'referral_code' => $m['referral_code'],
        'is_active' => (bool)$m['is_active'],
    ];
}

function normalise_referral(string $code): string
{
    $code = strtolower(trim($code));
    if (preg_match('/^[a-z0-9][a-z0-9-]{1,31}$/', $code) !== 1) {
        throw new HttpError(422, 'Referral code must be 2-32 letters, numbers or dashes.');
    }
    return $code;
}

function suggest_referral(PDO $pdo, string $name): string
{
    $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name)) ?? '');
    $base = trim(substr($base, 0, 24), '-');
    if (strlen($base) < 2) {
        $base = 'agent';
    }
    $stmt = $pdo->prepare('SELECT 1 FROM members WHERE referral_code = ?');
    for ($i = 0; $i < 100; $i++) {
        $cand = $i === 0 ? $base : "$base-" . ($i + 1);
        $stmt->execute([$cand]);
        if (!$stmt->fetchColumn()) {
            return $cand;
        }
    }
    throw new HttpError(500, 'Could not generate a referral code.');
}

/** @param array $in email, name, rank?, upline_id?, is_admin?, referral_code? */
function create_member(PDO $pdo, ?int $actorId, array $in): array
{
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $name  = trim((string)($in['name'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        throw new HttpError(422, 'A valid email is required.');
    }
    if ($name === '' || mb_strlen($name) > 120) {
        throw new HttpError(422, 'Name is required (max 120 characters).');
    }
    $rank = (string)($in['rank'] ?? 'agent');
    if (!in_array($rank, RANKS, true)) {
        throw new HttpError(422, 'Unknown rank.');
    }
    $map = load_member_map($pdo);
    $upline = isset($in['upline_id']) && $in['upline_id'] !== '' ? (int)$in['upline_id'] : null;
    if ($upline !== null && !isset($map[$upline])) {
        throw new HttpError(422, 'Upline member not found.');
    }
    foreach ($map as $m) {
        if ($m['email'] === $email) {
            throw new HttpError(409, 'A member with this email already exists.');
        }
    }
    $referral = isset($in['referral_code']) && $in['referral_code'] !== ''
        ? normalise_referral((string)$in['referral_code'])
        : suggest_referral($pdo, $name);

    try {
        $pdo->prepare('INSERT INTO members (email,name,rank_label,upline_id,is_admin,referral_code) VALUES (?,?,?,?,?,?)')
            ->execute([$email, $name, $rank, $upline, empty($in['is_admin']) ? 0 : 1, $referral]);
    } catch (PDOException $e) {
        throw new HttpError(409, 'Email or referral code is already taken.');
    }
    $id = (int)$pdo->lastInsertId();
    audit($pdo, $actorId, 'member.create', $id, ['email' => $email, 'upline_id' => $upline]);
    return public_member(load_member_map($pdo)[$id]);
}

/**
 * Admin edit. Supported keys: name, rank, upline_id, is_admin, is_active, referral_code,
 * move_downline_to (re-parents direct reports first - use it when someone leaves, Fix 11).
 */
function update_member(PDO $pdo, int $actorId, int $id, array $in): array
{
    $map = load_member_map($pdo);
    if (!isset($map[$id])) {
        throw new HttpError(404, 'Member not found.');
    }
    $cur = $map[$id];
    $set = [];
    $args = [];

    if (array_key_exists('name', $in)) {
        $name = trim((string)$in['name']);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new HttpError(422, 'Name is required (max 120 characters).');
        }
        $set['name'] = $name;
    }
    if (array_key_exists('email', $in)) {
        $email = strtolower(trim((string)$in['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new HttpError(422, 'A valid email is required.');
        }
        foreach ($map as $other) {
            if ($other['id'] !== $id && $other['email'] === $email) {
                throw new HttpError(409, 'A member with this email already exists.');
            }
        }
        $set['email'] = $email;
    }
    if (array_key_exists('rank', $in)) {
        if (!in_array($in['rank'], RANKS, true)) {
            throw new HttpError(422, 'Unknown rank.');
        }
        $set['rank_label'] = $in['rank'];
    }
    if (array_key_exists('referral_code', $in)) {
        $set['referral_code'] = normalise_referral((string)$in['referral_code']);
    }
    if (array_key_exists('upline_id', $in)) {
        $up = $in['upline_id'] === null || $in['upline_id'] === '' ? null : (int)$in['upline_id'];
        if ($up !== null && !isset($map[$up])) {
            throw new HttpError(422, 'Upline member not found.');
        }
        if (would_create_cycle($map, $id, $up)) {
            throw new HttpError(422, 'That would put the member under their own downline.');
        }
        $set['upline_id'] = $up;
    }
    $admins = count(array_filter($map, static fn ($m) => $m['is_admin'] && $m['is_active']));
    if (array_key_exists('is_admin', $in)) {
        $want = empty($in['is_admin']) ? 0 : 1;
        if (!$want && $cur['is_admin'] && $cur['is_active'] && $admins <= 1) {
            throw new HttpError(422, 'Cannot remove the last active admin.');
        }
        $set['is_admin'] = $want;
    }
    if (array_key_exists('is_active', $in)) {
        $want = empty($in['is_active']) ? 0 : 1;
        if (!$want) {
            if ($id === $actorId) {
                throw new HttpError(422, 'You cannot deactivate yourself.');
            }
            if ($cur['is_admin'] && $cur['is_active'] && $admins <= 1) {
                throw new HttpError(422, 'Cannot deactivate the last active admin.');
            }
        }
        $set['is_active'] = $want;
        $set['deactivated_at'] = $want ? null : date('Y-m-d H:i:s');
    }

    $move = null;
    if (array_key_exists('move_downline_to', $in) && $in['move_downline_to'] !== null && $in['move_downline_to'] !== '') {
        $move = (int)$in['move_downline_to'];
        if (!isset($map[$move]) || $move === $id || in_array($move, downline_ids($map, $id), true)) {
            throw new HttpError(422, 'Cannot move the downline there.');
        }
    }

    $pdo->beginTransaction();
    try {
        if ($move !== null) {
            $pdo->prepare('UPDATE members SET upline_id = ? WHERE upline_id = ?')->execute([$move, $id]);
        }
        if ($set) {
            $cols = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($set)));
            $pdo->prepare("UPDATE members SET $cols WHERE id = ?")->execute([...array_values($set), $id]);
        }
        audit($pdo, $actorId, 'member.update', $id, ['changes' => $set, 'move_downline_to' => $move]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw new HttpError(409, 'Email or referral code is already taken.');
    }
    return public_member(load_member_map($pdo)[$id]);
}
