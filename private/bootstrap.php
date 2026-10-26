<?php
declare(strict_types=1);

/**
 * Shared start-up for every /members/api/* endpoint. Returns [config, pdo, member].
 * Anything that fails here ends the request with a JSON error - an endpoint file
 * can only run its own code once the caller is a verified, active member.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';

function boot_member_request(bool $allowUpload = false): array
{
    try {
        $config = load_config();
        require_same_site_write($config, $_SERVER, $allowUpload);
        $pdo = connect_db($config);
        $cf = new CfAccess(
            $config['cf_access']['team_domain'],
            $config['cf_access']['aud'],
            CfAccess::httpJwksProvider($config['cf_access']['team_domain'], $config['cf_access']['cache_dir'], $config['cf_access']['jwks_url'] ?? null)
        );
        $member = authenticate_member($pdo, $cf, $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? null);
        return [$config, $pdo, $member];
    } catch (HttpError $e) {
        json_response($e->status, ['error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('members bootstrap: ' . $e->getMessage());
        json_response(500, ['error' => 'Server error.']);
    }
}
