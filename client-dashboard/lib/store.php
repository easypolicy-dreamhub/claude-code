<?php
// Storage for Client Details: one JSON file per agent, in the PRIVATE data
// folder, never under public_html. Each agent can only ever read or write
// their own file — see cd_data_path(), which is the only place a file path
// is built from the signed-in agent's id.

function cd_const(string $name, $default)
{
    return defined($name) ? constant($name) : $default;
}

function cd_fail(int $code, string $message, array $extra = [])
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

function cd_data_root(): string
{
    static $root = null;
    if ($root !== null) {
        return $root;
    }
    $dir = (string) cd_const('CD_DATA_DIR', '');
    $doc = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($dir === '') {
        if ($doc === '') {
            cd_fail(500, 'CD_DATA_DIR is not set in config.php.');
        }
        $dir = dirname(rtrim($doc, '/')) . '/e3-client-dashboard';
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        cd_fail(500, 'Cannot create the private data folder. Set CD_DATA_DIR in config.php to a writable folder above public_html.');
    }
    $real = realpath($dir);
    $docReal = $doc !== '' ? realpath($doc) : false;
    if ($real === false || ($docReal !== false && ($real === $docReal || str_starts_with($real, $docReal . DIRECTORY_SEPARATOR)))) {
        cd_fail(500, 'The Client Details data folder must be outside public_html.');
    }
    if (!is_dir("$real/members")) {
        @mkdir("$real/members", 0700, true);
    }
    if (!is_file("$real/.htaccess")) {
        @file_put_contents("$real/.htaccess", "Require all denied\n");
    }
    return $root = $real;
}

/** The ONLY place an agent id becomes a file path. Always the caller's own id. */
function cd_data_path(string $agentId): string
{
    $key = hash('sha256', $agentId);
    return cd_data_root() . '/members/' . $key . '.json';
}

function cd_read_json(string $path, $default = null)
{
    if (!is_file($path)) {
        return $default;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return $data === null ? $default : $data;
}

function cd_write_json(string $path, $data): void
{
    $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        cd_fail(500, 'Could not save your data. Check that the private folder is writable.');
    }
}
