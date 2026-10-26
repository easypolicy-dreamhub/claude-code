<?php
declare(strict_types=1);

// Run: php tests/phase8.php   DISC step 2: resume uploads, internship rule, Team Activity lists.
require_once __DIR__ . '/../private/lib/disc.php';
require_once __DIR__ . '/../private/lib/activity.php';
require_once __DIR__ . '/../private/lib/team.php';
require_once __DIR__ . '/helpers.php';

$tmp = sys_get_temp_dir() . '/e3res-' . bin2hex(random_bytes(4));
mkdir($tmp);
$cfg = ['upload_dir' => $tmp . '/uploads', 'default_owner_email' => 'you@x.com', 'ip_salt' => 's', 'rate_limit_per_hour' => 1000, 'base_url' => 'https://dreamhub-e3.com'];
$mkfile = function (string $bytes, string $name) use ($tmp): array {
    $p = $tmp . '/up-' . bin2hex(random_bytes(3));
    file_put_contents($p, $bytes);
    return ['tmp_name' => $p, 'name' => $name, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK];
};
$pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
$docxBytes = (function () use ($tmp): string {
    $p = $tmp . '/t.docx'; $z = new ZipArchive(); $z->open($p, ZipArchive::CREATE); $z->addFromString('word/document.xml', '<w:document/>'); $z->addFromString('[Content_Types].xml', '<Types/>'); $z->close();
    return (string)file_get_contents($p);
})();

$pdo = fresh_db();
$me = create_member($pdo, null, ['email' => 'you@x.com', 'name' => 'You', 'rank' => 'group_manager', 'is_admin' => true, 'referral_code' => 'cf']);
$A  = create_member($pdo, $me['id'], ['email' => 'amy@x.com', 'name' => 'Amy', 'rank' => 'unit_manager', 'upline_id' => $me['id'], 'referral_code' => 'amy']);
$AA = create_member($pdo, $me['id'], ['email' => 'alice@x.com', 'name' => 'Alice', 'upline_id' => $A['id'], 'referral_code' => 'alice']);
$B  = create_member($pdo, $me['id'], ['email' => 'ben@x.com', 'name' => 'Ben', 'upline_id' => $me['id'], 'referral_code' => 'ben']);
$n = 0;
$mk = function (string $ref, string $name, string $purpose = 'career') use ($pdo, $cfg, &$n): array {
    $n++;
    $mobile = '0112000' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    $r = start_candidate($pdo, $cfg, valid_start(['ref' => $ref, 'name' => $name, 'purpose' => $purpose, 'mobile' => $mobile, 'email' => "c$n@e.com"]), null);
    submit_assessment($pdo, $r['submit_token'], sample_answers());
    return ['id' => $r['candidate_id'], 'mobile' => '+60' . substr($mobile, 1), 'email' => "c$n@e.com"];
};
$mail = new MemoryMailer();
$ans = array_map(fn ($q) => ['most' => 0, 'least' => 1], range(0, 27));

