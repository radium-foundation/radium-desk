# Hardware Operations workspace — RadiumDesk-P-07-09-87

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-87`  
**Follows:** P-07-09-86 Awaiting IST cutoff (HEAD `c969fe40` before this ticket).

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-86**. This ticket: **P-07-09-87**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

**Mode:** Operations workspace + shared 360/show stepper that deep-links to existing gated show-page actions. No 360 drawers for serial/invoice/shipment. No RIN mapper. No ingest-from-support-order. No deploy.

---

## What already existed (reuse)

| Piece | Class |
|-------|--------|
| Server-side stage / next action | `HardwareFulfilmentOperationalClassifier` + `HardwareFulfilmentWorkQueue` | VERIFIED |
| IST window | `createdAtSqlBound()` from P-07-09-85/86 | VERIFIED |
| Execution surface | fulfilment show + existing POSTs (serial through ready-for-pickup) | VERIFIED |
| Invoice service | `HardwareFulfilmentInvoiceService::issueInvoice()` existed; **no HTTP route** before this ticket | VERIFIED |
| 360 overflow Fulfilment / Shipment | `Customer360OverflowMenuPresenter` when a fulfilment is resolvable | VERIFIED |
| Unused stepper | `x-c360.customer-journey-tracker` | VERIFIED |
| Isolated ingest blocker | `HardwareFulfilmentIsolatedWorkflowService` requires a Box handoff JSON. There is no HTTP create-from-support-order route | VERIFIED |

One source of truth remains the classifier. Workspace sections are projections of those stages.

---

## UX

Default `/inventory/hardware-fulfilments` is **Hardware Operations** (today’s `queue=work`).

| Surface | Behaviour |
|---------|-----------|
| Header | Hardware Operations + IST window + counts |
| Pills | Needs Fulfilment / In Progress / Ready for Pickup / Exceptions |
| Row | Order, Source (`RDE`/`RIN`), customer, product, payment, serial, invoice, shipment, AWB, stage, **one** primary next-action button, Open order / Customer 360, created IST |
| Row click | Customer 360 when a support order exists. Primary button is the next action |
| Open / Awaiting tabs | Kept. Date/filter logic unchanged |
| Bulk | None. No Select All / Create All |

| Section | Membership |
|---------|------------|
| Needs Fulfilment | No fulfilment + review-candidate RDE in the IST window |
| In Progress | Fulfilment exists and stage is serial→manifest (not ready/completed/blocked) |
| Ready for Pickup | `ReadyForPickup` or `Completed` |
| Exceptions | Frozen/HOLD/blocked, provider rejection, windowed RIN mapping-required |

Needs Fulfilment primary action is **Review & Start** → Customer 360. Start Hardware Fulfilment on 360/show stays **disabled** with the verified isolated-ingest blocker unless a fulfilment already exists.

Show page: progress rail + next-action banner + every existing gated form. **New:** `POST /inventory/hardware-fulfilments/{fulfilment}/invoice` wrapping `issueInvoice()`. Confirm-on-submit. Idempotent. Not exposed from 360 or the Operations list. Tests only — no production mint. No auto-issue.

Customer 360: read-only Hardware Fulfilment Overview after the device section (`id="hardware-fulfilment"`). Overflow adds **View fulfilment** (scroll) for RDE/RIN. Related **Fulfilment / Shipment** remains the escape hatch when the user can open the show page. No allocate / courier / label / pickup / invoice forms in 360.

---

## Stepper

Shared presenter: `HardwareFulfilmentStepper`.

Review → Fulfilment → Serial → Invoice → Shipment → AWB → Label → Packing → Pickup → Manifest → Ready

Mounted on fulfilment show (`#hardware-progress`) and the 360 Hardware card via `x-c360.customer-journey-tracker`. Completed / current / pending come from readiness. Blocked reason sits under the current node. Future nodes are not clickable. Show-page next-action links use existing card ids.

---

## RIN contract (document + display only)

Authoritative prior audit: `docs/desk-rdservice-in-hardware-fulfilment-compat-p-07-09-36.md`. Spoke tree `/Users/ravi/RadiumWebsites/rdservice.in` was not modified. `desk:fulfil-hardware` was not run on RIN ids. Ingest URL was not set.

