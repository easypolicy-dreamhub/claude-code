<?php
/* =====================================================================
   E3 Agency Report Generator: shared photo library
   Put this file in public_html/members/agency-report/ next to index.html.

   There is nothing to set up here. Who may use the photo library is decided
   by Members & Access: anyone who is signed in, active, not paused and has
   the Agency Report tool switched on (the super admin always can). They can
   view, add and delete photos. Nobody needs to be listed in this file.
   ===================================================================== */

const TOOL_KEY = 'agency-report';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

function fail($code, $msg, $extra = array()) {
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode(array_merge(array('error' => $msg), $extra));
  exit;
}
function ok($data) {
  header('Content-Type: application/json');
  echo json_encode($data);
  exit;
}
function key_ok($k) { return is_string($k) && preg_match('/^[A-Za-z0-9:_.\-]{1,80}$/', $k); }
function fname($k) { return substr(hash('sha256', $k), 0, 32); }
function load_idx($f) {
  if (!is_file($f)) return array();
  $d = json_decode((string)file_get_contents($f), true);
  return is_array($d) ? $d : array();
}
function jpeg_from($d) {
  $prefix = 'data:image/jpeg;base64,';
  if (!is_string($d) || strpos($d, $prefix) !== 0) return null;
  $b = base64_decode(substr($d, strlen($prefix)), true);
  if ($b === false || strlen($b) > 4 * 1024 * 1024 || substr($b, 0, 3) !== "\xFF\xD8\xFF") return null;
  if (function_exists('getimagesizefromstring') && !@getimagesizefromstring($b)) return null;
  return $b;
}
function short_text($s, $n) {
  $s = (string)$s;
  return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
}

/* ---------- sign-in and permission: the members system ---------- */
$private = __DIR__ . '/../../../private';
if (!is_file($private . '/bootstrap.php') || !is_file($private . '/lib/tools.php')) fail(500, 'members_system_missing');
require_once $private . '/bootstrap.php';
require_once $private . '/lib/tools.php';
/* Ends the request itself (401/403) when the person is not a signed-in, active, unpaused member,
   or when the request is a write that does not come from this site. */
list($config, $pdo, $me) = boot_member_request();
if (!can_use_tool($pdo, $me, TOOL_KEY)) {
  fail(403, 'not_allowed', array('message' => 'Your account does not have the Agency Report tool switched on. Ask your admin to switch it on in Members & Access.'));
}
$email = strtolower((string)$me['email']);

/* ---------- storage: outside public_html when possible ---------- */
$store = rtrim(dirname($_SERVER['DOCUMENT_ROOT']), '/') . '/e3-agency-photos';
if (!is_dir($store)) @mkdir($store, 0750, true);
if (!is_dir($store) || !is_writable($store)) {
  $store = __DIR__ . '/photo-store';
  if (!is_dir($store)) @mkdir($store, 0750, true);
  if (!is_file($store . '/.htaccess')) @file_put_contents($store . '/.htaccess', "Require all denied\nDeny from all\n");
}
if (!is_dir($store) || !is_writable($store)) fail(500, 'storage_not_writable');
/* Block direct web access to the folder, wherever it ended up */
if (!is_file($store . '/.htaccess')) @file_put_contents($store . '/.htaccess', "Require all denied\nDeny from all\n");
if (!is_file($store . '/index.html')) @file_put_contents($store . '/index.html', '');

$action = isset($_GET['action']) ? $_GET['action'] : '';
$idxFile = $store . '/index.json';

/* ---------- reading ---------- */
if ($action === 'img' || $action === 'orig') {
  $k = isset($_GET['key']) ? $_GET['key'] : '';
  if (!key_ok($k)) fail(400, 'bad_key');
  $f = $store . '/' . fname($k) . ($action === 'orig' ? '-orig' : '') . '.jpg';
  if (!is_file($f)) fail(404, 'not_found');
  header('Content-Type: image/jpeg');
  header('Cache-Control: private, max-age=31536000');
  header('Content-Length: ' . filesize($f));
  readfile($f);
  exit;
}
if ($action === 'list') {
  ok(array('photos' => array_values(load_idx($idxFile)), 'user' => $email, 'canEdit' => true));
}

/* ---------- changes: POST from the app only (the members system already checked header, type and origin) ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'method_not_allowed');
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) fail(400, 'bad_body');
$k = isset($in['key']) ? $in['key'] : '';
if (!key_ok($k)) fail(400, 'bad_key');

$lock = fopen($store . '/.lock', 'c');
if ($lock) flock($lock, LOCK_EX);
$idx = load_idx($idxFile);
$base = $store . '/' . fname($k);

if ($action === 'save') {
  $out = jpeg_from(isset($in['out']) ? $in['out'] : '');
  if (!$out) fail(400, 'bad_image');
  if (file_put_contents($base . '.jpg', $out) === false) fail(500, 'write_failed');
  if (!empty($in['orig'])) {
    $o = jpeg_from($in['orig']);
    if ($o) file_put_contents($base . '-orig.jpg', $o);
  }
  $frame = null;
  if (isset($in['frame']) && is_array($in['frame'])) {
    $frame = array();
    foreach (array('z', 'ox', 'oy') as $f) if (isset($in['frame'][$f])) $frame[$f] = (float)$in['frame'][$f];
  }
  $idx[$k] = array(
    'key' => $k,
    'name' => short_text(isset($in['name']) ? $in['name'] : '', 120),
    'frame' => $frame,
    'orig' => is_file($base . '-orig.jpg'),
    'updated' => (int)round(microtime(true) * 1000),
    'by' => $email
  );
  file_put_contents($idxFile, json_encode($idx), LOCK_EX);
  ok(array('saved' => $idx[$k]));
}
if ($action === 'delete') {
  @unlink($base . '.jpg');
  @unlink($base . '-orig.jpg');
  unset($idx[$k]);
  file_put_contents($idxFile, json_encode($idx), LOCK_EX);
  ok(array('deleted' => $k));
}
fail(400, 'unknown_action');
