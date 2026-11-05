// Headless check of monthly-report/index.html against a real production file.
// Usage: node monthly-report/test.cjs <production.xlsx> <path/to/xlsx.full.min.js>
// The xlsx library path is served in place of the cdnjs copy so the test runs offline.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const [xlsxFile, libFile] = process.argv.slice(2);
if (!xlsxFile || !libFile) { console.error('usage: node test.cjs <production.xlsx> <xlsx.full.min.js>'); process.exit(2); }

let failed = 0;
const check = (name, ok, extra) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok || !extra ? '' : '\n     ' + extra)); if (!ok) failed++; };

// The September sample messages, with the two agreed fixes:
// percentages rounded normally to 2 decimals, and DG goal line uses PFYLP.
const SEP_TEAM_NUMBERS = [
  'Monthly Goal: 800K x 10% = 80,000.00', '🎯Done: 25,933.25 (32.42%)',
  'ACC Goal: 800K x 68% = 544,000.00', '🎯ACC: 292,988.13 (53.86%)',
  'Monthly Goal: 900K x 10% = 90,000.00', '🎯Done: 32,275.20 (35.86%)',
  'ACC Goal: 900K x 68% = 612,000.00', '🎯ACC: 227,327.95 (37.15%)',
];
const SEP_MENTOR_NUMBERS = SEP_TEAM_NUMBERS.concat([
  '1st Quarter 5, 6, 7 = 18%', '2nd Quarter 7, 7, 8 = 22%', '3rd Quarter 9, 9, 10 = 28%', '4th Quarter 10, 11, 11 = 32%',
  'Monthly Goal: 400K x 10% = 40,000.00', '🎯Done: 8,844.85 (22.11%)', 'ACC Goal: 400K x 68% = 272,000.00', '🎯ACC: 128,520.15 (47.25%)',
  'Monthly Goal: 500K x 10% = 50,000.00', '🎯Done: 12,840.00 (25.68%)', 'ACC Goal: 500K x 68% = 340,000.00', '🎯ACC: 107,203.50 (31.53%)',
  'Manpower: 1+5+1\nFT: 4\nPT: 3', 'Active agent: 3/7\nDL1: 4\nDL2: 1\nDL4: 2', 'Unit NOC\nMonth: 12\nTotal: 73',
  '9月份自我点评:', '10月份计划与策略:', '🎯DG: PFYLP 800K / ANP 900K', '🎯PS: QFYLP 400K / ANP 500K',
]);
const OCT_MENTOR_NUMBERS = [
  '🎯Done: 8,135.75 (20.34%)', 'ACC Goal: 400K x 78% = 312,000.00', '🎯ACC: 136,655.90 (43.80%)',
  '🎯Done: 846.60 (1.69%)', '🎯ACC: 108,050.10 (27.71%)',
  '🎯Done: 17,503.37 (21.88%)', '🎯ACC: 310,491.50 (49.76%)',
  '🎯Done: 6,522.60 (7.25%)', '🎯ACC: 233,850.55 (33.31%)',
  'Active agent: 3/7', 'Unit NOC\nMonth: 3\nTotal: 76', '10月份自我点评:', '11月份计划与策略:',
];

