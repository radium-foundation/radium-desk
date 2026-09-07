# P2 hardware fulfilment workflow and Cashfree correlation

**Project:** Radium Desk  
**Prompt:** **RadiumDesk-P-07-09-16**  
**Date:** 2026-09-07  
**Type:** Implementation. Workflow guards + payment evidence only.  
**Prior:** P-07-09-12 … P-07-09-15.

Classification: **VERIFIED** / **OWNER-LOCKED** / **INFERRED** / **UNKNOWN**.

---

## 0. Repository verification (before modify)

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Branch | `main` (ahead of `origin/main` by P1) | VERIFIED |
| Before SHA | `4d56d5f99170137400a74bf581c9e2f9c4c9a1a5` (P1) | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| P1 | tables, hasher, `ensureIngested`, serial-first docs | VERIFIED |
| P1 vs this contract | Matches. No stop. | VERIFIED |

---

## 1. Owner-locked identity and serial-first

Primary identity remains `statutory:radiumbox_com:commerce_order:RDE*`.

Cashfree identifiers are **evidence only**:

| Identifier | Desk storage | Unique? | Role |
|------------|--------------|---------|------|
| Merchant `order_id` (`RDE*`) | `orders.order_id` | Yes | Preferred business correlation |
| `cf_payment_id` | `orders.cashfree_payment_id` | Yes (nullable) | Payment idempotency on Desk `orders` |
| `cf_order_id` | not a Desk column | n/a | Optional evidence copy |
| `gateway_*`, `bank_reference` | `orders` indexed, not unique | No | Rail correlation |
| `transaction_id` | service reference | No | Not Cashfree |
| Webhook delivery | `cashfree_webhook_logs.id` | PK only | Not a business key |

Preferred link remains `orders.order_id = RDE*` when that row exists.

Sequence (unchanged from P1 amendment):

```
PAID → INGESTED → READY_FOR_FULFILMENT → SERIALS_ALLOCATED
  → INVOICE_ISSUED → SHIPMENT_CREATED → AWB_ASSIGNED → SHIPPED → SYNCED
```

**SERIALS_ALLOCATED must precede INVOICE_ISSUED.**

Invoice layer (P3) must receive `HardwareFulfilmentWorkflowService::allocatedSerialNumbers()`.

| Count | Later PDF |
|-------|-----------|
| 1–5 | inline |
| >5 | “Serial Numbers: See Annexure A” in the **same** invoice PDF |

100–200 serial rows remain supported on `hardware_fulfilment_serials`. P2 does not allocate.

---

## 2. Cashfree correlation model

New additive table `hardware_fulfilment_payment_evidence`:

- Unique `cashfree_payment_id` (one evidence row per Cashfree payment).
- Many evidence rows may share one `source_id` / one fulfilment.
- `verified=true` only when `payment_status=SUCCESS` and a payment id is present.
- Missing payment id → fail closed; nothing is invented.
- Unknown/PENDING/FAILED → row may persist with `verified=false`; fulfilment is **not** marked paid and **not** moved to READY.

PAID recognition (`paid_recognized_at`) is **not** READY and is **not** fulfilled.

First verified payment fills empty `hardware_fulfilments.cashfree_payment_id`. Later distinct payment ids attach as extra evidence and do **not** create a second fulfilment or overwrite the first payment id.

Payment-first (no commerce yet): evidence is stored by `source_id`. Later ingest `ensureIngested` attaches it.

`OrderPaid` listener `CorrelateHardwareCashfreePayment` exists but **`hardware_fulfilment.correlate_cashfree` defaults false**. Live Cashfree webhooks, heal, and the seven frozen orders do not write hardware evidence until that flag is explicitly enabled (not P2 / not P7 yet).

Existing Cashfree path is unchanged: no mint, no serial allocate, no shipment. Journal listener still the only finance side effect on new paid orders.

---

## 3. State-transition rules

`HardwareFulfilmentState::canTransitionTo()` is forward-only on the happy path.

| From | Allowed to |
|------|------------|
| ingested | ready_for_fulfilment, failed, retry_pending |
| ready_for_fulfilment | **serials_allocated only** (not invoice_issued) |
| serials_allocated | invoice_issued, failed, retry_pending |
| invoice_issued | shipment_created, … |
| synced | synced (callback retry), failed, retry_pending |
| failed | retry_pending |
| retry_pending | failed |

`HardwareFulfilmentWorkflowService::transition()` locks the row, rejects illegal edges, is idempotent on the same state, appends `hardware_fulfilment_events`, and never mints or allocates.

Gates (contracts for later phases; P2 does not perform the work):

- `assertCanAllocateSerials` — state must be READY_FOR_FULFILMENT
- `assertCanIssueInvoice` — state must be SERIALS_ALLOCATED
- `assertCanCreateShipment` — state must be INVOICE_ISSUED

Payment SUCCESS does not call any of these.

Outbox: **reuse later**. P2 does not write `outbox_events` because `OutboxProcessorService` fail-closes unknown `event_type`. Audit stays on `hardware_fulfilment_events`. P3+ should add `hardware.fulfilment.advance` with keys `hw:{id}:{step}` once a processor exists.

---

## 4. Frozen seven

`HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS` lists the seven pending `RDE*` orders. P2 will not open a fulfilment, write payment evidence, or transition them. Tests never ingest those ids.

---

## 5. Non-goals (not done)

Serial picker, stock moves, invoice mint/PDF/annexure, Shiprocket, Box callbacks, ingest enablement, production DB mutation, POS issuer change, service invoice change, second statutory writer.

---

## 6. Remaining dependencies

| Phase | Needs |
|-------|-------|
| P3 | `HardwareIssuer` + mint after serials; annexure-capable PDF; uses `allocatedSerialNumbers()` |
| P4 | Owner SKU map + Avinash picker writing allocated serials |
| P5 | Shiprocket + invoice/serial/pickup gates |
| P6 | Desk→Box HMAC callback |
| P7 | Observe ingest; consider `correlate_cashfree` only after freeze policy for the seven |
| P8 | New-order E2E, then seven one-by-one |

---

## 7. Classification

**VERIFIED:** P1 unique fulfilment identity; Desk `orders.order_id` / `cashfree_payment_id` unique; webhook retries create new log rows keyed by `cf_payment_id`; Cashfree does not mint; outbox unknown types throw; POS `requireForProductBranch` unchanged.

**OWNER-LOCKED:** statutory identity; serial-first SM; annexure rule; seven frozen; ingest off.

**INFERRED:** first verified `cf_payment_id` is the fulfilment display correlation; additional SUCCESS payments are evidence only.

**UNKNOWN:** whether Owner will later want 422 when Desk `orders` payment id mismatches ingest; live SKU map; pickup nicknames; IRN HTTP.
