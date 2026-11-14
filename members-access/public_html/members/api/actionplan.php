<?php
declare(strict_types=1);

/**
 * Action Plan. Everything is decided in private/lib/actionplan.php (ap_dispatch); this file only signs the person in,
 * checks they may use the Action Plan tool, and passes the request through.
 *   GET  ?view=init | me | overview | approvals | person&plan=ID | template
 *   POST {action: tick | untick | count | approve | sendback | reopen | assign | plan_update | override | extra_add | extra_remove |
 *                 section_add | section_update | section_delete | section_move | item_add | item_update | item_remove | item_move, ...}
 * Errors use 4xx (never 502/504: Cloudflare would replace those with its own page and hide the reason).
 */
require_once __DIR__ . '/../../../private/bootstrap.php';
require_once __DIR__ . '/../../../private/lib/actionplan.php';

[$config, $pdo, $me] = boot_member_request();

try {
    if (!can_use_tool($pdo, $me, 'action-plan')) {
        throw new HttpError(403, 'The Action Plan is not switched on for you.');
    }
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method !== 'GET' && $method !== 'POST') {
        throw new HttpError(405, 'Method not allowed.');
    }
    json_response(200, ap_dispatch($pdo, $me, $method, $_GET, $method === 'POST' ? json_body() : [], ap_today($config)));
} catch (HttpError $e) {
    json_response($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('actionplan.php: ' . $e->getMessage());
    $detail = tool_is_super($me) ? basename($e->getFile()) . ':' . $e->getLine() . ' ' . get_class($e) . ': ' . $e->getMessage() : null;
    json_response(500, ['error' => 'Server error.'] + ($detail ? ['detail' => $detail] : []));
}
