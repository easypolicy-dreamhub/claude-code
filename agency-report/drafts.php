<?php
/* =====================================================================
   E3 Agency Report Generator: shared drafts
   Put this file in public_html/members/agency-report/ next to index.html
   and photos.php.

   SETTINGS: normally you change nothing. Leave the three lines below empty
   and this file uses the same Cloudflare Access settings as photos.php
   (team domain, AUD tag and allowed emails). Only fill them in here if you
   want drafts to use different values.
   ===================================================================== */
$TEAM_DOMAIN    = '';
$AUD_TAG        = '';
$ALLOWED_EMAILS = array();
/* ===================== End of the part you edit ====================== */

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
/* Reads one of the access settings out of photos.php, so they are kept in one place. */
function from_photos_php($name) {
  $f = __DIR__ . '/photos.php';
  if (!is_file($f)) return null;
  $src = @file_get_contents($f);
  if ($src === false) return null;
  if ($name === 'ALLOWED_EMAILS') {
    if (!preg_match('/\$ALLOWED_EMAILS\s*=\s*(?:array\s*\((.*?)\)|\[(.*?)\])\s*;/s', $src, $m)) return array();
    $body = (isset($m[1]) && $m[1] !== '') ? $m[1] : (isset($m[2]) ? $m[2] : '');
    $out = array();
    if (preg_match_all('/\'([^\']+)\'|"([^"]+)"/', $body, $q, PREG_SET_ORDER)) {
      foreach ($q as $one) {
        $v = (isset($one[2]) && $one[2] !== '') ? $one[2] : $one[1];
        if ($v !== '') $out[] = $v;
      }
    }
    return $out;
  }
  if (preg_match('/\$' . $name . '\s*=\s*\'([^\']*)\'\s*;/', $src, $m)) return $m[1];
  return null;
}
function b64url($s) {
  $s = strtr($s, '-_', '+/');
  $pad = strlen($s) % 4;
  if ($pad) $s .= str_repeat('=', 4 - $pad);
  return base64_decode($s);
}
function http_get($url) {
  if (function_exists('curl_init')) {
    $c = curl_init($url);
    curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($c, CURLOPT_TIMEOUT, 8);
    curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
    $r = curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return ($code == 200) ? $r : false;
  }
  return @file_get_contents($url);
}
function find_cert($certs, $kid) {
  if (!is_array($certs) || empty($certs['public_certs'])) return null;
  foreach ($certs['public_certs'] as $c) {
    if (isset($c['kid'], $c['cert']) && $c['kid'] === $kid) return $c['cert'];
  }
  return null;
}
/* Checks the Cloudflare Access sign-in token. Returns the email, or null. */
function access_email($team, $aud, $store) {
  $jwt = '';
  if (!empty($_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'])) $jwt = $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'];
  elseif (!empty($_COOKIE['CF_Authorization'])) $jwt = $_COOKIE['CF_Authorization'];
  if ($jwt === '') return null;
  $parts = explode('.', $jwt);
  if (count($parts) !== 3) return null;
  $hdr = json_decode(b64url($parts[0]), true);
  $pl  = json_decode(b64url($parts[1]), true);
  $sig = b64url($parts[2]);
  if (!is_array($hdr) || !is_array($pl) || !isset($hdr['alg']) || $hdr['alg'] !== 'RS256') return null;
  $kid = isset($hdr['kid']) ? $hdr['kid'] : '';

  $cacheFile = $store . '/.certs.json';
  $certs = null;
  if (is_file($cacheFile) && filemtime($cacheFile) > time() - 3600) {
    $certs = json_decode((string)file_get_contents($cacheFile), true);
  }
  if (!find_cert($certs, $kid)) {
    $raw = http_get('https://' . $team . '/cdn-cgi/access/certs');
    if ($raw) { $certs = json_decode($raw, true); @file_put_contents($cacheFile, $raw); }
  }
  $pem = find_cert($certs, $kid);
  if (!$pem) return null;
  if (openssl_verify($parts[0] . '.' . $parts[1], $sig, $pem, OPENSSL_ALGO_SHA256) !== 1) return null;

  $auds = isset($pl['aud']) ? (array)$pl['aud'] : array();
  if (!in_array($aud, $auds, true)) return null;
  if (!isset($pl['exp']) || $pl['exp'] < time()) return null;
  if (isset($pl['iss']) && rtrim($pl['iss'], '/') !== 'https://' . $team) return null;
  return isset($pl['email']) ? strtolower($pl['email']) : 'member';
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

/* ---------- settings: own values, else the ones in photos.php ---------- */
if ($TEAM_DOMAIN === '') { $v = from_photos_php('TEAM_DOMAIN'); if ($v !== null) $TEAM_DOMAIN = $v; }
if ($AUD_TAG === '')     { $v = from_photos_php('AUD_TAG');     if ($v !== null) $AUD_TAG = $v; }
if (empty($ALLOWED_EMAILS)) { $v = from_photos_php('ALLOWED_EMAILS'); if (is_array($v)) $ALLOWED_EMAILS = $v; }

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

/* ---------- sign-in check ---------- */
if ($TEAM_DOMAIN === '' || $AUD_TAG === '' || strpos($TEAM_DOMAIN, 'YOUR-TEAM') !== false || strpos($AUD_TAG, 'PASTE') !== false) fail(503, 'not_configured');
$email = access_email($TEAM_DOMAIN, $AUD_TAG, $store);
if (!$email) fail(401, 'not_signed_in');
/* Drafts hold production figures, so only the allowed emails may read or change them. */
$allowed = array_map('strtolower', $ALLOWED_EMAILS);
if (!empty($allowed) && !in_array($email, $allowed, true)) fail(403, 'not_allowed');

$action  = isset($_GET['action']) ? $_GET['action'] : '';
$idxFile = $store . '/index.json';

if ($action === 'list') {
  $idx = array_values(load_idx($idxFile));
  usort($idx, function ($a, $b) { $x = isset($a['updated']) ? $a['updated'] : 0; $y = isset($b['updated']) ? $b['updated'] : 0; return $y > $x ? 1 : ($y < $x ? -1 : 0); });
  ok(array('drafts' => $idx, 'user' => $email));
}
if ($action === 'get') {
  $id = isset($_GET['id']) ? $_GET['id'] : '';
  if (!id_ok($id)) fail(400, 'bad_id');
  $f = $store . '/' . $id . '.json';
  if (!is_file($f)) fail(404, 'not_found');
  header('Content-Type: application/json');
  header('Content-Length: ' . filesize($f));
  readfile($f);
  exit;
}

/* ---------- changes: POST from the app only ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'fetch') fail(405, 'method_not_allowed');
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
      fail(409, 'conflict', array('updated' => $idx[$id]['updated'], 'by' => isset($idx[$id]['by']) ? $idx[$id]['by'] : ''));
    }
  } else {
    if (count($idx) >= 200) fail(400, 'too_many_drafts');
    $id = bin2hex(random_bytes(8));
  }
  $updated = (int)round(microtime(true) * 1000);
  $meta = array('id' => $id, 'name' => $name, 'updated' => $updated, 'by' => $email, 'size' => strlen($payload),
                'month' => $info['month'], 'year' => $info['year'], 'agency' => $info['agency']);
  $file = json_encode(array_merge($meta, array('payload' => $payload)));
  if ($file === false || !write_atomic($store . '/' . $id . '.json', $file)) fail(500, 'write_failed');
  $idx[$id] = $meta;
  if (!write_atomic($idxFile, json_encode($idx))) fail(500, 'write_failed');
  ok(array('saved' => $meta));
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
  ok(array('saved' => $idx[$id]));
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
