<?php
// Add these lines to your EXISTING config.php (the one that already sits outside
// the public pages). Do not upload this sample as-is, and never put the real key
// in any file under public_html that can be opened from the web.

define('ANTHROPIC_API_KEY', 'sk-ant-...paste-your-key-here...');

// Leader's WhatsApp number for the "Ask my leader on WhatsApp" button.
// Country code first, digits only (e.g. 60123456789).
define('COACH_WHATSAPP_NUMBER', '60123456789');

// Optional. Where instructions, PDFs and chats are stored. MUST be outside
// public_html. If left out, the coach uses a folder called e3-ai-coach next to
// public_html (the same place as e3-agency-photos).
// define('COACH_DATA_DIR', '/home/YOUR-CPANEL-USER/e3-ai-coach');

// Optional tuning (defaults shown).
// define('COACH_MODEL', 'claude-opus-5-5');       // the coach. claude-sonnet-5-5 is about half the price.
// define('COACH_NOTES_MODEL', 'claude-haiku-5-5'); // writes each agent's short coaching notes
// define('COACH_EFFORT', 'medium');                // low | medium | high
// define('COACH_MAX_TOKENS', 3000);                // longest single reply
// define('COACH_CACHE_TTL', '1h');                 // '1h' or '5m': how long the PDFs stay cached
// define('COACH_HISTORY_MSGS', 24);                // recent messages sent with each question
// define('COACH_RATE_PER_HOUR', 60);               // messages per agent per hour
// define('COACH_INSTR_KEEP', 5);                   // instruction versions kept
// define('COACH_MAX_PDF_MB', 20);                  // per PDF
// define('COACH_MAX_TOTAL_MB', 22);                // all PDFs together (Claude request limit)
// define('COACH_USE_FALLBACKS', true);             // let Claude re-run a declined request on a fallback model
