# Phase 5: Team Activity page

`/members/activity/` (card in the toolkit under Leader Tools). Server: `public_html/members/api/activity.php`, `private/lib/activity.php`.

- Same visibility as the candidate list: admin sees everyone; anyone else sees themselves and everyone below them. Other branches never appear (numbers, names or feed).
- Period (last 7 days / 30 days / all time): candidates added, tests finished. "Joined" is all-time.
- "Where everyone stands now": finished tests by follow-up status.
- "Needs follow-up": finished tests still New after 3 days (up to 20, longest first).
- People: one row per person, indented as the tree, with added / finished / joined (their own candidates only) and when they last did something. Removed members show greyed only if they own candidates. Tapping a name opens the candidate list filtered to that person (`dashboard/?owner=ID`).
- Recent activity: new candidates, finished tests, status changes and reassignments (30 newest).
- No scores, advisor notes, phone numbers or emails are in this page's data. No database change.

Tests: `php tests/phase5.php`, `node tests/e2e_activity.js`.