| Layer | Binding | Class |
|-------|---------|--------|
| Source | rdservice.in `order_rdservice` / Direct Buy `website=direct_buy`; `rdorderid` = `RIN` + padded id | VERIFIED (P-07-09-36) |
| Destination (future) | Desk commerce `(channel=rdservice_in, source_type=commerce_order, source_id=RIN*)` linked to the existing support shell. Never `radiumbox_com` / `RDE*` | VERIFIED identity rule |
| Mechanism today | Cashfree webhook → Desk support `orders` + incident only. Isolated ingest authenticates as Box and requires `RDE*` + Box handoff JSON | VERIFIED |
| Lookup / SKU / shipping | Enrichment-only; stub `model_id` 1027; no ship-to; no country; Desk will not substitute billing | VERIFIED |
| Auth | Spoke→Desk HMAC `rdservice_in`. Isolated Box secret/channel must not be reused | VERIFIED |
| Payment | Cashfree SoT on Desk support row. Hardware payment-evidence writer is `RDE` prefix only | VERIFIED |
| Idempotency (future) | `(rdservice_in, commerce_order, RIN*)`. Do not use later `RD*` billing `ordercode` | VERIFIED collision risk |

**Must not invent:** device `model_id`, physical line, SKU/HSN, split bundle amounts, country `IN`, parcel dims, FM220 USB vs Type-C, source serial, Box channel.

`RIN3512331` / `RIN3512344` remain documented examples. They are not hard-coded.

**Runtime in this ticket:** Desk-resident `RIN%` orders in the IST window appear in Exceptions: `Source: RIN` + `Blocked — RIN mapping required`. Customer 360 / Open order only. No serial/invoice/shipment/Start CTA. `shouldOpenRecord` and isolated ingest were not changed to accept RIN.

Desk cannot classify every `RIN*` as hardware vs a later service billing row from support fields alone — **UNKNOWN**. Windowed Desk RIN rows stay visible as mapping-required rather than hidden. Awaiting review still excludes RIN.

---

## Future bulk (docs only)

Existing services are already per-order (`HardwareSerialAllocationService`, invoice, shipment, courier, documents). A later bulk runner can call those same methods with per-order validation, idempotency, and audit. **No bulk UI in this ticket.**

---

## Tests

Fixtures/fakes only. No production writes. No live Shiprocket. No rdservice.in calls.

| Coverage | Result |
|----------|--------|
| Operations sections + counts; IST cutoff still correct | VERIFIED |
| Eligible RDE without fulfilment → Needs Fulfilment, Review & Start, no create on GET | VERIFIED |
| Fulfilment stages drive In Progress / Ready / Exceptions and primary labels | VERIFIED |
| Frozen/HOLD/blocked + provider error → Exceptions, no mutating CTA | VERIFIED |
| Windowed RIN → Exceptions, mapping-required, no serial/invoice/ship CTA | VERIFIED |
| RDE318421 fixture show → AWB `284931178067631`, courier `Delhivery_Surface (15084)`, provider `1568724940`, label available, package evidence pending; HF count stays 1 | VERIFIED |
| Viewing never creates fulfilment/serial/invoice/shipment/AWB | VERIFIED |
| Issue Invoice POST: gated success + frozen/unallocated refusal + idempotent remint; GET never mints | VERIFIED |
| 360 section + stepper; overflow View fulfilment + Fulfilment / Shipment; fulfilment link still does not open the drawer for unauthorized users | VERIFIED |

Hardware fulfilment feature + unit + overflow: **238 tests, 237 passed, 1 skipped**. Pint on this ticket’s PHP.

Show-page tests that previously forbade the words “Create Shipment” now assert the gated create **form** is absent. The progress banner may name the next operator verb while the POST remains hidden.

---

## Safety

No production fulfilment / serial / invoice / shipment / AWB / pickup / manifest writes. RDE318421 not rewritten. Shiprocket config not changed. Isolated ingest / `shouldOpenRecord` not opened to RIN. Global/batch automation remains OFF. rdservice.in not modified.

---

## Production overlay

**NO — Not performed.** This ticket is local only. Deploy is separately authorized later.
