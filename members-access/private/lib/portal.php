<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';

/**
 * Client Portal on/off. The portal keeps its own record (e3-client-portal/agents.json) and the Team page already
 * flips it through the bridge file. Members & Access uses the SAME bridge functions, so the two screens can never
 * disagree. If the bridge file is missing or broken, the Client Portal switch is simply unavailable; nothing else breaks.
 */
function portal_bridge_file(): string
{
    return getenv('E3_PORTAL_BRIDGE') ?: __DIR__ . '/../../e3-client-portal/team-bridge.php';   // private/lib -> home folder
}

function portal_available(): bool
{
    $f = portal_bridge_file();
    if (!is_file($f)) {
        return false;
    }
    try {
        require_once $f;
    } catch (Throwable $e) {
        error_log('portal bridge: ' . $e->getMessage());
        return false;
    }
    return function_exists('e3pb_toggle') && function_exists('e3pb_load');
}

/** @return string[] lowercase emails switched on for the portal */
function portal_enabled_emails(): array
{
    if (!portal_available()) {
        return [];
    }
    try {
        $out = [];
        foreach (e3pb_load() as $email => $rec) {
            if (!empty($rec['enabled']) && empty($rec['removed'])) {
                $out[] = strtolower((string)$email);
            }
        }
        return $out;
    } catch (Throwable $e) {
        error_log('portal read: ' . $e->getMessage());
        return [];
    }
}

function portal_enabled(string $email): bool
{
    return in_array(strtolower(trim($email)), portal_enabled_emails(), true);
}

function portal_set(string $email, string $name, bool $on): void
{
    if (!portal_available()) {
        throw new HttpError(503, 'The Client Portal link is not installed on the server.');
    }
    e3pb_toggle($email, $name, $on);
}

/** Switch off quietly (used when someone is removed or paused); the portal keeps their data. */
function portal_off(string $email): void
{
    if (portal_available()) {
        try {
            e3pb_revoke($email);
        } catch (Throwable $e) {
            error_log('portal off: ' . $e->getMessage());
        }
    }
}
