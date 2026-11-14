<?php
declare(strict_types=1);

// Shared by tests/actionplan.php and the browser test harness.
require_once __DIR__ . '/../private/lib/actionplan.php';
require_once __DIR__ . '/helpers.php';

function ap_test_db(?string $file = null): PDO
{
    if ($file !== null) {
        $pdo = init_db(new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]));
    } else {
        $pdo = fresh_db();
    }
    $pdo->exec("ALTER TABLE members ADD COLUMN member_type TEXT NOT NULL DEFAULT 'team'");
    $pdo->exec('ALTER TABLE members ADD COLUMN is_suspended INTEGER NOT NULL DEFAULT 0');
    $pdo->exec('CREATE TABLE tool_access (member_id INTEGER NOT NULL, tool_key TEXT NOT NULL, PRIMARY KEY (member_id, tool_key))');
    $pdo->exec('CREATE TABLE ap_sections (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1)');
    $pdo->exec('CREATE TABLE ap_items (id INTEGER PRIMARY KEY AUTOINCREMENT, plan_id INTEGER NULL, section_id INTEGER NULL, title TEXT NOT NULL, details TEXT NULL, week_from INTEGER NOT NULL DEFAULT 1,
        week_to INTEGER NOT NULL DEFAULT 1, kind TEXT NOT NULL DEFAULT "tick", target INTEGER NULL, auto_key TEXT NULL, tool_key TEXT NULL, sort_order INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1,
        archived INTEGER NOT NULL DEFAULT 0, created_on TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE ap_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, member_id INTEGER NOT NULL UNIQUE, start_date TEXT NOT NULL, length_days INTEGER NOT NULL DEFAULT 90, disc TEXT NULL,
        private_number INTEGER NULL, buddy_id INTEGER NULL, last_nudged_at TEXT NULL, assigned_by INTEGER NULL, closed INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE ap_progress (plan_id INTEGER NOT NULL, item_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "todo", cnt INTEGER NOT NULL DEFAULT 0, note TEXT NULL, submitted_at TEXT NULL,
        approved_by INTEGER NULL, approved_at TEXT NULL, updated_at TEXT NOT NULL, PRIMARY KEY (plan_id, item_id))');
    $pdo->exec('CREATE TABLE ap_overrides (plan_id INTEGER NOT NULL, item_id INTEGER NOT NULL, skip INTEGER NOT NULL DEFAULT 0, skip_note TEXT NULL, week_from INTEGER NULL, week_to INTEGER NULL,
        target INTEGER NULL, PRIMARY KEY (plan_id, item_id))');
    return $pdo;
}

/** @return array{0:PDO,1:array,2:array<string,int>} pdo, super admin row, name => id */
function ap_world(?string $file = null): array
{
    $pdo = ap_test_db($file);
    $you = create_member($pdo, null, ['email' => 'you@x.com', 'name' => 'You', 'rank' => 'group_manager', 'is_admin' => true, 'referral_code' => 'you']);
    $ids = ['You' => $you['id']];
    foreach ([['Gm', 'group_manager', 'You'], ['Dave', 'agent', 'Gm'], ['Cindy', 'agent', 'Gm'], ['Ben', 'agent', 'You'], ['Eve', 'unit_manager', 'You']] as [$n, $rank, $up]) {
        $ids[$n] = create_member($pdo, $you['id'], ['email' => strtolower($n) . '@x.com', 'name' => $n, 'rank' => $rank, 'upline_id' => $ids[$up], 'referral_code' => 'r-' . strtolower($n)])['id'];
    }
    $pdo->prepare('UPDATE members SET is_team_admin = 1 WHERE id = ?')->execute([$ids['Gm']]);
    return [$pdo, load_member_map($pdo)[$you['id']], $ids];
}

/** A pretend Client Portal database for one person. $closes: 'A' (close_date, anp), 'B' (cdate, canp), 'C' (unrecognisable), 'none'. */
function ap_make_portal(string $dir, string $closes = 'A'): void
{
    @mkdir($dir, 0777, true);
    $db = new PDO('sqlite:' . $dir . '/clients.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT, kind TEXT, created_at TEXT)');
    $db->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, client_id INTEGER, enc_body TEXT, follow_up TEXT, created_at TEXT, kind TEXT, appt_date TEXT, next_kind TEXT, done INTEGER DEFAULT 0)');
    $defs = ['A' => 'CREATE TABLE closes (id INTEGER PRIMARY KEY, close_date TEXT, name TEXT, plan TEXT, anp REAL, updated_at TEXT)',
        'B' => 'CREATE TABLE closes (id INTEGER PRIMARY KEY, cdate TEXT, cname TEXT, cplan TEXT, canp TEXT)',
        'C' => 'CREATE TABLE closes (id INTEGER PRIMARY KEY, foo TEXT, bar TEXT)'];
    if (isset($defs[$closes])) { $db->exec($defs[$closes]); }
}
function ap_portal_db(string $dir): PDO { return new PDO('sqlite:' . $dir . '/clients.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
function ap_note(PDO $db, string $kind, string $date): void { $db->prepare('INSERT INTO notes (client_id, enc_body, created_at, kind, appt_date) VALUES (1, ?, ?, ?, ?)')->execute(['ENCRYPTED-SECRET', $date . ' 10:00:00', $kind, $date]); }

