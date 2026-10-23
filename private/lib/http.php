<?php
declare(strict_types=1);

final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

function json_response(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Parsed JSON body of a state-changing request. */
function json_body(): array
{
    $raw = file_get_contents('php://input', false, null, 0, 262144); // 256 KB cap
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new HttpError(400, 'Request body must be a JSON object.');
    }
    return $data;
}

/**
 * Cloudflare Access forwards the login cookie on cross-site requests too, so a
 * malicious page could make a logged-in member's browser POST to us. Block that:
 * state-changing requests must be JSON, carry a custom header and, when the
 * browser sends an Origin, come from one of our own hostnames.
 */
function require_same_site_write(array $config, array $server): void
{
    $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    if (($server['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
        throw new HttpError(403, 'Missing X-Requested-With header.');
    }
    if (stripos($server['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        throw new HttpError(415, 'Content-Type must be application/json.');
    }
    $origin = $server['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $host = parse_url($origin, PHP_URL_HOST);
        if (!is_string($host) || !in_array(strtolower($host), $config['allowed_origins'] ?? [], true)) {
            throw new HttpError(403, 'Origin not allowed.');
        }
    }
}
