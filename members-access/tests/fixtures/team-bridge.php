<?php
declare(strict_types=1);

// Copy of the live e3-client-portal/team-bridge.php, used only by the tests.
// Bridge between the Team page (public_html/members/api/team.php) and the Client Portal.

function e3pb_file(): string
{
    return __DIR__ . '/agents.json';
}

/** @return array<string, array> */
function e3pb_load(): array
{
    $f = e3pb_file();
    $a = is_file($f) ? json_decode((string) file_get_contents($f), true) : [];
    return is_array($a['members'] ?? null) ? $a['members'] : [];
}

function e3pb_save(array $members): void
{
    ksort($members);
    $tmp = e3pb_file() . '.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode(['members' => $members], JSON_PRETTY_PRINT));
    @chmod($tmp, 0600);
    rename($tmp, e3pb_file());
}

function e3pb_owner(): string
{
    if (!defined('PORTAL_OWNER') && is_file(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
    }
    return defined('PORTAL_OWNER') ? strtolower(trim((string) PORTAL_OWNER)) : '';
}

function e3pb_email(string $e): string
{
    return strtolower(trim($e));
}

function e3pb_toggle(string $email, string $name, bool $on): void
{
    $email = e3pb_email($email);
    if ($email === '' || ($email === e3pb_owner())) {
        return; // the owner is always on
    }
    $members = e3pb_load();
    if (!isset($members[$email])) {
        $members[$email] = ['enabled' => false, 'name' => trim($name), 'added' => date('Y-m-d'), 'uid' => bin2hex(random_bytes(10))];
    }
    unset($members[$email]['removed']);
    $members[$email]['enabled'] = $on;
    if (empty($members[$email]['uid'])) {
        $members[$email]['uid'] = bin2hex(random_bytes(10));
    }
    e3pb_save($members);
}

function e3pb_revoke(string $email): void
{
    $email = e3pb_email($email);
    $members = e3pb_load();
    if ($email !== '' && isset($members[$email]) && !empty($members[$email]['enabled'])) {
        $members[$email]['enabled'] = false; // the record and the data stay, so reactivating later gives it all back
        e3pb_save($members);
    }
}
