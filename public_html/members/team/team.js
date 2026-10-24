/* Team page. The server (/members/api/team.php) checks every action; this page only offers what the
   viewer is allowed to do and previews the effect before anything changes. */
(function () {
'use strict';

var API = '/members/api/team.php';
var $ = function (s, r) { return (r || document).querySelector(s); };
var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
var st = { data: null, byId: {}, kids: {} };
var dlg = $('#dlg'), body = $('#dlgBody');

function toast(msg, bad) {
  var t = $('#toast'); t.textContent = msg; t.className = 'toast' + (bad ? ' bad' : ''); t.hidden = false;
  clearTimeout(toast.t); toast.t = setTimeout(function () { t.hidden = true; }, 3800);
}
function req(method, url, data) {
  var h = { 'Accept': 'application/json' };
  if (method !== 'GET') { h['Content-Type'] = 'application/json'; h['X-Requested-With'] = 'fetch'; }
  return fetch(url, { method: method, headers: h, credentials: 'same-origin', body: data ? JSON.stringify(data) : undefined })
    .catch(function () { throw new Error('Could not reach the server. Check your connection.'); })
    .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) { var e = new Error(d.error || 'Something went wrong (' + r.status + ').'); e.status = r.status; throw e; } return d; }); });
}
function act(payload) {
  return req('POST', API + ($('#showInactive').checked ? '?inactive=1' : ''), payload).then(function (d) { if (d.tree) setData(d.tree); return d.result; });
}
function rankName(r) { return (st.data && st.data.ranks[r]) || r; }
function V() { return st.data.viewer; }

/* ---------- tree ---------- */
function setData(d) {
  st.data = d; st.byId = {}; st.kids = {};
  d.members.forEach(function (m) { st.byId[m.id] = m; (st.kids[m.parent === null ? 'root' : m.parent] = st.kids[m.parent === null ? 'root' : m.parent] || []).push(m); });
  render();
}
function load() {
  $('#msg').innerHTML = '';
  return req('GET', API + ($('#showInactive').checked ? '?inactive=1' : '')).then(setData).catch(function (e) {
    $('#tree').innerHTML = ''; $('#msg').innerHTML = '<div class="error" role="alert">' + esc(e.message) + '</div>';
  });
}
function descendants(id) {
  var out = [], q = [id];
  while (q.length) { (st.kids[q.shift()] || []).forEach(function (k) { out.push(k.id); q.push(k.id); }); }
  return out;
}
function sortNodes(a) {
  var lvl = { group_manager: 3, unit_manager: 2, agent: 1 };
  return a.slice().sort(function (x, y) { return (y.is_active - x.is_active) || (lvl[y.rank] - lvl[x.rank]) || x.name.localeCompare(y.name); });
}
function rankPill(r) { return '<span class="pill ' + (r === 'group_manager' ? 'gm' : r === 'unit_manager' ? 'um' : '') + '">' + esc(rankName(r)) + '</span>'; }
function nodeHtml(m) {
  var v = V(), pills = rankPill(m.rank);
  if (m.is_admin) pills += '<span class="pill admin">Super admin</span>';
  if (m.is_team_admin) pills += '<span class="pill admin">Team admin</span>';
  if (m.rank_warning) pills += '<span class="pill warn" title="Ranked above their upline">⚠ above upline</span>';
  if (!m.is_active) pills += '<span class="pill off">Removed</span>';
  if (m.id === v.id) pills += '<span class="pill">You</span>';
  var acts = '';
  if (m.is_active) acts += '<button class="btn plain small" data-copy="' + m.id + '">Copy link</button>';
  if (m.is_active && m.can_manage) acts += '<button class="btn small" data-manage="' + m.id + '">Manage</button>';
  if (!m.is_active && v.super_admin) acts += '<button class="btn small" data-react="' + m.id + '">Reactivate</button>';
  var kids = sortNodes(st.kids[m.id] || []);
  return '<li><div class="node' + (m.is_active ? '' : ' inactive') + '"><div class="who"><span class="name">' + esc(m.name) + '</span><span class="pills">' + pills + '</span>' +
    '<div class="sub">' + esc(m.email) + ' · ' + m.candidates + ' candidate' + (m.candidates === 1 ? '' : 's') + (kids.length ? ' · ' + kids.length + ' directly under' : '') + '</div></div><div class="acts">' + acts + '</div></div>' +
    (kids.length ? '<ul>' + kids.map(nodeHtml).join('') + '</ul>' : '') + '</li>';
}
function render() {
  var v = V();
  $('#who').textContent = 'Signed in as ' + v.name + (v.super_admin ? ' (super admin)' : v.team_admin ? ' (team admin)' : '');
  $('#intro').textContent = v.super_admin ? 'Everyone in the system. Only you can add, remove or reactivate members.' :
    v.team_admin ? 'You and your group. You can change ranks, move and remove people below you.' :
    'Your place in the team, and everyone under you. Who you can see here is who you can see in every tool.';
  $('#addBtn').hidden = !v.super_admin;
  var roots = sortNodes(st.kids.root || []);
  $('#tree').innerHTML = roots.length ? roots.map(nodeHtml).join('') : '<li class="card">Nobody to show.</li>';
}
$('#showInactive').addEventListener('change', load);
$('#tree').addEventListener('click', function (e) {
  var b = e.target.closest('button'); if (!b) return;
  if (b.dataset.copy) {
    var m = st.byId[b.dataset.copy], link = location.origin + '/career-code/?ref=' + encodeURIComponent(m.referral_code);
    (navigator.clipboard ? navigator.clipboard.writeText(link) : Promise.reject()).then(function () { toast('Copied ' + m.name + '’s link: ' + link); }, function () { window.prompt('Copy this link:', link); });
  } else if (b.dataset.manage) { manage(+b.dataset.manage); }
  else if (b.dataset.react) { reactivate(+b.dataset.react); }
});

