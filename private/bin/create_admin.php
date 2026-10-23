<?php
declare(strict_types=1);

/**
 * Creates the first admin (you). Run once over SSH or cPanel Terminal:
 *   php private/bin/create_admin.php you@example.com "Goh Cheng Fee" gcf
 * The email must match the one you log in with at Cloudflare.
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
require_once __DIR__ . '/../lib/members.php';

[$script, $email, $name] = $argv + [null, null, null];
$referral = $argv[3] ?? '';
if (!$email || !$name) {
    fwrite(STDERR, "Usage: php create_admin.php <email> \"<name>\" [referral-code]\n");
    exit(1);
}
require_once __DIR__ . '/../lib/db.php';
$config = load_config();
$pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
if ($pdo->query('SELECT COUNT(*) FROM members WHERE is_admin = 1')->fetchColumn() > 0) {
    fwrite(STDERR, "An admin already exists. Add members from the dashboard instead.\n");
    exit(1);
}
try {
    $m = create_member($pdo, null, [
        'email' => $email, 'name' => $name, 'rank' => 'unit_manager',
        'is_admin' => true, 'referral_code' => $referral,
    ]);
} catch (HttpError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo "Created admin #{$m['id']} {$m['email']} (referral code: {$m['referral_code']})\n";
