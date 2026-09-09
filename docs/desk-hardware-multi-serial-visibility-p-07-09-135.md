# Multi-serial visibility and copy UX — RadiumDesk-P-07-09-135

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-135`  
**Mode:** UI/presenter only. No serial allocate/deallocate. No parcel save. No shipment/AWB/label/pickup/manifest. No RDE318516 write.

Ledger last used at start of this ticket: **P-07-09-133** on `HEAD`. Concurrent uncommitted **P-07-09-134** (isolated READY for RDE318435 / RDE318401) already occupied 134. This ticket: **P-07-09-135**.

---

## Classification: A

Production SELECT (read-only) before this change: HF15 / RDE318516 had quantity 10 and 10 distinct allocated serials. Invoice `INV-076724` (id 629). `shipment_id` null. AWB null. The package-dimensions popup showed only the first serial because `HardwareFulfilmentOperationalRow::serialDisplay()` took `explode(',', $serialStatus)[0]`.

Backend already returned the complete list via `HardwareFulfilmentWorkflowService::allocatedSerialNumbers()` → `$ready->serials` → classifier `serialStatus` implode. No serial records were created or changed.

Serials at inspect (unchanged by this ticket):

1. `10532347`
2. `10556040`
3. `10561005`
4. `10553573`
5. `10556002`
6. `10532464`
7. `10566561`
8. `10566466`
9. `10562862`
10. `10553763`

---

## UI

Shared presenter `HardwareAllocatedSerialDisplay` + Blade fragment `serial-summary`.

| Qty / allocation | Compact | Expanded |
|------------------|---------|----------|
| 1 complete | the serial (existing copyable identifier; no `+0`) | none |
| N>1 complete | `first +N-1` e.g. `10532347 +9` | numbered list, Copy All |
| count ≠ quantity | `Serials: X / Y allocated` | list plus explicit mismatch |

Copy All uses existing `data-copyable-identifier` (one serial per line). Toast: `Copied N serials`. Clipboard fallback already exists. Panel is `position: fixed` on `document.body` so the modal does not clip it. Hover uses a short close delay so the operator can reach the list.

Surfaces: package-dimensions / action dialog, issue-invoice dialog, Hardware Fulfilment show (line + shipment confirm), work queue, Hardware workspace, Customer 360.

Allocation contract, inventory ownership, fulfilment state, invoices, Shiprocket, packaging master, and `commerce_orders.parcel` are unchanged.

---

## Tests / build / lint

- PHPUnit HardwareFulfilment feature+unit: 307 passed, 1 skipped
- Focused serial display/view/parcel dialog tests: passed
- Vitest `tests/js/hardware-action-dialog.test.js`: 17 passed
- Pint `--dirty`: passed
- `php -l` on changed PHP: passed
- Vite build: passed (`app-Drx2bi6m.js`, `app-BmJg3rcM.css`, `dashboard-CovULnXg.js`)

---

## Git

Before SHA: `bd9669020a538a17475ac8138a2dbeccf522b0c4`  
Dirty unrelated `ShiprocketCreateOrderRequest.php` and investigation markdown: **not included**.  
MakeLinkIT / Gitea push: **NO — Not performed.**

Production overlay, HTTPS check, and HF15 re-SELECT are recorded after deploy in the completion report.
