const { evaluate: ev, format } = require('./calc-engine.js');
const cases = [
  ['2+3*4', 14], ['(2+3)*4', 20], ['2^3^2', 512], ['-2^2', -4], ['sin(30)', 0.5], ['cos(60)', 0.5], ['tan(45)', 1],
  ['sqrt(16)', 4], ['√9', 3], ['5!', 120], ['ln(e)', 1], ['log(1000)', 3], ['2pi', 2 * Math.PI], ['2(3+4)', 14],
  ['10%3', 1], ['asin(1)', 90], ['sin(pi/6)', 0.5, 'rad'], ['1/4', 0.25], ['3×4÷2', 6], ['sqrt 16', 4],
];
let bad = 0;
for (const [e, want, angle] of cases) {
  const got = ev(e, { angle: angle || 'deg' });
  const ok = Math.abs(got - want) < 1e-9 || (angle === 'rad' && Math.abs(got - want) < 1e-9);
  if (!ok) { bad++; console.log('FAIL', e, got, want); }
}
for (const e of ['2+', '(1', 'foo(2)', '1 $ 2']) { try { ev(e); bad++; console.log('FAIL no error', e); } catch (_) {} }
console.log(format(1/3), format(1e15), format(0.1+0.2), format(1/0));
console.log(bad ? bad + ' failed' : 'all passed'); process.exit(bad ? 1 : 0);
