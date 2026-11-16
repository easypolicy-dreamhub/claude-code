<?php
/* =====================================================================
   E3 Agency Report Generator: shared drafts
   Put this file in public_html/members/agency-report/ next to index.html
   and photos.php.

   There is nothing to set up here. Who may use drafts is decided by
   Members & Access: anyone who is signed in, active, not paused and has
   the Agency Report tool switched on (the super admin always can).
   Names shown as "saved by" come from the same member records.
   ===================================================================== */

/* Most drafts that can be kept at once. Delete an old one to make room. */
const MAX_DRAFTS = 10;
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
function id_ok($k) { return is_string($k) && preg_match('/^[a-f0-9]{16}$/', $k); }
function load_idx($f) {
  if (!is_file($f)) return array();
  $d = json_decode((string)file_get_contents($f), true);
  return is_array($d) ? $d : array();
}
function short_text($s, $n) {
  $s = trim((string)$s);
  return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
}
function write_atomic($path, $data) {
  $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
  if (file_put_contents($tmp, $data) === false) return false;
  return @rename($tmp, $path);
}
/* email (lower case) => name, from the member records. Names are cosmetic, so a failure here is not fatal. */
function member_names($pdo) {
  $out = array();
  try {
    foreach (load_member_map($pdo) as $m) {
      $e = strtolower((string)(isset($m['email']) ? $m['email'] : ''));
      $n = trim((string)(isset($m['name']) ? $m['name'] : ''));
      if ($e !== '' && $n !== '') $out[$e] = $n;
    }
  } catch (Throwable $t) { }
  return $out;
}
function with_name($meta, $names) {
  $by = strtolower((string)(isset($meta['by']) ? $meta['by'] : ''));
  $meta['by_name'] = isset($names[$by]) ? $names[$by] : '';
  return $meta;
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
$store = rtrim(dirname($_SERVER['DOCUMENT_ROOT']), '/') . '/e3-agency-drafts';
if (!is_dir($store)) @mkdir($store, 0750, true);
if (!is_dir($store) || !is_writable($store)) {
  $store = __DIR__ . '/draft-store';
  if (!is_dir($store)) @mkdir($store, 0750, true);
  if (!is_file($store . '/.htaccess')) @file_put_contents($store . '/.htaccess', "Require all denied\nDeny from all\n");
}
if (!is_dir($store) || !is_writable($store)) fail(500, 'storage_not_writable');
if (!is_file($store . '/.htaccess')) @file_put_contents($store . '/.htaccess', "Require all denied\nDeny from all\n");
if (!is_file($store . '/index.html')) @file_put_contents($store . '/index.html', '');

$action  = isset($_GET['action']) ? $_GET['action'] : '';
$idxFile = $store . '/index.json';

if ($action === 'list') {
  $names = member_names($pdo);
  $idx = array_values(load_idx($idxFile));
  usort($idx, function ($a, $b) { $x = isset($a['updated']) ? $a['updated'] : 0; $y = isset($b['updated']) ? $b['updated'] : 0; return $y > $x ? 1 : ($y < $x ? -1 : 0); });
  $idx = array_map(function ($m) use ($names) { return with_name($m, $names); }, $idx);
  ok(array('drafts' => $idx, 'user' => $email, 'max' => MAX_DRAFTS));
}
if ($action === 'get') {
  $id = isset($_GET['id']) ? $_GET['id'] : '';
  if (!id_ok($id)) fail(400, 'bad_id');
  $f = $store . '/' . $id . '.json';
  if (!is_file($f)) fail(404, 'not_found');
  $d = json_decode((string)file_get_contents($f), true);
  if (!is_array($d)) fail(404, 'not_found');
  ok(with_name($d, member_names($pdo)));
}

/* ---------- changes: POST from the app only (the members system already checked header, type and origin) ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'method_not_allowed');
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) fail(400, 'bad_body');

$lock = fopen($store . '/.lock', 'c');
if ($lock) flock($lock, LOCK_EX);
$idx = load_idx($idxFile);

if ($action === 'save') {
  $payload = isset($in['payload']) ? $in['payload'] : null;
  /* The draft is stored exactly as the page sent it (a JSON string), so nothing is reshaped on the way. */
  if (!is_string($payload) || $payload === '' || strlen($payload) > 6 * 1024 * 1024) fail(400, 'bad_payload');
  if (!is_array(json_decode($payload, true))) fail(400, 'bad_payload');
  $name = short_text(isset($in['name']) ? $in['name'] : '', 80);
  if ($name === '') $name = 'Untitled draft';
  $info = array('month' => 0, 'year' => 0, 'agency' => '');
  if (isset($in['info']) && is_array($in['info'])) {
    $info['month']  = isset($in['info']['month']) ? max(0, min(12, (int)$in['info']['month'])) : 0;
    $info['year']   = isset($in['info']['year']) ? max(0, min(9999, (int)$in['info']['year'])) : 0;
    $info['agency'] = short_text(isset($in['info']['agency']) ? $in['info']['agency'] : '', 60);
  }
  $id = isset($in['id']) ? $in['id'] : '';
  if ($id !== '' && $id !== null) {
    if (!id_ok($id) || !isset($idx[$id])) fail(404, 'not_found');
    /* If somebody saved this draft after the page opened it, do not overwrite their work. */
    if (isset($in['expect']) && (int)$in['expect'] !== (int)$idx[$id]['updated']) {
      $names = member_names($pdo);
      $other = with_name($idx[$id], $names);
      fail(409, 'conflict', array('updated' => $idx[$id]['updated'], 'by' => isset($idx[$id]['by']) ? $idx[$id]['by'] : '', 'by_name' => $other['by_name']));
    }
  } else {
    if (count($idx) >= MAX_DRAFTS) fail(400, 'too_many_drafts', array('max' => MAX_DRAFTS));
    $id = bin2hex(random_bytes(8));
  }
  $updated = (int)round(microtime(true) * 1000);
  $meta = array('id' => $id, 'name' => $name, 'updated' => $updated, 'by' => $email, 'size' => strlen($payload),
                'month' => $info['month'], 'year' => $info['year'], 'agency' => $info['agency']);
  $file = json_encode(array_merge($meta, array('payload' => $payload)));
  if ($file === false || !write_atomic($store . '/' . $id . '.json', $file)) fail(500, 'write_failed');
  $idx[$id] = $meta;
  if (!write_atomic($idxFile, json_encode($idx))) fail(500, 'write_failed');
  ok(array('saved' => with_name($meta, member_names($pdo))));
}
if ($action === 'rename') {
  $id = isset($in['id']) ? $in['id'] : '';
  if (!id_ok($id) || !isset($idx[$id])) fail(404, 'not_found');
  $name = short_text(isset($in['name']) ? $in['name'] : '', 80);
  if ($name === '') fail(400, 'bad_name');
  $f = $store . '/' . $id . '.json';
  $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
  if (!is_array($d)) fail(404, 'not_found');
  $d['name'] = $name;
  $idx[$id]['name'] = $name;
  if (!write_atomic($f, json_encode($d)) || !write_atomic($idxFile, json_encode($idx))) fail(500, 'write_failed');
  ok(array('saved' => with_name($idx[$id], member_names($pdo))));
}
if ($action === 'delete') {
  $id = isset($in['id']) ? $in['id'] : '';
  if (!id_ok($id)) fail(400, 'bad_id');
  @unlink($store . '/' . $id . '.json');
  unset($idx[$id]);
  if (!write_atomic($idxFile, json_encode($idx))) fail(500, 'write_failed');
  ok(array('deleted' => $id));
}
fail(400, 'unknown_action');
