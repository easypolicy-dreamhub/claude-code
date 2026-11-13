<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../private/bootstrap.php';
require_once __DIR__ . '/../../../private/lib/tools.php';

[$config, $pdo, $me] = boot_member_request();

try {
    $map = load_member_map($pdo);
    $self = $map[$me['id']];
    json_response(200, [
        'member' => public_member($self) + ['type' => member_type($self)],
        'visible_member_ids' => visible_member_ids($map, $me['id']),
        // The toolkit page shows only these cards. (The server also checks every tool page itself.)
        'tools' => allowed_tools($pdo, $self),
    ]);
} catch (Throwable $e) {
    error_log('me.php: ' . $e->getMessage());
    json_response(500, ['error' => 'Server error.']);
}
