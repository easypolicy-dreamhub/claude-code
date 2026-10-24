<?php
declare(strict_types=1);

// Run: php tests/run.php   (needs php-openssl + pdo_sqlite; no network, no MySQL)
require_once __DIR__ . '/../private/lib/auth.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? "  ok   " : "  FAIL ") . $name . "\n"; }
function throws(string $name, int $status, callable $fn): void {
    try { $fn(); check("$name (expected $status)", false); }
    catch (HttpError $e) { check("$name -> {$e->status}", $e->status === $status); }
}
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

// ---------- JWT fixtures ----------
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$det = openssl_pkey_get_details($key);
$jwk = ['kty' => 'RSA', 'kid' => 'k1', 'alg' => 'RS256', 'use' => 'sig', 'n' => b64u($det['rsa']['n']), 'e' => b64u($det['rsa']['e'])];
$TEAM = 'dreamhub.cloudflareaccess.com'; $AUD = 'aud-tag-123'; $NOW = 1_800_000_000;

function mint($key, array $claims, array $header = []): string {
    $h = b64u(json_encode($header + ['alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT']));
    $p = b64u(json_encode($claims));
    openssl_sign("$h.$p", $sig, $key, OPENSSL_ALGO_SHA256);
    return "$h.$p." . b64u($sig);
}
$good = ['email' => 'Goh@Example.com', 'aud' => [$AUD], 'iss' => "https://$TEAM", 'exp' => $NOW + 600, 'iat' => $NOW];
$cf = new CfAccess($TEAM, $AUD, fn() => ['keys' => [$jwk]], fn() => $NOW);

echo "Cloudflare token verification\n";
check('valid token accepted, email lower-cased', $cf->verify(mint($key, $good))['email'] === 'goh@example.com');
check('PEM built from JWK matches OpenSSL', trim(CfAccess::jwkToPem($jwk)) === trim($det['key']));
throws('missing token', 401, fn() => $cf->verify(null));
throws('garbage token', 401, fn() => $cf->verify('a.b'));
throws('tampered payload', 401, function () use ($cf, $key, $good) {
    [$h, , $s] = explode('.', mint($key, $good));
    $cf->verify("$h." . b64u(json_encode(['email' => 'admin@evil.com'] + $good)) . ".$s");
});
$other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
throws('signed by a different key', 401, fn() => $cf->verify(mint($other, $good)));
throws('alg none', 401, fn() => $cf->verify(b64u('{"alg":"none","kid":"k1"}') . '.' . b64u(json_encode($good)) . '.' . b64u('x')));
throws('alg HS256', 401, fn() => $cf->verify(mint($key, $good, ['alg' => 'HS256'])));
throws('expired', 401, fn() => $cf->verify(mint($key, ['exp' => $NOW - 120] + $good)));
check('within clock leeway accepted', $cf->verify(mint($key, ['exp' => $NOW - 10] + $good))['email'] === 'goh@example.com');
throws('wrong audience (another Access app)', 401, fn() => $cf->verify(mint($key, ['aud' => ['other']] + $good)));
throws('wrong issuer', 401, fn() => $cf->verify(mint($key, ['iss' => 'https://evil.cloudflareaccess.com'] + $good)));
throws('no email claim', 401, fn() => $cf->verify(mint($key, array_diff_key($good, ['email' => 1]))));
throws('unknown kid', 401, fn() => $cf->verify(mint($key, $good, ['kid' => 'nope'])));
$calls = []; $rotating = new CfAccess($TEAM, $AUD, function (bool $refresh = false) use ($jwk, &$calls) { $calls[] = $refresh; return $refresh ? ['keys' => [$jwk]] : ['keys' => []]; }, fn() => $NOW);
check('key rotation: refetches once on a miss', $rotating->verify(mint($key, $good))['email'] === 'goh@example.com' && $calls === [false, true]);

// ---------- database fixtures (SQLite stands in for MySQL) ----------
function fresh_db(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE members (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL, name TEXT NOT NULL, rank_label TEXT NOT NULL DEFAULT "agent",
        upline_id INTEGER NULL, is_admin INTEGER NOT NULL DEFAULT 0, is_team_admin INTEGER NOT NULL DEFAULT 0, referral_code TEXT UNIQUE NOT NULL, is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, deactivated_at TEXT NULL, removal_batch TEXT NULL)');
    $pdo->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_id INTEGER, action TEXT, subject_id INTEGER, detail TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    return $pdo;
}
$pdo = fresh_db();
$me   = create_member($pdo, null, ['email' => 'you@x.com', 'name' => 'You', 'rank' => 'unit_manager', 'is_admin' => true]);
$mk = fn(string $n, int $up, string $rank = 'agent') => create_member($pdo, $me['id'], ['email' => strtolower($n) . '@x.com', 'name' => $n, 'upline_id' => $up, 'rank' => $rank])['id'];
$A = $mk('A', $me['id'], 'unit_manager'); $B = $mk('B', $me['id']); $C = $mk('C', $me['id']); $D = $mk('D', $me['id']);
$AA = $mk('AA', $A); $BB = $mk('BB', $B);
$ids = fn(int $v) => (function () use ($pdo, $v) { $x = visible_member_ids(load_member_map($pdo), $v); sort($x); return $x; })();
$want = fn(int ...$x) => (function () use ($x) { sort($x); return $x; })();

