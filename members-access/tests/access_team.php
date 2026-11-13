<?php
declare(strict_types=1);
// Run in the full site tree: php tests/access_team.php   Admins and Testers stay out of the Team tree and Team Activity.
require_once __DIR__ . '/../private/lib/team.php';
require_once __DIR__ . '/../private/lib/access.php';
require_once __DIR__ . '/../private/lib/activity.php';
require_once __DIR__ . '/helpers.php';
$pdo = fresh_db();
$pdo->exec("ALTER TABLE members ADD COLUMN member_type TEXT NOT NULL DEFAULT 'team'");
$pdo->exec('CREATE TABLE tool_access (member_id INTEGER NOT NULL, tool_key TEXT NOT NULL, PRIMARY KEY (member_id, tool_key))');
$you = create_member($pdo, null, ['email' => 'you@x.com', 'name' => 'You', 'rank' => 'group_manager', 'is_admin' => true, 'referral_code' => 'you']);
$you = load_member_map($pdo)[$you['id']];
access_add($pdo, null, $you, ['email' => 'dave@x.com', 'name' => 'Dave', 'type' => 'team']);
access_add($pdo, null, $you, ['email' => 'cindy@x.com', 'name' => 'Cindy', 'type' => 'admin']);
access_add($pdo, null, $you, ['email' => 'ben@x.com', 'name' => 'Ben', 'type' => 'tester']);
$map = load_member_map($pdo);
$tree = team_tree($pdo, $map, $map[$you['id']], true);
$names = array_column($tree['members'], 'name');
check('Team tree shows you and Dave', in_array('You', $names, true) && in_array('Dave', $names, true));
check('Team tree hides the Admin and the Tester', !in_array('Cindy', $names, true) && !in_array('Ben', $names, true));
$act = activity_summary($pdo, $map, $map[$you['id']], 'all');
$an = array_column($act['people'] ?? [], 'name');
check('Team Activity hides Admin and Tester rows', in_array('Dave', $an, true) && !in_array('Cindy', $an, true) && !in_array('Ben', $an, true));
$dave = $map[array_search('dave@x.com', array_column($map, 'email', 'id'), true)];
check('Dave (team) sees only himself in the tree', array_column(team_tree($pdo, $map, $dave, false)['members'], 'name') === ['Dave']);
$cindy = $map[array_search('cindy@x.com', array_column($map, 'email', 'id'), true)];
check('Cindy (admin) is not super admin and sees nobody else', !is_super_admin($cindy) && visible_member_ids($map, (int)$cindy['id']) === [(int)$cindy['id']]);
finish();
