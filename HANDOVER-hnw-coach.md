# Handover: HNW Coach (Gemini)

Replaces the HNW Coach Gemini Gem. Folder in repo: `hnw-coach/`. Server folder: `public_html/members/hnw-coach/` (tool key `hnw-coach`).
Built from the earlier `ai-coach/` (which uses Claude); `ai-coach/` is untouched.

## What it does
- **Chat** (any member with the HNW Coach tool ticked): the coach answers using the leader's instructions plus the PDFs, replies in Traditional Chinese, role-play with 「暫停」 / 「結束演練」 and the scorecard. Two quick-start chips on the page.
- **Settings** (super admin only): edit the instructions any time (last 5 versions kept, restore any), add / replace / delete PDFs any time (same name replaces). The next message uses the new set.
- **Usage** (super admin only): questions only, never the coach's answers.
- Private memory per signed-in member (keyed by their login email, never by anything the browser sends). WhatsApp hand-off button when the answer is not in the PDFs.
- Limits per member: 30 messages an hour, 120 a day (`COACH_RATE_PER_HOUR`, `COACH_RATE_PER_DAY`).

## How it is built
| Part | Notes |
|---|---|
| Access | `site-bridge.php`: `boot_member_request()` then `can_use_tool($pdo, $me, 'hnw-coach')` from `private/` (same pattern as `agency-report/photos.php`). Nobody is listed in any file. |
| Super admin | `coach_is_super_admin()` reads `is_super_admin`, `super_admin` or `role` = `super_admin` from the member record. **Unverified guess** (the real record was not available). Fails closed. If the Settings tab does not show for the super admin, open `/members/hnw-coach/api.php?action=whoami` while signed in and use what it shows to adjust this one function. |
| Writes | JSON only with `X-Requested-With: fetch` (the members system refuses anything else). PDFs are therefore sent as base64 inside JSON, one request per file. |
| Gemini | `lib/gemini.php`, plain cURL to `generativelanguage.googleapis.com`, key in the `x-goog-api-key` header. All PDFs are sent with every question. |
| Storage | `/home/<cpanel user>/e3-hnw-coach/` (above `public_html`). Refuses to run if inside `public_html`. Back it up: instructions, PDFs, chats. |
| Page | Shared `/members/e3-toolkit.css`, standard `e3-bar` and `topbar` header. |

## Setup on the server (in this order)
1. Upload the zip into `public_html`, tap Extract, destination `/public_html`.
2. Add to the existing `config.php` (outside `public_html`, never in a zip): `GEMINI_API_KEY` and `COACH_WHATSAPP_NUMBER`. Lines are in `config.sample.php`.
3. Add the tool: key `hnw-coach` in `private/lib/tools.php`, and the folder `hnw-coach` in `/members/apps.js` (needs the owner's current files; not in this repo).
4. cPanel: PHP 8.0 or newer with the `curl` extension. MultiPHP INI Editor: `post_max_size` at least 16M and `memory_limit` at least 256M (PDFs travel as base64 inside JSON).
5. Switch the tool on for the people who should have it in Members & Access.
6. Sign in as super admin, open `/members/hnw-coach/`. Settings: paste `starter-instructions.txt`, Save, upload the six PDFs, test in Chat.
7. Google AI Studio: API billing, spend cap or prepaid balance with auto-reload off.

## Tested (local, `hnw-coach/dev/run-tests.sh`, 74 checks)
Against a fake Gemini and a fake members system: sign-in, tool switch, admin actions refused for members and team admins, writes refused without the header / JSON / same origin, 5-version history and restore, same-name replace, bad PDFs refused, per-member privacy, questions-only usage, request shape sent to Gemini, safety block, cut-off reply, rate limits, API key never in page or responses, data folder must be outside the web folder. The six real PDFs were accepted by the upload code (23 pages, 0.86 MB).

## NOT tested
- A real call to Gemini (no key here). The request shape follows Google's documented `generateContent` format; the model name `gemini-2.5-flash` and the notes model `gemini-2.5-flash-lite` must be checked against Google's current list (`COACH_MODEL`, `COACH_NOTES_MODEL`).
- The real members system (`private/`), including the super-admin field names, and the shared header on a phone.
- Whether the host lets the reply finish after sending it (the notes update runs after the reply; if the host does not support that, notes may not update).

## Roll back
Nothing existing is overwritten (new folder). To remove: delete `public_html/members/hnw-coach/`, and take the tool key and the `apps.js` entry out again. Chats and PDFs stay in `e3-hnw-coach/` until you delete that folder.
