# Members & Access (phase 10)

One screen, the **Members & Access** card (`/members/access/`, its own folder, separate from Site Settings), where the super admin:

- sees **every email on the Cloudflare "Email Access" policy**, and gives each a **name** and a **type** (Team member, Admin, Tester)
- **adds / removes** people, which updates Cloudflare for you (removal also signs them out at once)
- switches **each tool on or off per person** (by person, or by tool), **Client Portal included**
- **pauses** anyone with one switch: blocked from every page and all data at once, nothing deleted, resume any time

The super admin always has every tool. Testimonials, Events, Site Settings and Members & Access are super admin only and can never be given to anyone.
Every tool page is **checked on the server** and shows one standard "You don't have access to …" page, so a hidden tool cannot be opened by typing its address.

## What is in the zip (extract it in your cPanel **home folder**, the one that holds `public_html` and `private`)

| File | New / replaces |
|---|---|
| `private/schema_phase10.sql` | new: run in phpMyAdmin (step 2) |
| `private/lib/tools.php`, `access.php`, `cf_policy.php`, `gate.php`, `portal.php` | new |
| `private/lib/auth.php` | replace: the login check that refuses paused people |
| `private/lib/team.php`, `activity.php` | replace: Admins and Testers stay out of the team tree and Team Activity |
| `public_html/members/.htaccess` | new: sends tool pages through the gate |
| `public_html/members/gate.php` | new |
| `public_html/members/index.html`, `apps.js` | replace: the toolkit page shows each person only their ticked tools |
| `public_html/members/api/access.php` | new |
| `public_html/members/api/me.php`, `team.php` | replace: `me.php` also says which tools you may use; `team.php` updates Cloudflare when you add / remove / reactivate / change an email |
| `public_html/members/team/team.js` | replace: messages say Cloudflare was updated |
| `public_html/members/access/index.html` | new: the Members & Access screen (own folder). If you installed an earlier version, delete the old folder `public_html/members/site-settings/access/` afterwards. |

## Steps

**1. Back up** (2 minutes). In File Manager, compress `public_html/members` and `private/lib` into zips and keep them. Also check these sizes before you start. If any differs, stop and tell Claude (it means a newer edit is on the server):
`members/api/team.php` 6492 bytes, `members/api/me.php` 459, `members/team/team.js` 21014, `private/lib/team.php` 19947, `private/lib/activity.php` 8140, `private/lib/auth.php` 1379.

**2. Database** (before uploading). phpMyAdmin › your database (`dreamhub_members`) › **SQL** tab › paste the contents of `private/schema_phase10.sql` › Go. Run it **once**. It adds the person types, the pause switch, a table for the ticks, and gives every current active member the tools they see today (everything except Monthly Report and the Site Admin tools) so nobody loses anything on day one.

**3. Upload.** Upload the zip to your home folder and **Extract**.

**4. Connect Cloudflare** (so add / remove also changes Zero Trust). Everything else works without this; the screen just shows an amber "Cloudflare is not connected yet" bar.
 1. Cloudflare dashboard › profile icon (top right) › **My Profile › API Tokens › Create Token › Create Custom Token**.
 2. Permissions (two rows): **Account › Access: Apps and Policies › Edit**, and **Account › Access: Organizations, Identity Providers, and Groups › Edit** (this one signs removed people out).
 3. Account Resources: **Include › your account only**.
 4. Optional but recommended, Client IP Address Filtering: your cPanel server's IP (cPanel home page, right-hand side, "Shared IP Address"). Then the token only works from your website.
 5. Create, and copy the token (shown once).
 6. Open `private/config.php` in cPanel's editor and add this inside the main `return [ … ];`, with a comma on the line before:
 ```php
     'cf_api' => [
         'token'      => 'PASTE_THE_TOKEN',
         'account_id' => 'PASTE_ACCOUNT_ID',   // the 32-character code in the Cloudflare address, right after dash.cloudflare.com/
         'policy_id'  => 'PASTE_POLICY_ID',    // Zero Trust › Access controls › Policies › Email Access: the code in the address after /policies/
     ],
 ```
 Never paste the token anywhere else.
 7. If the screen shows a red "Could not read the Cloudflare list" bar, press **Run Cloudflare check**. It tests each step (settings, token, account, policy) and says in plain words which one fails.

**5. Check it works.**
 1. Open **Members & Access**: you at the top, "Cloudflare connected" in green or amber.
 2. Open Monthly Report in a private window signed in as a non-admin (or ask a friend): you should get "You don't have access to Monthly Report".
 3. Tick Monthly Report for that person; reload their toolkit page: the card appears.
 4. Add a test email, then check it appeared in Cloudflare Zero Trust › Policies › Email Access. Remove it again and check it vanished.

**6. Tidy the people.** Open each existing person: set the right **type** (an admin or a tester becomes Admin / Tester and leaves your team tree, as long as nobody is under them) and trim the tools.

## If something goes wrong

- **Tool pages misbehave:** delete `public_html/members/.htaccess`. The gate switches off at once (the toolkit cards still follow the ticks). Tell Claude what you saw.
- **Back to how it was:** restore your two backup zips. The new database table and column are harmless to leave in place.

## Good to know

