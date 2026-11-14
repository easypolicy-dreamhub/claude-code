<?php
declare(strict_types=1);

require_once __DIR__ . '/members.php';
require_once __DIR__ . '/portal.php';

/**
 * The one list of toolkit tools and who may use them.
 *
 *  key     short name used in the database and in apps.js
 *  group   heading on the toolkit page (same order as the page)
 *  dir     folder under /members/ that the gate protects ('' = none: external link)
 *  locked  true = super admin only, can never be ticked for anyone else
 *  bridge  true = the on/off lives in the Client Portal's own record (private bridge), not in tool_access
 *  basic   true = ticked by default for a new Team member
 *
 * Super admin automatically has every tool. Everyone else has exactly the tools ticked for them.
 */
const TOOL_GROUPS = ['Servicing', 'Sales', 'Recruitment', 'Leader', 'Admin', 'Coaching', 'Site Admin'];

function tool_catalog(): array
{
    $t = static fn (string $key, string $name, string $group, string $dir, array $x = []): array =>
        $x + ['key' => $key, 'name' => $name, 'group' => $group, 'dir' => $dir, 'locked' => false, 'bridge' => false, 'basic' => false, 'link_only' => false];
    return [
        $t('panel-finder', 'GE Panel Finder', 'Servicing', 'panel-finder', ['basic' => true]),
        $t('great-journey-finder', 'Great Journey Finder', 'Servicing', 'great-journey-finder', ['basic' => true]),
        $t('client-portal', 'Client Portal', 'Servicing', '', ['bridge' => true]),
        $t('protection-needs', 'Protection Needs Calculator', 'Sales', 'protection-needs', ['basic' => true]),
        $t('sum-assured', 'Sum Assured Calculator', 'Sales', 'sum-assured', ['basic' => true]),
        $t('legacy-planner', 'Legacy Planner', 'Sales', 'legacy-planner', ['basic' => true]),
        $t('ci-poster-generator', 'CI Poster Generator', 'Sales', 'ci-poster-generator', ['basic' => true]),
        $t('policy-summary', 'Policy Summary', 'Sales', 'policy-summary', ['basic' => true]),
        $t('career-code', 'Career Code', 'Recruitment', 'career-code', ['basic' => true]),
        $t('career-code-candidates', 'Career Code Candidates', 'Recruitment', 'career-code/dashboard', ['basic' => true]),
        $t('event-links', 'Event Invite Links', 'Recruitment', 'event-links', ['basic' => true]),
        $t('event-invitees', 'My Event Invitees', 'Recruitment', 'event-invitees', ['basic' => true]),
        $t('million-income', 'Million Income Calculator', 'Recruitment', 'million-income', ['basic' => true]),
        $t('activity', 'Team Activity', 'Leader', 'activity'),
        $t('team', 'Team', 'Leader', 'team'),
        $t('agency-report', 'Agency Report Generator', 'Leader', 'agency-report'),
        $t('monthly-report', 'Monthly Report Messages', 'Leader', 'monthly-report'),
        $t('nominee-form', 'Nominee Form Filler', 'Admin', 'nominee-form'),
        // Opens in Gemini, outside this website: the card can be hidden, but the link itself cannot be blocked here.
        $t('hnw-coach', 'HNW Coach', 'Coaching', '', ['link_only' => true]),
        $t('testimonials', 'Testimonials', 'Site Admin', 'testimonials', ['locked' => true]),
        $t('events', 'Events', 'Site Admin', 'events', ['locked' => true]),
        $t('site-settings', 'Site Settings', 'Site Admin', 'site-settings', ['locked' => true]),
        $t('access', 'Members & Access', 'Site Admin', 'access', ['locked' => true]),
    ];
}

function tool_by_key(string $key): ?array
{
    foreach (tool_catalog() as $t) {
        if ($t['key'] === $key) {
            return $t;
        }
    }
    return null;
}

