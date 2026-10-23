<?php
declare(strict_types=1);

/** POST: submit_token + answers -> server-calculated { scores, code, advisor_name }; emails go out here. */
require_once __DIR__ . '/../../../private/bootstrap_public.php';

[$config, $pdo] = boot_public_request();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new HttpError(405, 'Method not allowed.');
    }
    $in = json_body();
    $r = submit_assessment($pdo, (string)($in['submit_token'] ?? ''), $in['answers'] ?? null);
    if (!$r['already']) {
        notify_completion($pdo, $config, make_mailer($config), $r['candidate'], $r['scores'], $r['code']);
    }
    json_response(200, ['scores' => $r['scores'], 'code' => $r['code'], 'advisor_name' => $r['advisor_name']]);
} catch (HttpError $e) {
    json_response($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('career-code submit: ' . $e->getMessage());
    json_response(500, ['error' => 'Server error.']);
}
