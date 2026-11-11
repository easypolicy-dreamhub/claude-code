# Client testimonial form + admin approval

Clients open a link, write a testimonial, and it waits in your admin page under **Waiting for your approval**.
You Approve (optionally edit first) or Reject. Approved ones join your normal testimonial list.

## Upload steps (cPanel)

Do them in this order. Take a backup of the 2 existing files first (step 1).

1. **Backup.** In File Manager, right-click each of these and choose Copy, adding `.bak` to the name:
   - `public_html/members/api/testimonials.php` → `testimonials.php.bak`
   - `public_html/members/testimonials/index.html` → `index.html.bak`
2. **Create the table.** cPanel → phpMyAdmin → click your site database on the left → **SQL** tab →
   paste everything in `sql/testimonial_submissions.sql` → **Go**.
3. **Upload the files** (File Manager → go to the folder → Upload; replace when asked):

   | File in this folder | Upload into |
   |---|---|
   | `private/lib/testimonial_submissions.php` | `private/lib/` (new file) |
   | `public_html/site-api/testimonial-submit.php` | `public_html/site-api/` (new file) |
   | `public_html/share-testimonial/index.html` | `public_html/share-testimonial/` (create this folder first) |
   | `public_html/members/api/testimonials.php` | `public_html/members/api/` (replaces the old one) |
   | `public_html/members/testimonials/index.html` | `public_html/members/testimonials/` (replaces the old one) |

4. **Check Cloudflare.** Zero Trust → Access → Applications: make sure no rule covers `/share-testimonial` or
   `/site-api`. (Your `/members` rule stays as it is.)
5. **Test.** Open `https://dreamhub-e3.com/share-testimonial/?n=Test` in a private/incognito window
   (it must open without asking you to log in), send a test testimonial, then open your admin
   Testimonials page: it appears under "Waiting for your approval". Reject it.

**Undo:** rename the two `.bak` files back. The new table and new files do nothing on their own.

## Daily use

Admin → Testimonials → **Ask a client for a testimonial** → type the client's name (and WhatsApp number) →
**Send by WhatsApp**. When they reply, approve it from the same page.

## What changed in the existing files

- `members/api/testimonials.php`: GET also returns `pending`; new POST actions `approve` and `reject`.
- `members/testimonials/index.html`: "Ask a client" box, "Waiting for your approval" list, and an
  "approve" mode in the existing edit form (consent pre-ticked because the client agreed on the form).
