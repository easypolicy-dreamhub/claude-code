# Brief: Policy Summary tool (DreamHub E3 members area)

Written 29 Oct 2026. This is the build brief for a new tool in the E3 Advisor Toolkit. It was agreed with the owner (a DreamHub E3 financial advisor and the toolkit's super admin) in a planning session. Build from this document; where it says "ask the owner", do that rather than guess.

## 1. What it is

A tool that turns a client's insurance policies, from **any insurer**, into one clear, branded summary: what they have, what it pays, what they pay, and where the gaps are. Advisors use it in client meetings, for prospect portfolio reviews and for annual reviews. The result prints to a 2-page PDF for the client.

Data gets in two ways:
1. **Upload a policy PDF.** Text is read in the browser and fields are auto-filled where the tool is confident. The advisor checks every field.
2. **Type it in manually.**

Optional later: an **AI auto-fill** button using Cloudflare Workers AI (free plan).

Image or photo scanning is **out of scope**.

Users: **team members only** (advisors in the Team tree). There is no staff or admin-without-tier role.

## 2. Where it fits

| Thing | Value |
|---|---|
| URL | `/members/policy-summary/` |
| cPanel path | `public_html/members/policy-summary/` |
| Hosting | cPanel (PHP + MySQL). Whole `/members/` folder is locked by **Cloudflare Zero Trust (Access)** |
| Database | Existing `dreamhub_members` (same as Career Code / Team) |
| Toolkit card | `public_html/members/apps.js`, entry in §14 |
| Related tools | Protection Needs Calculator `/members/protection-needs/`, Sum Assured Calculator `/members/sum-assured/` |

## 3. Match the existing toolkit conventions

Copy these from `sum-assured` / `protection-needs` (the owner can supply the files):

- One `index.html` per tool. Calculation and PDF reading run in the browser.
- `<style id="e3-theme">` brand layer: DM Sans body, Poppins headings, `--ink:#24333A`, background `#F4F2EF`, line `#E2DDD8`, olive accent `#5E6B40`, taupe `#B9AFA7`. `color-scheme: light only`.
- `<style id="e3-header">` title strip and the `<nav class="e3-bar">` top bar ("‹ E3 Toolkit" back link to `/members/`, "Dream**Hub** E3" brand), hidden in print.
- The `<script id="e3-tabfix">` Tab-key script, copied verbatim.
- Step tabs like the Protection Needs Calculator (1 Client, 2 Policies, 3 Review, 4 Summary).
- Money inputs: `RM` prefix, thousands separators while typing, `inputmode="numeric"`.
- Print or save as PDF through the browser (`window.print()` with print CSS), not a PDF library.
- Mobile first: works one-handed on a phone; side-by-side layouts only on wider screens.
- Charts: prefer CSS/SVG bars in the same style as the Protection Needs Calculator. A pinned charting library from cdnjs is acceptable if needed.

## 4. Architecture

```
Browser (index.html)
 ├─ PDF.js: reads PDF text locally. The PDF file is never uploaded.
 ├─ Rule-based auto-fill + manual form
 ├─ Summary, charts, print-to-PDF   ← all of this works with NO server (don't-store mode)
 └─ fetch → api.php (only for: store, list, share, delete, AI auto-fill)
              ├─ verifies the Cloudflare Access token (Cf-Access-Jwt-Assertion)
              ├─ maps email → member using the existing members table
              ├─ MySQL (dreamhub_members): new tables in §11
              └─ optional: calls Cloudflare Workers AI with the extracted TEXT
```

- **Reuse the existing identity + database code** that Career Code / Team use (JWT verification, member lookup, "is X above Y in the tree" helper, `audit_log` writer). **Ask the owner for those PHP files** (with credentials removed). Do not write a second auth system, and do not trust a plain email header: verify the signed token.
- Every permission check is enforced **on the server**. The UI hiding a button is not enough.
- Keep secrets (DB credentials, encryption key, Workers AI token) **outside `public_html`**, the same way the Agency Report keeps photos in a folder above `public_html`.

## 5. Screens

### 5.1 Home: My summaries
- `[ + New summary ]` button.
- Tabs: **My summaries** | **Shared with me (n)**.
- Search by client name.
- Each row: client name, number of policies, last updated, share badge (e.g. "🔗 Shared with Raj, until 27 Nov").
- Footer line: "AI auto-fill today: X left" (only once §8 is built).
- Only **stored** summaries appear. A don't-store summary warns before the page is closed or reloaded (`beforeunload`).

### 5.2 Step 1: Client
- Client / household name (required), e.g. "Mr & Mrs Lee".
- Age next birthday (required), annual income (required), income type: salaried | self-employed / commission (required), retirement age (optional, default 60).
- "Prepared by" = the signed-in advisor's name, read only.
- **Save this summary?**
  - ( ) Don't store. Generate only.
  - ( ) Store for future reviews → requires ☑ **Client consent obtained** before it can be selected. Optional ☑ **Supervisor may review** (needed later to share).
  - Show the consent text in §12.3 so the advisor can read it to the client.
- A summary can cover **one person or a family**: life assured is captured per policy.

### 5.3 Step 2: Policies
- Upload area: tap to choose or drop; several PDFs at once.
- `[ ✍️ Add a policy manually ]`.
- Policy list with status: ✅ Confirmed · ⚠️ Auto-filled, N fields to check · ✍️ Scanned PDF, no text found, enter manually.
- Only confirmed policies go into the summary.

### 5.4 Step 3: Check a policy (key screen)
- Phone: tabs **Form** | **PDF text**. Wider screens: side by side.
- Each auto-filled field shows ✓ confident, ? please check, ✗ not found.
- On the PDF text tab the advisor selects text and taps **Use for → [field]** to fill a field.
- Benefits table: category, amount, ends at age; `[ + Add benefit ]`. Medical rows show the extra medical fields (§6.3).
- Optional `[ ✨ AI auto-fill ]` (§8).
- Advisor reminder (§12.4) shown above the Confirm button.
- `[ Confirm policy ✅ ]`.

### 5.5 Step 4: Summary
- 4 tiles: total cover, total premium per year, number of policies, number of gaps (🔴 count).
- **Coverage vs guide**: one bar per category (Death/TPD, CI, Medical, Hospital income, Personal accident) showing current against the guide, with a 🟢 / 🟠 / 🔴 status (§9).
- **Things to note**: the general flags (§9.2).
- **Premium by policy**: bar chart.
- **Cover timeline**: per policy/benefit, a bar from now to the age cover ends, against the client's age and retirement age.
- **Savings / maturity**: per policy, current value and maturity benefit (guaranteed / non-guaranteed if split).
- **Policy list** table.
- Buttons: `Print / Save PDF` · `Run protection needs analysis ›` · `Check underwriting limit ›` · `Share` (stored only) · `Delete` (stored only).

### 5.6 Share dialog
- "Share '<client>' with": radio list of the advisor's **upline chain** only (everyone above them in the Team tree, including the super admin).
- Duration: 7 / 30 (default) / 90 days.
- Text: "They can view and print only. You can stop sharing any time."
- Disabled, with the reason shown, if "Supervisor may review" was not ticked.

### 5.7 Super admin screens
- **Gap rules settings**: edit the values in §9.1.
- **All summaries**: owner, created/updated, number of policies. **No client details.**
- **Frozen summaries** (owner left or removed): client name, owner, number of policies, days until auto-delete, `[ Transfer to… ]` (active member) and `[ Delete ]`.

## 6. Data captured

### 6.1 Client
| Field | Required | Notes |
|---|---|---|
| Client / household name | yes | |
| Age next birthday | yes | |
| Annual income (RM) | yes | |
| Income type | yes | salaried / self_employed |
| Retirement age | no | default 60 |
| Prepared by | auto | from login |

### 6.2 Policy
| Field | Required | Notes |
|---|---|---|
| Insurer | yes | dropdown of Malaysian insurers and takaful operators, plus "Other" |
| Plan name | yes | |
| Policy number | no | PDF shows **last 4 digits only** |
| Policy type | yes | life, investment-linked, medical, personal accident, group, MRTA/MLTA, takaful, other |
| Life assured | yes | name |
| Policy owner | no | |
| Start date | yes | |
| Cover ends (age or date) | yes | |
| Premium amount + frequency | yes | monthly, quarterly, half-yearly, annual, single |
| Premium paying until (age or date) | no | |
| Status | yes | in force, lapsed, paid-up, surrendered |
| Group (employer) cover | yes | yes/no |
| Nominee / beneficiary | no | **name and relationship only** |
| Current cash / fund value + "as at" date | no | |
| Maturity date or age | no | |
| Maturity benefit | no | **the amount stated in the policy**, keyed in or auto-filled. **No projection rate field.** |
| Maturity benefit split | no | guaranteed / non-guaranteed amounts, only if the policy states a split |
| Notes | no | free text |

### 6.3 Benefit (many per policy)
| Field | Required | Notes |
|---|---|---|
| Category | yes | death_tpd, ci_early, ci_advanced, medical, hospital_income, personal_accident, other |
| Amount | yes | sum assured; for medical, the annual limit; for hospital income, per day |
| Ends at age | yes | riders often end before the main policy |
| Medical only: lifetime limit / unlimited | | |
| Medical only: room & board per day | | |
| Medical only: deductible / co-payment | | information only |

### 6.4 Deliberately NOT captured
NRIC numbers (client or nominee), medical history / exclusions / loadings, nominee contact details, bank or payment details, and **the uploaded PDF itself** (read in the browser, then discarded). If extraction finds these, ignore them.

## 7. PDF reading and rule-based auto-fill (free, in the browser)

- Use **PDF.js** (pinned version from cdnjs) to extract text with positions, page by page.
- **Scanned PDF detection**: if a PDF yields almost no text, mark it "Scanned PDF, no text found. Please enter manually." Do not attempt OCR.
- Auto-fill with rules, and mark each field's confidence:
  - Insurer: match known insurer names and logos' alt text in the text.
  - Policy number, dates, RM amounts, premium frequency: regex patterns.
  - Benefits: keyword proximity, e.g. an RM amount on the same line / row as "Critical Illness", "Death", "TPD", "Room & Board", "Annual Limit", "Hospital Income", "Personal Accident", "Maturity". Use text positions to rebuild table rows where possible.
  - Structure the rules as **per-insurer templates** plus a generic fallback, so templates can be added over time. Start with Great Eastern, then the most common insurers the owner sees (ask the owner).
- Never auto-confirm. The advisor confirms each policy.

## 8. Optional AI auto-fill (Cloudflare Workers AI, free plan) (Phase 3)

- The browser sends the **extracted text** (not the PDF) to `api.php?action=ai_extract`; PHP calls Workers AI with the token from a file outside `public_html`. The token is never sent to the browser.
- Ask for JSON matching §6.2–6.3 only. Validate the JSON on the server before returning it; anything invalid is dropped.
- Model: a capable instruction model, e.g. `@cf/meta/llama-3.3-70b-instruct-fp8-fast`. **Check Cloudflare's current model list and pricing** before choosing.
- The account stays on the **Workers Free plan** (10,000 Neurons/day, resets 00:00 UTC; requests fail rather than bill when exceeded). Keep a per-day call counter in MySQL and show "X left today" as an estimate.
- When the limit is reached or the call fails: show "AI limit reached for today. Auto-fill still works." and fall back to §7. The tool must never break.
- AI results are marked "? please check" like any other auto-fill.

## 9. Gap rules

All values live in **one settings record** that the super admin can edit (§5.7), seeded with these defaults.

### 9.1 Category guides
| Category | Guide (default) | Status |
|---|---|---|
| Medical card | annual limit ≥ RM 1,000,000 **and** lifetime unlimited | 🔴 none · 🟠 below either · 🟢 meets both |
| Medical card | room & board ≥ RM 250/day | 🟠 if lower |
| Medical card | cover to age ≥ 80 | 🟠 if earlier |
| Medical card | deductible / co-payment | information only |
| Death / TPD | needs-based gap from the Protection Needs Calculator. On this page, show the total and link to the calculator; show 🟢/🟠 only if the advisor has run it | |
| Death / TPD | TPD cover to age ≥ 65 | 🟠 if earlier |
| Critical illness | ≥ 5 × annual income (same default as the needs calculator's CI years) | 🔴 none · 🟠 below · 🟢 meets |
| Critical illness | includes early-stage cover | 🟠 if advanced only |
| Hospital income | ≥ annual income ÷ 365 per day; priority flag for self-employed / commission earners | 🔴 none for self-employed · 🟠 below |
| Personal accident | ≥ 5 × annual income | 🔴 none · 🟠 below · 🟢 meets |

### 9.2 General flags
- No nominee on a policy.
- Cover ends before age 65.
- Premiums continue past retirement age.
- Lapsed or lapsing policy.
- Cover that is group-only (ends when they leave the job), with the RM amount.

Only **in-force** policies count towards totals. Lapsed / surrendered ones are listed but not counted.

## 10. Links to the existing calculators

Category totals map to the other tools like this:

| Policy Summary total | Protection Needs field | Sum Assured field |
|---|---|---|
| Death/TPD, personal policies | `life` | part of `exL` |
| Death/TPD, group policies | `group` | part of `exL` |
| MRTA / MLTA | `mrta` | part of `exL` |
| Critical illness (early + advanced) | `ci` | `exD` |
| Client ANB, annual income | `anb`, `income` | `anb`, `inc` |

`exL` = personal + group + MRTA/MLTA, the same way the needs calculator builds it today.

- **Run protection needs analysis ›** opens the needs calculator with Step 1 (`anb`, `income`) and Step 3 ("In place") pre-filled.
- **Check underwriting limit ›** opens the sum assured calculator with `anb`, `inc`, `exL`, `exD`.

Changes needed in the existing tools:
1. **Protection Needs Calculator** currently cannot receive figures. Add an incoming hand-off. When figures arrive, **do not load the SAMPLE data** (it loads sample figures on open today).
2. **Sum Assured Calculator** accepts `from=needs` only. Also accept `from=summary`, with matching wording in the "Carried over from…" box.
3. **Privacy:** both tools put the client name in the URL today. Hand over **numbers only** in the URL, or pass data via `sessionStorage`/`localStorage` on the same origin with a short expiry. Never put client names or policy numbers in a URL. Apply the same to the existing needs → sum assured link.

The underwriting factors in the sum assured calculator (`BANDS`, Field Underwriting Guide V102026) are hard-coded; leave them as they are.

## 11. Storage, access and sharing (Phase 2)

### 11.1 Tables (new, in `dreamhub_members`)
Deliver as `private/schema_phase<N>.sql` (pick the next number after the existing schema files).

- `policy_summaries`: id, owner_member_id, data (encrypted JSON of §6.1–6.3 plus results), policy_count, consent_at, supervisor_review_ok (bool), created_at, updated_at, frozen_at (null), delete_after (null).
- `policy_summary_shares`: id, summary_id, shared_with_member_id, created_at, expires_at, revoked_at (null).
- `policy_summary_settings`: the gap rules from §9 as JSON, updated_by, updated_at.
- `policy_summary_ai_usage`: date, calls.

Encrypt `data` with PHP **libsodium** (`sodium_crypto_secretbox`), key in a file outside `public_html`. Client name is inside the encrypted data; for search, decrypt the advisor's own rows in PHP (volumes are small).

### 11.2 Who sees what
| Person | Sees | Can do |
|---|---|---|
| Owner (the advisor who created it) | own summaries in full | create, edit, print, share, stop sharing, delete |
| Person it is shared with | only summaries shared with them, while the share is valid | view and print only (no edit, no re-share, no delete) |
| Leaders / team admins (Team tree) | **nothing of client content** | nothing |
| Super admin | list without client details; frozen summaries with client name for transfer decisions | edit gap rules; transfer or delete frozen summaries |

This is a deliberate exception to "the tree decides who sees what": client policy data is the client's personal and health data, consented to their own advisor.

### 11.3 Share validity: check live on every request
A share is valid only if **all** are true:
- not revoked, not expired;
- the summary has `supervisor_review_ok`;
- the summary is not frozen;
- the person shared with is **currently above the owner in the Team tree** (use the Team code's existing helper);
- both people are active members.

Because this is checked live, promotions, breakaways and moves need no hooks: if someone stops being above the owner (e.g. the owner is moved under another Unit Manager), the share stops working immediately. Show it to the owner as "Share ended: Raj is no longer your upline."

### 11.4 Lifecycle (ties into the Team page actions)
| Team page event | Stored summaries of that advisor |
|---|---|
| They left the business | **Frozen**: nobody can view. `delete_after` = +90 days. Super admin transfers (to the agent officially servicing those clients) or deletes. |
| Remove whole group | Same as left: frozen, then transfer or delete. **No CSV export of client data.** |
| Reactivate | Frozen summaries not yet transferred or deleted return to the advisor. |
| Move / rank change | Ownership unchanged. Shares re-checked live (§11.3). |

Implement freezing by checking the owner's active status, plus a daily cPanel cron job that deletes summaries past `delete_after`. Ask the owner whether the Team page should also show "N stored policy summaries will be frozen" in its leave/remove preview.

### 11.5 Audit log
Write to the existing `audit_log`: create, update, delete, share, stop sharing, shared view, print by a shared viewer, transfer, freeze, auto-delete, gap rules change, and refused access attempts.

## 12. Disclaimers and consent (use as written; the owner will get compliance to review)

### 12.1 PDF footer (every page)
> This summary is prepared for reference only, based on information provided by the client. It is not a policy contract. In the event of any difference, the terms and conditions of the respective policy contracts shall prevail. Please refer to your insurer for the official policy details.

### 12.2 PDF end note (last page)
> **Important Notice**
>
> This Policy Summary has been prepared by a DreamHub E3 advisor, an authorised agent of Great Eastern Life Assurance (Malaysia) Berhad, for the purpose of reviewing the client's existing insurance coverage.
>
> - The information in this summary is based on documents and details provided by the client, and has not been verified with the respective insurers. It may contain errors or omissions.
> - The terms, conditions, benefits and exclusions of each policy are governed solely by the respective policy contracts issued by the insurers. This summary does not change, replace or interpret those contracts.
> - Coverage gaps and recommended amounts shown are estimates based on general planning guidelines and the information provided. They are not a guarantee of future needs, and do not constitute a recommendation to purchase, cancel or replace any policy.
> - Replacing an existing policy may be disadvantageous to you. You may incur losses, lose benefits, or face new waiting periods and exclusions. Please consult your advisor and your existing insurer before making any decision.
> - Investment-linked values, if shown, are not guaranteed and may vary with fund performance.
>
> Your personal data is handled in accordance with the Personal Data Protection Act 2010.

### 12.3 Client consent (Step 1, store option)
> **Client Consent**
>
> I agree to share my insurance policy documents and personal information with my DreamHub E3 advisor for the purpose of preparing a Policy Summary and coverage review.
>
> ☐ **Do not store**: Use my information to generate this summary only. Do not keep a copy.
> ☐ **Store for future reviews**: Keep my information securely for future reviews. I understand I may ask for it to be deleted at any time.
> ☐ **Supervisor review** (optional): My advisor's supervisor may review this summary to help with my plan.
>
> My personal data will be handled in accordance with the Personal Data Protection Act 2010 and will not be shared with third parties without my consent.

### 12.4 Advisor reminder (Step 3)
> **Advisor reminder:** Auto-filled details are extracted automatically and may be incorrect. Check every field against the original policy document before generating the summary. This tool is for coverage review only. Any replacement recommendation must follow the company's replacement procedures and disclosure requirements.

## 13. The PDF (print layout)

- Page 1: E3 logo and name, "Policy Summary", client name, "Prepared by <advisor>, <date>", 4 tiles, Coverage vs guide, Premium by policy, footer disclaimer.
- Page 2: Policy list, Cover timeline, Savings / maturity, Things to note, Important Notice, footer disclaimer.
- Policy numbers: last 4 digits only. Non-guaranteed amounts labelled "Not guaranteed".
- Use `print-color-adjust: exact` for bars, as the existing tools do. Test on A4 in Chrome (desktop and Android) and Safari (iOS).

## 14. Toolkit card (`apps.js`)

Insert as the **first** entry in "Agent Tools", above Protection Needs Calculator:

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

## 15. Phases

1. **Phase 1 (no server):** Steps 1–4, manual entry, PDF.js + rule-based auto-fill, gap rules with defaults (hard-coded for now), summary and charts, print/PDF, links to both calculators and the changes in §10. Don't-store mode only.
2. **Phase 2 (server):** `api.php`, schema, encryption, store / list / search / delete, sharing (§11), lifecycle and cron, audit log, super admin screens, gap rules settings.
3. **Phase 3:** AI auto-fill (§8).
4. **Later, ask first:** "N summaries created this month" counts in Team Activity, following the tree's visibility rules (counts only, no client details).

Ship and get owner sign-off after each phase.

## 16. Tests (same standard as the Team page)

- PHP scenario tests (`php tests/policy_summary.php`): access matrix in §11.2, share validity in §11.3 after moves, promotions, breakaways, demotions, leave, remove group and reactivate; freeze, transfer, auto-delete.
- Browser tests (`node tests/e2e_policy_summary.js`, Playwright) with real signed login tokens, including **hostile requests**: guessing another summary's ID, viewing after a share expired or was revoked, a leader fetching a downline's summary, editing via a shared link, oversized or malformed JSON, AI endpoint abuse. Each must be refused by the server and logged.
- Unit tests for the gap rules and the calculator hand-off mapping (§10).
- Extraction tests with sample PDFs (ask the owner for a few real policies with personal details redacted). Report the field-level hit rate per insurer.

## 17. Deploy

1. phpMyAdmin > `dreamhub_members` > SQL: run the new `private/schema_phase<N>.sql` (Phase 2).
2. Upload and extract the zip in the cPanel home folder (`public_html/members/policy-summary/`, `private/…`).
3. Put the encryption key and Workers AI token files outside `public_html`; document their paths.
4. Replace `public_html/members/apps.js` (adds the card).
5. Replace the updated `protection-needs/index.html` and `sum-assured/index.html`.
6. Phase 2: add the daily cron job in cPanel.
7. Write a `HANDOVER-policy-summary.md` like `HANDOVER-agency-report-generator.md`.

## 18. Needed from the owner before or during the build

- The existing PHP files for login token verification, DB connection, member lookup, tree helper ("is X above Y") and `audit_log` (credentials removed).
- Current `protection-needs/index.html` and `sum-assured/index.html` (the owner has them).
- Which insurers' policies they see most, and a few redacted sample PDFs.
- Cloudflare account ID and a Workers AI API token (Phase 3), stored outside `public_html`.
- Compliance review of the wording in §12.
