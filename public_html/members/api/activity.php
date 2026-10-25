<?php
declare(strict_types=1);

/** GET ?period=week|month|all -> counts, per-person table, follow-ups and recent activity for the viewer's own tree. */
require_once __DIR__ . '/../../../private/bootstrap.php';
require_once __DIR__ . '/../../../private/lib/activity.php';

[$config, $pdo, $me] = boot_member_request();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new HttpError(405, 'Method not allowed.');
    }
    json_response(200, activity_summary($pdo, load_member_map($pdo), $me, (string)($_GET['period'] ?? 'week')));
} catch (HttpError $e) {
    json_response($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('activity.php: ' . $e->getMessage());
    json_response(500, ['error' => 'Server error.']);
}
