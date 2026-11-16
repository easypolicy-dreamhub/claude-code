# Handover: Agency Report Generator (DreamHub E3 members area)

Written 22 Oct 2026, updated 16 Nov 2026 (shared drafts added). Covers everything done to the Agency Report Generator in this working session, so someone (or a fresh Claude session) can pick it up cold.

## 1. What it is

A single-page tool in the members area that turns three monthly files into a 16-slide report for one agency (default SG-PJ). Slides are 1600×900, shown on screen (Present) or exported to PDF.

Inputs:
- Production report (Excel): per-agent ranking in sections GM, UM, CA/LIA, SM, AGT, R.AGT. Gives month, year, QFYLP/ANP/NOC totals, manpower, per-agent production.
- Regional sales report (PDF): last year's figures, annual goal, QFYLP and ANP agency ranking tables. The agency row is found by name.
- Recruitment breakdown (Excel): local and outside recruits, rejoins, this month's newcomers.
- Keyed in by the user: last year's same-month ANP and manpower, targets, award qualifying figures, messages.

## 2. Where everything lives

| Thing | Location |
|---|---|
| Live page | cPanel `public_html/members/agency-report/index.html` (served at dreamhub-e3.com members area) |
| Photo API | `public_html/members/agency-report/photos.php` (not modified in this session) |
| Drafts API | `public_html/members/agency-report/drafts.php` (new, see section 3 item 7). Reads its Cloudflare Access settings from `photos.php` unless filled in at the top of the file |
| Draft files | `/home/<cpanel user>/e3-agency-drafts/` (above `public_html`). Fallback: `agency-report/draft-store/` blocked by `.htaccess`. One `<id>.json` per draft plus `index.json` |
| Photo files | `/home/<cpanel user>/e3-agency-photos/` (above `public_html`). Fallback if not writable: `agency-report/photo-store/` |
| Photo files layout | `<sha256-prefix>.jpg` (framed 480×600), `<sha256-prefix>-orig.jpg`, `index.json` (keys and names), plus `.certs.json`, `.lock`, `.htaccess`, `index.html` |
| Photo access | Cloudflare Access sign-in. Any signed-in member can view. Only the emails listed in `$ALLOWED_EMAILS` inside `photos.php` can add or delete |
| Settings and monthly data | Browser localStorage key `agencyReport.v1` (per device, not on the server) |
| Source copy | GitHub `easypolicy-dreamhub/claude-code`, branch `claude/another-task-lzy5z6`, path `agency-report/index.html` |
| Backup of the old page | `index-backup.html` in the same cPanel folder (made by the user before upload) |
| Review preview | Private Claude artifact https://claude.ai/artifact/1LgCrmbF9jpNjNmhYWNC3a (same code, wrapper tags stripped) |

Note: the cPanel zips `e3-members-all-9-apps.zip` and `e3-members-all-tools.zip` only cover `public_html`, so they do not include `e3-agency-photos`. Back that folder up separately.

## 3. Changes made in this session (all in `index.html`)

1. **E3 Toolkit header bar.** The report generator was missing the dark top bar the other toolkits have. Copied exactly from `sum-assured`: `<nav class="e3-bar">` with back link to `/members/`, "DreamHub E3" brand, favicon link `/images/favicon.png`, and a print rule hiding the bar. CSS is in `<style id="e3-theme">`.
2. **Productivity target is automatic.** Target = 3 × report month (Jan 3, Aug 24, Sep 27, Dec 36). Constant `PROD_STEP=3`. Month comes from the Excel (falls back to the PDF month). Typing a value overrides it, and the reset button (↺) returns to automatic. A saved old value of exactly 24 is dropped once (flag `_tprodRule` in saved settings).
3. **Active agents target is automatic.** = manpower target × active ratio target (330 × 40% = 132), rounded. Same override and reset behaviour. `auto()` reads the raw overrides directly to avoid a loop through `V()`.
4. **MAPA view.** MAPA = Manpower × Active ratio × Productivity (cases) × Average case size. Without M, A×P×A = ANP per manpower ("Productivity (ANP)"). Implemented by `mapa()`.
   - Data tab: the old "Calculated for you" list became "Calculated for you: MAPA", a table of Actual vs Target by month, with rows for active agents (M × A), ANP year to date, and Productivity (ANP) per manpower, plus a year-end chain line. The three goal rows (on-track target, goal completion, this month vs monthly target) moved to a "Goal progress" group. Target fields are in MAPA order with letters in their labels.
   - Facts & Figures slide (page 6): four MAPA tiles with × between them (each actual / target and a bar), then Active agents (M × A), Productivity (ANP) per manpower, Total NOC, then the award cards.
