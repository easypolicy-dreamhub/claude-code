# Team page (`/members/team/`)

The tree here decides who sees whose candidates in every tool (Career Code now, future apps later).

## Who can do what
| Person | Sees | Can change |
|---|---|---|
| Super admin (you) | Everyone | Everything: add, ranks, move, remove, remove whole group, reactivate, team admins, details |
| Team admin (a Group Manager you chose) | Themselves + their group | Ranks, moves and removals of people **below** them, only within their group |
| Everyone else | Themselves + everyone below them | Nothing |

## Rank rules (always previewed; untick any suggested move)
- **Promotion above the upline's rank:** breaks away with their whole team to the nearest leader above with a high enough rank (usually you).
- **Unit Manager equal to a Unit Manager upline:** stays (parallel).
- **Group Manager directly under a Group Manager:** you choose: stay in the system (confirm) or opt out (Remove whole group).
- **Group Manager relegated to Unit Manager:** stays under their mother GM; their Unit Managers stay under them as parallels (GE circular CAD/1602/DEC2013/DT/SM, as given 29 Apr 2026); team admin is removed.
- **Unit Manager demoted to Agent:** their agents, and any Unit Manager under them, move up to their upline.
- **Nobody returns automatically** to a former upline after a demotion.

## Manual actions
- **Move:** put anyone under anyone (not under their own team). "Move their team with them" can be unticked to move only that person. Placements that break the rank rules are allowed and marked ⚠ in the tree.
- **They left the business:** switched off (not erased). Their agents and, by default, their candidates go to their upline or someone you choose.
- **Remove whole group:** download a copy (CSV) of their candidates, type REMOVE; everyone in the group is switched off and their candidates are permanently deleted. The page then lists the emails to delete from the Cloudflare Zero Trust list.
- **Reactivate:** brings someone back under who you choose, optionally with the people removed in the same action.
- Every change is recorded in `audit_log` with who did it.

## Deploy
1. phpMyAdmin > `dreamhub_members` > SQL: run `private/schema_phase3b.sql` (adds `is_team_admin` and `removal_batch`).
2. Upload and extract the zip in your cPanel home folder.
3. Replace `public_html/members/apps.js` (adds the "Team" card under Leader Tools).

## Tests
`php tests/team.php` (56 scenario checks: your promotion/demotion cases, overrides, removals, team admin limits)
and `node tests/e2e_team.js` (29 browser checks with real signed login tokens).
