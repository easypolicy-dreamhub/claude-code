# Legacy Planner: logic audit against the Super Group T&R Legacy Advisory Program V1.10 training documents

**Prepared:** 26 Oct 2026
**What was audited:** the Legacy Planner single-file app (`/members/legacy-planner/`, version built 7 Oct 2026). The app's source is the minified React bundle inside its `index.html`. Function names below are the minified names in that bundle.
**Audited against:** 11 PDFs from the Legacy Advisory Program V1.10 (listed at the end).
**Scope:** audit only. Nothing has been changed. This file is the handover for whoever builds the fixes.

---

## Read this first: what the documents can and cannot verify

The training documents cover **non-Muslim estates in Malaysia only**. They say nothing on:
- faraid, wasiat, hibah or anything else for Muslims
- Singapore CPF, the Singapore Insurance Act, or a Singapore will
- whether marriage cancels a will or a nomination

So the app's faraid engine, its Muslim-specific flags and its Singapore/CPF rules **could not be checked against your documents**. They are neither confirmed nor contradicted here. If you want them audited, upload a source for each (for example a faraid reference from a Syariah adviser, and the CPF Board's nomination rules).

**Status (26 Oct 2026):** findings 1–11 are implemented in the rebuilt app in `legacy-planner/` (see its README). Finding 10 was resolved by removing the unreachable Singapore-jurisdiction branches. Engine tests (`npm test`) cover each finding.

---

## Summary

| # | Finding | Severity | Verdict |
|---|---------|----------|---------|
| 1 | Insurance nominee who is not a spouse, child or parent is treated as receiving the money personally. The documents say they receive it as executor, so it belongs to the estate. | **High** | App is wrong |
| 2 | Joint accounts and joint property pass 100% to the survivor in the app. The documents say the deceased's share (e.g. 1/3 with 3 holders) goes into the estate. | **High** | App is wrong |
| 3 | When one insurance nominee dies first, the app sends that share to the estate. The documents say the surviving nominees take it all. | **High** | App is wrong |
| 4 | Parents are only valid trust nominees when there is no living spouse or child. The app doesn't model this for Malaysian policies. | Medium | Missing |
| 5 | The Distribution Act 1958 is applied to every non-Muslim. The documents say it covers Peninsular Malaysia and Sarawak only, not Sabah. | Medium | Missing |
| 6 | No check on a will witness who is also a beneficiary (their gift is void). | Medium | Missing |
| 7 | No liquidity check: is there enough estate cash for debts, admin fees and transfer costs? This is the theme of most case studies. | Medium | Missing |
| 8 | No indication of the administration route (Small Estate ≤ RM5M, Summary Distribution ≤ RM600k, High Court). | Low | Missing (feature) |
| 9 | Checklist wording for joint assets and Malaysian insurance contradicts or under-states the documents. | Medium | Text fix |
| 10 | New profiles are always Malaysia. The Singapore rules in the code can never run. | Low | App bug (not from the documents) |
| 11 | Business-asset rules (partnership dissolves on death, sole proprietor's business entity doesn't pass) are only covered by a generic flag. | Low | Missing |

**Confirmed correct against the documents:** the Distribution Act share table, representation by grandchildren, the sibling fallback, ex-spouse exclusion from intestacy, how EPF nominations work, how a trust nomination stays outside the estate and creditors, debts being paid from the estate only, foreign assets needing a separate grant or resealing, and the guardian and minor warnings. Details are in the "Confirmed" section.

---

## Findings in detail

### 1. Non-trust insurance nominees are treated as beneficial owners — HIGH

**What the documents say**
- *Part I – Foundations of Legacy Planning*, pp. 38–48 (FSA 2013 Schedule 10):
  - Only a **spouse, child, or parent** nominee creates an automatic trust (para 5). The money bypasses the estate and creditors.
  - Any other nominee (sibling, friend, non-married partner, cousin, business partner) **receives the money as executor**, not for themselves (para 6). The money "forms part of the estate", is "distributed per will" or the Distribution Act, and is "not creditor-proof".
  - The "Mr. Lee nominated his brother" example on p. 48 says the brother "cannot keep any for himself".
  - The exception is a written **assignment** (para 2(4)(a)), which makes that nominee a true beneficiary.

