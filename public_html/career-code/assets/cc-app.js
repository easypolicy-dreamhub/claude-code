/* Career Code - public page. Contains NO advisor logic: scores come back from the server. */
(function () {
'use strict';

var SITE = window.CC_SITE || {};
var API = SITE.apiBase || 'api/';
var STORE_KEY = 'e3cc.v1', MAX_AGE_MS = 24 * 3600 * 1000;
var params = new URLSearchParams(location.search);
var purpose = params.get('p') === 'internship' ? 'internship' : 'career';
var ref = (params.get('ref') || '').slice(0, 32);
var lang = params.get('lang') === 'zh' || (!params.get('lang') && /^zh/i.test(navigator.language || '')) ? 'zh' : 'en';

var PUB = {
  en: {
    titleCareer: 'Discover your Career Strengths',
    titleIntern: 'Apply for the Empowerment Internship',
    leadCareer: 'This 20-minute questionnaire maps your interests onto six work personality types and shows the work you are likely to enjoy and where your strengths lie. Your result is emailed to you, and a DreamHub E3 advisor will follow up.',
    leadIntern: 'Start your internship application by discovering your career strengths. This 20-minute questionnaire maps your interests onto six work personality types. Your result is emailed to you, and a DreamHub E3 advisor will follow up about the internship.',
    waPre: 'Prefer to chat first? WhatsApp us',
    resumeTitle: 'You have a test in progress', resumeText: 'Pick up where you left off. Your answers were saved on this device.',
    resumeGo: 'Continue my test', resumeNew: 'Start over',
    submitting: 'Calculating your results...', eNetwork: 'We could not reach the server. Check your connection and try again; your answers are saved.',
    eSpam: 'The spam check did not pass. Please wait a moment for it to finish, or refresh the page.', eSpamWait: 'Please wait for the spam check to finish, then continue.',
    eExpired: 'This test has expired. Please start again.', eLimit: 'Too many attempts from this connection. Please try again later.',
    advisor: function (n) { return n + ' from DreamHub E3 will contact you soon.'; },
    nextTitle: 'What happens next', nextText: 'We have emailed your result to the address you gave. Your advisor will be in touch to talk about your results.',
    waPost: 'WhatsApp your advisor', again: 'Start a new test', retry: 'Try again',
    disclaimer: 'This profile reflects interests, not ability or character. It is one input for a conversation about your career.',
    waMsg: { career: 'Hi DreamHub E3, I came across your website and would like to know more about a career with your team.', internship: 'Hi DreamHub E3, I came across your website and I’m interested in the Empowerment Internship Program.' },
    waAdv: 'Hi DreamHub E3, I just completed the Career Strengths assessment and would like to talk about my result.'
  },
  zh: {
    titleCareer: '发现你的职业优势',
    titleIntern: '申请赋能实习计划',
    leadCareer: '这份约20分钟的问卷会把你的兴趣对应到六种职业性格类型，显示你可能喜欢的工作以及你的优势所在。结果会发送到你的电邮，DreamHub E3 的顾问也会与你联系。',
    leadIntern: '先从发现你的职业优势开始你的实习申请。这份约20分钟的问卷会把你的兴趣对应到六种职业性格类型。结果会发送到你的电邮，DreamHub E3 的顾问会就实习事宜与你联系。',
    waPre: '想先聊聊？WhatsApp 联系我们',
    resumeTitle: '你有一份未完成的测试', resumeText: '从上次中断的地方继续。你的答案已保存在此设备上。',
    resumeGo: '继续我的测试', resumeNew: '重新开始',
    submitting: '正在计算你的结果...', eNetwork: '无法连接服务器。请检查网络后重试；你的答案已保存。',
    eSpam: '垃圾信息检查未通过。请稍等片刻让它完成，或刷新页面。', eSpamWait: '请等待垃圾信息检查完成后再继续。',
    eExpired: '此测试已过期，请重新开始。', eLimit: '此网络的尝试次数过多，请稍后再试。',
    advisor: function (n) { return 'DreamHub E3 的顾问 ' + n + ' 会尽快与你联系。'; },
    nextTitle: '接下来', nextText: '我们已将你的结果发送到你提供的电邮地址。你的顾问会与你联系，讨论你的结果。',
    waPost: 'WhatsApp 联系你的顾问', again: '开始新的测试', retry: '重试',
    disclaimer: '此报告反映的是兴趣，而非能力或品格，仅作为讨论你职业方向的参考。',
    waMsg: { career: '你好 DreamHub E3，我在网站上看到你们，想了解加入团队的职业机会。', internship: '你好 DreamHub E3，我在网站上看到你们，对赋能实习计划有兴趣。' },
    waAdv: '你好 DreamHub E3，我刚完成了职业优势测评，想和顾问谈谈我的结果。'
  }
};

var $ = function (s) { return document.querySelector(s); };
var L = function () { return STR[lang]; };
var P = function () { return PUB[lang]; };
var I_ = function (t) { return INFO[lang][t]; };
var COLOR = function (t) { return getComputedStyle(document.documentElement).getPropertyValue('--' + t).trim(); };
var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
var wa = function (msg) { return 'https://wa.me/' + (SITE.whatsapp || '60126718056') + '?text=' + encodeURIComponent(msg); };

/* ---------- state + saved progress (Fix 6) ---------- */
var state, current = 'welcome';
function freshState() {
  var ans = {}; ['A', 'B', 'C'].forEach(function (p) { ans[p] = {}; TYPES.forEach(function (t) { ans[p][t] = new Set(); }); });
  var rat = {}; TYPES.forEach(function (t) { rat[t] = [null, null]; });
  return { token: null, advisor: null, name: '', part: 0, answers: ans, ratings: rat, scores: null, code: null };
}
state = freshState();

function save() {
  if (!state.token || state.scores) return;
  try {
    var a = {}; ['A', 'B', 'C'].forEach(function (p) { a[p] = {}; TYPES.forEach(function (t) { a[p][t] = Array.from(state.answers[p][t]); }); });
    localStorage.setItem(STORE_KEY, JSON.stringify({ ts: Date.now(), lang: lang, purpose: purpose, ref: ref, token: state.token, advisor: state.advisor, name: state.name, part: state.part, a: a, r: state.ratings }));
  } catch (e) { /* private mode or storage full: the test still works, just without resume */ }
}
function clearSaved() { try { localStorage.removeItem(STORE_KEY); } catch (e) {} }
function loadSaved() {
  try {
    var s = JSON.parse(localStorage.getItem(STORE_KEY) || 'null');
    if (!s || !s.token || Date.now() - s.ts > MAX_AGE_MS) { clearSaved(); return null; }
    return s;
  } catch (e) { return null; }
}
function restore(s) {
  state = freshState();
  state.token = s.token; state.advisor = s.advisor; state.name = s.name || ''; state.part = Math.min(Math.max(+s.part || 0, 0), 3);
  ['A', 'B', 'C'].forEach(function (p) { TYPES.forEach(function (t) { ((s.a && s.a[p] && s.a[p][t]) || []).forEach(function (i) { state.answers[p][t].add(i); }); }); });
  TYPES.forEach(function (t) { if (s.r && Array.isArray(s.r[t])) state.ratings[t] = s.r[t].slice(0, 2); });
  if (s.lang === 'zh' || s.lang === 'en') lang = s.lang;
}

/* ---------- screens + language ---------- */
function show(id) {
  current = id;
  document.querySelectorAll('.screen').forEach(function (el) { el.hidden = el.id !== 's-' + id; });
  var map = { details: 's-details', results: 's-results' };
  $('#stepinfo').textContent = id === 'test' ? L().stepTest(state.part + 1) : (map[id] ? L().step[map[id]] : '');
  window.scrollTo(0, 0);
  if (id === 'details') mountTurnstile();
}
function fillSelects() {
  document.querySelectorAll('select[data-opts]').forEach(function (sel) {
    var keep = sel.value;
    sel.innerHTML = '<option value="">' + esc(L().select) + '</option>' + L().opts[sel.dataset.opts].map(function (o, i) { return '<option value="' + i + '">' + esc(o) + '</option>'; }).join('');
    sel.value = keep;
  });
}
function applyStatic() {
  document.documentElement.lang = lang === 'zh' ? 'zh-Hans' : 'en';
  document.title = P()[purpose === 'internship' ? 'titleIntern' : 'titleCareer'] + ' | DreamHub E3';
  document.querySelectorAll('[data-i18n]').forEach(function (el) { el.textContent = L()[el.dataset.i18n]; });
  $('#wTitle').textContent = P()[purpose === 'internship' ? 'titleIntern' : 'titleCareer'];
  $('#wLead').textContent = P()[purpose === 'internship' ? 'leadIntern' : 'leadCareer'];
  $('#langBtn').textContent = L().langBtn;
  $('#langBtn').setAttribute('aria-label', lang === 'en' ? '切换到中文' : 'Switch to English');
  $('#consentText').textContent = L().consent('DreamHub E3 (Dream Hub Ethree Solutions and Services)');
  $('#typestrip').innerHTML = TYPES.map(function (t) { return '<div style="border-color:' + COLOR(t) + '"><b>' + t + '</b> ' + esc(I_(t).name) + '</div>'; }).join('');
  $('#waPre').textContent = P().waPre; $('#waPre').href = wa(P().waMsg[purpose]);
  $('#resumeTitle').textContent = P().resumeTitle; $('#resumeText').textContent = P().resumeText;
  $('#resumeGo').textContent = P().resumeGo; $('#resumeNew').textContent = P().resumeNew;
  $('#againBtn').textContent = P().again;
  fillSelects();
  ['#d-err', '#t-err'].forEach(function (s) { $(s).textContent = ''; });
}
function setLang(l) {
  lang = l; applyStatic();
  if (current === 'test') renderPart();
  else if (current === 'results') renderResults();
  else show(current);
}
$('#langBtn').addEventListener('click', function () { setLang(lang === 'en' ? 'zh' : 'en'); });

/* ---------- hexagon ---------- */
var ANG = { R: -120, I: -60, A: 0, S: 60, E: 120, C: 180 };
function hexSVG(scores) {
  var c = 160, R = 108, max = 50;
  function pt(t, r) { var a = ANG[t] * Math.PI / 180; return [c + r * Math.cos(a), c + r * Math.sin(a)]; }
  function poly(r) { return TYPES.map(function (t) { return pt(t, r).map(function (n) { return n.toFixed(1); }).join(','); }).join(' '); }
  var g = '';
  [10, 20, 30, 40, 50].forEach(function (v) { g += '<polygon points="' + poly(R * v / max) + '" fill="none" stroke="#E2DDD8" stroke-width="' + (v === 50 ? 1.5 : 1) + '"/>'; });
  TYPES.forEach(function (t) { var p = pt(t, R); g += '<line x1="' + c + '" y1="' + c + '" x2="' + p[0] + '" y2="' + p[1] + '" stroke="#E2DDD8"/>'; });
  if (scores) {
    var v = function (t) { return R * Math.min(Math.max(scores[t].total || 0, 0), max) / max; };
    g += '<polygon points="' + TYPES.map(function (t) { return pt(t, v(t)).map(function (n) { return n.toFixed(1); }).join(','); }).join(' ') + '" fill="rgba(36,51,58,.16)" stroke="#24333A" stroke-width="2.5" stroke-linejoin="round"/>';
    TYPES.forEach(function (t) { var p = pt(t, v(t)); g += '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="4" fill="' + COLOR(t) + '"/>'; });
  }
  TYPES.forEach(function (t) {
    var p = pt(t, R + 30);
    g += '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="17" fill="' + COLOR(t) + '"/><text x="' + p[0] + '" y="' + (p[1] + 6) + '" text-anchor="middle" font-family="Poppins,sans-serif" font-weight="600" font-size="16" fill="#fff">' + t + '</text>';
    if (scores) { var dy = (t === 'R' || t === 'I') ? -24 : 28; g += '<text x="' + p[0] + '" y="' + (p[1] + dy) + '" text-anchor="middle" font-family="DM Sans,sans-serif" font-size="12" fill="#5C6870">' + scores[t].total + '</text>'; }
  });
  return '<svg viewBox="-10 -10 340 340" role="img" aria-label="' + esc(L().hexLabel) + '">' + g + '</svg>';
}

/* ---------- Turnstile (spam check) ---------- */
var tsToken = null, tsWidget = null, tsMounted = false;
function mountTurnstile() {
  if (tsMounted) return; tsMounted = true;
  if (SITE.turnstileSiteKey === 'TEST') { tsToken = 'good-token'; return; }   // test hook: the server still verifies the token
  var s = document.createElement('script');
  s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'; s.async = true;
  s.onload = function () {
    tsWidget = window.turnstile.render('#ts', { sitekey: SITE.turnstileSiteKey,
      callback: function (t) { tsToken = t; }, 'expired-callback': function () { tsToken = null; }, 'error-callback': function () { tsToken = null; } });
  };
  document.head.appendChild(s);
}
function resetTurnstile() { tsToken = SITE.turnstileSiteKey === 'TEST' ? 'good-token' : null; if (tsWidget !== null && window.turnstile) window.turnstile.reset(tsWidget); }

/* ---------- server calls ---------- */
function api(path, body) {
  return fetch(API + path, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify(body) })
    .catch(function () { var e = new Error(P().eNetwork); e.network = true; throw e; })
    .then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (data) {
        if (!r.ok) { var e = new Error(data.error || 'Request failed'); e.status = r.status; throw e; }
        return data;
      });
    });
}
function friendly(e) {
  if (e.network) return e.message;
  if (e.status === 403) return P().eSpam;
  if (e.status === 410) return P().eExpired;
  if (e.status === 429) return P().eLimit;
  return e.message;
}