/* ---------- dialog helpers ---------- */
function openDlg(html) { body.innerHTML = html + '<div class="row" style="justify-content:flex-end"><button class="btn plain" data-close>Close</button></div>'; if (!dlg.open) dlg.showModal(); }
dlg.addEventListener('click', function (e) { if (e.target === dlg || e.target.hasAttribute('data-close')) dlg.close(); });
function fail(e) { toast(e.message, true); }
function destOptions(target, selected, allowTop) {
  var v = V(), skip = target ? [target].concat(descendants(target)) : [];
  var opts = Object.keys(st.byId).map(Number).filter(function (id) { return st.byId[id].is_active && skip.indexOf(id) < 0; })
    .map(function (id) { return st.byId[id]; }).sort(function (a, b) { return a.name.localeCompare(b.name); })
    .map(function (m) { return '<option value="' + m.id + '"' + (m.id === selected ? ' selected' : '') + '>' + esc(m.name) + ' (' + esc(rankName(m.rank)) + ')</option>'; }).join('');
  return (allowTop && v.super_admin ? '<option value=""' + (selected === null ? ' selected' : '') + '>No upline (top level)</option>' : '') + opts;
}

/* ---------- manage one person ---------- */
function manage(id) {
  var m = st.byId[id], v = V(), parent = m.parent === null ? null : st.byId[m.parent];
  var ranks = Object.keys(st.data.ranks).map(function (r) { return '<option value="' + r + '"' + (r === m.rank ? ' selected' : '') + '>' + esc(rankName(r)) + '</option>'; }).join('');
  var html = '<h2>' + esc(m.name) + '</h2><p class="note">' + esc(rankName(m.rank)) + (parent ? ' · under ' + esc(parent.name) : ' · top level') + ' · ' + descendants(id).length + ' people below</p>' +
    '<div class="sec"><h3>Rank</h3><select id="mRank">' + ranks + '</select><div class="row"><button class="btn" id="mRankGo">Preview change</button></div><div id="mPlan"></div></div>' +
    (m.is_admin ? '<div class="sec"><h3>Position</h3><p class="note">The super admin always stays at the top: anyone above you could see your candidates.</p></div>' :
    '<div class="sec"><h3>Move (manual placement)</h3><label class="f" for="mTo">Put ' + esc(m.name) + ' under</label><select id="mTo">' + destOptions(id, m.parent, true) + '</select>' +
      '<label class="check"><input type="checkbox" id="mWith" checked> Move their team with them</label><div class="row"><button class="btn plain" id="mMove">Move</button></div></div>');
  if (v.super_admin && m.rank === 'group_manager' && !m.is_admin) {
    html += '<div class="sec"><h3>Team admin</h3><p class="note">' + (m.is_team_admin ? esc(m.name) + ' can change ranks, move and remove people in their own group.' : 'Let ' + esc(m.name) + ' change ranks, move and remove people in their own group (never anyone else).') + '</p><div class="row"><button class="btn plain" id="mTA" data-on="' + (m.is_team_admin ? '0' : '1') + '">' + (m.is_team_admin ? 'Remove team admin' : 'Make team admin') + '</button></div></div>';
  }
  if (v.super_admin) {
    html += '<div class="sec"><h3>Details</h3><label class="f" for="mName">Name</label><input type="text" id="mName" value="' + esc(m.name) + '"><label class="f" for="mEmail">Login email (must match Cloudflare)</label><input type="email" id="mEmail" value="' + esc(m.email) + '">' +
      '<label class="f" for="mRef">Personal link code</label><input type="text" id="mRef" value="' + esc(m.referral_code) + '"><div class="row"><button class="btn plain" id="mSave">Save details</button></div></div>';
  }
  if (!m.is_admin && m.id !== v.id) {
    html += '<div class="sec"><h3>Remove</h3><div class="row"><button class="btn danger" id="mRemove">They left the business…</button>' + (v.super_admin ? '<button class="btn danger" id="mGroup">Remove whole group…</button>' : '') + '</div></div>';
  }
  openDlg(html);
  $('#mRankGo').onclick = function () { previewRank(id); };
  if ($('#mMove')) $('#mMove').onclick = function () {
    var to = $('#mTo').value === '' ? null : +$('#mTo').value, withTeam = $('#mWith').checked, name = to === null ? 'top level' : st.byId[to].name;
    if (!confirm('Put ' + m.name + ' under ' + name + (withTeam ? ' with their team' : ' (their team stays where it is)') + '? Who can see whose candidates changes straight away.')) return;
    act({ action: 'move', id: id, to: to, with_team: withTeam }).then(function () { dlg.close(); toast(m.name + ' moved under ' + name + '.'); }).catch(fail);
  };
  if ($('#mTA')) $('#mTA').onclick = function () { var on = this.dataset.on === '1'; act({ action: 'team_admin', id: id, on: on }).then(function () { dlg.close(); toast(on ? m.name + ' is now a team admin.' : m.name + ' is no longer a team admin.'); }).catch(fail); };
  if ($('#mSave')) $('#mSave').onclick = function () { act({ action: 'edit', id: id, name: $('#mName').value, email: $('#mEmail').value, referral_code: $('#mRef').value }).then(function () { dlg.close(); toast('Details saved.'); }).catch(fail); };
  if ($('#mRemove')) $('#mRemove').onclick = function () { removeMember(id); };
  if ($('#mGroup')) $('#mGroup').onclick = function () { removeGroup(id); };
}
function personTxt(p) { return p ? esc(p.name) : 'top level'; }
function previewRank(id) {
  var rank = $('#mRank').value, m = st.byId[id];
  act({ action: 'preview_rank', id: id, rank: rank }).then(function (p) {
    if (p.from_rank === p.to_rank) { $('#mPlan').innerHTML = '<p class="note">That is already their rank.</p>'; return; }
    var h = '<div class="plan"><b>' + esc(m.name) + ': ' + esc(rankName(p.from_rank)) + ' → ' + esc(rankName(p.to_rank)) + '</b>';
    if (p.moves.length) {
      h += '<p class="note">Suggested moves (untick any you do not want):</p>' + p.moves.map(function (mv) {
        return '<label><input type="checkbox" class="mv" value="' + mv.member + '" checked><span><b>' + esc(mv.member_p.name) + '</b>' + (mv.team_size ? ' and ' + mv.team_size + ' under them' : '') + ' move from ' + personTxt(mv.from_p) + ' to <b>' + personTxt(mv.to_p) + '</b> (' + esc(mv.reason) + ')</span></label>';
      }).join('');
    } else { h += '<p class="note">Nobody moves.</p>'; }
    if (p.team_admin_off) h += '<p class="note">They will no longer be a team admin.</p>';
    if (p.gm_choice) h += '<p class="note"><b>New Group Manager.</b> Under GE rules they break away from the Group Manager who built them and sit directly under the super admin. ' + (V().super_admin ?
      'Ask them: stay in the system (confirm below) or opt out (use \u201cRemove whole group\u201d instead).' :
      'Confirm below to keep them in the system. If they want to leave the system completely, ask the super admin.') + '</p>';
    h += '<div class="row"><button class="btn" id="mApply">Confirm change</button></div></div>';
    $('#mPlan').innerHTML = h;
    $('#mApply').onclick = function () {
      var accept = Array.prototype.map.call(document.querySelectorAll('#mPlan .mv:checked'), function (c) { return +c.value; });
      act({ action: 'apply_rank', id: id, rank: rank, accept: accept }).then(function () { dlg.close(); toast(m.name + ' is now ' + rankName(rank) + '.'); }).catch(fail);
    };
  }).catch(fail);
}

