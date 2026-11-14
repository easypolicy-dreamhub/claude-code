<?php
// The ONE place where the AI Coach touches the rest of the members site.
//
// Sign-in is handled by Cloudflare Zero Trust (Access), the same as the other
// toolkits (see agency-report/photos.php). Cloudflare puts a signed token on every
// request it lets through. This file checks that token's signature against your
// Cloudflare team's public keys, so the coach knows WHO is asking, and a request
// that did not come through Cloudflare (or carries a forged token) is refused.
//
// Settings live in your existing config.php (see config.sample.php):
//   COACH_CF_TEAM_DOMAIN       e.g. 'yourteam.cloudflareaccess.com'
//   COACH_CF_AUD               the Access application's AUD tag
//   COACH_SUPER_ADMIN_EMAILS   the team leader's email(s): Settings + Usage tabs
//   COACH_MEMBER_NAMES         optional: email => display name (also fills "needs a nudge")
// Copy the team domain and AUD tag from photos.php; it uses the same Access app.

// Load the site's config.php (holds ANTHROPIC_API_KEY and the settings above).
// ADJUST the path if your config.php lives somewhere else.
foreach ([
    dirname(__DIR__) . '/config.php',
] as $file) {
    if (is_file($file)) {
        require_once $file;
    }
}

/** Who is signing in. Fails closed: if anything is missing or wrong, nobody gets in. */
function coach_boot(): array
{
    $team = coach_cf_team_domain();
    $aud = defined('COACH_CF_AUD') ? trim((string) COACH_CF_AUD) : '';
    if ($team === '' || $aud === '') {
        coach_bridge_fail(503, 'Sign-in is not connected yet. Add COACH_CF_TEAM_DOMAIN and COACH_CF_AUD to config.php.');
    }
    $token = (string) ($_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? $_COOKIE['CF_Authorization'] ?? '');
    $claims = $token === '' ? null : coach_cf_verify($token, $team, $aud);
    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    if ($email === '' || strpos($email, '@') === false) {
        coach_bridge_fail(401, 'Please sign in.');
    }
    return [
        'id' => $email,
        'name' => coach_member_name($email),
        'raw' => ['email' => $email],
    ];
}

/** True only for the team leader: emails listed in COACH_SUPER_ADMIN_EMAILS. */
function coach_is_super_admin(array $member): bool
{
    $list = defined('COACH_SUPER_ADMIN_EMAILS') ? COACH_SUPER_ADMIN_EMAILS : [];
    if (is_string($list)) {
        $list = explode(',', $list);
    }
    $list = array_map(fn($e) => strtolower(trim((string) $e)), (array) $list);
    return $member['id'] !== '' && in_array($member['id'], $list, true);
}

/** Server-side gate for every upload / save / delete / restore / usage action. */
function coach_require_super_admin(array $member): void
{
    if (!coach_is_super_admin($member)) {
        coach_bridge_fail(403, 'Only the super admin can do this.');
    }
}

/**
 * Optional roster: every member as [['id'=>email, 'name'=>...], ...]. Taken from
 * COACH_MEMBER_NAMES. When filled in, the Usage tab also lists agents who never
 * used the coach ("needs a nudge").
 */
function coach_list_members(): array
{
    $out = [];
    foreach (coach_member_names() as $email => $name) {
        $out[] = ['id' => $email, 'name' => $name];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Cloudflare Access token check (RS256, keys from <team>/cdn-cgi/access/certs)
// ---------------------------------------------------------------------------

function coach_cf_team_domain(): string
{
    $t = defined('COACH_CF_TEAM_DOMAIN') ? trim((string) COACH_CF_TEAM_DOMAIN) : '';
    $t = preg_replace('#^https?://#i', '', $t);
    return strtolower(rtrim((string) $t, '/'));
}

/** Returns the token's claims if it is genuine, current and meant for this app; otherwise null. */
function coach_cf_verify(string $jwt, string $team, string $aud): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }
    $header = json_decode(coach_b64url($parts[0]), true);
    $claims = json_decode(coach_b64url($parts[1]), true);
    $sig = coach_b64url($parts[2]);
    if (!is_array($header) || !is_array($claims) || $sig === '' || ($header['alg'] ?? '') !== 'RS256') {
        return null;
    }
    $kid = (string) ($header['kid'] ?? '');
    $cert = coach_cf_cert($team, $kid, false) ?? coach_cf_cert($team, $kid, true); // refetch once if keys rotated
    if ($cert === null || openssl_verify($parts[0] . '.' . $parts[1], $sig, $cert, OPENSSL_ALGO_SHA256) !== 1) {
        return null;
    }
    $now = time();
    $leeway = 60;
    $auds = (array) ($claims['aud'] ?? []);
    if (!in_array($aud, $auds, true)) {
        return null;
    }
    if (rtrim((string) ($claims['iss'] ?? ''), '/') !== 'https://' . $team) {
        return null;
    }
    if (!isset($claims['exp']) || (int) $claims['exp'] < $now - $leeway) {
        return null;
    }
    if (isset($claims['nbf']) && (int) $claims['nbf'] > $now + $leeway) {
        return null;
    }
    return $claims;
}

