<?php
declare(strict_types=1);

/** POST: candidate details + consent + Turnstile token -> { submit_token, advisor_name } */
require_once __DIR__ . '/../../../private/bootstrap_public.php';

[$config, $pdo] = boot_public_request();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new HttpError(405, 'Method not allowed.');
    }
    $in = json_body();
    if (!verify_turnstile($config, (string)($in['turnstile'] ?? ''), client_ip())) {
        throw new HttpError(403, 'Spam check failed. Please refresh the page and try again.');
    }
    $r = start_candidate($pdo, $config, $in, client_ip());
    json_response(200, ['submit_token' => $r['submit_token'], 'advisor_name' => $r['advisor_name']]);
} catch (HttpError $e) {
    json_response($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('career-code start: ' . $e->getMessage());
    json_response(500, ['error' => 'Server error.']);
}
