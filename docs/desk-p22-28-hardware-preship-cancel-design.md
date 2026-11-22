# P-22-28 — Hardware paid-order pre-shipment cancellation (Phase 2) — design & threat model

**Status:** Owner review draft (documentation only)  
**Scope:** Hardware storefront order families **RBP**, **RDE**, **RDP** on `desk.radiumbox.com`  
**Phase boundary:** **Phase 2** ends strictly **before AWB assignment / carrier shipment mutation**. **Phase 3** (Shiprocket `cancelOrders`, post-pickup) is out of scope for implementation authorized by this document.  
**Baseline code:** `origin/main` @ **`9a7c004f68d02e6c04902f199258e95f08935c3b`** (production **v4.1.112** known-good per P-22-26)  
**Evidence task:** P-22-27 gap audit; P-22-28 design (this file)  
**Cursor ledger ID:** `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef`

---

## 1. Executive recommendation

Implement hardware pre-shipment paid-order cancellation as a **durable, staged workflow** anchored on a new **`PreShipmentCancellationRequest`** aggregate (or equivalent), separate from post-shipment **ReturnExchangeCase** flows.

**Recommended orchestration:** **Durable staged workflow + outbox** (pattern already used for `RefundStatutoryAdjustment`, HF events, batch follow-up). A single giant DB transaction is **unsafe** because statutory IRN/CANCEL and refund execution involve external gateways and must support partial progress with explicit recovery states.

**Phase 2 deliverable:** Agent submit → Finance review → fulfilment stop → (optional) serial release → statutory outcome → refund allocation → refund execution, with **zero** live Shiprocket writes.

**Do not implement** until Owner approves: (1) split-tender refund policy for automatic vs manual legs, (2) statutory ordering (cancel before refund vs refund before cancel for hardware), (3) spoke metadata contract for cancellation markers, (4) whether to port or rewrite historical **p1711** WIP.

---

## 2. Verified current-state architecture (`main`)

| Area | Finding | Evidence status |
|------|---------|-----------------|
| Paid hardware ingest | `ChannelIngestService` → `HardwareFulfilmentFoundationService::ensureIngested` | **VERIFIED** — `app/Services/HardwareFulfilment/HardwareFulfilmentFoundationService.php` |
| Cancelled ingest metadata | `HardwareFulfilmentEligibility::metadataShowsCancelled` / `commerceOrderVoidsFulfilment` blocks **new** HF from ingest | **VERIFIED** — does **not** set durable Desk cancellation authority |
| Backfill / gap recovery | `ensureIngestedFromExistingCommerce`, `ensureIngestedFromExistingCommerceForRbpGapRecovery` | **VERIFIED** — **RBP2138** Owner recovery (P-14-59); **RBP2143** in P-17-43 allowlist pattern |
| Refund gate | `HardwareOrderRefundCaseGate` + `BYPASS_PRE_SHIPMENT_*` constants | **VERIFIED** — **no production caller** uses bypass tokens on `main` |
| Post-shipment returns | `ReturnExchangeCase*` + online refund submit | **VERIFIED** — merged; tax evidence before refund |
| Statutory cancel | `StatutoryInvoiceCancellationOrchestrator` | **VERIFIED** |
| Post-refund statutory (service path) | `RefundStatutoryAdjustmentService` on `RefundCompleted` | **VERIFIED** — **FLAG OFF** in production (see §8) |
| Split-tender lesson **RBP858** | `orders.payment_amount` ≠ full paid total | **VERIFIED** — ledger P-17-3, P-12-10-122 |
| Historical HF cancel pattern | `HardwareHistoricalDuplicateFulfilmentCancellationService` | **VERIFIED** — serial release + orchestrator; **no Shiprocket** |
| Shiprocket cancel API | `HttpShiprocketGateway::cancelOrders` | **VERIFIED** — **no app caller** on `main` |

**Production flags (read-only, 2026-11-22 IST):** `REFUNDS_STATUTORY_ADJUSTMENT_ENABLED` **false**; `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_ENABLED` **true**; e-invoice provider **whitebooks** (cached config); `WhitebooksEInvoiceGateway` bound.

---

## 3. Four pre-shipment states (Phase 2 matrix)

States are **mutually exclusive** entry classifications. Do not collapse to “HF exists”.

### State A — Paid order, **no** `hardware_fulfilments` row