/** The PEM certificate for a key id. Cached for an hour in the private data folder. */
function coach_cf_cert(string $team, string $kid, bool $refresh): ?string
{
    if ($kid === '') {
        return null;
    }
    $cacheFile = coach_cf_cache_file();
    if ($refresh && $cacheFile && is_file($cacheFile) && filemtime($cacheFile) > time() - 60) {
        return null; // keys were fetched under a minute ago: a made-up key id must not trigger endless downloads
    }
    $keys = null;
    if (!$refresh && $cacheFile && is_file($cacheFile) && filemtime($cacheFile) > time() - 3600) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['team'] ?? '') === $team) {
            $keys = $cached['keys'] ?? null;
        }
    }
    if (!is_array($keys)) {
        static $fetched = [];
        if (isset($fetched[$team])) {
            $keys = $fetched[$team];
        } else {
            $keys = coach_cf_fetch_certs($team);
            if ($keys === null) {
                return null;
            }
            $fetched[$team] = $keys;
            if ($cacheFile) {
                @file_put_contents($cacheFile, json_encode(['team' => $team, 'keys' => $keys]), LOCK_EX);
            }
        }
    }
    return $keys[$kid] ?? null;
}

/** Download Cloudflare's public certificates as [kid => PEM]. */
function coach_cf_fetch_certs(string $team): ?array
{
    $url = defined('COACH_CF_CERTS_URL') ? (string) COACH_CF_CERTS_URL : "https://$team/cdn-cgi/access/certs";
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = $code === 200 ? json_decode((string) $raw, true) : null;
    if (!is_array($data)) {
        return null;
    }
    $keys = [];
    foreach ((array) ($data['public_certs'] ?? []) as $c) {
        if (!empty($c['kid']) && !empty($c['cert'])) {
            $keys[(string) $c['kid']] = (string) $c['cert'];
        }
    }
    return $keys ?: null;
}

/** Cloudflare's keys are public, so the system temp folder is fine (and touches nothing else). */
function coach_cf_cache_file(): ?string
{
    if (defined('COACH_CF_CACHE_FILE')) {
        return (string) COACH_CF_CACHE_FILE;
    }
    $dir = sys_get_temp_dir();
    return is_dir($dir) && is_writable($dir) ? rtrim($dir, '/') . '/e3-coach-cf-certs-' . md5(__DIR__) . '.json' : null;
}

function coach_b64url(string $s): string
{
    $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    return $d === false ? '' : $d;
}

// ---------------------------------------------------------------------------
// Names
// ---------------------------------------------------------------------------

function coach_member_names(): array
{
    $out = [];
    foreach ((defined('COACH_MEMBER_NAMES') ? (array) COACH_MEMBER_NAMES : []) as $email => $name) {
        $out[strtolower(trim((string) $email))] = trim((string) $name);
    }
    return $out;
}

/** Display name: from COACH_MEMBER_NAMES, else made from the email ("alan.tan@x" -> "Alan Tan"). */
function coach_member_name(string $email): string
{
    $names = coach_member_names();
    if (!empty($names[$email])) {
        return $names[$email];
    }
    $local = preg_replace('/[._\-+]+/', ' ', explode('@', $email)[0]);
    return ucwords(trim((string) $local)) ?: $email;
}

function coach_bridge_fail(int $code, string $message)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $message]);
    exit;
}
