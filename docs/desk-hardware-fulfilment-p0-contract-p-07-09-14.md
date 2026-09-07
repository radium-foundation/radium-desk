# P0 hardware fulfilment implementation contract

**Project:** Radium Desk  
**Prompt:** **RadiumDesk-P-07-09-14**  
**Date:** 2026-09-07  
**Type:** Read-only P0 contract. No application, schema, ingest enablement, mint, serial, Shiprocket, or seven-order work.  
**Sources:** P-07-09-12 Step 1, P-07-09-13 Step 2, Owner-locked policy in this prompt.

**Verdict:** Implementation **must not start** until this contract is the ticket source. P1 is the first code phase. The seven `RDE*` orders stay frozen through P0–P7 and through the P8 new-order positive test.

Classification: **VERIFIED** / **OWNER-LOCKED** / **INFERRED** / **UNKNOWN**.

---

## 0. Repository verification

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Branch | `main` = `origin/main` | VERIFIED |
| HEAD | `e4c3aec328a2a132d3763c115aeb259021bc2585` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Worktrees | this `main`; `radium-desk` `feat/rd-fresh-01-inventory-pos`; phase1-clean detached | VERIFIED |
| Ledger | `docs/cursor-prompt-ledger.md` (no `cursor-prompt-log.md`) | VERIFIED |
| Step 1 / Step 2 docs | present | VERIFIED |

This HEAD is **not** assumed to be the full production tree (surgical overlays). Do not assume Shiprocket credentials, IRN HTTP, SKU rows, or pickup nicknames exist.

---

## 1. Owner decisions incorporated

| # | Decision | Class |
|---|----------|-------|
| 1 | Issuer = physical Desk stock / fulfilment location, not customer state | OWNER-LOCKED |
| 1a | Delhi stock B2C → `INV-671…` (`location:delhi_b2c`) | OWNER-LOCKED |
| 1b | Delhi stock B2B → `INV-07671…` (`location:delhi`) | OWNER-LOCKED |
| 1c | Mumbai stock B2C and B2B → `INV-27671…` (`location:mumbai`) | OWNER-LOCKED |
| 2 | Billing/state does not override location (MH customer + Delhi stock = Delhi) | OWNER-LOCKED |
| 3 | New Desk `INV-*` only. No `IND*` / `INM*` mint or restore | OWNER-LOCKED |
| 4–5 | Online hardware uses Desk opening stock; Avinash selects serials from available Desk inventory | OWNER-LOCKED |
| 6 | No Desk application access to `radiumbox_prod` | OWNER-LOCKED |
| 7 | SKU map Owner-supplied only | OWNER-LOCKED |
| 8 | Pickup follows stock location (Delhi stock → Delhi pickup; Mumbai → Mumbai) | OWNER-LOCKED |
| 9 | Invoice includes bundled RD when the order contains it | OWNER-LOCKED |
| 10 | IRN where legally required | OWNER-LOCKED (policy). Live GSP/HTTP **UNKNOWN** |
| 11 | Seven `RDE*` only after complete flow + new-order PASS, one-by-one | OWNER-LOCKED |
| 12 | State machine PAID→…→SYNCED | OWNER-LOCKED |

H-1…H-4 technical path from Step 2 remains in force (commerce Path B, `mint()` only, invoice then serial, Desk `InventoryStockService`, Shiprocket gates).

---

## 2. Contradictions: Owner policy vs current code/docs

