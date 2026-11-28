<?php
// HNW Coach settings. Create a NEW file called hnw-coach-config.php in the private folder
// (next to config.php, outside public_html) and paste this in. Do not edit config.php.
// Never put the real key in any file under public_html and never in a zip.
return [
    'GEMINI_API_KEY' => 'paste-your-key-here',

    // Leader's WhatsApp number for the "Ask my leader on WhatsApp" button.
    // Country code first, digits only (e.g. 60123456789).
    'COACH_WHATSAPP_NUMBER' => '60123456789',
];

// Optional tuning: add more lines inside the [ ] above, in the same 'NAME' => value form. Defaults shown.

// Optional. Where instructions, PDFs and chats are stored. MUST be outside
// public_html. If left out, the coach uses a folder called e3-hnw-coach next to
// public_html (the same place as e3-agency-photos).
//     'COACH_DATA_DIR' => '/home/YOUR-CPANEL-USER/e3-hnw-coach',

// Optional tuning (defaults shown).
//     'COACH_MODEL' => 'gemini-3.8-flash',        // the coach. Normally set in Settings > Connection instead.
//     'COACH_NOTES_MODEL' => 'gemini-3.8-flash',   // writes each advisor's short coaching notes (defaults to the same model)
//     'COACH_MAX_TOKENS' => 8192,                 // longest single reply (includes Gemini's thinking)
//     'COACH_TEMPERATURE' => 0.8,
//     'COACH_HISTORY_MSGS' => 24,                 // recent messages sent with each question
//     'COACH_RATE_PER_HOUR' => 30,                // messages per advisor per hour
//     'COACH_RATE_PER_DAY' => 120,                // messages per advisor per day
//     'COACH_INSTR_KEEP' => 5,                    // instruction versions kept
//     'COACH_MAX_PDF_MB' => 10,                   // per PDF
//     'COACH_MAX_TOTAL_MB' => 14,                 // all PDFs together (Gemini request limit is 20 MB after encoding)
