<?php
declare(strict_types=1);

// Run: php tests/phase7.php   DISC: content, scoring, invites, the candidate's side, notices, tags.
require_once __DIR__ . '/../private/lib/disc.php';
require_once __DIR__ . '/../private/lib/live.php';
require_once __DIR__ . '/../private/lib/candidate_access.php';
require_once __DIR__ . '/helpers.php';

$Q = disc_data()['questions'];

echo "Content\n";
check('28 questions, each uses D, I, S and C once', count($Q) === 28 && array_reduce($Q, fn ($ok, $q) => $ok && count($q) === 4 && count(array_unique($q)) === 4 && !array_diff($q, DISC_TYPES), true));
$node = trim((string)shell_exec('which node 2>/dev/null'));
if ($node === '') { echo "  skip node missing\n"; } else {
    $js = 'const f=new Function(require("fs").readFileSync(process.argv[1],"utf8")+";return DISC_WORDS;")();console.log(JSON.stringify(f));';
    $w = json_decode((string)shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg($js) . ' ' . escapeshellarg(__DIR__ . '/../public_html/career-code/assets/disc-words.js') . ' 2>&1'), true);
    check('disc-words.js has 28 questions of 4 words, in English and Chinese', is_array($w) && count($w) === 28 && array_reduce($w, fn ($ok, $q) => $ok && count($q) === 4 && array_reduce($q, fn ($o, $x) => $o && $x['en'] !== '' && $x['zh'] !== '', true), true));
    $en = array_merge(...array_map(fn ($q) => array_column($q, 'en'), $w));
    check('all 112 English words are different', count(array_unique($en)) === 112);
}
$jsText = file_get_contents(__DIR__ . '/../public_html/career-code/assets/disc-words.js');
check('the public words file has no style letters or scoring', !preg_match('/"[DISC]"|score|most|least|style/i', $jsText));
$T = disc_text();
$keys = ['name', 'tag', 'desc', 'strengths', 'work', 'tips', 'also', 'talk', 'pressure', 'role', 'questions'];
check('every style has all texts in English and Chinese', array_reduce(['en', 'zh'], fn ($ok, $l) => $ok && array_reduce(DISC_TYPES, fn ($o, $t) => $o && !array_diff($keys, array_keys($T[$l]['type'][$t])), true), true));
check('English and Chinese lists have the same lengths', array_reduce(DISC_TYPES, fn ($ok, $t) => $ok && array_reduce(['strengths', 'tips', 'talk', 'pressure', 'questions'], fn ($o, $k) => $o && count($T['en']['type'][$t][$k]) === count($T['zh']['type'][$t][$k]), true), true));
$cand = '';
foreach (['en', 'zh'] as $l) { foreach (DISC_TYPES as $t) { $v = disc_candidate_view($t . 'I', $l); $cand .= json_encode($v, JSON_UNESCAPED_UNICODE); } }
check('candidate-facing text never says fit / suitable / qualify / verdict / 适合 / 合格', !preg_match('/\bfit\b|suitab|qualif|verdict|适合|合格|录取/iu', $cand));

echo "\nScoring\n";
$pos = fn (string $type, int $q) => array_search($type, $Q[$q], true);
$answers = fn (string $most, string $least) => array_map(fn ($q) => ['most' => $pos($most, $q), 'least' => $pos($least, $q)], range(0, 27));
$r = score_disc($answers('D', 'S'));
check('always choosing D as most and S as least: D +28, S -28, I and C 0', $r['scores'] === ['D' => 28, 'I' => 0, 'S' => -28, 'C' => 0]);
check('...so the style is D alone (the next one is not close enough)', disc_style($r['scores']) === 'D');
mt_srand(7);
$allZero = true;
for ($n = 0; $n < 300; $n++) {
    $a = [];
    for ($i = 0; $i < 28; $i++) { $m = mt_rand(0, 3); do { $l = mt_rand(0, 3); } while ($l === $m); $a[] = ['most' => $m, 'least' => $l]; }
    $allZero = $allZero && array_sum(score_disc($a)['scores']) === 0;
}
check('on 300 random answer sets the four scores always add up to 0', $allZero);
$bad = fn (callable $f) => (function () use ($answers, $f) { $a = $answers('D', 'S'); $f($a); return $a; })();
throws('27 answers', 422, fn () => score_disc(array_slice($answers('D', 'S'), 0, 27)));
throws('most = least', 422, fn () => score_disc($bad(fn (&$a) => $a[3]['least'] = $a[3]['most'])));
throws('position 4', 422, fn () => score_disc($bad(fn (&$a) => $a[0]['most'] = 4)));
throws('negative position', 422, fn () => score_disc($bad(fn (&$a) => $a[0]['least'] = -1)));
throws('text instead of a number', 422, fn () => score_disc($bad(fn (&$a) => $a[0]['most'] = '1')));
throws('missing "least"', 422, fn () => score_disc($bad(function (&$a) { unset($a[5]['least']); })));
throws('not a list', 422, fn () => score_disc(['a' => 1]));
throws('not an array', 422, fn () => score_disc('hi'));