5. **Flexible award layout.** Awards have their own grid. 1–3 awards: unchanged. 4 → one row of 4, 5 → one row of 5, 6 → 3×2, 7–8 → 4 per row, 9 → 3×3, 10+ → 5 per row. 6 or more awards tighten the slide (`.dense`), 9 or more tighten more (`.dense2`). Measured to fit up to 12 awards with no clipped text.
7. **Shared drafts (option B).** A "Drafts" group at the top of the Data tab, backed by the new `drafts.php`.
   - Save as new draft (named, default "SG-PJ Sep 2026"), open, reload, rename, delete. Opening or deleting asks for a second tap, because pop-up confirmations are not always shown.
   - A draft is a snapshot of exactly what the browser autosaves: loaded Excel/PDF/recruitment data, keyed-in figures, targets, awards, newcomers, messages, theme, slide order and the ending-photo caption. Photos, the ending photo image and custom category pictures stay in the photo library and are not copied into drafts.
   - The name box shows the suggested name (agency, month, year) as a hint that refreshes whenever files load, with a line saying what an empty name will become.
   - A working bar shows "All changes saved" or "Unsaved changes" with Save changes and Close draft. Autosave to the browser still runs as before.
   - Protection against overwriting: saving sends the version it opened (`expect`); if somebody else saved the draft since, the server answers 409 and the page tells the user to save as new or reload.
   - Access: the same Cloudflare Access sign-in as `photos.php`, and unlike photos, drafts are readable and writable only by the allowed emails (they hold production figures). Maximum 10 drafts (setting `$MAX_DRAFTS` at the top of `drafts.php`; the page shows "n of 10 drafts used" and disables Save as new draft when full), 6 MB each. If somebody deletes the draft you are working on, Save changes says the draft no longer exists and keeps your work on screen (use Save as new draft). Stored as the exact JSON string the page sent.
6. **Slide result line removed (by request).** "ANP year to date", "Target by <month>" and "Year-end goal" were taken off page 6 because they duplicate the previous page or confuse (RM 20.43M versus the rounded 20M goal). The CSS class `.mapa-res` is now unused.

## 4. Rules and decisions to keep

- Year-end goal is about 20M: 330 × 40% × 36 × 4,300 = RM 20,433,600, which the business rounds to 20M.
- The goal read from the regional PDF is a QFYLP figure used for on-track and completion. The MAPA chain is ANP-based. They can legitimately differ. A "goal check" comparing them was considered and not built.
- Actual MAPA multiplies back to ANP year to date by construction (active ÷ manpower × cases ÷ active × ANP ÷ cases), so a check on actuals adds nothing.
- Active agent = at least as many cases year to date as the report month number.
- "Target by <month>" in the Data tab uses that month's productivity target (for example 27 in September gives RM 15.33M). The user confirmed this reading is right.

## 5. How the page works (for editing)

Single file, plain JS, no build step. Libraries from cdnjs: SheetJS (xlsx), pdf.js, and html2canvas + jsPDF loaded on demand for the PDF export.

