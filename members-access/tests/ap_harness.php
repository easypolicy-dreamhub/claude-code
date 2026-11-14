<?php
declare(strict_types=1);

// Browser-test harness (NOT for the live site): php -S 127.0.0.1:8099 tests/ap_harness.php
// Serves the Action Plan page and a fake sign-in over a throw-away sqlite file, using the real Action Plan code.
require_once __DIR__ . '/ap_support.php';

$file = getenv('AP_DB') ?: sys_get_temp_dir() . '/ap_harness.sqlite';
$portalDir = $file . '.portal';
if (!is_dir($portalDir)) { @mkdir($portalDir . '/agents', 0777, true); copy(__DIR__ . '/fixtures/team-bridge.php', $portalDir . '/team-bridge.php'); }
putenv('E3_PORTAL_BRIDGE=' . $portalDir . '/team-bridge.php');
$root = dirname(__DIR__) . '/public_html';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function ap_h_seed(string $file): void
{
    @unlink($file);
    exec('rm -rf ' . escapeshellarg($GLOBALS['portalDir'] . '/agents') . ' ' . escapeshellarg($GLOBALS['portalDir'] . '/agents.json'));
    @mkdir($GLOBALS['portalDir'] . '/agents', 0777, true);
    [$pdo, $you, $ids] = ap_world($file);
    $today = '2026-03-23';
    ap_ensure_template($pdo, '2026-03-01');
    $dave = ap_assign($pdo, $you, $ids['Dave'], '2026-03-02', 90);
    $cindy = ap_assign($pdo, $you, $ids['Cindy'], '2026-03-09', 120);
    $pdo->prepare('UPDATE members SET whatsapp = ? WHERE id = ?')->execute(['60123456789', $ids['Dave']]);
    $pdo->prepare('UPDATE members SET whatsapp = ? WHERE id = ?')->execute(['60198765432', $ids['Cindy']]);
    ap_plan_update($pdo, $you, $dave, ['buddy_id' => $ids['Cindy']]);
    $items = ap_plan_items($pdo, ap_plan_row($pdo, $dave), $today);
    foreach ($items as $it) {
        if ($it['to'] <= 1 && $it['title'] !== 'Family Visit') {
            ap_progress_set($pdo, $dave, $it['id'], ['status' => 'done', 'approved_by' => $you['id'], 'approved_at' => ap_now()]);
        }
        if ($it['title'] === 'Family Visit') { ap_tick($pdo, ap_plan_row($pdo, $dave), $it['id'], $today); }
        if ($it['title'] === 'Customer fact finding forms (30)') { ap_count($pdo, ap_plan_row($pdo, $dave), $it['id'], 6, $today); }
    }
    ap_plan_update($pdo, $you, $dave, ['disc' => 'IS', 'private_number' => 7]);
    // a pretend Client Portal for Dave: 64 prospects, 10 market surveys, 6 presentations, 2 closed cases
    global $portalDir;
    e3pb_toggle('dave@x.com', 'Dave', true);
    $dir = $portalDir . '/agents/' . e3pb_load()['dave@x.com']['uid'];
    ap_make_portal($dir, 'A');
    $pd = ap_portal_db($dir);
    for ($i = 0; $i < 64; $i++) { $pd->exec("INSERT INTO clients (name, kind, created_at) VALUES ('Someone', 'prospect', '2026-03-01')"); }
    for ($i = 1; $i <= 10; $i++) { ap_note($pd, 'open', '2026-03-' . sprintf('%02d', 2 + $i)); }
    for ($i = 1; $i <= 6; $i++) { ap_note($pd, 'present', '2026-03-' . sprintf('%02d', 2 + $i)); }
    $pd->exec("INSERT INTO closes (close_date, name, plan, anp, updated_at) VALUES ('2026-03-12', 'x', 'p', 4000, 'x'), ('2026-03-20', 'y', 'q', 6500, 'x')");
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
