<?php
declare(strict_types=1);

/**
 * Members & Access (super admin only).
 *   GET ?diagnose=1                      -> step-by-step Cloudflare check (for the "Run Cloudflare check" button)
 *   GET                                  -> everyone on the list, the tool catalog, and whether Cloudflare is connected
 *   POST {action:'add', email, name, type, tools?|copy_from?}      add on Cloudflare + this site (also "set up" a new email)
 *   POST {action:'update', id, name?, type?}
 *   POST {action:'tool', id, tool, on}   switch one tool for one person
 *   POST {action:'tools', id, tools[]}   replace one person's tools
 *   POST {action:'suspend', id, on}      pause / resume someone completely (blocked everywhere, nothing deleted)
 *   POST {action:'remove', email}        Admin / Tester: off the site and Cloudflare, signed out
 *   POST {action:'cf_add', email}        put someone already on this site onto Cloudflare
 */
require_once __DIR__ . '/../../../private/bootstrap.php';
require_once __DIR__ . '/../../../private/lib/access.php';

[$config, $pdo, $me] = boot_member_request();

// Step log for troubleshooting (private/access_trace.log) and a longer time limit for the Cloudflare calls.
define('ACCESS_TRACE', true);
@set_time_limit(60);
$accessT0 = microtime(true);
CfPolicy::$trace = 'access_trace';
access_trace('request ' . ($_SERVER['REQUEST_METHOD'] ?? '?'));
register_shutdown_function(static function () use ($accessT0): void {
    $e = error_get_last();
    access_trace('end after ' . round(microtime(true) - $accessT0, 2) . 's' . ($e && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true) ? ' FATAL: ' . $e['message'] . ' at ' . basename($e['file']) . ':' . $e['line'] : ''));
});

try {
    if (!tool_is_super($me)) {
        throw new HttpError(403, 'Only the super admin can do this.');
    }
    $cf = CfPolicy::fromConfig($config);
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET' && isset($_GET['diagnose'])) {
        json_response(200, ['steps' => access_diagnose($config)]);
    }
    if ($method === 'GET') {
        json_response(200, access_overview($pdo, $cf, load_member_map($pdo)) + ['me' => ['id' => (int)$me['id'], 'name' => $me['name']]]);
    }
    if ($method !== 'POST') {
        throw new HttpError(405, 'Method not allowed.');
    }
    $in = json_body();
    access_trace('action ' . (string)($in['action'] ?? '?'));
    $result = [];
    switch ((string)($in['action'] ?? '')) {
        case 'add':
            $result = access_add($pdo, $cf, $me, $in);
            break;
        case 'update':
            access_update($pdo, $me, (int)($in['id'] ?? 0), $in);
            break;
        case 'tool':
            access_set_tool($pdo, $me, (int)($in['id'] ?? 0), (string)($in['tool'] ?? ''), ($in['on'] ?? false) === true);
            break;
        case 'tools':
            access_set_tools($pdo, $me, (int)($in['id'] ?? 0), is_array($in['tools'] ?? null) ? $in['tools'] : []);
            break;
        case 'suspend':
            access_set_suspended($pdo, $me, (int)($in['id'] ?? 0), ($in['on'] ?? false) === true);
            break;
        case 'remove':
            $result = access_remove($pdo, $cf, $me, (string)($in['email'] ?? ''));
            break;
        case 'cf_add':
            $result = access_add_to_cloudflare($pdo, $cf, $me, (string)($in['email'] ?? ''));
            break;
        default:
            throw new HttpError(400, 'Unknown action.');
    }
    json_response(200, ['result' => $result] + access_overview($pdo, $cf, load_member_map($pdo)) + ['me' => ['id' => (int)$me['id'], 'name' => $me['name']]]);
} catch (HttpError $e) {
    json_response($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('access.php: ' . $e->getMessage());
    // Only the super admin can reach this far, so the real reason is shown to help fix problems.
    json_response(500, ['error' => 'Server error.', 'detail' => basename($e->getFile()) . ':' . $e->getLine() . ' ' . get_class($e) . ': ' . $e->getMessage()]);
}
