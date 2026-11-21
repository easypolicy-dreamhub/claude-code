# Rules for this repo (DreamHub E3 members toolkits)

These tools run on the owner's cPanel hosting, not from this repo. The owner uploads zips by hand in cPanel File Manager. Every session must package and explain uploads the same way.

## Packaging a zip for upload (always)

1. **Zip root = `public_html`.** Every file inside the zip carries its full path from `public_html`, for example `members/agency-report/index.html`. Never a flat zip, never a zip that only works from inside a tool's own folder.
2. **One instruction, always the same:** upload the zip into `public_html`, tap **Extract**, check the destination says `/public_html`. The files then land in their own folders by themselves.
3. **Back up first, every time:** before extracting, copy each file that will be overwritten to `<name>-backup.html` (or `.php`) in its folder. Say how to roll back.
4. **Prove it before sending:** extract the zip into a fake `public_html` and list where each file landed. Confirm files that must not change (for example `photos.php`) are not in the zip. Say so in the message.
5. **Do not invent other upload locations.** If a tool is not under `members/`, ask the owner once for its real folder, then record it in the table below.
6. Never put passwords, API keys, Cloudflare tags or allowed-email lists into files in this repo or in a zip. Who may use a tool's server files (for example `photos.php` and `drafts.php`) comes from the members system in `private/` (`boot_member_request()` and `can_use_tool()`), never from a list inside the file. Give every helper function and constant in such a file a unique prefix (`ar_`, `AGENCY_`), because `portal.php` loads another file into the same request.

## Where each tool lives on the server

| Repo folder | Server folder (under `public_html`) | Confirmed |
|---|---|---|
| `agency-report/` | `members/agency-report/` (tool key `agency-report`) | yes |
| `legacy-planner/` | `members/legacy-planner/` | yes (from `/members/apps.js`) |
| `monthly-report/` | `members/monthly-report/` | yes (from `/members/apps.js`) |
| Client Portal (not a repo folder; owner sends the live files) | `client-portal/` directly under `public_html` (not under `members/`), main file `index.php`, with `lib/` and `.htaccess` that a zip must never touch | yes (cPanel screenshot, 2026-11-21) |
| `ai-coach/`, `scientific-calculator/`, `qr-resized/` | not listed in `/members/apps.js`: ask the owner | no |

`/members/apps.js` is the list of tools and their folders (`/members/<folder>/`). Each tool's `key` ties it to Members & Access and `private/lib/tools.php`, so a new tool also needs its key added there.

## Standard header

Every toolkit page loads the shared stylesheet `/members/e3-toolkit.css` (do not copy its rules into a page) and uses this markup: `<div class="e3-bar">` (back link to `/members/` and the "DreamHub E3" name), then `<header class="topbar">` with `<h1>Title<small>subtitle</small></h1>` and any buttons or a `<div class="who">`. `agency-report/index.html` shows it. A new toolkit without the shared header is not finished. The shared file is owned by the server, not this repo, so never change header styling in a single page.

## Working style

- Say what was and was not tested. Do not say something cannot be done before checking.
- Keep a handover note per tool (see `HANDOVER-agency-report-generator.md`) and update it when behaviour changes.
