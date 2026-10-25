# Phase 4: advisor live session and paper-score entry

Pages (all behind Cloudflare Access, any active member): `/members/career-code/` (home), `/live/`, `/manual/`.
Server: `public_html/members/api/live.php`, `private/lib/live.php`. Replaces the old PIN-protected app (no PIN any more).

| Page | What it does |
|---|---|
| Home | Tiles for live test (career / internship), paper scores, candidate list; the advisor's two personal links with Copy buttons |
| Live test | The same questionnaire as the public page, run by a logged-in advisor sitting with the person. No spam check, email optional, consent ticked on the device. Result is shown on screen; "Open advisor view" jumps to the notes. Closing the page mid-test offers to resume (own saved key, separate from the public page) |
| Paper scores | Type the A / B / C / D totals per type from a paper test. Shows totals and the code as you type. Part D total outside 42 to 54 asks for confirmation before saving |

## Rules
- The advisor running the session becomes the candidate's owner (source "live"). A person already in the system stays with their first advisor; the advisor who met them is shown as "Link used" and is not emailed about their own session.
- The advisor in the room is never emailed about their own candidate; uplines are, exactly as for website candidates. The candidate gets their result email only if an address was entered.
- Scores are always computed (live test) or range-checked (paper: A,B 0-11, C 0-14, D 2-14) on the server.
- Every start/save carries a random `client_id`; sending the same one twice returns the same candidate and saves nothing twice. A paper entry made with no connection waits in the device's storage and is sent automatically when back online (a refused entry is dropped so it cannot block others).
- Starting a live test needs a connection; an already started test keeps its answers on the device until it can be submitted.
- The dashboard shows "Live session (paper scores typed in)" for typed-in entries; the typist is stored with the assessment.

## Install
1. phpMyAdmin > `dreamhub_members` > SQL: run `private/schema_phase3c.sql` (if not done), then `private/schema_phase4.sql`.
2. File Manager: rename `public_html/members/career-code/index.html` (the old app) to `index-old.html`, then extract the zip over `public_html` and `private`.
3. Re-upload `members/apps.js` is included in the zip. Purge the Cloudflare cache.

Tests: `php tests/phase4.php`, `node tests/e2e_live.js`.
