<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('The HNW Coach needs PHP 8.0 or newer (cPanel > Select PHP Version).');
}

require_once dirname(__DIR__) . '/site-bridge.php'; // config.php + member login + admin check
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/gemini.php';

/** Every POST must come from our own page: custom header (cannot be sent cross-site), JSON body, same-origin check.
 *  The members system checks the same things first; this is a second lock. */
function coach_check_csrf(): void
{
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch' || stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        coach_fail(403, 'Bad request.');
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $host = parse_url($origin, PHP_URL_HOST) . (($p = parse_url($origin, PHP_URL_PORT)) ? ':' . $p : '');
        if (strcasecmp($host, (string) ($_SERVER['HTTP_HOST'] ?? '')) !== 0) {
            coach_fail(403, 'Bad request.');
        }
    }
}
