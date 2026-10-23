<?php
declare(strict_types=1);

/**
 * GET                 members you may see (admin: everyone; others: self + downline)
 * POST   {…}          admin: add a member
 * PATCH  ?id=N {…}    admin: edit / deactivate / re-parent a member
 */
require_once __DIR__ . '/../../../private/bootstrap.php';

[$config, $pdo, $me] = boot_member_request();

try {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        $map = load_member_map($pdo);
        $ids = $me['is_admin'] ? array_keys($map) : visible_member_ids($map, $me['id']);
        $rows = array_map(static fn (int $id) => public_member($map[$id]), $ids);
        usort($rows, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        json_response(200, ['members' => $rows]);
    }

    require_admin($me);
    if ($method === 'POST') {
        json_response(201, ['member' => create_member($pdo, $me['id'], json_body())]);
    }
    if ($method === 'PATCH') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpError(400, 'Missing ?id=');
        }
        json_response(200, ['member' => update_member($pdo, $me['id'], $id, json_body())]);
    }
    throw new HttpError(405, 'Method not allowed.');
} catch (HttpError $e) {
    json_response($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('members.php: ' . $e->getMessage());
    json_response(500, ['error' => 'Server error.']);
}
