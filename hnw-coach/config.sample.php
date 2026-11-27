<?php
// HNW Coach settings. Add these lines to your EXISTING config.php (the one that sits
// outside the public pages). Never put the real key in any file under public_html
// and never in a zip.

define('GEMINI_API_KEY', 'paste-your-key-here');

// Leader's WhatsApp number for the "Ask my leader on WhatsApp" button.
// Country code first, digits only (e.g. 60123456789).
define('COACH_WHATSAPP_NUMBER', '60123456789');

// Optional. Where instructions, PDFs and chats are stored. MUST be outside
// public_html. If left out, the coach uses a folder called e3-hnw-coach next to
// public_html (the same place as e3-agency-photos).
// define('COACH_DATA_DIR', '/home/YOUR-CPANEL-USER/e3-hnw-coach');

// Optional tuning (defaults shown).
// define('COACH_MODEL', 'gemini-2.5-flash');        // the coach. Check the name is current on Google's pricing page.
// define('COACH_NOTES_MODEL', 'gemini-2.5-flash-lite'); // writes each advisor's short coaching notes
// define('COACH_MAX_TOKENS', 8192);                 // longest single reply (includes Gemini's thinking)
// define('COACH_TEMPERATURE', 0.8);
// define('COACH_HISTORY_MSGS', 24);                 // recent messages sent with each question
// define('COACH_RATE_PER_HOUR', 30);                // messages per advisor per hour
// define('COACH_RATE_PER_DAY', 120);                // messages per advisor per day
// define('COACH_INSTR_KEEP', 5);                    // instruction versions kept
// define('COACH_MAX_PDF_MB', 10);                   // per PDF
// define('COACH_MAX_TOTAL_MB', 14);                 // all PDFs together (Gemini request limit is 20 MB after encoding)
