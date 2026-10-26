<?php
declare(strict_types=1);

/**
 * A candidate's resume (members only; every call is checked against the upline tree).
 *   GET    ?candidate_id=N          download (audited)
 *   POST   multipart: candidate_id, file     the advisor adds or replaces it (for example one the candidate sent by WhatsApp)
 *   DELETE ?candidate_id=N          remove it
 */
require_once __DIR__ . '/../../../private/bootstrap.php';
require_once __DIR__ . '/../../../private/lib/candidate_access.php';

[$config, $pdo, $me] = boot_member_request(true);

try {
    $map = load_member_map($pdo);
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST' && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$_POST && !$_FILES) {
        throw new HttpError(413, 'That file is too big. The limit is 5 MB.');
    }
    $id = (int)($method === 'POST' ? ($_POST['candidate_id'] ?? 0) : ($_GET['candidate_id'] ?? 0));
    $row = $id > 0 ? load_candidate_row($pdo, $id) : null;
    if (!$row || !can_see_candidate($map, $me, (int)$row['owner_member_id'])) {
        throw new HttpError(404, 'Candidate not found.');
    }
    if ($method === 'GET') {
        $r = resume_get($pdo, $id);
        $path = $r ? resume_path($config, $r) : '';
        if (!$r || !is_file($path)) {
            throw new HttpError(404, 'No resume on file.');
        }
        audit($pdo, (int)$me['id'], 'resume.download', $id, []);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $r['name']) ?: 'resume';
        header('Content-Type: ' . $r['mime']);
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($r['name']));
        header('Content-Length: ' . (string)filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }
    if ($method === 'POST') {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            throw new HttpError(422, 'Please choose a file to upload.');
        }
        $r = resume_store($pdo, $config, $id, $file, (string)$me['id']);
        audit($pdo, (int)$me['id'], 'resume.upload', $id, []);
        json_response(200, ['resume' => ['name' => $r['name'], 'size' => $r['size'], 'at' => iso($r['created_at']), 'by_candidate' => false]]);
    }
    if ($method === 'DELETE') {
        resume_remove($pdo, $config, $id);
        audit($pdo, (int)$me['id'], 'resume.delete', $id, []);
        json_response(200, ['deleted' => true]);
    }
    throw new HttpError(405, 'Method not allowed.');
} catch (HttpError $e) {
    json_response($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('members resume: ' . $e->getMessage());
    json_response(500, ['error' => 'Server error.']);
}
