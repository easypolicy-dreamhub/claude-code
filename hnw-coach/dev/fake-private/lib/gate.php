<?php
// LOCAL TESTING ONLY. Stand-in for private/lib/gate.php (boot_member_page, render_denied, HttpError).
if (!class_exists('HttpError')) {
    class HttpError extends Exception { public int $status; public function __construct(int $s, string $m) { parent::__construct($m); $this->status = $s; } }
}
function boot_member_page(): array
{
    $u = dev_users()[strtolower($_SERVER['HTTP_X_DEV_USER'] ?? '')] ?? null;
    if (!$u) throw new HttpError(401, 'Please sign in.');
    if (!$u['is_active']) throw new HttpError(403, 'Your membership is inactive. Please contact your admin.');
    return [[], null, $u];
}
function render_denied(int $status, string $headline, string $detail, array $config = []): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>No access | DreamHub E3</title></head><body><div class="card" role="alert"><h1>' . htmlspecialchars($headline) . '</h1><p>' . htmlspecialchars($detail) . '</p><a href="/members/">Back to toolkit</a></div></body></html>';
    exit;
}
