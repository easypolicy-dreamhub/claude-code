# Phase 1: database, Cloudflare token check, members

## What's here
| Path | Purpose |
|---|---|
| `private/schema.sql` | `members` + `audit_log` tables (MySQL/MariaDB) |
| `private/lib/cf_access.php` | Verifies Cloudflare's signed login token (RS256, issuer, audience, expiry, key rotation) |
| `private/lib/auth.php` | Token -> active row in `members`, else 401/403 |
| `private/lib/tree.php` | Upline-tree visibility (self + whole downline; rank labels never matter) |
| `private/lib/members.php` | Create/edit/deactivate members, cycle + last-admin guards, audit log |
| `public_html/members/api/me.php` | Who am I + which member ids I may see |
| `public_html/members/api/members.php` | List (everyone for admin, own tree otherwise), add, edit (admin) |
| `private/bin/create_admin.php` | Creates the first admin |
| `tests/run.php` | `php tests/run.php` (47 checks, no network or MySQL needed) |

## Deploy on cPanel
1. **Database:** cPanel > MySQL Databases: create a database + user, grant all. Open phpMyAdmin, select the DB, import `private/schema.sql`.
2. **Upload** `private/` **above** `public_html/` (sibling folders) and `public_html/members/api/` into your existing `members/` folder. (If `private/` must stay inside `public_html`, its `.htaccess` blocks web access.)
3. **Config:** copy `private/config.sample.php` to `private/config.php`; fill in DB details, team domain and AUD tag (Zero Trust > Access > Applications > your members app > Overview). Create `private/cache/` (writable, mode 700).
4. **Cloudflare:** make sure the Access application covers `/members/*` (this includes `/members/api/*`).
5. **First admin** (cPanel Terminal): `php private/bin/create_admin.php you@example.com "Goh Cheng Fee" gcf`. Use the same email you log in with.
6. **Check:** log in at `/members/`, then open `/members/api/me.php`. You should see your member record and `is_admin: true`. A logged-in email that is not in `members` must get 403.
7. **Add agents:** `POST /members/api/members.php` (JSON, header `X-Requested-With: fetch`) with `email`, `name`, `upline_id`, `rank`. A dashboard screen for this comes in phase 3.

## Two lists to maintain
Cloudflare allow-list = who can log in. `members` table = what they can do. A new agent needs both.

## Not covered yet (later phases)
Candidates, test submission, emails, dashboard UI, activity page, landing page buttons, advisor mode.
