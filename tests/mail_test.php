<?php
declare(strict_types=1);

// Run: php tests/mail_test.php   The mail test helper: success, refusal, wrong password, unreachable server.
require_once __DIR__ . '/../private/lib/mailer.php';
require_once __DIR__ . '/helpers.php';
if (!class_exists('HttpError')) { require_once __DIR__ . '/../private/lib/http.php'; }

$ok = new MemoryMailer();
$r = run_mail_test(['mail' => ['transport' => 'mail', 'from' => 'hello@dreamhub-e3.com']], $ok, 'me@example.com');
check('success: ok, one message sent to the admin, advice mentions the spam folder', $r['ok'] && $r['error'] === null && count($ok->sent) === 1 && $ok->sent[0]['to'] === 'me@example.com' && str_contains($r['advice'], 'spam'));
$bad = new MemoryMailer(); $bad->fail = true;
$r = run_mail_test(['mail' => ['transport' => 'mail']], $bad, 'me@example.com');
check('mail() failure: not ok, error shown, advice says switch to SMTP', !$r['ok'] && $r['error'] === 'simulated mail failure' && str_contains($r['advice'], 'refused the message'));

$port = random_int(20000, 40000); $out = sys_get_temp_dir() . "/e3mt_$port.jsonl"; @unlink($out);
$proc = proc_open(['python3', __DIR__ . '/fakes/smtp.py', (string)$port, $out], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
$cfg = fn (string $pass, int $p) => ['mail' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => $p, 'secure' => '', 'user' => 'mailer@example.com', 'pass' => $pass, 'from' => 'hello@dreamhub-e3.com']];
$r = run_mail_test($cfg('secret', $port), make_mailer($cfg('secret', $port)), 'me@example.com');
check('SMTP with the right login: ok, message really delivered to the server', $r['ok'] && $r['transport'] === 'smtp' && is_file($out) && str_contains((string)file_get_contents($out), 'me@example.com'));
$r = run_mail_test($cfg('wrong', $port), make_mailer($cfg('wrong', $port)), 'me@example.com');
check('SMTP with a wrong password: not ok, 535, advice says check the mailbox login', !$r['ok'] && str_contains((string)$r['error'], '535') && str_contains($r['advice'], 'mailbox login'));
$r = run_mail_test($cfg('secret', 1), make_mailer($cfg('secret', 1)), 'me@example.com');
check('SMTP to a closed port: not ok, advice says check host and port', !$r['ok'] && str_contains($r['advice'], 'host and port'));
proc_terminate($proc); proc_close($proc); @unlink($out);

echo "\nPHP mail() message format (regression: mixed line endings glued the headers together)\n";
$cap = sys_get_temp_dir() . '/e3_capture_' . getmypid();
file_put_contents($cap . '.sh', "#!/bin/sh\necho \"\$@\" > $cap.args\ncat > $cap.eml\n"); chmod($cap . '.sh', 0755);
file_put_contents($cap . '.php', "<?php require '" . __DIR__ . "/../private/lib/mailer.php'; (new PhpMailMailer('hello@dreamhub-e3.com', 'DreamHub E3'))->send('easypolicy@gmail.com', 'New Career Code result: Kian Goh (SAE)', '<p>hi</p>', \"hi\\n.dot\\n\");");
shell_exec('php -d sendmail_path=' . escapeshellarg($cap . '.sh -t -i') . ' ' . escapeshellarg($cap . '.php') . ' 2>&1');
$raw = (string)@file_get_contents($cap . '.eml');
[$head, $bodyPart] = explode("\r\n\r\n", $raw, 2) + ['', ''];
$bareLf = preg_match_all('/(?<!\r)\n/', $raw);
check('every line of the message ends with CRLF (no bare LF anywhere)', $raw !== '' && $bareLf === 0, "bare LFs: $bareLf");
check('headers are separate lines: To, Subject, From, Date, Message-ID, MIME-Version, Content-Type', preg_match_all('/^(To|Subject|From|Date|Message-ID|MIME-Version|Content-Type): /m', $head) === 7);
check('exactly one From header, with exactly one address', substr_count($head, "From: ") === 1 && preg_match('/^From: DreamHub E3 <hello@dreamhub-e3\.com>$/m', str_replace("\r", '', $head)) === 1);
check('the Message-ID is not inside the From line', !preg_match('/^From:[^\n]*Message-ID/mi', $head));
check('the envelope sender is passed to the mail system as -fhello@dreamhub-e3.com', str_contains((string)@file_get_contents($cap . '.args'), '-fhello@dreamhub-e3.com'));
check('the body keeps its line starting with a dot intact (base64)', str_contains($bodyPart, 'Content-Transfer-Encoding: base64'));
foreach (['.sh', '.php', '.eml', '.args'] as $x) { @unlink($cap . $x); }
finish();
