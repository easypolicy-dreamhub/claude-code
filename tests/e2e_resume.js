// Run: node tests/e2e_resume.js   Browser test of DISC step 2: resume upload, internship rule, advisor upload/download, Team Activity lists.
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), http = require('http'), crypto = require('crypto');
const { chromium } = require('/opt/node-tools/node_modules/playwright');

const ROOT = path.resolve(__dirname, '..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'e3res-'));
const DB = path.join(tmp, 'db.sqlite'), CFG = path.join(tmp, 'config.php'), MAILS = path.join(tmp, 'mails.jsonl');
const PORT = 8159, JWKS = 8158, SMTPPORT = 8157, BASE = `http://127.0.0.1:${PORT}`, TEAM = 'test.cloudflareaccess.com', AUD = 'aud-test';
const SHOTS = process.env.SHOTS || tmp;
let pass = 0, fail = 0;
const check = (name, ok, extra = '') => { ok ? pass++ : fail++; console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${name}${ok ? '' : '  ' + extra}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const procs = [];
const mails = () => (fs.existsSync(MAILS) ? fs.readFileSync(MAILS, 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse) : []);
const rcpt = (from) => mails().slice(from).map((m) => m.to.join(',')).sort();
const decodeBodies = (m) => [...m.data.matchAll(/Content-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/g)].map((x) => Buffer.from(x[1].replace(/\s/g, ''), 'base64').toString('utf8')).join('\n');
const dump = () => JSON.parse(execFileSync('php', [path.join(ROOT, 'tests/e2e_db.php'), 'dump', DB]).toString());
const Q = JSON.parse(fs.readFileSync(path.join(ROOT, 'private/data/disc.json'), 'utf8')).questions;

const { privateKey, publicKey } = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 });
const jwk = { ...publicKey.export({ format: 'jwk' }), kid: 'k1', alg: 'RS256', use: 'sig' };
const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64url');
const mint = (email) => { const h = b64({ alg: 'RS256', kid: 'k1', typ: 'JWT' }), p = b64({ email, aud: [AUD], iss: `https://${TEAM}`, exp: Math.floor(Date.now() / 1000) + 3600 });
  return `${h}.${p}.${crypto.sign('RSA-SHA256', Buffer.from(`${h}.${p}`), privateKey).toString('base64url')}`; };

