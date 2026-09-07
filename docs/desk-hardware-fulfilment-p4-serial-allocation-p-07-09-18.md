# P4 hardware serial allocation from Desk opening stock

**Project:** Radium Desk  
**Prompt:** **RadiumDesk-P-07-09-18**  
**Date:** 2026-09-07  
**Type:** Implementation. Owner SKU-map table + Avinash-style picker + `SERIALS_ALLOCATED` writer for P3.  
**Prior:** P-07-09-12 … P-07-09-17.

Classification: **VERIFIED** / **OWNER-LOCKED** / **INFERRED** / **UNKNOWN**.

---

## 0. Repository verification (before modify)

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Branch | `main` (P1+P2+P3 ahead of origin) | VERIFIED |
| Before SHA | `315ba8dcc8bc0bedc4f326f4f230827b3b805a04` | VERIFIED |
| P3 consume contract | `allocated` rows + `SERIALS_ALLOCATED` before mint | VERIFIED |
| Desk serial source | `inventory_serials` via `InventoryStockService` | VERIFIED |
| Box `951` / `MSO1300` → Desk product | **absent** in code/config | VERIFIED absent |
| Architecture vs P0–P3 | Serial-first. P4 writes what P3 reads. No invoice/Shiprocket/ingest enable. | VERIFIED |

---

## 1. Serial source

Allocator uses Desk `inventory_serials` at `hardware_fulfilments.fulfilment_branch_id`.

Does **not** query `radiumbox_prod`, copy Box `product_stock`, invent serials, or use Admin `GenrateInvoice` / `OrderSerialService` (support `orders.serial_number`).

Enter stock via existing opening import / stock-in. P4 only selects `status=available` rows at the fulfilment branch.

---

## 2. SKU map status — Owner input still required

**VERIFIED absent:** no production `channel_sku_maps` rows and `config('hardware_fulfilment.sku_map')` is `[]`.

Implemented structure (empty until P0-M1):

| Column | Role |
|--------|------|
| `channel` + `model_id` | unique key. Box model only. |
| `inventory_product_id` | FK → `inventory_products` |
| `catalog_sku` / `channel_sku` / `notes` | documentation only. **Not** match keys. |

Missing map fail-closed. Name/`sku`/`MSO1300` matching is not used.

**Owner still must supply P0-M1 rows** before any live RDE* (including the seven) can allocate. Hinted Box `model_id`s from P0 (not a Desk product map): 951, 1006, 946, 930, 1723. Desk `inventory_products.id` for those models is **UNKNOWN** in this worktree.

Tests insert a **test-only** map `radiumbox_com` + `951` → `DESK-MSO-TEST`. That is not a production mapping.

---

## 3. Allocation contract

`HardwareSerialAllocationService`:

1. Refuse frozen `RDE*` ids.
2. Lock fulfilment.
3. If already `SERIALS_ALLOCATED`, retry with the same serial set is idempotent.
4. Refuse re-allocation after `INVOICE_ISSUED` / locked `metadata.invoice_serials`.
5. Require `READY_FOR_FULFILMENT` + active fulfilment branch.
6. Resolve each physical line via Owner map (`channel` + `model_id`).
7. Require exactly `qty` distinct serials per line, from **that** mapped product at **that** branch.
8. `lockAvailableSerialsForSale` (sorted locks) + persist `hardware_fulfilment_serials` `status=allocated` + `markSerialSold` + sale movement `notes=hardware_fulfilment:{id}` in one transaction.
9. Transition to `SERIALS_ALLOCATED` only after the allocated count equals physical qty.

Picker UI (`/inventory/hardware-fulfilments`): search available serials and add them. Not free-text paste. Not a second stock database. P-07-09-40 modernizes that page: confirmation, no operator branch override, no shipment/AWB actions on the allocation screen.

P3 then reads `allocatedSerialNumbers()` (`line_no`, `position`). Invoice still cannot mint from READY.

---

## 4. Invariants

- One physical serial → one fulfilment (`inventory_serials.serial_number` global unique + `hardware_fulfilment_serials.serial_number` / `inventory_serial_id` unique).
- No duplicates in one fulfilment (case-insensitive).
- Cannot exceed required qty.
- Wrong branch rejected.
- Already sold / reserved / allocated rejected.
- Partial failure rolls back; state stays `READY_FOR_FULFILMENT`.
- Customer state is not a branch substitute.
- Bundled RD remains an annotation; a priced SAC companion fail-closes.
- Mapped product must be serialized. Non-serialized hardware is not inferred.

---

## 5. Authorization

**UNKNOWN / P0-M6:** Owner has not confirmed permission vs a named Avinash user.

Implemented: Spatie permission `hardware.fulfilment.operate` (plus existing `inventory.view`). Assigned to `admin`, `operations_admin`, `superadmin`, and `hardware_team` **roles**. No user id is hard-coded.

Branch isolation uses `InventoryBranchScope`. A hardware-team operator without that branch assignment cannot allocate there.

---

## 6. Concurrency

Production path: fulfilment `lockForUpdate` + serial `lockForUpdate` in sorted number order + unique constraints.

SQLite tests prove sequential competing allocation (second writer is rejected) and transactional rollback. They are **not** proof of overlapping MySQL/MariaDB writers. No production DB was used.

---

## 7. Non-goals

Invoice mint (P3 already exists). Shiprocket (P5). Ingest enable. Cashfree correlate. Seven pending orders. Invented P0-M1 rows. `radiumbox_prod`. POS/service issuer changes.

---

## 8. Remaining dependencies

| Phase | Needs |
|-------|-------|
| P0-M1 | Owner `channel_sku_maps` rows for live Box `model_id`s |
| P5 | Implemented in P-07-09-19: Null+Fake Shiprocket + invoice/serial/pickup gates. Live HTTP and P0-M2 nicknames still required. |
| P6 | Box callback — implemented P-07-09-20; sender OFF |
| P7 | Observe ingest |
| P8 | New-order E2E, then seven one-by-one |

---

## 9. Classification

**VERIFIED:** Desk `InventoryStockService` lock/sold; global unique serials; P3 consume contract; POS/service paths untouched; map table empty.

**OWNER-LOCKED:** serial-first; stock-location branch; Desk opening stock; Avinash picker; seven frozen; no Admin writer.

**INFERRED:** sale movement (no POS `inventory_sales` row) is the audit record for hardware allocation.

**UNKNOWN:** live Desk product ids for Box 951/1006/946/930/1723; P0-M6 named operator; whether any current SKU is genuinely non-serialized.