| Item | Detail |
|------|--------|
| Examples | **RBP2138** / **RBP2143** class: paid commerce, HF missing until ingest/backfill/gap monitor (**VERIFIED** pattern — P-14-59, P-17-43 docs/tests) |
| Current prevention | Ingest: `commerceOrderVoidsFulfilment` if metadata cancelled (**VERIFIED**). Gap recovery: skips voided commerce (**VERIFIED** `RbpHardwareFulfilmentGapRecoveryService`) |
| **Gap** | **No authoritative Desk cancellation record** that blocks `ensureIngestedFromExistingCommerce` / gap recovery when **Desk** cancels but spoke metadata lags |
| Design requirement | Durable marker on `orders` and/or `commerce_orders` (e.g. `pre_shipment_cancellation_requests` terminal state, or `commerce_orders.cancellation_authority` JSON) checked **before any** HF create path |
| Idempotency | Unique `(order_id)` or `(commerce_order_id)` open-case constraint; ingest idempotency keys unchanged |
| Locking | `commerce_orders` row `lockForUpdate` during cancellation commit; gap monitor must read marker without race |

### State B — HF open, **before** serial allocation **or** statutory invoice

| Item | Detail |
|------|--------|
| HF states | Typically `Ingested` → `ReadyForFulfilment` (exact enum per `HardwareFulfilmentState`) |
| Service case | `HardwareOrderServiceCaseAssignmentCoordinator` — hardware routing deferred/unresolved audits (**VERIFIED**); v4.1.13 routing deploy (**VERIFIED** ledger P-04-10-133) |
| Design | Transition HF to **`CancelledPreShipment`** (new terminal state **proposed**) or reuse metadata + workflow with explicit enum; close/defer routed **ServiceCase** (Owner decision: auto-close vs leave audit trail) |
| Invoice in this state? | **VERIFIED** — hardware statutory generally **after** serial allocation (`requiresSerialAllocatedInvoice`); **UNKNOWN** for rare manual/issue paths — eligibility check must call `OrderStatutoryInvoiceResolver` |
| Shiprocket | **None** — Phase 2 |

### State C — Serials allocated **and** statutory invoice **Issued**

| Item | Detail |
|------|--------|
| Lineage | v4.0.81+ hardware invoice/PDF investigations (**VERIFIED** ledger P-18-09-*); serial allocation coupling (**INFERRED** from fulfilment workflow docs) |
| Reuse pattern | `HardwareHistoricalDuplicateFulfilmentCancellationService`: lock HF → release serials with **unique-claim detachment** → orchestrator cancel → terminal HF state (**VERIFIED**) |
| Statutory | Route through `StatutoryInvoiceCancellationOrchestrator` with case-specific idempotency prefix (e.g. `preship-cancel:invoice:{id}`) |
| B2B IRN &lt; 24h **CANCEL** | **LIVE-UNVERIFIED** for production WhiteBooks CANCEL in this specific Phase 2 journey — contract doc P-25-09-49 marks full NIC contract **UNKNOWN**; PHPUnit fakes **VERIFIED** only |
| B2B &gt; 24h **credit note GENERATE** | **VERIFIED** historical production evidence **P-25-09-57** (CN IRN on INV-0767138) for orchestrator **CN path** — **not** refund-triggered, **not** proof of end-to-end cancellation+refund |
| Ordering vs refund | **Owner decision required:** ReturnExchange path requires **recorded tax outcome before refund** (**VERIFIED** `ReturnExchangeCaseRecordedTaxOutcomeVerifier`). Pre-shipment cancel should **default to the same** unless CA approves refund-first for hardware |

### State D — AWB assigned / Ready for pickup, **before physical pickup**

| Item | Detail |
|------|--------|
| Phase boundary | **Phase 3 only** for carrier integration |
| Detection | **VERIFIED** pattern in p1711 WIP `PreShipmentFulfilmentShipmentVerifier`: `awb`, `shipment_id`, `shipped_at`, or HF states `AwbAssigned`+ → **reject** Phase 2 submit |
| Race | Request submitted while worker assigns AWB → verifier must **re-check under lock**; if AWB appears mid-flight → transition case to **Rejected — Phase 3** (no silent partial cancel) |
| Shiprocket | **Zero** live writes in Phase 2; local HF cancel **does not** cancel carrier shipment (document in ops runbook) |

---

