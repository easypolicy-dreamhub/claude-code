/* DISC working-style questionnaire (candidate side). Contains NO scoring: it sends which word was picked and the server does the rest. */
(function () {
'use strict';
var API = '../api/disc.php';
var params = new URLSearchParams(location.search);
var TOKEN = (params.get('t') || '').toLowerCase();
var PER_PAGE = 7, PAGES = 4, TOTAL = 28;
var STORE = 'e3disc.' + TOKEN.slice(0, 16);
var $ = function (s) { return document.querySelector(s); };
var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
var lang = 'en', info = null, st = { page: 0, answers: [], last4: '', started: 0 };

var S = {
  en: {
    langBtn: '中文', loading: 'Loading...', step: function (n) { return 'Part ' + n + ' of ' + PAGES; },
    title: 'Working style questionnaire',
    lead: 'This short questionnaire shows how you like to work and communicate. It takes about 10 minutes and there are no right or wrong answers.',
    how: 'For each group of four words, choose the one that is MOST like you and the one that is LEAST like you. Go with your first instinct.',
    most: 'Most like me', least: 'Least like me', mostShort: 'Most', leastShort: 'Least',
    forName: function (n) { return 'This questionnaire is for ' + n + ' only.'; }, notYou: function (n) { return 'Not ' + n + '? Please close this page and ask your advisor for your own link.'; },
    digitsLabel: 'Last 4 digits of your mobile number', consent: 'I agree to DreamHub E3 using my answers to understand my working style, and sharing my result with my advisor and their upline leaders. If I upload a resume, I agree to DreamHub E3 keeping it for the same purpose and sharing it with the same people. I understand I may ask to access, correct or delete my data by emailing hello@dreamhub-e3.com (Personal Data Protection Act 2010).',
    resReq: 'Your resume (required)', resOpt: 'Your resume (optional)', resHint: 'PDF or Word, up to 5 MB.', resLater: ' You can also send it to your advisor later.', resHave: function (n) { return 'Uploaded: ' + n + '. Choose a file below to replace it.'; },
    eResume: 'Please upload your resume to continue.', eResBig: 'That file is too big. The limit is 5 MB.', eResType: 'Please choose a PDF or Word file (.pdf, .doc or .docx).', uploading: 'Uploading your resume...',
    start: 'Start', next: 'Next', back: 'Back', see: 'Result', answered: function (a, b) { return a + ' / ' + b; },
    eDigits: 'Enter the last 4 digits of your mobile number.', eConsent: 'Tick the consent box to continue.', eIncomplete: 'Please choose one "most" and one "least" in every group.',
    eNetwork: 'We could not reach the server. Check your connection and try again; your answers are saved.', submitting: 'Calculating your result...',
    rFor: function (n, d) { return n + ', ' + d; }, rHead: 'Your working style', rStyle: 'Your style', strengths: 'Your strengths', work: 'You do your best work when', tips: 'Tips to grow',
    mailed: 'We have also emailed this result to you.', disc: 'This result reflects working-style preferences, not ability or character. It is a starting point for a conversation with your advisor.',
    expiredT: 'This link has expired', expiredX: 'Please ask your advisor to send you a new link.', doneT: 'Already completed', doneX: 'This questionnaire has already been completed. Thank you.',
    lockedT: 'Too many wrong attempts', lockedX: 'Please ask your advisor to send you a new link.', badT: 'This link is not valid', badX: 'Please check the link in your email, or ask your advisor for a new one.'
  },
  zh: {
    langBtn: 'EN', loading: '加载中...', step: function (n) { return '第 ' + n + ' / ' + PAGES + ' 部分'; },
    title: '工作风格问卷',
    lead: '这份简短的问卷会显示你喜欢的工作和沟通方式。大约需要10分钟，没有对错之分。',
    how: '每一组有四个词，请选出最像你的一个和最不像你的一个。凭第一直觉作答即可。',
    most: '最像我', least: '最不像我', mostShort: '最像', leastShort: '最不像',
    forName: function (n) { return '这份问卷只限 ' + n + ' 本人填写。'; }, notYou: function (n) { return '你不是 ' + n + '？请关闭此页面，并向你的顾问索取你自己的链接。'; },
    digitsLabel: '你手机号码的最后4位数字', consent: '我同意 DreamHub E3 使用我的答案来了解我的工作风格，并将结果分享给我的顾问及其上线领导。如果我上传简历，我同意 DreamHub E3 为同一目的保存简历，并分享给上述人员。我明白我可以电邮至 hello@dreamhub-e3.com，要求查阅、更正或删除我的资料（《2010年个人资料保护法令》）。',
    resReq: '你的简历（必填）', resOpt: '你的简历（选填）', resHint: 'PDF 或 Word 文件，最大 5 MB。', resLater: '你也可以稍后把简历发给你的顾问。', resHave: function (n) { return '已上传：' + n + '。如需更换，请在下面重新选择文件。'; },
    eResume: '请先上传你的简历再继续。', eResBig: '文件太大，上限为 5 MB。', eResType: '请选择 PDF 或 Word 文件（.pdf、.doc 或 .docx）。', uploading: '正在上传你的简历...',
    start: '开始', next: '下一步', back: '上一步', see: '结果', answered: function (a, b) { return a + ' / ' + b; },
    eDigits: '请输入你手机号码的最后4位数字。', eConsent: '请勾选同意框以继续。', eIncomplete: '每一组都需要选出一个“最像”和一个“最不像”。',
    eNetwork: '无法连接服务器。请检查网络后重试；你的答案已保存。', submitting: '正在计算你的结果...',
    rFor: function (n, d) { return n + '，' + d; }, rHead: '你的工作风格', rStyle: '你的风格', strengths: '你的优势', work: '你在这样的环境中表现最好', tips: '成长小提示',
    mailed: '我们也已把这份结果发送到你的电邮。', disc: '此结果反映的是工作风格偏好，而非能力或品格，仅作为与顾问交流的参考。',
    expiredT: '此链接已过期', expiredX: '请向你的顾问索取新的链接。', doneT: '已经完成', doneX: '这份问卷已经完成，谢谢。',
    lockedT: '错误次数过多', lockedX: '请向你的顾问索取新的链接。', badT: '此链接无效', badX: '请检查电邮中的链接，或向你的顾问索取新的链接。'
  }
};
var L = function () { return S[lang]; };

function show(id) { document.querySelectorAll('.screen').forEach(function (el) { el.hidden = el.id !== 's-' + id; }); window.scrollTo(0, 0); $('#stepinfo').textContent = id === 'test' ? L().step(st.page + 1) : ''; }
function message(t, x) { $('#msgTitle').textContent = t; $('#msgText').textContent = x; show('msg'); }

function api(body) {
  return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify(Object.assign({ t: TOKEN }, body)) })
    .catch(function () { var e = new Error(L().eNetwork); e.network = true; throw e; })
    .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) { var e = new Error(d.error || 'Request failed'); e.status = r.status; throw e; } return d; }); });
}

