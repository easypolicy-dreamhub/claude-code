# AI Coach (replaces the HNW Coach Gem)

A Gem-style coach for the E3 Advisor Toolkit: **instructions** + **PDF knowledge**, answered by Claude. Plain PHP, runs on cPanel, no Composer.

## What is in this folder

| File | What it does |
|---|---|
| `index.php` | The coach page. Agents see **Chat**. The super admin also sees **Settings** and **Usage**. |
| `api.php` | All actions. Every admin action is checked on the server. |
| `site-bridge.php` | The only link to the rest of the site: `boot_member_request()`, `require_admin`, `config.php`. |
| `lib/` | Storage, Claude calls. Not meant to be opened from the web. |
| `config.sample.php` | Lines to add to your existing `config.php`. |
| `dev/` | Local tests. **Do not upload.** |

## Install on cPanel

1. **Upload** this folder (without `dev/`) to `public_html/members/ai-coach/`, next to the other tools.
2. **config.php:** add the lines from `config.sample.php` to your existing `config.php`: `ANTHROPIC_API_KEY` and `COACH_WHATSAPP_NUMBER` (country code first, digits only).
3. **site-bridge.php:** check the three spots marked `ADJUST` (see below).
4. **PHP version:** cPanel > Select PHP Version. Use 8.0 or newer and tick the `curl` extension.
5. **Upload limits:** cPanel > MultiPHP INI Editor. Set `upload_max_filesize` to at least 25M and `post_max_size` to at least 30M. Each PDF is uploaded as its own request, so a bad file never blocks the others.
6. **Replace the HNW Coach card** on the `/members/` page: point its link to `/members/ai-coach/`. Suggested text:
   - Title: `AI Coach`
   - Description: `Practise client conversations and objection handling with your personal coach`
   - Grey note: `Your chats are private to you. Your team leader can see the questions you ask.`
7. **Open the coach page as the super admin.** Settings > paste the instructions > Save. Upload the PDFs (select all at once). Test in the Chat tab. Agents see "The coach is being set up" until you save instructions.

## The three `ADJUST` spots in `site-bridge.php`

The toolkit's own PHP was not available when this was built, so these were written as safe guesses that **fail closed** (if wrong, nobody gets in, nothing leaks):

1. **Which files to load:** the path to `config.php`, plus the file that defines `boot_member_request()` and `require_admin()`. Until `boot_member_request()` exists the API answers "Sign-in is not connected yet".
2. **What `boot_member_request()` returns:** the code reads an `id` (or `member_id` / `email`) and a `name` (or `full_name` / `display_name`) from it.
3. **Who is the super admin:** `coach_is_super_admin()` looks for `is_super_admin` or `role === 'super_admin'`. Team admins must not pass. If the Settings and Usage tabs do not appear for you, this is the one to change.

`require_admin()` is called with no arguments, and the coach adds its own super-admin check on top.

## How it works

- **Knowledge:** every PDF is sent to Claude with every question, using prompt caching so repeat questions are fast and cheap. No search index to maintain.
- **Instructions:** saved in the private folder. Each save is a version, the newest 5 are kept, and any older one can be restored (a restore becomes a new version).
- **Memory:** each agent has a private file keyed by their login. Recent messages are sent with each question. Older ones are folded into short private "coaching notes" by a cheaper model (`claude-haiku-5-5`) after the reply is sent.
- **Handoff:** if the answer is not in the PDFs, the coach says so and shows an "Ask my leader on WhatsApp" button (a `wa.me` link with the question filled in).
- **Usage tab:** questions only, never the coach's answers, in the same style as Team Activity. Other leaders cannot see it.
- **Languages:** replies in English or Simplified Chinese, matching the agent.

## Where the data lives

`/home/<cpanel user>/e3-ai-coach/` (above `public_html`, beside `e3-agency-photos`). Override with `COACH_DATA_DIR`. The coach refuses to run if that folder is inside the web folder.

| Path | Contents |
|---|---|
| `instructions/` | the last 5 versions |
| `knowledge/` | the PDFs |
| `members/<hash>/chat.json` | one agent's chat and coaching notes |
| `usage/questions.jsonl` | the questions log |

**Back this folder up** (cPanel > Backup does not include folders you have not told it about; the `public_html` zips will not contain it).

## Cost

The model is `claude-opus-5-5` ($4 per million input tokens, $20 per million output). A PDF page is roughly 2,000 to 3,000 tokens, so 100 pages is about 250,000 tokens.

- A question that finds the PDFs in the cache reads them at $0.20 per million: about **$0.05** for 100 pages.
- The cache lasts **1 hour** (`COACH_CACHE_TTL`). The first question after a quiet hour writes the cache again at $8 per million: about **$2** for 100 pages.
- Settings shows the real token count of the last question, so check it after your first test.
- To roughly halve the bill, set `COACH_MODEL` to `claude-sonnet-5-5` in `config.php`. Compare the answers first.

Limits: Claude takes up to 600 PDF pages and 32 MB per request, so the library is capped at 22 MB in total (`COACH_MAX_TOTAL_MB`).

## Tests

```
./dev/run-tests.sh
```

Starts a fake Claude and a stand-in login, then runs 64 checks: the admin gate (agents and team admins get 403 when calling admin actions directly), 5-version history, same-name replace, per-agent privacy, questions-only usage, request shape sent to Claude, and that the API key never reaches the browser.
