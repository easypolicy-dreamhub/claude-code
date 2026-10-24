// Run: node tests/e2e_dashboard.js   Browser test of /members/career-code/dashboard/ with real signed login tokens.
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), http = require('http'), crypto = require('crypto');
const { chromium } = require('/opt/node-tools/node_modules/playwright');

const ROOT = path.resolve(__dirname, '..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'e3dash-'));
const DB = path.join(tmp, 'db.sqlite'), CFG = path.join(tmp, 'config.php');
const PORT = 8199, JWKS = 8198, BASE = `http://127.0.0.1:${PORT}`, TEAM = 'test.cloudflareaccess.com', AUD = 'aud-test';
const SHOTS = process.env.SHOTS || tmp;
let pass = 0, fail = 0;
const check = (name, ok, extra = '') => { ok ? pass++ : fail++; console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${name}${ok ? '' : '  ' + extra}`); };
const procs = [];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const { privateKey, publicKey } = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 });
const jwk = { ...publicKey.export({ format: 'jwk' }), kid: 'k1', alg: 'RS256', use: 'sig' };
const b64 = (o) => Buffer.from(typeof o === 'string' ? o : JSON.stringify(o)).toString('base64url');
const mint = (email) => { const h = b64({ alg: 'RS256', kid: 'k1', typ: 'JWT' }), p = b64({ email, aud: [AUD], iss: `https://${TEAM}`, exp: Math.floor(Date.now() / 1000) + 3600 });
  return `${h}.${p}.${crypto.sign('RSA-SHA256', Buffer.from(`${h}.${p}`), privateKey).toString('base64url')}`; };

(async () => {
  execFileSync('php', [path.join(ROOT, 'tests/e2e_db.php'), 'seed-team', DB]);
  fs.mkdirSync(path.join(tmp, 'cache'));
  fs.writeFileSync(CFG, `<?php return [
    'db' => ['dsn' => 'sqlite:${DB}', 'user' => null, 'pass' => null],
    'cf_access' => ['team_domain' => '${TEAM}', 'aud' => '${AUD}', 'cache_dir' => '${path.join(tmp, 'cache')}', 'jwks_url' => 'http://127.0.0.1:${JWKS}/certs'],
    'allowed_origins' => ['127.0.0.1'], 'base_url' => 'https://dreamhub-e3.com',
  ];`);
  const jwksServer = http.createServer((q, r) => { r.setHeader('Content-Type', 'application/json'); r.end(JSON.stringify({ keys: [jwk] })); }).listen(JWKS);
  const phpSrv = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public_html')], { env: { ...process.env, E3_CONFIG: CFG, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  procs.push(phpSrv);
  await sleep(1200);

  const browser = await chromium.launch();
  const problems = [];
  const open = async (email, viewport = { width: 1100, height: 900 }) => {
    const ctx = await browser.newContext({ viewport, extraHTTPHeaders: email ? { 'Cf-Access-Jwt-Assertion': mint(email) } : {} });
    await ctx.route('**/*', (r) => (new URL(r.request().url()).hostname === '127.0.0.1' ? r.continue() : r.abort()));
    const page = await ctx.newPage();
    page.on('pageerror', (e) => problems.push(`${email}: pageerror ${e.message}`));
    page.on('dialog', (d) => d.accept());
    return page;
  };
  const dash = async (email, qs = '', vp) => { const p = await open(email, vp); await p.goto(`${BASE}/members/career-code/dashboard/${qs}`); await p.waitForFunction(() => document.querySelector('#count').textContent || document.querySelector('#msg').textContent || document.querySelector('#detail:not([hidden])') || document.querySelector('.empty'), null, { timeout: 8000 }); return p; };
  const names = (p) => p.$$eval('#list .item .name', (e) => e.map((x) => x.textContent).sort());
  const api = (p, url, method = 'GET', body) => p.evaluate(async ([u, m, b]) => { const r = await fetch(u, { method: m, headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: b ? JSON.stringify(b) : undefined }); const t = await r.text(); let j; try { j = JSON.parse(t); } catch (e) { j = t; } return [r.status, j]; }, [`${BASE}/members/api/candidates.php${url}`, method, body]);

  console.log('Amy (Unit Manager: sees herself + Alice)');
  const amy = await dash('amy@x.com');
  check('Amy sees Cara (hers) and Dina (Alice\'s) only', JSON.stringify(await names(amy)) === JSON.stringify(['Cara Chin', 'Dina Dass']), JSON.stringify(await names(amy)));
  check('header says signed in as Amy, not admin', /Signed in as Amy/.test(await amy.textContent('#who')) && !/admin/.test(await amy.textContent('#who')));
  check('no CSV export button for a non-admin', await amy.isHidden('#export'));
  check('scope label is "My team"', (await amy.textContent('#fScope option:first-child')) === 'My team');
  check('Ben\'s and Bella\'s candidates are not in the list', !(await names(amy)).some((n) => /Eddy|Fiona|Zed/.test(n)));
  check('API: Eddy (Ben\'s) -> 404 for Amy', (await api(amy, '?id=4'))[0] === 404);
  check('API: Fiona (Bella\'s) -> 404 for Amy', (await api(amy, '?id=5'))[0] === 404);
  check('API: Zed (You) -> 404 for Amy (upline\'s candidate)', (await api(amy, '?id=1'))[0] === 404);
  check('API: CSV export -> 403', (await api(amy, '?export=csv'))[0] === 403);
  check('API: delete her own candidate -> 403', (await api(amy, '?id=2', 'DELETE'))[0] === 403);
  check('API: give Cara to Ben (outside her team) -> 403', (await api(amy, '?id=2', 'PATCH', { owner_member_id: 3 }))[0] === 403 || (await api(amy, '?id=2', 'PATCH', { owner_member_id: 4 }))[0] === 403);
  const amyOther = await dash('amy@x.com', '?candidate=4');
  check('opening a candidate link she may not see shows the list and a "not available" message', await amyOther.isVisible('#listview') && /not available/.test(await amyOther.textContent('#toast')));
  await amy.screenshot({ path: path.join(SHOTS, 'd1-amy-list.png') });

  console.log('\nAlice (agent under Amy)');
  const alice = await dash('alice@x.com');
  check('Alice sees only Dina', JSON.stringify(await names(alice)) === JSON.stringify(['Dina Dass']));
  check('API: Cara (her upline\'s) -> 404 for Alice', (await api(alice, '?id=2'))[0] === 404);
  await alice.click('.item');
  await alice.waitForSelector('#dOwner');
  check('Alice\'s reassign list contains only herself', JSON.stringify(await alice.$$eval('#dOwner option', (o) => o.map((x) => x.textContent))) === JSON.stringify(['Alice']));

  console.log('\nLogin edge cases');
  const stranger = await dash('stranger@x.com');
  check('valid Cloudflare login but not a member: told they are not registered, sees no data', /not registered/i.test(await stranger.textContent('#msg')) && (await stranger.$$('#list .item')).length === 0);
  const nobody = await dash(null);
  check('no login token at all: "Login required"', /Login required/i.test(await nobody.textContent('#msg')));
  const forged = await open(null);
  const [fh, , fs_] = mint('amy@x.com').split('.');
  const fp = b64({ email: 'you@x.com', aud: [AUD], iss: `https://${TEAM}`, exp: Math.floor(Date.now() / 1000) + 3600 });
  await forged.context().setExtraHTTPHeaders({ 'Cf-Access-Jwt-Assertion': `${fh}.${fp}.${fs_}` });   // Amy's signature on a payload claiming to be You
  await forged.goto(`${BASE}/members/career-code/dashboard/`); await forged.waitForSelector('#msg .error');
  check('a forged token (Amy\'s signature, payload claiming to be the admin) is rejected', /signature/i.test(await forged.textContent('#msg')) && (await forged.$$('#list .item')).length === 0);
  const csrf = await open('you@x.com'); await csrf.goto(`${BASE}/members/career-code/dashboard/`);
  check('cross-site style write (no custom header) is refused even for the admin', await csrf.evaluate(async (u) => (await fetch(u, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: '{"pipeline_status":"joined"}' })).status, `${BASE}/members/api/candidates.php?id=1`) === 403);

  console.log('\nYou (admin)');
  const adm = await dash('you@x.com');
  check('admin sees all 5 finished candidates', (await names(adm)).length === 5, JSON.stringify(await names(adm)));
  check('admin has CSV export and scope label "Everyone"', await adm.isVisible('#export') && (await adm.textContent('#fScope option:first-child')) === 'Everyone');
  await adm.selectOption('#fPurpose', 'internship'); await adm.waitForFunction(() => /2 of 2|Showing 2/.test(document.querySelector('#count').textContent));
  check('filter: internship -> Dina and Fiona', JSON.stringify(await names(adm)) === JSON.stringify(['Dina Dass', 'Fiona Foo']));
  await adm.selectOption('#fPurpose', '');
  await adm.fill('#q', 'Cara'); await adm.waitForFunction(() => document.querySelectorAll('#list .item').length === 1);
  check('search: "Cara" -> 1 result', true);
  await adm.fill('#q', ''); await adm.waitForFunction(() => document.querySelectorAll('#list .item').length === 5);
  await adm.check('#fUnfinished'); await adm.waitForFunction(() => document.querySelectorAll('#list .item').length === 6);
  check('include unfinished shows Una, marked Unfinished', (await adm.textContent('#list')).includes('Unfinished Una') && (await adm.textContent('#list')).includes('Unfinished'));
  await adm.uncheck('#fUnfinished'); await adm.waitForFunction(() => document.querySelectorAll('#list .item').length === 5);
  await adm.screenshot({ path: path.join(SHOTS, 'd2-admin-list.png') });

  console.log('\nCandidate detail + advisor notes');
  await adm.click('.item:has-text("Cara Chin")');
  await adm.waitForSelector('#dStatus');
  check('URL carries ?candidate= (the link in advisor emails)', /candidate=2/.test(adm.url()));
  const dtext = await adm.textContent('#detail');
  check('shows code, verdict, criteria, pattern, questions', /Good fit, needs admin support/.test(dtext) && /Criteria check/.test(dtext) && /Score pattern/.test(dtext) && /Suggested interview questions/.test(dtext));
  check('shows the advisor chain Amy > You and the details entered', /Amy\s*›\s*You/.test(dtext) && /Marketing/.test(dtext) && /Diploma/.test(dtext));
  const kv = await adm.$$eval('#detail dl.kv dt', (dts) => dts.filter((d) => d.offsetParent !== null).map((d) => [d.textContent, d.nextElementSibling && d.nextElementSibling.offsetParent !== null ? d.nextElementSibling.textContent : null]));
  const kvm = Object.fromEntries(kv);
  check('info block: every visible label has its own value (nothing shifted)', kv.length >= 8 && kv.every(([, v]) => v !== null) && kvm['Age group'] === '26 to 30' && kvm['Qualification'] === 'Diploma' && kvm['Status'] === 'Working full-time' && kvm['Job / studies'] === 'Marketing', JSON.stringify(kv));
  check('hexagon chart with the 25-point line drawn', (await adm.$$('#detail svg polygon')).length >= 7);
  check('code letters E S C', (await adm.$$eval('#detail .detail-head .code span', (s) => s.map((x) => x.textContent).join(''))) === 'ESC');
  await adm.screenshot({ path: path.join(SHOTS, 'd3-detail.png'), fullPage: true });
  await adm.click('[data-lang="zh"]');
  await adm.waitForFunction(() => /合适，需要行政支援/.test(document.querySelector('#advisorBody').textContent));
  check('advisor notes switch to Chinese', true);
  await adm.click('[data-lang="en"]'); await adm.waitForFunction(() => /Good fit/.test(document.querySelector('#advisorBody').textContent));
  await adm.selectOption('#dStatus', 'contacted');
  await adm.waitForFunction(() => /Status saved: Contacted/.test(document.querySelector('#toast').textContent));
  check('status change saves (toast) and persists on the server', (await api(adm, '?id=2'))[1].candidate.status === 'contacted');
  await adm.selectOption('#dOwner', { label: 'Ben' });
  await adm.waitForFunction(() => /Assigned to Ben/.test(document.querySelector('#toast').textContent));
  check('reassign Cara to Ben: detail now shows Ben', /Ben\s*›\s*You/.test(await adm.textContent('#detail')));
  check('...Amy no longer sees Cara, Ben does', (await api(amy, '?id=2'))[0] === 404 && (await api(await dash('ben@x.com'), '?id=2'))[0] === 200);
  await adm.click('#backBtn'); await adm.waitForSelector('#listview:not([hidden])');
  check('back to the list', await adm.isVisible('#list'));
  const csv = await api(adm, '?export=csv');
  check('CSV export works for admin (header + 5 candidates + Una)', csv[0] === 200 && String(csv[1]).split('\r\n').filter(Boolean).length === 7 && /Cara Chin/.test(csv[1]));
  await adm.click('.item:has-text("Eddy Ee")'); await adm.waitForSelector('#delBtn'); await adm.click('#delBtn');
  await adm.waitForFunction(() => /Candidate deleted/.test(document.querySelector('#toast').textContent));
  await adm.waitForFunction(() => document.querySelectorAll('#list .item').length === 4);
  check('admin deletes Eddy; list shrinks to 4', !(await names(adm)).includes('Eddy Ee'));

  console.log('\nRepeat applicant through someone else\'s link');
  execFileSync('php', [path.join(ROOT, 'tests/e2e_db.php'), 'add-dup', DB]);
  await adm.reload(); await adm.waitForSelector('#list .item');
  const rita = adm.locator('.item', { hasText: 'Repeat Rita' });
  check('list row shows the Repeat applicant tag and "Via Amy\'s link"', /Repeat applicant/.test(await rita.textContent()) && /Via Amy\u2019s link/.test(await rita.textContent()), await rita.textContent());
  check('it stays with the first advisor (You), not Amy', /Advisor: You/.test(await rita.textContent()) || !/Advisor: Amy/.test(await rita.textContent()));
  await rita.click(); await adm.waitForSelector('#dStatus');
  const rtext = await adm.textContent('#detail');
  check('detail says "Link used: Amy\u2019s link (already registered ...)" and shows the earlier-record warning', /Link used/.test(rtext) && /Amy\u2019s link/.test(rtext) && /already registered/.test(rtext) && /took the test before/.test(rtext));
  check('Amy cannot open Rita (she only gets a short email)', (await api(amy, '?id=7'))[0] === 404 && !(await names(amy)).includes('Repeat Rita'));
  await adm.screenshot({ path: path.join(SHOTS, 'd6-repeat-link.png') });

  console.log('\nPhone layout');
  const phone = await dash('you@x.com', '', { width: 390, height: 844 });
  await phone.screenshot({ path: path.join(SHOTS, 'd4-phone-list.png'), fullPage: true });
  await phone.click('.item >> nth=0'); await phone.waitForSelector('#dStatus');
  await phone.screenshot({ path: path.join(SHOTS, 'd5-phone-detail.png'), fullPage: true });
  check('no horizontal scrolling on a phone', await phone.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
  check('no JavaScript errors during the whole run', problems.length === 0, problems.join(' | '));

  await browser.close(); procs.forEach((p) => p.kill()); jwksServer.close();
  console.log(`\n${pass} passed, ${fail} failed. Screenshots: ${SHOTS}`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); procs.forEach((p) => p.kill()); process.exit(1); });
