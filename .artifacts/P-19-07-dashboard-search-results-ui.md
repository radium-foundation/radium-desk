# P-19-07 — Dashboard search results UI

| Field | Value |
| --- | --- |
| Global ID | **P-19-07** |
| Cursor ledger | **RadiumDesk-P-19-07** |
| Branch | `fix/p19-07-dashboard-search-ui` |
| Commit | **`bbf69140b`** (pre-push) |
| Base | **`8d2356d7183e5c5738b0a89bbd1013a05012b50f`** |
| Start | 2026-11-19 **07:22:46 IST** |

## Changes

| Area | Action |
| --- | --- |
| Dashboard search history panel | **Removed** render path (`showSearchOrderHistory`); cleanup via `hideSearchOrderHistoryPanel` |
| `/search` `order_history` JSON | **Preserved** |
| Intake / Quick Create `renderIntakeOrderHistory` | **Preserved** |
| Multi-hit strip | Actions only (Customer 360 / Reopen); removed duplicate ref·order·status |
| `service-case-row.blade.php` | Order id tooltip |
| `app.css` | Order column contrast, truncation, search-match row tint, action strip spacing |

## Tests (07:23:55 IST)

| Command | Result | Duration |
| --- | --- | --- |
| `npm run test -- --run tests/js/dashboard-global-search.test.js` | **46/46 PASS** | ~4.0s |
| `npm run test -- --run tests/js/intake-search-flow-errors.test.js` | **2/2 PASS** | ~0.6s |
| `npm run build` | **PASS** | ~0.4s |

## Deploy

**NO — not performed.**