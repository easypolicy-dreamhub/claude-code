# Phase 2: public Career Code test, saved results, emails

Visitors open `/career-code/` (public, not behind Cloudflare Access), fill in details, take the 4-part test and see
their result. The server saves everything, recalculates the scores itself, emails the candidate, and tells the
assigned advisor and their uplines (link only). Nothing links to the page yet: phase 6 adds the landing page buttons.

## Who owns a public candidate
1. `?ref=amy` in the link -> Amy (if she is an active member), else
2. `default_owner_email` in config (you).
3. Same mobile or email seen before -> stays with the FIRST advisor, flagged `duplicate_of`.

URLs: `/career-code/` (career), `/career-code/?p=internship`, add `&ref=<referral_code>` for an agent's personal link.
Add `&lang=zh` to open in Chinese.

## What was built
| Path | Purpose |
|---|---|
| `private/schema_phase2.sql` | `candidates` + `assessments` tables |
| `private/lib/scoring.php` | Scores from raw answers (never trusts browser totals) |
| `private/lib/candidates.php` | Start, duplicates, owner, rate limit, submit, notify |
| `private/lib/emails.php`, `mailer.php` | Candidate result email, advisor notice, server-mail and SMTP senders |
| `private/lib/turnstile.php` | Cloudflare spam check |
| `private/data/holland.json` | Type descriptions for emails (a test keeps it equal to the page's) |
| `public_html/career-code/` | The page, shared questionnaire data, `api/start.php`, `api/submit.php`, `site-config.js` |
| `tests/` | `php tests/run.php`, `php tests/phase2.php`, `node tests/e2e.js` (browser, real endpoints) |

## Deploy
1. **Tables:** phpMyAdmin > `dreamhub_members` > SQL tab > paste `private/schema_phase2.sql` > Go.
2. **Upload** the phase 2 zip into your cPanel home folder (the one containing `public_html`) and Extract. It never touches `private/config.php`.
3. **Turnstile** (free): Cloudflare dashboard > Turnstile > Add widget. Hostnames: `dreamhub-e3.com` and `www.dreamhub-e3.com`. Mode: Managed. Copy the **Site key** and **Secret key**.
   - Site key (public) goes in `public_html/career-code/site-config.js` (`turnstileSiteKey`).
   - Secret key goes in `private/config.php` under `turnstile`.
4. **Config:** add the phase 2 block from `private/config.sample.php` to your real `private/config.php` (base_url, default_owner_email, ip_salt, turnstile, mail, contact).
5. **Try it:** open `dreamhub-e3.com/career-code/`, do the test with your own email and phone. You should get the result email, and your own advisor notice.
6. Check in phpMyAdmin that `candidates` and `assessments` each have a row.

## Known limits / to check on the real server
- Not yet run against real MySQL, real Cloudflare Turnstile or real mail delivery (tests use SQLite, fake servers). First real run may need a small fix.
- `'transport' => 'mail'` uses the server's mail system; if messages land in spam, switch to `'smtp'` with your mailbox. SMTP over TLS (465/587) is written but only the plain path was tested.
- The advisor notice links to `/members/career-code/dashboard/?candidate=N`. That page arrives in phase 3; until then the link will 404.
- The consent wording (PDPA 2010) is my draft. Have it checked before going public.
- The internship purpose is stored and shown in emails; the different advisor view for interns comes with the dashboard.
