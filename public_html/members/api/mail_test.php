<?php
declare(strict_types=1);

/**
 * Super admin only. Open this address in your browser (while logged in) to send a test email to your own
 * login address using the settings in config.php, and see exactly what happened.
 */
require_once __DIR__ . '/../../../private/bootstrap.php';
require_once __DIR__ . '/../../../private/lib/mailer.php';

[$config, $pdo, $me] = boot_member_request();

try {
    require_admin($me);
    json_response(200, run_mail_test($config, make_mailer($config), (string)$me['email']));
} catch (HttpError $e) {
    json_response($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('mail_test.php: ' . $e->getMessage());
    json_response(500, ['error' => 'Server error.']);
}