echo "\nStyle, levels, flags, alignment\n";
check('close second style is named (I 10, S 8)', disc_style(['D' => -6, 'I' => 10, 'S' => 8, 'C' => -12]) === 'IS');
check('far second style is not named (I 12, S 2)', disc_style(['D' => -6, 'I' => 12, 'S' => 2, 'C' => -8]) === 'I');
check('a second style below zero is never named', disc_style(['D' => -4, 'I' => -3, 'S' => -2, 'C' => 9]) === 'C');
check('ties keep D, I, S, C order', disc_style(['D' => 5, 'I' => 5, 'S' => -5, 'C' => -5]) === 'DI');
check('levels: >= 6 high, <= -6 low, else moderate', disc_levels(['D' => 6, 'I' => 5, 'S' => -6, 'C' => -5]) === ['D' => 'high', 'I' => 'mid', 'S' => 'low', 'C' => 'mid']);
$allFirst = array_fill(0, 28, 0); $mixed = array_map(fn ($i) => $i % 4, range(0, 27));
check('flag: finished in 90 seconds', array_column(disc_flags($mixed, array_reverse($mixed), ['D' => 3, 'I' => -3, 'S' => 6, 'C' => -6], 90), 'key') === ['fast']);
check('flag: no flag at 5 minutes with a varied profile', disc_flags($mixed, array_map(fn ($i) => ($i + 1) % 4, range(0, 27)), ['D' => 6, 'I' => -2, 'S' => 4, 'C' => -8], 300) === []);
check('flag: always the same position', in_array('position', array_column(disc_flags($allFirst, array_map(fn ($i) => 1 + $i % 3, range(0, 27)), ['D' => 6, 'I' => -2, 'S' => 4, 'C' => -8], 300), 'key'), true));
check('flag: very one-sided and very flat', in_array('extreme', array_column(disc_flags($mixed, $mixed, ['D' => 25, 'I' => -10, 'S' => -5, 'C' => -10], 300), 'key'), true) && in_array('flat', array_column(disc_flags($mixed, $mixed, ['D' => 2, 'I' => -1, 'S' => 0, 'C' => -1], 300), 'key'), true));
check('alignment: ESC with an I style is consistent (E is in I\'s list)', disc_alignment('I', 'ESC') === 'consistent');
check('alignment: S style with ESC is consistent through the second letter... S matches S, E first no', disc_alignment('S', 'ESC') === 'partly');
check('alignment: D style with SCI differs', disc_alignment('D', 'SCI') === 'differs');
check('alignment: no Career Code result', disc_alignment('D', null) === 'none');

