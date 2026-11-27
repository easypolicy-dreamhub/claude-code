<?php
// LOCAL TESTING ONLY. Stands in for the real private/bootstrap.php of the members system.
// Same contract as the real one (as used by agency-report/photos.php): returns [$config, $pdo, $me] and ends
// the request itself with 401/403 for signed-out people and for writes that do not come from the site.
function dev_users(): array
{
    return [
        'alan'      => ['email' => 'alan@example.com',  'name' => 'Alan',       'role' => 'member',      'tools' => ['hnw-coach']],
        'brenda'    => ['email' => 'brenda@example.com','name' => 'Brenda',     'role' => 'member',      'tools' => ['hnw-coach']],
        'carol'     => ['email' => 'carol@example.com', 'name' => 'Carol',      'role' => 'member',      'tools' => ['hnw-coach']],
        'erin'      => ['email' => 'erin@example.com',  'name' => 'Erin',       'role' => 'member',      'tools' => ['hnw-coach']],
        'teamadmin' => ['email' => 'ta@example.com',    'name' => 'Team Admin', 'role' => 'admin',       'tools' => ['hnw-coach']],
        'notool'    => ['email' => 'nt@example.com',    'name' => 'No Tool',    'role' => 'member',      'tools' => []],
        'boss'      => ['email' => 'boss@example.com',  'name' => 'Boss',       'role' => 'super_admin', 'tools' => []],
    ];
}
function dev_fail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    exit(json_encode(['error' => $msg]));
}
function boot_member_request(): array
{
    $u = dev_users()[strtolower($_SERVER['HTTP_X_DEV_USER'] ?? '')] ?? null;
    if (!$u) dev_fail(401, 'sign in');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') dev_fail(403, 'missing header');
        if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) dev_fail(403, 'wrong content type');
        $o = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($o !== '' && strcasecmp(parse_url($o, PHP_URL_HOST) . (($p = parse_url($o, PHP_URL_PORT)) ? ':' . $p : ''), (string) $_SERVER['HTTP_HOST']) !== 0) dev_fail(403, 'wrong origin');
    }
    return [[], null, $u];
}
