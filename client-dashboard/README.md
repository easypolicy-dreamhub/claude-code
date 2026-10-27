# Client Details

A members-area page that shows each agent their own e-Partner clients — on
phone, tablet or laptop — without the site ever touching an e-Partner
password. Built the same way as `ai-coach`: it reuses the site's existing
member login and never runs its own.

## How data gets in

e-Partner's agent portal does not have a download/export button, and its
sign-in is RSA-locked and sits behind Imperva bot protection — not something
this site should try to sign in through on an agent's behalf. Instead, each
agent already has a **GE Refresh** browser bookmark (built separately,
outside this repo) that, from their own signed-in e-Partner tab, reads their
policy listing and downloads `portfolio_update_<date>.json`.

This page adds one more step: the agent imports that file here, so it also
shows up on their phone and tablet, not just the computer they ran the
bookmark on.

```
Agent's e-Partner tab (their own login)
  → GE Refresh bookmark → portfolio_update_<date>.json (downloaded)
  → here: "Import update file" → api.php?action=upload
  → saved under the agent's own site login, nowhere else
  → dashboard renders on any signed-in device
```

## Deploy on cPanel

1. Upload this folder to `public_html/members/client-dashboard/`.
2. In the members site's own `config.php` (one level up), make sure
   `boot_member_request()` exists and returns the signed-in member's email
   as `id` or `email`. `site-bridge.php` calls it — adjust the two marked
   spots there if your function name or return shape differs.
3. Optional: set `CD_DATA_DIR` in `config.php` to a folder **outside**
   `public_html` (see `config.sample.php`). If you skip this, it defaults to
   `e3-client-dashboard` next to `public_html`, the same pattern as
   `e3-agency-photos`.
4. Add a Cloudflare Cache Rule that bypasses cache for
   `/members/client-dashboard/*` (the page already sends
   `Cache-Control: no-store`, but a rule avoids any Cloudflare edge caching
   too).
5. Link to `/members/client-dashboard/` from a "Client Details" card on the
   members home page.

## Data and access

- Each agent's data is stored as one file, named by a hash of their site
  login id — never by their real name or email — in the private data
  folder.
- `api.php` only ever reads or writes **the signed-in agent's own file**.
  There is no endpoint that lists agents or returns another agent's data.
- Nothing here stores or asks for an e-Partner password.
- Policy and client details are personal data (PDPA). Even stored this way,
  confirm with your agency that keeping client data on the website is
  acceptable before agents other than you start using this.
