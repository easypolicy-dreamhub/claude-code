<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
coach_boot(); // existing member login: signed-out visitors never get the page
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
?><!DOCTYPE html>
<html lang="en" data-theme="light">
<head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light only">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="/images/favicon.png">
<title>AI Coach</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#F4F2EF;--surface:#fff;--ink:#24333A;--muted:#5C6870;--line:#E2DDD8;--accent:#24333A;--accent-ink:#fff;--chip:#EFEAE5;--warn:#b45309;--bad:#c0262d;--good:#15803d;--wa:#1f9d55}
*{box-sizing:border-box}
html,body{margin:0}
body{background:var(--bg);color:var(--ink);font:400 16px/1.5 "DM Sans","PingFang SC","Microsoft YaHei",system-ui,sans-serif;min-height:100vh;-webkit-text-size-adjust:100%}
.e3-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 16px;min-height:46px;background:#24333A;color:#FFFFFF;font:500 14px/1.2 "DM Sans",system-ui,sans-serif;position:relative;z-index:100}
.e3-bar a{display:inline-flex;align-items:center;gap:8px;min-height:44px;color:#FFFFFF;text-decoration:none}
.e3-bar a:hover{color:#B9AFA7}
.e3-bar .e3-brand{font:700 15px/1 Poppins,"DM Sans",system-ui,sans-serif;color:#FFFFFF}
.e3-bar .e3-brand span{color:#B9AFA7}
.hero{background:#24333A;color:#fff;padding:28px 16px 64px}
.wrap{max-width:860px;margin:0 auto}
.hero h1{font:700 34px/1.1 Poppins,"DM Sans",sans-serif;margin:0 0 6px}
.hero p{margin:0;color:#c9d2d7}
.card{background:var(--surface);border:1px solid var(--line);border-radius:20px;box-shadow:0 6px 24px rgba(36,51,58,.08);padding:18px;margin:-40px 16px 0}
.card+.card{margin-top:16px}
.card.plain{margin-top:16px}
@media(min-width:900px){.card{margin-left:auto;margin-right:auto;max-width:860px;padding:22px}}
.tabs,.pills{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.tab,.pill{border:1px solid var(--line);background:#fff;color:var(--ink);border-radius:999px;padding:9px 18px;font:500 15px "DM Sans",sans-serif;cursor:pointer;min-height:44px}
.tab.on,.pill.on{background:var(--accent);color:var(--accent-ink);border-color:var(--accent)}
.panel{display:none}.panel.on{display:block}
h2{font:600 18px/1.2 Poppins,"DM Sans",sans-serif;margin:0 0 4px}
.sub{color:var(--muted);font-size:14px;margin:0 0 12px}
.btn{border:0;background:var(--accent);color:var(--accent-ink);border-radius:12px;padding:11px 18px;font:500 15px "DM Sans",sans-serif;cursor:pointer;min-height:44px}
.btn.ghost{background:#fff;color:var(--ink);border:1px solid var(--line)}
.btn.small{padding:6px 12px;min-height:36px;font-size:14px}
.btn:disabled{opacity:.5;cursor:not-allowed}
.banner{border:1px solid var(--line);background:var(--chip);border-radius:12px;padding:10px 14px;font-size:14px;margin-bottom:12px}
.banner.warn{border-color:#f0c58a;background:#fff7ec;color:#7a4306}
.banner.bad{border-color:#e6a5a8;background:#fdeeee;color:#8c1c22}
/* chat */
#log{min-height:260px;max-height:58vh;overflow:auto;padding:4px 2px;display:flex;flex-direction:column;gap:10px}
.msg{max-width:88%;padding:10px 14px;border-radius:16px;word-wrap:break-word;overflow-wrap:anywhere}
.msg.user{align-self:flex-end;background:var(--accent);color:#fff;border-bottom-right-radius:4px;white-space:pre-wrap}
.msg.coach{align-self:flex-start;background:var(--chip);border-bottom-left-radius:4px}
.msg.coach p{margin:0 0 8px}.msg.coach p:last-child{margin:0}
.msg.coach ul{margin:4px 0 8px;padding-left:20px}
.msg.err{align-self:center;background:#fdeeee;color:#8c1c22;font-size:14px}
.typing{align-self:flex-start;color:var(--muted);font-size:14px;padding:4px 8px}
.wa{display:inline-block;margin-top:8px;background:var(--wa);color:#fff;text-decoration:none;border-radius:12px;padding:10px 16px;font-weight:500;min-height:44px}
#form{display:flex;gap:8px;align-items:flex-end;margin-top:12px}
#msg{flex:1;resize:none;border:1px solid var(--line);border-radius:14px;padding:11px 14px;font:inherit;min-height:48px;max-height:160px;background:#fff;color:var(--ink)}
.note{color:var(--muted);font-size:13px;margin:10px 0 0}
/* settings + usage */
textarea.big{width:100%;min-height:260px;border:1px solid var(--line);border-radius:14px;padding:12px 14px;font:inherit;font-size:15px;background:#fff;color:var(--ink);resize:vertical}
.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0}
.list{border:1px solid var(--line);border-radius:14px;overflow:hidden}
.list .item{display:flex;gap:10px;align-items:center;justify-content:space-between;padding:10px 14px;border-top:1px solid var(--line);font-size:14px}
.list .item:first-child{border-top:0}
.list .item .grow{flex:1;min-width:0;overflow-wrap:anywhere}
.list .item small{color:var(--muted);display:block}
.drop{border:2px dashed var(--line);border-radius:14px;padding:18px;text-align:center;color:var(--muted);margin:10px 0;cursor:pointer}
.drop.over{border-color:var(--accent);background:var(--chip)}
.ok{color:var(--good)}.bad{color:var(--bad)}.warn{color:var(--warn)}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
.tile{border:1px solid var(--line);border-radius:14px;padding:14px 16px}
.tile b{display:block;font:700 28px/1.1 Poppins,"DM Sans",sans-serif}
.tile span{color:var(--muted);font-size:14px}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{padding:9px 6px;text-align:right;border-top:1px solid var(--line)}
th:first-child,td:first-child{text-align:left}
th{color:var(--muted);font-weight:500;border-top:0}
td button.link{background:none;border:0;color:var(--ink);font:inherit;text-decoration:underline;cursor:pointer;padding:6px 0;text-align:left}
.q{border-top:1px solid var(--line);padding:9px 0;font-size:14px}.q small{color:var(--muted)}
.hide{display:none!important}
</style>
</head>
<body>
<nav class="e3-bar" aria-label="E3 toolkit"><a href="/members/"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>E3 Toolkit</a><span class="e3-brand">Dream<span>Hub</span> E3</span></nav>
<header class="hero"><div class="wrap"><h1>AI Coach</h1><p>Ask anything. Your coach remembers you.</p></div></header>

<main>
<div class="card">
  <div class="tabs hide" id="tabs">
    <button class="tab on" data-tab="chat">Chat</button>
    <button class="tab" data-tab="settings">Settings</button>
    <button class="tab" data-tab="usage">Usage</button>
  </div>

  <section class="panel on" id="p-chat">
    <div id="chat-banner"></div>
    <div id="log" aria-live="polite"></div>
    <form id="form" autocomplete="off">
      <textarea id="msg" rows="1" placeholder="Type your question…" maxlength="4000"></textarea>
      <button class="btn" id="send" type="submit">Send</button>
    </form>
    <p class="note">Your chats are private to you. Your team leader can see the questions you ask, but not the coach's answers.</p>
  </section>

  <section class="panel" id="p-settings">
    <div id="set-warn"></div>
    <h2>Coach instructions</h2>
    <p class="sub">How the coach should behave: who it is, how it coaches, and what it must never do. Changes apply from the next message any agent sends.</p>
    <textarea class="big" id="instr" placeholder="Example:&#10;You are a friendly, direct sales coach for DreamHub E3 insurance agents.&#10;- Role: coach agents through client conversations and objection handling.&#10;- Style: ask one question at a time, then give a short example they can say out loud.&#10;- Always base product facts on the knowledge documents.&#10;- Never: promise returns, give legal advice, or discuss other agents."></textarea>
    <div class="row"><button class="btn" id="save-instr">Save instructions</button><span id="instr-msg" class="sub" style="margin:0"></span></div>
    <h2 style="margin-top:22px">Earlier versions</h2>
    <p class="sub" id="ver-sub">The last 5 saves are kept.</p>
    <div class="list" id="versions"></div>

    <h2 style="margin-top:26px">Knowledge (PDF documents)</h2>
    <p class="sub">The coach reads all of these for every question. Uploading a file with the same name replaces the old one.</p>
    <div class="drop" id="drop">Tap to choose PDFs, or drop several here at once<input type="file" id="file" accept="application/pdf,.pdf" multiple hidden></div>
    <div id="up-results"></div>
    <div class="list" id="kb"></div>
    <p class="note" id="kb-status"></p>
  </section>

  <section class="panel" id="p-usage">
    <div class="pills" id="ranges">
      <button class="pill on" data-r="7">Last 7 days</button>
      <button class="pill" data-r="30">Last 30 days</button>
      <button class="pill" data-r="all">All time</button>
    </div>
    <div class="tiles" id="tiles"></div>
    <h2>Needs a nudge</h2>
    <p class="sub">Agents who have not asked the coach anything in 7 days.</p>
    <div class="list" id="nudge" style="margin-bottom:20px"></div>
    <h2>People</h2>
    <p class="sub">Tap a name to see their questions. You see questions only, never the coach's answers.</p>
    <div style="overflow-x:auto"><table id="people"></table></div>
    <div id="person" class="hide" style="margin-top:16px"></div>
  </section>
</div>
</main>

<script>
(function(){
const $=s=>document.querySelector(s);
const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const when=ts=>ts?new Date(ts*1000).toLocaleString([], {dateStyle:'medium',timeStyle:'short'}):'never';
const mb=n=>(n/1048576).toFixed(1)+' MB';
let ME={name:'',is_admin:false,ready:false,whatsapp:''};

async function api(action,{method='GET',json,form,query=''}={}){
  const opt={method,headers:{'X-Coach-Request':'1'},credentials:'same-origin'};
  if(json!==undefined){opt.headers['Content-Type']='application/json';opt.body=JSON.stringify(json)}
  if(form){opt.body=form}
  const r=await fetch('api.php?action='+action+query,opt);
  let d={};try{d=await r.json()}catch(e){}
  if(!r.ok){const err=new Error(d.error||('Something went wrong ('+r.status+')'));err.detail=d.detail;err.status=r.status;throw err}
  return d;
}

/* ---- safe mini-markdown for coach replies ---- */
function rich(text){
  const inline=s=>s.replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>');
  let html='',list=false;
  for(const raw of esc(text).split('\n')){
    const m=raw.match(/^\s*[-*•]\s+(.*)/);
    if(m){if(!list){html+='<ul>';list=true}html+='<li>'+inline(m[1])+'</li>'}
    else{if(list){html+='</ul>';list=false}if(raw.trim())html+='<p>'+inline(raw)+'</p>'}
  }
  return html+(list?'</ul>':'');
}

/* ---- chat ---- */
const log=$('#log');
function scrollEnd(){log.scrollTop=log.scrollHeight}
function addUser(t){const d=document.createElement('div');d.className='msg user';d.textContent=t;log.appendChild(d);scrollEnd()}
function addCoach(t,handoff,question){
  const d=document.createElement('div');d.className='msg coach';d.innerHTML=rich(t);
  if(handoff){
    if(ME.whatsapp){
      const a=document.createElement('a');a.className='wa';a.target='_blank';a.rel='noopener';
      a.href='https://wa.me/'+ME.whatsapp+'?text='+encodeURIComponent('Hi, this is '+ME.name+'. I asked the AI Coach: "'+question+'" and need your help.');
      a.textContent='Ask my leader on WhatsApp';d.appendChild(a);
    }else{const p=document.createElement('p');p.textContent='Please ask your team leader about this one.';d.appendChild(p)}
  }
  log.appendChild(d);scrollEnd();
}
function addErr(t){const d=document.createElement('div');d.className='msg err';d.textContent=t;log.appendChild(d);scrollEnd()}

async function loadChat(){
  const {messages}=await api('history');
  let lastQ='';
  for(const m of messages){
    if(m.role==='user'){lastQ=m.text;addUser(m.text)}else addCoach(m.text,m.handoff,lastQ);
  }
  if(!messages.length)addCoach(ME.ready||ME.is_admin?'Hi '+ME.name+'! What would you like to work on today?':'The coach is being set up. Check back soon.',false,'');
}
function setBusy(b){$('#send').disabled=b;$('#msg').disabled=b}
$('#form').addEventListener('submit',async e=>{
  e.preventDefault();
  const ta=$('#msg'),text=ta.value.trim();if(!text)return;
  ta.value='';ta.style.height='';addUser(text);
  const t=document.createElement('div');t.className='typing';t.textContent='Coach is typing…';log.appendChild(t);scrollEnd();
  setBusy(true);
  try{const d=await api('chat',{method:'POST',json:{message:text}});t.remove();addCoach(d.reply,d.handoff,text)}
  catch(err){t.remove();addErr(err.message+(err.detail&&ME.is_admin?' ['+err.detail+']':''))}
  setBusy(false);ta.focus();
});
$('#msg').addEventListener('keydown',e=>{
  if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing&&e.keyCode!==229){e.preventDefault();$('#form').requestSubmit()}
});
$('#msg').addEventListener('input',e=>{e.target.style.height='auto';e.target.style.height=Math.min(e.target.scrollHeight,160)+'px'});

/* ---- tabs ---- */
document.querySelectorAll('.tab').forEach(b=>b.addEventListener('click',()=>{
  document.querySelectorAll('.tab').forEach(x=>x.classList.toggle('on',x===b));
  document.querySelectorAll('.panel').forEach(p=>p.classList.toggle('on',p.id==='p-'+b.dataset.tab));
  if(b.dataset.tab==='settings')loadSettings();
  if(b.dataset.tab==='usage')loadUsage();
}));

/* ---- settings (super admin) ---- */
async function loadSettings(){
  let s;try{s=await api('settings')}catch(e){$('#set-warn').innerHTML='<div class="banner bad">'+esc(e.message)+'</div>';return}
  $('#set-warn').innerHTML=s.warnings.map(w=>'<div class="banner warn">'+esc(w)+'</div>').join('')
    +(s.instructions?'':'<div class="banner">Not set up yet. Agents see "The coach is being set up" until you save instructions. You can chat in the Chat tab to test at any time.</div>');
  if(document.activeElement!==$('#instr'))$('#instr').value=s.instructions?s.instructions.text:'';
  $('#instr-msg').textContent=s.instructions?'Version '+s.instructions.version+' saved '+when(s.instructions.saved_at)+' by '+s.instructions.saved_by:'';
  $('#ver-sub').textContent='The last '+s.keep_versions+' saves are kept. Restoring one saves it as the newest version.';
  $('#versions').innerHTML=s.versions.length?s.versions.map((v,i)=>
    '<div class="item"><div class="grow"><b>Version '+v.version+'</b>'+(i===0?' (current)':'')+(v.restored_from?' (restored from '+v.restored_from+')':'')+'<small>'+esc(when(v.saved_at))+' · '+esc(v.saved_by)+'</small><small>'+esc(v.preview)+'</small></div>'
    +(i===0?'':'<button class="btn ghost small" data-restore="'+v.version+'">Restore</button>')+'</div>').join(''):'<div class="item">Nothing saved yet.</div>';
  renderKb(s);
}
function renderKb(s){
  $('#kb').innerHTML=s.knowledge.length?s.knowledge.map(f=>
    '<div class="item"><div class="grow">'+esc(f.name)+'<small>'+mb(f.size)+(f.pages?' · '+f.pages+' pages':'')+' · '+esc(when(f.uploaded_at))+'</small></div><button class="btn ghost small" data-del="'+esc(f.name)+'">Delete</button></div>').join(''):'<div class="item">No PDFs yet.</div>';
  const st=s.status;let line=st.pdf_count+' PDF'+(st.pdf_count===1?'':'s')+', '+mb(st.total_bytes)+', about '+st.est_pages+' pages (estimate).';
  if(st.last_usage)line+=' Last question used '+(st.last_usage.input+st.last_usage.cache_read+st.last_usage.cache_write).toLocaleString()+' input tokens ('+st.last_usage.cache_read.toLocaleString()+' read from cache).';
  $('#kb-status').innerHTML=esc(line)+(st.warn_too_big?' <b class="warn">This is getting very large for one request. Consider removing documents you no longer need.</b>':'');
}
$('#save-instr').addEventListener('click',async()=>{
  const b=$('#save-instr');b.disabled=true;
  try{await api('save_instructions',{method:'POST',json:{text:$('#instr').value}});ME.ready=true;$('#chat-banner').innerHTML='';await loadSettings();$('#instr-msg').textContent='Saved. '+$('#instr-msg').textContent}
  catch(e){$('#instr-msg').innerHTML='<span class="bad">'+esc(e.message)+'</span>'}
  b.disabled=false;
});
$('#versions').addEventListener('click',async e=>{
  const v=e.target.dataset.restore;if(!v||!confirm('Restore version '+v+'? It becomes the new current version.'))return;
  try{await api('restore_instructions',{method:'POST',json:{version:+v}});$('#instr').value='';await loadSettings()}catch(err){alert(err.message)}
});
$('#kb').addEventListener('click',async e=>{
  const n=e.target.dataset.del;if(!n||!confirm('Delete "'+n+'" from the coach\'s knowledge?'))return;
  try{await api('delete_pdf',{method:'POST',json:{name:n}});await loadSettings()}catch(err){alert(err.message)}
});
const drop=$('#drop'),fileIn=$('#file');
drop.addEventListener('click',()=>fileIn.click());
['dragover','dragenter'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.add('over')}));
['dragleave','drop'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.remove('over')}));
drop.addEventListener('drop',e=>upload([...e.dataTransfer.files]));
fileIn.addEventListener('change',()=>{upload([...fileIn.files]);fileIn.value=''});
async function upload(files){
  files=files.filter(f=>/\.pdf$/i.test(f.name)||f.type==='application/pdf');
  const box=$('#up-results');box.innerHTML='';
  for(const f of files){ // one request per file: a bad file never blocks the others, and server upload limits apply per file
    const row=document.createElement('div');row.className='sub';row.textContent=f.name+': uploading…';box.appendChild(row);
    try{
      const fd=new FormData();fd.append('pdf',f);
      const r=await api('upload_pdf',{method:'POST',form:fd});
      row.innerHTML=esc(r.name||f.name)+': '+(r.ok?'<b class="ok">'+(r.status==='replaced'?'replaced':'added')+'</b>':'<b class="bad">failed</b> '+esc(r.error));
    }catch(err){row.innerHTML=esc(f.name)+': <b class="bad">failed</b> '+esc(err.message)}
  }
  loadSettings();
}

/* ---- usage (super admin) ---- */
let range='7';
document.querySelectorAll('#ranges .pill').forEach(b=>b.addEventListener('click',()=>{
  range=b.dataset.r;document.querySelectorAll('#ranges .pill').forEach(x=>x.classList.toggle('on',x===b));loadUsage();
}));
async function loadUsage(){
  $('#person').classList.add('hide');
  let u;try{u=await api('usage',{query:'&range='+range})}catch(e){$('#tiles').innerHTML='<div class="banner bad">'+esc(e.message)+'</div>';return}
  const t=u.tiles;
  $('#tiles').innerHTML=[['Questions asked',t.questions],['Agents using it',t.agents_using],['Handed to leader',t.handoffs]].map(x=>'<div class="tile"><b>'+x[1]+'</b><span>'+x[0]+'</span></div>').join('');
  $('#nudge').innerHTML=u.needs_nudge.length?u.needs_nudge.map(p=>'<div class="item"><div class="grow">'+esc(p.name)+'</div><small>'+(p.last_active?'last asked '+esc(when(p.last_active)):'never used')+'</small></div>').join(''):'<div class="item">Nobody is waiting. Nice.</div>';
  $('#people').innerHTML='<tr><th>Name</th><th>Questions</th><th>Handoffs</th><th>Last active</th></tr>'
    +(u.people.length?u.people.map(p=>'<tr><td><button class="link" data-m="'+p.mid+'" data-n="'+esc(p.name)+'">'+esc(p.name)+'</button></td><td>'+p.questions+'</td><td>'+p.handoffs+'</td><td>'+esc(when(p.last_active))+'</td></tr>').join(''):'<tr><td colspan="4">No questions yet.</td></tr>');
}
$('#people').addEventListener('click',async e=>{
  const m=e.target.dataset.m;if(!m)return;
  const box=$('#person');box.classList.remove('hide');box.innerHTML='Loading…';
  try{
    const d=await api('usage_member',{query:'&m='+m+'&range='+range});
    box.innerHTML='<h2>'+esc(e.target.dataset.n)+'</h2>'+(d.questions.length?d.questions.map(q=>'<div class="q">'+esc(q.q)+'<br><small>'+esc(when(q.ts))+(q.handoff?' · <b class="warn">sent to leader</b>':'')+'</small></div>').join(''):'<p class="sub">No questions in this period.</p>');
  }catch(err){box.innerHTML='<div class="banner bad">'+esc(err.message)+'</div>'}
});

/* ---- start ---- */
(async()=>{
  try{ME=await api('me')}catch(e){addErr(e.message);setBusy(true);return}
  if(ME.is_admin)$('#tabs').classList.remove('hide');
  if(!ME.ready&&!ME.is_admin){$('#chat-banner').innerHTML='<div class="banner">The coach is being set up. Check back soon.</div>';setBusy(true)}
  else if(!ME.ready)$('#chat-banner').innerHTML='<div class="banner warn">Not set up yet: add instructions in Settings. You can still chat here to test.</div>';
  try{await loadChat()}catch(e){addErr(e.message)}
})();
})();
</script>
</body>
</html>
