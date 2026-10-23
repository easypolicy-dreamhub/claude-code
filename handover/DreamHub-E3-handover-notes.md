# DreamHub E3 landing page: handover notes

Date: 2026-10-23
(Transferred from the "Landing Page" session, session_01YU5xhKyPrWpC2RPF2qaXv4)

## 1. What this project is

The DreamHub E3 landing page (dreamhub-e3.com). It is a single static `index.html` hosted on cPanel. It links to two other pages that are **not** part of this file: `/members/` (members-only page) and `/journey/` (Journey of Wealth page).

## 2. Which copy is the real one

| Copy | Status |
|---|---|
| **`index.html` on the cPanel site** (updated version delivered 23 Oct) | **The real one. Edit this.** |
| Claude design canvas, claude.ai/artifact/3AUN2RpmtSUHHyabNu1pSv | **Outdated mock-up. Do not edit or build from it.** Its member login buttons only scroll to the footer, and it has no `/journey/` link. |
| "The journey of wealth · DreamHub E3" artifact (claude.ai/artifact/FTCaZg1fPYBhmFoawoyMaX) | Separate page; likely the source of `/journey/`. Not touched. |

Keep a backup of the previous `index.html` (`index-backup.html`) until the new one has been checked live.

## 3. What changed in this round

**Header**
- Links: About · What We Do ▾ · Join Us ▾ · Contact · E3 Member Login (title case).
- What We Do ▾ → Our Approach, Our Solutions. Join Us ▾ → Career Path, Internship Path.
- Computer: dropdowns open on hover and click; close on mouse leave, outside click or Esc; keyboard works (Enter/Esc).
- Phone/tablet: ☰ button; sub-items are indented under their heading and open on tap.
- "Back to top ↑" round button, bottom-right, appears after scrolling past the hero and scrolls to the very top.

**Page structure**
- "What We Do" is now two parts: **Our Approach** (the 4 steps, plus the "See the four steps come to life" link to `/journey/`) and **Our Solutions** (the 6 cards).
- "Join Us" is two parts: **Career Path** (section label still reads "Join our team") and **Internship Path** (label still reads "Internship").
- Hero button "Explore a career with E3" now goes to `#career`.

**Section ids changed**

| Old | New |
|---|---|
| `#services` | `#approach` |
| `#business` | `#solutions` |
| `#careers` | `#career` |

`#top`, `#about`, `#internship` and `#contact` are unchanged. Any outside link that used an old id will no longer jump to a section.

**Our Solutions cards**: each can now have two tag bubbles, shown in journey order.

| # | Card | Tags | Text |
|---|---|---|---|
| 1 | Business Continuation Plan | Protection · Distribution | New |
| 2 | Business Debt Cancellation | Protection | Unchanged |
| 3 | Investment Portfolio Protection | Protection · Acceleration | New |
| 4 | Tax-to-Legacy Planning | Distribution | Unchanged |
| 5 | Business Exit Plan | Acceleration · Distribution | New |
| 6 | Multi-Generational Legacy Planning | Accumulation · Distribution | New |

Every journey step now has at least one card (Accumulation previously had none).
Above the cards: label "For business owners and family wealth" became **"Our Solutions"**; button "Discuss your business plan" became **"Discuss your plan"**, and its WhatsApp message now says "business or family".

## 4. Decisions made (and why)

- **Merged the paired sections** instead of adding 2 more header links. Services and the solution cards are one topic (framework, then solutions), as are careers and internship.
- **Dropdowns instead of one long section**, because each merged section is long.
- **Hover and click on computers, tap on phones**, because phones have no hover.
- **Back to top instead of a "Home" link.** The DreamHub E3 name at the top-left still returns to the hero.
- Card 3 wording uses "the payout is sized to protect your portfolio's value" rather than "matches", because cover may not always equal the full portfolio value.

## 5. Left unchanged on purpose

All other wording, colours, fonts, images, animations, the WhatsApp number (60126718056), the email, the map link, the footer disclaimer, and both `/members/` links. The sticky header can cover the very top of a section after a jump; this was reviewed and accepted.

## 6. How it was tested

In a headless desktop browser, at 1280px and 375px wide: dropdown hover, click, keyboard and Esc; every menu link jumping to the right part; ☰ menu on a phone; no sideways scrolling; tap targets at least 44px; both `/members/` links and the `/journey/` link unchanged; 6 cards with the right number of tags; back to top.

**Not tested:** a real phone or tablet, Safari/iOS, or the live site with its real images. Please check these after uploading.

## 7. Open items and things to watch

1. **Compliance:** DreamHub E3 represents Great Eastern. The new card wording (cards 1, 3, 5, 6) was written from the team's own descriptions. Have whoever signs off marketing copy confirm it before or soon after going live. No claims or numbers were invented.
2. **Card 4** (Tax-to-Legacy Planning) was never revisited. It is unchanged.
3. **Old anchor links:** if any ad, social post or QR code points to `/#services`, `/#business` or `/#careers`, update it.
4. **Back-to-top on phones** can briefly sit over the edge of a button while scrolling. It does not block anything else.
5. **Other issues:** the owner mentioned fixing other issues in a separate session. Always give that session this `index.html`, not the Claude canvas copy.

## 8. Deploying

1. Back up the current `index.html` (rename to `index-backup.html`).
2. Upload the new `index.html` to the same folder (usually `public_html`). Leave `images/`, `/members/` and `/journey/` alone.
3. Purge any cache (Cloudflare, LiteSpeed/plugin cache or the host's cache), if the site uses one.
4. Check in a private window or with a hard refresh, on a computer and on a phone. If something is wrong, restore `index-backup.html`.
