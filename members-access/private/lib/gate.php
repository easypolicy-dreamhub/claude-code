<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tools.php';

/**
 * The page gate: every tool page under /members/ is opened through members/gate.php (see members/.htaccess),
 * which checks the signed-in person against their ticks BEFORE the page is sent. Typing the address directly gets the
 * same answer as the toolkit page: the standard "no access" page below.
 */

/** Same checks as boot_member_request(), but throws instead of printing JSON so we can show a proper page. */
function boot_member_page(): array
{
    $config = load_config();
    $pdo = connect_db($config);
    $cf = new CfAccess(
        $config['cf_access']['team_domain'],
        $config['cf_access']['aud'],
        CfAccess::httpJwksProvider($config['cf_access']['team_domain'], $config['cf_access']['cache_dir'], $config['cf_access']['jwks_url'] ?? null)
    );
    $member = authenticate_member($pdo, $cf, $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? null);
    return [$config, $pdo, $member];
}

/**
 * @param string $membersDir absolute path of public_html/members
 * @param string $rel        requested path under /members/, e.g. "team/" or "career-code/dashboard/index.html"
 * @return array{tool:array,file:string,kind:string}|null  null = not a gated tool page (answer 404)
 */
function gate_resolve(string $membersDir, string $rel): ?array
{
    if ($rel === '' || str_contains($rel, "\0") || str_contains($rel, '\\') || preg_match('#(^|/)\.\.?(/|$)#', $rel)) {
        return null;
    }
    $rel = ltrim($rel, '/');
    if ($rel === '' || str_ends_with($rel, '/')) {
        $rel .= 'index.html';
    }
    if (!preg_match('/\.html?$/i', $rel)) {
        return null;
    }
    $tool = tool_for_path($rel);
    if ($tool === null) {
        return null;
    }
    $root = realpath($membersDir);
    if ($root === false) {
        return null;
    }
    $file = realpath($root . '/' . $rel);
    $kind = 'html';
    if ($file === false && preg_match('#(^|/)index\.html$#', $rel)) {
        $php = realpath($root . '/' . preg_replace('#index\.html$#', 'index.php', $rel));   // a tool whose front page is PHP
        if ($php !== false) {
            $file = $php;
            $kind = 'php';
        }
    }
    if ($file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
        return null;
    }
    return ['tool' => $tool, 'file' => $file, 'kind' => $kind];
}

/** The one standard page for "you may not open this", used by every tool. Ends the request. */
function render_denied(int $status, string $headline, string $detail, array $config = []): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $wa = (string)($config['contact']['whatsapp'] ?? '');
    $waBtn = preg_match('#^https://wa\.me/\d+$#', $wa) ? '<a class="btn" href="' . $h($wa) . '" rel="noopener">WhatsApp the super admin</a>' : '';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
        . '<title>No access | DreamHub E3</title>'
        . '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">'
        . '<style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;flex-direction:column;background:#F4F2EF;color:#24333A;font:400 16px/1.55 "DM Sans",system-ui,sans-serif}'
        . '.bar{background:#24333A;color:#fff;padding:0 16px;min-height:46px;display:flex;align-items:center;justify-content:space-between;font:700 15px Poppins,sans-serif}.bar span{color:#B9AFA7}'
        . 'main{flex:1;display:grid;place-items:center;padding:24px 16px}.card{max-width:30rem;width:100%;background:#fff;border:1px solid #E2DDD8;border-radius:16px;padding:28px 22px;text-align:center;box-shadow:0 10px 30px rgba(36,51,58,.08)}'
        . '.ic{width:56px;height:56px;border-radius:14px;background:#F4F2EF;display:grid;place-items:center;font-size:28px;margin:0 auto 14px}'
        . 'h1{font:700 1.3rem/1.25 Poppins,sans-serif;margin:0 0 8px}p{margin:0 0 20px;color:#5C6870}.row{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}'
        . '.btn{display:inline-flex;align-items:center;min-height:48px;padding:0 20px;border-radius:10px;border:1px solid #E2DDD8;background:#fff;color:#24333A;font:600 15px "DM Sans",sans-serif;text-decoration:none}.btn.p{background:#24333A;color:#fff;border-color:#24333A}</style></head><body>'
        . '<div class="bar"><span style="color:#fff">E3 Toolkit</span><div>Dream<span>Hub</span> E3</div></div>'
        . '<main><div class="card" role="alert"><div class="ic" aria-hidden="true">🔒</div><h1>' . $h($headline) . '</h1><p>' . $h($detail) . '</p>'
        . '<div class="row"><a class="btn p" href="/members/">Back to toolkit</a>' . $waBtn . '</div></div></main></body></html>';
    exit;
}

/** Decide what the person gets for this page. @return array{ok:bool,status:int,headline:string,detail:string} */
function gate_decide(PDO $pdo, array $member, array $tool): array
{
    if (can_use_tool($pdo, $member, $tool['key'])) {
        return ['ok' => true, 'status' => 200, 'headline' => '', 'detail' => ''];
    }
    $detail = $tool['locked'] ? 'This page is for the super admin only.' : 'Ask the super admin if you need it.';
    return ['ok' => false, 'status' => 403, 'headline' => "You don't have access to " . $tool['name'], 'detail' => $detail];
}