## 4. Split-tender & Central Wallet (RBP858 lesson)

### Verified evidence (**RBP858** / **REF-67398**)

| Fact | Status |
|------|--------|
| Desk `orders.payment_amount` reflected **Cashfree leg only** (₹119) while Box order total ₹736 with wallet ₹617 | **VERIFIED** — ledger P-12-10-122 |
| Refund closed at ₹119; wallet portion not restored in that incident | **VERIFIED** |
| Root parse fix for wallet reference | **VERIFIED** — P-04-10-216 |

### Refundable amount rules (design)

1. **Never** use `orders.payment_amount` alone when `CommerceOrder` exists.
2. **Authoritative total:** `commerce_orders.order_value` and/or resolved **`OrderPaymentBreakdown`** (p1711 WIP — **not on `main`**).
3. **Components:** `wallet_tender_amount`, external/Cashfree captured amount, prior refunds (`RefundCalculationService::alreadyRefundedAmount`).
4. **Split-tender default (p1711 WIP policy):** mark `execution_hold` — Finance manual allocation (**VERIFIED** in `PreShipmentTenderRefundability` WIP).
5. **Tolerance:** reuse refund tolerance **0.01** (`RefundStatutoryAdjustmentEligibility` pattern).

### RELEASE vs Credit (**VERIFIED** concepts, **INFERRED** mapping to Box API)

| Concept | When | Desk authority |
|---------|------|----------------|
| **Reservation RELEASE** | Pre-commit: cancel checkout hold before money fully captured | Spoke/Box wallet API semantics — **UNKNOWN** exact Box endpoint in this doc; p1711 WIP references reservation flags in config |
| **Credit (post-commit)** | After payment settled; refund via `WalletRefundExecutor` / desk-first ledger | **VERIFIED** — Desk CW ledger credit then spoke audit when desk-first **on** |

**Design rule:** Pre-shipment cancel must record **per-leg allocation** (wallet vs OPM) before execution; duplicate callback protection via refund idempotency keys + `RefundRequest` status machine.

---

## 5. Ordered transition sequence (Phase 2)

Statuses below are **proposed**; align with p1711 WIP enums where reused.

| Step | Action | Authority / lock | Idempotency | Txn boundary | External effect |
|------|--------|------------------|-------------|----------------|-----------------|
| 0 | Eligibility + authorization | Policy + permissions | n/a | read | none |
| 1 | **Claim intent** | `pre_shipment_cancellation_requests` insert; `orders`/`commerce_orders` lock | unique open case per order | single txn | none |
| 2 | Shipment verify (Desk-only) | HF rows | n/a | read | **no Shiprocket** |
| 3 | Tender assess | breakdown resolver | n/a | read | none |
| 4 | **Fulfilment stop** | HF `lockForUpdate`; workflow transition | `psc:{ref}` | txn | none |
| 5 | **Ingestion guard** | persist cancellation marker readable by `ensureIngested*` | commerce id | txn | none |
| 6 | ServiceCase reconcile | case row lock | case id | txn | none — **Owner rules** |
| 7 | Serial release (C only) | serial + HF serial rows | per serial | txn | none |
| 8 | Statutory outcome (C) | orchestrator | `preship-cancel:invoice:{id}` | multi-phase orchestrator | WhiteBooks IRN/CANCEL/CN — **provider write** |
| 9 | Refund request | `RefundRequestService::create` + `BYPASS_PRE_SHIPMENT_CANCELLATION` | refund id / reference | txn | none |
| 10 | Finance allocation | allocation lines | line ids | txn | optional OPM conversion reservation (**flag off** default in p1711 config) |
| 11 | Refund execute | executors | execution reference | after commit events | Cashfree/spoke — **Phase 2 may remain manual for split** |
| 12 | Post-refund statutory | **only if** flag enabled + eligible | adjustment outbox | async | **currently OFF** — do not rely |
| 13 | Terminal + audit | status `Completed` / `Failed` | case ref | txn | notifications — best effort |

**Retry:** Each step reads durable status; retries must be safe from last completed step. **No silent success** if step 7–11 disagree (e.g. serials released but refund not created → `HeldExecution`).

**Orchestration choice:** **Staged workflow + outbox** (recommended) vs single coordinator — single txn cannot span IRN gateway and refund executor.

---

## 6. Locking & idempotency contract

