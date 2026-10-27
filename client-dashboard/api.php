<?php
declare(strict_types=1);

// Client Details API. Every request is tied to the signed-in agent (from the
// site's own login via site-bridge.php) and can only ever touch that agent's
// own file. There is no endpoint that lists or reads another agent's data.

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('Client Details needs PHP 8.0 or newer (cPanel > Select PHP Version).');
}

require_once __DIR__ . '/site-bridge.php';
require_once __DIR__ . '/lib/store.php';

const CD_MAX_UPLOAD_BYTES = 12 * 1024 * 1024; // the update file for ~1,500 policies is well under this

/** Every POST must come from our own page: custom header (cannot be sent cross-site) + same-origin check. */
function cd_check_csrf(): void
{
    if (($_SERVER['HTTP_X_CD_REQUEST'] ?? '') !== '1') {
        cd_fail(403, 'Bad request.');
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $host = parse_url($origin, PHP_URL_HOST) . (($p = parse_url($origin, PHP_URL_PORT)) ? ':' . $p : '');
        if (strcasecmp((string) $host, (string) ($_SERVER['HTTP_HOST'] ?? '')) !== 0) {
            cd_fail(403, 'Bad request.');
        }
    }
}

header('Cache-Control: no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');

$member = cd_boot(); // fails closed with 401 if not signed in
$action = $_GET['action'] ?? ($_SERVER['REQUEST_METHOD'] === 'GET' ? 'get' : '');

switch ($action) {

    case 'get': {
        $path = cd_data_path($member['id']);
        $data = cd_read_json($path, null);
        header('Content-Type: application/json; charset=utf-8');
        if ($data === null) {
            echo json_encode(['hasData' => false]);
            exit;
        }
        echo json_encode(['hasData' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    case 'upload': {
        cd_check_csrf();
        $raw = file_get_contents('php://input', false, null, 0, CD_MAX_UPLOAD_BYTES + 1);
        if ($raw === false || $raw === '') {
            cd_fail(400, 'No file content received.');
        }
        if (strlen($raw) > CD_MAX_UPLOAD_BYTES) {
            cd_fail(413, 'That file is larger than expected for a policy listing update. Nothing was saved.');
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            cd_fail(400, 'That does not look like a GE Refresh update file.');
        }
        // Shape check only — same file your GE Refresh bookmark downloads.
        foreach (['list'] as $req) {
            if (!array_key_exists($req, $json)) {
                cd_fail(400, 'That file does not look like a GE Refresh update file (missing "' . $req . '").');
            }
        }
        if (!is_array($json['list'])) {
            cd_fail(400, 'That file does not look like a GE Refresh update file.');
        }

        $record = [
            'ownerId' => $member['id'],
            'ownerName' => $member['name'],
            'uploadedAt' => date('c'),
            'asOf' => $json['asOf'] ?? null,
            'agent' => $json['agent'] ?? null,
            'extracted' => $json['extracted'] ?? null,
            'extractedAt' => $json['extractedAt'] ?? null,
            'list' => $json['list'],
            'det' => $json['det'] ?? [],
        ];

        $path = cd_data_path($member['id']);
        cd_write_json($path, $record);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'policies' => count($record['list']), 'uploadedAt' => $record['uploadedAt']]);
        exit;
    }

    case 'delete': {
        cd_check_csrf();
        $path = cd_data_path($member['id']);
        if (is_file($path)) {
            @unlink($path);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    default:
        cd_fail(404, 'Unknown action.');
}
