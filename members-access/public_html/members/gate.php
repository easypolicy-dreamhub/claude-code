<?php
declare(strict_types=1);

/**
 * Opens a tool page only for people who are ticked for it. Reached through members/.htaccess; the address in the
 * browser does not change. It only ever serves .html pages of the tools listed in private/lib/tools.php.
 */
require_once __DIR__ . '/../../private/lib/gate.php';

$config = [];
try {
    [$config, $pdo, $member] = boot_member_page();
} catch (HttpError $e) {
    render_denied($e->status === 401 ? 401 : 403, $e->status === 401 ? 'Please sign in' : 'No access', $e->getMessage(), $config);
} catch (Throwable $e) {
    error_log('gate boot: ' . $e->getMessage());
    render_denied(500, 'Something went wrong', 'Please try again in a moment.', $config);
}

$hit = gate_resolve(__DIR__, (string)($_GET['path'] ?? ''));
if ($hit === null) {
    render_denied(404, 'Page not found', 'That page does not exist.', $config);
}
$d = gate_decide($pdo, $member, $hit['tool']);
if (!$d['ok']) {
    render_denied($d['status'], $d['headline'], $d['detail'], $config);
}

if ($hit['kind'] === 'php') {
    chdir(dirname($hit['file']));
    require $hit['file'];
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-cache');
header('X-Content-Type-Options: nosniff');
readfile($hit['file']);
