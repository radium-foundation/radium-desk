# P-16-53 — OEM RD expiry in REF inline workflow

**Global ID:** P-16-53  
**Cursor ledger:** RadiumDesk-P-16-53  
**Risk:** HIGH (service-case lifecycle; reuses P-16-51 completion endpoint)

## Summary

Removes the P-16-51 **serial-column OEM results / administrator completion panel** (row-height regression). OEM capture remains informational. Mantra **`rd_expiry_date`** is held in memory as a **proposed** RD subscription expiry and prefilled in the existing **REF.** inline **Add service reference** editor. Administrator tick posts **`complete-assignment`** (unchanged P-16-51 backend).

## OEM data mapping

| Mantra OEM column | Used for prefill | Notes |
|-------------------|------------------|-------|
| **RD Expiry Date** (`rd_expiry_date`) | Yes (Y-m-d) | Only authoritative prefill source |
| Warranty Expiry Date | **Never** | Must not substitute for RD subscription expiry |
| Morpho `fields.expiry_date` | **Never** (P-16-53 scope) | Admin enters expiry manually for Morpho `draft_ready` |

## Frontend modules

- `dashboard-oem-rd-expiry-prefill.js` — normalize + propose from Mantra draft only
- `dashboard-oem-inline-completion.js` — in-memory session → `completeAssignmentUrl` + proposed expiry
- `dashboard-oem-warranty-handoff.js` — registers inline context on draft/recover; no DOM panel under Serial
- `pages/dashboard.js` — REF editor shows date input when OEM context exists; save branches to `complete-assignment`
- `transaction-id-cell.blade.php` — hidden `type="date"` in inline editor

## Preserved (P-16-51)

- `OemHandoffAdministratorCompletionService`, `CompleteOemHandoffAssignmentRequest`, authorization, audit, atomic close
- Mantra CAPTCHA/Check manual; production flags unchanged by this task

## Testing

- Vitest: `dashboard-oem-warranty-handoff.test.js`, `dashboard-oem-rd-expiry-prefill.test.js`
- PHPUnit: no backend delta; reuse P-16-51 `DashboardOemHandoffCompleteAssignmentTest` evidence on unchanged PHP

## Deployment

**NOT AUTHORIZED** — merge/tag only; stop at deployment gate.