**What the app does**
- `wm()` treats a `nominee` asset the same way for any relationship (non-Muslim profiles). It credits each living nominee's % to that person as an outside-estate gift.
- A Malaysian life policy nominated to a brother shows the brother receiving it. It also skips the estate's debts.

**Fix**
- For a Malaysian (non-`foreign`) asset with `category === "insurance"` and `route === "nominee"`, work out the nomination type from each nominee's relationship:
  - spouse, son or daughter → **trust** (outside the estate, not reduced by debts)
  - father or mother → trust only if the profile has no living spouse and no living child (see #4)
  - anyone else → **non-trust**. Add that share to `estatePool`, so it is reduced by liabilities and passes under the will or intestacy.
- Add an "Assigned in writing to this nominee" checkbox on the asset. When ticked, treat a non-trust nominee as beneficial.
- Add a flag (level `high`): *"[Name] is nominated on '[policy]' but is not a spouse, child or parent. Under FSA 2013 Schedule 10 they receive the money as executor for the estate, not personally. Use an assignment, or nominate a spouse/child/parent, if they are meant to keep it."*
- Muslim profiles: keep the existing `nomineeHeldForHeirs` behaviour. The documents don't cover Muslims.

### 2. Joint ownership is treated as full survivorship — HIGH

**What the documents say**
- *Part III – Estate & Taxation*, p. 19 (Mr. Lim case, "The Legal Math"): *"with 3 joint holders, the deceased owns only 1/3 of the balance"*. Only that 1/3 (RM39,717 of RM119,151) was counted as estate cash. The case's takeaway is that joint accounts are not estate cash.
- *Debt Cancellation Through Insurance*, p. 38: jointly owned condo, link house and semi-D are listed under **"Probate Asset: Immovable Asset"**. Joint property is shown as going through probate, not passing outside it.
- *2.3b Asset Coverage*, p. 4: tells advisers to list "all jointly held accounts" as part of the asset inventory for the estate.

**What the app does**
- `wm()`, `route === "joint"`: the whole value goes to the single chosen "surviving joint holder" and is counted as outside the estate.
- Checklist item `joint` (in `hm()`) says: *"Property and accounts held as joint tenants pass to the survivor automatically."*
- `route` label `Rn.joint` reads "Joint ownership (survivorship)".

**Fix**
- Change the joint route to record **all** joint holders, as a number or a list, instead of one survivor.
- Default treatment, in line with the documents: the deceased's share = value ÷ number of holders. That share goes into the **estate pool**. The other holders' shares are theirs already, so leave them out of the deceased's total assets, or show them separately. Do not credit them as inheritance.
- Optionally add a per-asset toggle "Account mandate / title expressly gives survivorship". Leave it off by default, with a hint to confirm with the bank or a lawyer. If it's on, keep the current behaviour.
- Rewrite the `joint` checklist text, the route label and the `Jv.joint` hint so they don't promise automatic survivorship.
- Add a flag (level `medium`) for any joint asset: the deceased's share forms part of the estate. Surviving holders shouldn't withdraw it, and it isn't freely usable estate cash.

### 3. Insurance nominee who dies first: the share goes to the estate instead of to the surviving nominees — HIGH

**What the documents say**
- *Part I*, p. 51, citing Schedule 10 para 6(2): when one nominee dies before the policy owner, the *"remaining surviving nominees receive the full proceeds between them. Estate administration is not required — as long as at least one nominee survives."*
- *Part I*, p. 52, para 6(3): if **all** nominees die first, the proceeds go to the estate.
- The rule is different for **EPF**. *Part I*, pp. 31–32: when one nominee dies first, *"Nominee B's share is directed into the estate"*. If all die first, the whole balance goes to the estate.

**What the app does**
- `wm()` uses one rule for every nominee asset. A dead nominee's share goes into the estate. That's correct for EPF and wrong for insurance.

**Fix**
- In `wm()`, for `category === "insurance"` (Malaysian policy) and `route === "nominee"`, share out among the **living** nominees in proportion to their percentages, so they still add up to 100%. Only send the full amount to the estate when no nominee is alive.
- Keep the current behaviour for `category === "retirement"` (EPF).
- Adjust the note text: the current "Part of X has no living beneficiary, so it falls back into the estate" should apply to EPF and to the all-dead case only.
- **Point to confirm with the trainer:** *Part I*, p. 47 says *"If a trust nominee passes away before the insured, policy monies revert to the Estate and lose creditor protection."* Read alone, this conflicts with p. 51. The two only agree if p. 47 means a **sole** trust nominee. I've built the fix on paras 6(2)/6(3), but the slide wording should be checked.

### 4. A parent is a trust nominee only when there is no spouse or child — MEDIUM

- *Part I*, p. 38: parents qualify *"only if there is no living spouse or children"*.
- The app has no relationship-based logic for Malaysian insurance (see #1). When #1 is built, include this condition. If a parent is nominated while a spouse or child is alive, treat that nomination as non-trust (estate) and raise a `medium` flag.

### 5. Where the Distribution Act 1958 applies — MEDIUM

- *2.2 Distribution Act 1958*, p. 2: *"Geographic Scope: Peninsular Malaysia & Sarawak"*. The same point appears on p. 3 and in *Debt Cancellation Through Insurance*: *"Peninsular Malaysia & Sarawak Only"*.
- The app (`Om()`, Profile tab text, report) applies the Distribution Act to every non-Muslim Malaysian profile.
- **Fix:** add a "State of domicile" field on the Profile tab. If Sabah is chosen for a non-Muslim:
  - show a `high` flag saying the Distribution Act 1958 does not apply in Sabah and the outcome shown may be wrong
  - label the default-law column "Not modelled for Sabah", or keep the calculation but mark it unverified

  Don't invent Sabah's rules. The documents don't give them.
- Related fact from *2.3 Wills Act 1959*, p. 3: the minimum age to make a will is 18, or **21 in Sabah**. Use this in the will-validity check (#6).

### 6. Will validity checks — MEDIUM

*2.3 Wills Act 1959*, pp. 3–4 lists the requirements:
- age 18 or over (Sabah 21)
- sound mind
- in writing
- signed by the testator
- **two witnesses present at the same time**
- **witnesses cannot be beneficiaries or their spouses.** *"Doing so voids their entitlement under the will."*

The app has no check for any of this. **Fix:** under the Will checklist item (status Done), add three yes/no fields:
- "Signed before two witnesses present together"
- "Any witness (or witness's spouse) is a beneficiary"
- "Testator was 18+ (21+ in Sabah) when signing"

Flags:
- Witness is a beneficiary → `high`. Their gift is void. In the calculation, drop that person's will share so it falls into intestacy, the same way a lapsed gift is handled now.
- Two-witness box not ticked → `medium`.

### 7. Liquidity check is missing — MEDIUM

Liquidity is the main lesson in most of the case studies:
- Mr Lim: shortfall of RM7,413 against RM47,130 of admin fees, and a stall of nearly a year (*Part III*, pp. 17–24)
- Dato C: RM140,000 of transfer fees with no cash to pay them (*Case Studies KB02*, pp. 1–8)
- Mr A: estate cash fell from RM335,804 to RM87,866 over seven years (*Debt Cancellation*)
- Mr W: a 21-year-old unable to pay a RM40,000 executor fee (*2.1 Small Estates* deck, KB10)

The app subtracts one "Debts and funeral costs" number and stops there.

**Fix (suggested):**
- Work out **liquid estate cash**:
  - `cash` and `investments` assets on the estate route
  - plus the deceased's share of joint accounts (#2)
  - plus non-trust insurance (#1)
- Compare it with:
  - liabilities
  - an estimated administration cost
  - property transfer cost of about **1% of each estate property's value** (*2.3b Asset Coverage*, p. 11, and *KB02*)
- If cash is less than what's needed → `high` flag with the shortfall amount, suggesting life cover or a cash reserve.
- Keep the admin-cost estimate simple and visibly labelled as an estimate, or let the adviser type it in. Fee rates vary by provider, so don't hard-code one provider's rates.

### 8. Administration route — LOW (new feature)

*2.1 Small Estates (Distribution) Act 1955*, pp. 1, 8–12; repeated in *Part I*, p. 63:

| Situation | Route |
|---|---|
| Valid will (any value) | High Court, Grant of Probate. Small Estate does **not** apply. |
| No will, movable assets only, ≤ RM600,000 | Summary Distribution (s.8B/8C) at the Land Office |
| No will, total ≤ RM5,000,000 (from 15 July 2024) | Small Estate: Land Office / Amanah Raya, Distribution Order |
| No will, total > RM5,000,000 | High Court, Letter of Administration (usually 2 sureties) |

The app shows no route. **Suggested fix:** add a tile to the Outcome tab and the report: "Likely administration route", based on the will status, the estate pool value and whether any estate asset is `property`. Nominee and trust assets don't count toward the threshold. The documents also say minors' shares need a trustee under Small Estate (p. 13). Mention this when a minor inherits through the estate.

### 9. Checklist and hint wording that conflicts with the documents — MEDIUM (text only)

| Where | Current text | Problem | Replace with (gist) |
|---|---|---|---|
| Checklist `joint` | "...joint tenants pass to the survivor automatically. Tenants-in-common shares fall into the estate." | Contradicts #2 | "The deceased's share of a joint account or property generally forms part of the estate and needs the grant to release it. Don't treat joint accounts as available estate cash." |
| Checklist `insurance` (MY branch) | "Check the nomination type on each policy and whether the nominee holds as trustee or beneficial owner" | Doesn't state the rule | Spell out FSA 2013 Sch 10: spouse/child/parent (parent only if no spouse or child) = automatic trust, creditor-proof; anyone else = executor for the estate unless assigned in writing. |
| Checklist `retirement` (MY branch) | (fine) | — | Add: if a nominee dies first, their share goes to the estate. If all die first, the whole balance does. Review after a death in the family. |
| Asset route label `Rn.joint` | "Joint ownership (survivorship)" | Contradicts #2 | "Joint ownership" |
| Assets tab hint `Jv.nominee` | "Paid to the named nominees, outside the will" | Wrong for non-trust MY insurance | Add: "For Malaysian insurance, only a spouse, child or parent nominee keeps the money; others hold it for the estate." |
| Checklist `will` | (no storage advice) | Docs stress this (KB05, KB06; *2.3b*, p. 20) | Add: original must be findable; don't keep the only original in a safe deposit box (the bank seals it on death); tell the executor where it is. |

### 10. Jurisdiction is locked to Malaysia — LOW (logic bug, not from the documents)

`_l()` always sets `jurisdiction: "MY"`, and no control changes it. All the `t === "SG"` branches (Intestate Succession Act, LPA, AMD, the CPF wording) can't be reached. Either:
- add a Malaysia/Singapore selector to the Profile tab, or
- delete the SG-only branches.

The app's own title says "for Malaysia, including assets held abroad", so removing them is probably the intended direction. Check with the owner.

### 11. Business assets — LOW

*2.3b Asset Coverage*, pp. 13–17:
- a sole proprietor can pass on business **assets** but not the business itself, and the SSM registration must be closed
- a partnership **dissolves on a partner's death** unless the agreement says otherwise
- Sdn Bhd **shares** pass, but company assets are not personal estate

The app has one generic business flag. **Suggested fix:** add a "Business type" field (Sole proprietor / Partnership / Sdn Bhd) on `business` assets. Show the matching sentence as a hint and in the flag.

---

## Confirmed correct against the documents

| Rule in the app | Where it is in the app | Document reference |
|---|---|---|
| Distribution Act shares: spouse only → all; spouse + children → ⅓ / ⅔; parents + spouse + children → ¼ / ¼ / ½; spouse + parents → ½ / ½; children only → all; parents only → all | `Om()` | *2.2 Distribution Act*, p. 3; worked example in *2.1 Small Estates* deck KB07 (wife ¼, children ½, mother ¼) |
| No spouse, children or parents → siblings | `Om()` | *2.2*, p. 3 & p. 5. The app stops at siblings and notes that more remote relatives aren't modelled. The documents continue: grandparents → uncles/aunts → great-grandparents → great-uncles/aunts → Government. Acceptable as-is; could be extended. |
| Grandchildren take a predeceased child's share (representation); a predeceased child with no descendants drops out | `Vv()` | *2.2*, p. 4; *KB04*, pp. 15–20 (Mr T: 3 living children + 7 grandchildren = 10 heirs; child C with no descendants dropped) |
| A divorced spouse takes nothing on intestacy | `Om()` uses only `relationship === "spouse"` | *2.2*, p. 4 ("Divorced spouses and de facto partners excluded") |
| EPF passes by nomination, not by will; one nominee dead → their share to the estate; all dead → whole balance to the estate | `wm()` nominee branch | *Part I*, pp. 26–33; *2.3b*, p. 8 |
| Trust-nominated insurance is outside the estate and not used for debts | `wm()` (liabilities taken from the estate pool only) | *Part I*, pp. 39–46; *Part III*, p. 22; *Debt Cancellation* (FSA 2013) |
| Debts are paid from the estate before distribution | `wm()` `netEstate = estatePool − liabilities` | *Part III*, pp. 2–5; *2.3a*, Step 7 |
| Foreign assets need a separate grant or resealing | `foreign_assets` checklist item and flag | *2.3a Estate Administration with a Will*, Step 9 |
| Minors need a guardian (Guardianship of Infants Act 1961) and a trust or trustee for their inheritance | `guardian` checklist item; minor flag in `Kv()` | *Legal Guardian & Insurance*, p. 6; *2.1 Small Estates*, p. 13 |
| A will can't control nominated assets | info flag in `Kv()` ("pass outside the will") | *2.3b*, pp. 6 & 8 ("Nomination supersedes Will") |
| Malaysia has no inheritance tax | (nothing modelled, correctly) | *Part III*, p. 5 |

---

## Document inconsistency to raise with the training team

This doesn't affect the app as it stands. It matters if the trust-nominee rule is built in.

1. **Trust nominee who dies first.** *Part I*, p. 47 says the money "reverts to the Estate". *Part I*, p. 51 (para 6(2)) says surviving nominees take it all. See #3.

---

## Documents reviewed

| File (upload id) | Title | Pages | Used for |
|---|---|---|---|
| 92166f86 | Part I – Foundations of Legacy Planning | 63 | EPF, FSA Sch 10 nominations, frameworks |
| b5aa5d30 | 2.2 Distribution Act 1958 | 12 | Intestacy shares, scope, representation |
| f583eaa4 | 2.3 Wills Act 1959 | 10 | Will validity |
| e357d7c0 | 2.1 Small Estates (Distribution) Act 1955 + case studies KB07–KB10 | 56 | Administration routes, minors |
| 7b84091a | 2.3a Estate Administration Journey with a Will | 40 | Probate steps, debts, overseas assets, fees |
| 98ac02cd | 2.3b Asset Coverage | 22 | Asset classes, nominations, business, digital |
| 6a9fd653 | Part III – Estate & Taxation | 24 | Debts, tax, joint accounts (Mr Lim) |
| e6dd6ef6 | Debt Cancellation Through Insurance | 61 | Liquidity, joint property as probate asset, fees |
| 1d4447e0 | Case Studies KB02–KB06 | 52 | Transfer costs, representation, lost wills |
| 348812d3 | Legal Guardian & Financial Support via Insurance | 49 | Guardianship |
| a3ffb8dd | Estate Planning vs Legacy Planning | 20 | Background only |
