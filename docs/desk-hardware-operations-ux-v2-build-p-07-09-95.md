# Hardware Operations UX v2 Vite build — RadiumDesk-P-07-09-95

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-95`  
**Follows:** P-07-09-94 Hardware Operations UX v2 (`9f423118`).

Ledger file requested as `docs/cursor-prompt-log.md` — **that file does not exist**. Authoritative ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-94**. This ticket: **P-07-09-95**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Intent

Build the existing Vite frontend for already-committed P-07-09-94. Verify generated assets. Run validation. Decide whether P-07-09-94 is safe to deploy.

This ticket does **not** redesign Hardware UX v2. It does **not** deploy.

---

## Context (VERIFIED)

| Check | Result |
|-------|--------|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` |
| Project | Radium Desk (`origin` `git@github.com:radium-foundation/radium-desk.git`) |
| Branch | `main` (ahead of `origin/main` by 21) |
| HEAD before / after | `9f42311805d318004bc4fb08d7e1951e1c12659f` |
| Reference commit | Exists (`git cat-file -t` = `commit`) |
| Worktree | This tree on `main`. Sibling trees: `radium-desk` (`feat/rd-fresh-01-inventory-pos`), `radium-desk-phase1-clean` (detached). Untouched. |
| Isolation | radiumbox.com and other RadiumWebsites projects were not inspected or modified |

Pre-existing dirty tracked files (not introduced by this ticket; not modified):

- `app/Services/Shipping/Data/ShiprocketCreateOrderRequest.php`
- `docs/POS-DESK-STATUTORY-INVOICE-ARCHITECTURE-DECISION.md`
- `docs/desk-statutory-finance-hub-consolidation.md`
- `docs/rd-central-finance-invoice-architecture.md`

Plus many untracked investigation markdown files and `tests/Unit/Shipping/ShiprocketCreateOrderRequestTest.php`.

---

## Build

Established command from `package.json` / `tools/README.md` / `tools/commands/deploy.sh`:

```text
npm run build
```

which runs `vite build`.

**Result:** passed (229 modules, ~341ms). Ineffective-dynamic-import warnings for timeline/email modules are pre-existing and not a failure.

`/public/build` is gitignored. Project workflow builds locally and rsyncs `public/build/` at deploy time **without** `--delete`. Generated assets are **not** committed.

### Generated entries (VERIFIED)

| Entry | File |
|-------|------|
| CSS | `public/build/assets/app-D4lQXdS3.css` |
| app.js | `public/build/assets/app-nLcHXNoc.js` |
| dashboard.js | `public/build/assets/dashboard-BsgJcaDW.js` |
| manifest | `public/build/manifest.json` |

P-07-09-94 interactive markers present in the hashed output:

- CSS: `dashboard-hardware-selection`, `dashboard-hardware-product--more`, `dashboard-hardware-product-popover`, `hardware-action-courier--recommended`
- app.js: `data-hardware-action-dialog`, `data-hardware-action-form`, `data-hardware-serial-picker`, `refresh_customer360`, `action_dialog_url`
- dashboard.js: `data-hardware-select-all`, `data-hardware-open-selected`, `data-hardware-clear-selected`, `data-hardware-product-detail`, `1 selected`

No `Label-applied package photo` in Hardware action views. Package photo kind remains `package_before_label`.

Vite did not modify tracked source. HEAD stayed `9f423118`.

---

## Validation

| Suite | Result |
|-------|--------|
| `php artisan test tests/Feature/HardwareFulfilment tests/Unit/HardwareFulfilment tests/Unit/Customer360OverflowMenuPresenterTest.php` | **250 passed, 1 skipped**, 2414 assertions |
| Pint `--test` on P-07-09-94 application PHP | passed |
| `php -l` on P-07-09-94 Hardware classes | no syntax errors |
| `npm test -- tests/js/customer-360-drawer.test.js` | **23 passed, 3 failed** |

The 3 Vitest failures expect fetch URL `http://localhost/dashboard/service-cases/42/customer-360`. Implementation returns relative `/dashboard/service-cases/42/customer-360` from `drawerContentUrl()` (`pathname` + `search`). P-07-09-94 only added Hardware click-ignore selectors to `isInteractiveTarget`. It did **not** change URL construction.

Hardware-relevant drawer tests that passed:

- does not open on interactive row controls
- does not open on fulfilment shipment link
- does not open on serial copy
- workspace trigger / refresh behavior except the absolute-URL assertion

Those 3 failures are a **pre-existing assertion mismatch**. Not fixed in this ticket (unrelated to the Vite build / Hardware UX v2).

---

## Production safety

| Action | Status |
|--------|--------|
| Deploy | NO — Not performed. |
| Push | NO — Not performed. |
| Production browser verification | NO — Not performed. |
| Production data mutation | NO — Not performed. |
| Shiprocket calls | NO — Not performed. |
| Create shipment / assign AWB / generate label | NO — Not performed. |
| Request pickup / generate manifest | NO — Not performed. |
| Modify RIN mapping | NO — Not performed. |
| Migrate / `.env` | NO — Not performed. |

---

## Git

Tracked source after build: unchanged vs `9f423118`.  
Generated `public/build` is gitignored and must be rsync'd by a later deploy ticket.

**Committed:** NO — Not performed.

---

## SAFE TO DEPLOY

**YES**

- Vite build passed
- Generated assets include P-07-09-94 Hardware interactive code
- Relevant Hardware PHP tests passed
- No unrelated source change was introduced
- P-07-09-94 commit remains intact
- No production mutation occurred

Remaining risks (do not block the *decision*, they block *this* ticket from being a deploy):

1. Production still serves the previous Vite bundle until a later overlay rsyncs `public/build/` without `--delete`.
2. Official `./tools/desk deploy` remains unsafe on this dirty worktree (unrelated shipping/statutory files).
3. C360 drawer Vitest still expects absolute localhost URLs; relative fetch works in-browser but the three assertions fail locally.

---

## Feature confirmation (local tests / source / assets — not production)

| Feature | Status |
|---------|--------|
| Product + quantity | VERIFIED (unit + Blade + CSS product cell) |
| Multi-product support | VERIFIED (unit + popover CSS in build) |
| Checkboxes | VERIFIED (feature test + dashboard.js markers) |
| Select-all | VERIFIED |
| Selection bar | VERIFIED |
| Open selected | VERIFIED (only enabled bulk action; no Create All / bulk ship/pickup/manifest) |
| Customer 360 Hardware card | VERIFIED (PHP + overflow presenter suite) |
| Next Action | VERIFIED (classifier + action-dialog GET) |
| Action modal | VERIFIED (`c360-correction-dialog` / `#workspaceModal`) |
| Shipment modal | VERIFIED (existing start-shipment fragment + JS wiring in app.js; no live provider call) |
| Manifest modal | VERIFIED (existing gated fulfilment routes; no live manifest) |
| Package photo | VERIFIED (`package_before_label` only) |
| RIN handling | VERIFIED (Blocked + `RIN mapping required` + View; no mapper) |
| Shiprocket calls | NO — Not performed. |
| Production mutations | NO — Not performed. |
