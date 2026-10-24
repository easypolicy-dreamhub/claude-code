/* Career Code candidates dashboard. Every call goes to /members/api/candidates.php, which checks the
   Cloudflare login AND the upline tree on the server. Hiding things here is only for convenience. */
(function () {
'use strict';

var API = '/members/api/candidates.php';
var $ = function (s) { return document.querySelector(s); };
var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
var STATUS = { 'new': 'New', contacted: 'Contacted', interviewed: 'Interviewed', joined: 'Joined', not_suitable: 'Not suitable' };
var COLOR = function (t) { return getComputedStyle(document.documentElement).getPropertyValue('--' + t).trim(); };
var st = { page: 1, total: 0, items: [], viewer: null, current: null, lang: 'en', timer: null };

function fmt(iso) {
  if (!iso) return '-';
  return new Date(iso).toLocaleString('en-MY', { timeZone: 'Asia/Kuala_Lumpur', day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}
function ago(iso) {
  var s = (Date.now() - new Date(iso).getTime()) / 1000;
  if (s < 3600) return Math.max(1, Math.round(s / 60)) + ' min ago';
  if (s < 86400) return Math.round(s / 3600) + ' h ago';
  if (s < 86400 * 14) return Math.round(s / 86400) + ' days ago';
  return fmt(iso).split(',')[0];
}
function toast(msg, bad) {
  var t = $('#toast'); t.textContent = msg; t.className = 'toast' + (bad ? ' bad' : ''); t.hidden = false;
  clearTimeout(toast.t); toast.t = setTimeout(function () { t.hidden = true; }, 3200);
}
function req(url, opts) {
  opts = opts || {};
  var headers = { 'Accept': 'application/json' };
  if (opts.method && opts.method !== 'GET') { headers['Content-Type'] = 'application/json'; headers['X-Requested-With'] = 'fetch'; }
  return fetch(url, { method: opts.method || 'GET', headers: headers, credentials: 'same-origin', body: opts.body ? JSON.stringify(opts.body) : undefined })
    .catch(function () { var e = new Error('Could not reach the server. Check your connection.'); e.network = true; throw e; })
    .then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok) { var e = new Error(d.error || 'Something went wrong (' + r.status + ').'); e.status = r.status; throw e; }
        return d;
      });
    });
}
function codeHtml(code, big) {
  if (!code) return '<div class="code none' + (big ? ' big' : '') + '" title="Not finished"><span>?</span><span>?</span><span>?</span></div>';
  return '<div class="code' + (big ? ' big' : '') + '">' + code.split('').map(function (t) { return '<span style="background:' + COLOR(t) + '">' + esc(t) + '</span>'; }).join('') + '</div>';
}

