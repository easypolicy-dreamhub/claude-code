<?php
declare(strict_types=1);

// Helper for tests/e2e.js:  php e2e_db.php seed <sqlite-file>   |   php e2e_db.php dump <sqlite-file>
require_once __DIR__ . '/../private/lib/candidates.php';
require_once __DIR__ . '/helpers.php';

[$_, $mode, $file] = $argv;
$pdo = new PDO("sqlite:$file", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if ($mode === 'seed') {
    init_db($pdo);
    $me = create_member($pdo, null, ['email' => 'you@x.com', 'name' => 'You', 'rank' => 'unit_manager', 'is_admin' => true, 'referral_code' => 'cf']);
    create_member($pdo, $me['id'], ['email' => 'a@x.com', 'name' => 'Amy A', 'upline_id' => $me['id'], 'referral_code' => 'amy']);
    echo "seeded\n";
} else {
    echo json_encode(['candidates' => $pdo->query('SELECT * FROM candidates ORDER BY id')->fetchAll(), 'assessments' => $pdo->query('SELECT * FROM assessments ORDER BY id')->fetchAll()]);
}