echo "\nTags (what an advisor sees on the list)\n";
$now = 1_800_000_000; $ts = fn (int $off) => gmdate('Y-m-d H:i:s', $now + $off);
$inv = fn (int $createdOff, int $expOff, ?int $sentOff = null, bool $done = false) => ['created_at' => $ts($createdOff), 'expires_at' => $ts($expOff), 'last_sent_at' => $sentOff === null ? null : $ts($sentOff), 'completed_at' => $done ? $ts(0) : null];
$res = ['x' => 1];
$tag = fn (?string $v, bool $c, string $st, ?array $i, ?array $r) => disc_state($v, $c, $st, $i, $r, $now)['tag'];
check('strong fit, new: ready', $tag('v-strong', true, 'new', null, null) === 'ready');
check('good fit: ready', $tag('v-good', true, 'contacted', null, null) === 'ready');
check('borderline: ready', $tag('v-review', true, 'new', null, null) === 'ready');
check('not a typical fit: no tag, cannot invite', $tag('v-low', true, 'new', null, null) === null && !disc_state('v-low', true, 'new', null, null, $now)['can_invite']);
check('career test not finished: no tag', $tag('v-strong', false, 'new', null, null) === null);
check('not suitable / joined: no tag and cannot invite', $tag('v-strong', true, 'not_suitable', null, null) === null && $tag('v-strong', true, 'joined', null, null) === null && !disc_state('v-strong', true, 'joined', null, null, $now)['can_invite']);
check('invited 1 day ago: invited', $tag('v-good', true, 'new', $inv(-86400, 13 * 86400), null) === 'invited');
check('invited 3 days ago: not done after 3 days', $tag('v-good', true, 'new', $inv(-3 * 86400, 11 * 86400), null) === 'overdue');
check('a reminder resets the 3-day clock', $tag('v-good', true, 'new', $inv(-5 * 86400, 9 * 86400, -3600), null) === 'invited');
check('past the 14 days: expired', $tag('v-good', true, 'new', $inv(-15 * 86400, -86400), null) === 'expired');
check('finished: done', $tag('v-good', true, 'new', $inv(-86400, 13 * 86400, null, true), $res) === 'done');
check('finished then marked Not suitable: still shows done', $tag('v-good', true, 'not_suitable', $inv(-86400, 13 * 86400, null, true), $res) === 'done');
check('invited then marked Not suitable: tag switched off', $tag('v-good', true, 'not_suitable', $inv(-86400, 13 * 86400), null) === null);
check('invited again after a result: invited, not done', $tag('v-good', true, 'new', $inv(-3600, 14 * 86400), $res) === 'invited');

echo "\nInvites\n";
$pdo = fresh_db();
$me = create_member($pdo, null, ['email' => 'you@x.com', 'name' => 'You', 'rank' => 'unit_manager', 'is_admin' => true, 'referral_code' => 'cf']);
$A  = create_member($pdo, $me['id'], ['email' => 'amy@x.com', 'name' => 'Amy', 'rank' => 'unit_manager', 'upline_id' => $me['id'], 'referral_code' => 'amy']);
$AA = create_member($pdo, $me['id'], ['email' => 'alice@x.com', 'name' => 'Alice', 'upline_id' => $A['id'], 'referral_code' => 'alice']);
$B  = create_member($pdo, $me['id'], ['email' => 'ben@x.com', 'name' => 'Ben', 'upline_id' => $me['id'], 'referral_code' => 'ben']);
$cfg = ['default_owner_email' => 'you@x.com', 'ip_salt' => 's', 'rate_limit_per_hour' => 1000, 'base_url' => 'https://dreamhub-e3.com'];
$n = 0;
$mk = function (string $ref, string $name, ?string $email = 'x') use ($pdo, $cfg, &$n): array {
    $n++;
    $d = valid_start(['ref' => $ref, 'name' => $name, 'mobile' => '0112000' . str_pad((string)$n, 4, '0', STR_PAD_LEFT), 'email' => $email === null ? '' : "c$n@e.com"]);
    $r = $email === null ? live_start($pdo, $cfg, $GLOBALS['AA'], $d) : start_candidate($pdo, $cfg, $d, null);
    submit_assessment($pdo, $r['submit_token'], sample_answers());
    return ['id' => $r['candidate_id'], 'mobile' => '+60112000' . str_pad((string)$n, 4, '0', STR_PAD_LEFT), 'email' => "c$n@e.com"];
};
$kian = $mk('alice', 'Kian Goh');
$mail = new MemoryMailer();
$act = fn (array $who, string $action, int $cid, string $ch = 'email', ?MemoryMailer $m = null) => disc_advisor_action($pdo, $cfg, $m ?? $mail, $who, $action, $cid, $ch);

