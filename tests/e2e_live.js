// Run: node tests/e2e_live.js   Browser test of advisor home, live test and paper-score entry (/members/career-code/).
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), http = require('http'), crypto = require('crypto');
const { chromium } = require('/opt/node-tools/node_modules/playwright');

const ROOT = path.resolve(__dirname, '..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'e3live-'));
const DB = path.join(tmp, 'db.sqlite'), CFG = path.join(tmp, 'config.php');
const PORT = 8189, JWKS = 8188, BASE = `http://127.0.0.1:${PORT}`, TEAM = 'test.cloudflareaccess.com', AUD = 'aud-test';
const SHOTS = process.env.SHOTS || tmp;
let pass = 0, fail = 0;
const check = (name, ok, extra = '') => { ok ? pass++ : fail++; console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${name}${ok ? '' : '  ' + extra}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const procs = [];

const { privateKey, publicKey } = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 });
const jwk = { ...publicKey.export({ format: 'jwk' }), kid: 'k1', alg: 'RS256', use: 'sig' };
const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64url');
const mint = (email) => { const h = b64({ alg: 'RS256', kid: 'k1', typ: 'JWT' }), p = b64({ email, aud: [AUD], iss: `https://${TEAM}`, exp: Math.floor(Date.now() / 1000) + 3600 });
  return `${h}.${p}.${crypto.sign('RSA-SHA256', Buffer.from(`${h}.${p}`), privateKey).toString('base64url')}`; };
const dump = () => JSON.parse(execFileSync('php', [path.join(ROOT, 'tests/e2e_db.php'), 'dump', DB]).toString());

// The real sample photo: R2 I16 A11 S30 E34 C18 -> ESC. counts of ticks per type for parts A,B,C; D rating pairs.
const TICKS = { A: [0, 4, 3, 7, 7, 4], B: [0, 5, 1, 6, 7, 4], C: [0, 3, 0, 4, 7, 1] };
const RATE = { R: [1, 1], I: [2, 2], A: [3, 4], S: [6, 7], E: [7, 6], C: [4, 5] };
const TYPES = 'RIASEC'.split('');