| Topic | Current `main` (VERIFIED) | Owner-locked hardware | Contract |
|-------|---------------------------|----------------------|----------|
| Delhi product series | `requireForProductBranch` → always `delhi` → `INV-07671` for **all** POS/product B2C and B2B | Delhi **hardware B2C** → `INV-671` | New `requireForHardwareFulfilment()`. **Do not change POS** `requireForProductBranch` |
| Service issuer | Location from billing_state / GSTIN state | Hardware location from stock branch only | Hardware must not call `requireForCommerceOrder()` as it is today (HSN kind → service matrix) |
| Mixed HSN+SAC | `StatutorySupplyKindResolver::requireFromLines` fail-closes mixed 8471 + 99* | Bundled RD must appear on the hardware invoice | Hardware issuer **ignores** mixed-kind fail-closed. See §5.6 |
| `INV-671` comments | Documented as Delhi B2C **service** only | Hardware Delhi B2C **shares** that sequence | Same `location:delhi_b2c` sequence; do not create a second Delhi B2C sequence |
| Serial UX | Step 1/2: no paste as final architecture | Avinash **selects** from available Desk stock | Controlled picker of **available** serials at the fulfilment branch. Not free-text of unvalidated labels |
| IRN | `EInvoiceEligibility` B2B=eligible; `NullEInvoiceGateway`; `worker_may_mint=false` | Generate where legally required | Queue B2B; **do not** enable live HTTP until provider/credentials exist |
| Pickup | WIP single `SHIPROCKET_PICKUP_LOCATION`; nickname UNKNOWN | Two locations, follow stock | Two Owner-supplied nicknames; fail-closed if unset |
| SKU | No map | Owner will supply | Empty `channel_sku_map`; fail-closed if missing row |

No contradiction with: Path B commerce identity, `mint()` sole writer, no Admin `GenrateInvoice`, no `radiumbox_prod` SQL, invoice-before-serial, GST split on commerce using seller vs **place of supply** (issuer ≠ split).

---

## 3. Classification register

### VERIFIED

- HMAC ingest, unique `(channel, source_type, source_id)`, `mint()` idempotency key `statutory:{channel}:{source_type}:{source_id}`.
- `payload_hash` omits metadata, timestamps, `support_order_id`.
- Box payload `paid_at` is `now()` on every enqueue — must not be hashed.
- Cashfree creates RDE support shells without lines; `issueFromSupportOrder` is `rdservice_in` only.
- `GstSplitService` is commerce-only; POS `issueFromPosSale` has no split and no serials on PDF.
- Desk `inventory_serials` unique; branches `DELHI-RETAIL`, `MUMBAI`.
- Box checkout snapshots `users_address` → `userdetails` (one bill/ship snapshot).
- Box outbox + invoice-status receiver exist; Desk has no fulfilment callback sender.
- Shiprocket absent on `main`; WIP S4 has no invoice/serial gates.
- Seven `RDE*` still Paid/Processing, no invoice/AWB/branch; `radiumbox_com` commerce = 0 (SELECT this session).
- B2B e-invoice eligibility = valid buyer GSTIN; live IRN not bound.

### OWNER-LOCKED

All twelve policy rows in §1. Plus Step 2 Path B, `mint()` via `issueFromCommerceOrder`, Desk inventory only, invoice then serial.

### INFERRED (do not upgrade)

- Fulfilment location is an **operator-chosen** Desk branch (`DELHI-RETAIL` or `MUMBAI`) recorded **before** mint; all serials must come from that branch.
- Pre-mint **availability count** at that branch (no reserve) to reduce stranded invoices; not a hard stock reservation.
- Bundled RD is **not** a second priced line: Box has one `order_details` total + FKs. Invoice shows RD as description/annotation (and persisted `rdserviceid`). A valued SAC split would invent amounts.
- GST split stays seller GST state (from issuer location) vs ingested `place_of_supply_state`. MH customer + Delhi stock → Delhi series + **IGST** if POS is Maharashtra.
- One shipment per commerce order in v1.
- Avinash acts via a new permission, not a hardcoded user id.

### UNKNOWN (still need Owner)

| ID | Item |
|----|------|
| **P0-M1** | Exact `channel_sku_map` rows (Box `model_id` / `sku_code` → Desk `inventory_products.id`) |
| **P0-M2** | Shiprocket pickup **nicknames** for Delhi and Mumbai |
| **P0-M3** | IRN provider, credentials, and whether any B2B threshold besides “valid GSTIN” |
| **P0-M4** | Confirm bundled RD = annotation on the hardware line (recommended) vs a later priced split |
| **P0-M5** | Confirm POS walk-in Delhi B2C stays `INV-07671` (recommended: **yes, unchanged**) |
| **P0-M6** | Confirm allocator role = `hardware.fulfilment.operate` (Avinash assigned later), not email hardcoded |

