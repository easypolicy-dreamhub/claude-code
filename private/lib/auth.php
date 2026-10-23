<?php
declare(strict_types=1);

require_once __DIR__ . '/cf_access.php';
require_once __DIR__ . '/members.php';

/**
 * Cloudflare answers "who are you" (and the token proves it); this table answers
 * "what may you do". A valid Cloudflare login for an email that is not an active
 * member gets a 403, so a slip in the Cloudflare allow-list cannot leak data.
 *
 * @return array the member row (id, email, name, rank_label, upline_id, is_admin, ...)
 */
function authenticate_member(PDO $pdo, CfAccess $cf, ?string $jwt): array
{
    $claims = $cf->verify($jwt);
    $stmt = $pdo->prepare('SELECT * FROM members WHERE email = ?');
    $stmt->execute([$claims['email']]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        throw new HttpError(403, 'Your email is not registered as a member. Please contact your admin.');
    }
    if (!(int)$m['is_active']) {
        throw new HttpError(403, 'Your membership is inactive. Please contact your admin.');
    }
    $m['id'] = (int)$m['id'];
    $m['upline_id'] = $m['upline_id'] === null ? null : (int)$m['upline_id'];
    $m['is_admin'] = (int)$m['is_admin'];
    return $m;
}

function require_admin(array $member): void
{
    if (!$member['is_admin']) {
        throw new HttpError(403, 'Admins only.');
    }
}