| Resource | Lock order | Idempotency key |
|----------|------------|-----------------|
| `orders` | First on submit | `psc-submit:{order_id}:{actor_hash}` optional |
| `commerce_orders` | With order | marker version |
| `hardware_fulfilments` | Before workflow | HF id in case row |
| `inventory_serials` | Per serial during release | `psc-release:{hf_id}:{serial_id}` |
| `statutory_invoices` | orchestrator internal | `preship-cancel:invoice:{id}` |
| `refund_requests` | on create | standard refund keys + gate bypass token recorded |
| `pre_shipment_cancellation_requests` | case row | `reference_no` + unique open constraint |

Concurrent scenarios: duplicate agent submit → unique constraint (**VERIFIED** pattern in p1711 WIP); cancel vs serial allocation worker → HF row lock + state check fail-closed.

---

## 7. Threat & failure matrix (excerpt)

| Threat / failure | State | Impact | Prevention / detection | Recovery | Test |
|------------------|-------|--------|------------------------|----------|------|
| Delayed ingest after cancel | A | HF opens after refund intent | Cancellation marker on commerce; gap monitor check | Reconcile job closes orphan HF | `HardwareRbpFulfilmentRegressionTest`-style + new cancel marker test |
| Dual submit | all | duplicate cases | DB unique open case | return existing | feature HTTP test |
| Cancel vs allocate race | B/C | double spend serial | HF lock + state | abort cancel; alert | concurrency harness |
| Serial released, statutory fails | C | stock/tax mismatch | step status `HeldStatutory` | manual Finance; no refund execute | feature + fake gateway |
| Statutory ok, refund create fails | C | tax cancelled, customer paid | `HeldExecution`; block terminal | create refund manually | feature |
| Split-tender under-refund | all | customer loss | execution_hold + breakdown required | Finance allocation | unit `PreShipmentTenderRefundability` |
| RBP858-style amount | all | wrong refund | never `payment_amount` alone | recompute | unit + regression |
| Duplicate Cashfree callback | 11 | double payout | idempotent executor | revoke path | existing refund guard tests |
| Wallet RELEASE vs Credit race | 11 | double wallet movement | separate phases; desk-first ledger | CW revoke coordinator | integration fake |
| Partial prior refund | all | over-refund | `RefundCalculationService` caps | reduce requested | unit |
| Invoice already cancelled | C | duplicate orchestrator | orchestrator idempotent | continue refund only | statutory tests |
| AWB assigned mid-cancel | D→3 | carrier desync | verifier re-check | reject to Phase 3 | feature state machine |
| `REFUNDS_STATUTORY_ADJUSTMENT` off | 12 | tax not auto-adjusted | explicit manual cancel step | enable flag later | flag matrix test |
| Process crash between steps | all | partial | durable statuses | resume command | outbox pattern tests |

---

## 8. Feature-flag & statutory rollout

| Flag | Production (verified) | Phase 2 recommendation |
|------|----------------------|---------------------------|
| `REFUNDS_STATUTORY_ADJUSTMENT_ENABLED` | **false** | Keep **off** for Phase 2; hardware cancel uses **explicit orchestrator step** (like ReturnExchange tax evidence), not post-refund automation |
| `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_ENABLED` | **true** | Wallet leg must use desk-first path when executing wallet refunds |
| `PRE_SHIPMENT_*` (p1711 WIP) | absent on `main` | All default **false**; staged enable per runbook |

Statutory: distinguish **CANCEL** vs **CN** by `StatutoryInvoiceCancellationPolicyService` (**VERIFIED** on main). Do not treat P-25-09-57 CN success as refund-completion proof.

---

## 9. P-17-11 historical work — file-by-file assessment

### Git branch `feature/p17-11-preship-cancel` @ `905cdda76`

| Finding | Status |
|---------|--------|
| Branch is **ancestor of `main`** | **VERIFIED** |
| Unique commits vs `main` | **0** |
| Pre-shipment application code on that SHA | **None** — branch name is **misleading** |

### Worktree `/Users/ravi/RadiumWebsites/.worktrees/p1711-preship-cancel/desk`

| Finding | Status |
|---------|--------|
| Checked-out branch | `feature/p17-13-finance-allocation` (not p17-11) |
| HEAD | `905cdda76` |
| Pre-shipment WIP | **~40 files** under `PreShipmentCancellation*`, migrations, views, **5** feature tests — **uncommitted** |

