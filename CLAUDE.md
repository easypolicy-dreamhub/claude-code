# Rules for this repo (DreamHub E3 members toolkits)

These tools run on the owner's cPanel hosting, not from this repo. The owner uploads zips by hand in cPanel File Manager. Every session must package and explain uploads the same way.

## Packaging a zip for upload (always)

1. **Zip root = `public_html`.** Every file inside the zip carries its full path from `public_html`, for example `members/agency-report/index.html`. Never a flat zip, never a zip that only works from inside a tool's own folder.
2. **One instruction, always the same:** upload the zip into `public_html`, tap **Extract**, check the destination says `/public_html`. The files then land in their own folders by themselves.
3. **Back up first, every time:** before extracting, copy each file that will be overwritten to `<name>-backup.html` (or `.php`) in its folder. Say how to roll back.
4. **Prove it before sending:** extract the zip into a fake `public_html` and list where each file landed. Confirm files that must not change (for example `photos.php`) are not in the zip. Say so in the message.
5. **Do not invent other upload locations.** If a tool is not under `members/`, ask the owner once for its real folder, then record it in the table below.
6. Never put passwords, API keys, Cloudflare tags or allowed-email lists into files in this repo or in a zip. `drafts.php` reads those from the existing `photos.php`.

## Where each tool lives on the server

| Repo folder | Server folder (under `public_html`) | Confirmed |
|---|---|---|
| `agency-report/` | `members/agency-report/` | yes |
| other folders | ask the owner | no |

## Standard header

Every toolkit page has the same dark E3 bar (`<nav class="e3-bar">`, back link to `/members/`, "DreamHub E3" brand, favicon `/images/favicon.png`). Copy it from `agency-report/index.html`. A new toolkit without it is not finished.

## Working style

- Say what was and was not tested. Do not say something cannot be done before checking.
- Keep a handover note per tool (see `HANDOVER-agency-report-generator.md`) and update it when behaviour changes.
