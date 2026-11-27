<?php
// LOCAL TESTING ONLY. Never upload the dev/ folder to your server.
// Used as auto_prepend_file by run-tests.sh: gives the coach a fake members system and points it at a fake Gemini.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('dev stub is local only');
}
define('HNWCOACH_PRIVATE_DIR', __DIR__ . '/fake-private');
if (!getenv('TEST_CFG_FILE_ONLY')) { // the settings-file test leaves these out so they must come from hnw-coach-config.php
    define('GEMINI_API_KEY', 'test-key-123');
    define('COACH_WHATSAPP_NUMBER', '60123456789');
    define('COACH_API_BASE', (string) getenv('MOCK_BASE'));
}
define('COACH_DATA_DIR', (string) getenv('TEST_DATA_DIR'));
define('COACH_HISTORY_MSGS', (int) (getenv('TEST_HISTORY') ?: 4));
define('COACH_RATE_PER_HOUR', (int) (getenv('TEST_RATE') ?: 20));
define('COACH_RATE_PER_DAY', 30);
