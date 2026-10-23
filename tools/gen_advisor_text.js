// One-off generator: turns the ADV object of the original Career Code app into data/advisor_text.json.
// Functions become templates by calling them with {0},{1},... placeholders.
// Usage: node tools/gen_advisor_text.js tests/fixtures/career-code-original.html private/data/advisor_text.json
const fs = require('fs');
const [src, out] = process.argv.slice(2);
const lines = fs.readFileSync(src, 'utf8').split('\n');
const start = lines.findIndex((l) => l.startsWith('const ADV = {'));
const end = lines.findIndex((l, i) => i > start && l.startsWith('const A_ ='));
const ADV = new Function(lines.slice(start, end).join('\n') + '\nreturn ADV;')();
const tpl = (v) => {
  if (typeof v === 'function') { const r = v(...Array.from({ length: v.length }, (_, i) => `{${i}}`)); return r; }
  return v;
};
const o = {};
for (const lang of ['en', 'zh']) { o[lang] = {}; for (const [k, v] of Object.entries(ADV[lang])) o[lang][k] = tpl(v); }
fs.writeFileSync(out, JSON.stringify(o, null, 1) + '\n');
console.log('keys en:', Object.keys(o.en).length, 'zh:', Object.keys(o.zh).length);
