<?php
declare(strict_types=1);
define('HNWCOACH_PAGE', true); // tells the login bridge to show a readable page, not JSON, when it refuses someone
require __DIR__ . '/lib/bootstrap.php';
coach_boot(); // existing member login: signed-out visitors never get the page
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
?><!DOCTYPE html>
<html lang="zh-Hans" data-theme="light">
<head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light only">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="/images/favicon.png">
<title>HNW Coach</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Noto+Sans+SC:wght@400;500;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/members/e3-toolkit.css">
<style>
:root{--bg:#F4F2EF;--surface:#fff;--ink:#24333A;--muted:#5C6870;--line:#E2DDD8;--accent:#24333A;--accent-ink:#fff;--chip:#EFEAE5;--warn:#b45309;--bad:#c0262d;--good:#15803d;--wa:#1f9d55}
*{box-sizing:border-box}
html,body{margin:0}
body{background:var(--bg);color:var(--ink);font:400 16px/1.5 "DM Sans","Noto Sans SC","PingFang SC","Microsoft YaHei",system-ui,sans-serif;min-height:100vh;-webkit-text-size-adjust:100%}
.card{background:var(--surface);border:1px solid var(--line);border-radius:20px;box-shadow:0 6px 24px rgba(36,51,58,.08);padding:18px;margin:16px 16px 0}
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
.chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.chip{border:1px solid var(--line);background:var(--chip);color:var(--ink);border-radius:999px;padding:8px 14px;font:inherit;font-size:14px;cursor:pointer;min-height:40px;text-align:left}
.txt{width:100%;min-height:46px;border:1px solid var(--line);border-radius:12px;padding:10px 14px;font:inherit;background:#fff;color:var(--ink);margin:6px 0}
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
<div class="e3-bar">
  <a href="/members/"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>E3 Toolkit</a>
  <span class="e3-brand">Dream<span>Hub</span> E3</span>
</div>
<header class="topbar">
  <h1>HNW Coach<small data-i="sub"></small></h1>
  <button class="btn" id="lang" type="button"></button>
</header>

<main>
<div class="card">
  <div class="tabs hide" id="tabs">
    <button class="tab on" data-tab="chat" data-i="tab_chat"></button>
    <button class="tab" data-tab="settings" data-i="tab_settings"></button>
    <button class="tab" data-tab="usage" data-i="tab_usage"></button>
  </div>

  <section class="panel on" id="p-chat">
    <div id="chat-banner"></div>
    <div id="log" aria-live="polite"></div>
    <div class="chips" id="chips">
      <button type="button" class="chip" id="chip1"></button>
      <button type="button" class="chip" id="chip2"></button>
    </div>
    <form id="form" autocomplete="off">
      <textarea id="msg" rows="1" data-ip="ph_msg" maxlength="4000"></textarea>
      <button class="btn" id="send" type="submit" data-i="send"></button>
    </form>
    <p class="note" data-i="note"></p>
  </section>

  <section class="panel" id="p-settings">
    <div id="set-warn"></div>
    <h2 data-i="conn_h"></h2>
    <p class="sub" data-i="conn_p"></p>
    <p class="sub" id="conn-status"></p>
    <input class="txt" id="key" type="password" autocomplete="new-password" spellcheck="false" data-ip="key_ph">
    <input class="txt" id="wa" type="tel" inputmode="numeric" data-ip="wa_ph">
    <div class="row"><button class="btn" id="save-conn" data-i="save_conn"></button><button class="btn ghost" id="clear-key" type="button" data-i="clear_key"></button><span id="conn-msg" class="sub" style="margin:0"></span></div>
    <div class="row"><button class="btn ghost" id="test-conn" type="button" data-i="test_btn"></button></div>
    <div id="test-out"></div>

    <h2 style="margin-top:26px" data-i="instr_h"></h2>
    <p class="sub" data-i="instr_p"></p>
    <textarea class="big" id="instr" data-ip="instr_ph"></textarea>
    <div class="row"><button class="btn" id="save-instr" data-i="save_instr"></button><span id="instr-msg" class="sub" style="margin:0"></span></div>
    <h2 style="margin-top:22px" data-i="ver_h"></h2>
    <p class="sub" id="ver-sub"></p>
    <div class="list" id="versions"></div>

    <h2 style="margin-top:26px" data-i="kb_h"></h2>
    <p class="sub" data-i="kb_p"></p>
    <div class="drop" id="drop"><span data-i="drop"></span><input type="file" id="file" accept="application/pdf,.pdf" multiple hidden></div>
    <div id="up-results"></div>
    <div class="list" id="kb"></div>
    <p class="note" id="kb-status"></p>
  </section>

  <section class="panel" id="p-usage">
    <div class="pills" id="ranges">
      <button class="pill on" data-r="7" data-i="r7"></button>
      <button class="pill" data-r="30" data-i="r30"></button>
      <button class="pill" data-r="all" data-i="rall"></button>
    </div>
    <div class="tiles" id="tiles"></div>
    <h2 data-i="nudge_h"></h2>
    <p class="sub" data-i="nudge_p"></p>
    <div class="list" id="nudge" style="margin-bottom:20px"></div>
    <h2 data-i="ppl_h"></h2>
    <p class="sub" data-i="ppl_p"></p>
    <div style="overflow-x:auto"><table id="people"></table></div>
    <div id="person" class="hide" style="margin-top:16px"></div>
  </section>
</div>
</main>

<script>
(function(){
const T={
zh:{
 test_btn:'测试连接',testing:'测试中…（最多约 1 分钟）',test_all_ok:'全部正常。',test_bad:'有问题，请把这个结果截图给开发者。',
 sub:'实战教练 · 拆解反对意见、优化话术、客户演练',lang_btn:'English',
 tab_chat:'教练对话',tab_settings:'设置',tab_usage:'使用情况',
 ph_msg:'输入你的问题…',send:'发送',typing:'教练输入中…',
 chip1:'客户说保险不如投资，怎么回？',chip2:'我想演练 A 型创一代，难度 2',
 note:'演练中输入「暂停」取得提示，输入「结束演练」取得评分卡。⚠️ 请勿输入真实客户姓名或个资。你的对话只有你自己看得到；团队领袖能看到你提出的问题，但看不到教练的回答。',
 hi:n=>'你好 '+n+'！今天想练习哪个情境，或想拆解哪个反对意见？',
 setup:'教练正在设置中，请稍后再回来。',
 notset_admin:'尚未设置完成：请到「设置」加入教练指示。你仍可在此对话测试。',
 wa_btn:'在 WhatsApp 询问我的团队领袖',ask_leader:'这个问题请直接询问你的团队领袖。',
 wa_msg:(n,q)=>'你好，我是 '+n+'。我问了 HNW 教练：「'+q+'」，需要你的协助。',
 conn_h:'连接设置',conn_p:'在此粘贴 Google AI Studio 的 Gemini API 密钥。密钥只保存在服务器，之后不会再显示，也不会传给成员。密钥栏留空即保留已保存的密钥。',
 key_ph:'粘贴新的 Gemini API 密钥',wa_ph:'团队领袖的 WhatsApp 号码（国码开头，例如 60123456789）',
 save_conn:'保存连接设置',clear_key:'移除已保存的密钥',saved:'已保存。',key_removed:'已移除密钥。',
 key_saved:l=>'Gemini 密钥已保存（结尾为 <b>'+l+'</b>）',key_file:'。此密钥来自服务器文件，无法在此更改。',
 key_none:'<b class="warn">尚未设置 Gemini 密钥。</b>加入之前，教练无法回答。',
 confirm_clear:'要移除已保存的 Gemini 密钥吗？重新加入之前，教练会停止回答。',
 instr_h:'教练指示',instr_p:'设置教练是谁、如何辅导、禁止做什么。保存后，从下一则消息起套用到所有成员。',
 instr_ph:'在此粘贴教练指示：教练是谁、如何辅导、不可以做什么，以及回复语言。',save_instr:'保存指示',
 instr_line:(v,w,b)=>'第 '+v+' 版，由 '+b+' 于 '+w+' 保存',
 ver_h:'历史版本',ver_p:k=>'保留最近 '+k+' 次保存。还原某一版会将它另存为最新版本。',
 ver:v=>'第 '+v+' 版',cur:'（目前）',rest_from:v=>'（还原自第 '+v+' 版）',restore:'还原',none_saved:'尚无保存纪录。',
 confirm_restore:v=>'要还原第 '+v+' 版吗？它会成为新的目前版本。',
 kb_h:'知识库（PDF 文档）',kb_p:'教练每次回答都会阅读全部文档。上传同名文件会取代旧档。可随时添加、取代或删除，下一则消息就会使用新内容。',
 drop:'点击选择 PDF，或一次拖放多个文件到这里',kb_none:'尚未有 PDF。',pages:'页',del:'删除',
 confirm_del:n=>'要从教练的知识库删除「'+n+'」吗？',
 kb_status:(n,sz,pg)=>n+' 份 PDF，'+sz+'，约 '+pg+' 页（估算）。',
 kb_usage:(i,c)=>' 上一个问题使用了 '+i+' 个输入 token（其中 '+c+' 个来自 Gemini 缓存）。',
 kb_big:' 内容偏大，建议移除不再需要的文档。',
 uploading:'上传中…',added:'已添加',replaced:'已取代',failed:'失败',
 notset_banner:'尚未设置：保存教练指示之前，成员会看到「教练正在设置中」。你随时可在「教练对话」分页测试。',
 r7:'最近 7 天',r30:'最近 30 天',rall:'全部时间',
 t_q:'提问数',t_a:'使用人数',t_h:'转交领袖',
 nudge_h:'需要提醒',nudge_p:'7 天内没有向教练提问的成员。',nudge_none:'目前没有人需要提醒。',
 last_asked:w=>'上次提问 '+w,never_used:'从未使用',
 ppl_h:'成员',ppl_p:'点击名字查看提问。你只会看到问题，看不到教练的回答。',
 th_name:'姓名',th_q:'提问数',th_h:'转交',th_last:'最后活动',no_q:'尚无提问。',
 loading:'加载中…',to_leader:'已转交领袖',no_q_period:'此期间没有提问。',never:'从未',loc:'zh-CN'
},
en:{
 test_btn:'Test connection',testing:'Testing… (up to about a minute)',test_all_ok:'Everything works.',test_bad:'Something is wrong. Send a screenshot of this result.',
 sub:'Objection handling, better scripts, client role-play',lang_btn:'简体中文',
 tab_chat:'Chat',tab_settings:'Settings',tab_usage:'Usage',
 ph_msg:'Type your question…',send:'Send',typing:'Coach is typing…',
 chip1:'A client says insurance is worse than investing. How do I reply?',chip2:'I want to practise with a type A founder, difficulty 2',
 note:'During a role-play, type "pause" for a hint and "end drill" for the scorecard. ⚠️ Do not enter real client names or personal data. Your chats are private to you. Your team leader can see the questions you ask, but not the coach\'s answers.',
 hi:n=>'Hi '+n+'! Which scenario would you like to practise today, or which objection should we break down?',
 setup:'The coach is being set up. Check back soon.',
 notset_admin:'Not set up yet: add instructions in Settings. You can still chat here to test.',
 wa_btn:'Ask my leader on WhatsApp',ask_leader:'Please ask your team leader about this one.',
 wa_msg:(n,q)=>'Hi, this is '+n+'. I asked the HNW Coach: "'+q+'" and need your help.',
 conn_h:'Connection',conn_p:'Paste the Gemini API key from Google AI Studio here. It is kept on the server, never shown again and never sent to members. Leave the key box empty to keep the saved key.',
 key_ph:'Paste a new Gemini API key',wa_ph:"Leader's WhatsApp number, country code first (e.g. 60123456789)",
 save_conn:'Save connection',clear_key:'Remove saved key',saved:'Saved.',key_removed:'Key removed.',
 key_saved:l=>'Gemini key saved (ends in <b>'+l+'</b>)',key_file:'. It comes from a file on the server, so it cannot be changed here.',
 key_none:'<b class="warn">No Gemini key yet.</b> The coach cannot answer until you add one.',
 confirm_clear:'Remove the saved Gemini key? The coach stops answering until you add one again.',
 instr_h:'Coach instructions',instr_p:'How the coach should behave: who it is, how it coaches, and what it must never do. Changes apply from the next message any member sends.',
 instr_ph:'Paste the coach instructions here: who the coach is, how it coaches, what it must never do, and the language to reply in.',save_instr:'Save instructions',
 instr_line:(v,w,b)=>'Version '+v+' saved '+w+' by '+b,
 ver_h:'Earlier versions',ver_p:k=>'The last '+k+' saves are kept. Restoring one saves it as the newest version.',
 ver:v=>'Version '+v,cur:' (current)',rest_from:v=>' (restored from '+v+')',restore:'Restore',none_saved:'Nothing saved yet.',
 confirm_restore:v=>'Restore version '+v+'? It becomes the new current version.',
 kb_h:'Knowledge (PDF documents)',kb_p:'The coach reads all of these for every question. Uploading a file with the same name replaces the old one. Add, replace or delete them any time; the next message uses the new set.',
 drop:'Tap to choose PDFs, or drop several here at once',kb_none:'No PDFs yet.',pages:'pages',del:'Delete',
 confirm_del:n=>'Delete "'+n+'" from the coach\'s knowledge?',
 kb_status:(n,sz,pg)=>n+' PDF'+(n===1?'':'s')+', '+sz+', about '+pg+' pages (estimate).',
 kb_usage:(i,c)=>' Last question used '+i+' input tokens ('+c+' reused from Gemini\'s cache).',
 kb_big:' This is getting very large for one request. Consider removing documents you no longer need.',
 uploading:'uploading…',added:'added',replaced:'replaced',failed:'failed',
 notset_banner:'Not set up yet. Members see "The coach is being set up" until you save instructions. You can chat in the Chat tab to test at any time.',
 r7:'Last 7 days',r30:'Last 30 days',rall:'All time',
 t_q:'Questions asked',t_a:'People using it',t_h:'Handed to leader',
 nudge_h:'Needs a nudge',nudge_p:'Members who have not asked the coach anything in 7 days.',nudge_none:'Nobody is waiting. Nice.',
 last_asked:w=>'last asked '+w,never_used:'never used',
 ppl_h:'People',ppl_p:"Tap a name to see their questions. You see questions only, never the coach's answers.",
 th_name:'Name',th_q:'Questions',th_h:'Handoffs',th_last:'Last active',no_q:'No questions yet.',
 loading:'Loading…',to_leader:'sent to leader',no_q_period:'No questions in this period.',never:'never',loc:'en'
}};
let LANG='zh';try{LANG=localStorage.getItem('hnwlang')==='en'?'en':'zh'}catch(e){}
const t=(k,...a)=>{const v=(T[LANG][k]!==undefined?T[LANG][k]:T.en[k]);return typeof v==='function'?v(...a):v};

const $=s=>document.querySelector(s);
const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const when=ts=>ts?new Date(ts*1000).toLocaleString(t('loc'),{dateStyle:'medium',timeStyle:'short'}):t('never');
const mb=n=>(n/1048576).toFixed(1)+' MB';
let ME={name:'',is_admin:false,ready:false,whatsapp:''};

async function api(action,{method='GET',json,query=''}={}){
  const opt={method,headers:{'X-Requested-With':'fetch','X-Coach-Lang':LANG},credentials:'same-origin'};
  if(json!==undefined){opt.headers['Content-Type']='application/json';opt.body=JSON.stringify(json)}
  const r=await fetch('api.php?action='+action+query,opt);
  let d={};try{d=await r.json()}catch(e){}
  if(!r.ok){const err=new Error(d.error||('Error ('+r.status+')'));err.detail=d.detail;err.status=r.status;throw err}
  return d;
}

/* ---- language ---- */
function applyLang(){
  document.documentElement.lang=LANG==='zh'?'zh-Hans':'en';
  document.querySelectorAll('[data-i]').forEach(e=>{e.textContent=t(e.dataset.i)});
  document.querySelectorAll('[data-ip]').forEach(e=>{e.placeholder=t(e.dataset.ip)});
  $('#lang').textContent=t('lang_btn');
  $('#chip1').textContent=$('#chip1').dataset.t=t('chip1');
  $('#chip2').textContent=$('#chip2').dataset.t=t('chip2');
}
$('#lang').addEventListener('click',async()=>{
  LANG=LANG==='zh'?'en':'zh';try{localStorage.setItem('hnwlang',LANG)}catch(e){}
  applyLang();renderBanner();
  log.innerHTML='';try{await loadChat()}catch(e){addErr(e.message)}
  const on=document.querySelector('.tab.on');
  if(on&&on.dataset.tab==='settings')loadSettings();
  if(on&&on.dataset.tab==='usage')loadUsage();
});
function renderBanner(){
  if(!ME.ready&&!ME.is_admin){$('#chat-banner').innerHTML='<div class="banner">'+esc(t('setup'))+'</div>'}
  else if(!ME.ready){$('#chat-banner').innerHTML='<div class="banner warn">'+esc(t('notset_admin'))+'</div>'}
  else $('#chat-banner').innerHTML='';
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
function addUser(x){const d=document.createElement('div');d.className='msg user';d.textContent=x;log.appendChild(d);scrollEnd()}
function addCoach(x,handoff,question){
  const d=document.createElement('div');d.className='msg coach';d.innerHTML=rich(x);
  if(handoff){
    if(ME.whatsapp){
      const a=document.createElement('a');a.className='wa';a.target='_blank';a.rel='noopener';
      a.href='https://wa.me/'+ME.whatsapp+'?text='+encodeURIComponent(t('wa_msg',ME.name,question));
      a.textContent=t('wa_btn');d.appendChild(a);
    }else{const p=document.createElement('p');p.textContent=t('ask_leader');d.appendChild(p)}
  }
  log.appendChild(d);scrollEnd();
}
function addErr(x){const d=document.createElement('div');d.className='msg err';d.textContent=x;log.appendChild(d);scrollEnd()}

async function loadChat(){
  const {messages}=await api('history');
  let lastQ='';
  for(const m of messages){
    if(m.role==='user'){lastQ=m.text;addUser(m.text)}else addCoach(m.text,m.handoff,lastQ);
  }
  if(!messages.length)addCoach(ME.ready||ME.is_admin?t('hi',ME.name):t('setup'),false,'');
}
function setBusy(b){$('#send').disabled=b;$('#msg').disabled=b}
$('#form').addEventListener('submit',async e=>{
  e.preventDefault();
  const ta=$('#msg'),text=ta.value.trim();if(!text)return;
  ta.value='';ta.style.height='';addUser(text);
  const ty=document.createElement('div');ty.className='typing';ty.textContent=t('typing');log.appendChild(ty);scrollEnd();
  setBusy(true);
  try{const d=await api('chat',{method:'POST',json:{message:text}});ty.remove();addCoach(d.reply,d.handoff,text)}
  catch(err){ty.remove();addErr(err.message+(err.detail&&ME.is_admin?' ['+err.detail+']':''))}
  setBusy(false);ta.focus();
});
$('#msg').addEventListener('keydown',e=>{
  if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing&&e.keyCode!==229){e.preventDefault();$('#form').requestSubmit()}
});
$('#msg').addEventListener('input',e=>{e.target.style.height='auto';e.target.style.height=Math.min(e.target.scrollHeight,160)+'px'});
document.querySelectorAll('.chip').forEach(c=>c.addEventListener('click',()=>{const ta=$('#msg');ta.value=c.dataset.t;ta.focus()}));

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
    +(s.instructions?'':'<div class="banner">'+esc(t('notset_banner'))+'</div>');
  if(document.activeElement!==$('#instr'))$('#instr').value=s.instructions?s.instructions.text:'';
  $('#instr-msg').textContent=s.instructions?t('instr_line',s.instructions.version,when(s.instructions.saved_at),s.instructions.saved_by):'';
  $('#ver-sub').textContent=t('ver_p',s.keep_versions);
  $('#versions').innerHTML=s.versions.length?s.versions.map((v,i)=>
    '<div class="item"><div class="grow"><b>'+esc(t('ver',v.version))+'</b>'+(i===0?esc(t('cur')):'')+(v.restored_from?esc(t('rest_from',v.restored_from)):'')+'<small>'+esc(when(v.saved_at))+' · '+esc(v.saved_by)+'</small><small>'+esc(v.preview)+'</small></div>'
    +(i===0?'':'<button class="btn ghost small" data-restore="'+v.version+'">'+esc(t('restore'))+'</button>')+'</div>').join(''):'<div class="item">'+esc(t('none_saved'))+'</div>';
  renderConn(s.connection);
  renderKb(s);
}
function renderConn(c){
  $('#conn-status').innerHTML=c.key_set?(t('key_saved',esc(c.key_last4))+(c.key_from_server_file?esc(t('key_file')):'')):t('key_none');
  if(document.activeElement!==$('#wa'))$('#wa').value=c.whatsapp||'';
  $('#clear-key').classList.toggle('hide',!c.key_set||c.key_from_server_file);
}
$('#save-conn').addEventListener('click',async()=>{
  const b=$('#save-conn');b.disabled=true;
  try{const r=await api('save_settings',{method:'POST',json:{gemini_api_key:$('#key').value.trim(),whatsapp:$('#wa').value}});
    $('#key').value='';renderConn(r.connection);$('#conn-msg').textContent=t('saved');await loadSettings()}
  catch(e){$('#conn-msg').innerHTML='<span class="bad">'+esc(e.message)+'</span>'}
  b.disabled=false;
});
$('#test-conn').addEventListener('click',async()=>{
  const b=$('#test-conn'),out=$('#test-out');b.disabled=true;out.innerHTML='<p class="sub">'+esc(t('testing'))+'</p>';
  try{const r=await api('test_connection',{method:'POST',json:{}});
    const bad=r.steps.filter(x=>!x.ok).length;
    out.innerHTML='<div class="list">'+r.steps.map(x=>'<div class="item"><div class="grow"><b class="'+(x.ok?'ok':'bad')+'">'+(x.ok?'✔':'✘')+'</b> '+esc(x.name)+'<small>'+esc(x.detail)+'</small></div></div>').join('')+'</div><p class="sub '+(bad?'bad':'ok')+'">'+esc(bad?t('test_bad'):t('test_all_ok'))+'</p>'}
  catch(e){out.innerHTML='<div class="banner bad">'+esc(e.message+(e.detail?' ['+e.detail+']':''))+'</div>'}
  b.disabled=false;
});
$('#clear-key').addEventListener('click',async()=>{
  if(!confirm(t('confirm_clear')))return;
  try{const r=await api('save_settings',{method:'POST',json:{clear_key:true}});renderConn(r.connection);$('#conn-msg').textContent=t('key_removed');await loadSettings()}
  catch(e){$('#conn-msg').innerHTML='<span class="bad">'+esc(e.message)+'</span>'}
});
function renderKb(s){
  $('#kb').innerHTML=s.knowledge.length?s.knowledge.map(f=>
    '<div class="item"><div class="grow">'+esc(f.name)+'<small>'+mb(f.size)+(f.pages?' · '+f.pages+' '+esc(t('pages')):'')+' · '+esc(when(f.uploaded_at))+'</small></div><button class="btn ghost small" data-del="'+esc(f.name)+'">'+esc(t('del'))+'</button></div>').join(''):'<div class="item">'+esc(t('kb_none'))+'</div>';
  const st=s.status;let line=t('kb_status',st.pdf_count,mb(st.total_bytes),st.est_pages);
  if(st.last_usage)line+=t('kb_usage',st.last_usage.input.toLocaleString(),st.last_usage.cache_read.toLocaleString());
  $('#kb-status').innerHTML=esc(line)+(st.warn_too_big?' <b class="warn">'+esc(t('kb_big'))+'</b>':'');
}
$('#save-instr').addEventListener('click',async()=>{
  const b=$('#save-instr');b.disabled=true;
  try{await api('save_instructions',{method:'POST',json:{text:$('#instr').value}});ME.ready=true;renderBanner();await loadSettings();$('#instr-msg').textContent=t('saved')+' '+$('#instr-msg').textContent}
  catch(e){$('#instr-msg').innerHTML='<span class="bad">'+esc(e.message)+'</span>'}
  b.disabled=false;
});
$('#versions').addEventListener('click',async e=>{
  const v=e.target.dataset.restore;if(!v||!confirm(t('confirm_restore',v)))return;
  try{await api('restore_instructions',{method:'POST',json:{version:+v}});$('#instr').value='';await loadSettings()}catch(err){alert(err.message)}
});
$('#kb').addEventListener('click',async e=>{
  const n=e.target.dataset.del;if(!n||!confirm(t('confirm_del',n)))return;
  try{await api('delete_pdf',{method:'POST',json:{name:n}});await loadSettings()}catch(err){alert(err.message)}
});
const drop=$('#drop'),fileIn=$('#file');
drop.addEventListener('click',()=>fileIn.click());
['dragover','dragenter'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.add('over')}));
['dragleave','drop'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.remove('over')}));
drop.addEventListener('drop',e=>upload([...e.dataTransfer.files]));
fileIn.addEventListener('change',()=>{upload([...fileIn.files]);fileIn.value=''});
async function b64(file){
  const buf=new Uint8Array(await file.arrayBuffer());let bin='';
  for(let i=0;i<buf.length;i+=0x8000)bin+=String.fromCharCode.apply(null,buf.subarray(i,i+0x8000));
  return btoa(bin);
}
async function upload(files){
  files=files.filter(f=>/\.pdf$/i.test(f.name)||f.type==='application/pdf');
  const box=$('#up-results');box.innerHTML='';
  for(const f of files){ // one request per file: a bad file never blocks the others
    const row=document.createElement('div');row.className='sub';row.textContent=f.name+': '+t('uploading');box.appendChild(row);
    try{
      const r=await api('upload_pdf',{method:'POST',json:{name:f.name,data:await b64(f)}});
      row.innerHTML=esc(r.name||f.name)+': '+(r.ok?'<b class="ok">'+esc(r.status==='replaced'?t('replaced'):t('added'))+'</b>':'<b class="bad">'+esc(t('failed'))+'</b> '+esc(r.error));
    }catch(err){row.innerHTML=esc(f.name)+': <b class="bad">'+esc(t('failed'))+'</b> '+esc(err.message)}
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
  const tl=u.tiles;
  $('#tiles').innerHTML=[[t('t_q'),tl.questions],[t('t_a'),tl.agents_using],[t('t_h'),tl.handoffs]].map(x=>'<div class="tile"><b>'+x[1]+'</b><span>'+esc(x[0])+'</span></div>').join('');
  $('#nudge').innerHTML=u.needs_nudge.length?u.needs_nudge.map(p=>'<div class="item"><div class="grow">'+esc(p.name)+'</div><small>'+esc(p.last_active?t('last_asked',when(p.last_active)):t('never_used'))+'</small></div>').join(''):'<div class="item">'+esc(t('nudge_none'))+'</div>';
  $('#people').innerHTML='<tr><th>'+esc(t('th_name'))+'</th><th>'+esc(t('th_q'))+'</th><th>'+esc(t('th_h'))+'</th><th>'+esc(t('th_last'))+'</th></tr>'
    +(u.people.length?u.people.map(p=>'<tr><td><button class="link" data-m="'+p.mid+'" data-n="'+esc(p.name)+'">'+esc(p.name)+'</button></td><td>'+p.questions+'</td><td>'+p.handoffs+'</td><td>'+esc(when(p.last_active))+'</td></tr>').join(''):'<tr><td colspan="4">'+esc(t('no_q'))+'</td></tr>');
}
$('#people').addEventListener('click',async e=>{
  const m=e.target.dataset.m;if(!m)return;
  const box=$('#person');box.classList.remove('hide');box.innerHTML=esc(t('loading'));
  try{
    const d=await api('usage_member',{query:'&m='+m+'&range='+range});
    box.innerHTML='<h2>'+esc(e.target.dataset.n)+'</h2>'+(d.questions.length?d.questions.map(q=>'<div class="q">'+esc(q.q)+'<br><small>'+esc(when(q.ts))+(q.handoff?' · <b class="warn">'+esc(t('to_leader'))+'</b>':'')+'</small></div>').join(''):'<p class="sub">'+esc(t('no_q_period'))+'</p>');
  }catch(err){box.innerHTML='<div class="banner bad">'+esc(err.message)+'</div>'}
});

/* ---- start ---- */
(async()=>{
  applyLang();
  try{ME=await api('me')}catch(e){addErr(e.message);setBusy(true);return}
  if(ME.is_admin)$('#tabs').classList.remove('hide');
  renderBanner();
  if(!ME.ready&&!ME.is_admin)setBusy(true);
  try{await loadChat()}catch(e){addErr(e.message)}
})();
})();
</script>
<script src="/members/tool-tab.js" defer></script>
</body>
</html>
