// Runs the ORIGINAL assess() from the legacy app on seeded random scores and prints cases as JSON.
// Usage: node tests/tools/assess_js.js <original.html> <count>
const fs = require('fs');
const [file, countArg] = process.argv.slice(2);
const L = fs.readFileSync(file, 'utf8').split('\n');
const slice = (from, to) => L.slice(from - 1, to).join('\n');
// CONFIG/TYPES/lang (298-306), INFO + I_ (407-424), ADV + A_ (489-574), rank (798), assess (835-893)
const code = [slice(298, 307), slice(407, 424), slice(489, 574), slice(798, 798), slice(835, 893)].join('\n');
const run = new Function('langRef', code.replace('let lang = CONFIG.defaultLang;', 'let lang = "en";') + '\nreturn {assess, setLang:(l)=>{lang=l;}};');
const eng = run();
let seed = 12345; const rnd = () => (seed = (seed * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff;
const ri = (a, b) => a + Math.floor(rnd() * (b - a + 1));
const T = ['R', 'I', 'A', 'S', 'E', 'C'];
const cases = [];
for (let i = 0; i < +countArg; i++) {
  const mode = i % 5;      // 0-2 full parts, 3 totals only, 4 skewed high/low profiles
  const s = {};
  T.forEach((t) => {
    let a, b, c, d;
    if (mode === 4) { const hi = rnd() < 0.5; a = hi ? ri(6, 11) : ri(0, 3); b = hi ? ri(6, 11) : ri(0, 3); c = hi ? ri(7, 14) : ri(0, 4); d = hi ? ri(9, 14) : ri(2, 6); }
    else { a = ri(0, 11); b = ri(0, 11); c = ri(0, 14); d = ri(2, 14); }
    if (mode === 3) s[t] = { a: null, b: null, c: null, d: null, abc: null, total: ri(0, 50) };
    else s[t] = { a, b, c, d, abc: a + b + c, total: a + b + c + d };
  });
  const lang = i % 2 ? 'zh' : 'en';
  eng.setLang(lang);
  const r = eng.assess(s);
  cases.push({ scores: s, lang, expected: { v: r.v, checks: r.checks, why: r.why, watch: r.watch, qs: r.qs, pattern: r.pattern } });
}
console.log(JSON.stringify(cases));
