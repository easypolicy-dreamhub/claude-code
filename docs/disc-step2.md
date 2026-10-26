# DISC step 2: resumes, and the Team Activity lists

SQL: `private/schema_phase8.sql` (table `candidate_files`). Files are stored in `private/uploads/resumes/` (outside `public_html`), random names, one resume per candidate (a new upload replaces the old one).

## Resume
- **Candidate:** on the first DISC screen. Required for internship candidates (the Start button refuses without one, and the server refuses to finish the questionnaire without one, including in-the-room sessions). Optional for career, with "You can also send it to your advisor later". Needs the same link + last 4 digits.
- **Advisor:** a Resume section on the candidate page: Download, Upload/Replace (for one the candidate sent by WhatsApp), Remove. List pill "Resume: not yet" on finished internship candidates without one.
- **Accepted:** PDF, DOC, DOCX, up to 5 MB, and the file's first bytes must match its type (a renamed program or picture is refused; a .docx must contain Word content).
- **Who can download:** the owner and their uplines (admin: all), through `/members/api/resume.php`; every download is audited. Other branches get "not found".
- **Deleted with the candidate** (single delete and whole-group removal).
- **Consent:** the DISC consent line now covers the resume.

## Team Activity
- **Ready to invite to DISC:** finished Career Code with Strong / Good / Borderline, not Not-suitable/Joined, not invited yet.
- **Waiting on DISC:** invited but not done after 3 days, or the link expired.
- Same scope rules as everything else on that page.

Tests: `php tests/phase8.php`, `node tests/e2e_resume.js`.

## Toolkit header (all Career Code and Team pages)
The six toolkit pages (Career Code home, Live test, Enter paper scores, Candidates, Team, Team Activity) now use the same header as Agency Report Generator: the slim dark bar ("E3 Toolkit" back link, DreamHub E3 name) and the white title strip (page title, subtitle, buttons). The shared styling lives in `public_html/members/e3-toolkit.css` (linked with `?v=1`; bump the number when it changes). Titles match the toolkit card names, except the sub-pages (Live test, Enter paper scores). Candidate-facing pages (website test, DISC) are unchanged.