/* ---------- details ---------- */
$('#startBtn').addEventListener('click', function () { show('details'); });
$('#d-back').addEventListener('click', function () { show('welcome'); });
$('#d-next').addEventListener('click', function () {
  var name = $('#d-name').value.trim(), mobile = $('#d-mobile').value.trim(), email = $('#d-email').value.trim(), errs = [];
  if (!name) errs.push(L().eName);
  if (!/^(?:\+?60|0)1\d[\d\s-]{7,12}$/.test(mobile)) errs.push(L().eMobile);
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) errs.push(L().eEmail);
  if (!$('#d-consent').checked) errs.push(L().eConsent);
  if (!errs.length && !tsToken) errs.push(P().eSpamWait);
  $('#d-err').textContent = errs.join(' ');
  if (errs.length) return;
  var btn = $('#d-next'); btn.disabled = true;
  api('start.php', { name: name, mobile: mobile, email: email, age: $('#d-age').value, edu: $('#d-edu').value, status: $('#d-status').value,
    field: $('#d-field').value.trim(), lang: lang, purpose: purpose, ref: ref, consent: true, turnstile: tsToken })
    .then(function (r) { state = freshState(); state.token = r.submit_token; state.advisor = r.advisor_name; state.name = name; save(); tsToken = null; renderPart(); })
    .catch(function (e) { $('#d-err').textContent = friendly(e); resetTurnstile(); })
    .then(function () { btn.disabled = false; });
});

