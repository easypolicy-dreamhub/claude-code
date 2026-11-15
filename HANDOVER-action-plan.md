# Handover: Action Plan module (DreamHub E3 members area)

Written 15 Nov 2026. Covers the Action Plan module only, from the first conversation about it to the last delivered zip, so someone (or a fresh Claude session) can pick it up cold. The wider Members & Access system (who can log in, tool toggles, Cloudflare sync) is a separate piece of work; only the parts the Action Plan depends on are mentioned here.

## 1. What it is, and where it came from

A tool in the members area that replaces the owner's spreadsheet "DreamHub E3 New Associate Action Plan". A new Team member gets a 90-day plan (default). They tick or count items, their leader approves or sends them back, and the leader sees who is on track.

How it started:
- The owner (team leader and super admin of "DreamHub E3", a Malaysian insurance team) shared a KCG Kickstart slide deck from a friend's insurance team and asked for a point of view. Then they sent their own spreadsheet.
- The result was a combination: the owner's items and sequence, plus a few gap-fillers inspired by the KCG deck.
- The original design is in `action-plan/DESIGN.md` (marked "draft for review"). **Where the built version differs from that draft, this handover wins** (see section 3).

Live location: `https://dreamhub-e3.com/members/action-plan/`. It is a card in the **Coaching** group of the toolkit page.

## 2. How it is built

Same pattern as the rest of the members area: a single-page vanilla JS front end, a thin PHP API, logic in `private/lib`, MySQL on cPanel shared hosting (tests use sqlite). Sign-in is Cloudflare Access; the person is looked up from the Cloudflare JWT by the existing `boot_member_request()`.

| Piece | File (under `members-access/`) |
|---|---|
| Page (all views, one file, no libraries) | `public_html/members/action-plan/index.html` |
| API (sign-in, permission check, pass-through) | `public_html/members/api/actionplan.php` |
| All the logic; single entry point `ap_dispatch()` | `private/lib/actionplan.php` |
| Automatic counts and DISC from Career Code | `private/lib/actionplan_auto.php` |
| Starting template (7 sections, 45 items) | `private/lib/actionplan_seed.php` |
| Tool catalogue entry (key `action-plan`, group Coaching) | `private/lib/tools.php`, `public_html/members/apps.js` |
| Page gate (rewrites `action-plan/` through `gate.php`) | `public_html/members/.htaccess` |
| Database scripts | `private/schema_phase11.sql` to `schema_phase14.sql` |
| Tests | `tests/actionplan.php` (220 checks), `tests/ap_support.php`, `tests/ap_harness.php` (browser harness) |
| Install notes per phase | `README.md` (sections for phases 11 to 14) |

Database tables: `ap_sections`, `ap_items` (`plan_id` NULL = template item, set = an extra item for one person), `ap_plans` (one per person; includes `disc`, `private_number`, `buddy_id`, `last_nudged_at`, `closed`), `ap_progress` (status, count, note, approval info per plan and item), `ap_overrides` (per-person skip, week and target changes).

The API: `GET ?view=init | me | overview | approvals | person&plan=ID | template | buddy | buddy_plan&plan=ID | nudge&plan=ID` and `POST {action: ...}` (tick, untick, count, approve, sendback, reopen, assign, plan_update, override, extra_add, extra_remove, section_*, item_*, nudge_done). Errors use HTTP 4xx. **Never 502/504**: Cloudflare replaces those bodies with its own page and hides the reason.

## 3. Decisions and behaviour (what the owner chose)

Template (super admin only)
- The owner can add, edit, turn off, remove (archived if anyone has history on it) and reorder items and sections.
- Each item is a **tick** or a **count** (with a target). Week ranges (from/to) are suggestions.
- Plan length is adjustable (default 90 days); suggested weeks stretch to fit. A late-added template item is never overdue for existing plans.
- **No point system** (dropped in the design discussion): plain completion only.