Key pieces in `index.html`:
- `FIELD_GROUPS`, `DEFAULT_SETTINGS`: the editable fields and defaults. `S.ov` holds user overrides, `auto()` holds file-derived and rule-derived values, `V(k)` returns override first, then auto.
- `parseExcel`, `parsePdf`, `parseRecruits`: file readers.
- `metrics()`, `mapa()`, `awardStats()`: calculations.
- `slideHTML(id)` and the per-slide functions (`factsSlide`, `vsSlide`, `tblSlide`, `mpSlide`, `welcomeSlide`, `catSlide`, ...): slide markup. Theme classes `.t-teal`, `.t-light`, `.t-gold`, `.t-crimson`.
- `renderFields`, `renderClubs`, `renderPeople`, `renderDesign`, `renderPreview`: Data/Photos/Design tabs and thumbnails.
- Photos: `photos.php` API when available (`SERVER` true), else IndexedDB `agencyReportPhotos`. A Photos-tab message states which mode is active.
- A small `e3-tabfix` script at the bottom makes Tab move between text boxes.

Gotcha found while testing: top-level `const`/`let` helpers (like `num`) are not usable before their line runs. A migration line placed right after `let S=load();` must not call helpers defined later.

## 6. Deploy and rollback

1. cPanel File Manager → `public_html/members/agency-report/`.
2. Copy the current `index.html` to `index-backup.html`.
3. Upload the new `index.html`, overwrite. Also upload the new `drafts.php` into the same folder (first time only). Do not touch `photos.php`.
4. Hard reload the toolkit page. Check: dark E3 bar on top, productivity target shows 3 × month, active agents target 132, page 6 looks right, and the Drafts group at the top of the Data tab says "No drafts yet" (if it says drafts.php is missing or not set up, see the message it gives).
5. Rollback: delete `index.html`, rename `index-backup.html` back to `index.html`. Drafts already saved stay on the server in `e3-agency-drafts`.

Photos and saved settings are not touched by replacing `index.html`.

## 7. Testing notes

- All checks were run in headless Chromium (Playwright) against the local file with network blocked and made-up sample data injected into the page (`excel = {...}; renderAll()`), so fonts were fallbacks. Checked: target values for months 1, 8, 9, 12, an old saved 24, a user override, slide fit for 0–12 awards, no page errors.
- `drafts.php` was tested with PHP 8.3 against a fake Cloudflare Access setup (own RSA key and certificate, token built by hand): no token, tampered token, wrong audience, expired token, wrong issuer, email not allowed, save, get (payload returned exactly), save with matching and stale `expect`, rename, delete, missing request header, bad JSON, oversize payload, path-traversal id, unknown id, and a missing settings file. 21 of 21 passed. The page's Drafts group was tested in Chromium against a mock of the same API (save, unsaved-changes tracking, conflict, open with two-tap confirmation, rename, delete, 3 MB draft, and the three error messages).
- Not tested against the real Cloudflare Access login or the real host. The first live check should be: open the page, save a draft, reload the page from another device or browser, open the draft.
- Not tested with real September files, a real regional PDF, or real photos. First live check should use real files.
- In the Claude artifact preview, Save as PDF, confirm pop-ups (Clear data, Remove award) and the `/members/` back link do not work, and the shared photo library is unreachable. They work on the real site.

## 8. Open items and ideas

- Data tab still shows the "Target by <month>" column and the year-end chain (RM 20,433,600). Only page 6 was cleaned. Remove there too if wanted.
- Title bar: `sum-assured` has a dark title bar, the report generator keeps its white one. Offered to match, not done.
- The 46px E3 bar scrolls away, but the side panel height assumes nothing above the title bar. At scroll position zero the bottom 46px of the panel may sit off screen until the page is scrolled slightly. Not adjusted.
- Idea, not built: move the E3 bar into one shared `e3-bar.js` loaded by every toolkit so headers cannot drift again.
- Idea, not built: a "goal check" line comparing the four targets with the PDF goal (about 3% tolerance). Skipped because the goals may be different measures.
- `photos.php` was read, not changed. It contains the Cloudflare team domain, AUD tag and allowed emails. Treat the file as configuration.
- `drafts.php` uses `random_bytes` (PHP 7+) and plain `array()` syntax, so it should run on older cPanel PHP versions, but this was only run on PHP 8.3. If the host's `post_max_size` is small (some hosts use 2M), a very large draft could be refused; the page then shows a message about size.
- The artifact tool warns that the page refers to a `downloads` capability that is not declared. This only affects the preview.

