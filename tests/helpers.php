<?php
declare(strict_types=1);

$GLOBALS['pass'] = 0; $GLOBALS['fail'] = 0;
function check(string $name, bool $ok): void { $GLOBALS[$ok ? 'pass' : 'fail']++; echo ($ok ? "  ok   " : "  FAIL ") . $name . "\n"; }
function throws(string $name, int $status, callable $fn): void {
    try { $fn(); check("$name (expected $status)", false); }
    catch (HttpError $e) { check("$name -> {$e->status}", $e->status === $status); }
}
function finish(): never { echo "\n{$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n"; exit($GLOBALS['fail'] ? 1 : 0); }

function fresh_db(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return init_db($pdo);
}
function init_db(PDO $pdo): PDO {
    $pdo->exec('CREATE TABLE members (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL, name TEXT NOT NULL, rank_label TEXT NOT NULL DEFAULT "agent",
        upline_id INTEGER NULL, is_admin INTEGER NOT NULL DEFAULT 0, referral_code TEXT UNIQUE NOT NULL, is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, deactivated_at TEXT NULL)');
    $pdo->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_id INTEGER, action TEXT, subject_id INTEGER, detail TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE candidates (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_member_id INTEGER NOT NULL, purpose TEXT NOT NULL DEFAULT "career", source TEXT NOT NULL,
        name TEXT NOT NULL, mobile TEXT NOT NULL, email TEXT NULL, age_group INTEGER NULL, education INTEGER NULL, work_status INTEGER NULL, field TEXT NULL, lang TEXT NOT NULL DEFAULT "en",
        consent_at TEXT DEFAULT CURRENT_TIMESTAMP, consent_version TEXT NOT NULL, pipeline_status TEXT NOT NULL DEFAULT "new", duplicate_of INTEGER NULL, submit_token_hash TEXT NULL,
        ip_hash TEXT NULL, started_at TEXT DEFAULT CURRENT_TIMESTAMP, completed_at TEXT NULL, result_emailed_at TEXT NULL, notified_at TEXT NULL)');
    $pdo->exec('CREATE TABLE assessments (id INTEGER PRIMARY KEY AUTOINCREMENT, candidate_id INTEGER NOT NULL, mode TEXT NOT NULL DEFAULT "test", answers_json TEXT NULL,
        scores_json TEXT NOT NULL, code TEXT NOT NULL, entered_by_member_id INTEGER NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    return $pdo;
}

/** The real sample photo's scores: totals R2 I16 A11 S30 E34 C18 -> code ESC. */
function sample_answers(): array {
    $a = ['A' => [0, 4, 3, 7, 7, 4], 'B' => [0, 5, 1, 6, 7, 4], 'C' => [0, 3, 0, 4, 7, 1]];
    $ans = [];
    foreach ($a as $part => $counts) {
        foreach (HOLLAND_TYPES as $i => $t) { $ans[$part][$t] = $counts[$i] ? range(0, $counts[$i] - 1) : []; }
    }
    $d = ['R' => [1, 1], 'I' => [2, 2], 'A' => [3, 4], 'S' => [6, 7], 'E' => [7, 6], 'C' => [4, 5]];
    $ans['D'] = $d;
    return $ans;
}
function valid_start(array $over = []): array {
    return $over + ['name' => 'Amy Tan', 'mobile' => '012-345 6789', 'email' => 'Amy@Example.com', 'consent' => true, 'lang' => 'en', 'purpose' => 'career',
        'age' => 2, 'edu' => 3, 'status' => 1, 'field' => 'Marketing'];
}
