<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';

/**
 * Keeps the Cloudflare Zero Trust "Email Access" policy in step with this site.
 * Only the email rules of the one policy are touched; everything else in the policy is sent back exactly as it was.
 *
 * config.php needs:  'cf_api' => ['token' => '...', 'account_id' => '...', 'policy_id' => '...']
 * The token goes only in config.php (outside public_html), never in a web page.
 */
/**
 * Cloudflare failures use HTTP 424, never 502/504: Cloudflare (in front of this site) swaps any 502/504 from the origin for
 * its own generic error page, which would hide the real reason from the screen.
 */
final class CfPolicy
{
    private const BASE = 'https://api.cloudflare.com/client/v4';
    /** keys Cloudflare returns but does not accept back */
    private const READ_ONLY = ['id', 'uid', 'created_at', 'updated_at', 'reusable', 'app_count', 'version'];

    /** @var callable(string,string,?array,string):array  (method, url, body, token) => decoded JSON */
    private $http;
    /** @var callable|null  optional step logger: fn(string $line) */
    public static $trace = null;

    public function __construct(private array $cfg, ?callable $http = null)
    {
        $this->http = $http ?? [self::class, 'curl'];
    }

    public static function fromConfig(array $config, ?callable $http = null): ?self
    {
        $c = $config['cf_api'] ?? null;
        if (!is_array($c) || empty($c['token']) || empty($c['account_id']) || empty($c['policy_id'])) {
            return null;
        }
        return new self($c, $http);
    }

    private function policyUrl(): string
    {
        return self::BASE . '/accounts/' . rawurlencode((string)$this->cfg['account_id']) . '/access/policies/' . rawurlencode((string)$this->cfg['policy_id']);
    }

    private function call(string $method, string $url, ?array $body = null): array
    {
        $t0 = microtime(true);
        if (self::$trace) { (self::$trace)('cf ' . $method . ' ' . preg_replace('#^.*/client/v4#', '', $url) . ' ...'); }
        try {
            $res = ($this->http)($method, $url, $body, (string)$this->cfg['token']);
        } catch (Throwable $e) {
            if (self::$trace) { (self::$trace)('cf ' . $method . ' failed after ' . round(microtime(true) - $t0, 2) . 's: ' . $e->getMessage()); }
            throw $e;
        }
        if (self::$trace) { (self::$trace)('cf ' . $method . ' answered in ' . round(microtime(true) - $t0, 2) . 's, success=' . (is_array($res) && !empty($res['success']) ? 'yes' : 'no')); }
        if (!is_array($res) || empty($res['success'])) {
            $msg = is_array($res) ? (string)($res['errors'][0]['message'] ?? 'unknown error') : 'no answer';
            throw new HttpError(424, 'Cloudflare said: ' . $msg);
        }
        return $res;
    }

    /** @return array the policy as Cloudflare holds it */
    public function policy(): array
    {
        return (array)($this->call('GET', $this->policyUrl())['result'] ?? []);
    }

    /** @return string[] lowercase emails on the policy */
    public function emails(?array $policy = null): array
    {
        $policy ??= $this->policy();
        $out = [];
        foreach ((array)($policy['include'] ?? []) as $rule) {
            if (isset($rule['email']['email'])) {
                $out[] = strtolower(trim((string)$rule['email']['email']));
            }
        }
        return array_values(array_unique($out));
    }

