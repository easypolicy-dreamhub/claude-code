<?php
declare(strict_types=1);

/**
 * Public: receives the client testimonial form (/share-testimonial/) and saves it as pending.
 *   POST JSON { client_name, area, rating, message, phone?, consent: true, website (must be empty), elapsed }
 *   -> 200 { ok: true }   or   4xx { error: "plain words for the client" }
 * Nothing is published here. The admin approves or rejects it later, and gets an email for each new one.
 */
require_once __DIR__ . '/../../private/bootstrap_public.php';
require_once __DIR__ . '/../../private/lib/testimonial_submissions.php';
require_once __DIR__ . '/../../private/lib/site_settings.php';

[$config, $pdo] = boot_public_request();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new HttpError(405, 'Method not allowed.');
    }
    // boot_public_request() has already refused writes from other websites (require_same_site_write).
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        throw new HttpError(415, 'Please send the form from our website.');
    }
    $raw = file_get_contents('php://input', false, null, 0, 20000);
    $in = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($in)) {
        throw new HttpError(400, 'Sorry, something went wrong. Please try again.');
    }
    $id = tsub_submit($pdo, $in);
    if ($id > 0) {
        tsub_notify_admin($pdo, $config, $id);   // email the owner; never fails the request
    }
    json_response(200, ['ok' => true]);
} catch (HttpError $e) {
    json_response($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('site-api testimonial-submit: ' . $e->getMessage());
    json_response(500, ['error' => 'Sorry, something went wrong on our side. Please try again later.']);
}
