# Monthly Report Messages (E3 members area)

Single-page tool. Reads the monthly production Excel from the admin and writes two WhatsApp messages: one for the mentor, one for the team. No server code, no build step. The Excel is read in the browser and never uploaded.

## Deploy

1. cPanel File Manager → `public_html/members/` → create folder `monthly-report`.
2. Upload `index.html` into it. Do not upload `test.cjs` or this README.
3. Served at `/members/monthly-report/`, behind the same Cloudflare Access rule as the other toolkits (check the Zero Trust application path covers `/members/*`).
4. Add the card to Leader Tools in `/members/` (see below).
5. Rollback: delete the folder.

## Leader Tools card

Copy an existing card in the Leader Tools section of the members page and change three things: the link, the title, the description.

```html
<a class="card" href="/members/monthly-report/">
  <h3>Monthly Report Messages</h3>
  <p>Upload the production Excel, get the mentor and team WhatsApp messages.</p>
</a>
```

The class names above are a guess. This repo does not contain the members page, so match whatever the neighbouring cards use.

## Monthly use

1. Choose the Excel. The newest month tab is picked automatically.
2. Check the figures table and the green or amber status line.
3. Update manpower, FT/PT, DL1/2/4, self-review and next-month plan. Each month starts from last month's answers.
4. Team tab: press "Write a draft from this month's figures" for the comment, then edit.
5. Copy message, or Send via WhatsApp.

## Rules built in

| Item | Source |
|---|---|
| PS QFYLP and PS ANP | Your row (agent account no. in Settings): month column and YTD column |
| DG PFYLP and DG ANP | Sum of all people, so a wrong Total row in the file cannot leak in. The page warns when the file's Total row disagrees |
| Monthly goal | Goal x that month's On Track % |
| ACC goal | Goal x running total of On Track % (Sep 68%, Oct 78%) |
| Percentages | Normal rounding to 2 decimals |
| Active agent | People with NOC above 0 that month, out of everyone listed. Can be overridden |
| Unit NOC | Team total NOC: month and YTD |
| Active Ratio and Average Case Size | Active ÷ people listed; team ANP ÷ team NOC |

Typed by hand each month: manpower, FT, PT, DL1/2/4, self-review, plan, comment.
Settings (saved in the browser): headers, goals, On Track %, WU and NR goal lines, closing lines, agent account no.

Everything is stored in browser localStorage (`monthlyReport.v1`), per device. Clearing site data resets it.

## Test

```
npm i xlsx@0.18.5          # in a scratch folder, not here
node monthly-report/test.cjs <production.xlsx> <path/to/xlsx.full.min.js>
```

Needs Playwright. Opens the page offline, loads the real file, and checks September against the sample messages and October against the figures in this note, at desktop and phone width.

## Known

- Feb 2026 tab in the 2026 file has the wrong monthly FY_Comm total (9,066.22, copied from January; the people add up to 9,457.45). Not used in the messages.
- The header reads "吳昌輝 UM3 …" and "DreamHub E3 …". Change them in Settings if the mentor or team name changes.