/* ---------- test ---------- */
function usedBy(k, n, self) { return TYPES.some(function (t) { return t !== self && state.ratings[t][k] === n; }); }
function renderPart() {
  var key = PART_KEYS[state.part], p = L().parts[state.part];
  $('#t-title').textContent = p.title; $('#t-intro').textContent = p.intro;
  $('#t-prog').style.width = (state.part / 4 * 100) + '%'; $('#t-err').textContent = '';
  $('#t-back').textContent = L().previous; $('#t-back').style.visibility = state.part === 0 ? 'hidden' : 'visible';
  $('#t-next').textContent = state.part === 3 ? L().seeMine : L().next;
  var h = '';
  if (key !== 'D') {
    TYPES.forEach(function (t, gi) {
      h += '<div class="group"><h3>' + esc(L().set(gi + 1)) + '</h3><div class="items">';
      ITEMS[lang][key][t].forEach(function (txt, i) { h += '<button type="button" class="item" data-t="' + t + '" data-i="' + i + '" aria-pressed="' + state.answers[key][t].has(i) + '">' + esc(txt) + '</button>'; });
      h += '</div></div>';
    });
  } else {
    RATE_ORDER.forEach(function (o, idx) {
      var t = o[0], k = o[1];
      if (idx === 0 || idx === 6) h += '<h3 class="rateset">' + esc(L().rateSets[k]) + '</h3>';
      var v = state.ratings[t][k], lbl = RATINGS[lang][t][k];
      h += '<div class="rateblock"><div style="font-weight:500">' + esc(lbl) + '</div><div class="rate" role="group" aria-label="' + esc(lbl) + '">';
      for (var n = 1; n <= 7; n++) h += '<button type="button" data-t="' + t + '" data-k="' + k + '" data-v="' + n + '" aria-pressed="' + (v === n) + '"' + (usedBy(k, n, t) ? ' disabled' : '') + '>' + n + '</button>';
      h += '</div><div class="ratescale"><span>' + esc(L().low) + '</span><span>' + esc(L().avg) + '</span><span>' + esc(L().high) + '</span></div></div>';
    });
  }
  $('#t-body').innerHTML = h; updateCount(); show('test');
}
function updateCount() {
  var key = PART_KEYS[state.part];
  $('#t-count').textContent = key === 'D'
    ? L().rated(TYPES.reduce(function (n, t) { return n + state.ratings[t].filter(function (v) { return v !== null; }).length; }, 0))
    : L().selected(TYPES.reduce(function (s, t) { return s + state.answers[key][t].size; }, 0));
}
function refreshRates() {
  document.querySelectorAll('#t-body .rate button').forEach(function (x) {
    var t = x.dataset.t, k = +x.dataset.k, v = +x.dataset.v;
    x.setAttribute('aria-pressed', state.ratings[t][k] === v); x.disabled = usedBy(k, v, t);
  });
}
$('#t-body').addEventListener('click', function (e) {
  var b = e.target.closest('button'); if (!b) return;
  var key = PART_KEYS[state.part];
  if (key === 'D') {
    var t = b.dataset.t, k = +b.dataset.k, v = +b.dataset.v;
    if (usedBy(k, v, t)) { $('#t-err').textContent = L().eDup; return; }
    state.ratings[t][k] = v; $('#t-err').textContent = ''; refreshRates();
  } else {
    var set = state.answers[key][b.dataset.t], i = +b.dataset.i;
    if (set.has(i)) set.delete(i); else set.add(i);
    b.setAttribute('aria-pressed', set.has(i));
  }
  updateCount(); save();
});
$('#t-back').addEventListener('click', function () { if (state.part > 0) { state.part--; save(); renderPart(); } });
$('#t-next').addEventListener('click', function () {
  if (state.part < 3) { state.part++; save(); renderPart(); return; }
  if (TYPES.some(function (t) { return state.ratings[t].indexOf(null) >= 0; })) { $('#t-err').textContent = L().eRate; return; }
  if ([0, 1].some(function (k) { return new Set(TYPES.map(function (t) { return state.ratings[t][k]; })).size < 6; })) { $('#t-err').textContent = L().eDup; return; }
  submit();
});
function payload() {
  var a = {};
  ['A', 'B', 'C'].forEach(function (p) { a[p] = {}; TYPES.forEach(function (t) { a[p][t] = Array.from(state.answers[p][t]).sort(function (x, y) { return x - y; }); }); });
  a.D = {}; TYPES.forEach(function (t) { a.D[t] = state.ratings[t].slice(); });
  return a;
}
function submit() {
  var next = $('#t-next'); next.disabled = true; $('#t-err').textContent = P().submitting;
  api('submit.php', { submit_token: state.token, answers: payload() })
    .then(function (r) { state.scores = r.scores; state.code = r.code; state.advisor = r.advisor_name || state.advisor; clearSaved(); renderResults(); })
    .catch(function (e) { $('#t-err').textContent = friendly(e); })
    .then(function () { next.disabled = false; });
}

