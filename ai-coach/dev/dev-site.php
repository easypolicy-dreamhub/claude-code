<?php
// LOCAL TESTING ONLY. Never upload the dev/ folder to your server.
// Used as auto_prepend_file by run-tests.sh to stand in for the real members site
// (boot_member_request / require_admin / config.php) so the coach can be tested alone.

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('dev stub is local only');
}

define('ANTHROPIC_API_KEY', 'test-key-123');
define('COACH_WHATSAPP_NUMBER', '60123456789');
define('COACH_API_BASE', (string) getenv('MOCK_BASE'));
define('COACH_DATA_DIR', (string) getenv('TEST_DATA_DIR'));
define('COACH_HISTORY_MSGS', (int) (getenv('TEST_HISTORY') ?: 4));
define('COACH_RATE_PER_HOUR', (int) (getenv('TEST_RATE') ?: 20));

function dev_users(): array
{
    return [
        'alan' => ['id' => 'u-alan', 'name' => 'Alan', 'role' => 'agent'],
        'brenda' => ['id' => 'u-brenda', 'name' => 'Brenda', 'role' => 'agent'],
        'carol' => ['id' => 'u-carol', 'name' => 'Carol', 'role' => 'agent'],
        'teamadmin' => ['id' => 'u-ta', 'name' => 'Team Admin', 'role' => 'admin'], // passes require_admin, is NOT super admin
        'boss' => ['id' => 'u-boss', 'name' => 'Boss', 'role' => 'super_admin'],
    ];
}

function boot_member_request()
{
    $u = dev_users()[strtolower($_SERVER['HTTP_X_DEV_USER'] ?? '')] ?? null;
    if (!$u) {
        http_response_code(401);
        header('Content-Type: application/json');
        exit('{"error":"sign in"}');
    }
    $GLOBALS['dev_user'] = $u;
    return $u;
}

function require_admin()
{
    $u = $GLOBALS['dev_user'] ?? null;
    if (!$u || !in_array($u['role'], ['admin', 'super_admin'], true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        exit('{"error":"admin only"}');
    }
}
