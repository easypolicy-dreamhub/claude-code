// Run: node tests/e2e.js   (needs php, python3, playwright + chromium; no network)
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path');
const { chromium } = require('/opt/node-tools/node_modules/playwright');

const ROOT = path.resolve(__dirname, '..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'e3e2e-'));
const DB = path.join(tmp, 'db.sqlite'), MAILS = path.join(tmp, 'mails.jsonl'), CFG = path.join(tmp, 'config.php');
const PORT = 8099, TSPORT = 8098, SMTPPORT = 8097, BASE = `http://127.0.0.1:${PORT}`;
const SHOTS = process.env.SHOTS || tmp;
let pass = 0, fail = 0;
const check = (name, ok, extra = '') => { ok ? pass++ : fail++; console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${name}${ok ? '' : '  ' + extra}`); };
const procs = [];
const php = (...a) => execFileSync('php', [path.join(ROOT, 'tests/e2e_db.php'), ...a], { encoding: 'utf8' });
const dump = () => JSON.parse(php('dump', DB));
const mails = () => (fs.existsSync(MAILS) ? fs.readFileSync(MAILS, 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse) : []);
const decodeBodies = (m) => [...m.data.matchAll(/Content-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/g)].map((x) => Buffer.from(x[1].replace(/\s/g, ''), 'base64').toString('utf8')).join('\n');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  php('seed', DB);
  fs.writeFileSync(CFG, `<?php return [
    'db' => ['dsn' => 'sqlite:${DB}', 'user' => null, 'pass' => null],
    'default_owner_email' => 'you@x.com', 'ip_salt' => 'e2e', 'rate_limit_per_hour' => 50, 'base_url' => 'https://dreamhub-e3.com',
    'allowed_origins' => ['127.0.0.1'],
    'turnstile' => ['secret' => 'test-secret', 'verify_url' => 'http://127.0.0.1:${TSPORT}/'],
    'mail' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => ${SMTPPORT}, 'secure' => '', 'user' => 'mailer@example.com', 'pass' => 'secret', 'from' => 'hello@dreamhub-e3.com', 'from_name' => 'DreamHub E3'],
  ];`);
  const start = (cmd, args, env = {}) => { const p = spawn(cmd, args, { env: { ...process.env, ...env }, stdio: 'ignore' }); procs.push(p); return p; };
  start('python3', [path.join(ROOT, 'tests/fakes/turnstile.py'), String(TSPORT)]);
  start('python3', [path.join(ROOT, 'tests/fakes/smtp.py'), String(SMTPPORT), MAILS]);
  start('php', ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public_html')], { E3_CONFIG: CFG, PHP_CLI_SERVER_WORKERS: '4' });
  await sleep(1500);

  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true });
  const page = await ctx.newPage();
  const problems = [];
  page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource|ERR_/.test(m.text())) problems.push('console: ' + m.text()); });
  await page.route('**/*', (r) => (new URL(r.request().url()).hostname === '127.0.0.1' ? r.continue() : r.abort()));
  await page.route('**/site-config.js', (r) => r.fulfill({ contentType: 'text/javascript', body: "window.CC_SITE={turnstileSiteKey:'TEST',apiBase:'api/',whatsapp:'60126718056'};" }));

  console.log('Welcome + details');
  await page.goto(`${BASE}/career-code/?p=internship&ref=amy`);
  check('internship wording on welcome', /Internship/.test(await page.textContent('#wTitle')));
  check('resume panel hidden on a fresh visit', await page.isHidden('#resumePanel'));
  check('no advisor / PIN wording anywhere on the page', !/\bPIN\b|8888|[Aa]dvisor notes/.test(await page.content()));
  await page.screenshot({ path: path.join(SHOTS, '1-welcome.png') });
  await page.click('#startBtn');
  await page.click('#d-next');
  check('empty form shows name, mobile, email and consent errors', /full name/i.test(await page.textContent('#d-err')) && /Malaysian mobile/i.test(await page.textContent('#d-err')) && /email/i.test(await page.textContent('#d-err')));
  await page.fill('#d-name', 'Bob Lim'); await page.fill('#d-mobile', '+65 9123 4567'); await page.fill('#d-email', 'bob@example.com'); await page.check('#d-consent');
  await page.click('#d-next');
  check('Singapore number rejected in the browser', /Malaysian mobile/i.test(await page.textContent('#d-err')) && (await page.isVisible('#s-details')));
  await page.fill('#d-mobile', '012-345 6789'); await page.selectOption('#d-age', '2'); await page.selectOption('#d-edu', '3'); await page.selectOption('#d-status', '1'); await page.fill('#d-field', 'Marketing');
  const edu = await page.$$eval('#d-edu option', (o) => o.map((x) => x.textContent));
  check('Malaysian education options (SPM...)', edu.includes('SPM or equivalent') && edu.some((x) => /Bachelor/.test(x)) && !edu.some((x) => /Nitec|O \/ N/.test(x)));
  await page.screenshot({ path: path.join(SHOTS, '2-details.png') });
  await page.click('#d-next');
  await page.waitForSelector('#s-test:not([hidden])');
  check('moved on to Part A after the server accepted the details', /Part A/.test(await page.textContent('#t-title')));

  console.log('Taking the test (the real sample: R0 I4 A3 S7 E7 C4 ...)');
  const counts = { A: [0, 4, 3, 7, 7, 4], B: [0, 5, 1, 6, 7, 4], C: [0, 3, 0, 4, 7, 1] };
  const pickPart = async (part) => page.evaluate((c) => {
    const types = ['R', 'I', 'A', 'S', 'E', 'C'];
    types.forEach((t, ti) => { for (let i = 0; i < c[ti]; i++) document.querySelector(`#t-body .item[data-t="${t}"][data-i="${i}"]`).click(); });
  }, counts[part]);
  await pickPart('A');
  check('counter reflects 25 selected', /25/.test(await page.textContent('#t-count')));
  await page.screenshot({ path: path.join(SHOTS, '3-test.png') });
  await page.click('#t-next'); await pickPart('B');
  console.log('Reload mid-test -> resume (Fix 6)');
  await page.reload();
  check('resume panel offered after refresh', await page.isVisible('#resumePanel'));
  await page.click('#resumeGo');
  check('resumed at Part B with answers kept (23 selected)', /Part B/.test(await page.textContent('#t-title')) && /23/.test(await page.textContent('#t-count')));
  await page.click('#t-next'); await pickPart('C'); await page.click('#t-next');
  check('on Part D', /Part D/.test(await page.textContent('#t-title')));
  await page.click('#t-next');
  check('cannot finish Part D unrated', /Rate all 12/.test(await page.textContent('#t-err')));
  const D = { 0: { R: 1, I: 2, A: 3, S: 6, E: 7, C: 4 }, 1: { R: 1, I: 2, A: 4, S: 7, E: 6, C: 5 } };
  for (const k of [0, 1]) for (const [t, v] of Object.entries(D[k])) await page.click(`#t-body .rate button[data-t="${t}"][data-k="${k}"][data-v="${v}"]`);
  const disabled = await page.$$eval('#t-body .rate button:disabled', (b) => b.length);
  check('a number used once is disabled for the other types in that set', disabled > 0);
  await page.screenshot({ path: path.join(SHOTS, '4-partD.png'), fullPage: false });
  await page.click('#t-next');
  await page.waitForSelector('#s-results:not([hidden])');

  console.log('Results');
  const code = await page.$$eval('#r-code span', (s) => s.map((x) => x.textContent).join(''));
  check('server-calculated code is ESC', code === 'ESC', code);
  check('score table has the paper totals (E34 S30 C18)', /34/.test(await page.textContent('#r-scores')) && /30/.test(await page.textContent('#r-scores')) && /18/.test(await page.textContent('#r-scores')));
  check('names the advisor (Amy A, from ?ref=amy)', /Amy A/.test(await page.textContent('#r-next-text')));
  check('WhatsApp link to the advisor', /wa\.me\/60126718056/.test(await page.getAttribute('#waPost', 'href')));
  check('progress cleared after finishing', (await page.evaluate(() => localStorage.getItem('e3cc.v1'))) === null);
  await page.screenshot({ path: path.join(SHOTS, '5-results.png'), fullPage: true });
  await page.click('#langBtn');
  check('Chinese toggle re-renders the result', /企业型/.test(await page.textContent('#r-types')) && /你的职业代码/.test(await page.textContent('#s-results')));
  await page.screenshot({ path: path.join(SHOTS, '6-results-zh.png'), fullPage: true });

  console.log('Saved on the server');
  const d = dump();
  check('one candidate, owned by Amy, internship, referral, Malaysian mobile', d.candidates.length === 1 && d.candidates[0].owner_member_id === 2 && d.candidates[0].purpose === 'internship' && d.candidates[0].source === 'referral' && d.candidates[0].mobile === '+60123456789', JSON.stringify(d.candidates[0]));
  check('details stored (email, education index 3, field)', d.candidates[0].email === 'bob@example.com' && d.candidates[0].education === 3 && d.candidates[0].field === 'Marketing');
  check('assessment saved: code ESC, raw answers kept', d.assessments.length === 1 && d.assessments[0].code === 'ESC' && JSON.parse(d.assessments[0].answers_json).D.S[0] === 6);
  check('candidate completed + email timestamps set', d.candidates[0].completed_at && d.candidates[0].result_emailed_at && d.candidates[0].notified_at);
  await sleep(300);
  const ms = mails();
  const to = ms.map((m) => m.to[0]).sort();
  check('3 emails sent over SMTP: candidate, Amy, You', ms.length === 3 && JSON.stringify(to) === JSON.stringify(['<a@x.com>', '<bob@example.com>', '<you@x.com>']), JSON.stringify(to));
  const cm = ms.find((m) => m.to[0] === '<bob@example.com>'), am = ms.find((m) => m.to[0] === '<a@x.com>');
  check('candidate email carries the result', /Enterprising/.test(decodeBodies(cm)) && /ESC|E S C/.test(decodeBodies(cm)));
  check('advisor email: link only, no scores', /candidate=1/.test(decodeBodies(am)) && !/Strong fit|Good fit|34/.test(decodeBodies(am)));

  console.log('Abuse');
  const post = (p, body) => page.evaluate(async ([u, b]) => { const r = await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify(b) }); return [r.status, await r.json()]; }, [`${BASE}/career-code/api/${p}`, body]);
  const base = { name: 'X', mobile: '0129999999', email: 'x@x.com', consent: true, lang: 'en', purpose: 'career' };
  check('start with a bad spam token -> 403', (await post('start.php', { ...base, turnstile: 'bad' }))[0] === 403);
  check('start with no spam token -> 403', (await post('start.php', base))[0] === 403);
  const tok = (await post('start.php', { ...base, turnstile: 'good-token' }))[1].submit_token;
  check('start with a good token works', /^[a-f0-9]{64}$/.test(tok));
  check('submit with forged scores/answers -> 422, nothing saved', (await post('submit.php', { submit_token: tok, answers: { A: { R: [99] } } }))[0] === 422 && dump().assessments.length === 1);
  const dupe = await post('submit.php', { submit_token: tok, answers: { A: { R: [], I: [], A: [], S: [], E: [], C: [] }, B: { R: [], I: [], A: [], S: [], E: [], C: [] }, C: { R: [], I: [], A: [], S: [], E: [], C: [] }, D: { R: [1, 1], I: [2, 2], A: [3, 3], S: [4, 4], E: [5, 5], C: [6, 6] } } });
  check('all-empty A/B/C is a valid (low) result', dupe[0] === 200 && dupe[1].code.length === 3);
  const again = await post('submit.php', { submit_token: tok, answers: {} });
  check('resubmitting the same token returns the saved result', again[0] === 200 && again[1].code === dupe[1].code);
  await sleep(300);
  check('...and sends no extra emails', mails().length === 5, String(mails().length));   // 3 from Bob + 2 from the second candidate (candidate + You, who owns it)
  check('GET on the API -> 405', await page.evaluate(async (u) => (await fetch(u)).status, `${BASE}/career-code/api/start.php`) === 405);
  check('cross-site style POST (no custom header) -> 403', await page.evaluate(async (u) => (await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).status, `${BASE}/career-code/api/start.php`) === 403);
  check('no JavaScript errors during the whole run', problems.length === 0, problems.join(' | '));

  await browser.close();
  procs.forEach((p) => p.kill());
  console.log(`\n${pass} passed, ${fail} failed. Screenshots: ${SHOTS}`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); procs.forEach((p) => p.kill()); process.exit(1); });