P1–P2 may proceed without P0-M1…M3. **P3 mint requires P0-M5 implicit default or confirm.** **P4 requires P0-M1.** **P5 live pickup requires P0-M2.** **IRN HTTP requires P0-M3.**

---

## 4. Target state machine (OWNER-LOCKED)

```
PAID → INGESTED → READY_FOR_FULFILMENT → INVOICE_ISSUED
  → SERIALS_ALLOCATED → SHIPMENT_CREATED → AWB_ASSIGNED → SHIPPED → SYNCED
```

| State | Persistence | Advance when |
|-------|-------------|--------------|
| PAID | Box `orders` | Cashfree paid (existing) |
| INGESTED | `commerce_orders` + `hardware_fulfilments` row `ingested` | HMAC ingest 201/200 same hash |
| READY_FOR_FULFILMENT | fulfilment.branch_id set; sku map present; availability count ≥ qty | Operator selects fulfilment branch |
| INVOICE_ISSUED | `statutory_invoices` + fulfilment.invoice_id | `issueFromCommerceOrder` after hardware issuer applied |
| SERIALS_ALLOCATED | `hardware_fulfilment_serials` | Avinash picker commits N serials at that branch |
| SHIPMENT_CREATED | `shipments` | Provider create/search bound |
| AWB_ASSIGNED | `shipments.awb` unique | AWB assign/reconcile |
| SHIPPED | fulfilment + shipment status | Track or operator |
| SYNCED | Box handoff display copies | HMAC callback ACK |

Idempotency, retries, and crash models: unchanged from P-07-09-13 § state machine. Invoice OK / serial fail → keep invoice. Serial OK / ship fail → keep those serials. Ship OK / callback fail → retry callback only. Timeout → search before create.

---

## 5. P1→P8 implementation contract

Flags default **off** in every phase: `HARDWARE_FULFILMENT_ENABLED`, `SHIPROCKET_ENABLED`, `channel_ingest.auto_issue_invoice`, `worker_may_mint`, live IRN HTTP. Box `DESK_INGEST_ENABLED` unread; empty URL/secret remains the HTTP gate until P7.

Do **not** merge dirty `feat/rd-fresh-01-inventory-pos`. Port reviewed slices only.

### P1 — Persist + hash contract

**Scope:** Desk ingest persists hardware fields; canonical hash includes them; Box retry `paid_at` excluded from hash.  
**Non-goals:** enable ingest, mint, Shiprocket, replay outbox.

**DB:** additive on `commerce_order_items`: `shipping_line_kind`, `requires_shipping`, `product_id`, `model_id`, `catalog_sku`. Additive structured address/parcel columns on `commerce_orders` if still missing on `main`.

**Hash MUST include:** identity, payment status/provider/reference/method/currency, customer, gstin, structured bill/ship parts, billing_state, POS, branch_code/seller if sent, discount, every line field above + tax amounts + kind + FKs.  
**Hash MUST NOT include:** `paid_at`, `ordered_at`, `support_order_id`, `metadata` secrets, Box wall-clock fields.

**API:** existing `POST /api/v1/channel-orders` HMAC. Reject hardware payloads missing physical lines (`shipping_line_kind=physical_merchandise` or `model_id`) once kind is persisted — empty-line support-only stay invalid for fulfilment (may still persist as commerce if they pass today’s validator; fulfilment row not created).

**Tests:** persist kind/ids/gst%; same business body + different `paid_at` → 200; change `gst_percentage` or `model_id` → 409; HMAC fail 401.

**Deploy:** code only, flags off, **do not** set Box URL/secret.  
**Rollback:** revert persist; no live Box traffic.

### P2 — Fulfilment row + Cashfree link

