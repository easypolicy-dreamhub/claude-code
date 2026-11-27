<?php
// LOCAL TESTING ONLY. Stands in for the real private/bootstrap.php of the members system.
// Same contract as the real one (as used by agency-report/photos.php): returns [$config, $pdo, $me] and ends
// the request itself with 401/403 for signed-out people and for writes that do not come from the site.
function dev_users(): array
{
    // Same shape as the real members row after authenticate_member(): ints for id / is_admin / is_active / is_team_admin.
    $row = fn(int $id, string $email, string $name, int $admin = 0, int $team = 0) =>
        ['id' => $id, 'email' => $email, 'name' => $name, 'is_admin' => $admin, 'is_active' => 1, 'is_team_admin' => $team, 'upline_id' => null];
    return [
        'alan'      => $row(11, 'alan@example.com', 'Alan'),
        'brenda'    => $row(12, 'brenda@example.com', 'Brenda'),
        'carol'     => $row(13, 'carol@example.com', 'Carol'),
        'erin'      => $row(14, 'erin@example.com', 'Erin'),
        'dora'      => $row(16, 'dora@example.com', 'Dora'),
        'teamadmin' => $row(15, 'ta@example.com', 'Team Admin', 0, 1),
        'notool'    => $row(17, 'nt@example.com', 'No Tool'),
        'boss'      => $row(1, 'boss@example.com', 'Boss', 1),
        'gone'      => ['is_active' => 0] + $row(18, 'gone@example.com', 'Gone', 1),
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
