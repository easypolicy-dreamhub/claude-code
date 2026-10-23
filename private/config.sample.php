<?php
// Copy to config.php (same folder) and fill in. config.php must never be committed or web-accessible.
return [
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=CPANELUSER_dreamhub;charset=utf8mb4',
        'user' => 'CPANELUSER_dbuser',
        'pass' => 'CHANGE_ME',
    ],
    'cf_access' => [
        // Zero Trust dashboard > Settings > Custom Pages / "Team domain": <team>.cloudflareaccess.com
        'team_domain' => 'YOURTEAM.cloudflareaccess.com',
        // Zero Trust > Access > Applications > (members app) > Overview > "Application Audience (AUD) Tag"
        'aud'         => 'PASTE_AUD_TAG_HERE',
        // Writable folder for the cached Cloudflare signing keys.
        'cache_dir'   => __DIR__ . '/cache',
    ],
    // Hostnames allowed in the Origin header of state-changing requests.
    'allowed_origins' => ['dreamhub-e3.com', 'www.dreamhub-e3.com'],

    // ---- Phase 2 (public Career Code test) ----
    'base_url' => 'https://dreamhub-e3.com',
    // Candidates from the landing page (no ?ref= link) belong to this member. Must be an ACTIVE member's email.
    'default_owner_email' => 'your-login-email@example.com',
    // Any long random text. Used only to scramble visitor IP addresses before they are stored.
    'ip_salt' => 'CHANGE_ME_TO_A_LONG_RANDOM_TEXT',
    'rate_limit_per_hour' => 10,   // test starts allowed per visitor connection per hour
    // Cloudflare dashboard > Turnstile > your widget > SECRET key (never put this in a web page).
    'turnstile' => ['secret' => 'PASTE_TURNSTILE_SECRET_KEY_HERE'],
    'mail' => [
        'transport' => 'mail',                 // 'mail' = the server's own mail (simplest). Use 'smtp' if emails land in spam.
        'from'      => 'hello@dreamhub-e3.com',
        'from_name' => 'DreamHub E3',
        // Only for 'smtp':  'host' => 'mail.dreamhub-e3.com', 'port' => 465, 'secure' => 'ssl' ('ssl' | 'tls' | ''),
        //                   'user' => 'hello@dreamhub-e3.com', 'pass' => '...', 'verify_tls' => true,
    ],
    'contact' => ['whatsapp' => 'https://wa.me/60126718056', 'email' => 'hello@dreamhub-e3.com'],
];
