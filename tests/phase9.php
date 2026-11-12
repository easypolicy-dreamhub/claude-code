<?php
declare(strict_types=1);

// Run: php tests/phase9.php   Each advisor's own WhatsApp number.
require_once __DIR__ . '/../private/lib/emails.php';
require_once __DIR__ . '/../private/lib/team.php';
require_once __DIR__ . '/helpers.php';

$cfg = ['default_owner_email' => 'you@x.com', 'ip_salt' => 's', 'rate_limit_per_hour' => 1000, 'base_url' => 'https://dreamhub-e3.com', 'contact' => ['whatsapp' => 'https://wa.me/60126718056', 'email' => 'hello@dreamhub-e3.com']];
$pdo = fresh_db();
$me = create_member($pdo, null, ['email' => 'you@x.com', 'name' => 'You', 'rank' => 'group_manager', 'is_admin' => true, 'referral_code' => 'cf']);
$A  = create_member($pdo, $me['id'], ['email' => 'amy@x.com', 'name' => 'Amy', 'upline_id' => $me['id'], 'referral_code' => 'amy', 'whatsapp' => '012-345 6789']);
$B  = create_member($pdo, $me['id'], ['email' => 'ben@x.com', 'name' => 'Ben', 'upline_id' => $me['id'], 'referral_code' => 'ben']);

echo "Saving the number\n";
check('012-345 6789 is stored as 60123456789', $A['whatsapp'] === '60123456789');
check('a member without a number has an empty one', $B['whatsapp'] === '');
check('+60 12 345 6789 is stored as 60123456789', update_member($pdo, $me['id'], $B['id'], ['whatsapp' => '+60 12 345 6789'])['whatsapp'] === '60123456789');
check('an empty value clears it', update_member($pdo, $me['id'], $B['id'], ['whatsapp' => ''])['whatsapp'] === '');
throws('letters only is refused', 422, fn () => update_member($pdo, $me['id'], $B['id'], ['whatsapp' => 'abc']));
throws('too short is refused', 422, fn () => normalise_whatsapp('12345'));
throws('too long is refused', 422, fn () => normalise_whatsapp('1234567890123456'));

echo "\nWho the candidate reaches\n";
$r = start_candidate($pdo, $cfg, valid_start(['ref' => 'amy', 'mobile' => '0112000001', 'email' => 'c1@e.com']), null);
$s = submit_assessment($pdo, $r['submit_token'], sample_answers());
check('the result carries the advisor\'s number', $s['advisor_whatsapp'] === '60123456789');
$cm = build_candidate_email(['name' => 'C', 'lang' => 'en'], $s['scores'], ['name' => 'Amy', 'whatsapp' => '60123456789'], $cfg);
check('the candidate email links to the advisor\'s WhatsApp', str_contains($cm['text'], 'https://wa.me/60123456789') && !str_contains($cm['text'], '60126718056'));
$r2 = start_candidate($pdo, $cfg, valid_start(['ref' => 'ben', 'mobile' => '0112000002', 'email' => 'c2@e.com']), null);
$s2 = submit_assessment($pdo, $r2['submit_token'], sample_answers());
check('an advisor with no number gives an empty one (page falls back to the company number)', $s2['advisor_whatsapp'] === '');
$cm2 = build_candidate_email(['name' => 'C', 'lang' => 'en'], $s2['scores'], ['name' => 'Ben', 'whatsapp' => ''], $cfg);
check('the email then uses the company number', str_contains($cm2['text'], 'https://wa.me/60126718056'));
finish();