**Scope:** `hardware_fulfilments` + events; on ingest of `radiumbox_com` + `RDE*` + ≥1 physical line, create fulfilment `ingested`. Link `orders.order_id = source_id` → `support_order_id`.  
**Non-goals:** mint, allocate, ship.

**DB:** `hardware_fulfilments` (unique `commerce_order_id`, `idempotency_key`, state, `fulfilment_branch_id` nullable, invoice_id, shipment_id); `hardware_fulfilment_events` append-only.

**Cashfree:** link only. Never `issueFromSupportOrder` for `radiumbox_com`. Store both payment ids in metadata; correlate by `RDE*`.

**Tests:** ingest creates one fulfilment; duplicate ingest no second row; RD* service commerce does not create hardware fulfilment; link matches `RDE*`; mismatch fail-closed.

**Deploy:** flags off. **Rollback:** unused tables unused.

### P3 — Hardware issuer + mint

**Scope:** `HardwareIssuer::require(fulfilment_branch_code, buyer_gstin)`:

| Stock branch | Buyer GSTIN | Location | FY 2026-27 number |
|--------------|-------------|----------|-------------------|
| `DELHI-RETAIL` | empty | `delhi_b2c` | `INV-671…` |
| `DELHI-RETAIL` | valid | `delhi` | `INV-07671…` |
| `MUMBAI` | empty or valid | `mumbai` | `INV-27671…` |
| other | — | fail-closed | — |

Invalid non-empty GSTIN fail-closed (never B2C). Customer state **not** an input. Place of supply **not** an input.

Entry: operator sets `fulfilment_branch_id` → READY (availability count ≥ physical qty for **mapped** SKUs; if map missing, cannot READY). Then `HardwareFulfilmentService::issueInvoice()` sets commerce `branch_code` from that branch and calls `issueFromCommerceOrder` **after** a hardware-specific issuer hook (do not use today’s HSN→service matrix). Sole allocate remains `mint()`.

**PDF:** do not persist the hardware PDF until `SERIALS_ALLOCATED` (immutable-file rule).

**POS:** `issueFromPosSale` / `requireForProductBranch` **unchanged** (P0-M5).

**IRN:** after mint, existing `queueEinvoiceIfEligible` (B2B queued, B2C skip). Null gateway stays. No live WhiteBooks in P3.

**Bundled RD:** persist FKs; PDF/line text must include RD when `rdserviceid` present. No invented second amount (P0-M4).

**Non-goals:** auto-issue, remint `IND*`, change service RD* issuer.

**Tests:** MH B2C + Delhi branch → `INV-671` / Delhi seller / IGST if POS=MH; Delhi B2B → `INV-07671`; Mumbai B2C/B2B → `INV-27671`; retry same key; POS Delhi B2C still `INV-07671`; no issuer until branch set.

**Deploy:** `HARDWARE_FULFILMENT_ENABLED` still false or operator-only behind permission with ingest still off.  
**Rollback:** flag off; issued numbers stay.

### P4 — SKU map + Avinash serial picker

**Scope:** `channel_sku_map` (unique `channel+model_id`); Owner rows only. UI: list **available** serials for mapped product at `fulfilment_branch_id`; operator selects exactly `qty` distinct; `InventoryStockService` locks and `markSerialSold` in one txn. Retry returns existing allocation.

**Non-goals:** paste unvalidated serials; `radiumbox_prod`; invent map rows.

**Tests:** missing map fail-closed; wrong branch fail-closed; qty≠selected fail-closed; concurrent same serial one winner; retry no second serial.

**Deploy:** map empty in prod until Owner supplies P0-M1.  
**Rollback:** no allocate if flag off.

### P5 — Shiprocket port + gates

**Scope:** Clean-port WIP `shipments` / Null+Fake / create-AWB-pickup processors. **Add gates:** invoice issued, serials allocated, structured ship-to, pickup nickname for **that** fulfilment branch. Env only: `SHIPROCKET_API_EMAIL`, `SHIPROCKET_API_PASSWORD`, `SHIPROCKET_CHANNEL_ID`, `SHIPROCKET_PICKUP_DELHI`, `SHIPROCKET_PICKUP_MUMBAI` (names indicative). Never copy Admin/Box passwords.

