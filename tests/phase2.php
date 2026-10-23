<?php
declare(strict_types=1);

// Run: php tests/phase2.php   (needs php-openssl + pdo_sqlite, node, python3; no network or MySQL)
require_once __DIR__ . '/../private/lib/candidates.php';
require_once __DIR__ . '/../private/lib/turnstile.php';
require_once __DIR__ . '/helpers.php';

echo "Scoring (your real sample photo: R2 I16 A11 S30 E34 C18, code ESC)\n";
$s = score_test(sample_answers());
check('totals match the paper (C is 18, the candidate wrote 17)', array_map(fn ($t) => $s[$t]['total'], HOLLAND_TYPES) === [2, 16, 11, 30, 34, 18]);
check('code is ESC', holland_code($s) === 'ESC');
check('part D per type', array_map(fn ($t) => $s[$t]['d'], HOLLAND_TYPES) === [2, 4, 7, 13, 13, 9]);
check('abc = a+b+c', $s['E']['abc'] === 21 && $s['S']['abc'] === 17);
$tie = array_fill_keys(HOLLAND_TYPES, ['total' => 10]);
check('ties keep R,I,A,S,E,C order', holland_code($tie) === 'RIA');
$bad = fn (callable $f) => (function () use ($f) { $a = sample_answers(); $f($a); return $a; })();
throws('item index out of range', 422, fn () => score_test($bad(fn (&$a) => $a['A']['R'] = [11])));
throws('duplicate item index', 422, fn () => score_test($bad(fn (&$a) => $a['C']['E'] = [1, 1])));
throws('more items than exist', 422, fn () => score_test($bad(fn (&$a) => $a['A']['E'] = range(0, 11))));
throws('non-integer index', 422, fn () => score_test($bad(fn (&$a) => $a['B']['S'] = ['x'])));
throws('rating 8', 422, fn () => score_test($bad(fn (&$a) => $a['D']['R'][0] = 8)));
throws('rating reused within a set', 422, fn () => score_test($bad(fn (&$a) => $a['D']['I'][0] = 1)));
throws('rating missing', 422, fn () => score_test($bad(function (&$a) { unset($a['D']['C']); })));
throws('part missing', 422, fn () => score_test($bad(function (&$a) { unset($a['B']); })));
throws('not an array', 422, fn () => score_test('hello'));
check('same number MAY repeat across the two sets', (function () { $a = sample_answers(); return score_test($a)['R']['d'] === 2; })());

echo "\nQuestionnaire data stays in step with the server\n";
$node = trim((string)shell_exec('which node 2>/dev/null'));
if ($node === '') { echo "  skip node not installed\n"; } else {
    $js = 'const f=new Function(require("fs").readFileSync(process.argv[1],"utf8")+";return {ITEMS,INFO,RATINGS};")();'
        . 'const c={};for(const p of ["A","B","C"])for(const t of "RIASEC")c[p+t]=f.ITEMS.en[p][t].length;'
        . 'for(const p of ["A","B","C"])for(const t of "RIASEC")if(f.ITEMS.zh[p][t].length!==c[p+t])throw new Error("zh/en length differs "+p+t);'
        . 'console.log(JSON.stringify({c,info:f.INFO}));';
    $out = json_decode((string)shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg($js) . ' ' . escapeshellarg(__DIR__ . '/../public_html/career-code/assets/cc-data.js') . ' 2>&1'), true);
    check('cc-data.js loads and en/zh item counts agree', is_array($out) && isset($out['c']));
    $ok = is_array($out);
    foreach (ITEM_COUNTS as $part => $n) { foreach (HOLLAND_TYPES as $t) { $ok = $ok && ($out['c'][$part . $t] ?? -1) === $n; } }
    check('ITEM_COUNTS equal the real number of items per type', $ok);
    check('private/data/holland.json equals INFO in cc-data.js', is_array($out) && json_decode(file_get_contents(__DIR__ . '/../private/data/holland.json'), true)['info'] == $out['info']);
}
$ccdata = file_get_contents(__DIR__ . '/../public_html/career-code/assets/cc-data.js');
check('public data file contains no advisor logic or PIN', !preg_match('/ADV\b|assess\(|8888|criteria|suitab/i', $ccdata));

