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
finish();