/* ---------- list ---------- */
function filters() {
  return { q: $('#q').value.trim(), status: $('#fStatus').value, purpose: $('#fPurpose').value, mine: $('#fScope').value === 'mine', unfinished: $('#fUnfinished').checked };
}
function listUrl(page) {
  var f = filters(), p = ['page=' + page];
  if (f.q) p.push('q=' + encodeURIComponent(f.q));
  if (f.status) p.push('status=' + f.status);
  if (f.purpose) p.push('purpose=' + f.purpose);
  if (f.mine) p.push('mine=1');
  if (f.unfinished) p.push('include_unfinished=1');
  return API + '?' + p.join('&');
}
function loadList(append) {
  var page = append ? st.page + 1 : 1;
  $('#msg').innerHTML = '';
  return req(listUrl(page)).then(function (d) {
    st.page = d.page; st.total = d.total; st.viewer = d.viewer;
    st.items = append ? st.items.concat(d.candidates) : d.candidates;
    setViewer(); renderList();
  }).catch(function (e) {
    $('#list').innerHTML = ''; $('#count').textContent = ''; $('#more').hidden = true;
    $('#msg').innerHTML = '<div class="error" role="alert">' + esc(e.message) + '</div>';
  });
}
function setViewer() {
  var v = st.viewer; if (!v) return;
  $('#who').textContent = 'Signed in as ' + v.name + (v.is_admin ? ' (admin)' : '');
  $('#intro').textContent = v.is_admin ? 'You can see every candidate from the public test and live sessions.' : 'You can see your own candidates and your team’s.';
  $('#export').hidden = !v.is_admin;
  $('#fScope').options[0].textContent = v.is_admin ? 'Everyone' : 'My team';
}
function renderList() {
  var v = st.viewer;
  $('#count').textContent = st.total ? 'Showing ' + st.items.length + ' of ' + st.total + ' candidate' + (st.total === 1 ? '' : 's') : '';
  if (!st.items.length) { $('#list').innerHTML = '<li class="empty card">No candidates match. Results appear here as soon as someone finishes the test.</li>'; $('#more').hidden = true; return; }
  $('#list').innerHTML = st.items.map(function (c) {
    var pills = '';
    if (c.verdict) pills += '<span class="pill ' + esc(c.verdict.key) + '">' + esc(c.verdict.title) + '</span>';
    pills += '<span class="pill">' + esc(STATUS[c.status] || c.status) + '</span>';
    pills += '<span class="pill' + (c.purpose === 'internship' ? ' intern' : '') + '">' + (c.purpose === 'internship' ? 'Internship' : 'Career') + '</span>';
    if (!c.completed) pills += '<span class="pill grey">Unfinished</span>';
    if (c.duplicate_of) pills += '<span class="pill warn">Repeat applicant</span>';
    if (c.via_link) pills += '<span class="pill warn">Via ' + esc(c.via_link.name) + '\u2019s link</span>';
    if (c.completed && !c.result_emailed) pills += '<span class="pill warn">Result email not sent</span>';
    var advisor = c.owner && (!v || c.owner.id !== v.id) ? ' · Advisor: ' + esc(c.owner.name) : '';
    return '<li><button class="item" data-id="' + c.id + '">' + codeHtml(c.code) + '<span><span class="name">' + esc(c.name) + '</span><br><span class="sub">' + esc(c.mobile) + advisor + '</span><span class="pills">' + pills + '</span></span><span class="when">' + esc(ago(c.completed_at || c.started_at)) + '</span></button></li>';
  }).join('');
  $('#more').hidden = st.items.length >= st.total;
}
$('#list').addEventListener('click', function (e) { var b = e.target.closest('.item'); if (b) openDetail(+b.dataset.id, true); });
$('#more').addEventListener('click', function () { loadList(true); });
['#fStatus', '#fPurpose', '#fScope', '#fUnfinished'].forEach(function (s) { $(s).addEventListener('change', function () { loadList(false); }); });
$('#q').addEventListener('input', function () { clearTimeout(st.timer); st.timer = setTimeout(function () { loadList(false); }, 300); });

/* ---------- detail ---------- */
function hexSVG(scores) {
  var ANG = { R: -120, I: -60, A: 0, S: 60, E: 120, C: 180 }, c = 160, R = 108, max = 50;
  function pt(t, r) { var a = ANG[t] * Math.PI / 180; return [c + r * Math.cos(a), c + r * Math.sin(a)]; }
  function poly(r) { return TYPES.map(function (t) { return pt(t, r).map(function (n) { return n.toFixed(1); }).join(','); }).join(' '); }
  var g = '';
  [10, 20, 30, 40, 50].forEach(function (v) { g += '<polygon points="' + poly(R * v / max) + '" fill="none" stroke="#E2DDD8"/>'; });
  g += '<polygon points="' + poly(R * 25 / max) + '" fill="none" stroke="#24333A" stroke-dasharray="4 4"/>';
  TYPES.forEach(function (t) { var p = pt(t, R); g += '<line x1="160" y1="160" x2="' + p[0] + '" y2="' + p[1] + '" stroke="#E2DDD8"/>'; });
  var v = function (t) { return R * Math.min(Math.max(scores[t].total || 0, 0), max) / max; };
  g += '<polygon points="' + TYPES.map(function (t) { return pt(t, v(t)).map(function (n) { return n.toFixed(1); }).join(','); }).join(' ') + '" fill="rgba(36,51,58,.16)" stroke="#24333A" stroke-width="2.5" stroke-linejoin="round"/>';
  TYPES.forEach(function (t) {
    var q = pt(t, v(t)), p = pt(t, R + 30), dy = (t === 'R' || t === 'I') ? -24 : 28;
    g += '<circle cx="' + q[0] + '" cy="' + q[1] + '" r="4" fill="' + COLOR(t) + '"/><circle cx="' + p[0] + '" cy="' + p[1] + '" r="17" fill="' + COLOR(t) + '"/><text x="' + p[0] + '" y="' + (p[1] + 6) + '" text-anchor="middle" font-weight="600" font-size="16" fill="#fff">' + t + '</text><text x="' + p[0] + '" y="' + (p[1] + dy) + '" text-anchor="middle" font-size="12" fill="#5C6870">' + scores[t].total + '</text>';
  });
  return '<svg viewBox="-10 -10 340 340" role="img" aria-label="Holland hexagon chart; dashed line marks 25 points">' + g + '</svg>';
}
function opt(list, i) { return i == null ? '-' : esc(list[i] || '-'); }

