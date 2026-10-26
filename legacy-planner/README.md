# Legacy Planner (v2)

Rebuilt from the deployed single-file app, with the fixes from `../AUDIT-legacy-planner.md` applied.

- **Deploy:** upload `dist/index.html` as `/members/legacy-planner/index.html`. It is one self-contained file (React bundled in), same E3 header, theme and Tab-key fix as before.
- **Saved data:** profiles keep the same browser storage key (`legacy-planner.v1`), so people's existing profiles load. Joint assets saved by the old version now count the deceased's share as part of the estate (the old version gave the whole value to the survivor).
- **Build:** `npm install`, then `npm run build` (writes `dist/index.html`).
- **Tests:** `npm test` (engine rules) and `node test/smoke.cjs` (opens the built file in headless Chromium and walks every tab) and `node test/format-scan.cjs` (fails if any tab shows an amount without thousand separators). Both need Playwright.

## Layout

| Path | What it holds |
|---|---|
| `src/engine/outcome.js` | Who receives what: estate, nominations, joint assets, will gifts |
| `src/engine/insurance.js` | Malaysian life insurance nominee rules (FSA 2013 Schedule 10) |
| `src/engine/intestacy.js` | Distribution Act 1958 shares |
| `src/engine/faraid.js` | Faraid (unchanged from v1) |
| `src/engine/flags.js` | The "Gaps and things to check" list |
| `src/engine/route.js` | Probate / Small Estate / Summary Distribution route |
| `src/model.js` | Plan shape, checklist items (wording lives here) |
| `src/ui/*.jsx` | Tabs and components |

## What changed from v1

Fixed (training documents, see the audit): non-spouse/child/parent insurance nominees hold for the estate; a parent is a trust nominee only with no living spouse or child; a nominee who dies first passes the share to surviving insurance nominees (EPF still goes to the estate); joint assets count only the deceased's share (value ÷ holders) unless survivorship is ticked.

Added: state field and Sabah warning; will-validity checks (two witnesses, age, witness-beneficiary gift void); cash-shortfall warning (debts + about 1% property transfer fees + an optional admin-cost estimate); likely administration route (Grant of Probate / Summary Distribution / Small Estate / Letter of Administration); business type notes; assignment tick for insurance; clearer checklist wording.

Removed: the unreachable Singapore-jurisdiction branches (profiles were always Malaysia). Singapore CPF and insurance rules for assets "Held outside Malaysia" are kept.

## Not covered by the training documents (unchanged, unverified)

Faraid, wasiat, hibah, Singapore CPF/insurance rules, and "marriage revokes a will or CPF nomination".