(async () => {
  execFileSync('php', [path.join(ROOT, 'tests/e2e_db.php'), 'seed-team', DB]);
  fs.mkdirSync(path.join(tmp, 'cache'));
  fs.writeFileSync(CFG, `<?php return [
    'db' => ['dsn' => 'sqlite:${DB}', 'user' => null, 'pass' => null],
    'cf_access' => ['team_domain' => '${TEAM}', 'aud' => '${AUD}', 'cache_dir' => '${path.join(tmp, 'cache')}', 'jwks_url' => 'http://127.0.0.1:${JWKS}/certs'],
    'allowed_origins' => ['127.0.0.1'], 'base_url' => 'https://dreamhub-e3.com',
  ];`);
  http.createServer((q, r) => { r.setHeader('Content-Type', 'application/json'); r.end(JSON.stringify({ keys: [jwk] })); }).listen(JWKS);
  procs.push(spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public_html')], { env: { ...process.env, E3_CONFIG: CFG, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' }));
  await sleep(1200);

  const browser = await chromium.launch();
  const problems = [];
  const open = async (email, viewport = { width: 1024, height: 1100 }) => {
    const ctx = await browser.newContext({ viewport, extraHTTPHeaders: email ? { 'Cf-Access-Jwt-Assertion': mint(email) } : {} });
    await ctx.route('**/*', (r) => (new URL(r.request().url()).hostname === '127.0.0.1' ? r.continue() : r.abort()));
    const page = await ctx.newPage();
    page.on('pageerror', (e) => problems.push(`${email}: pageerror ${e.message}`));
    page.on('dialog', (d) => d.accept());
    return page;
  };
  const apiGet = (p, url) => p.evaluate(async (u) => { const r = await fetch(u, { headers: { 'X-Requested-With': 'fetch' } }); return [r.status, await r.json().catch(() => null)]; }, `${BASE}/members/api/candidates.php${url}`);

  console.log('Advisor home');
  const alice = await open('alice@x.com');
  await alice.goto(`${BASE}/members/career-code/`);
  await alice.waitForFunction(() => document.querySelector('#l1').value);
  check('four tiles', (await alice.$$('.tile')).length === 4);
  check('personal career link carries her referral code', (await alice.inputValue('#l1')).endsWith('/career-code/?ref=alice&p=career'), await alice.inputValue('#l1'));
  check('internship link too', (await alice.inputValue('#l2')).endsWith('?ref=alice&p=internship'));
  check('greeting uses her first name', /Hi Alice/.test(await alice.textContent('#hello')));

  console.log('\nLive test (Alice sits with a candidate; no email given)');
  await alice.goto(`${BASE}/members/career-code/live/?p=career`);
  check('no spam-check widget, no WhatsApp pre-chat button', (await alice.$$('#ts iframe')).length === 0 && await alice.isHidden('#waPre'));
  await alice.click('#startBtn');
  check('email label says optional', /optional/.test(await alice.textContent('label[for=d-email]')));
  await alice.fill('#d-name', 'Live Larry'); await alice.fill('#d-mobile', '013-555 6677');
  await alice.click('#d-consent'); await alice.click('#d-next');
  await alice.waitForSelector('#s-test:not([hidden])');
  check('started without an email', true);
  const tickPart = async (part) => { for (const [i, t] of TYPES.entries()) for (let k = 0; k < TICKS[part][i]; k++) await alice.click(`#t-body .item[data-t="${t}"][data-i="${k}"]`); };
  await tickPart('A'); await alice.click('#t-next');
  // reload mid-test: should offer to resume, from this advisor's own saved key
  await alice.reload();
  check('reload mid-test offers to resume', await alice.isVisible('#resumePanel'));
  await alice.click('#resumeGo'); await alice.waitForSelector('#s-test:not([hidden])');
  await alice.click('#t-back');
  check('resume keeps part A ticks (25 ticks)', (await alice.$$('#t-body .item[aria-pressed=true]')).length === TICKS.A.reduce((a, b) => a + b, 0));
  await alice.click('#t-next');
  await tickPart('B'); await alice.click('#t-next');
  await tickPart('C'); await alice.click('#t-next');
  for (const t of TYPES) for (const k of [0, 1]) await alice.click(`#t-body .rate button[data-t="${t}"][data-k="${k}"][data-v="${RATE[t][k]}"]`);
  await alice.click('#t-next');
  await alice.waitForSelector('#s-results:not([hidden])');
  const codeText = (await alice.$$eval('#r-code span', (s) => s.map((x) => x.textContent))).join('');
  check('result shows code ESC', codeText === 'ESC', codeText);
  check('result says no email was sent', /No email address/.test(await alice.textContent('#r-next-text')));
  const href = await alice.getAttribute('#waPost', 'href');
  check('"Open advisor view" points at the dashboard record', /\/members\/career-code\/dashboard\/\?candidate=\d+$/.test(href), href);
  await alice.screenshot({ path: path.join(SHOTS, 'l1-live-result.png') });
  let d = dump(); const larry = d.candidates.find((c) => c.name === 'Live Larry');
  check('saved: owner Alice, source live, no email, completed, 1 assessment', larry && larry.source === 'live' && larry.email === null && larry.completed_at && d.assessments.filter((a) => a.candidate_id == larry.id).length === 1);
  check('no leftover saved test on the device', await alice.evaluate(() => localStorage.getItem('e3cc.live.v1') === null));
  await alice.click('#waPost'); await alice.waitForSelector('#detail:not([hidden])', { timeout: 8000 });
  check('advisor view opens the candidate with the advisor notes', /Live Larry/.test(await alice.textContent('#detail')) && /Live session/.test(await alice.textContent('#detail')));
  const amy = await open('amy@x.com'), ben = await open('ben@x.com');
  await amy.goto(`${BASE}/members/career-code/dashboard/`); await ben.goto(`${BASE}/members/career-code/dashboard/`);
  check('Amy (Alice\'s upline) can see Larry', (await apiGet(amy, `?id=${larry.id}`))[0] === 200);
  check('Ben (other branch) gets 404', (await apiGet(ben, `?id=${larry.id}`))[0] === 404);

  console.log('\nPaper-score entry');
  await alice.goto(`${BASE}/members/career-code/manual/`);
  const fillScores = async (override = {}) => {
    const S = { R: [0, 0, 0, 2], I: [4, 5, 3, 4], A: [3, 1, 0, 7], S: [7, 6, 4, 13], E: [7, 7, 7, 13], C: [4, 4, 1, 9], ...override };
    for (const t of TYPES) for (const [j, k] of ['a', 'b', 'c', 'd'].entries()) await alice.fill(`input[data-t="${t}"][data-k="${k}"]`, String(S[t][j]));
  };
  await alice.click('#save');
  check('empty form is refused with a message', /Enter the name/.test(await alice.textContent('#err')));
  await alice.fill('#name', 'Paper Pat'); await alice.fill('#mobile', '016-777 8888'); await alice.fill('#email', 'pat@e.com');
  await fillScores();
  check('live totals + code preview ESC', (await alice.textContent('#tot-E')) === '34' && (await alice.$$eval('#preview .code span', (s) => s.map((x) => x.textContent))).join('') === 'ESC');
  await alice.click('#save');
  check('consent is required', /consent/i.test(await alice.textContent('#err')));
  await alice.fill('input[data-t="R"][data-k="a"]', '12'); await alice.click('#consent'); await alice.click('#save');
  check('A=12 is refused in the browser', /Realistic part A/.test(await alice.textContent('#err')));
  await alice.fill('input[data-t="R"][data-k="a"]', '0');
  await alice.fill('input[data-t="R"][data-k="d"]', '14'); await alice.click('#save');
  await alice.waitForSelector('#confirmD:not([hidden])');
  check('D total outside 42-54 asks to confirm instead of saving', /adds up to/.test(await alice.textContent('#confirmText')) && dump().candidates.every((c) => c.name !== 'Paper Pat'));
  await alice.fill('input[data-t="R"][data-k="d"]', '2');
  check('editing a score withdraws the confirm box', await alice.isHidden('#confirmD'));
  await alice.click('#save'); await alice.waitForSelector('#done:not([hidden])');
  check('saved: shows ESC and "now in your candidate list"', /now in your candidate list/.test(await alice.textContent('#done')) && (await alice.$$eval('#done .code span', (s) => s.map((x) => x.textContent))).join('') === 'ESC');
  d = dump(); const pat = d.candidates.find((c) => c.name === 'Paper Pat'); const pa = d.assessments.find((a) => a.candidate_id == pat.id);
  check('stored as manual, Alice recorded as the typist', pa.mode === 'manual' && pa.entered_by_member_id == pat.owner_member_id);
  await alice.screenshot({ path: path.join(SHOTS, 'l2-manual-saved.png') });
  const patRow = await apiGet(alice, `?id=${pat.id}`);
  check('dashboard says paper scores typed in', patRow[0] === 200 && patRow[1].candidate.entry === 'paper');

  console.log('\nPaper entry while offline');
  await alice.click('#another'); await alice.waitForSelector('#f:not([hidden])');
  check('form is blank again', (await alice.inputValue('#name')) === '' && (await alice.inputValue('input[data-t="S"][data-k="d"]')) === '');
  await alice.fill('#name', 'Offline Olive'); await alice.fill('#mobile', '017-111 2200'); await fillScores({ S: [6, 6, 4, 13] }); await alice.click('#consent');
  await alice.context().setOffline(true);
  await alice.click('#save'); await alice.waitForSelector('#done:not([hidden])');
  check('offline: "Saved on this device" and a waiting note', /Saved on this device/.test(await alice.textContent('#done')));
  check('nothing reached the server yet', dump().candidates.every((c) => c.name !== 'Offline Olive'));
  await alice.context().setOffline(false);
  await alice.evaluate(() => window.dispatchEvent(new Event('online')));
  await alice.waitForFunction(() => document.querySelector('#queueNote').hidden, null, { timeout: 8000 });
  d = dump();
  check('back online: sent automatically, exactly once', d.candidates.filter((c) => c.name === 'Offline Olive').length === 1);
  await alice.reload(); await sleep(500);
  check('reloading does not send it again', dump().candidates.filter((c) => c.name === 'Offline Olive').length === 1);

  console.log('\nSecurity');
  const anon = await open(null); await anon.goto(`${BASE}/members/career-code/manual/`);
  check('live.php without a login is refused', await anon.evaluate(async (u) => (await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: '{"action":"start"}' })).status, `${BASE}/members/api/live.php`) === 401);
  const nofetch = await open('alice@x.com'); await nofetch.goto(`${BASE}/members/career-code/`);
  check('live.php without the custom header is refused (cross-site)', await nofetch.evaluate(async (u) => (await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{"action":"start"}' })).status, `${BASE}/members/api/live.php`) === 403);
  check('GET on live.php is refused', await nofetch.evaluate(async (u) => (await fetch(u, { headers: { 'X-Requested-With': 'fetch' } })).status, `${BASE}/members/api/live.php`) === 405);

  check('no JavaScript errors anywhere', problems.length === 0, problems.join(' | '));
  await browser.close(); procs.forEach((p) => p.kill());
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); procs.forEach((p) => p.kill()); process.exit(1); });
