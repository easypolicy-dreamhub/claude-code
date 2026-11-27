# Handover: HNW Coach (Gemini)

Replaces the HNW Coach Gemini Gem. Folder in repo: `hnw-coach/`. Server folder: `public_html/members/hnw-coach/` (tool key `hnw-coach`).
Built from the earlier `ai-coach/` (which uses Claude); `ai-coach/` is untouched.

## What it does
- **Chat** (any member with the HNW Coach tool ticked): the coach answers using the leader's instructions plus the PDFs, replies in Traditional Chinese, role-play with 「暫停」 / 「結束演練」 and the scorecard. Two quick-start chips on the page.
- **Settings** (super admin only): edit the instructions any time (last 5 versions kept, restore any), add / replace / delete PDFs any time (same name replaces). The next message uses the new set.
- **Usage** (super admin only): questions only, never the coach's answers.
- Private memory per signed-in member (keyed by their login email, never by anything the browser sends). WhatsApp hand-off button when the answer is not in the PDFs.
- Limits per member: 30 messages an hour, 120 a day (`COACH_RATE_PER_HOUR`, `COACH_RATE_PER_DAY`).

## Language
- The page is in **Simplified Chinese** by default. A button in the top bar switches to English and remembers the choice in the browser. Every page text, the quick-start chips, the WhatsApp pre-filled message and the member-facing error messages exist in both languages (`index.php` dictionary `T`, `coach_msg()` in `lib/store.php`). Traditional Chinese is not offered (the owner asked for Simplified).
- The page sends `X-Coach-Lang` (`zh` or `en`) with every request. The coach is told (system instruction, after the leader's instructions) to reply in Simplified Chinese for `zh`, and in English for `en`, overriding any language line in the leader's instructions. It accepts 暂停 / 结束演练 (and the traditional forms) in Chinese mode, and "pause" / "end drill" in English mode. A request with no header counts as English.
- Admin-only error messages (for example a badly shaped key) are still English.
- Instructions: `starter-instructions-simplified.txt` is the Simplified version of the Gem instructions (converted automatically, then fixed by hand where the converter picked the wrong word, e.g. 核心, 建立). `starter-instructions.txt` is the original Traditional text. Either works; the language button overrides their language line.

## How it is built
| Part | Notes |
|---|---|
| Access | `site-bridge.php`. The page uses `boot_member_page()` and `render_denied()` from `private/lib/gate.php` (same friendly pages as every tool); the API uses `boot_member_request()`. Both then call `can_use_tool($pdo, $me, 'hnw-coach')`. Nobody is listed in any file. |
| Super admin | Same rule as `tool_is_super()`: `is_admin` and `is_active`. Team admins (`is_team_admin`) do not pass. Settings, Usage and every admin action are checked on the server. |
| Member id | Private memory is keyed on the member's numeric `id` from the members row. |
| Gate | `hnw-coach` is NOT in the folder list in `members/.htaccess` and `tool_catalog()` still has `dir => ''` for it, so `/members/hnw-coach/` is served directly and enforces access itself. `tools.php`, `.htaccess`, `gate.php`, `config.php` are not edited. |
| Settings | The super admin pastes the Gemini key and the leader's WhatsApp number in Settings > Connection. They are saved in `e3-hnw-coach/settings.json` (private data folder, owner-only, outside `public_html`). The key check only needs 20 to 300 visible characters with no spaces or line breaks (Google's newer key formats contain dots; an earlier stricter check wrongly refused a real key). The key is never sent back to the browser (only "set" and its last 4 characters) and never to members. Optional: `private/hnw-coach-config.php` (see `config.sample.php`, not in the zip) can set the key and tuning instead; if it sets the key, the page says so and cannot change it. `config.php` is never edited. |
| Writes | JSON only with `X-Requested-With: fetch`. PDFs travel as base64 inside JSON, one request per file. |
| Gemini | `lib/gemini.php`, plain cURL to `generativelanguage.googleapis.com`, key in the `x-goog-api-key` header. All PDFs are sent with every question. |
| Storage | `/home/<cpanel user>/e3-hnw-coach/` (above `public_html`). Refuses to run if inside `public_html`. Back it up: instructions, PDFs, chats. |
| Page | Shared `/members/e3-toolkit.css`, standard `e3-bar` and `topbar` header, and the same `/members/tool-tab.js` line the gate adds to other tools. |
| Card | `members/apps.js`: only the HNW Coach entry changed (url `/members/hnw-coach/`, new grey note). |

## Setup on the server (in this order)
1. Copy `members/apps.js` to `members/apps-backup.js`. Upload the zip into `public_html`, tap Extract, destination `/public_html`.
2. cPanel: PHP 8.1 or newer with the `curl` extension. MultiPHP INI Editor: `post_max_size` at least 16M and `memory_limit` at least 256M.
3. Google AI Studio: API billing as a prepaid balance with auto-reload off; create an API key.
4. Members & Access: tick HNW Coach for the people who should use it (the super admin always has it).
5. Sign in as super admin, open `/members/hnw-coach/`, Settings: paste the key and the WhatsApp number under Connection and Save; paste `starter-instructions.txt` under Coach instructions and Save; upload the six PDFs; test in Chat.
7. Optional, later: in `private/lib/tools.php` the `hnw-coach` line still says "Opens in Gemini" and `link_only`. That only affects the label in Members & Access.

## Tested (local, `hnw-coach/dev/run-tests.sh`, 104 checks)
Against a fake Gemini and a stand-in for the members system (same shapes as the real `boot_member_request`, `boot_member_page`, `can_use_tool`, member row): sign-in, tool ticks, unknown/inactive members, admin actions refused for members and team admins, writes refused without the header / JSON / same origin, 5-version history and restore, same-name replace, bad PDFs refused, per-member privacy, questions-only usage, request shape sent to Gemini, safety block, cut-off reply, hourly and daily limits, key and WhatsApp number read from the optional settings file with no constants, key pasted in Settings > Connection (admin only, never echoed back, owner-only file outside the web folder, coach answers with it, can be removed), API key never in page or responses, data folder must be outside the web folder, standard no-access page, tool-tab.js line present. The six real PDFs were accepted by the upload code (23 pages, 0.86 MB).

## NOT tested
- A real call to Gemini (no key here). The request follows Google's documented `generateContent` format; check `gemini-2.5-flash` and `gemini-2.5-flash-lite` against Google's current model list (`COACH_MODEL`, `COACH_NOTES_MODEL`).
- The real members system end to end (tests used stand-ins built from the real files you pasted: bootstrap.php, auth.php, db.php, tools.php, gate.php, members/gate.php, members/.htaccess, apps.js, members/index.html).
- `/members/e3-toolkit.css` (never seen): my page's own styles (`.card`, `.chip`, `.btn`, `.tabs`) could clash with it.
- `/members/tool-icons.js` and `tool-tab.js` (never seen): the card icon for key `hnw-coach`, and the back link closing the tab.
- Your real header styling (`e3-toolkit.css` was never seen). The page was checked in a headless browser on a phone-sized screen, in Chinese and English, without that file.
- Whether the host lets the reply finish after it is sent (the coaching-notes update runs after the reply).

## Roll back
Only `members/apps.js` is overwritten: copy `apps-backup.js` back over it. To remove the tool: delete `public_html/members/hnw-coach/`. Chats and PDFs stay in `e3-hnw-coach/` until you delete that folder.
