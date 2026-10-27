<?php
// The ONE place where Client Details touches the rest of the members site.
// Same pattern as ai-coach/site-bridge.php: reuses the site's existing member
// login so this app never has its own password or its own user list.

foreach ([
    dirname(__DIR__) . '/config.php',
    // dirname(__DIR__) . '/lib/members.php',
] as $file) {
    if (is_file($file)) {
        require_once $file;
    }
}

/**
 * Fails closed: if sign-in is not wired up, nobody gets in and nobody's
 * data is ever returned to a stranger.
 *
 * Returns ['id' => ..., 'name' => ..., 'raw' => ...]. 'id' MUST be something
 * stable and unique per agent (their site login email is what we assume
 * below) — it is the key every agent's uploaded client data is filed under.
 */
function cd_boot(): array
{
    if (!function_exists('boot_member_request')) {
        cd_bridge_fail(503, 'Sign-in is not connected yet. Open client-dashboard/site-bridge.php and point it at your boot_member_request().');
    }
    $m = boot_member_request();
    $m = is_array($m) ? $m : (is_object($m) ? (array) $m : []);
    $id = $m['id'] ?? $m['member_id'] ?? $m['email'] ?? null;
    if ($id === null || $id === '') {
        cd_bridge_fail(401, 'Please sign in.');
    }
    return [
        'id' => strtolower((string) $id),
        'name' => (string) ($m['name'] ?? $m['full_name'] ?? $m['display_name'] ?? $id),
        'raw' => $m,
    ];
}

function cd_bridge_fail(int $code, string $message)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $message]);
    exit;
}
