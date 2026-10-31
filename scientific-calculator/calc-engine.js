// Safe expression evaluator (no eval). Recursive descent parser.
(function (root) {
  function fact(n) {
    if (n < 0 || !Number.isFinite(n)) return NaN;
    if (Math.floor(n) !== n) return gamma(n + 1);
    if (n > 170) return Infinity;
    let r = 1; for (let i = 2; i <= n; i++) r *= i; return r;
  }
  function gamma(z) { // Lanczos
    const g = 7, c = [0.99999999999980993, 676.5203681218851, -1259.1392167224028, 771.32342877765313,
      -176.61502916214059, 12.507343278686905, -0.13857109526572012, 9.9843695780195716e-6, 1.5056327351493116e-7];
    if (z < 0.5) return Math.PI / (Math.sin(Math.PI * z) * gamma(1 - z));
    z -= 1; let x = c[0]; const t = z + g + 0.5;
    for (let i = 1; i < g + 2; i++) x += c[i] / (z + i);
    return Math.sqrt(2 * Math.PI) * Math.pow(t, z + 0.5) * Math.exp(-t) * x;
  }
  function evaluate(src, opts) {
    opts = opts || {};
    const deg = opts.angle !== 'rad';
    const ans = opts.ans || 0;
    const toR = x => deg ? x * Math.PI / 180 : x;
    const fromR = x => deg ? x * 180 / Math.PI : x;
    const clean = v => { const r = Math.round(v); return Math.abs(v - r) < 1e-12 * Math.max(1, Math.abs(v)) ? r : v; };
    const funcs = {
      sin: x => clean(Math.sin(toR(x))), cos: x => clean(Math.cos(toR(x))),
      tan: x => { const c = Math.cos(toR(x)); if (Math.abs(c) < 1e-15) return NaN; return clean(Math.sin(toR(x)) / c); },
      asin: x => fromR(Math.asin(x)), acos: x => fromR(Math.acos(x)), atan: x => fromR(Math.atan(x)),
      sinh: Math.sinh, cosh: Math.cosh, tanh: Math.tanh, asinh: Math.asinh, acosh: Math.acosh, atanh: Math.atanh,
      ln: Math.log, log: Math.log10, log2: Math.log2, exp: Math.exp,
      sqrt: Math.sqrt, cbrt: Math.cbrt, abs: Math.abs, floor: Math.floor, ceil: Math.ceil, round: Math.round,
      fact: fact, sqr: x => x * x,
    };
    const consts = { pi: Math.PI, e: Math.E, ans: ans, phi: (1 + Math.sqrt(5)) / 2 };
    const s = src.replace(/π/g, ' pi ').replace(/√/g, ' sqrt ').replace(/×/g, '*').replace(/÷/g, '/').replace(/−/g, '-');
    const tok = [];
    const re = /\s*(?:(\d+\.?\d*(?:[eE][+-]?\d+)?|\.\d+(?:[eE][+-]?\d+)?)|([A-Za-z_][A-Za-z0-9_]*)|(.))/y;
    let m; re.lastIndex = 0;
    while (re.lastIndex < s.length && (m = re.exec(s))) {
      if (m[1] !== undefined) tok.push({ t: 'n', v: parseFloat(m[1]) });
      else if (m[2] !== undefined) tok.push({ t: 'id', v: m[2].toLowerCase() });
      else if (m[3] !== undefined && m[3].trim()) tok.push({ t: 'op', v: m[3] });
      else if (m[3] === undefined) break;
    }
    let p = 0;
    const peek = () => tok[p], isOp = v => tok[p] && tok[p].t === 'op' && tok[p].v === v;
    const fail = msg => { throw new Error(msg); };
    function expr() {
      let v = term();
      while (isOp('+') || isOp('-')) { const o = tok[p++].v; const r = term(); v = o === '+' ? v + r : v - r; }
      return v;
    }
    function term() {
      let v = unary();
      for (;;) {
        if (isOp('*') || isOp('/') || isOp('%')) {
          const o = tok[p++].v; const r = unary();
          v = o === '*' ? v * r : o === '/' ? v / r : v % r;
        } else if (peek() && (peek().t === 'n' || peek().t === 'id' || isOp('('))) { v *= power(); } // implicit multiply
        else return v;
      }
    }
    function unary() {
      if (isOp('-')) { p++; return -unary(); }
      if (isOp('+')) { p++; return unary(); }
      return power();
    }
    function power() {
      let b = postfix();
      if (isOp('^')) { p++; const e = unary(); return Math.pow(b, e); } // right assoc
      return b;
    }
    function postfix() {
      let v = primary();
      while (isOp('!') || isOp('%') && false) { p++; v = fact(v); }
      return v;
    }
    function primary() {
      const t = tok[p++];
      if (!t) fail('Unexpected end');
      if (t.t === 'n') return t.v;
      if (t.t === 'id') {
        if (funcs[t.v]) {
          let a;
          if (isOp('(')) { p++; a = expr(); if (!isOp(')')) fail('Missing )'); p++; }
          else a = power();
          return funcs[t.v](a);
        }
        if (t.v in consts) return consts[t.v];
        fail('Unknown: ' + t.v);
      }
      if (t.v === '(') { const v = expr(); if (isOp(')')) p++; else fail('Missing )'); return v; }
      fail('Unexpected ' + t.v);
    }
    if (!tok.length) return 0;
    const v = expr();
    if (p < tok.length) fail('Unexpected ' + tok[p].v);
    return v;
  }
  function format(v) {
    if (Number.isNaN(v)) return 'Error';
    if (!Number.isFinite(v)) return v > 0 ? '∞' : '-∞';
    if (v !== 0 && (Math.abs(v) >= 1e12 || Math.abs(v) < 1e-9)) return v.toExponential(9).replace(/\.?0+e/, 'e').replace('e+', 'e');
    return String(parseFloat(v.toPrecision(12)));
  }
  const api = { evaluate, format };
  if (typeof module !== 'undefined') module.exports = api; else root.CalcEngine = api;
})(this);
