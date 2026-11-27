<?php
// The ONE place where the HNW Coach touches the rest of the members site.
//
// It uses the members system in the private/ folder, the same way photos.php and
// drafts.php do in agency-report: boot_member_request() checks sign-in, the request
// itself, and that the member is active and not paused; can_use_tool() checks the
// "HNW Coach" tool switch in Members & Access. Nobody is listed in this file.

const HNWCOACH_TOOL_KEY = 'hnw-coach';

$GLOBALS['coach_ctx'] = null;

/** Fails closed: anything missing or broken ends the request with a clear message. */
function coach_boot(): array
{
    if ($GLOBALS['coach_ctx'] !== null) {
        return $GLOBALS['coach_ctx'];
    }
    // public_html/members/hnw-coach -> ../../../private (HNWCOACH_PRIVATE_DIR is only ever set by the local test stand-in)
    $private = defined('HNWCOACH_PRIVATE_DIR') ? HNWCOACH_PRIVATE_DIR : dirname(__DIR__, 3) . '/private';
    if (!is_file($private . '/bootstrap.php') || !is_file($private . '/lib/tools.php')) {
        coach_bridge_fail(500, 'members_system_missing');
    }
    require_once $private . '/bootstrap.php';
    require_once $private . '/lib/tools.php';

    // Ends the request itself (401/403) if not a signed-in, active, unpaused member,
    // or if a write does not come from this site.
    [$config, $pdo, $me] = boot_member_request();
    try {
        $allowed = can_use_tool($pdo, $me, HNWCOACH_TOOL_KEY);
    } catch (Throwable $e) {
        error_log('hnw-coach access check: ' . $e->getMessage());
        coach_bridge_fail(500, 'The members system could not check your access. Please tell your admin.');
    }
    if (!$allowed) {
        coach_bridge_fail(403, 'Your account does not have the HNW Coach tool switched on. Ask your admin to switch it on in Members & Access.');
    }
    $m = is_array($me) ? $me : (is_object($me) ? (array) $me : []);
    $email = strtolower((string) ($m['email'] ?? ''));
    if ($email === '') {
        coach_bridge_fail(401, 'Please sign in.');
    }
    $name = trim((string) ($m['name'] ?? $m['full_name'] ?? $m['display_name'] ?? ''));
    return $GLOBALS['coach_ctx'] = [
        'id' => $email, // memory is keyed by the signed-in email, never by anything the browser sends
        'name' => $name !== '' ? $name : (string) strstr($email, '@', true),
        'raw' => $m,
        'config' => $config,
    ];
}

/**
 * True only for the SUPER admin (the team leader). Team admins and ordinary members must NOT pass.
 * ADJUST: this reads the usual field names from the member record that boot_member_request() returns.
 * If the Settings and Usage tabs do not appear for the super admin, open
 * /members/hnw-coach/api.php?action=whoami while signed in and send me what it shows.
 */
function coach_is_super_admin(array $member): bool
{
    $m = $member['raw'];
    return !empty($m['is_super_admin']) || !empty($m['super_admin'])
        || in_array(strtolower((string) ($m['role'] ?? '')), ['super_admin', 'superadmin', 'super-admin'], true);
}

/** Server-side gate for every upload / save / delete / restore / usage action. */
function coach_require_super_admin(array $member): void
{
    if (!coach_is_super_admin($member)) {
        coach_bridge_fail(403, 'Only the super admin can do this.');
    }
}

/** Optional: list every member as [['id'=>..., 'name'=>...], ...] so the Usage tab can show people who never used the coach. */
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