echo "\nMobile numbers (Malaysia)\n";
foreach ([['012-345 6789', '+60123456789'], ['0123456789', '+60123456789'], ['+6012 345 6789', '+60123456789'], ['60123456789', '+60123456789'], ['011-2345 6789', '+601123456789'], ['(012) 345-6789', '+60123456789']] as [$in, $out]) {
    check("$in -> $out", normalise_mobile($in) === $out);
}
foreach (['03-1234 5678', '65 9123 4567', '12345', '+44 7700 900123', 'abc', ''] as $bad) { check("rejects '$bad'", normalise_mobile($bad) === null); }

echo "\nOwner assignment\n";
$pdo = fresh_db();
$me = create_member($pdo, null, ['email' => 'you@x.com', 'name' => 'You', 'rank' => 'unit_manager', 'is_admin' => true, 'referral_code' => 'cf']);
$A  = create_member($pdo, $me['id'], ['email' => 'a@x.com', 'name' => 'Amy A', 'upline_id' => $me['id'], 'referral_code' => 'amy']);
$AA = create_member($pdo, $me['id'], ['email' => 'aa@x.com', 'name' => 'Ah Ah', 'upline_id' => $A['id'], 'referral_code' => 'aa']);
$cfg = ['default_owner_email' => 'you@x.com', 'ip_salt' => 'salt', 'rate_limit_per_hour' => 3, 'base_url' => 'https://dreamhub-e3.com'];
check('no ref -> default owner (you), source landing', (function () use ($pdo, $cfg, $me) { [$o, $src] = resolve_owner($pdo, $cfg, null); return $o['id'] === $me['id'] && $src === 'landing'; })());
check('?ref=amy -> Amy, source referral', (function () use ($pdo, $cfg, $A) { [$o, $src] = resolve_owner($pdo, $cfg, 'AMY'); return $o['id'] === $A['id'] && $src === 'referral'; })());
check('unknown ref falls back to default', resolve_owner($pdo, $cfg, 'nobody')[0]['email'] === 'you@x.com');
update_member($pdo, $me['id'], $AA['id'], ['is_active' => false]);
check('inactive agent link falls back to default', resolve_owner($pdo, $cfg, 'aa')[0]['email'] === 'you@x.com');
update_member($pdo, $me['id'], $AA['id'], ['is_active' => true]);
check('no default email configured -> first active admin', resolve_owner($pdo, ['ip_salt' => 'x'], null)[0]['email'] === 'you@x.com');

echo "\nStarting a test\n";
$r = start_candidate($pdo, $cfg, valid_start(['ref' => 'amy']), '203.0.113.5');
check('start returns a 64-hex token and advisor name', preg_match('/^[a-f0-9]{64}$/', $r['submit_token']) === 1 && $r['advisor_name'] === 'Amy A');
$row = $pdo->query('SELECT * FROM candidates WHERE id = ' . $r['candidate_id'])->fetch();
check('stored: owner Amy, mobile normalised, email lower-cased, source referral', $row['owner_member_id'] == $A['id'] && $row['mobile'] === '+60123456789' && $row['email'] === 'amy@example.com' && $row['source'] === 'referral');
check('token is stored hashed, not in clear', $row['submit_token_hash'] === hash('sha256', $r['submit_token']) && $row['submit_token_hash'] !== $r['submit_token']);
check('consent version recorded; completed_at empty', $row['consent_version'] === CONSENT_VERSION && $row['completed_at'] === null);
check('IP stored only as a salted hash', $row['ip_hash'] === ip_hash($cfg, '203.0.113.5') && !str_contains((string)$row['ip_hash'], '203'));
throws('missing name', 422, fn () => start_candidate($pdo, $cfg, valid_start(['name' => ' ']), null));
throws('Singapore number', 422, fn () => start_candidate($pdo, $cfg, valid_start(['mobile' => '+65 9123 4567']), null));
throws('missing email', 422, fn () => start_candidate($pdo, $cfg, valid_start(['email' => '']), null));
throws('bad email', 422, fn () => start_candidate($pdo, $cfg, valid_start(['email' => 'a@b']), null));
throws('consent not ticked', 422, fn () => start_candidate($pdo, $cfg, valid_start(['consent' => false]), null));
throws('consent as string "true"', 422, fn () => start_candidate($pdo, $cfg, valid_start(['consent' => 'true']), null));
throws('bad purpose', 422, fn () => start_candidate($pdo, $cfg, valid_start(['purpose' => 'x']), null));
throws('age out of range', 422, fn () => start_candidate($pdo, $cfg, valid_start(['age' => 9]), null));
throws('edu not a number', 422, fn () => start_candidate($pdo, $cfg, valid_start(['edu' => 'abc']), null));
check('internship purpose + Chinese saved', (function () use ($pdo, $cfg) { $r = start_candidate($pdo, $cfg, valid_start(['purpose' => 'internship', 'lang' => 'zh', 'mobile' => '0187654321', 'email' => 'zh@example.com']), null);
    $x = $pdo->query('SELECT purpose, lang, source FROM candidates WHERE id = ' . $r['candidate_id'])->fetch(); return $x['purpose'] === 'internship' && $x['lang'] === 'zh' && $x['source'] === 'landing'; })());

