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
} elseif ($mode === 'seed-team') {
    init_db($pdo);
    $cfg = ['default_owner_email' => 'you@x.com', 'ip_salt' => 'e2e', 'rate_limit_per_hour' => 1000];
    $me = create_member($pdo, null, ['email' => 'you@x.com', 'name' => 'You', 'rank' => 'unit_manager', 'is_admin' => true, 'referral_code' => 'cf']);
    $mk = fn (string $n, string $code, int $up, string $rank = 'agent') => create_member($pdo, $me['id'], ['email' => strtolower($n) . '@x.com', 'name' => $n, 'upline_id' => $up, 'rank' => $rank, 'referral_code' => $code]);
    $A = $mk('Amy', 'amy', $me['id'], 'unit_manager'); $B = $mk('Ben', 'ben', $me['id']); $AA = $mk('Alice', 'alice', $A['id']); $BB = $mk('Bella', 'bella', $B['id']);
    create_member($pdo, $me['id'], ['email' => 'dormant@x.com', 'name' => 'Dormant', 'upline_id' => $me['id'], 'referral_code' => 'dormant', 'is_active' => true]);
    $n = 20000000;
    foreach ([['cf', 'Zed Zhang', 'career'], ['amy', 'Cara Chin', 'career'], ['alice', 'Dina Dass', 'internship'], ['ben', 'Eddy Ee', 'career'], ['bella', 'Fiona Foo', 'internship']] as [$ref, $nm, $purpose]) {
        $n++;
        $r = start_candidate($pdo, $cfg, valid_start(['ref' => $ref, 'name' => $nm, 'purpose' => $purpose, 'mobile' => '01' . $n, 'email' => "c$n@x.com"]), null);
        submit_assessment($pdo, $r['submit_token'], sample_answers());
    }
    start_candidate($pdo, $cfg, valid_start(['ref' => 'amy', 'name' => 'Unfinished Una', 'mobile' => '0199990001', 'email' => 'una@x.com']), null);
    echo "seeded team\n";
} elseif ($mode === 'add-dup') {
    // A repeat applicant (same mobile as the first seeded candidate, owned by You) arrives through Amy's personal link.
    $cfg = ['default_owner_email' => 'you@x.com', 'ip_salt' => 'e2e', 'rate_limit_per_hour' => 1000];
    $r = start_candidate($pdo, $cfg, valid_start(['ref' => 'amy', 'name' => 'Repeat Rita', 'mobile' => '0120000001', 'email' => 'rita@x.com']), null);
    submit_assessment($pdo, $r['submit_token'], sample_answers());
    echo "added dup\n";
} else {
    echo json_encode(['candidates' => $pdo->query('SELECT * FROM candidates ORDER BY id')->fetchAll(), 'assessments' => $pdo->query('SELECT * FROM assessments ORDER BY id')->fetchAll()]);
}