### Reuse vs discard (vs `main`)

| Artifact | Verdict | Notes |
|----------|---------|-------|
| `PreShipmentCancellationRequest` model + migrations | **REUSE with rewrite** | Align FKs with current `main` schema; re-validate migration timestamps |
| `PreShipmentTenderRefundability` + `OrderPaymentBreakdown` | **REUSE concept** | Port `OrderPaymentBreakdownResolver` — **missing on `main`** |
| `PreShipmentFulfilmentShipmentVerifier` | **REUSE** | Matches Phase 2/3 boundary; extend for multi-HF edge cases |
| `PreShipmentCancellationRequestService` | **REUSE partial** | Add statutory + serial steps; wire gate bypasses |
| `PreShipmentAutomaticWalletCreditService` | **DISCARD auto path for v1?** | Owner: split-tender should stay manual; auto credit risky |
| `PreShipmentFinanceAllocationService` | **REUSE partial** | Overlaps ReturnExchange OPM patterns — consolidate to avoid duplication |
| Controllers / views | **REUSE UI skeleton** | Permissions need re-seed review |
| `config/pre_shipment_cancellation.php` | **REUSE** | Defaults false |
| Tests (5 files) | **REUSE** | Re-run on ported code |

**Do not merge p1711 tree blindly:** branch base is ancient; permissions seed and routes conflict with 900+ commits of `main`.

---

## 10. Regression test plan (no full suite run in P-22-28)

| Layer | Focus |
|-------|--------|
| Unit | Tender breakdown; refund cap; state machine transitions |
| Feature | Submit/approve; gate bypass; HF state guards; AWB boundary rejection |
| Integration | Fake WhiteBooks gateway; fake Shiprocket (assert **not called**) |
| Concurrency | HF ingest vs cancel marker; dual submit |
| DB integrity | FK cascades; no orphan serials |
| Journey | A→terminal without HF; B→cancel; C→serial+invoice+refund |

Existing suites to extend: `HardwareHistoricalDuplicateFulfilmentCancellationTest`, `HardwareOrderRefundCaseGateTest`, `RefundStatutoryAdjustmentTest` (flag off behavior).

---

## 11. Operations & monitoring

- Support queue: open `pre_shipment_cancellation_requests` in `Held*` states.
- Alerts: statutory manual review (orchestrator), refund `pending_execution` stuck, HF state mismatch vs case status.
- Explicit operator note: **cancelling fulfilment ≠ cancelling AWB** (Phase 2).

---

## 12. Owner decisions required (unresolved)

1. **Tax before refund** for hardware pre-ship (align with ReturnExchange?) — default **yes**.
2. **Split-tender:** manual allocation only vs automated wallet credit for wallet-only orders.
3. **ServiceCase** on cancel: auto-close vs leave open with audit.
4. **Authoritative cancellation marker** schema (commerce vs support order vs both).
5. **Port p1711 WIP** vs clean-room implementation on current `main`.
6. **Phase 3** priority for Shiprocket `cancelOrders` after Phase 2 stable.

---

## 13. Implementation prerequisites & rollout gates

- [ ] Owner sign-off on this design  
- [ ] CA/statutory sign-off on cancel-before-refund  
- [ ] Schema + migration review (MariaDB FK order per P-18-3 lesson)  
- [ ] PHPUnit focused cluster green  
- [ ] Tier A deploy; **no** `REFUNDS_STATUTORY_ADJUSTMENT` global enable without separate UAT (P-25-09-83)  
- [ ] Operator runbook + support training  

---

## 14. References

- P-22-27 audit (conversation report, not merged until P-22-28 ledger)  
- `docs/desk-statutory-invoice-cancellation-refund-lifecycle-p-23-09-47.md`  
- `docs/desk-refund-statutory-adjustment-controlled-uat-runbook-p-25-09-83.md`  
- `docs/p14-59-rbp2138-hardware-fulfilment-recovery-runbook.md`  
- `app/Services/HardwareFulfilment/HardwareHistoricalDuplicateFulfilmentCancellationService.php`  
- `app/Services/Refunds/HardwareOrderRefundCaseGate.php`  
- Ledger: P-17-3 (RBP858), P-25-09-57 (CN IRN), P-14-59 (RBP2138), P-22-25/26 production baseline  

---

*This document does not authorize implementation, deployment, or production mutation.*