echo "\nDuplicates (Fix 12: first advisor keeps the candidate)\n";
$d = start_candidate($pdo, $cfg, valid_start(['ref' => 'aa']), null);   // same mobile + email as Amy's first candidate, via AA's link
$drow = $pdo->query('SELECT * FROM candidates WHERE id = ' . $d['candidate_id'])->fetch();
check('stays with the first advisor (Amy), not AA', (int)$drow['owner_member_id'] === $A['id'] && $d['advisor_name'] === 'Amy A');
check('flagged as duplicate of the first record', (int)$drow['duplicate_of'] === $r['candidate_id']);
$d2 = start_candidate($pdo, $cfg, valid_start(['mobile' => '+60 12 345 6789', 'email' => 'other@example.com']), null);
check('match on mobile alone, and chains back to the ROOT record', (int)$pdo->query('SELECT duplicate_of FROM candidates WHERE id = ' . $d2['candidate_id'])->fetchColumn() === $r['candidate_id']);

echo "\nRate limit\n";
$rl = fresh_db(); create_member($rl, null, ['email' => 'you@x.com', 'name' => 'You', 'is_admin' => true]);
$n = 0; foreach (range(1, 3) as $i) { start_candidate($rl, $cfg, valid_start(['mobile' => "012345000$i", 'email' => "r$i@x.com"]), '198.51.100.9'); $n++; }
throws('4th start from one IP within the hour', 429, fn () => start_candidate($rl, $cfg, valid_start(['mobile' => '0123450009', 'email' => 'r9@x.com']), '198.51.100.9'));
check('a different IP is unaffected', start_candidate($rl, $cfg, valid_start(['mobile' => '0123450008', 'email' => 'r8@x.com']), '198.51.100.10')['candidate_id'] > 0);

