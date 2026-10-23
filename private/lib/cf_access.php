<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';

/**
 * Verifies the signed token Cloudflare Access adds to every request it lets through
 * (header Cf-Access-Jwt-Assertion). Without this, anyone who finds the cPanel
 * server's real address could send a forged "I am admin@..." header and skip the login.
 */
final class CfAccess
{
    private const LEEWAY = 30; // seconds of clock drift tolerated

    /** @param callable(): array $jwksProvider returns the decoded {"keys":[...]} document */
    public function __construct(
        private readonly string $teamDomain,
        private readonly string $aud,
        private readonly \Closure $jwksProvider,
        private readonly ?\Closure $now = null,
    ) {
    }

    /** @return array verified claims (always contains a lower-cased 'email') */
    public function verify(?string $jwt): array
    {
        if ($jwt === null || $jwt === '') {
            throw new HttpError(401, 'Login required.');
        }
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new HttpError(401, 'Malformed token.');
        }
        [$h64, $p64, $s64] = $parts;
        $header = json_decode(self::b64url($h64) ?? '', true);
        $claims = json_decode(self::b64url($p64) ?? '', true);
        $sig    = self::b64url($s64);
        if (!is_array($header) || !is_array($claims) || $sig === null || $sig === '') {
            throw new HttpError(401, 'Malformed token.');
        }
        // Pin the algorithm: never trust the token to pick "none" or HS256.
        if (($header['alg'] ?? '') !== 'RS256' || !isset($header['kid']) || !is_string($header['kid'])) {
            throw new HttpError(401, 'Unsupported token.');
        }

        $jwk = $this->findKey($header['kid']);
        $ok  = openssl_verify("$h64.$p64", $sig, self::jwkToPem($jwk), OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new HttpError(401, 'Invalid token signature.');
        }

        $now = ($this->now ?? static fn (): int => time())();
        if (!isset($claims['exp']) || !is_numeric($claims['exp']) || $now > (int)$claims['exp'] + self::LEEWAY) {
            throw new HttpError(401, 'Session expired.');
        }
        if (isset($claims['nbf']) && is_numeric($claims['nbf']) && $now + self::LEEWAY < (int)$claims['nbf']) {
            throw new HttpError(401, 'Token not yet valid.');
        }
        if (($claims['iss'] ?? '') !== 'https://' . $this->teamDomain) {
            throw new HttpError(401, 'Wrong token issuer.');
        }
        $aud = $claims['aud'] ?? [];
        if (!in_array($this->aud, is_array($aud) ? $aud : [$aud], true)) {
            throw new HttpError(401, 'Token is for a different application.');
        }
        $email = $claims['email'] ?? '';
        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError(401, 'Token has no email.');
        }
        $claims['email'] = strtolower($email);
        return $claims;
    }

    private function findKey(string $kid): array
    {
        // Cloudflare rotates keys; the provider may serve a cache, so refetch once on a miss.
        foreach ([false, true] as $refresh) {
            $doc = ($this->jwksProvider)($refresh);
            foreach ($doc['keys'] ?? [] as $k) {
                if (($k['kid'] ?? null) === $kid && ($k['kty'] ?? '') === 'RSA' && isset($k['n'], $k['e'])) {
                    return $k;
                }
            }
        }
        throw new HttpError(401, 'Unknown signing key.');
    }

    public static function b64url(string $s): ?string
    {
        if ($s === '' || preg_match('/^[A-Za-z0-9_-]+$/', $s) !== 1) {
            return null;
        }
        $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $d === false ? null : $d;
    }

    /** RSA JWK -> PEM public key (builds the DER by hand; no composer needed on cPanel). */
    public static function jwkToPem(array $jwk): string
    {
        $n = self::b64url((string)$jwk['n']);
        $e = self::b64url((string)$jwk['e']);
        if ($n === null || $e === null) {
            throw new HttpError(401, 'Bad signing key.');
        }
        $rsaKey = self::derSeq(self::derInt($n) . self::derInt($e));
        $algId  = self::derSeq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00"); // rsaEncryption, NULL
        $bits   = "\x00" . $rsaKey;
        $spki   = self::derSeq($algId . "\x03" . self::derLen(strlen($bits)) . $bits);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derLen(int $l): string
    {
        if ($l < 128) {
            return chr($l);
        }
        $b = ltrim(pack('N', $l), "\0");
        return chr(0x80 | strlen($b)) . $b;
    }

    private static function derInt(string $bin): string
    {
        $bin = ltrim($bin, "\0");
        if ($bin === '' || (ord($bin[0]) & 0x80)) {
            $bin = "\0" . $bin;
        }
        return "\x02" . self::derLen(strlen($bin)) . $bin;
    }

    private static function derSeq(string $content): string
    {
        return "\x30" . self::derLen(strlen($content)) . $content;
    }

    /** Production key source: Cloudflare's public certs, cached on disk for an hour. */
    public static function httpJwksProvider(string $teamDomain, string $cacheDir, ?string $url = null): \Closure
    {
        return static function (bool $forceRefresh = false) use ($teamDomain, $cacheDir, $url): array {
            $file = rtrim($cacheDir, '/') . '/cf_jwks.json';
            if (!$forceRefresh && is_file($file) && filemtime($file) > time() - 3600) {
                $cached = json_decode((string)file_get_contents($file), true);
                if (is_array($cached) && isset($cached['keys'])) {
                    return $cached;
                }
            }
            $ch = curl_init($url ?? "https://$teamDomain/cdn-cgi/access/certs");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $doc = is_string($body) ? json_decode($body, true) : null;
            if ($code !== 200 || !is_array($doc) || !isset($doc['keys'])) {
                // Fall back to a stale cache rather than lock everyone out during a Cloudflare blip.
                $stale = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
                if (is_array($stale) && isset($stale['keys'])) {
                    return $stale;
                }
                throw new HttpError(503, 'Cannot reach login service.');
            }
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0700, true);
            }
            @file_put_contents($file, json_encode($doc), LOCK_EX);
            return $doc;
        };
    }
}
