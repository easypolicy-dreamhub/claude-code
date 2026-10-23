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
];