/* ---------- removals ---------- */
function removeMember(id) {
  var m = st.byId[id];
  act({ action: 'preview_remove', id: id }).then(function (p) {
    var opts = (V().super_admin ? '<option value="">Nobody (top level)</option>' : '') + p.destinations.map(function (d) { return '<option value="' + d.id + '"' + (p.default_to && d.id === p.default_to.id ? ' selected' : '') + '>' + esc(d.name) + ' (' + esc(rankName(d.rank)) + ')</option>'; }).join('');
    openDlg('<h2>Remove ' + esc(m.name) + '</h2><p class="note">For someone who has left the business. They are switched off, not erased, and can be reactivated later.</p>' +
      '<div class="plan">' + (p.agents.length ? '<b>' + p.agents.length + ' directly under them:</b> ' + p.agents.map(function (a) { return esc(a.name); }).join(', ') : 'Nobody is directly under them.') + '<br><b>' + p.candidates + '</b> candidate' + (p.candidates === 1 ? '' : 's') + ' of theirs.</div>' +
      '<label class="f" for="rTo">Their team' + (p.candidates ? ' and candidates' : '') + ' go to</label><select id="rTo">' + opts + '</select>' +
      (p.candidates ? '<label class="check"><input type="checkbox" id="rCand" checked> Move their candidates too (so someone follows them up)</label>' : '') +
      '<div class="row"><button class="btn danger" id="rGo">Remove ' + esc(m.name) + '</button></div><p class="note">Remember to delete ' + esc(m.email) + ' from the Cloudflare Zero Trust list too.</p>');
    $('#rGo').onclick = function () {
      act({ action: 'remove', id: id, team_to: $('#rTo').value === '' ? null : +$('#rTo').value, move_candidates: $('#rCand') ? $('#rCand').checked : true })
        .then(function () { dlg.close(); toast(m.name + ' removed. Delete their email from Cloudflare Zero Trust.'); }).catch(fail);
    };
  }).catch(fail);
}
function removeGroup(id) {
  var m = st.byId[id];
  act({ action: 'preview_remove_group', id: id }).then(function (p) {
    var needCopy = p.candidates > 0, copied = false;
    openDlg('<h2>Remove ' + esc(m.name) + '’s whole group</h2><p class="note">For a Group Manager who opts out. Everyone below is switched off (they can be reactivated later) and <b>their ' + p.candidates + ' candidate' + (p.candidates === 1 ? '' : 's') + ' are permanently deleted</b> for their privacy.</p>' +
      '<div class="plan"><b>' + p.people.length + ' people:</b><br>' + p.people.map(function (x) { return esc(x.name) + ' · ' + esc(rankName(x.rank)) + ' · ' + esc(x.email); }).join('<br>') + '</div>' +
      (needCopy ? '<div class="row"><a class="btn plain" id="gCopy" href="' + API + '?export_group=' + id + '" download>1. Download a copy (CSV)</a></div>' : '') +
      '<label class="f" for="gWord">' + (needCopy ? '2. ' : '') + 'Type REMOVE to confirm</label><input type="text" id="gWord" autocomplete="off" autocapitalize="characters">' +
      '<div class="row"><button class="btn danger" id="gGo" disabled>Remove the group</button></div>');
    var check = function () { $('#gGo').disabled = !((copied || !needCopy) && $('#gWord').value.trim() === 'REMOVE'); };
    if ($('#gCopy')) $('#gCopy').onclick = function () { copied = true; setTimeout(check, 0); };
    $('#gWord').oninput = check;
    $('#gGo').onclick = function () {
      act({ action: 'remove_group', id: id, confirm: 'REMOVE' }).then(function (r) {
        openDlg('<h2>Group removed</h2><p>' + r.removed + ' people switched off, ' + r.candidates_deleted + ' candidates deleted.</p><p><b>Now delete these emails from the Cloudflare Zero Trust list:</b></p><div class="plan" id="gEmails">' + r.emails.map(esc).join('<br>') + '</div>');
      }).catch(fail);
    };
  }).catch(fail);
}
function reactivate(id) {
  var m = st.byId[id];
  act({ action: 'preview_reactivate', id: id }).then(function (p) {
    openDlg('<h2>Reactivate ' + esc(m.name) + '</h2><label class="f" for="aTo">Put them under</label><select id="aTo">' + destOptions(id, p.default_to ? p.default_to.id : null, true) + '</select>' +
      (p.team.length ? '<label class="check"><input type="checkbox" id="aTeam" checked> Also bring back the ' + p.team.length + ' people removed with them (' + p.team.map(function (x) { return esc(x.name); }).join(', ') + ')</label>' : '') +
      '<div class="row"><button class="btn" id="aGo">Reactivate</button></div><p class="note">Add their email' + (p.team.length ? 's' : '') + ' back to the Cloudflare Zero Trust list so they can log in. Candidates deleted earlier do not come back.</p>');
    $('#aGo').onclick = function () {
      act({ action: 'reactivate', id: id, to: $('#aTo').value === '' ? null : +$('#aTo').value, with_team: $('#aTeam') ? $('#aTeam').checked : false })
        .then(function () { dlg.close(); toast(m.name + ' is active again.'); }).catch(fail);
    };
  }).catch(fail);
}

