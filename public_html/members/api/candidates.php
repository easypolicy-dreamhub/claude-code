<?php
declare(strict_types=1);

/**
 * Career Code candidates (members only; every call is checked against the upline tree).
 *   GET                       list   ?status= &purpose= &q= &mine=1 &owner_id= &include_unfinished=1 &page=
 *   GET  ?id=N [&lang=en|zh]  one candidate with results and advisor notes
 *   GET  ?export=csv          admin: everything as CSV
 *   PATCH ?id=N {pipeline_status?, owner_member_id?, purpose?}
 *   DELETE ?id=N              admin: erase a candidate (PDPA request)
 */
require_once __DIR__ . '/../../../private/bootstrap.php';
require_once __DIR__ . '/../../../private/lib/candidate_access.php';

[$config, $pdo, $me] = boot_member_request();

try {
    $map = load_member_map($pdo);
    $method = $_SERVER['REQUEST_METHOD'];
    $id = (int)($_GET['id'] ?? 0);

    if ($method === 'GET') {
        if (($_GET['export'] ?? '') === 'csv') {
            $csv = export_candidates_csv($pdo, $map, $me);
            audit($pdo, $me['id'], 'candidate.export', null, []);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="career-code-candidates-' . gmdate('Y-m-d') . '.csv"');
            header('Cache-Control: no-store');
            echo $csv;
            exit;
        }
        if ($id > 0) {
            json_response(200, ['candidate' => get_candidate($pdo, $map, $me, $id, ($_GET['lang'] ?? 'en') === 'zh' ? 'zh' : 'en')]);
        }
        $r = list_candidates($pdo, $map, $me, [
            'status' => $_GET['status'] ?? '', 'purpose' => $_GET['purpose'] ?? '', 'q' => $_GET['q'] ?? '',
            'mine' => ($_GET['mine'] ?? '') === '1', 'owner_id' => (int)($_GET['owner_id'] ?? 0),
            'include_unfinished' => ($_GET['include_unfinished'] ?? '') === '1', 'page' => (int)($_GET['page'] ?? 1),
        ]);
        json_response(200, $r + ['viewer' => ['id' => $me['id'], 'name' => $me['name'], 'is_admin' => (bool)$me['is_admin'], 'rank' => $me['rank_label']]]);
    }
    if ($id <= 0) {
        throw new HttpError(400, 'Missing ?id=');
    }
    if ($method === 'PATCH') {
        update_candidate($pdo, $map, $me, $id, json_body());
        json_response(200, ['candidate' => get_candidate($pdo, $map, $me, $id)]);
    }
    if ($method === 'DELETE') {
        delete_candidate($pdo, $map, $me, $id);
        json_response(200, ['deleted' => true]);
    }
    throw new HttpError(405, 'Method not allowed.');
} catch (HttpError $e) {
    json_response($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('candidates.php: ' . $e->getMessage());
    json_response(500, ['error' => 'Server error.']);
}
