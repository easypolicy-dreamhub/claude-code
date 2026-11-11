<?php
declare(strict_types=1);

/**
 * Super admin only: manage the client testimonials shown on the website.
 *   GET                                                       -> { testimonials: [...], home_limit, message_max }
 *   POST {action:'create',  message, client_name, area, rating, consent_confirmed, is_visible, on_homepage} -> { id }
 *   POST {action:'update',  id, ...same fields }              -> { ok:true }
 *   POST {action:'delete',  id}                               -> { ok:true }
 *   POST {action:'reorder', ids:[...]}                        -> { ok:true }
 * Client submissions from /share-testimonial/ (waiting for approval):
 *   GET also returns  pending: [...]  (newest first; includes the client's private phone number)
 *   POST {action:'approve', id, message?, client_name?, area?, rating?, is_visible?, on_homepage?} -> { id }  (the new testimonial)
 *   POST {action:'reject',  id}                               -> { ok:true }
 */
require_once __DIR__ . '/../../../private/bootstrap.php';
require_once __DIR__ . '/../../../private/lib/testimonials.php';
require_once __DIR__ . '/../../../private/lib/testimonial_submissions.php';

[$config, $pdo, $me] = boot_member_request();

try {
    require_admin($me);
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        json_response(200, ['testimonials' => testi_list_all($pdo), 'home_limit' => TESTI_HOME_LIMIT, 'message_max' => TESTI_MESSAGE_MAX,
            'pending' => tsub_list_pending($pdo)]);
    }
    if ($method !== 'POST') {
        throw new HttpError(405, 'Method not allowed.');
    }
    $in = json_body();
    $id = isset($in['id']) && is_int($in['id']) ? $in['id'] : 0;
    switch ((string)($in['action'] ?? '')) {
        case 'create':
            json_response(200, ['id' => testi_create($pdo, testi_validate($in), (int)$me['id'])]);
        case 'update':
            if ($id < 1) {
                throw new HttpError(422, 'Missing testimonial.');
            }
            testi_update($pdo, $id, testi_validate($in), (int)$me['id']);
            json_response(200, ['ok' => true]);
        case 'delete':
            if ($id < 1) {
                throw new HttpError(422, 'Missing testimonial.');
            }
            testi_delete($pdo, $id, (int)$me['id']);
            json_response(200, ['ok' => true]);
        case 'reorder':
            testi_reorder($pdo, $in['ids'] ?? null, (int)$me['id']);
            json_response(200, ['ok' => true]);
        case 'approve':
            if ($id < 1) {
                throw new HttpError(422, 'Missing submission.');
            }
            json_response(200, ['id' => tsub_approve($pdo, $id, $in, (int)$me['id'])]);
        case 'reject':
            if ($id < 1) {
                throw new HttpError(422, 'Missing submission.');
            }
            tsub_reject($pdo, $id, (int)$me['id']);
            json_response(200, ['ok' => true]);
        default:
            throw new HttpError(400, 'Unknown action.');
    }
} catch (HttpError $e) {
    json_response($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('testimonials.php: ' . $e->getMessage());
    json_response(500, ['error' => 'Server error.']);
}
