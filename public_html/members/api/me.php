<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../private/bootstrap.php';

[$config, $pdo, $me] = boot_member_request();

try {
    $map = load_member_map($pdo);
    json_response(200, [
        'member' => public_member($map[$me['id']]),
        'visible_member_ids' => visible_member_ids($map, $me['id']),
    ]);
} catch (Throwable $e) {
    error_log('me.php: ' . $e->getMessage());
    json_response(500, ['error' => 'Server error.']);
}
