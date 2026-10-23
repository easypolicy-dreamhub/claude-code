# Phase 3: candidates dashboard and access rules

Page: `/members/career-code/dashboard/` (behind Cloudflare Access like the rest of `/members/`).
The advisor emails link to `?candidate=<id>`.

## Who sees what (enforced on the server, in `private/lib/candidate_access.php`)
| Person | Candidates they can see |
|---|---|
| Admin (you) | Everyone's |
| Anyone else | Their own + everyone below them in the upline tree (any depth) |

Rank labels play no part. A cannot see B's team; AA sees only AA's own. A candidate someone may not see is
reported as "not found", so even its existence is not revealed.

| Action | Who |
|---|---|
| View list / detail / advisor notes | Whoever can see that candidate |
| Change follow-up status (New, Contacted, Interviewed, Joined, Not suitable) | Whoever can see the candidate |
| Reassign to another advisor | Whoever can see the candidate, but only to themselves or someone in their own team. Admin: any active member. |
| Export CSV, delete a candidate (PDPA request) | Admin only |

Note: when an upline takes a candidate over (assigns to themselves), the previous owner no longer sees it.
Every status change, reassignment, export and deletion is written to `audit_log` (deletion keeps no personal data).

## Advisor notes
Verdict, criteria, score pattern, reasons, watch-outs and interview questions come from `private/lib/advisor.php`,
a port of the original app's rules (thresholds S/E 25 with A+B+C 15, C 25). A test runs the ORIGINAL JavaScript
against the PHP on 3,000 random score sets and requires identical output (English and Chinese). The notes are
only ever sent to a member who may see that candidate. There is no PIN.

## Files
| Path | Purpose |
|---|---|
| `private/lib/advisor.php`, `private/data/advisor_text.json` | Assessment rules and wording |
| `private/lib/candidate_access.php` | List, detail, status, reassign, delete, CSV, all with the access rules |
| `public_html/members/api/candidates.php` | The API the dashboard uses |
| `public_html/members/career-code/dashboard/` | The page (`index.html`, `dashboard.js`) |
| `public_html/members/apps.js` | Your toolkit list with the new "Career Code Candidates" card (replace your copy) |

## Deploy (on top of phases 1 and 2)
1. Upload and extract the zip in your cPanel home folder. It adds a `dashboard` folder inside your existing
   `members/career-code/` and does not touch the old Career Code app.
2. Replace `public_html/members/apps.js` with the new one to show the card.
3. Log in as admin, open the toolkit, tap "Career Code Candidates".

## Tests
`php tests/phase3.php` (access rules), `php tests/advisor_equivalence.php` (PHP vs original JS),
`node tests/e2e_dashboard.js` (browser, real signed tokens for admin, a unit manager, an agent, a stranger, a forger).

## Not yet
Live/manual entry for advisors replaces the old app in phase 4 (the PIN note on the old card goes then).
Activity page: phase 5. Hiding the "Leader Tools" cards from non-leaders needs a server check (the toolkit is a static page).