echo "What is accepted\n";
$c1 = $mk('alice', 'Cara Lim');
$r = resume_store($pdo, $cfg, $c1['id'], $mkfile($pdf, 'My CV.pdf'), 'candidate');
check('a PDF is accepted; name kept', $r['name'] === 'My CV.pdf' && $r['uploaded_by'] === 'candidate');
check('the file is in <upload_dir>/resumes under a random name, not the original', ($files = glob($cfg['upload_dir'] . '/resumes/*')) && count($files) === 1 && !str_contains($files[0], 'My CV') && preg_match('/\/' . $c1['id'] . '-[a-f0-9]{24}\.pdf$/', $files[0]) === 1);
check('a deny-all .htaccess is placed next to it', is_file($cfg['upload_dir'] . '/.htaccess'));
check('database row has size and type', resume_get($pdo, $c1['id'])['size'] === strlen($pdf) && resume_get($pdo, $c1['id'])['mime'] === 'application/pdf');
check('a real .docx is accepted', resume_store($pdo, $cfg, $c1['id'], $mkfile($docxBytes, 'cv.docx'), 'candidate')['name'] === 'cv.docx');
check('an old-style .doc is accepted', resume_store($pdo, $cfg, $c1['id'], $mkfile("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat('x', 50), 'old.doc'), 'candidate')['name'] === 'old.doc');
check('each upload REPLACES the last: one row and one file left', (int)$pdo->query('SELECT COUNT(*) FROM candidate_files WHERE candidate_id = ' . $c1['id'])->fetchColumn() === 1 && count(glob($cfg['upload_dir'] . '/resumes/*')) === 1);

echo "\nWhat is refused\n";
throws('an .exe', 422, fn () => resume_store($pdo, $cfg, $c1['id'], $mkfile('MZ' . str_repeat('x', 200), 'virus.exe'), 'candidate'));
throws('a program renamed .pdf', 422, fn () => resume_store($pdo, $cfg, $c1['id'], $mkfile('MZ' . str_repeat('x', 200), 'cv.pdf'), 'candidate'));
throws('a picture renamed .pdf', 422, fn () => resume_store($pdo, $cfg, $c1['id'], $mkfile("\x89PNG\r\n\x1a\n" . str_repeat('x', 50), 'cv.pdf'), 'candidate'));
throws('a zip renamed .docx with no Word content', 422, fn () => resume_store($pdo, $cfg, $c1['id'], $mkfile((function () use ($tmp) { $p = $tmp . '/z.zip'; $z = new ZipArchive(); $z->open($p, ZipArchive::CREATE); $z->addFromString('a.txt', 'hi'); $z->close(); return (string)file_get_contents($p); })(), 'cv.docx'), 'candidate'));
throws('plain text', 422, fn () => resume_store($pdo, $cfg, $c1['id'], $mkfile('hello', 'cv.txt'), 'candidate'));
throws('a PHP file', 422, fn () => resume_store($pdo, $cfg, $c1['id'], $mkfile('<?php echo 1;', 'cv.php'), 'candidate'));
throws('double extension cv.pdf.php', 422, fn () => resume_store($pdo, $cfg, $c1['id'], $mkfile($pdf, 'cv.pdf.php'), 'candidate'));
throws('empty file', 422, fn () => resume_store($pdo, $cfg, $c1['id'], $mkfile('', 'cv.pdf'), 'candidate'));
throws('over 5 MB', 413, fn () => resume_store($pdo, $cfg, $c1['id'], $mkfile($pdf . str_repeat('x', RESUME_MAX_BYTES), 'big.pdf'), 'candidate'));
throws('the server\'s own size limit', 413, fn () => resume_store($pdo, $cfg, $c1['id'], ['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'name' => 'x.pdf'], 'candidate'));
throws('no file chosen', 422, fn () => resume_store($pdo, $cfg, $c1['id'], ['error' => UPLOAD_ERR_NO_FILE], 'candidate'));
check('the refused files were not stored: still one file for Cara', (int)$pdo->query('SELECT COUNT(*) FROM candidate_files')->fetchColumn() === 1 && count(glob($cfg['upload_dir'] . '/resumes/*')) === 1);
check('names are cleaned: path tricks and odd characters', resume_clean_name('../../etc/passwd.pdf') === 'passwd.pdf' && resume_clean_name("a<b>c\"d.pdf") === 'abcd.pdf' && resume_clean_name('陈大文 简历.pdf') === '陈大文 简历.pdf' && resume_clean_name('///') === 'resume');
check('a name with no extension gets the real one added', resume_store($pdo, $cfg, $c1['id'], $mkfile($pdf, 'resume.PDF'), 'candidate')['name'] === 'resume.PDF' && resume_store($pdo, $cfg, $c1['id'], $mkfile($pdf, 'My%20CV.pdf'), 'candidate')['name'] === 'My20CV.pdf');

echo "\nInternship needs a resume; career does not\n";
$int = $mk('alice', 'Ivy Intern', 'internship');
$car = $mk('alice', 'Cal Career', 'career');
$iv = disc_advisor_action($pdo, $cfg, $mail, $AA, 'invite', $int['id'])['invite']['token'];
$cv = disc_advisor_action($pdo, $cfg, $mail, $AA, 'invite', $car['id'])['invite']['token'];
check('info: required for internship, not for career', disc_public_info($pdo, $iv)['resume'] === ['required' => true, 'have' => false, 'name' => null] && disc_public_info($pdo, $cv)['resume']['required'] === false);
throws('internship: finishing without a resume is refused (422)', 422, fn () => disc_submit($pdo, $iv, substr($int['mobile'], -4), true, $ans, 600, 'en'));
check('...and the invite is not used up', $pdo->query("SELECT completed_at FROM disc_invites WHERE token = '$iv'")->fetchColumn() === null);
throws('upload with wrong digits: 403 (and counted)', 403, fn () => disc_resume_upload($pdo, $cfg, $iv, '0000', $mkfile($pdf, 'ivy.pdf')));
check('wrong digits did count', (int)$pdo->query("SELECT wrong_tries FROM disc_invites WHERE token = '$iv'")->fetchColumn() === 1);
throws('upload of a bad file: 422', 422, fn () => disc_resume_upload($pdo, $cfg, $iv, substr($int['mobile'], -4), $mkfile('MZ....', 'ivy.pdf')));
$up = disc_resume_upload($pdo, $cfg, $iv, substr($int['mobile'], -4), $mkfile($pdf, 'Ivy CV.pdf'));
check('upload with the right digits works', $up['name'] === 'Ivy CV.pdf' && disc_public_info($pdo, $iv)['resume']['have'] === true && disc_public_info($pdo, $iv)['resume']['name'] === 'Ivy CV.pdf');
check('internship: now the questionnaire can be finished', disc_submit($pdo, $iv, substr($int['mobile'], -4), true, $ans, 600, 'en')['style'] !== '');
check('career: finishes with no resume', disc_submit($pdo, $cv, substr($car['mobile'], -4), true, $ans, 600, 'en')['style'] !== '' && resume_get($pdo, $car['id']) === null);
throws('upload after the invite is finished: 410', 410, fn () => disc_resume_upload($pdo, $cfg, $iv, substr($int['mobile'], -4), $mkfile($pdf, 'again.pdf')));
$int2 = $mk('alice', 'Lia Live', 'internship');
$lv = disc_advisor_action($pdo, $cfg, $mail, $AA, 'live', $int2['id'])['invite']['token'];
throws('in the room, internship: also needs the resume', 422, fn () => disc_submit($pdo, $lv, '', true, $ans, 600, 'en'));
resume_store($pdo, $cfg, $int2['id'], $mkfile($pdf, 'lia.pdf'), (string)$AA['id']);
check('...which the advisor can add from the dashboard; then it finishes (no digits)', disc_submit($pdo, $lv, '', true, $ans, 600, 'en')['style'] !== '');

echo "\nOn the candidate page and list\n";
$map = load_member_map($pdo);
$g = get_candidate($pdo, $map, $AA, $int['id'], 'en', $cfg);
check('detail: resume name, size, who uploaded (candidate)', $g['resume']['name'] === 'Ivy CV.pdf' && $g['resume']['by_candidate'] === true && $g['resume']['size'] === strlen($pdf));
$g2 = get_candidate($pdo, $map, $AA, $int2['id'], 'en', $cfg);
check('detail: advisor-uploaded resume says so', $g2['resume']['by_candidate'] === false);
check('detail: none when there is no resume', get_candidate($pdo, $map, $AA, $car['id'], 'en', $cfg)['resume'] === null);
$lst = list_candidates($pdo, $map, $me, []);
$flags = array_column(array_map(fn ($x) => ['n' => $x['name'], 'r' => $x['resume']], $lst['candidates']), 'r', 'n');
check('list: resume flag per candidate', $flags['Ivy Intern'] === true && $flags['Cal Career'] === false && $flags['Lia Live'] === true);
check('the download path stays inside the upload folder and the stored name is never from the client', str_starts_with(resume_path($cfg, resume_get($pdo, $int['id'])), $cfg['upload_dir'] . '/resumes/'));

echo "\nDelete\n";
$stored = $cfg['upload_dir'] . '/resumes/' . resume_get($pdo, $int['id'])['stored'];
check('file exists before the delete', is_file($stored));
delete_candidate($pdo, $map, $me, $int['id'], $cfg);
check('deleting a candidate removes the resume file and row', !is_file($stored) && (int)$pdo->query('SELECT COUNT(*) FROM candidate_files WHERE candidate_id = ' . $int['id'])->fetchColumn() === 0);
$gcand = $mk('alice', 'Grace Group');
$gf = resume_store($pdo, $cfg, $gcand['id'], $mkfile($pdf, 'g.pdf'), 'candidate');
$gpath = $cfg['upload_dir'] . '/resumes/' . $gf['stored'];
$pdo->prepare("UPDATE members SET rank_label = 'group_manager' WHERE id = ?")->execute([$A['id']]);
try { $rg = remove_group($pdo, $me, $A['id'], 'REMOVE', $cfg); check('removing a whole group deletes its candidates\' resume files too', !is_file($gpath)); }
catch (Throwable $e) { check('removing a whole group deletes its candidates\' resume files too (' . $e->getMessage() . ')', false); }

echo "\nTeam Activity lists\n";
$pdo2 = fresh_db();
$me2 = create_member($pdo2, null, ['email' => 'you@x.com', 'name' => 'You', 'rank' => 'unit_manager', 'is_admin' => true, 'referral_code' => 'cf']);
$A2 = create_member($pdo2, $me2['id'], ['email' => 'amy@x.com', 'name' => 'Amy', 'rank' => 'unit_manager', 'upline_id' => $me2['id'], 'referral_code' => 'amy']);
$B2 = create_member($pdo2, $me2['id'], ['email' => 'ben@x.com', 'name' => 'Ben', 'upline_id' => $me2['id'], 'referral_code' => 'ben']);
$k = 0;
$mk2 = function (string $ref, string $name) use ($pdo2, $cfg, &$k): int {
    $k++;
    $r = start_candidate($pdo2, $cfg, valid_start(['ref' => $ref, 'name' => $name, 'mobile' => '0113000' . str_pad((string)$k, 4, '0', STR_PAD_LEFT), 'email' => "d$k@e.com"]), null);
    submit_assessment($pdo2, $r['submit_token'], sample_answers());
    return $r['candidate_id'];
};
$ca = $mk2('amy', 'Ann Ready'); $cb = $mk2('ben', 'Bob Ready'); $cc = $mk2('amy', 'Cat Invited'); $cd = $mk2('amy', 'Dee Closed'); $ce = $mk2('amy', 'Eve Expired');
$pdo2->prepare("UPDATE candidates SET pipeline_status = 'not_suitable' WHERE id = ?")->execute([$cd]);
$unf = start_candidate($pdo2, $cfg, valid_start(['ref' => 'amy', 'name' => 'Una Unfinished', 'mobile' => '0114000001', 'email' => 'u@e.com']), null);
$act2 = fn (array $who, string $action, int $cid) => disc_advisor_action($pdo2, $cfg, new MemoryMailer(), $who, $action, $cid);
$act2($A2, 'invite', $cc);
$act2($A2, 'invite', $ce);
$old = gmdate('Y-m-d H:i:s', time() - 20 * 86400);
$pdo2->prepare('UPDATE disc_invites SET created_at = ?, expires_at = ?, last_sent_at = ? WHERE candidate_id = ?')->execute([$old, gmdate('Y-m-d H:i:s', time() - 6 * 86400), $old, $ce]);
$map2 = load_member_map($pdo2);
$s = activity_summary($pdo2, $map2, $me2, 'all');
$nm = fn (array $rows) => array_column($rows, 'name');
check('admin: ready = Ann and Bob (not the invited, closed, unfinished or expired ones)', $nm($s['ready_to_invite']) === ['Ann Ready', 'Bob Ready'], json_encode($nm($s['ready_to_invite'])));
check('admin: waiting = Eve (link expired)', $nm($s['disc_waiting']) === ['Eve Expired'] && $s['disc_waiting'][0]['tag'] === 'expired');
$sa = activity_summary($pdo2, $map2, $A2, 'all');
check('Amy sees only her own: Ann in ready, Eve waiting', $nm($sa['ready_to_invite']) === ['Ann Ready'] && $nm($sa['disc_waiting']) === ['Eve Expired']);
$sb = activity_summary($pdo2, $map2, $B2, 'all');
check('Ben sees only Bob, nothing waiting', $nm($sb['ready_to_invite']) === ['Bob Ready'] && $sb['disc_waiting'] === []);
$pdo2->prepare('UPDATE disc_invites SET created_at = ?, last_sent_at = ? WHERE candidate_id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 4 * 86400), gmdate('Y-m-d H:i:s', time() - 4 * 86400), $cc]);
check('Cat invited 4 days ago and not done: now in waiting (days = 4)', in_array('Cat Invited', $nm(activity_summary($pdo2, $map2, $me2, 'all')['disc_waiting']), true) && array_values(array_filter(activity_summary($pdo2, $map2, $me2, 'all')['disc_waiting'], fn ($r) => $r['name'] === 'Cat Invited'))[0]['days'] === 4);
$pdo2->prepare("UPDATE candidates SET pipeline_status = 'joined' WHERE id = ?")->execute([$cc]);
check('marked Joined: leaves the waiting list', !in_array('Cat Invited', $nm(activity_summary($pdo2, $map2 = load_member_map($pdo2), $me2, 'all')['disc_waiting']), true));
check('no scores, phone numbers or emails in the activity data', !preg_match('/scores|verdict|mobile|@e\.com|\+60/i', json_encode(activity_summary($pdo2, $map2, $me2, 'all'))));
finish();
