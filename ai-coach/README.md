# AI Coach (replaces the HNW Coach Gem)

A Gem-style coach for the E3 Advisor Toolkit: **instructions** + **PDF knowledge**, answered by Claude. Plain PHP, runs on cPanel, no Composer.

## What is in this folder

| File | What it does |
|---|---|
| `index.php` | The coach page. Agents see **Chat**. The super admin also sees **Settings** and **Usage**. |
| `api.php` | All actions. Every admin action is checked on the server. |
| `site-bridge.php` | The only link to the rest of the site: checks the Cloudflare Access sign-in and loads `config.php`. |
| `lib/` | Storage, Claude calls. Not meant to be opened from the web. |
| `config.sample.php` | Lines to add to your existing `config.php`. |
| `dev/` | Local tests. **Do not upload.** |

## Install on cPanel

1. **Upload** this folder (without `dev/`) to `public_html/members/ai-coach/`, next to the other tools.
2. **config.php:** add the lines from `config.sample.php` to your existing `config.php`: `ANTHROPIC_API_KEY`, `COACH_WHATSAPP_NUMBER` (country code first, digits only), and the Cloudflare sign-in settings (see below). If your `config.php` is not in `public_html/members/`, change the one path at the top of `site-bridge.php`.
3. **Cloudflare:** check that your Zero Trust Access application covers `/members/*`, so `/members/ai-coach/` is protected like the other toolkits. Nothing new to set up in Cloudflare.
4. **PHP version:** cPanel > Select PHP Version. Use 8.0 or newer and tick the `curl` extension.
5. **Upload limits:** cPanel > MultiPHP INI Editor. Set `upload_max_filesize` to at least 25M and `post_max_size` to at least 30M. Each PDF is uploaded as its own request, so a bad file never blocks the others.
6. **Replace the HNW Coach card** on the `/members/` page: point its link to `/members/ai-coach/`. Suggested text:
   - Title: `AI Coach`
   - Description: `Practise client conversations and objection handling with your personal coach`
   - Grey note: `Your chats are private to you. Your team leader can see the questions you ask.`
7. **Open the coach page as the super admin.** Settings > paste the instructions > Save. Upload the PDFs (select all at once). Test in the Chat tab. Agents see "The coach is being set up" until you save instructions.

## Sign-in: Cloudflare Zero Trust

Members sign in through Cloudflare Access, like the other toolkits. Cloudflare adds a signed token to every request it lets through. The coach checks that token itself (signature against your team's public keys, the application's AUD tag, issuer and expiry), the same way `agency-report/photos.php` does. So a request that skips Cloudflare, or carries a forged or expired token, gets nothing.

Add to `config.php`:

| Setting | What to put |
|---|---|
| `COACH_CF_TEAM_DOMAIN` | Your team domain, e.g. `yourteam.cloudflareaccess.com`. Copy it from `photos.php`. |
| `COACH_CF_AUD` | The members app's AUD tag. Copy it from `photos.php`, or Zero Trust > Access > Applications > your app > Overview. |
| `COACH_SUPER_ADMIN_EMAILS` | The team leader's sign-in email(s). Only they see Settings and Usage. |
| `COACH_MEMBER_NAMES` | Optional. `email => name`. Unlisted members get a name from their email. Listed members who never used the coach show as "needs a nudge". |

Each member's chats are keyed by their sign-in email. If the settings are missing, the coach says "Sign-in is not connected yet" and nobody gets in.

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

Starts a fake Claude and a stand-in Cloudflare key server, then runs 78 checks: forged, expired or wrong-app sign-in tokens are refused, the admin gate (agents and team admins get 403 when calling admin actions directly), 5-version history, same-name replace, per-agent privacy, questions-only usage, request shape sent to Claude, and that the API key never reaches the browser.
