# Monthly Report Messages (E3 members area)

Single-page tool. Reads the monthly production Excel from the admin and writes two reports: one for the mentor, one for the team. Each can be sent as **text** or as a **graphic poster** (1080x1350 PNG, three styles). No server code, no build step. The Excel is read in the browser and never uploaded.

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
4. Team tab: press "Write a draft message" for the comment, then edit.
5. Pick **Text** or **Graphic poster** (and a style). The preview shows exactly what will go out.
6. Text: Copy message, or Send via WhatsApp. Graphic: Share to WhatsApp, Download PNG, or Copy image.

### Sending a poster
A `wa.me` link can only carry text, so a picture cannot be pre-attached from a web page.
- **Phone:** Share to WhatsApp opens the phone's share sheet with the PNG attached (Web Share API).
- **Computer:** Download PNG or Copy image, then attach or paste it in WhatsApp Web. Browsers without file sharing fall back to a download.

Nothing is ever sent automatically. You still tap send inside WhatsApp.

### Motivational message
"Write a draft message" analyses the month: Active Ratio against 50%, Average Case Size against 3,000, and DG ANP against last month (up 10% or more counts as a win, down 20% or more is flagged). It writes the comment in the same style as the September team message, naming the quarter or month ahead. Press again for another version. Names of individual people appear only in the on-screen analysis, never in the message or poster.

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