- **Pause** blocks the person at the login check every page and data call uses, so it stops everything on this site at once (they can still pass Cloudflare). It also switches their Client Portal off, and Resume switches it back on if it was on. To remove someone completely, use Remove.
- **Client Portal** is now a normal switch here. It uses the same record as the Team page switch (through `e3-client-portal/team-bridge.php`), so the two always agree and you can use either. Its card shows only for people it is switched on for. Nobody's current Client Portal setting is changed by this update. Presets: "None" and Pause switch it off, "All tools" and "Copy from" include it, "Basic set" does not.
- If the bridge file ever goes missing, only the Client Portal switch shows "Unavailable"; everything else keeps working.
- **HNW Coach** opens in Gemini: switching it off only hides the card. Gemini's own sharing is what really blocks it.
- **Team members** are still removed on the Team page (it looks after their team and candidates). It now also removes them from Cloudflare and signs them out.
- The gate protects tool **pages**. Tools that save data keep their own server checks as before.
- Adding a new tool later: add it to `private/lib/tools.php` **and** give the card the same `key` in `apps.js`.
- Tests: `php tests/access.php` and `php tests/access_team.php` (run in the full site folder).


---

# Action Plan (phase 11, step 1: the core)

A new **Action Plan** card (in the **Coaching** group, next to HNW Coach): one template, a plan per new associate, tick / count / approve, and an overview.
Design: `action-plan/DESIGN.md`. This is **step 1 only**: auto-counts from the Client Portal / Career Code, DISC from Career Code, WhatsApp nudges and the printable Day-90 review come later.

## What it does
- **Template (you edit it once):** your sections and tasks, with suggested weeks. Add, edit, turn off, remove (archives if anyone finished it), reorder; add, rename, turn off sections.
- **Assign:** Everyone > **+ Assign a plan**: pick a Team member, a start date, a plan length (default 90 days). It switches the Action Plan card on for them, and for any team admin above them (who approves).
- **Agent:** sees only their own plan: "Week 4 of 13", **Due this week**, **Still to finish**, follow-ups, and every section. Taps **Mark done** or counts with + / −. Weeks are a guide: they see "Suggested by week N", never "overdue" or "Behind".
- **You and team admins:** Everyone (overview with On track / A little behind / Behind), **Approvals** (Approve, or Send back with a note), and each person's page.
- **Only you:** the template, assigning, a person's start date and length, DISC style, your private number, "Not needed for this person", changing a week or target for one person, and extra items for one person.
- **Team admins** (Group Managers switched on as team admins on the Team page) approve and send back only for people below them. Nobody approves their own items.

## Install (in this order)
1. **Back up** `public_html/members` and `private/lib` as before.
2. **Database:** phpMyAdmin > your database > SQL tab > paste `private/schema_phase11.sql` > Go. Run it **once**. It only adds five new tables; nothing existing changes.
3. **Upload and extract the zip in your home folder** (above `public_html`). It overwrites files with the same names.
4. Open the toolkit: an **Action Plan** card appears for you. Open it, press **Everyone > + Assign a plan**, and set up your first associate. The starting template is already filled in from your spreadsheet.

Time zone: dates follow `Asia/Kuala_Lumpur`. To change it, add `'timezone' => 'Region/City',` to `private/config.php`.

## Good to know
- Existing members do **not** get the card automatically; assigning a plan switches it on for that person. You can also tick it for anyone in Members & Access.
- Turning an item **off** hides it for everyone and loses nothing. **Removing** an item nobody has touched deletes it; if anyone finished it, it is archived so their history stays.
- The `.htaccess` gate now also protects `/members/action-plan/`.
- Tests: `php tests/actionplan.php` (119 checks), plus the earlier suites. A browser test harness is in `tests/ap_harness.php` (not for the live site).


---

# Action Plan step 2: automatic counts (phase 12)

Numbers that fill themselves in, so agents do not type them and you do not have to trust a self-reported count. **Only numbers** cross over: the portal's notes stay encrypted and are never read, and no client or candidate name is sent anywhere.

| Plan item | Counted from | Rule |
|---|---|---|
| Prospect list (100) | Client Portal | prospects on the list now |
| Natural market survey (70) | Client Portal | notes of type **Open** since the start date |
| Presentations (50) | Client Portal | notes of type **Present** since the start date |
| New business (3) | Client Portal | cases closed since the start date, with the **ANP so far** shown |
| Career Introduction 1+2 | Career Code | candidates added since the start date (a tick item: the number shows beside it) |
| DISC style | Career Code | the style from the candidate's test, matched by email; a style you set by hand wins |

- **The date range** is the person's plan start date up to today.
- **When a number reaches its target by itself,** the item goes to the approval list (an agent cannot "Mark done" a count).
- **No portal (or it is switched off) for someone?** Nothing breaks: those items stay counts they enter by hand, with a hint to switch the portal on.
- **Any item can use it:** Template > Edit > "Counted automatically from". The choices are the rows above plus "Recruit notes".

## Install
1. **Database:** phpMyAdmin > SQL tab > paste `private/schema_phase12.sql` > Go, **once**. No new tables; it only switches the automatic count on for the five items above in your existing template.
2. **Extract the zip in your home folder** (overwrites the same-named files).
3. Open someone's plan > **Plan settings > Check the automatic counts**. It lists what it found (note types and their counts, the closes table's columns, ANP). If a number looks wrong, send me that screen.

## What I could not check
I have not seen the Client Portal's database, only how its pages use it. Counting notes by type and prospects follows the portal's own code. The **closes** table (cases closed) is the least certain: its columns are found by name, and the check screen shows what was found. If it cannot recognise the date, it says so and that item stays manual.
