# Client testimonial form + admin inbox

Clients open a link, fill in a testimonial, and it waits as **pending** until the admin approves it.
Approving copies it into the existing `testimonials` table (via `testi_create`), so the existing admin list,
"shown" switch, consent rule and audit log work as before.

## Files (same paths as on the server)

| Repo path | Upload to |
|---|---|
| `sql/testimonial_submissions.sql` | Run once in phpMyAdmin (SQL tab). Do not upload. |
| `private/lib/testimonial_submissions.php` | `private/lib/` |
| `public_html/site-api/testimonial-submit.php` | `public_html/site-api/` |
| `public_html/share-testimonial/index.html` | `public_html/share-testimonial/` |

Link to send a client: `https://dreamhub-e3.com/share-testimonial/?n=Aisyah` (`n` is optional; it greets them and fills in the name).

Cloudflare Access must NOT cover `/share-testimonial/` or `/site-api/testimonial-submit.php`.

## Still to do

Admin "Pending" list (Approve / Edit / Reject). Uses `tsub_list_pending`, `tsub_approve`, `tsub_reject`; waits on the existing admin testimonial page and its API file.
