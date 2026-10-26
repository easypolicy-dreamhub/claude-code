# Build Brief: AI Coach for the Members Toolkit

Hand this to the developer (Sonnet) along with the members toolkit site code.

## What it is
An AI coach for the team's agents, inside the existing members-only toolkit site.
It works like a Gemini Gem: **instructions** (how the coach behaves) plus
**knowledge** (PDF documents), answered by Claude through the API.

## Where it lives
- **It replaces the existing HNW Coach card** in the Coaching section of the E3 Advisor Toolkit (dreamhub-e3.com). That card currently opens a Gemini Gem. It should now open this coach instead.
- **One card, one coach page.** No separate admin area and no new admin pages.
- The card keeps the toolkit's card style: emoji icon, title, description, grey note line, ↗ arrow. Suggested note line: *"Your chats are private to you."*
- Tapping the card opens the coach page:
  - **Agents (any logged-in member):** see only the **Chat** tab.
  - **Super admin (team leader) only:** also sees two more tabs on the same page, **Settings** (instructions editor and PDF upload, replace and delete) and **Usage**.
  - The extra tabs are only drawn for the super admin, and every Settings and Usage action is also checked on the server (see below).

## Login and permissions (reuse existing code)
- Every endpoint starts with the existing `boot_member_request()`. No new login system.
- Admin actions (upload, replace or delete a PDF, save instructions, restore a version, usage view) call the existing `require_admin` **on the server, inside that endpoint**.
- **Hiding the admin controls in the page is not security.** If a non-admin member calls an admin endpoint directly, the server must refuse it (403), even though the button was never shown to them.
- Admin actions that change something must be POST and use the site's existing CSRF protection, if it has one.
- **Check first:** `require_admin` must mean *super admin* (the team leader). If the site has several admin levels, use the super-admin check.

## Storage
- **Everything goes in the private folder, never in `public_html`:**
  - the instructions, current version plus history
  - the knowledge PDFs
  - each agent's chat history and coaching notes
- No file in the private folder may be reachable by URL. PDFs are only read by the server.
- **API key:** kept in the existing `config.php`, e.g. `ANTHROPIC_API_KEY`. Never sent to the browser, never logged.

## Instructions (coach behaviour)
- The super admin edits them in a text box on the card and clicks Save.
- **Keep the last 5 versions.** Each save stores the new version with a date, time and who saved it, and deletes anything older than the 5 most recent.
- The super admin can view the old versions and restore one. Restoring counts as a new save.
- New instructions apply to the next message any agent sends.

## Knowledge (PDFs)
- About 8 PDFs at the start, more added over time.
- Upload: PDF only, checked on the server (file type and size limit).
- **Same file name = replace.** Uploading a PDF with an existing name overwrites the old one, so there are no duplicates.
- The super admin can delete a PDF.
- **How Claude uses them:** the knowledge is small, so send all the PDFs with every request (no RAG) and use **prompt caching** so repeat requests are cheap and fast. The cache refreshes automatically when a PDF changes.

## Memory (per agent, private)
- Memory carries across chats. When Alan comes back another day, the coach remembers his earlier chats and progress.
- **Each agent's memory is private.** Brenda never sees Alan's chats, and Alan never sees hers. Memory is keyed by the member ID that `boot_member_request()` returns, never by anything the browser sends.
- Store each agent's recent messages plus a short "coaching notes" summary that the coach updates, so the conversation history doesn't grow without limit.
- Agents can see their own past chats. The super admin can see questions through the usage view (agents should be told this).

## Handoff to the team leader (WhatsApp)
- When the coach can't answer, or the answer isn't in the PDFs, it says so and shows an **"Ask my leader on WhatsApp"** button.
- The button is a `wa.me` link to the leader's number, with the agent's name and question already filled in. The agent taps send. Free, no WhatsApp API needed.
- The leader's WhatsApp number goes in `config.php`.

## Usage view (super admin only, the Usage tab on the coach page)
- **Agent list:** each agent's name, last used date, and number of chats. Agents who never used it are shown too, so the leader can see who needs a nudge.
- **Tap an agent:** see their chats, newest first.
- **Handoffs:** questions sent to the leader on WhatsApp are flagged, so gaps in the PDFs are easy to spot.
- **Date filter:** this week, this month, all time.
- Enforced on the server with `require_admin`, like every other admin action. Agents can never open another agent's chats.
- **Open question for the team leader:** should the leader see full conversations, or only the agents' questions?

## Languages
- The coach replies in **English or Chinese**, matching the language the agent writes in. The PDFs stay as they are.
- **Open question for the team leader:** Simplified or Traditional Chinese?

## Model
Use a current Claude model through the Anthropic API. The developer should pick the model and check prices at build time.

## Test checklist before going live
- [ ] A logged-out visitor can't reach the chat or any endpoint.
- [ ] A normal member calling the upload, save, delete, restore or usage endpoints directly gets 403.
- [ ] PDFs, instructions and chats can't be opened by URL.
- [ ] Uploading a PDF with the same name replaces the old one.
- [ ] A 6th instruction save removes the oldest version, so only 5 remain.
- [ ] Alan's memory carries over to the next day, and Brenda can't see any of Alan's chats.
- [ ] Asking in Chinese gets a Chinese reply, asking in English gets an English reply.
- [ ] The WhatsApp button opens a chat to the leader with the question filled in.
- [ ] The API key never appears in the page source or the browser's network traffic.
