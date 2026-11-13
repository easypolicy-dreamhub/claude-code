<?php
declare(strict_types=1);

/**
 * Members & Access (super admin only).
 *   GET                                  -> everyone on the list, the tool catalog, and whether Cloudflare is connected
 *   POST {action:'add', email, name, type, tools?|copy_from?}      add on Cloudflare + this site (also "set up" a new email)
 *   POST {action:'update', id, name?, type?}
 *   POST {action:'tool', id, tool, on}   switch one tool for one person
 *   POST {action:'tools', id, tools[]}   replace one person's tools
 *   POST {action:'remove', email}        Admin / Tester: off the site and Cloudflare, signed out
 *   POST {action:'cf_add', email}        put someone already on this site onto Cloudflare
 */
require_once __DIR__ . '/../../../private/bootstrap.php';
require_once __DIR__ . '/../../../private/lib/access.php';

[$config, $pdo, $me] = boot_member_request();

try {
    if (!tool_is_super($me)) {
        throw new HttpError(403, 'Only the super admin can do this.');
    }
    $cf = CfPolicy::fromConfig($config);
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        json_response(200, access_overview($pdo, $cf, load_member_map($pdo)) + ['me' => ['id' => (int)$me['id'], 'name' => $me['name']]]);
    }
    if ($method !== 'POST') {
        throw new HttpError(405, 'Method not allowed.');
    }
    $in = json_body();
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
    json_response(500, ['error' => 'Server error.']);
}
