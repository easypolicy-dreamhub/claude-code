<?php
// LOCAL TESTING ONLY. Never upload the dev/ folder to your server.
// Used as auto_prepend_file by run-tests.sh to stand in for the real site's config.php.
// Sign-in is real code: test.php signs Cloudflare-style tokens with a throwaway key,
// and the mock server (mock-claude.php) serves the matching public certificate.

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('dev stub is local only');
}

define('ANTHROPIC_API_KEY', 'test-key-123');
define('COACH_WHATSAPP_NUMBER', '60123456789');
define('COACH_API_BASE', (string) getenv('MOCK_BASE'));
define('COACH_DATA_DIR', (string) getenv('TEST_DATA_DIR'));
define('COACH_HISTORY_MSGS', (int) (getenv('TEST_HISTORY') ?: 4));
define('COACH_RATE_PER_HOUR', (int) (getenv('TEST_RATE') ?: 20));

define('COACH_CF_TEAM_DOMAIN', 'test-team.cloudflareaccess.com');
define('COACH_CF_AUD', 'test-aud-123');
define('COACH_CF_CERTS_URL', getenv('MOCK_BASE') . '/cdn-cgi/access/certs');
define('COACH_CF_CACHE_FILE', dirname((string) getenv('CF_CERTS_FILE')) . '/cf-cert-cache.json');
define('COACH_SUPER_ADMIN_EMAILS', ['Boss@E3.test']); // mixed case on purpose: matching ignores case
define('COACH_MEMBER_NAMES', [
    'alan@e3.test' => 'Alan',
    'brenda@e3.test' => 'Brenda',
    'carol@e3.test' => 'Carol',
    'teamadmin@e3.test' => 'Team Admin',
    'boss@e3.test' => 'Boss',
    'dave@e3.test' => 'Dave', // never uses the coach: should show as "needs a nudge"
]);
