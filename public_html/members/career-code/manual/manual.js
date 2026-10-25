/* Paper-score entry. Totals are re-checked and the code recomputed on the server; this page only helps typing.
   A save that cannot reach the server is kept on this device (with its own id, so sending it twice is harmless) and retried. */
(function () {
'use strict';
var TYPES = ['R', 'I', 'A', 'S', 'E', 'C'];
var NAMES = { R: 'Realistic', I: 'Investigative', A: 'Artistic', S: 'Social', E: 'Enterprising', C: 'Conventional' };
var COLORS = { R: '#B8642A', I: '#3567A8', A: '#8A4FA0', S: '#2E9466', E: '#C8961A', C: '#5E6E80' };
var MAX = { a: 11, b: 11, c: 14, d: 14 }, MIN = { a: 0, b: 0, c: 0, d: 2 };
var QKEY = 'e3cc.manualQueue';
var $ = function (s) { return document.querySelector(s); };
var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
var clientId = null, confirmD = false;

function newId() { var b = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(b); return Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join(''); }

$('#sc').innerHTML = '<thead><tr><th>Type</th><th>A</th><th>B</th><th>C</th><th>D</th><th>Total</th></tr></thead><tbody>' + TYPES.map(function (t) {
  return '<tr><td><span class="tl" style="background:' + COLORS[t] + '">' + t + '</span>' + NAMES[t] + '</td>' +
    ['a', 'b', 'c', 'd'].map(function (k) { return '<td><input type="number" inputmode="numeric" min="' + MIN[k] + '" max="' + MAX[k] + '" data-t="' + t + '" data-k="' + k + '" aria-label="' + NAMES[t] + ' part ' + k.toUpperCase() + '"></td>'; }).join('') +
    '<td><b id="tot-' + t + '">–</b></td></tr>';
}).join('') + '</tbody>';

function read() {
  var s = {}, missing = false;
  TYPES.forEach(function (t) {
    s[t] = {};
    ['a', 'b', 'c', 'd'].forEach(function (k) {
      var v = document.querySelector('input[data-t="' + t + '"][data-k="' + k + '"]').value.trim();
      if (v === '') missing = true;
      s[t][k] = v === '' ? null : Number(v);
    });
  });
  return { scores: s, missing: missing };
}
function totals(s) {
  var o = {}; TYPES.forEach(function (t) { var r = s[t]; o[t] = ['a', 'b', 'c', 'd'].some(function (k) { return r[k] === null || isNaN(r[k]); }) ? null : r.a + r.b + r.c + r.d; });
  return o;
}
function refresh() {
  var r = read(), tt = totals(r.scores);
  TYPES.forEach(function (t) { $('#tot-' + t).textContent = tt[t] === null ? '–' : tt[t]; });
  var done = TYPES.every(function (t) { return tt[t] !== null; });
  if (!done) { $('#preview').innerHTML = ''; return; }
  var order = TYPES.slice().sort(function (x, y) { return tt[y] - tt[x] || TYPES.indexOf(x) - TYPES.indexOf(y); });
  var dSum = TYPES.reduce(function (n, t) { return n + r.scores[t].d; }, 0);
  $('#preview').innerHTML = '<div class="small muted">Code from these scores</div><div class="code">' + order.slice(0, 3).map(function (t) { return '<span style="background:' + COLORS[t] + '">' + t + '</span>'; }).join('') + '</div>' +
    (dSum < 42 || dSum > 54 ? '<div class="small" style="color:var(--warn)">Part D adds up to ' + dSum + '; it should be 42 to 54. Check the paper.</div>' : '');
}
$('#sc').addEventListener('input', function () { confirmD = false; $('#confirmD').hidden = true; refresh(); });

/* ---- saving + the offline queue ---- */
function qLoad() { try { return JSON.parse(localStorage.getItem(QKEY) || '[]') || []; } catch (e) { return []; } }
function qSave(a) { try { localStorage.setItem(QKEY, JSON.stringify(a)); } catch (e) {} }
function post(body) {
  return fetch('/members/api/live.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify(body) })
    .catch(function () { var e = new Error('offline'); e.network = true; throw e; })
    .then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok) { var e = new Error(d.error || 'Could not save.'); e.status = r.status; e.data = d; throw e; }
        return d;
      });
    });
}
function showQueue() {
  var q = qLoad(), n = $('#queueNote');
  n.hidden = !q.length;
  if (q.length) n.innerHTML = q.length + ' saved on this device, waiting for a connection: ' + q.map(function (x) { return esc(x.name); }).join(', ') + '. <button class="btn plain" type="button" id="retryQ" style="min-height:38px;margin-left:.5rem">Send now</button>';
}
function flush() {
  var q = qLoad(); if (!q.length) return Promise.resolve();
  return q.reduce(function (p, item) {
    return p.then(function () {
      return post(Object.assign({ action: 'manual', confirm_d: true }, item)).then(function () { qSave(qLoad().filter(function (x) { return x.client_id !== item.client_id; })); },
        function (e) { if (e.network) throw e; qSave(qLoad().filter(function (x) { return x.client_id !== item.client_id; })); });   // a refused entry cannot be fixed later; drop it so it does not block the rest
    });
  }, Promise.resolve()).catch(function () {}).then(showQueue);
}
document.addEventListener('click', function (e) { if (e.target.id === 'retryQ') flush(); });
window.addEventListener('online', flush);