Assigning
- **Only Team members get a plan** (not Admin or Tester types, not the super admin). An **Assign** button (Everyone tab) takes the person, start date and length.
- Assign switches on the Action Plan card for the member and for team admins above them, and offers a tick-box of the tools the template has shortcuts for (ticked by default; people who already have a tool show "already on"). Client Portal ticking switches their portal on.
- "Close this plan" removes someone from the lists, deletes nothing; assigning again reopens it with their progress. **There is no delete-forever button** (the owner said it isn't needed).

Statuses and approval
- Item status: `todo`, `waiting` (agent ticked, or an automatic count reached its target), `done` (approved), `followup` (sent back with a note).
- Agents tick or count; the super admin and **team admins** (Team page flag `is_team_admin`; there is no role called "Team Admin" beyond that flag) approve or send back. **Nobody approves their own items.** A team admin only acts on people below them in the upline tree.
- Approvers can also **Mark as approved** an item the agent has not ticked, and **Ask them to do this** (a note, status followup). Reopen undoes an approval.

Weeks are a guide, not a rule
- Agents see "Suggested by week N". **They never see Behind or Overdue.**
- Approvers see On track / A little behind / Behind. Logic is in `ap_summary()`: pace uses only completed weeks of an item's window; a count is "pace behind" when below 80% of expected; the label depends on how many due items are late (about 15% for "a little behind"). Check the code before changing a threshold.

Per-person adjustments (super admin): skip an item, change its weeks or target, add extra items for one person.

DISC and the private number
- DISC style is set by hand, or taken automatically from Career Code if present. **Manual wins.** The owner does not need DISC for everyone ("optional").
- The **private numerology number** (1 to 33) is visible to the super admin only. It is never shown to team admins or agents, and **never printed** on the Day-90 review.

Automatic counts (numbers only, never client or candidate names)
- Keys in `AP_AUTO`: Client Portal prospects, notes of type Open / Present / Recruit, closed cases (and ANP), Career Code candidates added and tests completed.
- Counted from the person's plan start date to today. An item can use any of them (Template > Edit > "Counted automatically from").
- An auto-counted item cannot be typed over or ticked manually in todo; when it reaches its target it goes to `waiting`.
- Portal notes are encrypted: only their type and date are read.
- The real portal data was checked by the owner (prospects, Open/Present/Recruit, closes with ANP, Career Code) and matched. The `closes` table's columns are matched by name; the check screen shows what was found.

Tool shortcuts ("Open <tool> ›" buttons)
- An item can have a shortcut to a toolkit tool (set in the template). Four portal-counted items point to the Client Portal (`schema_phase13.sql`).
- **The shortcut shows only to the agent, and only if that agent can open the tool** (Client Portal also needs their portal switched on). The leader sees no shortcut on an agent's plan (it would open the leader's own tool); instead a grey note says "Shortcut hidden: X is not switched on for <name>", and the fix is to switch it on in Members & Access.
- The owner removed the weak Sum Assured shortcut from "Product knowledge" in their own template.

Step 3 features
- **Nudge on WhatsApp**: a drafted check-in (week, percent, follow-ups with notes, items due) the leader edits and opens in their own WhatsApp via `wa.me`. **Nothing is sent from the server** (no WhatsApp API, no cost). Needs the person's WhatsApp number saved in Team > details. "Last nudged" is remembered. Team admins can nudge people below them. The text never says Behind or Overdue.
- **Buddy**: set in Plan settings (super admin only). The buddy gets a **Buddy** tab with a read-only view (titles, ticks and counts; no notes, labels, DISC, private number or skipped items) and their Action Plan card is switched on. The agent sees "Your buddy: X. Ask them if you get stuck." plus a **Message X on WhatsApp** button (only if the buddy has a saved number). The buddy's number goes only to the agent whose buddy it is. Anyone active can be a buddy (a new agent, a senior, even Admin/Tester types); one buddy per plan, one person can be buddy to many. Nobody is notified when made a buddy.
- **Day-90 review**: a print-ready page (button "Day-<length> review" on a plan, then "Print or save as PDF"). DreamHub E3 logo and heading on page 1, progress per section, skipped items and follow-ups, four blank note boxes, signature lines. **Footer on every page** ("DreamHub E3 · Day-90 review · <name>", "Page N of M") and print rules so a heading never separates from its table and rows never split. The owner chose "option A" (footer only); a dimmed watermark and a small top-right logo on pages 2 and 3 were tried as samples and **rejected**. The Behind label and the private number are not printed. The logo is embedded in the page as a data URI (no image file on the server).

## 4. Deployment history and how to deploy

Deliverables are always one zip named `e3-members-access_NEWEST_<date>_<letter>.zip` (cumulative; each includes everything before it), plus a repo copy `members-access/e3-members-access.zip` (kept in step with the latest; an older `e3-members-access.zip` at the repo root is stale and can be ignored). **Latest: `e3-members-access_NEWEST_2026-11-15_k.zip`.** The owner extracts it in their cPanel home folder, overwriting.

Database scripts, run **once each** in phpMyAdmin, in order (all four already run by the owner):

