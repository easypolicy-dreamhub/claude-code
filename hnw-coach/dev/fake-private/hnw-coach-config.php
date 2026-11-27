<?php
// LOCAL TESTING ONLY. Stand-in for private/hnw-coach-config.php, used when no constants are defined.
if (getenv('TEST_NO_KEY')) { return ['COACH_API_BASE' => (string) getenv('MOCK_BASE')]; }
return ['GEMINI_API_KEY' => 'test-key-123', 'COACH_WHATSAPP_NUMBER' => '60123456789', 'COACH_API_BASE' => (string) getenv('MOCK_BASE')];