**Behaviour:** merchant `order_id` = `shipment_no`; search-before-create on timeout; unique AWB; callback must not recreate AWB.

**Non-goals:** live HTTP until P0-M2 + flag; webhook v1 optional later.

**Tests:** Fake: reject missing invoice/serial/pickup; duplicate create reused; timeout → search bind.

**Deploy:** `SHIPROCKET_ENABLED=false`, provider `none`.  
**Rollback:** flag off.

### P6 — Box fulfilment callback

**Scope:** Desk HMAC POST to Box `POST /api/desk/fulfilment-status` (or additive on invoice-status). Body: `source_id`, invoice, serials[], shipment ids/AWB, state. Box apply idempotent display copies. Desk has no `radiumbox_prod` writes.

**Tests:** replay 401; duplicate apply; AWB not overwritten by empty retry.

**Deploy:** `DESK_CALLBACK_SECRET` both sides; sender flag off until P8. P6 sender shipped disabled (P-07-09-20).  
**Rollback:** disable sender.

### P7 — Enable ingest only

**Scope:** Owner sets matching `CHANNEL_INGEST_SECRET_RADIUMBOX_COM` and Box `DESK_BASE_URL`. Observe handoffs → commerce + fulfilment `ingested`.  
**Non-goals:** mint, allocate, ship, replay-mutate the seven, process them.

**Gate:** P1 hash tests green on staging/fixture; 401 then 201 fixture; **then** live observe.  
**Rollback:** empty Box `DESK_BASE_URL`.

### P8 — New-order positive test (then seven-order procedure)

**Scope:** One **new** paid hardware order through PAID→SYNCED with flags explicit, Owner pickup + SKU map for that model, operator branch + Avinash serials.  
**Non-goals:** the seven pending `RDE*`.

**Pass criteria:** one Desk `INV-*` (correct series for chosen branch + B2B/B2C), serials on PDF, AWB unique, Box display copy, no second invoice/AWB on retry.

**Rollback:** flags off; do not delete the test invoice number.

**Seven-order procedure (after P8 PASS only):** see §8. Not a code phase.

---

## 6. Acceptance tests (by phase)

| Phase | Must pass |
|-------|-----------|
| P1 | Hash ignores `paid_at`; business-field change 409; fields persist; HMAC 401 |
| P2 | One fulfilment per RDE commerce; Cashfree link; no fulfilment for service-only RD* |
| P3 | Implemented in P-07-09-17. Matrix in §5 P3; idempotent mint; POS Delhi B2C unchanged; no mint without branch; PDF absent until serials |
| P4 | Implemented in P-07-09-18. Map required; N distinct serials same branch; concurrency; retry stable |
| P5 | Implemented in P-07-09-19. Gates fail-closed; search-before-create; no AWB duplicate |
| P6 | HMAC callback idempotent; empty AWB does not clear existing |
| P7 | Live 201/200 for a **fixture or new** ingest; seven remain unminted |
| P8 | Full SM on a **new** order; retry safe |

Shared negatives: no `IND*`/`INM*`; no `radiumbox_prod` connection in app; no `issueFromPosSale` for RDE; auto-issue stays off.

---

## 7. Safe enablement sequence

1. Merge P1–P6 to `main` with all flags off.  
2. Owner fills `channel_sku_map` (P0-M1) and pickup nicknames (P0-M2) in config/env — not in code guesses.  
3. P7: secrets + URL; ingest observe.  
4. Assign `hardware.fulfilment.operate` to Avinash (and Admin).  
5. P8: one new order, operator Delhi or Mumbai, picker, ship, callback.  
6. Only after documented P8 PASS: §8.

Never enable Box ingest before P1. Never enable Shiprocket HTTP before P5 gates + P0-M2. Never enable IRN HTTP before P0-M3.

---

## 8. Seven existing orders — freeze and later procedure