echo "\nSubmitting answers\n";
$ans = sample_answers();
throws('unknown token', 404, fn () => submit_assessment($pdo, str_repeat('a', 64), $ans));
throws('malformed token', 404, fn () => submit_assessment($pdo, "x' OR 1=1 --", $ans));
throws('invalid answers rejected and nothing saved', 422, fn () => submit_assessment($pdo, $r['submit_token'], ['A' => []]));
check('...candidate still open after a rejected submit', $pdo->query('SELECT completed_at FROM candidates WHERE id = ' . $r['candidate_id'])->fetchColumn() === null);
$sub = submit_assessment($pdo, $r['submit_token'], $ans);
check('server-calculated code ESC, advisor Amy', $sub['code'] === 'ESC' && $sub['advisor_name'] === 'Amy A' && !$sub['already']);
$arow = $pdo->query('SELECT * FROM assessments WHERE candidate_id = ' . $r['candidate_id'])->fetch();
check('assessment row saved with raw answers and scores', $arow['code'] === 'ESC' && json_decode($arow['answers_json'], true)['A']['E'] === range(0, 6) && json_decode($arow['scores_json'], true)['E']['total'] === 34);
check('candidate marked completed', $pdo->query('SELECT completed_at FROM candidates WHERE id = ' . $r['candidate_id'])->fetchColumn() !== null);
$again = submit_assessment($pdo, $r['submit_token'], $ans);
check('second submit returns the saved result, flagged already, no duplicate row', $again['already'] && $again['code'] === 'ESC' && (int)$pdo->query('SELECT COUNT(*) FROM assessments WHERE candidate_id = ' . $r['candidate_id'])->fetchColumn() === 1);
$old = start_candidate($pdo, $cfg, valid_start(['mobile' => '0129990000', 'email' => 'old@x.com']), null);
$pdo->prepare('UPDATE candidates SET started_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 90000), $old['candidate_id']]);
throws('token older than 24h', 410, fn () => submit_assessment($pdo, $old['submit_token'], $ans));

echo "\nEmails\n";
$mailer = new MemoryMailer();
$cand = $pdo->query('SELECT * FROM candidates WHERE id = ' . $r['candidate_id'])->fetch();
$res = notify_completion($pdo, $cfg, $mailer, $cand, $sub['scores'], $sub['code']);
$to = array_column($mailer->sent, 'to');
check('candidate + Amy + You were emailed (3), each once', $res === ['candidate_sent' => true, 'notified' => 2] && count($to) === 3 && count(array_unique($to)) === 3);
check('recipients: candidate, advisor Amy, upline You', in_array('amy@example.com', $to, true) && in_array('a@x.com', $to, true) && in_array('you@x.com', $to, true));
check('AA (not in the chain) was not emailed', !in_array('aa@x.com', $to, true));
$cm = $mailer->sent[0]; $am = $mailer->sent[1];
check('candidate subject has the code', $cm['subject'] === 'Your Career Code: ESC');
check('candidate email: names the types, advisor, WhatsApp', str_contains($cm['text'], 'Enterprising') && str_contains($cm['text'], 'Social') && str_contains($cm['text'], 'Amy A') && str_contains($cm['html'], 'wa.me/60126718056'));
check('candidate email shows strengths and fields', str_contains($cm['text'], 'Persuasion') && str_contains($cm['text'], 'Financial services'));
check('advisor notice links to the dashboard for THIS candidate', str_contains($am['text'], 'https://dreamhub-e3.com/members/career-code/dashboard/?candidate=' . $r['candidate_id']));
check('advisor notice has NO scores, verdict or interview notes', !preg_match('/\b34\b|Strong fit|Good fit|Borderline|interview|criteria|Watch-outs/i', $am['text'] . $am['html']));
check('send timestamps recorded', $pdo->query('SELECT result_emailed_at IS NOT NULL AND notified_at IS NOT NULL FROM candidates WHERE id = ' . $r['candidate_id'])->fetchColumn() == 1);
$evil = $cand; $evil['name'] = "<script>alert(1)</script> Bob\r\nBcc: spy@evil.com"; $evil['lang'] = 'zh';
$zm = build_candidate_email($evil, $sub['scores'], $A, $cfg);
check('Chinese email: Chinese subject and text', $zm['subject'] === '你的职业代码：ESC' && str_contains($zm['text'], '企业型'));
check('HTML-escapes a hostile name', !str_contains($zm['html'], '<script>') && str_contains($zm['html'], '&lt;script&gt;'));
$an = build_advisor_notice($evil, 'ESC', $A, $me, $cfg, true);
check('advisor notice: hostile name cannot add headers (CR/LF stripped)', !preg_match('/[\r\n]/', $an['subject']) && str_contains($an['html'], 'taken the test before'));
$mailer2 = new MemoryMailer(); $mailer2->fail = true;
$fails = notify_completion($pdo, $cfg, $mailer2, $cand, $sub['scores'], $sub['code']);
check('mail failure does not throw and reports nothing sent', $fails === ['candidate_sent' => false, 'notified' => 0]);
update_member($pdo, $me['id'], $A['id'], ['is_active' => false]);
$m3 = new MemoryMailer(); notify_completion($pdo, $cfg, $m3, $cand, $sub['scores'], $sub['code']);
check('inactive advisor is skipped, upline still told', !in_array('a@x.com', array_column($m3->sent, 'to'), true) && in_array('you@x.com', array_column($m3->sent, 'to'), true));
check('mail_encode_header handles Chinese and strips newlines', str_starts_with(mail_encode_header("你好\r\nBcc: x"), '=?UTF-8?B?') && !preg_match('/[\r\n]/', mail_encode_header("a\r\nb")));

try { mail_address("a@b.com\r\nBcc: x@y.com"); check('rejects header injection in an address', false); } catch (RuntimeException $e) { check('rejects header injection in an address', true); }

echo "\nTurnstile\n";
$tcfg = ['turnstile' => ['secret' => 's3']];
$okHttp = fn ($url, $f) => ['success' => $f['response'] === 'good' && $f['secret'] === 's3'];
check('good token passes', verify_turnstile($tcfg, 'good', '1.2.3.4', $okHttp));
check('bad token fails', !verify_turnstile($tcfg, 'bad', null, $okHttp));
check('empty token fails without calling out', !verify_turnstile($tcfg, '', null, fn () => throw new Exception('must not be called')));
check('no secret configured -> fails closed', !verify_turnstile([], 'good', null, $okHttp));
check('network failure fails closed', !verify_turnstile($tcfg, 'good', null, fn () => null));
check('success must be exactly true', !verify_turnstile($tcfg, 'good', null, fn () => ['success' => 'yes']));

echo "\nSMTP client against a fake server\n";
$port = random_int(20000, 40000); $out = sys_get_temp_dir() . "/e3smtp_$port.jsonl"; @unlink($out);
$proc = proc_open(['python3', __DIR__ . '/fakes/smtp.py', (string)$port, $out], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
$smtp = new SmtpMailer('127.0.0.1', $port, '', 'mailer@example.com', 'secret', 'hello@dreamhub-e3.com', 'DreamHub E3');
$smtp->send('amy@example.com', 'Your Career Code: ESC 你好', '<p>Hi 你好</p>', "Hi 你好\n.dot line\nbye");
$msg = json_decode(trim((string)file_get_contents($out)), true);
check('authenticated and addressed correctly', $msg['auth'] === 'mailer@example.com' && $msg['from'] === '<hello@dreamhub-e3.com>' && $msg['to'] === ['<amy@example.com>']);
check('headers present and subject encoded', str_contains($msg['data'], 'From: DreamHub E3 <hello@dreamhub-e3.com>') && str_contains($msg['data'], 'Subject: =?UTF-8?B?') && str_contains($msg['data'], 'multipart/alternative'));
preg_match_all('/Content-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/', $msg['data'], $mm);
$decoded = implode("\n", array_map(fn ($b) => (string)base64_decode($b), $mm[1]));
check('Chinese text, HTML and a line starting with "." survive', str_contains($decoded, 'Hi 你好') && str_contains($decoded, "\n.dot line\n") && str_contains($decoded, '<p>Hi'));
$bad = new SmtpMailer('127.0.0.1', $port, '', 'mailer@example.com', 'WRONG', 'hello@dreamhub-e3.com', 'DreamHub E3');
try { $bad->send('a@b.com', 's', 'h', 't'); check('wrong password is reported', false); } catch (RuntimeException $e) { check('wrong password is reported', str_contains($e->getMessage(), '535')); }
try { (new SmtpMailer('127.0.0.1', 1, '', '', '', 'a@b.com', 'x', true, 2))->send('a@b.com', 's', 'h', 't'); check('connection failure is reported', false); } catch (RuntimeException $e) { check('connection failure is reported', true); }
proc_terminate($proc); proc_close($proc); @unlink($out);

finish();