$r1 = $act($AA, 'invite', $kian['id']);
check('invite by email: sent once, to the candidate, with the link', $r1['emailed'] && $r1['created'] && count($mail->sent) === 1 && $mail->sent[0]['to'] === $kian['email'] && str_contains($mail->sent[0]['text'], $r1['link']));
check('the link is on dreamhub-e3.com/career-code/disc/?t=<64 hex>', (bool)preg_match('#^https://dreamhub-e3\.com/career-code/disc/\?t=[a-f0-9]{64}$#', $r1['link']));
check('the invite email does not hint at the verdict', !preg_match('/\bfit\b|suitab|qualif|strong|good|borderline|passed/i', $mail->sent[0]['text'] . $mail->sent[0]['html']));
check('it says how long the link works and asks for the last 4 digits', str_contains($mail->sent[0]['text'], 'last 4 digits') && str_contains($mail->sent[0]['text'], 'valid until'));
$again = $act($AA, 'invite', $kian['id']);
check('inviting again while one is open re-sends the SAME link (no second invite)', $again['invite']['id'] === $r1['invite']['id'] && !$again['created'] && count($mail->sent) === 2 && (int)$pdo->query('SELECT COUNT(*) FROM disc_invites')->fetchColumn() === 1);
$rem = $act($AA, 'remind', $kian['id']);
check('remind: same link, subject starts with Reminder, sent_count 3', $rem['invite']['id'] === $r1['invite']['id'] && str_starts_with($mail->sent[2]['subject'], 'Reminder') && (int)$pdo->query('SELECT sent_count FROM disc_invites')->fetchColumn() === 3);
$link = $act($AA, 'remind', $kian['id'], 'link');
check('channel "link": returns the link and sends nothing', $link['link'] === $r1['link'] && !$link['emailed'] && count($mail->sent) === 3);
$re = $act($AA, 'reinvite', $kian['id']);
check('re-invite: a NEW link, and the old one stops working', $re['created'] && $re['link'] !== $r1['link'] && disc_active_invite($pdo, $kian['id'])['token'] === $re['invite']['token']);
throws('the old link is expired', 410, fn () => disc_submit($pdo, $r1['invite']['token'], substr($kian['mobile'], -4), true, array_map(fn ($q) => ['most' => 0, 'least' => 1], range(0, 27)), 300, 'en'));
$mail2 = new MemoryMailer(); $mail2->fail = true;
$kayla = $mk('alice', 'Kayla Tan');
$f = $act($AA, 'invite', $kayla['id'], 'email', $mail2);
check('mail failure: the invite still exists, emailed = false (advisor can copy the link)', !$f['emailed'] && $f['created'] && disc_active_invite($pdo, $kayla['id']) !== null);
$noMail = $mk('alice', 'No Email', null);
throws('email channel without an address: 422', 422, fn () => $act($AA, 'invite', $noMail['id']));
$nm = $act($AA, 'invite', $noMail['id'], 'link');
check('no email: Copy link still works', $nm['created'] && !$nm['emailed']);
$low = start_candidate($pdo, $cfg, valid_start(['ref' => 'alice', 'name' => 'Low Lim', 'mobile' => '0113000001', 'email' => 'low@e.com']), null);
$lowRaw = array_fill_keys(HOLLAND_TYPES, ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 7]); $lowRaw['R'] = ['a' => 9, 'b' => 9, 'c' => 9, 'd' => 7]; $lowScores = manual_scores($lowRaw, true);
store_assessment($pdo, $low['candidate_id'], 'manual', null, $lowScores, holland_code($lowScores), (int)$AA['id']);
throws('not a typical fit: refused (409)', 409, fn () => $act($AA, 'invite', $low['candidate_id']));
$pdo->prepare("UPDATE candidates SET pipeline_status = 'not_suitable' WHERE id = ?")->execute([$kayla['id']]);
throws('marked Not suitable: refused (409)', 409, fn () => $act($AA, 'remind', $kayla['id']));
$pdo->prepare("UPDATE candidates SET pipeline_status = 'new' WHERE id = ?")->execute([$kayla['id']]);
$unfinished = start_candidate($pdo, $cfg, valid_start(['ref' => 'alice', 'name' => 'Not Done', 'mobile' => '0114000001', 'email' => 'nd@e.com']), null);
throws('career test not finished: refused', 409, fn () => $act($AA, 'invite', $unfinished['candidate_id']));
$mailsBefore = count($mail->sent);
$live = $act($AA, 'live', $kian['id']);
check('live: no email, mode live, valid about 24 hours, the earlier link is closed', $live['invite']['mode'] === 'live' && count($mail->sent) === $mailsBefore && disc_ts($live['invite']['expires_at']) - time() < 25 * 3600 && disc_ts($live['invite']['expires_at']) - time() > 23 * 3600);
check('the invite is audited', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'disc.%'")->fetchColumn() >= 5);

echo "\nThe candidate's side\n";
$info = disc_public_info($pdo, $re['invite']['token']);
check('first screen shows only the first name, the language and that digits are needed', $info === ['first_name' => 'Kian', 'lang' => 'en', 'needs_digits' => true, 'state' => 'expired'] || $info['first_name'] === 'Kian');
$kian2 = $mk('alice', 'Kenny Lau');
$ri = $act($AA, 'invite', $kian2['id']);
$tok = $ri['invite']['token'];
$inf = disc_public_info($pdo, $tok);
check('info for a fresh invite: state ok, digits needed, resume optional and not yet uploaded', array_diff_key($inf, ['resume' => 1]) === ['first_name' => 'Kenny', 'lang' => 'en', 'needs_digits' => true, 'state' => 'ok'] && $inf['resume'] === ['required' => false, 'have' => false, 'name' => null]);
throws('unknown token: 404', 404, fn () => disc_public_info($pdo, str_repeat('a', 64)));
throws('malformed token: 404', 404, fn () => disc_public_info($pdo, 'abc'));
$ans = $answers('I', 'C');
$last4 = substr($kian2['mobile'], -4);
throws('wrong digits: 403 with tries left', 403, fn () => disc_submit($pdo, $tok, '0000', true, $ans, 600, 'en'));
check('...counted', (int)$pdo->query("SELECT wrong_tries FROM disc_invites WHERE token = '$tok'")->fetchColumn() === 1);
throws('verify with wrong digits: 403 and counted', 403, fn () => disc_verify($pdo, $tok, '9999'));
check('verify counted the try (now 2)', (int)$pdo->query("SELECT wrong_tries FROM disc_invites WHERE token = '$tok'")->fetchColumn() === 2);
disc_verify($pdo, $tok, $last4);
check('verify with the right digits passes silently', true);
throws('missing consent: 422', 422, fn () => disc_submit($pdo, $tok, $last4, false, $ans, 600, 'en'));
throws('bad answers: 422', 422, fn () => disc_submit($pdo, $tok, $last4, true, array_slice($ans, 0, 5), 600, 'en'));
check('bad answers and missing consent do not use up the invite or count as wrong digits', (int)$pdo->query("SELECT wrong_tries FROM disc_invites WHERE token = '$tok'")->fetchColumn() === 2 && $pdo->query("SELECT completed_at FROM disc_invites WHERE token = '$tok'")->fetchColumn() === null);
for ($i = 0; $i < 2; $i++) { try { disc_submit($pdo, $tok, '1111', true, $ans, 600, 'en'); } catch (HttpError $e) {} }
try { disc_submit($pdo, $tok, '1111', true, $ans, 600, 'en'); check('5th wrong try locks it', false); } catch (HttpError $e) { check('5th wrong try locks it (message tells them to ask the advisor)', str_contains($e->getMessage(), 'advisor') && $e->status === 403); }
throws('locked even with the right digits: 423', 423, fn () => disc_submit($pdo, $tok, $last4, true, $ans, 600, 'en'));
check('info says locked', disc_public_info($pdo, $tok)['state'] === 'locked');
$rem2 = $act($AA, 'reinvite', $kian2['id']);
$tok2 = $rem2['invite']['token'];
$mailN = new MemoryMailer();
$done = disc_submit($pdo, $tok2, $last4, true, $ans, 600, 'en');
check('right digits: result saved, style I (I +28, C -28)', $done['style'] === 'I' && (int)$pdo->query('SELECT COUNT(*) FROM disc_results WHERE candidate_id = ' . $kian2['id'])->fetchColumn() === 1);
check('candidate view is positive and has no verdict wording', $done['view']['label'] === 'Influence' && !preg_match('/suitab|verdict/i', json_encode($done['view'])));
throws('the same link cannot be used twice', 410, fn () => disc_submit($pdo, $tok2, $last4, true, $ans, 600, 'en'));
check('info says done', disc_public_info($pdo, $tok2)['state'] === 'done');
$sent = notify_disc_completion($pdo, $cfg, $mailN, $done);
$to = array_column($mailN->sent, 'to'); sort($to);
check('candidate + Alice (owner) + Amy + You are emailed', $sent['candidate_sent'] && $sent['notified'] === 3 && $to === ['alice@x.com', 'amy@x.com', $kian2['email'], 'you@x.com'], json_encode($to));
$adv = array_values(array_filter($mailN->sent, fn ($m) => $m['to'] === 'amy@x.com'))[0];
check('advisor notice is a link only: no style, no scores', str_contains($adv['text'], 'candidate=' . $kian2['id']) && !preg_match('/Influence|style|score|\bI\b.*[+-]\d/i', $adv['text']));
$cm = array_values(array_filter($mailN->sent, fn ($m) => $m['to'] === $kian2['email']))[0];
check('candidate email carries the style and tips', str_contains($cm['text'], 'Influence') && str_contains($cm['text'], 'Tips to grow'));

echo "\nIn the room (live) and reassignment\n";
$liveC = $mk('alice', 'Lena Wong');
$lv = $act($AA, 'live', $liveC['id']);
check('live info: no digits needed', disc_public_info($pdo, $lv['invite']['token'])['needs_digits'] === false);
$d2 = disc_submit($pdo, $lv['invite']['token'], '', true, $answers('S', 'D'), 700, 'zh');
$mL = new MemoryMailer(); notify_disc_completion($pdo, $cfg, $mL, $d2);
$toL = array_column($mL->sent, 'to'); sort($toL);
check('live: finished without digits; Alice (in the room) is not emailed, Amy and You are; Chinese email for a Chinese session', $toL === ['amy@x.com', $liveC['email'], 'you@x.com'] && str_contains(implode('|', array_column($mL->sent, 'subject')), '你的 DISC'), json_encode($toL));
$mv = $mk('alice', 'Moved Mei');
$iv = $act($AA, 'invite', $mv['id']);
$pdo->prepare('UPDATE candidates SET owner_member_id = ? WHERE id = ?')->execute([$B['id'], $mv['id']]);
$d3 = disc_submit($pdo, $iv['invite']['token'], substr($mv['mobile'], -4), true, $answers('C', 'I'), 700, 'en');
$m3 = new MemoryMailer(); notify_disc_completion($pdo, $cfg, $m3, $d3);
$t3 = array_column($m3->sent, 'to'); sort($t3);
check('reassigned to Ben before finishing: Ben and You get the notice, Alice and Amy do not', $t3 === ['ben@x.com', $mv['email'], 'you@x.com'], json_encode($t3));

echo "\nOn the candidate page\n";
$map = load_member_map($pdo);
$c = get_candidate($pdo, $map, $AA, $kian2['id'], 'en', $cfg);
check('detail: tag done, an advisor view with 4 scores and notes, no invite link left', $c['disc']['tag'] === 'done' && count($c['disc']['result']['rows']) === 4 && $c['disc']['result']['talk'] && $c['disc']['invite'] === null);
check('detail: alignment line compares with the Career Code (ESC vs I)', $c['disc']['result']['align']['key'] === 'consistent');
$cz = get_candidate($pdo, $map, $AA, $kian2['id'], 'zh', $cfg);
check('detail in Chinese', $cz['disc']['result']['label'] === '影响型' && $cz['disc']['result']['labels']['talk'] === '如何与对方沟通');
$c2 = get_candidate($pdo, $map, $AA, $kayla['id'], 'en', $cfg);
check('open invite: link shown to the advisor', $c2['disc']['tag'] === 'invited' && str_contains((string)$c2['disc']['invite']['link'], '/career-code/disc/?t='));
$lst = list_candidates($pdo, $map, $me, ['include_unfinished' => true]);
$tags = array_column(array_map(fn ($x) => ['n' => $x['name'], 't' => $x['disc']['tag']], $lst['candidates']), 't', 'n');
check('list: tags for each (done / invited / ready / none)', $tags['Kenny Lau'] === 'done' && $tags['Kayla Tan'] === 'invited' && $tags['Low Lim'] === null && $tags['Not Done'] === null, json_encode($tags));
$ben = get_candidate($pdo, $map, $B, $mv['id'], 'en', $cfg);
check('Ben (new owner) sees the finished result', $ben['disc']['tag'] === 'done');
try { get_candidate($pdo, $map, $B, $kian2['id'], 'en', $cfg); check('another branch cannot open it', false); } catch (HttpError $e) { check('another branch gets 404 for DISC too', $e->status === 404); }

echo "\nRedo and delete\n";
$redo = $act($AA, 'reinvite', $kian2['id']);
check('invite again after a result: a new open invite, state back to invited', get_candidate($pdo, $map, $AA, $kian2['id'], 'en', $cfg)['disc']['tag'] === 'invited' && $redo['created']);
$d4 = disc_submit($pdo, $redo['invite']['token'], $last4, true, $answers('D', 'C'), 700, 'en');
check('the newest result is the one shown', get_candidate($pdo, $map, $AA, $kian2['id'], 'en', $cfg)['disc']['result']['style'] === 'D');
delete_candidate($pdo, $map, $me, $kian2['id']);
check('deleting a candidate removes their DISC invites and results', (int)$pdo->query('SELECT COUNT(*) FROM disc_invites WHERE candidate_id = ' . $kian2['id'])->fetchColumn() === 0 && (int)$pdo->query('SELECT COUNT(*) FROM disc_results WHERE candidate_id = ' . $kian2['id'])->fetchColumn() === 0);
finish();