function reset() {
  $('#f').reset(); clientId = null; confirmD = false; $('#confirmD').hidden = true; $('#err').textContent = ''; refresh();
}
function collect() {
  var r = read();
  return { name: $('#name').value.trim(), mobile: $('#mobile').value.trim(), email: $('#email').value.trim(), purpose: $('#purpose').value, lang: $('#lang').value, consent: $('#consent').checked, scores: r.scores, missing: r.missing };
}
function validate(c) {
  var errs = [];
  if (!c.name) errs.push('Enter the name.');
  if (!/^(?:\+?60|0)1\d[\d\s-]{7,12}$/.test(c.mobile)) errs.push('Enter a valid Malaysian mobile number, e.g. 012-345 6789.');
  if (c.email && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(c.email)) errs.push('That email address does not look right.');
  if (c.missing) errs.push('Fill in every score (use 0 where nothing was ticked).');
  else TYPES.forEach(function (t) { ['a', 'b', 'c', 'd'].forEach(function (k) {
    var v = c.scores[t][k]; if (!Number.isInteger(v) || v < MIN[k] || v > MAX[k]) errs.push(NAMES[t] + ' part ' + k.toUpperCase() + ' must be ' + MIN[k] + ' to ' + MAX[k] + '.');
  }); });
  if (!c.consent) errs.push('Tick the consent box once the candidate has agreed.');
  return errs.slice(0, 3);
}
function finished(r, c) {
  var code = r.code.split('').map(function (t) { return '<span style="background:' + COLORS[t] + '">' + t + '</span>'; }).join('');
  $('#f').hidden = true; $('#done').hidden = false;
  $('#done').innerHTML = '<h2>Saved</h2><div class="code">' + code + '</div><p>' + esc(c.name) + (r.already ? ' was already saved.' : ' is now in your candidate list.') +
    (c.email ? (r.candidate_emailed ? ' Their result was emailed.' : ' The email to them could not be sent.') : '') + '</p>' +
    '<div style="display:flex;gap:.7rem;flex-wrap:wrap"><a class="btn" href="/members/career-code/dashboard/?candidate=' + r.candidate_id + '">Open advisor view</a><button class="btn plain" type="button" id="another">Enter another</button></div>';
}
$('#done').addEventListener('click', function (e) { if (e.target.id === 'another') { $('#done').hidden = true; $('#f').hidden = false; reset(); window.scrollTo(0, 0); } });

function save(force) {
  var c = collect(), errs = validate(c);
  $('#err').textContent = errs.join(' ');
  if (errs.length) return;
  if (!clientId) clientId = newId();
  var body = { client_id: clientId, name: c.name, mobile: c.mobile, email: c.email, purpose: c.purpose, lang: c.lang, consent: true, scores: c.scores };
  if (force || confirmD) body.confirm_d = true;
  var btn = $('#save'); btn.disabled = true;
  post(Object.assign({ action: 'manual' }, body))
    .then(function (r) { finished(r, c); reset(); })
    .catch(function (e) {
      if (e.network) {
        var q = qLoad().filter(function (x) { return x.client_id !== clientId; }); q.push(Object.assign({ name: c.name }, body, { confirm_d: true })); qSave(q);
        finished({ code: ' ', candidate_id: '', candidate_emailed: false }, c);
        $('#done').innerHTML = '<h2>Saved on this device</h2><p>There is no connection right now. ' + esc(c.name) + ' will be sent to your list automatically as soon as you are back online. Keep this page or the Career Code page open on this device until then.</p><div style="display:flex;gap:.7rem;flex-wrap:wrap"><button class="btn plain" type="button" id="another">Enter another</button></div>';
        reset(); showQueue(); return;
      }
      if (e.data && e.data.needs_confirm === 'd_sum') { $('#confirmText').textContent = e.message; $('#confirmD').hidden = false; $('#err').textContent = ''; return; }
      $('#err').textContent = e.message;
    })
    .then(function () { btn.disabled = false; });
}
$('#f').addEventListener('submit', function (e) { e.preventDefault(); save(false); });
$('#confirmBtn').addEventListener('click', function () { confirmD = true; $('#confirmD').hidden = true; save(true); });

showQueue(); flush(); refresh();
})();
