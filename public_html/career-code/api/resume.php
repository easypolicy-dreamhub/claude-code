<?php
declare(strict_types=1);

/**
 * The candidate uploads their resume from the first DISC screen. multipart/form-data fields: t (invite code), last4, file.
 * PDF / DOC / DOCX up to 5 MB; same key as the questionnaire (secret link + last 4 digits of the mobile).
 */
require_once __DIR__ . '/../../../private/bootstrap_public.php';
require_once __DIR__ . '/../../../private/lib/disc.php';

[$config, $pdo] = boot_public_request(true);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new HttpError(405, 'Method not allowed.');
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$_POST && !$_FILES) {   // bigger than the server's post_max_size: PHP drops everything
        throw new HttpError(413, 'That file is too big. The limit is 5 MB.');
    }
    $file = $_FILES['file'] ?? null;
    if (!is_array($file)) {
        throw new HttpError(422, 'Please choose a file to upload.');
    }
    $r = disc_resume_upload($pdo, $config, (string)($_POST['t'] ?? ''), (string)($_POST['last4'] ?? ''), $file);
    json_response(200, ['ok' => true] + $r);
} catch (HttpError $e) {
    json_response($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('career-code resume: ' . $e->getMessage());
    json_response(500, ['error' => 'Server error.']);
}