echo "\nAccess rules (your A/B/AA/BB example)\n";
check('you see everyone', $ids($me['id']) === $want($me['id'], $A, $B, $C, $D, $AA, $BB));
check('A sees A + AA only', $ids($A) === $want($A, $AA));
check('B sees B + BB only', $ids($B) === $want($B, $BB));
check('C sees only C (same tier, not yours, not others)', $ids($C) === $want($C));
check('AA sees only AA', $ids($AA) === $want($AA));
$map = load_member_map($pdo);
check('A cannot view BB', !can_view_owner($map, $A, $BB));
check('B cannot view AA', !can_view_owner($map, $B, $AA));
check('A cannot view you (upline)', !can_view_owner($map, $A, $me['id']));
check('you can view AA (indirect)', can_view_owner($map, $me['id'], $AA));
check('deeper chain: AAA under AA visible to A and you', (function () use ($mk, $pdo, $AA, $A, $me) { $x = $mk('AAA', $AA); $m = load_member_map($pdo); return can_view_owner($m, $A, $x) && can_view_owner($m, $me['id'], $x); })());

echo "\nMembers management\n";
throws('cycle: you under AA', 422, fn() => update_member($pdo, $me['id'], $me['id'], ['upline_id' => $AA]));
throws('cycle: A under itself', 422, fn() => update_member($pdo, $me['id'], $A, ['upline_id' => $A]));
throws('cannot deactivate yourself', 422, fn() => update_member($pdo, $me['id'], $me['id'], ['is_active' => false]));
throws('cannot demote the last admin', 422, fn() => update_member($pdo, $A, $me['id'], ['is_admin' => false]));
throws('duplicate email', 409, fn() => create_member($pdo, $me['id'], ['email' => 'A@X.com', 'name' => 'Dup']));
throws('bad rank', 422, fn() => create_member($pdo, $me['id'], ['email' => 'r@x.com', 'name' => 'R', 'rank' => 'ceo']));
update_member($pdo, $me['id'], $B, ['is_active' => false, 'move_downline_to' => $C]);
check('Fix 11: deactivated B still in table, flagged inactive', (int)load_member_map($pdo)[$B]['is_active'] === 0 && load_member_map($pdo)[$B]['deactivated_at'] !== null);
check('Fix 11: BB moved to C; you still see BB', in_array($BB, $ids($me['id']), true) && in_array($BB, $ids($C), true));
check('Fix 11: B no longer sees BB after move', !in_array($BB, $ids($B), true));
check('referral codes auto-generated and unique', count(array_unique(array_column(load_member_map($pdo), 'referral_code'))) === count(load_member_map($pdo)));
check('audit log recorded changes', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='member.update'")->fetchColumn() === 1);

echo "\nMember gate (Cloudflare login -> members table)\n";
$tok = fn(string $email) => mint($key, ['email' => $email] + $good);
check('registered member passes', authenticate_member($pdo, $cf, $tok('A@x.com'))['id'] === $A);
throws('valid Cloudflare login but not a member', 403, fn() => authenticate_member($pdo, $cf, $tok('stranger@x.com')));
throws('deactivated member', 403, fn() => authenticate_member($pdo, $cf, $tok('b@x.com')));
throws('no token at all', 401, fn() => authenticate_member($pdo, $cf, null));
throws('non-admin hits admin action', 403, fn() => require_admin(authenticate_member($pdo, $cf, $tok('c@x.com'))));
check('admin passes admin action', (function () use ($pdo, $cf, $tok) { require_admin(authenticate_member($pdo, $cf, $tok('you@x.com'))); return true; })());

echo "\nCross-site write protection\n";
$cfg = ['allowed_origins' => ['dreamhub-e3.com']];
$post = ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'fetch', 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://dreamhub-e3.com'];
check('GET needs nothing', (function () use ($cfg) { require_same_site_write($cfg, ['REQUEST_METHOD' => 'GET']); return true; })());
check('proper same-site POST allowed', (function () use ($cfg, $post) { require_same_site_write($cfg, $post); return true; })());
throws('POST without custom header', 403, fn() => require_same_site_write($cfg, array_diff_key($post, ['HTTP_X_REQUESTED_WITH' => 1])));
throws('POST as form (CSRF style)', 415, fn() => require_same_site_write($cfg, ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'] + $post));
throws('POST from another site', 403, fn() => require_same_site_write($cfg, ['HTTP_ORIGIN' => 'https://evil.com'] + $post));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
