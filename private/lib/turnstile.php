<?php
declare(strict_types=1);

/**
 * Cloudflare Turnstile: the free "are you a person" check on the public form.
 * @param ?callable(string $url, array $fields): ?array $http injectable for tests
 */
function verify_turnstile(array $config, string $token, ?string $ip, ?callable $http = null): bool
{
    $cfg = $config['turnstile'] ?? [];
    $secret = (string)($cfg['secret'] ?? '');
    if ($secret === '' || $token === '' || strlen($token) > 2048) {
        return false;
    }
    $url = (string)($cfg['verify_url'] ?? 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
    $fields = ['secret' => $secret, 'response' => $token] + ($ip ? ['remoteip' => $ip] : []);
    $res = $http ? $http($url, $fields) : turnstile_http($url, $fields);
    return is_array($res) && ($res['success'] ?? false) === true;
}

function turnstile_http(string $url, array $fields): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $doc = is_string($body) ? json_decode($body, true) : null;
    return is_array($doc) ? $doc : null;
}