    /** True when the policy has include rules that are not plain emails (a list, a domain...). They are kept untouched. */
    public function hasOtherRules(?array $policy = null): bool
    {
        $policy ??= $this->policy();
        foreach ((array)($policy['include'] ?? []) as $rule) {
            if (!isset($rule['email'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Add and/or remove emails in ONE save. Never removes a "keep" email and never leaves the list empty.
     * @return array{added:string[],removed:string[]}
     */
    public function change(array $add, array $remove, array $keep = []): array
    {
        $norm = static fn (array $a): array => array_values(array_unique(array_map(static fn ($e): string => strtolower(trim((string)$e)), $a)));
        $add = $norm($add); $remove = $norm($remove); $keep = $norm($keep);
        $policy = $this->policy();
        $have = $this->emails($policy);
        $remove = array_values(array_diff($remove, $keep));
        $add = array_values(array_diff($add, $have));
        $removeNow = array_values(array_intersect($remove, $have));
        if (!$add && !$removeNow) {
            return ['added' => [], 'removed' => []];
        }
        $final = array_values(array_diff(array_merge($have, $add), $removeNow));
        if (!$final) {
            throw new HttpError(422, 'That would leave nobody on the Cloudflare list, so nothing was changed.');
        }
        // Keep every non-email rule and the order of the existing emails; new emails go on the end.
        $include = [];
        foreach ((array)($policy['include'] ?? []) as $rule) {
            if (!isset($rule['email']['email'])) {
                $include[] = $rule;
            } elseif (!in_array(strtolower(trim((string)$rule['email']['email'])), $removeNow, true)) {
                $include[] = $rule;
            }
        }
        foreach ($add as $e) {
            $include[] = ['email' => ['email' => $e]];
        }
        $body = array_diff_key($policy, array_flip(self::READ_ONLY));
        $body['include'] = $include;
        $this->call('PUT', $this->policyUrl(), $body);
        return ['added' => $add, 'removed' => $removeNow];
    }

    /** @return array{ok:bool,detail:string} is the token accepted by Cloudflare at all? */
    public function checkToken(): array
    {
        try {
            $r = $this->call('GET', self::BASE . '/user/tokens/verify');
            $st = (string)($r['result']['status'] ?? '');
            return ['ok' => $st === 'active', 'detail' => $st === 'active' ? 'The token is active.' : 'The token status is "' . $st . '".'];
        } catch (HttpError $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /** @return array{ok:bool,detail:string,policies:array<int,array{id:string,name:string}>} every Access policy in the account */
    public function listPolicies(): array
    {
        try {
            $r = $this->call('GET', self::BASE . '/accounts/' . rawurlencode((string)$this->cfg['account_id']) . '/access/policies');
            $list = array_map(static fn ($p): array => ['id' => (string)($p['id'] ?? ''), 'name' => (string)($p['name'] ?? '')], (array)($r['result'] ?? []));
            return ['ok' => true, 'detail' => count($list) . ' polic' . (count($list) === 1 ? 'y' : 'ies') . ' found in this account.', 'policies' => $list];
        } catch (HttpError $e) {
            return ['ok' => false, 'detail' => $e->getMessage(), 'policies' => []];
        }
    }

    /** Sign the person out everywhere right now (their Cloudflare sessions end). */
    public function revoke(string $email): void
    {
        $this->call('POST', self::BASE . '/accounts/' . rawurlencode((string)$this->cfg['account_id']) . '/access/organizations/revoke_user', ['email' => strtolower(trim($email))]);
    }

    public static function curl(string $method, string $url, ?array $body, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,   // some hosts hang on IPv6 routes
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new HttpError(424, 'Could not reach Cloudflare (' . $err . ').');
        }
        curl_close($ch);
        $d = json_decode((string)$raw, true);
        return is_array($d) ? $d : ['success' => false, 'errors' => [['message' => 'unreadable answer']]];
    }
}

/**
 * Best-effort sync used by the Team page: never breaks the action that already succeeded.
 * @return string 'ok' | 'not_connected' | 'failed: ...'
 */
function cf_sync_try(array $config, array $add, array $remove, array $keep = [], ?callable $http = null): string
{
    $cf = CfPolicy::fromConfig($config, $http);
    if ($cf === null) {
        return 'not_connected';
    }
    try {
        $cf->change($add, $remove, $keep);
        foreach ($remove as $e) {
            try { $cf->revoke((string)$e); } catch (Throwable $e2) { error_log('cf revoke: ' . $e2->getMessage()); }
        }
        return 'ok';
    } catch (Throwable $e) {
        error_log('cf sync: ' . $e->getMessage());
        return 'failed: ' . $e->getMessage();
    }
}
