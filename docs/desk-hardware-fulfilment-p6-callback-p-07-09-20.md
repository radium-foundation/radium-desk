# P6 hardware fulfilment callback (Desk → radiumbox.com)

**Project:** Radium Desk  
**Prompt:** **RadiumDesk-P-07-09-20**  
**Date:** 2026-09-07  
**Type:** Implementation. Desk sender + Fake gateway + transactional outbox. No live HTTP.  
**Prior:** P-07-09-12 … P-07-09-19.

Classification: **VERIFIED** / **OWNER-LOCKED** / **INFERRED** / **UNKNOWN**.

---

## 0. Repository verification (before modify)

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Branch | `main` (P1–P5 ahead of origin) | VERIFIED |
| Before SHA | `a139e6be305b40ca1fff7426eaa880b1c6ace77c` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Ledger next ID | `RadiumDesk-P-07-09-20` | VERIFIED |
| P5 Shiprocket | Null bound; live HTTP absent | VERIFIED |
| Seven frozen RDE* | `HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS` | VERIFIED |

---

## 1. Contract

Desk is authoritative after ingest. Synchronization uses HTTPS + HMAC. No shared database. No `radiumbox_prod` writes. This is **Desk → Box only**.

**Intended Box path (OWNER-LOCKED, P0):** `POST /api/desk/fulfilment-status`.

**Box receiver implementation in this repo:** **UNKNOWN**. This prompt implements the Desk sender, a versioned v1 payload, HMAC sign/verify, and a test-only apply projection. It does not invent Box apply/storage behavior.

Existing Box `POST /api/desk/invoice-status` is invoice-only (architecture audit). P6 does **not** jam serials/AWB into that invoice contract.

### v1 payload

| Field | When present |
|-------|----------------|
| `contract_version` | always `v1` |
| `event_id` | UUID minted once on first outbox insert |
| `event_type` | `hardware.fulfilment.{state}` |
| `channel` / `source_type` / `source_id` | hardware identity parts |
| `identity` | `statutory:radiumbox_com:commerce_order:{RDE*}` |
| `commerce_order_id` / `hardware_fulfilment_id` | Desk identities required by the contract |
| `state` / `sequence` / `occurred_at` | authoritative state; sequence = `hardware_fulfilment_events.id` |
| `invoice.number` / `invoice.issued_at` | only when a statutory invoice exists and state ≥ `invoice_issued` |
| `serials[]` | persisted allocated serials when state ≥ `serials_allocated` |
| `shipment.shipment_no` / `provider_shipment_id` | when state ≥ `shipment_created` and values exist |
| `shipment.awb` | only when a real AWB is persisted |

Omitted: secrets, payment credentials, filesystem PDF paths, public document URLs, invented courier/AWB values.

**PDF/document delivery:** **DEFERRED**. HMAC document GET already exists for other channels; P6 does not create unauthenticated document URLs.

Primary identity is `source_id` / `identity`. Invoice number and AWB are payloads, not keys.

---

## 2. Event / outbox

Reuse `outbox_events` (unique `idempotency_key`). No new table.

1. Authoritative fulfilment transaction writes `hardware_fulfilment_events` + `OutboxEvent`.
2. Commit.
3. `OutboxProcessorService` claims the row and the callback processor may send HTTP.

`idempotency_key` = `hardware:box-callback:{fulfilment_id}:{state}`.  
`firstOrCreate` so duplicate operator/job execution cannot mint a second event or a second `event_id`.

Callback-worthy states only: `SERIALS_ALLOCATED`, `INVOICE_ISSUED`, `SHIPMENT_CREATED`, `AWB_ASSIGNED`, `SHIPPED`.  
`SHIPPED` is enqueued only when Desk actually transitioned to `SHIPPED`. P5 does not auto-ship.

`SYNCED` means Box **acknowledged** the **SHIPPED** callback. It is not physical delivery. Earlier successful callbacks do not jump to `SYNCED`.

---

## 3. HMAC

Verified reuse of `ChannelIngestAuthenticator`:

- Headers: `X-Desk-Channel`, `X-Desk-Timestamp`, `X-Desk-Signature`
- Input: hex HMAC-SHA256 of `{unixTimestamp}{rawBody}`
- Compare: `hash_equals`
- Replay window: 300 seconds (stale → reject)
- Secret: `hardware_fulfilment.callback.secret` / `DESK_CALLBACK_SECRET` (env only; empty in production)

Malformed, unsigned, wrong channel, empty secret, invalid signature → reject. Stale timestamp → replay reject.

---

## 4. Delivery

`BoxFulfilmentCallbackGateway`:

- Production bind: `NullBoxFulfilmentCallbackGateway` (no HTTP)
- Tests: `FakeBoxFulfilmentCallbackGateway`
- Flag: `HARDWARE_FULFILMENT_CALLBACK_ENABLED=false`
- URL empty until Owner supplies it

Outcomes:

| Result | Outbox |
|--------|--------|
| disabled / empty URL / empty secret / Null | complete as skipped, no HTTP |
| HTTP 2xx / Fake accepted | complete; SHIPPED ACK may `SHIPPED → SYNCED` |
| 5xx / timeout / ambiguous | retryable; **same** `event_id` |
| 4xx | Failed immediately; not retried |

Timeout after possible Box accept retries the **same** event identity. A new event is never minted because delivery is unknown.

Box apply ordering is **UNKNOWN**. Payload includes `sequence`. `HardwareFulfilmentCallbackProjection` is a Desk test simulator: older sequence / lower rank cannot regress a newer applied display state. If Box cannot honour that, document it on the Box side rather than inventing a live receiver here.

Desk exposes **no** inbound fulfilment-status writer. Box cannot instruct Desk to invent invoice numbers, serials, AWBs, or fulfilment state.

---

## 5. Production safety

P6 does **not**:

- enable the sender
- populate production `DESK_CALLBACK_SECRET`
- enable Box ingest or Cashfree hardware correlation
- call radiumbox.com
- process the seven frozen RDE* orders
- touch `radiumbox_prod`
- deploy

---

## 6. Remaining dependencies

| Item | Status |
|------|--------|
| Box `POST /api/desk/fulfilment-status` apply | UNKNOWN |
| Production callback URL / secret | UNKNOWN / empty |
| Sender enable | OFF until P8 |
| P0-M1 SKU map / P0-M2 pickup | UNKNOWN |
| P7 observe ingest | later |
| P8 new-order then seven | later |

---

## 7. Classification

**VERIFIED:** outbox reuse; HMAC headers/canonicalization/`hash_equals`/300s window; Null bound; Fake used in tests; no HTTP inside the fulfilment transaction; `event_id` stable across retry; 4xx fail-closed; SHIPPED not emitted from shipment/AWB success; production flag/url/secret empty.

**OWNER-LOCKED:** Desk → Box HMAC only; identity `statutory:radiumbox_com:commerce_order:RDE*`; seven frozen; no shared DB; SYNCED after SHIPPED ACK only; sender off until P8.

**INFERRED:** intended production path remains `/api/desk/fulfilment-status` from P0 (Box code not in this repo).

**UNKNOWN:** live Box receiver, Box out-of-order apply, production URL/secret.
