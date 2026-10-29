# Policy Summary: Phase 1 install and calculator patches

Phase 1 is one self-contained page: `index.html`. It runs entirely in the browser, stores nothing, and has no server part. Phase 2 (saving, sharing, super admin) and Phase 3 (AI auto-fill) are still to do; see `BRIEF-policy-summary.md`.

## 1. Install

1. In cPanel File Manager, create `public_html/members/policy-summary/`.
2. Upload `index.html` into it.
3. Open `/members/policy-summary/` while signed in through Cloudflare Access.
4. Add the toolkit card (below).

PDF reading loads PDF.js 3.11.174 from `cdnjs.cloudflare.com` the first time a PDF is uploaded. If the network blocks that, the tool still works with manual entry.

## 2. Toolkit card: `public_html/members/apps.js`

Insert as the first entry in "Agent Tools" (above Protection Needs Calculator):

```js
  {
    name:  "Policy Summary",
    url:   "/members/policy-summary/",
    icon:  "📋",
    about: "Turn a client's policies from any insurer into a one-page coverage summary with charts",
    group: "Agent Tools",
    note:  "Client-facing. PDFs are read in your browser; saving client data is optional."
  },
```

## 3. Patch: Protection Needs Calculator (`protection-needs/index.html`)

The Policy Summary opens it with numbers only (no names):
`?from=summary&anb=42&income=180000&life=600000&group=120000&mrta=0&ci=300000`

At the very end of the main script, replace the last two lines

```js
$('adj').checked=true;
load(SAMPLE);
```

with

```js
$('adj').checked=true;
(function(){
  const q=new URLSearchParams(location.search);
  if(q.get('from')==='summary'){
    const v={};
    ['anb','income','life','group','mrta','ci'].forEach(k=>{const x=q.get(k);if(x&&/^\d+$/.test(x))v[k]=x;});
    load(Object.assign({},DEFAULTS,v));
    const n=document.createElement('p');
    n.className='calcline';
    n.innerHTML='<strong>Carried over from the Policy Summary:</strong> age, income and existing cover are filled in. Check them, then complete steps 1 to 3.';
    document.querySelector('.steps').before(n);
  }else{
    load(SAMPLE);
  }
})();
```

Note: `life` fills "Personal life cover", `group` fills "Employer group cover", `mrta` fills "MRTA / MLTA", `ci` fills "Existing critical illness cover". Nothing is loaded from the sample data when figures arrive.

## 4. Patch: Sum Assured Calculator (`sum-assured/index.html`)

The Policy Summary opens it with `?from=summary&anb=42&inc=180000&exL=720000&exD=300000`.

In the last script block ("Figures passed from the Protection Needs Calculator"), replace

```js
  if(q.get('from')!=='needs') return;
```

with

```js
  const fr=q.get('from');
  if(fr!=='needs'&&fr!=='summary') return;
```

and replace the line that builds `$('#from').innerHTML=...` with

```js
  const src=fr==='summary'?'Policy Summary':'Protection Needs Calculator';
  $('#from').innerHTML=`<div class="card from"><b>Carried over from the ${src}.</b><br>${fr==='summary'?'Existing Life/TPD adds up personal, group and MRTA/MLTA cover, and existing CI is the total critical illness cover.':'Proposed cover is the protection gap and CI gap from that calculator. Existing Life/TPD adds up personal, group and MRTA/MLTA cover.'} Edit any figure here if needed.</div>`;
```

## 5. Privacy fix in the existing needs to sum assured link

`protection-needs/index.html` currently puts the client's profile name in the URL (`name:$('#pname').value||''`). Delete that property from the `URLSearchParams` object, and in `sum-assured/index.html` delete the `name` lines (`const name=q.get('name');` and the `${name?' for '+esc(name):''}` part). URLs end up in browser history and logs. Numbers are fine; names are not.

## 6. What Phase 1 covers

- Step 1 client details, Step 2 upload or manual entry, Step 3 check each policy, Step 4 summary.
- PDF text read in the browser, with rule-based auto-fill for: insurer, policy number, plan, life assured, owner, nominee (ID numbers stripped), start date, premium and frequency, status, benefits (death/TPD, CI early and advanced, medical with room and board, hospital income, personal accident), cash value, maturity benefit. Every auto-filled field is marked ✓ / ? / ✗ and must be checked.
- Scanned PDFs are detected and sent to manual entry.
- Gap guides from the brief (section 9), held in the `RULES` object near the top of the script.
- Summary with tiles, coverage-against-guide bars, premium chart, policy list, cover timeline, savings and maturity, things to note, and the full disclaimer. Prints to A4 with the disclaimer footer on every page.
- Hand-off buttons to both calculators (after the patches above).

Not in Phase 1: storing, sharing, super admin screens, AI auto-fill, "Prepared by" from login (typed in for now).