## 8b. Pending changes agreed with the owner (not built yet, as of 16 Nov 2026)

1. **Shorter drafts list (done 16 Nov).** The 10-draft maximum stays. The list shows the newest 3, with "Show all n drafts" and "Show less".
2. **Names instead of emails** for "last saved by" (Working-on box, draft list, conflict message). The Cloudflare sign-in only carries the email, so names must come from a list. Preferred source: the owner's existing member access function that records each user with their email, if it also holds names. Fallback: a small `drafts-names.php` (email to name), which must not be re-sent in later zips. Waiting on the owner to say where the member records live and whether they include names.
3. **Photo-save warning (suggested, not yet approved).** A message that stays on screen when the signed-in email is not on the photo allowed list, instead of the 3-second pop-up. Cause of the admin's lost photos on 16 Nov was most likely her email missing from `$ALLOWED_EMAILS` in `photos.php`; it was added afterwards (`sgpj1991@gmail.com`). Her earlier uploads never reached the server and must be uploaded again.
4. **Use Members & Access as the one source of who may save photos and drafts (approved by the owner).** Today `photos.php` and `drafts.php` use their own `$ALLOWED_EMAILS` list, separate from the Members & Access page (`/members/members-access` page, data behind `/members/api/access.php`, which holds name, email, type, per-tool switches and paused status per person). Having two lists is what made the admin's photo uploads silently fail. Plan: allow anyone whose Agency Report tool is switched on (and who is not paused), and take names for "saved by" from the same records. The tool's key in Members & Access is `agency-report` (group Leader, from `/members/apps.js`). Members live in a database (`members` and `tool_access` tables) behind `boot_member_request()` in `private/bootstrap.php`; `photos.php` and `drafts.php` can call it. From `private/lib/tools.php`: the check is `can_use_tool($pdo, $member, 'agency-report')` (super admin always true, removed people false, otherwise the tool must be ticked); load it with `require_once .../private/lib/tools.php` as `api/access.php` does. Names come from `load_member_map($pdo)`. Still needs `private/lib/auth.php` to confirm what `require_same_site_write()` and its `$allowUpload` flag allow (photo and draft saves send large JSON bodies) and that `authenticate_member()` rejects paused and inactive people. Do not send or commit `config.php` (Cloudflare token).
5. **Standard header (done 16 Nov).** The Agency Report now loads the shared `/members/e3-toolkit.css` and uses the standard `<div class="e3-bar">` markup; its own inline copies of the header styles were removed. Checked in Chromium at 1400px and 420px: header positions and screenshots are identical to the old inline version; the only computed differences are the shared file's own `min-width:0` on the title and a `DM Sans` font fallback on the subtitle. Original note:  The newer pages load a shared `/members/e3-toolkit.css` (and `/members/apps.js`) and use `<div class="e3-bar">` plus `<div class="topbar"><h1>Title<small>subtitle</small></h1><div class="who">…</div></div>`. The Agency Report still carries its own inline copy of the E3 bar and a different white title bar. Plan: switch it to the shared stylesheet and markup, then correct the "Standard header" section in `CLAUDE.md`, which currently points to the Agency Report as the model. Blocked on seeing `e3-toolkit.css`.

## 9. Picking this up in a new Claude session

Give it this file, the current `index.html` and `drafts.php` (download it from cPanel, or from GitHub path `agency-report/index.html`). The repo has no other files. When publishing a preview artifact, strip the `<!DOCTYPE>`, `<html>`, `<head>` and `<body>` wrapper tags first, because the artifact tool adds its own.
