<?php
// The ONE place where the AI Coach touches the rest of the members site.
//
// The coach reuses the site's existing member login (boot_member_request) and
// admin gate (require_admin). Those functions live in the main toolkit code,
// which was not available when this was written, so the three marked spots
// below are the only things to check when you first deploy.

// 1) Load the site's existing config.php (holds ANTHROPIC_API_KEY etc.) and the
//    file that defines boot_member_request() / require_admin().
//    ADJUST the paths to match where they live on your cPanel.
foreach ([
    dirname(__DIR__) . '/config.php',
    // dirname(__DIR__) . '/lib/members.php',
] as $file) {
    if (is_file($file)) {
        require_once $file;
    }
}

/** Fails closed: if sign-in is not wired up, nobody gets in. */
function coach_boot(): array
{
    if (!function_exists('boot_member_request')) {
        coach_bridge_fail(503, 'Sign-in is not connected yet. Open ai-coach/site-bridge.php and point it at your boot_member_request().');
    }
    // 2) ADJUST if boot_member_request() returns something other than an array
    //    describing the signed-in member (id + display name).
    $m = boot_member_request();
    $m = is_array($m) ? $m : (is_object($m) ? (array) $m : []);
    $id = $m['id'] ?? $m['member_id'] ?? $m['email'] ?? null;
    if ($id === null || $id === '') {
        coach_bridge_fail(401, 'Please sign in.');
    }
    return [
        'id' => (string) $id,
        'name' => (string) ($m['name'] ?? $m['full_name'] ?? $m['display_name'] ?? $id),
        'raw' => $m,
    ];
}

/**
 * True only for the SUPER admin (the team leader). Team admins must NOT pass.
 * 3) ADJUST to however your site marks the super admin.
 */
function coach_is_super_admin(array $member): bool
{
    $m = $member['raw'];
    return !empty($m['is_super_admin']) || ($m['role'] ?? '') === 'super_admin';
}

/**
 * Server-side gate for every upload / save / delete / restore / usage action.
 * Runs the site's own require_admin() first, then insists on super admin as well.
 */
function coach_require_super_admin(array $member): void
{
    if (function_exists('require_admin')) {
        require_admin();
    }
    if (!coach_is_super_admin($member)) {
        coach_bridge_fail(403, 'Only the super admin can do this.');
    }
}

/**
 * Optional: list every member as [['id'=>..., 'name'=>...], ...]. When provided,
 * the Usage tab also shows agents who never used the coach ("needs a nudge").
 */
function coach_list_members(): array
{
    return [];
}

function coach_bridge_fail(int $code, string $message)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $message]);
    exit;
}
