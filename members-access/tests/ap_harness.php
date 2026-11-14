<?php
declare(strict_types=1);

// Browser-test harness (NOT for the live site): php -S 127.0.0.1:8099 tests/ap_harness.php
// Serves the Action Plan page and a fake sign-in over a throw-away sqlite file, using the real Action Plan code.
require_once __DIR__ . '/ap_support.php';

$file = getenv('AP_DB') ?: sys_get_temp_dir() . '/ap_harness.sqlite';
$root = dirname(__DIR__) . '/public_html';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function ap_h_seed(string $file): void
{
    @unlink($file);
    [$pdo, $you, $ids] = ap_world($file);
    $today = '2026-03-23';
    ap_ensure_template($pdo, '2026-03-01');
    $dave = ap_assign($pdo, $you, $ids['Dave'], '2026-03-02', 90);
    $cindy = ap_assign($pdo, $you, $ids['Cindy'], '2026-03-09', 120);
    $items = ap_plan_items($pdo, ap_plan_row($pdo, $dave), $today);
    foreach ($items as $it) {
        if ($it['to'] <= 1 && $it['title'] !== 'Family Visit') {
            ap_progress_set($pdo, $dave, $it['id'], ['status' => 'done', 'approved_by' => $you['id'], 'approved_at' => ap_now()]);
        }
        if ($it['title'] === 'Family Visit') { ap_tick($pdo, ap_plan_row($pdo, $dave), $it['id'], $today); }
        if ($it['title'] === 'Prospect list (100)') { ap_count($pdo, ap_plan_row($pdo, $dave), $it['id'], 64, $today); }
        if ($it['title'] === 'Presentations (50)') { ap_count($pdo, ap_plan_row($pdo, $dave), $it['id'], 6, $today); }
    }
    ap_plan_update($pdo, $you, $dave, ['disc' => 'IS', 'private_number' => 7]);
    file_put_contents($file . '.ids', json_encode($ids));
}

if ($path === '/__reset' || !is_file($file)) {
    ap_h_seed($file);
    if ($path === '/__reset') { header('Content-Type: application/json'); echo file_get_contents($file . '.ids'); return; }
}
$ids = json_decode((string)file_get_contents($file . '.ids'), true);

if ($path === '/__as') {   // /__as?who=Dave&today=2026-03-23
    setcookie('as', (string)($ids[$_GET['who'] ?? 'You'] ?? $ids['You']), 0, '/');
    setcookie('today', (string)($_GET['today'] ?? '2026-03-23'), 0, '/');
    header('Content-Type: application/json'); echo json_encode(['ok' => true]); return;
}
if ($path === '/members/api/actionplan.php') {
    $pdo = ap_test_db_open($file);
    $me = load_member_map($pdo)[(int)($_COOKIE['as'] ?? $ids['You'])];
    $in = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
    header('Content-Type: application/json');
    try {
        if (!can_use_tool($pdo, $me, 'action-plan')) { throw new HttpError(403, 'The Action Plan is not switched on for you.'); }
        echo json_encode(ap_dispatch($pdo, $me, $_SERVER['REQUEST_METHOD'], $_GET, $in, (string)($_COOKIE['today'] ?? '2026-03-23')));
    } catch (HttpError $e) {
        http_response_code($e->status); echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}
if ($path === '/members/action-plan/') { header('Content-Type: text/html'); readfile($root . '/members/action-plan/index.html'); return; }
if ($path === '/members/e3-toolkit.css' && is_file($root . '/members/e3-toolkit.css')) { header('Content-Type: text/css'); readfile($root . '/members/e3-toolkit.css'); return; }
http_response_code(404);

function ap_test_db_open(string $file): PDO
{
    return new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