/* ---------- saved progress ---------- */
function save() { try { localStorage.setItem(STORE, JSON.stringify({ ts: Date.now(), page: st.page, a: st.answers, d: st.last4, s: st.started, lang: lang })); } catch (e) {} }
function load() { try { var s = JSON.parse(localStorage.getItem(STORE) || 'null'); if (s && Date.now() - s.ts < 7 * 86400000) return s; } catch (e) {} return null; }
function clear() { try { localStorage.removeItem(STORE); } catch (e) {} }

/* ---------- screens ---------- */
function applyStatic() {
  document.documentElement.lang = lang === 'zh' ? 'zh-Hans' : 'en';
  document.title = L().title + ' | DreamHub E3';
  $('#langBtn').textContent = L().langBtn;
  if (!info) { $('#loadingText').textContent = L().loading; return; }
  $('#iTitle').textContent = L().title; $('#iLead').textContent = L().lead; $('#iHow').textContent = L().how;
  $('#legM').textContent = L().most; $('#legL').textContent = L().least;
  $('#iFor').textContent = L().forName(info.first_name); $('#iNotYou').textContent = L().notYou(info.first_name);
  var rs = info.resume || { required: false, have: false };
  $('#resumeLabel').textContent = rs.required ? L().resReq : L().resOpt; $('#resumeHint').textContent = L().resHint + (rs.required ? '' : L().resLater);
  $('#resumeHave').textContent = rs.have ? L().resHave(rs.name) : '';
  $('#digitsLabel').textContent = L().digitsLabel; $('#digitsBox').hidden = !info.needs_digits;
  $('#consentText').textContent = L().consent; $('#startBtn').textContent = L().start;
  $('#t-back').textContent = L().back;
}
$('#langBtn').addEventListener('click', function () {
  lang = lang === 'en' ? 'zh' : 'en'; applyStatic();
  if (!$('#s-test').hidden) renderPage(); else if (!$('#s-result').hidden && st.view) renderResult();
  else if (!$('#s-msg').hidden && st.msg) message(L()[st.msg + 'T'], L()[st.msg + 'X']);
});