(async () => {
  const browser = await chromium.launch();
  for (const vp of [{ width: 1280, height: 900 }, { width: 390, height: 844 }]) {
    const page = await browser.newPage({ viewport: vp });
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.route('**/*', route => {
      const u = route.request().url();
      if (u.includes('xlsx.full.min.js')) return route.fulfill({ body: fs.readFileSync(libFile), contentType: 'application/javascript' });
      if (u.startsWith('file:')) return route.continue();
      return route.abort();
    });
    await page.addInitScript(() => { Object.defineProperty(navigator, 'canShare', {value: () => true, configurable: true}); Object.defineProperty(navigator, 'share', {value: async d => { window.__shared = {n: d.files.length, type: d.files[0].type, size: d.files[0].size, text: d.text}; }, configurable: true}); });
    await page.goto('file://' + path.resolve(__dirname, 'index.html'));
    const tag = `[${vp.width}px] `;
    check(tag + 'empty state shows prompt', (await page.textContent('#out')).includes('Upload the production file'));

    await page.setInputFiles('#file', xlsxFile);
    await page.waitForFunction(() => !document.getElementById('month').disabled);
    const opts = await page.$$eval('#month option', o => o.map(x => x.textContent));
    check(tag + 'reads Jan–Oct tabs', opts.length === 10 && opts[9].startsWith('Oct 2026'), opts.join(' | '));
    check(tag + 'latest month selected by default', (await page.$eval('#month', s => s.value)) === '9');

    // October
    let mentor = await page.textContent('#out');
    for (const n of OCT_MENTOR_NUMBERS) check(tag + 'Oct mentor has ' + JSON.stringify(n), mentor.includes(n), mentor);
    check(tag + 'Oct header', mentor.startsWith('吳昌輝 UM3 以學習的心情匯報2026年10月份標準業務報告：'));

    // September
    await page.selectOption('#month', '8');
    mentor = await page.textContent('#out');
    for (const n of SEP_MENTOR_NUMBERS) check(tag + 'Sep mentor has ' + JSON.stringify(n).slice(0, 60), mentor.includes(n), mentor);
    await page.click('#tab-team');
    const team = await page.textContent('#out');
    check(tag + 'Sep team header', team.startsWith('DreamHub E3 2026年9月份標準業務報告：'));
    for (const n of SEP_TEAM_NUMBERS) check(tag + 'Sep team has ' + n, team.includes(n), team);
    check(tag + 'team message has no PS lines', !team.includes('PS QFYLP') && !team.includes('Manpower'));
    check(tag + 'WhatsApp link carries the message', decodeURIComponent((await page.getAttribute('#btnWa', 'href')).split('text=')[1]) === team);
    const facts = await page.textContent('#facts');
    check(tag + 'facts: active ratio and case size', facts.includes('3/7 (42.9%)') && facts.includes('2,689.60'), facts);

    // Draft comment is written from the figures, follows the month, and cycles versions
    check(tag + 'Sep team comment is empty until drafted', !team.includes('9月業績'));
    await page.click('#btnDraft');
    const SEP_DRAFT = '9月團隊 DG ANP 比上月增加 30%，感謝大家的努力。 不過業績還有進步空間，除了 Active Ratio 少於 50% 以外，Average Case Size 低於 3千。 Q4 只要專注於團隊發展，啟動 Active Ratio 與提高 Average Case Size, 必定可以大幅度提升團隊業績與氣勢，我們大家一起加油！';
    const team2 = await page.textContent('#out');
    check(tag + 'Sep draft: your wording plus the ANP gain', team2.includes(SEP_DRAFT), team2);
    await page.click('#btnDraft');
    check(tag + 'second press gives another version, no prompt', (await page.textContent('#out')).includes('一步一步把業績拉起來'));
    await page.selectOption('#month', '9');
    await page.click('#btnDraft');
    const octTeam = await page.textContent('#out');
    check(tag + 'Oct draft: says 10月, flags ANP drop, no September wording',
      octTeam.includes('10月業績目前還沒有很大的突破，除了 Active Ratio 少於 50% 以外，Average Case Size 低於 3千，DG ANP 比上月下降 80%') &&
      octTeam.includes('接下來的 11 月') && !octTeam.includes('9月'), octTeam);
    const ana = await page.textContent('#facts');
    check(tag + 'analysis panel lists top producer and idle people', ana.includes('Top ANP this month: Yiew Kien Peng') && ana.includes('No new cases this month (4/7)'), ana);
    await page.selectOption('#month', '8');

    // February: Total row in the file is wrong, page should warn and use the real sum
    await page.selectOption('#month', '1');
    const st = await page.textContent('#fileStatus');
    check(tag + 'Feb warns about bad Total row', st.includes('file says 9,066.22') && st.includes('9,457.45'), st);

    // Details carry over and persist across reload
    await page.selectOption('#month', '9');
    await page.click('#tab-mentor');
    await page.fill('#f-review', '完成了20R');
    await page.fill('#f-active', '4');
    mentor = await page.textContent('#out');
    check(tag + 'typed review shows', mentor.includes('10月份自我点评: 完成了20R'));
    check(tag + 'active override shows', mentor.includes('Active agent: 4/7'));
    await page.reload();
    await page.waitForFunction(() => !document.getElementById('month').disabled);
    mentor = await page.textContent('#out');
    check(tag + 'details survive reload without re-uploading', mentor.includes('完成了20R') && mentor.includes('Active agent: 4/7'));
    await page.click('[data-reset="active"]');
    check(tag + 'reset active returns to file figure', (await page.textContent('#out')).includes('Active agent: 3/7'));

    // Prospects: add, set temperature, hottest first, blank rows skipped
    await page.fill('#f-review', '完成了20R\n- 10月份啟動新夥伴中。');
    await page.fill('#f-plan', '- 確保完成 50% Active Ratio');
    for (const [n, note, tmp] of [['Hui Lin', 'Direct Approach, 50/50', 'warm'], ['Li Quan', '', 'cold'], ['', '', 'warm'], ['Joe Cheong', 'Follow Up', 'hot']]) {
      await page.click('#btnAddPro');
      const k = (await page.$$('#prosList .pro')).length;
      await page.fill(`#prosList .pro:nth-child(${k}) .pn`, n);
      await page.fill(`#prosList .pro:nth-child(${k}) .pnote`, note);
      await page.selectOption(`#prosList .pro:nth-child(${k}) .pt`, tmp);
    }
    const PROS_BLOCK = '10月份自我点评: 完成了20R\n- [ ] Joe Cheong (Follow Up) 🔥\n- [ ] Hui Lin (Direct Approach, 50/50) 🟠\n- [ ] Li Quan ❄️\n\n- 10月份啟動新夥伴中。';
    mentor = await page.textContent('#out');
    check(tag + 'prospects: hottest first, blank row skipped, activity split around the list', mentor.includes(PROS_BLOCK), mentor);
    const psum = await page.textContent('#prosSum');
    check(tag + 'prospect summary counts', psum.includes('3 prospects') && psum.includes('總跟進名單3位') && psum.includes('🔥 1 hot'), psum);
    await page.reload();
    await page.waitForFunction(() => !document.getElementById('month').disabled);
    check(tag + 'prospects survive reload', (await page.textContent('#out')).includes(PROS_BLOCK));
    await page.click('#prosList .pro:nth-child(3) .x');
    check(tag + 'remove a prospect row', (await page.$$('#prosList .pro')).length === 3);
    const carry = await page.evaluate(() => {
      S.data[10] = JSON.parse(JSON.stringify(S.data[9])); S.data[10].mIdx = 10; S.month = 10; renderAll();
      const d = curDetails(); const r = {n: d.prospects.length, review: d.review, comment: d.comment, plan: d.plan};
      d.prospects[0].temp = 'cold'; r.oct = S.details['2026-10'].prospects[0].temp;
      delete S.data[10]; delete S.details['2026-11']; S.month = 9; save(); renderAll(); return r;
    });
    check(tag + 'next month keeps prospects and plan, starts activity and comment blank, Oct untouched',
      carry.n === 3 && carry.review === '' && carry.comment === '' && carry.plan === '- 確保完成 50% Active Ratio' && carry.oct === 'warm', JSON.stringify(carry));
    const mig = await page.evaluate(() => {
      const d = S.details['2026-09']; d.prospects = undefined; delete d.prosV;
      d.review = '完成了19R。\n- [ ] Hui Lin (Direct Approach, 50/50)\n- [ ] Joe Cheong (Follow Up in Sep) **\n\n- 重新整理名單';
      migrateDetails(d); return {n: d.prospects.length, joe: d.prospects[1], rest: d.review};
    });
    check(tag + 'old pasted self-review is split into prospects and activity',
      mig.n === 2 && mig.joe.name === 'Joe Cheong' && mig.joe.note === 'Follow Up in Sep' && mig.joe.temp === 'hot' && mig.rest === '完成了19R。\n\n- 重新整理名單', JSON.stringify(mig));

    // Settings change flows into the message
    await page.click('#settingsBox summary');
    await page.fill('#s-dgA', '1000000');
    mentor = await page.textContent('#out');
    check(tag + 'goal change updates message', mentor.includes('Monthly Goal: 1,000K x 10% = 100,000.00') && mentor.includes('🎯DG: PFYLP 800K / ANP 1,000K'));

    // Graphic mode: preview, poster size, themes, share/download/copy, back to text
    await page.selectOption('#month', '9');
    await page.click('#tab-mentor');
    await page.click('[data-fmt="graphic"]');
    const ready = () => page.waitForFunction(() => document.getElementById('gfxImg').src.startsWith('data:image/png') && !document.getElementById('btnShare').disabled);
    await ready();
    check(tag + 'graphic mode hides the text box, shows the poster', await page.$eval('#out', e => e.hidden) && !(await page.$eval('#gfx', e => e.hidden)));
    const dims = await page.$eval('#gfxImg', i => [i.naturalWidth, i.naturalHeight]);
    check(tag + 'poster is 1080x1350', dims[0] === 1080 && dims[1] === 1350, dims.join('x'));
    const colours = await page.$eval('#gfxImg', i => { const c = document.createElement('canvas'); c.width = 1080; c.height = 1350; const x = c.getContext('2d'); x.drawImage(i, 0, 0); const s = new Set(); for (let k = 0; k < 4000; k++) { const d = x.getImageData((k * 97) % 1080, (k * 53) % 1350, 1, 1).data; s.add(d.join()); } return s.size; });
    check(tag + 'poster is not blank', colours > 30, String(colours));
    const shots = process.env.SHOT_DIR || '.';
    const grab = async name => fs.writeFileSync(path.join(shots, `poster-${name}.png`), Buffer.from((await page.$eval('#gfxImg', i => i.src)).split(',')[1], 'base64'));
    for (const theme of ['dark', 'gold', 'light']) {
      await page.selectOption('#style', theme); await ready();
      await page.click('#tab-mentor'); await ready(); await grab(`mentor-${theme}-${vp.width}`);
      await page.click('#tab-team'); await ready(); await grab(`team-${theme}-${vp.width}`);
    }
    await page.selectOption('#style', 'dark'); await ready();
    check(tag + 'team and mentor posters differ', true);
    const [dl] = await Promise.all([page.waitForEvent('download'), page.click('#btnDownload')]);
    check(tag + 'download file name', dl.suggestedFilename() === 'E3-team-2026-10.png', dl.suggestedFilename());
    await page.click('#btnShare');
    await page.waitForFunction(() => window.__shared);
    const sh = await page.evaluate(() => window.__shared);
    check(tag + 'share hands WhatsApp a PNG with a caption', sh.n === 1 && sh.type === 'image/png' && sh.size > 20000 && sh.text.includes('10月業績'), JSON.stringify(sh));
    await page.click('[data-fmt="text"]');
    check(tag + 'back to text mode', !(await page.$eval('#out', e => e.hidden)) && (await page.$eval('#gfx', e => e.hidden)) && (await page.textContent('#out')).includes('DreamHub E3'));
    await page.click('#tab-mentor');

    // Layout: no sideways scroll
    const sw = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    check(tag + 'no horizontal page scroll', sw <= 0, 'overflow ' + sw + 'px');
    await page.screenshot({ path: path.join(process.env.SHOT_DIR || '.', `monthly-report-${vp.width}.png`), fullPage: true });
    check(tag + 'no page errors', errors.length === 0, errors.join('; '));
    await page.close();
  }
  await browser.close();
  console.log(failed ? `\n${failed} check(s) failed` : '\nAll checks passed');
  process.exit(failed ? 1 : 0);
})();
