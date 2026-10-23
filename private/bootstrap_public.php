<?php
declare(strict_types=1);

/** Start-up for the PUBLIC /career-code/api/* endpoints (no Cloudflare login, protected by Turnstile + rate limit). */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/candidates.php';
require_once __DIR__ . '/lib/turnstile.php';

function boot_public_request(): array
{
    try {
        $config = load_config();
        require_same_site_write($config, $_SERVER);
        return [$config, connect_db($config)];
    } catch (HttpError $e) {
        json_response($e->status, ['error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('public bootstrap: ' . $e->getMessage());
        json_response(500, ['error' => 'Server error.']);
    }
}

/** The visitor's address as Cloudflare saw it (used for Turnstile and the rate limit only). */
function client_ip(): ?string
{
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}