function blank() { var a = []; for (var i = 0; i < TOTAL; i++) a.push({ most: null, least: null }); return a; }

$('#startBtn').addEventListener('click', function () {
  var digits = $('#digits').value.replace(/\D/g, '');
  if (info.needs_digits && digits.length !== 4) { $('#iErr').textContent = L().eDigits; return; }
  if (!$('#consent').checked) { $('#iErr').textContent = L().eConsent; return; }
  var file = $('#resumeFile').files[0] || null, rs = info.resume || { required: false, have: false };
  if (file && file.size > 5 * 1024 * 1024) { $('#iErr').textContent = L().eResBig; return; }
  if (file && !/\.(pdf|doc|docx)$/i.test(file.name)) { $('#iErr').textContent = L().eResType; return; }
  if (rs.required && !rs.have && !file) { $('#iErr').textContent = L().eResume; return; }
  $('#iErr').textContent = ''; var b = $('#startBtn'); b.disabled = true;
  api({ action: 'verify', last4: digits })
    .then(function () { return file ? uploadResume(digits, file) : null; })
    .then(function () { st.last4 = digits; st.page = 0; if (!st.started) st.started = Date.now(); if (!st.answers.length) st.answers = blank(); save(); renderPage(); })
    .catch(function (e) {
      if (e.status === 410 || e.status === 423) return gone(e.status === 423 ? 'locked' : (/already/i.test(e.message) ? 'done' : 'expired'));
      $('#iErr').textContent = e.message;
    }).then(function () { b.disabled = false; });
});

function uploadResume(digits, file) {
  $('#iErr').textContent = L().uploading;
  var fd = new FormData(); fd.append('t', TOKEN); fd.append('last4', digits); fd.append('file', file);
  return fetch('../api/resume.php', { method: 'POST', headers: { 'X-Requested-With': 'fetch' }, body: fd })
    .catch(function () { var e = new Error(L().eNetwork); e.network = true; throw e; })
    .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) { var e = new Error(d.error || 'Upload failed'); e.status = r.status; throw e; } info.resume = { required: info.resume.required, have: true, name: d.name }; $('#iErr').textContent = ''; $('#resumeFile').value = ''; return d; }); });
}
function renderPage() {
  var base = st.page * PER_PAGE, h = '';
  $('#t-title').textContent = L().title; $('#t-prog').style.width = (st.page / PAGES * 100) + '%';
  for (var q = base; q < base + PER_PAGE; q++) {
    h += '<div class="wq" data-q="' + q + '"><h3>' + (q + 1) + ' / ' + TOTAL + '</h3>';
    DISC_WORDS[q].forEach(function (w, p) {
      var a = st.answers[q];
      h += '<div class="wrow"><span class="w">' + esc(w[lang]) + '</span><span class="btns">' +
        '<button type="button" class="wb m" data-q="' + q + '" data-p="' + p + '" data-k="most" aria-pressed="' + (a.most === p) + '"' + (a.least === p ? ' disabled' : '') + '>' + esc(L().mostShort) + '</button>' +
        '<button type="button" class="wb l" data-q="' + q + '" data-p="' + p + '" data-k="least" aria-pressed="' + (a.least === p) + '"' + (a.most === p ? ' disabled' : '') + '>' + esc(L().leastShort) + '</button></span></div>';
    });
    h += '</div>';
  }
  $('#t-body').innerHTML = h; $('#t-err').textContent = '';
  $('#t-back').style.visibility = st.page === 0 ? 'hidden' : 'visible';
  $('#t-next').textContent = st.page === PAGES - 1 ? L().see : L().next;
  count(); show('test');
}
function count() { var n = st.answers.filter(function (a) { return a.most !== null && a.least !== null; }).length; $('#t-count').textContent = L().answered(n, TOTAL); }
$('#t-body').addEventListener('click', function (e) {
  var b = e.target.closest('.wb'); if (!b || b.disabled) return;
  var q = +b.dataset.q, p = +b.dataset.p, k = b.dataset.k, a = st.answers[q];
  a[k] = a[k] === p ? null : p;       // tapping again clears it
  save(); var y = window.scrollY; renderPage(); window.scrollTo(0, y);
});
function pageDone() { for (var q = st.page * PER_PAGE; q < st.page * PER_PAGE + PER_PAGE; q++) { if (st.answers[q].most === null || st.answers[q].least === null) return false; } return true; }
$('#t-back').addEventListener('click', function () { if (st.page > 0) { st.page--; save(); renderPage(); } });
$('#t-next').addEventListener('click', function () {
  if (!pageDone()) { $('#t-err').textContent = L().eIncomplete; return; }
  if (st.page < PAGES - 1) { st.page++; save(); renderPage(); return; }
  submit();
});