| Script | What it does |
|---|---|
| `schema_phase11.sql` | the five Action Plan tables |
| `schema_phase12.sql` | switches on the automatic count for items by title |
| `schema_phase13.sql` | adds Client Portal shortcuts to four counted items (only where an item has none) |
| `schema_phase14.sql` | adds `buddy_id` and `last_nudged_at` to `ap_plans` |

Two cautions: re-running phase 14 gives "Duplicate column" (harmless); re-running phase 13 would put a shortcut back on an item where the owner removed it. **The template is the owner's live data in their database; a new zip never changes it** (the seed only loads while the template is empty).

Source: GitHub `easypolicy-dreamhub/claude-code`, working branch `claude/youthful-mayer-ff43p6` (the owner does not use GitHub day to day; files live in cPanel).

## 5. Testing

- `php tests/actionplan.php` (220 checks) covers weeks, statuses, approval rules, permissions, automatic counts (with a fake Client Portal), Career Code, shortcuts, assign tick-box, nudge, buddy and the audit log. Earlier suites: `tests/access.php` (129), `tests/access_team.php` (5).
- **Important gotcha:** the tests need the owner's full site tree. Files like `private/lib/members.php`, `auth.php`, `tests/helpers.php` and `private/bootstrap.php` are **not in this repository** (they are the owner's live files). In the working session they were copied into a scratch overlay folder together with this repo's files. A fresh session must get those files from the owner (or from cPanel) before the tests can run.
- Browser checks used Playwright against `tests/ap_harness.php` (`php -S 127.0.0.1:8099 tests/ap_harness.php`; a fake sign-in at `/__as?who=NAME&today=DATE`, reset at `/__reset`). The Playwright script (`ap_ui_test.js`, 65 checks) lived in the session scratchpad, not in the repo.
- Responsive check (phone 360/390, tablet 820, desktop 1366) found and fixed one real bug in the last round: a long grey "Shortcut hidden" note widened the page on phones.

## 6. Known limits and things not verified

- **Never tried on a real iPhone or Android phone**, only a desktop browser imitating phone widths. WhatsApp links from a phone are untested.
- **DISC from Career Code** has not been checked on real data (the owner sets DISC by hand).
- **Printing the Day-90 review** was checked as a Chrome PDF only; not on a real printer. The footer uses page-margin boxes (recent Chrome, Edge, Safari); in an old browser the footer is simply missing and the rest still prints. There is no fallback.
- Nobody is notified automatically of anything (no email, no WhatsApp push). Nudges are manual.
- The Day-90 review's printed "Done" dates come from the approval date.
- The Client Portal's own database was never seen, only how its pages use it; counts of cases closed depend on matching column names.
- Not built, deliberately: delete-forever for a plan, automatic WhatsApp sending, a "you are the buddy" tag on the Everyone list, buddy-to-agent messaging, a fallback footer for old browsers.

## 7. Common changes and where to make them

| Want to | Do this |
|---|---|
| Add, rename, reorder, turn off an item or section | Action Plan > Template (no code, no zip) |
| Change an item's tool shortcut or automatic count | Template > Edit the item |
| Give a new tool a shortcut | Tool must exist in `tool_catalog()` (`private/lib/tools.php`) and `apps.js`; then Template > Edit > "Open tool shortcut" |
| Change plan length, DISC, buddy, private number | Open the person's plan > Plan settings |
| See why a count looks wrong | Plan settings > "Check the automatic counts", or Everyone > "Try the automatic counts" (nothing saved) |
| Change the Behind thresholds | `ap_summary()` and the pace rule in `ap_plan_items()` in `private/lib/actionplan.php` (then update tests) |
| Change the Day-90 print layout or footer | `renderReview()` and the `@media print` block in `index.html` |
| Remove someone from the Action Plan | Their plan > Plan settings > Close this plan (their tool access is NOT switched off; do that in Members & Access) |

## 8. How the owner likes to work (worth knowing)

- They are not a developer. Give short, plain steps; say exactly which file to extract where and whether a database script is needed.
- **Do not start building until they say so.** An early session started building unasked and the owner objected. When they ask "how will it look", show a sample (screenshot or PDF) first.
- **One clearly named zip per delivery**, and say which one to use. Two zips caused confusion once.
- They notice logic gaps quickly (the DISC check, the leader seeing their own Client Portal button, the preview that opened the leader's tool). Before delivering, check every button and caption per viewer: agent, leader viewing an agent, team admin, buddy.
- Their screenshots are the main feedback channel; the answer to "something looks off" is usually a screenshot request.
- They prefer a recommendation to a list of options.
