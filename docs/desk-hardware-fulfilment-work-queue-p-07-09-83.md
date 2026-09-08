# Hardware fulfilment Work Queue — RadiumDesk-P-07-09-83

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-83`  
**Mode:** Extend the existing Hardware Fulfilment page with one operational Work Queue. No fulfilment/serial/invoice/shipment/AWB/pickup/manifest writes. No batch actions. No deploy.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-82**. This ticket: **P-07-09-83**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Authoritative fields (do not invent others)

| Concern | Source | Class |
|---------|--------|-------|
| Isolated / ingest cutoff | `HardwareFulfilmentEligibility::CUTOFF_IST` = `2026-09-05 00:00:00`, TZ `Asia/Kolkata` | VERIFIED |
| Isolated order timestamp | Commerce `ordered_at`. `created_at` is **not** substituted on isolated ingest | VERIFIED |
| Awaiting / no-commerce date | Desk `orders.created_at` (documented proxy; support rows have no `ordered_at`) | VERIFIED |
| Hardware class | `source_id` / `orders.order_id` prefix `RDE`. RIN excluded | VERIFIED |
| Payment (commerce / fulfilment) | Commerce `payment_status` in `paid`/`success`/`captured`, or `paid_at`, or fulfilment `paid_recognized_at` | VERIFIED |
| Payment (awaiting support order) | Desk `Order::isCashfreeVerified()` (`cashfree_payment_id`) | VERIFIED |
| Customer / order identity | Fulfilment `source_id`; commerce `order_no` / `customer_name`; support `orders.order_id` | VERIFIED |
| Product / SKU / qty | Commerce physical item `sku` / `catalog_sku` / `model_id` / `qty`. Support `product_name` only when there is no fulfilment | VERIFIED |
| Fulfilment relationship | `hardware_fulfilments` by `source_id` or `support_order_id` | VERIFIED |
| Serial | Fulfilment serials with status `allocated`. Support `serial_number` / `transaction_id` = already completed on Desk | VERIFIED |
| Invoice | `statutory_invoice_id` + issued `invoice_number` | VERIFIED |
| Shipment / AWB | `shipments` bound when `external_order_id` + `external_shipment_id` are filled; AWB from shipment or fulfilment | VERIFIED |
| Next action | `HardwareShipmentEligibility::inspect()` on the show page. Queue labels only; no POST from the list | VERIFIED |

### Date-window rule used by this queue

- **Awaiting support orders:** `orders.created_at` from `2026-09-05 00:00:00 IST` through now.
- **Existing fulfilments:** always listed, even when commerce `ordered_at` is null. This keeps RDE318421 visible.

This dual-source rule is the existing architecture, not a newly guessed timestamp.

---

## What shipped in this ticket (local only)

Default `/inventory/hardware-fulfilments` is now **Work Queue**.

| Tab | Purpose |
|-----|---------|
| Work Queue | Single operational list. Default date range 5 Sep 2026 IST → now |
| Open Fulfilments | Existing fulfilment-only ship list (`queue=open`). Kept for filters/tests |
| Awaiting Fulfilment | Existing no-fulfilment review list (`queue=awaiting`) |

Open-filter query params (`serial`, `state`, `branch_id`, `shipment_status`) still open the Open Fulfilments tab so existing list-filter tests keep working.

Work Queue columns: Order ID, date, customer, product/SKU, qty, payment, fulfilment, serial, invoice, shipment, AWB, stage, next action, blocker.

Awaiting rows: **Open order** + **Customer 360** only. No create-fulfilment button.

Fulfilment rows: GET link to the existing show page. The queue does not POST Allocate / Issue / Create Shipment / AWB / label / pickup / manifest.

No Create All / bulk actions.

Owner-HOLD constants now include `RDE255714` and `RDE313554` in addition to `RDE318438`. Production still has only `RDE318438` until a later deploy.

---

## Production read-only snapshot (2026-09-08 17:09 IST)

SELECT only. This ticket did not write production rows.

| Item | Count | Class |
|------|------:|-------|
| Qualifying operational rows (fulfilments + review candidates) | **13** | VERIFIED |
| Blocked / review in the default window | **9** | VERIFIED |
| Work-list rows if this code were deployed | **22** | VERIFIED |
| Excluded from the work list | **21** (Desk-completed in window **2** + RIN **19** + unpaid in window **0**) | VERIFIED |
| RDE support orders | 875 | VERIFIED |
| Hardware fulfilments | 1 | VERIFIED |
| Shipments | 1 | VERIFIED |

### Counts by operational stage (if deployed now)

| Stage | Count | Rows |
|-------|------:|------|
| Awaiting Fulfilment | 12 | `RDE318401`, `RDE318434`, `RDE318435`, `RDE318437`, `RDE318467`, `RDE318469`, `RDE318477`, `RDE318482`, `RDE318486`, `RDE318487`, `RDE318489`, `RDE318490` |
| Label/Packing Pending | 1 | RDE318421 — AWB + label present, package evidence absent → next action **Record Package / Label-Applied Evidence** |
| Blocked / Review Required | 9 | 7 frozen + HOLD `RDE318438` + blocked `RDE318400` |
| Other stages | 0 | — |

`RDE255714` and `RDE313554` exist and are paid, but their Desk `created_at` is before the cutoff (2026-07-02 and 2026-08-26). They are not in the default window. They remain HOLD if they appear later.

RDE318421 observed (unchanged by this ticket): fulfilment `awb_assigned`, shipment bound (`1572506854` / `1568724940`), AWB `284931178067631`, label URL present, no package evidence, pickup/manifest null. **This ticket did not create that shipment/AWB/label.**

---

## Safety

- No fulfilments, serials, invoices, shipments, AWBs, pickups, or manifests were created by this implementation.
- Global hardware automation remains OFF.
- Batch shipment / invoice / serial / AWB actions were not added.
- RIN remains excluded.
- Frozen / HOLD / blocked rows are listed as Blocked / Review Required, not processed.
- Credentials were not logged or committed.

---

## Tests / lint

- Focused Work Queue + classifier + awaiting: passed.
- Related hardware fulfilment / serial / invoice / shipment / courier / navigation: passed.
- Pint on dirty PHP: passed (import/brace fixes only).

Browser click-through was **not** available in this session. Behaviour was verified by HTTP feature tests against the existing Blade page.