function gone(kind) { st.msg = kind; clear(); message(L()[kind + 'T'], L()[kind + 'X']); }
function submit() {
  var b = $('#t-next'); b.disabled = true; $('#t-err').textContent = L().submitting;
  var dur = st.started ? Math.round((Date.now() - st.started) / 1000) : null;
  api({ action: 'submit', last4: st.last4, consent: true, lang: lang, duration_sec: dur, answers: st.answers.map(function (a) { return { most: a.most, least: a.least }; }) })
    .then(function (r) { st.view = r.view; st.mailed = r.candidate_emailed; clear(); renderResult(); })
    .catch(function (e) {
      if (e.status === 410) return gone(/already/i.test(e.message) ? 'done' : 'expired');
      if (e.status === 423) return gone('locked');
      if (/resume/i.test(e.message)) { show('intro'); $('#iErr').textContent = e.message; return; }   // the resume was removed meanwhile: back to the first screen to upload it
      $('#t-err').textContent = e.message;
    }).then(function () { b.disabled = false; });
}

function renderResult() {
  var v = st.view, d = new Date().toLocaleDateString(lang === 'zh' ? 'zh-CN' : 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  $('#r-for').textContent = L().rFor(info.first_name, d); $('#r-head').textContent = L().rHead; $('#r-k').textContent = L().rStyle;
  $('#r-style').textContent = v.label; $('#r-tag').textContent = v.tag; $('#r-desc').textContent = v.desc;
  $('#r-strH').textContent = L().strengths; $('#r-str').innerHTML = v.strengths.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('');
  $('#r-workH').textContent = L().work; $('#r-work').textContent = v.work;
  $('#r-tipsH').textContent = L().tips; $('#r-tips').innerHTML = v.tips.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('');
  $('#r-mail').textContent = st.mailed ? L().mailed : ''; $('#r-disc').textContent = L().disc;
  show('result');
}
/* the result text came back in the language chosen at submit time; switching language after that only changes the labels */

/* ---------- start ---------- */
if (!/^[a-f0-9]{64}$/.test(TOKEN)) { applyStatic(); st.msg = 'bad'; message(L().badT, L().badX); }
else {
  var saved = load();
  if (saved) { st.page = Math.min(Math.max(+saved.page || 0, 0), PAGES - 1); st.answers = saved.a && saved.a.length === TOTAL ? saved.a : []; st.last4 = saved.d || ''; st.started = saved.s || 0; if (saved.lang === 'zh' || saved.lang === 'en') lang = saved.lang; }
  applyStatic();
  api({ action: 'info' }).then(function (i) {
    info = i; if (!saved && (i.lang === 'zh' || i.lang === 'en')) lang = i.lang; applyStatic();
    if (i.state !== 'ok') return gone(i.state);
    if (saved && st.answers.length && (st.last4 || !i.needs_digits)) { renderPage(); } else { show('intro'); }
  }).catch(function (e) { st.msg = 'bad'; if (e.network) { $('#loadingText').textContent = e.message; } else { message(L().badT, L().badX); } });
}
})();
