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

    // Draft comment follows the month, and never carries September wording into October
    check(tag + 'Sep team comment is empty until drafted', !team.includes('9月業績'));
    page.once('dialog', d => d.accept());
    await page.click('#btnDraft');
    const team2 = await page.textContent('#out');
    check(tag + 'Sep draft matches your wording', team2.includes('9月業績目前還沒有很大的突破，除了 Active Ratio 少於 50%，Average Case Size 低於 3千。 Q4 只要專注於團隊發展'), team2);
    await page.selectOption('#month', '9');
    await page.click('#btnDraft');
    const octTeam = await page.textContent('#out');
    check(tag + 'Oct draft says 10月 and Q4', octTeam.includes('10月業績目前') && octTeam.includes('Q4 只要') && !octTeam.includes('9月業績'), octTeam);
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

    // Settings change flows into the message
    await page.click('#settingsBox summary');
    await page.fill('#s-dgA', '1000000');
    mentor = await page.textContent('#out');
    check(tag + 'goal change updates message', mentor.includes('Monthly Goal: 1,000K x 10% = 100,000.00') && mentor.includes('🎯DG: PFYLP 800K / ANP 1,000K'));

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