function advisorHtml(a, c) {
  var L = a.labels, rows = a.rows.map(function (r) {
    var label = r.status === 'met' ? L.met : r.status === 'notMet' ? L.notMet : L.notEntered;
    return '<tr class="' + (r.indent ? 'sub-row' : '') + '"><td>' + (r.indent ? '' : '<span class="tletter" style="background:' + COLOR(r.t) + '">' + esc(r.t) + '</span>') + esc(r.text) + '</td><td class="' + esc(r.status) + '">' + esc(label) + '</td></tr>';
  }).join('');
  var li = function (arr) { return arr.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join(''); };
  return '<div class="verdict ' + esc(a.verdict.key) + '"><h3>' + esc(a.verdict.title) + '</h3><p>' + esc(a.verdict.text) + '</p></div>' +
    '<h3>' + esc(L.criteria) + '</h3><table class="checks">' + rows + '</table>' +
    '<h3>' + esc(L.pattern) + '</h3><ul class="notes plist">' + a.pattern.map(function (p) { return '<li class="' + (p.warn ? 'pw' : 'pg') + '">' + esc(p.text) + '</li>'; }).join('') + '</ul>' +
    '<h3>' + esc(L.why) + '</h3><ul class="notes">' + li(a.why) + '</ul>' +
    (a.watch.length ? '<h3>' + esc(L.watch) + '</h3><ul class="notes">' + li(a.watch) + '</ul>' : '') +
    '<h3>' + esc(L.questions) + '</h3><ul class="notes">' + li(a.questions) + '</ul>' +
    '<p class="disclaimer">' + esc(L.disclaimer) + '</p>';
}
function renderDetail(c) {
  st.current = c;
  var chain = c.owner_chain.map(function (m) { return esc(m.name); }).join(' › ');
  var wa = 'https://wa.me/' + c.mobile.replace(/\D/g, '');
  var s = c.scores, d = c.details || {};
  var o = c.reassign_options.map(function (m) { return '<option value="' + m.id + '"' + (c.owner && m.id === c.owner.id ? ' selected' : '') + '>' + esc(m.name) + '</option>'; }).join('');
  if (c.owner && !c.reassign_options.some(function (m) { return m.id === c.owner.id; })) o = '<option value="' + c.owner.id + '" selected>' + esc(c.owner.name) + (c.owner.active ? '' : ' (inactive)') + '</option>' + o;
  var results = s ? '<section class="block"><h2>Results</h2><div class="res"><div><table class="scoretable"><thead><tr><th>Type</th><th>A</th><th>B</th><th>C</th><th>D</th><th>Total</th></tr></thead><tbody>' +
      TYPES.slice().sort(function (x, y) { return s[y].total - s[x].total || TYPES.indexOf(x) - TYPES.indexOf(y); }).map(function (t) {
        return '<tr><td><span class="tletter" style="background:' + COLOR(t) + '">' + t + '</span>' + esc(INFO.en[t].name) + '</td><td>' + (s[t].a == null ? '-' : s[t].a) + '</td><td>' + (s[t].b == null ? '-' : s[t].b) + '</td><td>' + (s[t].c == null ? '-' : s[t].c) + '</td><td>' + (s[t].d == null ? '-' : s[t].d) + '</td><td><b>' + s[t].total + '</b></td></tr>';
      }).join('') + '</tbody></table></div><div class="hexchart">' + hexSVG(s) + '</div></div></section>' :
    '<section class="block"><h2>Results</h2><p class="sub">This person started the test but has not finished it yet.</p></section>';
  var advisor = c.assessment ? '<section class="block advisor"><h2>' + esc(c.assessment.labels.title) +
      '<span class="seg no-print" role="group" aria-label="Notes language"><button type="button" data-lang="en" aria-pressed="' + (st.lang === 'en') + '">English</button><button type="button" data-lang="zh" aria-pressed="' + (st.lang === 'zh') + '">中文</button></span></h2><div id="advisorBody">' + advisorHtml(c.assessment, c) + '</div></section>' : '';
  var dup = c.duplicate ? '<p class="sub" style="margin-top:.6rem">⚠ This person took the test before' + (c.duplicate.started_at ? ' (' + esc(fmt(c.duplicate.started_at)) + (c.duplicate.code ? ', code ' + esc(c.duplicate.code) : '') + ')' : '') + '. ' + (c.duplicate.started_at ? '<a href="?candidate=' + c.duplicate.id + '" data-open="' + c.duplicate.id + '">Open the earlier record</a>' : 'The earlier record belongs to someone outside your team.') + '</p>' : '';
  $('#detail').innerHTML = '<div class="card">' +
    '<div class="no-print"><button class="btn plain" id="backBtn">← All candidates</button></div>' +
    '<div class="print-only"><h2>Career Code advisor report: ' + esc(c.name) + '</h2><p class="sub">' + esc(fmt(c.completed_at || c.started_at)) + ' · Advisor: ' + esc(c.owner ? c.owner.name : '-') + '</p></div>' +
    '<div class="detail-head" style="margin-top:1rem"><div><h2 style="font-size:1.5rem">' + esc(c.name) + '</h2><div class="pills">' +
      (c.purpose === 'internship' ? '<span class="pill intern">Internship</span>' : '<span class="pill">Career</span>') + (c.completed ? '' : '<span class="pill grey">Unfinished</span>') + '</div>' +
      '<div class="contact no-print"><a class="chip" href="tel:' + esc(c.mobile) + '">📞 ' + esc(c.mobile) + '</a><a class="chip" href="' + esc(wa) + '" target="_blank" rel="noopener">WhatsApp</a>' + (c.email ? '<a class="chip" href="mailto:' + esc(c.email) + '">' + esc(c.email) + '</a>' : '') + '</div></div>' +
      codeHtml(c.code, true) + '</div>' + dup +
    '<div class="grid2"><dl class="kv"><dt>Advisor</dt><dd>' + chain + '</dd><dt>Source</dt><dd>' + esc({ landing: 'Website', referral: 'Advisor’s link', live: 'Live session' }[c.source] || c.source) + '</dd>' +
      (c.via_link ? '<dt>Link used</dt><dd>' + esc(c.via_link.name) + '\u2019s link <span class="sub">(already registered, so stays with the first advisor; reassign below if needed)</span></dd>' : '') +
      '<dt>Started</dt><dd>' + esc(fmt(c.started_at)) + '</dd><dt>Finished</dt><dd>' + esc(fmt(c.completed_at)) + '</dd><dt class="print-only">Mobile</dt><dd class="print-only">' + esc(c.mobile) + '</dd><dt class="print-only">Email</dt><dd class="print-only">' + esc(c.email || '-') + '</dd>' +
      '<dt>Age group</dt><dd>' + opt(STR.en.opts.age, d.age) + '</dd><dt>Qualification</dt><dd>' + opt(STR.en.opts.edu, d.edu) + '</dd><dt>Status</dt><dd>' + opt(STR.en.opts.status, d.status) + '</dd><dt>Job / studies</dt><dd>' + esc(d.field || '-') + '</dd>' +
      '<dt>Consent</dt><dd>' + esc(fmt(c.consent.at)) + '</dd></dl>' +
    '<div class="no-print"><label class="f" for="dStatus">Follow-up status</label><select id="dStatus">' + c.pipeline.map(function (p) { return '<option value="' + p + '"' + (p === c.status ? ' selected' : '') + '>' + esc(STATUS[p]) + '</option>'; }).join('') + '</select>' +
      '<label class="f" for="dOwner" style="margin-top:.8rem">Advisor</label><select id="dOwner">' + o + '</select>' +
      '<p class="sub" style="margin-top:.6rem">Email: result ' + (c.result_emailed ? 'sent' : '<b>not sent</b>') + ' · advisors ' + (c.advisor_notified ? 'told' : '<b>not told</b>') + '</p></div></div>' +
    results + advisor +
    '<div class="actions no-print"><button class="btn" id="printBtn">Print advisor report</button>' + (st.viewer && st.viewer.is_admin ? '<button class="btn danger" id="delBtn">Delete candidate…</button>' : '') + '</div></div>';
  $('#listview').hidden = true; $('#detail').hidden = false; window.scrollTo(0, 0);
}
function openDetail(id, push) {
  return req(API + '?id=' + id + '&lang=' + st.lang).then(function (d) {
    if (push) history.pushState({ id: id }, '', '?candidate=' + id);
    renderDetail(d.candidate);
  }).catch(function (e) {
    toast(e.status === 404 ? 'That candidate is not available to you.' : e.message, true);
    if (!push) showList(false);
  });
}
function showList(push) {
  st.current = null; $('#detail').hidden = true; $('#listview').hidden = false;
  if (push) history.pushState({}, '', location.pathname);
  loadList(false);
}
$('#detail').addEventListener('click', function (e) {
  var t = e.target;
  if (t.id === 'backBtn') { showList(true); }
  else if (t.id === 'printBtn') { window.print(); }
  else if (t.id === 'delBtn') {
    var c = st.current;
    if (confirm('Permanently delete ' + c.name + ' and their results? This cannot be undone. Use it for a data-deletion request.')) {
      req(API + '?id=' + c.id, { method: 'DELETE' }).then(function () { toast('Candidate deleted.'); showList(true); }).catch(function (er) { toast(er.message, true); });
    }
  } else if (t.dataset && t.dataset.lang) {
    st.lang = t.dataset.lang;
    req(API + '?id=' + st.current.id + '&lang=' + st.lang).then(function (d) { st.current = d.candidate; renderDetail(d.candidate); });
  } else if (t.dataset && t.dataset.open) { e.preventDefault(); openDetail(+t.dataset.open, true); }
});
$('#detail').addEventListener('change', function (e) {
  var c = st.current, t = e.target;
  if (t.id === 'dStatus') {
    req(API + '?id=' + c.id + '&lang=' + st.lang, { method: 'PATCH', body: { pipeline_status: t.value } })
      .then(function (d) { st.current = d.candidate; toast('Status saved: ' + STATUS[t.value]); }).catch(function (er) { toast(er.message, true); t.value = c.status; });
  } else if (t.id === 'dOwner') {
    var name = t.options[t.selectedIndex].textContent;
    if (!confirm('Assign ' + c.name + ' to ' + name + '? ' + (st.viewer && st.viewer.is_admin ? '' : 'You will still see them only if ' + name + ' is you or in your team.'))) { t.value = c.owner.id; return; }
    req(API + '?id=' + c.id + '&lang=' + st.lang, { method: 'PATCH', body: { owner_member_id: +t.value } })
      .then(function (d) { renderDetail(d.candidate); toast('Assigned to ' + name); }).catch(function (er) { toast(er.message, true); t.value = c.owner.id; });
  }
});
window.addEventListener('popstate', function () {
  var id = +new URLSearchParams(location.search).get('candidate');
  if (id) openDetail(id, false); else showList(false);
});

var first = +new URLSearchParams(location.search).get('candidate');
if (first) { loadList(false).then(function () { openDetail(first, false); }); } else { loadList(false); }
})();