/** Tools whose tick is stored in tool_access (everything except the locked Site Admin tools and the Client Portal). */
function grantable_keys(): array
{
    return array_values(array_map(static fn (array $t): string => $t['key'], array_filter(tool_catalog(), static fn (array $t): bool => !$t['locked'] && !$t['bridge'])));
}

/** Every tool that can be switched for someone: the stored ones plus the Client Portal. */
function switchable_keys(): array
{
    return array_values(array_map(static fn (array $t): string => $t['key'], array_filter(tool_catalog(), static fn (array $t): bool => !$t['locked'])));
}

function bridge_keys(): array
{
    return array_values(array_map(static fn (array $t): string => $t['key'], array_filter(tool_catalog(), static fn (array $t): bool => $t['bridge'])));
}

function basic_keys(): array
{
    return array_values(array_map(static fn (array $t): string => $t['key'], array_filter(tool_catalog(), static fn (array $t): bool => $t['basic'])));
}

const MEMBER_TYPES = ['team' => 'Team member', 'admin' => 'Admin', 'tester' => 'Tester'];

function member_type(array $m): string
{
    $t = (string)($m['member_type'] ?? 'team');
    return isset(MEMBER_TYPES[$t]) ? $t : 'team';
}

function tool_is_super(array $m): bool
{
    return !empty($m['is_admin']) && !empty($m['is_active']);
}

/** @return string[] tool keys ticked for this member (the super admin's list is "everything"). */
function ticked_tools(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT tool_key FROM tool_access WHERE member_id = ?');
    $st->execute([$memberId]);
    return array_values(array_intersect(grantable_keys(), $st->fetchAll(PDO::FETCH_COLUMN)));
}

/** Ticked tools plus the Client Portal when it is switched on for them. */
function member_tool_keys(PDO $pdo, array $member): array
{
    $keys = ticked_tools($pdo, (int)$member['id']);
    if (portal_enabled((string)$member['email'])) {
        $keys[] = 'client-portal';
    }
    return $keys;
}

/** Every tool key this member may open (what the toolkit page shows). */
function allowed_tools(PDO $pdo, array $member): array
{
    if (tool_is_super($member)) {
        return array_map(static fn (array $t): string => $t['key'], tool_catalog());
    }
    return array_values(array_unique(member_tool_keys($pdo, $member)));
}

function can_use_tool(PDO $pdo, array $member, string $key): bool
{
    $t = tool_by_key($key);
    if ($t === null) {
        return false;
    }
    if (tool_is_super($member)) {
        return true;
    }
    if (empty($member['is_active'])) {
        return false;   // a removed person gets nothing, whatever is ticked
    }
    if ($t['locked']) {
        return false;
    }
    return in_array($key, allowed_tools($pdo, $member), true);
}

/** Replace the ticks for one member. Unknown or locked keys are ignored. */
function set_ticked_tools(PDO $pdo, int $memberId, array $keys): array
{
    $keys = array_values(array_intersect(grantable_keys(), array_map('strval', $keys)));
    $own = $pdo->inTransaction() === false;
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare("DELETE FROM tool_access WHERE member_id = ? AND tool_key <> 'portal-was-on'")->execute([$memberId]);   // keep the pause bookmark
        $ins = $pdo->prepare('INSERT INTO tool_access (member_id, tool_key) VALUES (?, ?)');
        foreach ($keys as $k) {
            $ins->execute([$memberId, $k]);
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $keys;
}

/** Map a /members/... page path to the tool that guards it, or null when the path is not a gated tool. */
function tool_for_path(string $rel): ?array
{
    $rel = trim($rel, '/');
    $best = null;
    foreach (tool_catalog() as $t) {
        if ($t['dir'] === '') {
            continue;
        }
        if ($rel === $t['dir'] || str_starts_with($rel . '/', $t['dir'] . '/')) {
            if ($best === null || strlen($t['dir']) > strlen($best['dir'])) {
                $best = $t;   // the longest folder wins: career-code/dashboard beats career-code
            }
        }
    }
    return $best;
}
