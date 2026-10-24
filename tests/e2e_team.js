// Run: node tests/e2e_team.js   Browser test of /members/team/ (ranks, moves, removals) with real signed login tokens.
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), http = require('http'), crypto = require('crypto');
const { chromium } = require('/opt/node-tools/node_modules/playwright');

const ROOT = path.resolve(__dirname, '..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'e3team-'));
const DB = path.join(tmp, 'db.sqlite'), CFG = path.join(tmp, 'config.php');
const PORT = 8299, JWKS = 8298, BASE = `http://127.0.0.1:${PORT}`, TEAM = 'test.cloudflareaccess.com', AUD = 'aud-test';
const SHOTS = process.env.SHOTS || tmp;
let pass = 0, fail = 0;
const check = (name, ok, extra = '') => { ok ? pass++ : fail++; console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${name}${ok ? '' : '  ' + extra}`); };
const procs = [];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const { privateKey, publicKey } = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 });
const jwk = { ...publicKey.export({ format: 'jwk' }), kid: 'k1', alg: 'RS256', use: 'sig' };
const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64url');
const mint = (email) => { const h = b64({ alg: 'RS256', kid: 'k1' }), p = b64({ email, aud: [AUD], iss: `https://${TEAM}`, exp: Math.floor(Date.now() / 1000) + 3600 });
  return `${h}.${p}.${crypto.sign('RSA-SHA256', Buffer.from(`${h}.${p}`), privateKey).toString('base64url')}`; };

(async () => {
  execFileSync('php', [path.join(ROOT, 'tests/e2e_db.php'), 'seed-team', DB]);   // You(UM, admin) > Amy(UM) > Alice ; You > Ben(agent) > Bella ; You > Dormant
  fs.mkdirSync(path.join(tmp, 'cache'));
  fs.writeFileSync(CFG, `<?php return ['db' => ['dsn' => 'sqlite:${DB}', 'user' => null, 'pass' => null],
    'cf_access' => ['team_domain' => '${TEAM}', 'aud' => '${AUD}', 'cache_dir' => '${path.join(tmp, 'cache')}', 'jwks_url' => 'http://127.0.0.1:${JWKS}/certs'],
    'allowed_origins' => ['127.0.0.1'], 'base_url' => 'https://dreamhub-e3.com'];`);
  const jw = http.createServer((q, r) => { r.setHeader('Content-Type', 'application/json'); r.end(JSON.stringify({ keys: [jwk] })); }).listen(JWKS);
  procs.push(spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public_html')], { env: { ...process.env, E3_CONFIG: CFG, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' }));
  await sleep(1200);
  const browser = await chromium.launch();
  const problems = [];
  const open = async (email, viewport = { width: 1100, height: 900 }) => {
    const ctx = await browser.newContext({ viewport, acceptDownloads: true, extraHTTPHeaders: { 'Cf-Access-Jwt-Assertion': mint(email) } });
    // No request routing here: Playwright cancels downloads in a context that has any route registered.
    const p = await ctx.newPage();
    p.on('pageerror', (e) => problems.push(`${email}: ${e.message}`));
    p.on('dialog', (d) => d.accept());
    return p;
  };
  const team = async (email, vp) => { const p = await open(email, vp); await p.goto(`${BASE}/members/team/`); await p.waitForSelector('#tree .node, #msg .error'); return p; };
  const row = (p, name) => p.locator('.node', { has: p.locator('.name', { hasText: new RegExp(`^${name}$`) }) }).first();
  const parentOf = (p, name) => p.evaluate((n) => { const li = [...document.querySelectorAll('.node .name')].find((x) => x.textContent === n).closest('li').parentElement.closest('li'); return li ? li.querySelector('.name').textContent : null; }, name);
  const tree = async (email) => { const p = await open(email); await p.goto(`${BASE}/members/team/`); await p.waitForSelector('#tree .node'); const n = await p.$$eval('.node .name', (e) => e.map((x) => x.textContent)); await p.close(); return n.sort(); };
  const candStatus = (p, id) => p.evaluate(async (u) => (await fetch(u)).status, `${BASE}/members/api/candidates.php?id=${id}`);
  const manage = async (p, name) => { await row(p, name).locator('[data-manage]').click(); await p.waitForSelector('dialog[open] #mRank'); };
  const changeRank = async (p, name, rank, untick = false) => {
    await manage(p, name); await p.selectOption('#mRank', rank); await p.click('#mRankGo'); await p.waitForSelector('#mPlan .plan');
    const text = await p.textContent('#mPlan');
    if (untick) for (const c of await p.$$('#mPlan .mv')) await c.uncheck();
    await p.click('#mApply'); await p.waitForSelector('dialog[open]', { state: 'detached' }).catch(() => {}); await p.waitForFunction(() => !document.querySelector('dialog').open);
    return text;
  };

  console.log('Super admin view');
  const you = await team('you@x.com');
  check('tree shows You at the top, Amy > Alice and Ben > Bella', (await parentOf(you, 'Alice')) === 'Amy' && (await parentOf(you, 'Bella')) === 'Ben' && (await parentOf(you, 'Amy')) === 'You' && (await parentOf(you, 'You')) === null);
  check('Add member button for the super admin', await you.isVisible('#addBtn'));
  await you.screenshot({ path: path.join(SHOTS, 't1-tree.png'), fullPage: true });

  console.log('\nRanks with previews');
  let t = await changeRank(you, 'You', 'group_manager');
  check('You -> Group Manager: preview says nobody moves', /Nobody moves/.test(t));
  t = await changeRank(you, 'Alice', 'unit_manager');
  check('Alice (under Unit Manager Amy) -> Unit Manager: parallel, nobody moves', /Nobody moves/.test(t) && (await parentOf(you, 'Alice')) === 'Amy');
  t = await changeRank(you, 'Bella', 'unit_manager');
  check('Bella outranks agent Ben: preview suggests Bella -> You, confirmed', /Bella.*move from Ben to You/.test(t) && (await parentOf(you, 'Bella')) === 'You');
  const ben = await open('ben@x.com'); await ben.goto(`${BASE}/members/team/`); await ben.waitForSelector('#tree .node');
  check('Ben can no longer see Bella\'s candidate (Fiona)', (await candStatus(ben, 5)) === 404);
  t = await changeRank(you, 'Bella', 'agent');
  check('Bella demoted: stays under You (no going back to Ben)', (await parentOf(you, 'Bella')) === 'You');
  await changeRank(you, 'Ben', 'unit_manager');
  await manage(you, 'Dormant'); await you.click('#mRankGo'); await you.waitForFunction(() => /already their rank/.test(document.querySelector('#mPlan').textContent));
  check('choosing the current rank: told it is already their rank, nothing to confirm', !(await you.$('#mApply')));
  await you.click('[data-close]');
  t = await changeRank(you, 'Amy', 'group_manager');
  check('Amy -> Group Manager under Group Manager You: preview explains the GE stay/opt-out choice', /Group Manager under a Group Manager/.test(t));
  await manage(you, 'Alice'); await you.selectOption('#mRank', 'group_manager'); await you.click('#mRankGo'); await you.waitForSelector('#mPlan .plan');
  check('preview never changes anything by itself', (await you.evaluate(async (u) => (await (await fetch(u)).json()).members.find((m) => m.name === 'Alice').rank, `${BASE}/members/api/team.php`)) === 'unit_manager');
  await you.click('[data-close]');

  console.log('\nManual move (your CA example)');
  await manage(you, 'Alice'); await you.selectOption('#mTo', { label: 'Ben (Unit Manager)' }); await you.click('#mMove'); await you.waitForFunction(() => !document.querySelector('dialog').open);
  check('Alice moved from Amy to Ben', (await parentOf(you, 'Alice')) === 'Ben');
  const amy = await open('amy@x.com'); await amy.goto(`${BASE}/members/team/`); await amy.waitForSelector('#tree .node');
  check('Ben now sees Alice\'s candidate (Dina); Amy no longer does', (await candStatus(ben, 3)) === 200 && (await candStatus(amy, 3)) === 404);

  console.log('\nTeam admin');
  await manage(you, 'Amy'); await you.click('#mTA'); await you.waitForFunction(() => !document.querySelector('dialog').open);
  await manage(you, 'Ben'); await you.selectOption('#mTo', { label: 'Amy (Group Manager)' }); await you.click('#mMove'); await you.waitForFunction(() => !document.querySelector('dialog').open);
  const amyT = await team('amy@x.com');
  check('Amy (team admin) sees her group only, no Add button', JSON.stringify(await amyT.$$eval('.node .name', (e) => e.map((x) => x.textContent).sort())) === JSON.stringify(['Alice', 'Amy', 'Ben']) && await amyT.isHidden('#addBtn'));
  check('Amy can manage Ben and Alice but not herself', (await row(amyT, 'Ben').locator('[data-manage]').count()) === 1 && (await row(amyT, 'Amy').locator('[data-manage]').count()) === 0);
  await manage(amyT, 'Alice');
  check('Amy\'s move list only offers her own group, and no top level', JSON.stringify((await amyT.$$eval('#mTo option', (o) => o.map((x) => x.textContent.split(' (')[0]))).sort()) === JSON.stringify(['Amy', 'Ben']) && !(await amyT.isVisible('#mGroup')));
  await amyT.click('[data-close]');
  check('Amy cannot use the admin-only actions through the API', (await amyT.evaluate(async (u) => (await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action: 'add', name: 'X', email: 'x9@x.com' }) })).status, `${BASE}/members/api/team.php`)) === 403);
  await amyT.screenshot({ path: path.join(SHOTS, 't2-team-admin.png'), fullPage: true });

  console.log('\nSomeone leaves; a group opts out; comes back');
  await manage(you, 'Ben'); await you.click('#mRemove'); await you.waitForSelector('#rGo');
  check('remove preview: Alice goes to Amy (Ben\'s upline), with candidates', /Alice/.test(await you.textContent('dialog')) && (await you.$eval('#rTo', (s) => s.options[s.selectedIndex].textContent)).startsWith('Amy'));
  await you.click('#rGo'); await you.waitForFunction(() => !document.querySelector('dialog').open);
  check('Ben removed: Alice now under Amy; Eddy (Ben\'s candidate) now Amy\'s', (await parentOf(you, 'Alice')) === 'Amy' && (await candStatus(amy, 4)) === 200);
  await manage(you, 'Amy'); await you.click('#mGroup'); await you.waitForSelector('#gGo');
  check('remove-group button stays disabled until a copy is downloaded and REMOVE typed', await you.isDisabled('#gGo'));
  await you.fill('#gWord', 'REMOVE');
  check('...still disabled without the download', await you.isDisabled('#gGo'));
  // Playwright does not attach the test's login header to file downloads (Cloudflare does, on the real site),
  // so read the copy the way the link would get it, then click the link to unlock the button.
  const href = await you.getAttribute('#gCopy', 'href');
  const csv = await you.evaluate(async (u) => { const r = await fetch(u); return r.status === 200 && /attachment/.test(r.headers.get('content-disposition')) ? await r.text() : 'HTTP ' + r.status; }, href);
  await you.click('#gCopy').catch(() => {});
  check('downloaded copy holds the group\'s candidates (Cara, Dina, Eddy)', /Cara Chin/.test(csv) && /Dina Dass/.test(csv) && /Eddy Ee/.test(csv) && !/Zed Zhang/.test(csv));
  await you.waitForFunction(() => !document.querySelector('#gGo').disabled);
  await you.click('#gGo'); await you.waitForSelector('#gEmails');
  check('after removal: shows the emails to delete from Cloudflare', /amy@x\.com/.test(await you.textContent('#gEmails')) && /alice@x\.com/.test(await you.textContent('#gEmails')));
  await you.click('[data-close]');
  check('Amy and Alice are gone from the tree; their candidates deleted', !(await tree('you@x.com')).includes('Amy') && (await candStatus(you, 2)) === 404 && (await candStatus(you, 1)) === 200);
  await you.check('#showInactive'); await you.waitForSelector('[data-react]');
  await row(you, 'Amy').locator('[data-react]').click(); await you.waitForSelector('#aGo');
  const teamLabel = await you.textContent('label:has(#aTeam)');
  check('reactivate offers Alice (removed together) but not Ben (left earlier)', /Alice/.test(teamLabel) && !/Ben/.test(teamLabel), teamLabel);
  await you.click('#aGo'); await you.waitForFunction(() => !document.querySelector('dialog').open);
  await you.uncheck('#showInactive'); await you.waitForFunction(() => !document.querySelector('[data-react]'));
  check('Amy and Alice are back under You', (await parentOf(you, 'Amy')) === 'You' && (await parentOf(you, 'Alice')) === 'Amy');

  console.log('\nAdd a member; what an agent sees');
  await you.click('#addBtn'); await you.fill('#nName', 'Nadia Noor'); await you.fill('#nEmail', 'nadia@x.com'); await you.selectOption('#nUp', { label: 'Amy (Group Manager)' }); await you.click('#nGo');
  await you.waitForFunction(() => [...document.querySelectorAll('.node .name')].some((x) => x.textContent === 'Nadia Noor'));
  check('Nadia added under Amy', (await parentOf(you, 'Nadia Noor')) === 'Amy');
  const dormant = await team('dormant@x.com');
  check('a plain agent sees only themselves, with no Manage buttons', JSON.stringify(await dormant.$$eval('.node .name', (e) => e.map((x) => x.textContent))) === JSON.stringify(['Dormant']) && (await dormant.$$('[data-manage]')).length === 0);
  const phone = await team('you@x.com', { width: 390, height: 844 });
  await phone.screenshot({ path: path.join(SHOTS, 't3-phone.png'), fullPage: true });
  check('no horizontal scrolling on a phone', await phone.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
  await manage(phone, 'Amy'); await phone.screenshot({ path: path.join(SHOTS, 't4-phone-manage.png') });
  check('no JavaScript errors', problems.length === 0, problems.join(' | '));

  await browser.close(); procs.forEach((p) => p.kill()); jw.close();
  console.log(`\n${pass} passed, ${fail} failed. Screenshots: ${SHOTS}`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); procs.forEach((p) => p.kill()); process.exit(1); });