/* ---------- results ---------- */
function rank(s) { return TYPES.slice().sort(function (x, y) { return s[y].total - s[x].total || TYPES.indexOf(x) - TYPES.indexOf(y); }); }
function fmtDate() { return new Date().toLocaleDateString(L().dateLocale, { day: 'numeric', month: 'short', year: 'numeric' }); }
function renderResults() {
  var s = state.scores, r = rank(s), code = r.slice(0, 3), Lx = L(), sep = lang === 'zh' ? '，' : ', ';
  $('#printHead').innerHTML = '<h2>' + esc(Lx.phTitle(state.name)) + '</h2><div class="small muted">' + esc(fmtDate()) + '</div>';
  $('#r-for').textContent = state.name + sep + fmtDate();
  $('#r-code').innerHTML = code.map(function (t) { return '<span style="background:' + COLOR(t) + '" title="' + esc(I_(t).name) + '">' + t + '</span>'; }).join('');
  var sum = Lx.summary(I_(code[0]).name, I_(code[1]).name, I_(code[2]).name);
  if (s[r[2]].total === s[r[3]].total) sum += Lx.tie(r[2], r[3], code[0] + code[1] + r[3]);
  $('#r-summary').innerHTML = sum;
  $('#r-hex').innerHTML = hexSVG(s);
  $('#r-types').innerHTML = code.map(function (t) { return '<div class="typecard" style="border-color:' + COLOR(t) + '"><h3>' + t + ': ' + esc(I_(t).name) + ' <span class="muted" style="font-weight:400">(' + esc(I_(t).tag) + ')</span></h3><p>' + esc(I_(t).desc) + '</p></div>'; }).join('');
  var T = function (x) { return Math.max(s[x].total, 0); };
  var people = 2 * T('S') + T('E') + T('A'), things = 2 * T('R') + T('I') + T('C'), data = T('E') + T('C'), ideas = T('I') + T('A');
  var pp = people + things ? people / (people + things) : .5, dp = data + ideas ? data / (data + ideas) : .5;
  function lean(v, a, b) { return v >= .58 ? Lx.leanClear(a) : v >= .52 ? Lx.leanSlight(a) : v <= .42 ? Lx.leanClear(b) : v <= .48 ? Lx.leanSlight(b) : Lx.balanced; }
  $('#r-orient').innerHTML =
    '<div class="orient"><div class="ends"><span>' + esc(Lx.people) + '</span><span>' + esc(Lx.task) + '</span></div><div class="bar2"><i style="left:' + ((1 - pp) * 100).toFixed(0) + '%"></i></div><div class="small muted">' + esc(lean(pp, Lx.pPeople, Lx.pTask)) + '</div></div>' +
    '<div class="orient"><div class="ends"><span>' + esc(Lx.data) + '</span><span>' + esc(Lx.ideas) + '</span></div><div class="bar2"><i style="left:' + ((1 - dp) * 100).toFixed(0) + '%"></i></div><div class="small muted">' + esc(lean(dp, Lx.pData, Lx.pIdeas)) + '</div></div>';
  $('#r-strengths').innerHTML = Array.from(new Set(code.reduce(function (a, t) { return a.concat(I_(t).strengths.slice(0, 3)); }, []))).map(function (x) { return '<span>' + esc(x) + '</span>'; }).join('');
  $('#r-fields').innerHTML = Array.from(new Set(code.slice(0, 2).reduce(function (a, t) { return a.concat(I_(t).fields); }, []))).map(function (x) { return '<span>' + esc(x) + '</span>'; }).join('');
  $('#r-scores').innerHTML = '<thead><tr><th>' + esc(Lx.type) + '</th><th>A</th><th>B</th><th>C</th><th>D</th><th>' + esc(Lx.total) + '</th></tr></thead><tbody>' +
    r.map(function (t) { return '<tr><td><span class="tletter" style="background:' + COLOR(t) + '">' + t + '</span>' + esc(I_(t).name) + '</td><td>' + s[t].a + '</td><td>' + s[t].b + '</td><td>' + s[t].c + '</td><td>' + s[t].d + '</td><td><b>' + s[t].total + '</b></td></tr>'; }).join('') + '</tbody>';
  $('#r-disc').textContent = P().disclaimer;
  $('#r-next-title').textContent = P().nextTitle;
  $('#r-next-text').textContent = (state.advisor ? P().advisor(state.advisor) + ' ' : '') + P().nextText;
  $('#waPost').textContent = P().waPost; $('#waPost').href = wa(P().waAdv);
  var old = document.title; document.title = Lx.fileName(state.name || Lx.candidate); setTimeout(function () { document.title = old; }, 0);
  show('results');
}
$('#printBtn').addEventListener('click', function () { window.print(); });
$('#againBtn').addEventListener('click', function () {
  state = freshState(); clearSaved();
  document.querySelectorAll('#s-details input, #s-details select').forEach(function (el) { if (el.type === 'checkbox') el.checked = false; else el.value = ''; });
  $('#resumePanel').hidden = true; resetTurnstile(); show('welcome');
});

/* ---------- resume ---------- */
$('#resumeGo').addEventListener('click', function () { var s = loadSaved(); if (s) { restore(s); applyStatic(); renderPart(); } else { $('#resumePanel').hidden = true; } });
$('#resumeNew').addEventListener('click', function () { clearSaved(); state = freshState(); $('#resumePanel').hidden = true; });

$('#heroHex').innerHTML = hexSVG(null);
var saved = loadSaved();
if (saved && saved.lang) lang = saved.lang;
applyStatic();
$('#resumePanel').hidden = !saved;
show('welcome');
})();
