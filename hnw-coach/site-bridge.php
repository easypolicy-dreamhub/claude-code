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
    $m = is_array($me) ? $me : [];
    $id = (string) ($m['id'] ?? '');
    if ($id === '') {
        coach_bridge_fail(401, 'Please sign in.');
    }
    $email = strtolower((string) ($m['email'] ?? ''));
    $name = trim((string) ($m['name'] ?? ''));

    // Settings (the Gemini key, the leader's WhatsApp number): a NEW file next to config.php, so config.php itself is never edited.
    $cfg = [];
    $extra = $private . '/hnw-coach-config.php';
    if (is_file($extra)) {
        $x = require $extra;
        if (is_array($x)) {
            $cfg = $x;
        }
    }
    if (is_array($config['hnw_coach'] ?? null)) {
        $cfg += $config['hnw_coach'];
    }
    $GLOBALS['coach_cfg'] = $cfg;

    return $GLOBALS['coach_ctx'] = [
        'id' => $id, // memory is keyed by the signed-in member's id, never by anything the browser sends
        'name' => $name !== '' ? $name : (string) strstr($email, '@', true),
        'raw' => $m,
    ];
}

/**
 * True only for the SUPER admin: the same rule as tool_is_super() in private/lib/tools.php
 * (is_admin and active). Team admins (is_team_admin) and ordinary members do NOT pass.
 */
function coach_is_super_admin(array $member): bool
{
    $m = $member['raw'];
    return !empty($m['is_admin']) && !empty($m['is_active']);
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
    header('Cache-Control: no-store');
    if (defined('HNWCOACH_PAGE')) { // the page itself (index.php): a readable message instead of raw JSON
        header('Content-Type: text/html; charset=utf-8');
        $m = htmlspecialchars($message === 'members_system_missing' ? 'The members system could not be found. Please tell your admin.' : $message, ENT_QUOTES);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>HNW Coach</title>'
            . '<link rel="stylesheet" href="/members/e3-toolkit.css"></head><body>'
            . '<div class="e3-bar"><a href="/members/">E3 Toolkit</a><span class="e3-brand">Dream<span>Hub</span> E3</span></div>'
            . '<header class="topbar"><h1>HNW Coach<small>' . $m . '</small></h1></header></body></html>';
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message]);
    exit;
}