**P0–P8 freeze (OWNER-LOCKED):** no ingest replay that mints, no Issue, no serial, no Shiprocket, no status change, no Admin restore.

Re-verified this session: all seven Paid/Processing, `invoicecode` NULL, `awb` NULL, `branch` NULL, Desk `radiumbox_com` = 0.

**After P8 PASS, one at a time:**

1. Confirm that order still Paid/Processing, no invoice/serial/AWB (re-SELECT).  
2. Ensure P0-M1 rows for **that** order’s `model_id`s (318360 needs 951 + 1006; 367/391: 946; 378: 930; 379: 1006; 382: 951; 388: 1723).  
3. Allow ingest of that `source_id` only if not already ingested (P7 may already have created commerce rows — if P7 ingested all pending handoffs, **do not mint them until this step**).  
4. Operator sets fulfilment branch; confirm availability count.  
5. Issue once (`mint()`).  
6. Avinash allocates exact qty.  
7. Ship + AWB + callback.  
8. Stop if any step fails; do not start the next RDE.  
9. Order: prefer single-SKU orders first (318367, 318378, 318379, 318382, 318388, 318391) then 318360 (qty 2 + two models).

Missing data still: gst% on wire (P1 Box payload), SKU map, pickup nicknames, country/parcel. Do not invent them.

---

## 9. Observability / audit

- `channel_ingest_attempts` (existing)
- `hardware_fulfilment_events` (from, to, actor, payload)
- `inventory_movements` on allocate
- `outbox_events` unique keys
- Log **no** secrets; log `source_id`, fulfilment id, invoice number, AWB, state
- Metrics: ingest 201/200/409, mint fail-closed, allocate fail, ship search-vs-create, callback fail

---

## 10. Remaining Owner decisions and blockers

**Must approve before the named phase:** P0-M1 (P4), P0-M2 (P5 live), P0-M3 (IRN HTTP), P0-M4 (if they want a priced RD line), P0-M5 (POS unchanged — recommend lock yes), P0-M6 (permission vs named user).

**Blockers if P1 starts tomorrow:** none for persist/hash. Blockers for a complete E2E: map, pickup nicknames, IRN HTTP, Box payload gst%/ids (Box repo change in P1 companion), production overlay vs tag.

---

## 11. What this prompt did not do

No application code, migrations, `.env`, ingest enablement, outbox replay, invoices, serials, Shiprocket, AWB, seven-order processing, `radiumbox_prod` app connection, credential copy, Admin writer restore, `IND*`/`INM*` mint, historical invoice changes, commit, push, deploy.

---

## 12. Amendment — serial-first (Owner update, 2026-09-07)

Recorded by **RadiumDesk-P-07-09-15**. This does **not** rewrite the P0 history above.

**Supersedes** §4 invoice-then-serial and the P3 note “PDF deferred until serials / issue then allocate”.

Owner-locked hardware sequence is now:

```
PAID → INGESTED → READY_FOR_FULFILMENT → SERIALS_ALLOCATED
  → INVOICE_ISSUED → SHIPMENT_CREATED → AWB_ASSIGNED → SHIPPED → SYNCED
```

**Invariant:** for hardware, `SERIALS_ALLOCATED` MUST precede `INVOICE_ISSUED`. The mint layer receives the final allocated serial list. Shipment/AWB is allowed only after invoice + serial allocation + required fulfilment gates.

Invoice serial rendering (P3/P4, not P1):

- 1–5 serials: display inline on the invoice/details.
- More than 5: invoice text “Serial Numbers: See Annexure A”. Annexure A is part of the **same** invoice PDF/document, not a second invoice, and lists every serial immutably linked to that statutory invoice.
- POS quantities of 100–200 devices are in scope. Do not assume a small serial list.

See `docs/desk-hardware-fulfilment-p1-foundation-p-07-09-15.md`.

P2 implementation (payment evidence + state guards, no mint/serial/ship): `docs/desk-hardware-fulfilment-p2-workflow-p-07-09-16.md`.