(async () => {
  execFileSync('php', [path.join(ROOT, 'tests/e2e_db.php'), 'seed-team', DB]);
  fs.mkdirSync(path.join(tmp, 'cache'));
  fs.writeFileSync(CFG, `<?php return [
    'db' => ['dsn' => 'sqlite:${DB}', 'user' => null, 'pass' => null],
    'cf_access' => ['team_domain' => '${TEAM}', 'aud' => '${AUD}', 'cache_dir' => '${path.join(tmp, 'cache')}', 'jwks_url' => 'http://127.0.0.1:${JWKS}/certs'],
    'allowed_origins' => ['127.0.0.1'], 'base_url' => '${BASE}', 'default_owner_email' => 'you@x.com', 'ip_salt' => 'e2e', 'upload_dir' => '${path.join(tmp, 'uploads')}',
    'mail' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => ${SMTPPORT}, 'secure' => '', 'user' => 'mailer@example.com', 'pass' => 'secret', 'from' => 'hello@dreamhub-e3.com', 'from_name' => 'DreamHub E3'],
  ];`);
  http.createServer((q, r) => { r.setHeader('Content-Type', 'application/json'); r.end(JSON.stringify({ keys: [jwk] })); }).listen(JWKS);
  procs.push(spawn('python3', [path.join(ROOT, 'tests/fakes/smtp.py'), String(SMTPPORT), MAILS], { stdio: 'ignore' }));
  procs.push(spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public_html')], { env: { ...process.env, E3_CONFIG: CFG, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' }));
  await sleep(1500);

  const browser = await chromium.launch(); const problems = [];
  const open = async (email, viewport = { width: 1024, height: 1300 }) => {
    const ctx = await browser.newContext({ viewport, extraHTTPHeaders: email ? { 'Cf-Access-Jwt-Assertion': mint(email) } : {} });
    await ctx.route('**/*', (r) => (new URL(r.request().url()).hostname === '127.0.0.1' ? r.continue() : r.abort()));
    const page = await ctx.newPage(); page.on('pageerror', (e) => problems.push(`${email}: ${e.message}`)); page.on('dialog', (d) => d.accept());
    return page;
  };
  const openCand = async (page, id) => { await page.goto(`${BASE}/members/career-code/dashboard/?candidate=${id}`); await page.waitForSelector('#detail:not([hidden]) #dStatus', { timeout: 8000 }); };
  const candId = (name) => dump().candidates.find((c) => c.name === name).id;
  const post = (p, url, body) => p.evaluate(async ([u, b]) => { const r = await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify(b) }); return [r.status, await r.json().catch(() => null)]; }, [`${BASE}${url}`, body]);


  const PDF = Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n'), pdfPath = path.join(tmp, 'My Resume.pdf'), txtPath = path.join(tmp, 'notes.txt'), bigPath = path.join(tmp, 'big.pdf'), fakePath = path.join(tmp, 'fake.pdf');
  fs.writeFileSync(pdfPath, PDF); fs.writeFileSync(txtPath, 'hello'); fs.writeFileSync(bigPath, Buffer.concat([PDF, Buffer.alloc(6 * 1024 * 1024)])); fs.writeFileSync(fakePath, 'MZ' + 'x'.repeat(200));
  const answerAll = async (cand) => { for (let p = 0; p < 4; p++) { for (let q = p * 7; q < p * 7 + 7; q++) { await cand.click(`.wb.m[data-q="${q}"][data-p="${Q[q].indexOf('S')}"]`); await cand.click(`.wb.l[data-q="${q}"][data-p="${Q[q].indexOf('D')}"]`); } await cand.click('#t-next'); if (p < 3) await cand.waitForSelector(`.wq[data-q="${(p + 1) * 7}"]`); } };

  console.log('Internship candidate (Dina, Alice\'s) must upload a resume');
  const alice = await open('alice@x.com');
  const dina = candId('Dina Dass');
  await alice.goto(`${BASE}/members/career-code/dashboard/`); await alice.waitForSelector('#list .item');
  check('list shows "Resume: not yet" on the internship candidate', /Resume: not yet/.test(await alice.locator('.item', { hasText: 'Dina Dass' }).textContent()));
  await openCand(alice, dina);
  check('detail: Resume section says "Required for internship" and offers Upload', /Required for internship/.test(await alice.textContent('#resumeBlock')) && /Upload resume/.test(await alice.textContent('#resumeBlock')));
  await alice.click('[data-disc=invite][data-channel=link]'); await alice.waitForSelector('#discLink');
  const link = await alice.inputValue('#discLink');
  const cand = await open(null, { width: 420, height: 900 });
  await cand.goto(link); await cand.waitForSelector('#s-intro:not([hidden])');
  check('first screen: resume box says (required), with the PDF/Word and 5 MB hint', /required/.test(await cand.textContent('#resumeLabel')) && /5 MB/.test(await cand.textContent('#resumeHint')));
  check('the consent text now mentions the resume', /resume/i.test(await cand.textContent('#consentText')));
  const last4 = dump().candidates.find((c) => c.id == dina).mobile.slice(-4);
  await cand.fill('#digits', last4); await cand.click('#consent'); await cand.click('#startBtn');
  check('no file: "Please upload your resume to continue"', /upload your resume/i.test(await cand.textContent('#iErr')));
  await cand.setInputFiles('#resumeFile', txtPath); await cand.click('#startBtn');
  check('a .txt file is refused in the browser', /PDF or Word/.test(await cand.textContent('#iErr')));
  await cand.setInputFiles('#resumeFile', bigPath); await cand.click('#startBtn');
  check('a 6 MB file is refused in the browser', /too big/.test(await cand.textContent('#iErr')));
  await cand.setInputFiles('#resumeFile', fakePath); await cand.click('#startBtn');
  await cand.waitForFunction(() => /does not look like a real/i.test(document.querySelector('#iErr').textContent));
  check('a program renamed .pdf is refused by the server', true);
  check('nothing was stored', !fs.existsSync(path.join(tmp, 'uploads/resumes')) || fs.readdirSync(path.join(tmp, 'uploads/resumes')).length === 0);
  await cand.setInputFiles('#resumeFile', pdfPath); await cand.click('#startBtn');
  await cand.waitForSelector('#s-test:not([hidden])');
  check('a real PDF is accepted and the test starts', fs.readdirSync(path.join(tmp, 'uploads/resumes')).length === 1);
  await answerAll(cand); await cand.waitForSelector('#s-result:not([hidden])');
  check('...and the questionnaire finishes', /Steadiness|Dominance|Influence|Conscientiousness/.test(await cand.textContent('#r-style')));
  const stored = fs.readdirSync(path.join(tmp, 'uploads/resumes'))[0];
  check('the stored file has a random name, not "My Resume"', /^\d+-[a-f0-9]{24}\.pdf$/.test(stored));
  const wp = execFileSync('sh', ['-c', `find ${path.join(ROOT, 'public_html')} -name '*.pdf' | wc -l`]).toString().trim();
  check('nothing was written into the public web folder', wp === '0');

  console.log('\nAdvisors see and download it');
  await openCand(alice, dina);
  const rb = await alice.textContent('#resumeBlock');
  check('Resume section: Received, name, uploaded by the candidate', /Received/.test(rb) && /My Resume\.pdf/.test(rb) && /uploaded by the candidate/.test(rb), rb);
  const dl = await alice.evaluate(async (u) => { const r = await fetch(u); const b = new Uint8Array(await r.arrayBuffer()); return [r.status, r.headers.get('content-type'), r.headers.get('content-disposition'), r.headers.get('x-content-type-options'), Array.from(b.slice(0, 5))]; }, `${BASE}/members/api/resume.php?candidate_id=${dina}`);
  check('download: 200, application/pdf, attachment, nosniff, real bytes', dl[0] === 200 && dl[1] === 'application/pdf' && /attachment; filename="My_Resume\.pdf"/.test(dl[2]) && dl[3] === 'nosniff' && String.fromCharCode(...dl[4]) === '%PDF-', JSON.stringify(dl));
  const amy = await open('amy@x.com'), ben = await open('ben@x.com');
  await amy.goto(`${BASE}/members/career-code/dashboard/`); await ben.goto(`${BASE}/members/career-code/dashboard/`);
  const get = (p) => p.evaluate(async (u) => (await fetch(u)).status, `${BASE}/members/api/resume.php?candidate_id=${dina}`);
  check('Amy (upline) can download; Ben (other branch) gets 404', (await get(amy)) === 200 && (await get(ben)) === 404);
  const anon = await open(null); await anon.goto(`${BASE}/career-code/disc/`);
  check('no login: 401', (await get(anon)) === 401);
  check('the download is audited', JSON.parse(execFileSync('php', ['-r', `$p=new PDO('sqlite:${DB}');echo json_encode($p->query("SELECT COUNT(*) FROM audit_log WHERE action='resume.download'")->fetchColumn());`]).toString()) >= 2);

  console.log('\nAdvisor adds, replaces and removes a resume (Cara, Amy\'s career candidate)');
  const cara = candId('Cara Chin');
  await openCand(amy, cara);
  check('career: no "required" label', !/Required for internship/.test(await amy.textContent('#resumeBlock')) && /No resume yet/.test(await amy.textContent('#resumeBlock')));
  await amy.setInputFiles('#resumeUp', pdfPath);
  await amy.waitForFunction(() => /Received/.test(document.querySelector('#resumeBlock').textContent));
  check('uploaded: Received, "added by an advisor"', /added by an advisor/.test(await amy.textContent('#resumeBlock')));
  await amy.setInputFiles('#resumeUp', fakePath);
  await amy.waitForFunction(() => /does not look like a real/i.test(document.querySelector('#toast').textContent));
  check('a fake PDF is refused here too, the real one stays', /Received/.test(await amy.textContent('#resumeBlock')));
  await amy.click('[data-resdel]');
  await amy.waitForFunction(() => /No resume yet/.test(document.querySelector('#resumeBlock').textContent));
  check('removed: back to "No resume yet", file gone from disk', fs.readdirSync(path.join(tmp, 'uploads/resumes')).length === 1);
  const direct = await open(null); await direct.goto(`${BASE}/career-code/disc/`);
  check('public resume API: GET refused (405)', (await direct.evaluate(async (u) => (await fetch(u)).status, `${BASE}/career-code/api/resume.php`)) === 405);
  check('public resume API: JSON body instead of a file refused', (await direct.evaluate(async (u) => (await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: '{}' })).status, `${BASE}/career-code/api/resume.php`)) === 422);
  check('public resume API: without the custom header refused (403)', (await direct.evaluate(async (u) => { const fd = new FormData(); fd.append('t', 'a'.repeat(64)); return (await fetch(u, { method: 'POST', body: fd })).status; }, `${BASE}/career-code/api/resume.php`)) === 403);

  console.log('\nDeleting a candidate deletes the file');
  const adm = await open('you@x.com');
  await openCand(adm, dina);
  await adm.click('#delBtn');
  await adm.waitForFunction(() => /Candidate deleted/.test(document.querySelector('#toast').textContent));
  check('the resume file was removed from disk', fs.readdirSync(path.join(tmp, 'uploads/resumes')).length === 0);

  console.log('\nTeam Activity lists');
  await amy.goto(`${BASE}/members/career-code/dashboard/?candidate=${cara}`); await amy.waitForSelector('#detail:not([hidden]) #dStatus');
  await amy.click('[data-disc=invite][data-channel=link]'); await amy.waitForSelector('#discLink');
  execFileSync('php', ['-r', `$p=new PDO('sqlite:${DB}');$old=gmdate('Y-m-d H:i:s',time()-4*86400);$p->exec("UPDATE disc_invites SET created_at='$old'");`]);
  const act = await open('you@x.com'); await act.goto(`${BASE}/members/activity/`); await act.waitForSelector('#ready li');
  const ready = await act.textContent('#ready'), waiting = await act.textContent('#waiting');
  check('admin: "Ready to invite" lists Eddy and Fiona (not Cara, invited)', /Eddy Ee/.test(ready) && /Fiona Foo/.test(ready) && !/Cara Chin/.test(ready), ready);
  check('admin: "Waiting on DISC" lists Cara (not done, 4 days)', /Cara Chin/.test(waiting) && /not done, 4 days/.test(waiting), waiting);
  await act.screenshot({ path: path.join(SHOTS, 'r1-activity.png'), fullPage: true });
  const amyAct = await open('amy@x.com'); await amyAct.goto(`${BASE}/members/activity/`); await amyAct.waitForSelector('#ready li');
  check('Amy: ready list has nothing from other branches; waiting shows Cara', !/Eddy|Fiona/.test(await amyAct.textContent('#ready')) && /Cara Chin/.test(await amyAct.textContent('#waiting')));
  await adm.goto(`${BASE}/members/career-code/dashboard/`); await adm.waitForSelector('#list .item');
  check('list: "Resume: not yet" on Fiona (internship, none)', /Resume: not yet/.test(await adm.locator('.item', { hasText: 'Fiona Foo' }).textContent()));
  check('no JavaScript errors anywhere', problems.length === 0, problems.join(' | '));
  await browser.close(); procs.forEach((p) => p.kill());
  console.log(`\n${pass} passed, ${fail} failed. Screenshots: ${SHOTS}`); process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); procs.forEach((p) => p.kill()); process.exit(1); });