/* ---------- add ---------- */
$('#addBtn').addEventListener('click', function () {
  var ranks = Object.keys(st.data.ranks).map(function (r) { return '<option value="' + r + '">' + esc(rankName(r)) + '</option>'; }).join('');
  openDlg('<h2>Add a member</h2><label class="f" for="nName">Name</label><input type="text" id="nName"><label class="f" for="nEmail">Login email (the one on the Cloudflare list)</label><input type="email" id="nEmail">' +
    '<label class="f" for="nRank">Rank</label><select id="nRank">' + ranks + '</select><label class="f" for="nUp">Upline</label><select id="nUp">' + destOptions(null, V().id, true) + '</select>' +
    '<label class="f" for="nRef">Personal link code (optional, made from the name if empty)</label><input type="text" id="nRef">' +
    '<div class="row"><button class="btn" id="nGo">Add</button></div><p class="note">They can log in once their email is also on the Cloudflare Zero Trust list.</p>');
  $('#nGo').onclick = function () {
    act({ action: 'add', name: $('#nName').value, email: $('#nEmail').value, rank: $('#nRank').value, upline_id: $('#nUp').value === '' ? null : +$('#nUp').value, referral_code: $('#nRef').value })
      .then(function (r) { dlg.close(); toast(r.name + ' added. Add ' + r.email + ' to Cloudflare Zero Trust if it is not there yet.'); }).catch(fail);
  };
});

load();
})();
