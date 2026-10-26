// Run: node tests/e2e_disc.js   Browser test of the DISC step: advisor invites, candidate takes it, advisor reads it.
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), http = require('http'), crypto = require('crypto');
const { chromium } = require('/opt/node-tools/node_modules/playwright');

const ROOT = path.resolve(__dirname, '..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'e3disc-'));
const DB = path.join(tmp, 'db.sqlite'), CFG = path.join(tmp, 'config.php'), MAILS = path.join(tmp, 'mails.jsonl');
const PORT = 8169, JWKS = 8168, SMTPPORT = 8167, BASE = `http://127.0.0.1:${PORT}`, TEAM = 'test.cloudflareaccess.com', AUD = 'aud-test';
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
    'allowed_origins' => ['127.0.0.1'], 'base_url' => '${BASE}', 'default_owner_email' => 'you@x.com', 'ip_salt' => 'e2e',
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

  console.log('Advisor invites (Alice invites her candidate Dina)');
  const alice = await open('alice@x.com');
  const dina = candId('Dina Dass');
  await alice.goto(`${BASE}/members/career-code/dashboard/`); await alice.waitForSelector('#list .item');
  check('list shows a "Ready to invite" tag on Dina', /Ready to invite/.test(await alice.locator('.item', { hasText: 'Dina Dass' }).textContent()));
  await openCand(alice, dina);
  check('detail shows the DISC block with "Ready to invite"', /DISC working style/.test(await alice.textContent('#discBlock')) && /Ready to invite/.test(await alice.textContent('#discBlock')));
  check('buttons: Send invite by email, Create link to copy, Start DISC now', await alice.isVisible('[data-disc=invite][data-channel=email]') && await alice.isVisible('[data-disc=invite][data-channel=link]') && await alice.isVisible('[data-disc=live]'));
  const m0 = mails().length;
  await alice.click('[data-disc=invite][data-channel=email]');
  await alice.waitForFunction(() => /DISC invited/.test(document.querySelector('#discBlock').textContent), null, { timeout: 8000 });
  check('after sending: tag "DISC invited", link shown, 1 email to Dina', rcpt(m0).join() === '<c20000003@x.com>' && /\/career-code\/disc\/\?t=[a-f0-9]{64}/.test(await alice.inputValue('#discLink')), rcpt(m0).join());
  const link = await alice.inputValue('#discLink');
  check('the invite email has the link and does not hint at a verdict', decodeBodies(mails()[m0]).includes(link.split('?t=')[1]) && !/Strong fit|Good fit|Borderline|qualif|passed/i.test(decodeBodies(mails()[m0])));
  check('asked "Mark as Contacted?" and said yes: status is now Contacted', dump().candidates.find((c) => c.id == dina).pipeline_status === 'contacted');
  check('buttons are now: Send reminder, Start DISC now (no second invite)', await alice.isVisible('[data-disc=remind]') && !(await alice.isVisible('[data-disc=invite]')));
  await alice.screenshot({ path: path.join(SHOTS, 'x1-invited.png'), fullPage: true });

  console.log('\nCandidate takes it (no login)');
  const cand = await open(null, { width: 420, height: 900 });
  await cand.goto(link); await cand.waitForSelector('#s-intro:not([hidden])');
  check('first screen says it is for Dina only, asks for digits and consent', /for Dina only/.test(await cand.textContent('#iFor')) && await cand.isVisible('#digits') && await cand.isVisible('#consent'));
  check('page contains no style letters / scoring text', !/most.*DISC_TYPES|score\(/i.test(await cand.content()));
  await cand.click('#startBtn');
  check('empty digits refused', /last 4 digits/i.test(await cand.textContent('#iErr')));
  await cand.fill('#digits', '0000'); await cand.click('#consent'); await cand.click('#startBtn');
  await cand.waitForFunction(() => /do not match/i.test(document.querySelector('#iErr').textContent));
  check('wrong digits: "do not match … tries left"', /tries left/.test(await cand.textContent('#iErr')));
  const last4 = dump().candidates.find((c) => c.id == dina).mobile.slice(-4);
  await cand.fill('#digits', last4); await cand.click('#startBtn'); await cand.waitForSelector('#s-test:not([hidden])');
  const answerPage = async (page, most, least) => {
    for (let q = page * 7; q < page * 7 + 7; q++) {
      const m = Q[q].indexOf(most), l = Q[q].indexOf(least);
      await cand.click(`.wb.m[data-q="${q}"][data-p="${m}"]`); await cand.click(`.wb.l[data-q="${q}"][data-p="${l}"]`);
    }
  };
  await cand.click('#t-next');
  check('cannot go on with unanswered questions', /one "most" and one "least"/.test(await cand.textContent('#t-err')));
  await answerPage(0, 'I', 'C');
  check('"most" disables the same word\'s "least"', await cand.isDisabled('.wb.l[data-q="0"][data-p="' + Q[0].indexOf('I') + '"]'));
  await cand.reload(); await cand.waitForSelector('#s-test:not([hidden])');
  check('reload mid-way keeps the answers (resume)', (await cand.$$('.wb[aria-pressed=true]')).length === 14);
  await cand.click('#t-next');
  for (const p of [1, 2, 3]) { await answerPage(p, 'I', 'C'); await cand.click('#t-next'); if (p < 3) await cand.waitForSelector(`.wq[data-q="${(p + 1) * 7}"]`); }
  await cand.waitForSelector('#s-result:not([hidden])');
  check('result page: Influence, positive text, strengths, tips', /Influence/.test(await cand.textContent('#r-style')) && (await cand.$$('#r-str li')).length === 3 && (await cand.$$('#r-tips li')).length === 3);
  check('result page has no fit / verdict wording', !/suitab|verdict|\bfit\b|qualif/i.test(await cand.textContent('#s-result')));
  check('says the result was emailed', /emailed/.test(await cand.textContent('#r-mail')));
  await cand.screenshot({ path: path.join(SHOTS, 'x2-result-phone.png'), fullPage: true });
  const tos = rcpt(m0 + 1);
  check('emails after completion: Dina + Alice + Amy + You', JSON.stringify(tos) === JSON.stringify(['<alice@x.com>', '<amy@x.com>', '<c20000003@x.com>', '<you@x.com>']), JSON.stringify(tos));
  const adv = mails().slice(m0 + 1).find((m) => m.to.join() === '<amy@x.com>');
  check('advisor notice: link only, no style or scores', /candidate=/.test(decodeBodies(adv)) && !/Influence|score|\+28/i.test(decodeBodies(adv)));
  await cand.goto(link);
  await cand.waitForSelector('#s-msg:not([hidden])');
  check('opening the same link again: "Already completed"', /Already completed/.test(await cand.textContent('#msgTitle')));
  const bad = await open(null); await bad.goto(`${BASE}/career-code/disc/?t=${'a'.repeat(64)}`); await bad.waitForSelector('#s-msg:not([hidden])');
  check('a made-up link: "This link is not valid"', /not valid/.test(await bad.textContent('#msgTitle')));
  const none = await open(null); await none.goto(`${BASE}/career-code/disc/`); await none.waitForSelector('#s-msg:not([hidden])');
  check('no link code at all: not valid', /not valid/.test(await none.textContent('#msgTitle')));

  console.log('\nAdvisor reads it');
  await openCand(alice, dina);
  check('tag is "DISC done"; no buttons for an invite that is finished except redo', /DISC done/.test(await alice.textContent('#discBlock')) && await alice.isVisible('[data-disc=reinvite]'));
  const blk = await alice.textContent('#discBlock');
  check('advisor view: style, four scores, how to talk, pressure, role, questions, compared with Career Code', /Style: Influence/.test(blk) && /\+28/.test(blk) && /How to talk to them/.test(blk) && /Under pressure/.test(blk) && /Interview questions/.test(blk) && /Compared with Career Code/.test(blk));
  check('flag shown: finished very quickly', /Finished very quickly/.test(blk));
  await alice.click('[data-lang="zh"]'); await alice.waitForFunction(() => /影响型/.test(document.querySelector('#discBlock').textContent));
  check('Chinese notes switch the DISC section too', /如何与对方沟通/.test(await alice.textContent('#discBlock')));
  await alice.click('[data-lang="en"]');
  await alice.screenshot({ path: path.join(SHOTS, 'x3-advisor.png'), fullPage: true });
  const amy = await open('amy@x.com'), ben = await open('ben@x.com');
  await amy.goto(`${BASE}/members/career-code/dashboard/`); await ben.goto(`${BASE}/members/career-code/dashboard/`);
  check('Amy (upline) sees the DISC result', (await amy.evaluate(async (u) => (await (await fetch(u)).json()).candidate.disc.tag, `${BASE}/members/api/candidates.php?id=${dina}`)) === 'done');
  check('Ben (other branch): candidate 404 and DISC action 404', (await ben.evaluate(async (u) => (await fetch(u)).status, `${BASE}/members/api/candidates.php?id=${dina}`)) === 404 && (await post(ben, '/members/api/disc.php', { action: 'invite', candidate_id: dina }))[0] === 404);

  console.log('\nStatus rules, links, in-the-room');
  const cara = candId('Cara Chin');
  await openCand(amy, cara);
  check('Amy\'s candidate Cara: Ready to invite', /Ready to invite/.test(await amy.textContent('#discBlock')));
  await amy.selectOption('#dStatus', 'not_suitable');
  await amy.waitForFunction(() => /switched off/.test(document.querySelector('#discBlock').textContent));
  check('marked Not suitable: invites switched off, no buttons', (await amy.$$('#discBlock [data-disc]')).length === 0);
  check('...and the server refuses it too (409)', (await post(amy, '/members/api/disc.php', { action: 'invite', candidate_id: cara }))[0] === 409);
  await amy.selectOption('#dStatus', 'contacted');
  await amy.waitForSelector('#discBlock [data-disc=invite]');
  check('set back to Contacted: the buttons return', true);
  const m1 = mails().length;
  await amy.click('[data-disc=invite][data-channel=link]');
  await amy.waitForSelector('#discLink');
  check('"Create link to copy": link shown, nothing emailed', mails().length === m1 && /\/career-code\/disc\/\?t=/.test(await amy.inputValue('#discLink')));
  const [livePage] = await Promise.all([amy.context().waitForEvent('page'), amy.click('[data-disc=live]')]);
  await livePage.waitForSelector('#s-intro:not([hidden])', { timeout: 8000 });
  check('"Start DISC now" opens the test with no digits box', await livePage.isHidden('#digitsBox') && /for Cara only/.test(await livePage.textContent('#iFor')));
  await livePage.fill('#consent', '').catch(() => {}); await livePage.click('#consent'); await livePage.click('#startBtn'); await livePage.waitForSelector('#s-test:not([hidden])');
  check('...and starts straight away', true);
  const una = candId('Unfinished Una');
  await openCand(await open('amy@x.com'), una).catch(() => {});
  const amy2 = await open('amy@x.com'); await amy2.goto(`${BASE}/members/career-code/dashboard/?candidate=${una}`).catch(() => {});
  check('an unfinished Career Code test gets no DISC block', (await amy2.evaluate(async (u) => (await (await fetch(u)).json()).candidate.disc.tag, `${BASE}/members/api/candidates.php?id=${una}`)) === null);
  const noMail = await post(amy, '/members/api/disc.php', { action: 'nope', candidate_id: cara });
  check('unknown action refused', noMail[0] === 400 || noMail[0] === 409, JSON.stringify(noMail));
  const getTry = await amy.evaluate(async (u) => (await fetch(u)).status, `${BASE}/members/api/disc.php`);
  const noHdr = await amy.evaluate(async (u) => (await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).status, `${BASE}/members/api/disc.php`);
  check('GET refused (405) and a POST without the custom header refused (403)', getTry === 405 && noHdr === 403);
  const anon = await open(null); await anon.goto(`${BASE}/career-code/disc/?t=${'b'.repeat(64)}`);
  check('the members DISC API without a login is refused', (await post(anon, '/members/api/disc.php', { action: 'invite', candidate_id: cara }))[0] === 401);
  check('no JavaScript errors anywhere', problems.length === 0, problems.join(' | '));
  await browser.close(); procs.forEach((p) => p.kill());
  console.log(`\n${pass} passed, ${fail} failed. Screenshots: ${SHOTS}`); process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); procs.forEach((p) => p.kill()); process.exit(1); });
