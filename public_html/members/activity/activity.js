/* Team activity: counts and names only. The server decides whose numbers you may see; this page only draws them. */
(function () {
'use strict';
var $ = function (s) { return document.querySelector(s); };
var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
var STATUS = { new: 'New', contacted: 'Contacted', interviewed: 'Interviewed', joined: 'Joined', not_suitable: 'Not suitable' };
var SCOLOR = { new: '#B9AFA7', contacted: '#3567A8', interviewed: '#C8961A', joined: '#2E9466', not_suitable: '#B9433A' };
var RANK = { agent: 'Agent', unit_manager: 'Unit Manager', group_manager: 'Group Manager' };
var SRC = { landing: 'from the website', referral: 'through their own link', live: 'in a live session' };
var period = 'week';

function ago(iso) {
  if (!iso) return 'never';
  var s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  if (s < 90) return 'just now'; if (s < 3600) return Math.round(s / 60) + ' min ago'; if (s < 86400) return Math.round(s / 3600) + ' h ago';
  var d = Math.round(s / 86400); return d === 1 ? 'yesterday' : d + ' days ago';
}
var cand = function (id, name) { return '<a href="/members/career-code/dashboard/?candidate=' + id + '">' + esc(name) + '</a>'; };

function sentence(e) {
  switch (e.kind) {
    case 'added': return '<b>' + esc(e.who) + '</b> got a new candidate, ' + cand(e.cid, e.cand) + ', ' + esc(SRC[e.to] || '');
    case 'completed': return cand(e.cid, e.cand) + ' finished the test <span class="muted">(' + esc(e.who) + ')</span>';
    case 'status': return '<b>' + esc(e.who) + '</b> marked ' + cand(e.cid, e.cand) + ' as ' + esc(STATUS[e.to] || e.to);
    case 'reassign': return '<b>' + esc(e.who) + '</b> gave ' + cand(e.cid, e.cand) + ' to ' + esc(e.to || 'someone');
  }
  return '';
}

function draw(d) {
  $('#intro').textContent = d.viewer.is_admin ? 'Everyone’s numbers, and who needs a follow-up.' : 'Your numbers and your team’s, and who needs a follow-up.';
  $('#stats').innerHTML = [['added', 'Candidates added'], ['completed', 'Tests finished'], ['joined', 'Joined (all time)']].map(function (x) { return '<div class="stat"><b>' + d.totals[x[0]] + '</b><span>' + x[1] + '</span></div>'; }).join('');
  var total = Object.keys(d.status_now).reduce(function (n, k) { return n + d.status_now[k]; }, 0);
  $('#bar').innerHTML = total ? Object.keys(STATUS).map(function (k) { return d.status_now[k] ? '<i style="width:' + (d.status_now[k] / total * 100) + '%;background:' + SCOLOR[k] + '" title="' + STATUS[k] + '"></i>' : ''; }).join('') : '';
  $('#legend').innerHTML = total ? Object.keys(STATUS).map(function (k) { return '<span><span style="color:' + SCOLOR[k] + '">●</span> ' + STATUS[k] + ' <b>' + d.status_now[k] + '</b></span>'; }).join('') : 'No finished tests yet.';
  $('#fuNote').textContent = 'Finished tests still marked New after ' + d.follow_up_days + ' days.';
  $('#fu').innerHTML = d.follow_up.length ? d.follow_up.map(function (f) { return '<li><span>' + cand(f.id, f.name) + ' <span class="muted">· ' + esc(f.owner) + '</span></span><span class="d">' + f.days + ' days</span></li>'; }).join('') : '<li><span class="muted">Nobody is waiting. Nice.</span></li>';
  $('#people').innerHTML = '<thead><tr><th>Name</th><th>Added</th><th>Finished</th><th>Joined</th><th>Last active</th></tr></thead><tbody>' + d.people.map(function (p) {
    return '<tr class="' + (p.active ? '' : 'off') + '"><td style="padding-left:' + (0.4 + p.depth * 1.1) + 'rem"><a href="/members/career-code/dashboard/?owner=' + p.id + '">' + esc(p.name) + '</a><span class="rk">' + esc(RANK[p.rank] || '') + (p.active ? '' : ' · removed') + '</span></td><td>' + p.added + '</td><td>' + p.completed + '</td><td>' + p.joined + '</td><td>' + esc(ago(p.last_active)) + '</td></tr>';
  }).join('') + '</tbody>';
  $('#feed').innerHTML = d.feed.length ? d.feed.map(function (e) { return '<li><span>' + sentence(e) + '</span><time datetime="' + esc(e.at) + '">' + esc(ago(e.at)) + '</time></li>'; }).join('') : '<li><span class="muted">Nothing yet.</span></li>';
}
function load() {
  $('#err').textContent = '';
  fetch('/members/api/activity.php?period=' + period, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    .catch(function () { throw new Error('Could not reach the server. Check your connection.'); })
    .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) throw new Error(d.error || 'Something went wrong (' + r.status + ').'); return d; }); })
    .then(draw).catch(function (e) { $('#err').textContent = e.message; });
}
document.querySelectorAll('.pill').forEach(function (b) {
  b.addEventListener('click', function () { period = b.dataset.p; document.querySelectorAll('.pill').forEach(function (x) { x.setAttribute('aria-pressed', x === b); }); load(); });
});
load();
})();
