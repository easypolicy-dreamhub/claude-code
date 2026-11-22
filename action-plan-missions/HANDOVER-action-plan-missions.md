# Handover: Action Plan missions (stages, badges, rewards)

Built 22 Nov 2026 on top of the LIVE `actionplan.php` and `index.html` the owner pasted into the chat that day (not the repo copies).
It adds to the Action Plan described in `HANDOVER-action-plan.md` (on branch `claude/youthful-mayer-ff43p6`); where this note differs, this note wins for the features below.

## What it does
- **Stage** = a template section. Agents see "Stage N · name". N counts only active sections that have items on that plan. Nothing is locked.
- **Stage complete** = every item in the stage that is not skipped is approved. Extra items for one person do not count.
- **Badge**: each stage has a badge title (super admin, Template screen, same for everyone). Earned = stage complete. The plan shows a Badges strip (earned in gold, others greyed with the title visible). The Day-90 review prints "Badges earned".
- **Pop-up**: when a stage is complete the agent sees a congratulations pop-up (badge, reward, confetti; confetti off for "reduce motion") the next time they open "My plan". Once per stage (`seen_at`). Re-opening and re-completing a stage does not pop up again. Stages completed BEFORE go-live (`ap_settings.missions_from`, set by the SQL to the day it was run) never pop up.
- **Reward**: super admin sets a template reward (title + description) per stage. The plan's coach (or super admin) can replace it for that one person ("Rewards and badges" panel on the person's plan; blank = back to the template). Team admins who are not the coach cannot.
- **Show or hide upcoming rewards**: default on the Template screen (checkbox). Per plan the coach or super admin chooses Follow default / Show / Hide. The agent always sees the reward of a COMPLETED stage. When hidden, the server does not send the upcoming reward at all (not only hidden on screen).
- **Reward given**: the coach / super admin ticks "Reward given ✓" (only on a completed stage that has a reward). The new **Rewards** tab (super admin and coaches) lists "To give" and "Given" with a Mark as given button. A coach sees only the plans they coach; the super admin sees all.
- **Skipping**: the plan's coach can now skip a task (button Skip / Bring back on the person's plan) with a REQUIRED reason, recorded in the audit log. Weeks and targets stay super-admin only. The super admin also needs a reason now (was optional). **At most half of a stage's tasks can be skipped, for everyone including the super admin** (5 tasks: 2, 4 tasks: 2, 1 task: 0). A task that suits nobody should be removed from the template instead.
- Internship is unchanged.

## Files (zip root = the home folder, which holds `public_html` and `private`)
| In the zip | Server location |
|---|---|
| `private/lib/actionplan.php` | next to `public_html`, in `private/lib/` |
| `private/schema_phase17.sql` | run once in phpMyAdmin (copy of the text is also in this folder) |
| `public_html/members/action-plan/index.html` | `public_html/members/action-plan/` |

Not in the zip and not changed: `api/actionplan.php` (a pass-through to `ap_dispatch()`), `tools.php`, `apps.js`, `.htaccess`, the toolkit `index.html`, `actionplan_auto.php`, `actionplan_seed.php`.

## Database (schema_phase17.sql, run BEFORE uploading)
Only adds. `ap_sections`: `badge_title`, `reward_title`, `reward_desc`. `ap_plans`: `reveal_rewards` (NULL = follow default). New tables `ap_settings` (`reveal_default`, `missions_from`) and `ap_plan_stage` (per plan and stage: the coach's own reward, `given_at`, `given_by`, `seen_at`). Suggested badge titles are filled in for the 7 starting sections only where empty. Running it twice gives "Duplicate column name": harmless.
If the page is uploaded before the SQL: reads fall back safely (no stages data), but saving a reward / given / template change will fail until the SQL is run.

## New API
GET `view=rewards`. POST `stage_reward`, `stage_given`, `plan_reveal` (plan's coach or super admin), `stage_seen` (the agent), `reveal_default` (super admin). `section_update` also takes `badge_title`, `reward_title`, `reward_desc`. `override` is now allowed for the plan's coach for skip / un-skip only. Errors are 4xx (never 502/504).

## Tested / not tested
Tested here: PHP syntax; the new stage / reward / reveal / pop-up-once / skip-limit logic with a sqlite test (35 checks); the page in a real browser with a simulated server (30 checks: pop-up, confetti, badges, hidden and shown rewards, coach panel, skip needing a reason, Rewards tab, Template screen), no script errors.
NOT tested: against the real database and the real members system (`members.php`, `boot_member_request()` etc. are not in the repo), so the existing `tests/actionplan.php` could not be run: run it on a copy. The only existing behaviour deliberately changed that old tests may assert: skip note is now required, and `override` by a non-super now needs to be the plan's coach.
Day-90 review logo: the pasted logo line was too long to copy exactly, so the logo data in `index.html` was taken from the repo copy (a complete PNG whose first 2,218 characters matched the pasted line). Check the review's print preview; if the logo differs, send the live line.

## Roll back
Before extracting, in cPanel File Manager copy `private/lib/actionplan.php` to `actionplan-backup.php` and `public_html/members/action-plan/index.html` to `index-backup.html` (same folders). To roll back, delete the new file and rename the backup. The database additions can stay; the old code ignores them.

## Update 22 Nov 2026 (later): badge icons and the icon picker (schema_phase18.sql)
- Each stage has a **badge icon**, chosen on the Template screen (✎ on a stage > "Badge icon", 12 choices: leap, book, bolt, flag, scout, shield, cup, star, rocket, team, target, heart). Style A: a solid gold medal with a white line icon; unearned badges are grey with the title still visible.
- Shown on: the Badges strip, the congratulations pop-up, the Rewards tab, the Template stage headers, and the Day-90 review ("Badges earned"). No icon, or an unknown one, shows the star.
- Stored in `ap_sections.badge_icon` (`schema_phase18.sql` adds the column and sets the starting icons for the seven original sections). The server accepts only the 12 keys in `AP_BADGE_ICONS` (`actionplan.php`); the drawings live in `ICONS` in `index.html`. **Keep the two lists the same** when adding an icon.
- Built from the live files pasted by the owner on 22 Nov (checked against the previous zip; 12 distinctive lines matched). Files in the zip: `private/lib/actionplan.php`, `private/schema_phase18.sql`, `public_html/members/action-plan/index.html`. Upload: run the SQL first; zip into the HOME folder (holds `public_html` and `private`).
- Tested: PHP syntax; stage / reward / icon logic with sqlite (43 checks, including: unlisted icon refused and a refused save changes nothing); the page in a browser with a simulated server (35 checks, picker included). Not tested against the real database.
