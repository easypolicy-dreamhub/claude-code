// Run: node tests/e2e_activity.js   Browser test of /members/activity/ with signed login tokens.
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), http = require('http'), crypto = require('crypto');
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const ROOT = path.resolve(__dirname, '..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'e3act-'));
const DB = path.join(tmp, 'db.sqlite'), CFG = path.join(tmp, 'config.php');
const PORT = 8179, JWKS = 8178, BASE = `http://127.0.0.1:${PORT}`, TEAM = 'test.cloudflareaccess.com', AUD = 'aud-test';
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
  const browser = await chromium.launch(); const problems = [];
  const open = async (email, viewport = { width: 1024, height: 1200 }) => {
    const ctx = await browser.newContext({ viewport, extraHTTPHeaders: email ? { 'Cf-Access-Jwt-Assertion': mint(email) } : {} });
    await ctx.route('**/*', (r) => (new URL(r.request().url()).hostname === '127.0.0.1' ? r.continue() : r.abort()));
    const page = await ctx.newPage(); page.on('pageerror', (e) => problems.push(`${email}: ${e.message}`)); return page;
  };
  const go = async (email, vp) => { const p = await open(email, vp); await p.goto(`${BASE}/members/activity/`); await p.waitForFunction(() => document.querySelector('#people tbody') || document.querySelector('#err').textContent, null, { timeout: 8000 }); return p; };
  const peopleNames = (p) => p.$$eval('#people tbody tr td:first-child a', (a) => a.map((x) => x.textContent));

  console.log('Admin');
  const adm = await go('you@x.com');
  check('sees the whole tree', JSON.stringify(await peopleNames(adm)) === JSON.stringify(['You', 'Amy', 'Alice', 'Ben', 'Bella', 'Dormant']), JSON.stringify(await peopleNames(adm)));
  check('stat tiles: 6 added / 5 finished', /6[\s\S]*Candidates added/.test(await adm.textContent('#stats')) && /5[\s\S]*Tests finished/.test(await adm.textContent('#stats')), await adm.textContent('#stats'));
  check('status bar and legend drawn', (await adm.$$('#bar i')).length >= 1 && /New/.test(await adm.textContent('#legend')));
  check('recent activity lists candidates by name', /Cara Chin/.test(await adm.textContent('#feed')));
  await adm.screenshot({ path: path.join(SHOTS, 'a1-admin.png'), fullPage: true });
  await adm.click('.pill[data-p=all]'); await adm.waitForFunction(() => document.querySelector('.pill[data-p=all]').getAttribute('aria-pressed') === 'true');
  check('period pills switch', true);
  await adm.click('#people a:has-text("Amy")'); await adm.waitForSelector('#list .item');
  check('tapping a name opens the candidate list for that person only', JSON.stringify(await adm.$$eval('#list .item .name', (e) => e.map((x) => x.textContent).sort())) === JSON.stringify(['Cara Chin']), page_url(adm));
  check('...labelled "Showing Amy’s candidates only" with a "Show everyone" link', /Showing\s+Amy’s candidates\s+only/.test(await adm.textContent('#ownerBar')) && /Show everyone/.test(await adm.textContent('#ownerBar')), await adm.textContent('#ownerBar'));
  await adm.goBack(); await adm.waitForSelector('#people tbody a');
  await adm.click('#people a:has-text("Dormant")'); await adm.waitForSelector('#list .empty');
  check('a person with no candidates says so by name', /Dormant has no candidates yet/.test(await adm.textContent('#list')) && /Dormant’s candidates/.test(await adm.textContent('#ownerBar')), await adm.textContent('#list'));
  function page_url(p) { return p.url(); }

  console.log('\nAmy (unit manager)');
  const amy = await go('amy@x.com');
  check('sees herself and Alice only', JSON.stringify(await peopleNames(amy)) === JSON.stringify(['Amy', 'Alice']), JSON.stringify(await peopleNames(amy)));
  const feed = await amy.textContent('#feed');
  check('her feed has no Ben, Bella or You candidates', !/Eddy|Fiona|Zed/.test(feed), feed);
  const direct = await amy.evaluate(async (u) => (await fetch(u)).json(), `${BASE}/members/api/activity.php?period=all`);
  check('API data for Amy contains nobody outside her tree', JSON.stringify(direct).indexOf('Ben') === -1 && JSON.stringify(direct).indexOf('Eddy') === -1);

  console.log('\nOthers');
  const alice = await go('alice@x.com');
  check('Alice (agent) sees only herself', JSON.stringify(await peopleNames(alice)) === JSON.stringify(['Alice']));
  const stranger = await go('stranger@x.com');
  check('a login that is not a member sees an error, no data', /not registered/i.test(await stranger.textContent('#err')) && (await stranger.$$('#people tbody tr')).length === 0);
  const phone = await go('you@x.com', { width: 390, height: 844 });
  await phone.screenshot({ path: path.join(SHOTS, 'a2-phone.png'), fullPage: true });
  check('no sideways page scroll on a phone', await phone.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
  check('no JavaScript errors', problems.length === 0, problems.join(' | '));
  await browser.close(); procs.forEach((p) => p.kill());
  console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); procs.forEach((p) => p.kill()); process.exit(1); });
