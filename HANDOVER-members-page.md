# Handover: Members page revamp (E3 Advisor Toolkit)

Status: **mockups only. Nothing is built, packaged or uploaded.** Waiting for the owner's `apps.js` (and the current members index) before building.

## Why
The current `/members/` page has about 20 or more tools in 7 categories (Servicing, Sales, Recruitment, Leader, Admin, Coaching, Site Admin). Cards are tall with empty space, so the page is a long scroll.

## Decisions made with the owner
- **Look:** the first mock (light grey ground, slate ink, small cards, Poppins and DM Sans). Not the dark website-styled version.
- **Layouts:** Grid, Side menu and List, with a switch under the search box.
- **Default layout:** Grid. Remember each member's last choice (browser storage is fine).
- **Notes under cards** (for example "Agents only"): **remove all**, to keep it sleek.
- **Favourites:**
  - Star on each card.
  - Shown at the top **on every category and in every layout**, hidden only while the search box has text.
  - **Limit of 6.** A 7th gives the message "You can have up to 6 favourites. Unstar one to add another." No "Show all" button.
- **Search box** over name, description and category, with category chips and counts.
- **Role rules:** Leader, Admin and Site Admin tools stay hidden from people without that role (assumed to match today's behaviour; confirm from the existing page).
- **Login animation:** a short welcome band (about 3 seconds, "Welcome back, <name>") slides across the header once per login. Tap to skip. Skipped when the device asks for reduced motion.
  - Real page: play once per login. Check how the members system in `private/` handles login first. Fallback: once per browser session.

## Tools seen so far (from the owner's screenshot)
- Servicing: GE Panel Finder, Great Journey Finder, Client Portal
- Sales: Protection Needs Calculator, Sum Assured Calculator, Legacy Planner, plus 2 not yet seen
- Recruitment, Leader, Admin, Coaching, Site Admin: tools not yet seen

The real list comes from `/members/apps.js`. Each tool's `key` ties to Members & Access and `private/lib/tools.php`.

## Mockups (private claude.ai links, for reference only)
- First look with 3 layouts: https://claude.ai/artifact/Px3RHWASYmwgQFQHEi55Ty
- First look plus login animation and 6-favourite limit: https://claude.ai/artifact/RE5kB1K9ubcDwbqbTwL6is
- Website-styled alternatives (not chosen): `TqtxxgJYep1BySbmMMYXcJ`, `J2kpEhnT3mGi1utxN7s7Ek`, `JxKfjLN2KkfgvbiujJPuR6`, `F6XpmwwFHSYRaM5b36VPtX`

## Build rules (from CLAUDE.md)
- Keep the standard header: `/members/e3-toolkit.css`, `<div class="e3-bar">`, then `<header class="topbar">` with `<h1>Title<small>subtitle</small></h1>` and a `<div class="who">`. Do not copy or change the shared stylesheet.
- No passwords, keys, Cloudflare tags or allowed-email lists in files or zips. Who may see a tool comes from the members system, not a list in the page.
- Package as a zip rooted at `public_html` (for example `members/index...`). One instruction: upload to `public_html`, tap Extract, check the destination says `/public_html`.
- Back up each overwritten file first (`<name>-backup.html` or `.php`) and say how to roll back.
- Test-extract the zip into a fake `public_html`, list where each file landed, and confirm files that must not change are not in it.

## Open questions
1. `apps.js` contents (all tools, folders, keys, icons, categories).
2. File type and location of the current members index (confirm the real path under `public_html/members/`).
3. How login is handled, so the once-per-login animation can use it.
4. Whether the tool icons should be kept (the mock only uses initials).

## Not tested
Mockups were not opened in a browser by the assistant. Phone and desktop layout, the animation, and dark or light behaviour still need a real check after the build.
