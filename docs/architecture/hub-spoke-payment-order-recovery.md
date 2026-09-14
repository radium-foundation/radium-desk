# Hub / Spoke Payment, Order, and Recovery Architecture

**Canonical status:** Source-of-truth for Cursor agents working on Radium Desk payment, order, and spoke coordination.  
**Companion (RadiumBox spoke contract):** sibling repo `radiumbox.com` → `docs/architecture/desk-payment-recovery-contract.md` (verify branch/HEAD before use).  
**Prompt:** `RadiumDesk-P-07-09-162` (initial); policy supersession `RadiumDesk-P-07-09-163`.  
**Classification key:** **VERIFIED** = evidenced in this repository’s code or committed docs. **INFERRED** = consistent but not re-executed here. **UNKNOWN** = not established; do not assume.

---

## 1. Architecture goal

Radium Desk is the **central Hub/coordinator** for multi-channel order visibility, payment-event handling (where Cashfree webhooks are configured to reach Desk), downstream commerce ingest, statutory/finance workflows, and hardware fulfilment coordination.

**Spokes** (independent applications, subject to separate verification) include:

| Spoke | Typical role | Desk integration in this repo |
|-------|--------------|--------------------------------|
| **radiumbox.com** | Storefront + local order/payment/fulfilment | **VERIFIED** — lookup, channel ingest, RBP/RDE recovery (Phase 1) |
| **rdservice.in** | RD Service India | **VERIFIED** — order lookup spoke config; RIN hardware ingest contract |
| **rdservice.net** | RD Service .net | **VERIFIED** — documented channel-order design; no Box-style confirm-payment client in Desk |
| **radiumsign.com** | Radium Sign | **VERIFIED** — prefix routing in `BusinessOrderId`; no Desk confirm-payment client |
| **RDServiceOnline.in** | **UNKNOWN** — not inspected in this document |

Do **not** assume spokes share repositories, databases, credentials, infrastructure, payment implementations, or deployment paths.

---

## 2. Role boundaries

### Desk (Hub)

- Receives Cashfree webhooks where merchant configuration points to Desk (`POST /api/webhooks/cashfree`). **VERIFIED** — `routes/api.php`, `CashfreeWebhookProcessorService`.
- Owns Desk `orders`, `commerce_orders`, enrichment sync state, hardware fulfilment — in database `radium_desk`. **VERIFIED** — schema/docs in repo.
- Coordinates downstream processing when established contracts require it (e.g. channel ingest from Box handoff). **VERIFIED** — `POST /api/v1/channel-orders`.
- **Does not** replace a spoke’s local paid-state source of truth for that spoke’s own Cashfree session. For RadiumBox recovery, Desk triggers Box server-side Cashfree verification; Box remains authoritative for Box `payment_status`. **VERIFIED** — `docs/cashfree-box-desk-handoff-reliability-p-07-09-158.md`.

### Spoke

- Owns its local order, payment state, and fulfilment/handoff lifecycle. **VERIFIED** for RadiumBox — `PaidOrderFulfillmentService`, `DeskOutboxService`, `desk_order_handoffs`.
- Deployed and operated independently. **INFERRED** — separate repos and production paths documented per project.
- Must not be treated as a generic RadiumBox clone. **VERIFIED** — distinct codebases and contracts per spoke docs.

### Cashfree (external processor)

- External payment processor; merchant webhook routing is a **configuration** concern, not redefined by this document.
- Do not reroute Cashfree webhooks broadly based solely on the RBP94 incident. **VERIFIED** — provider action noted in `docs/cashfree-box-desk-handoff-reliability-p-07-09-158.md`.

---

## 3. RBP product / hardware policy (OWNER-VERIFIED)

### CURRENT / OWNER-VERIFIED

**All `RBP*` orders represent RadiumBox product orders and are therefore hardware/product orders in Radium Desk.**

Owner decision recorded in `RadiumDesk-P-07-09-163`. This is intentional policy, not an incidental side effect of the payment-recovery endpoint alone.

### SUPERSEDED (historical — do not apply)

The following ledger entries remain preserved for history but are **no longer current policy**:

| Prompt | Former policy | Status |
|--------|---------------|--------|
| `RadiumDesk-P-07-09-154` | “Hardware prefixes stay RDE/RIN only (no auto-hardware for RBP…)” | **SUPERSEDED** |
| `RadiumDesk-P-07-09-156` | “`RBP` cannot become hardware… Hardware remains RDE/RIN`” | **SUPERSEDED** |

### Scope of this policy (broader than recovery API)

Classifying `RBP*` as hardware/product affects Desk behaviour beyond `POST …/confirm-payment` recovery, including where implemented on the handoff-reliability branch:

- `BusinessOrderId` — `RBP` parsed as `owner=radiumbox.com`, `hardware=true`
- `HardwareFulfilmentEligibility` — RadiumBox hardware source detection includes `RBP`
- `config/operations.php` — default hardware order prefixes include `RBP`
- `SpokeOrderClient` — RadiumBox lookup eligibility includes `RBP`
- RadiumBox payment confirmation / handoff reconciliation — eligible for `RBP*` when Cashfree-verified at Desk

This policy does **not**:

- authorize generic Hub recovery for rdservice.in, rdservice.net, radiumsign.com, RDServiceOnline.in, or other spokes;
- make RadiumBox the only Desk spoke;
- replace normal RadiumBox payment-success return or Cashfree webhook paths;
- redesign Cashfree merchant webhook routing.

---

## 4. RBP94 forensic lesson (repository-evidenced facts)

**VERIFIED** from investigation prompts `RadiumDesk-P-07-09-157` and `docs/cashfree-box-desk-handoff-reliability-p-07-09-158.md`:

| Identifier | Value |
|------------|-------|
| Business order | `RBP94` |
| Desk order (db id) | `53615` |
| RadiumBox order (db id) | `318703` |
| Cashfree payment id | `6470331477` |
| Desk Cashfree webhook processed | yes (Desk paid support order) |
| Box local `payment_status` before recovery | unpaid / null |
| Box `desk_order_handoffs` before recovery | none |
| Desk `commerce_orders` before recovery | none |

**What RBP94 exposed**

- A **recovery gap**: Desk had a verified Cashfree payment while the originating RadiumBox order never completed the normal Box-side paid confirmation and handoff enqueue.
- Successful RadiumBox orders use an established path: browser return → Box payment success handling → `PaidOrderFulfillmentService::confirmByFetching()` (or equivalent gateway confirmation) → local Paid → outbox/handoff → Desk channel ingest. **VERIFIED** — control orders cited in investigation; Box `PaymentController` / `PaidOrderFulfillmentService` in sibling repo.
- RBP94 did **not** complete that normal RadiumBox payment-success return/confirmation lifecycle. **VERIFIED** — Box remained unpaid with no handoff.
- The implemented Phase 1 fix is a **recovery path**, not a replacement for the successful payment flow. **VERIFIED** — design in `RadiumBoxPaymentConfirmationService`, `ConfirmRadiumBoxPaymentOnOrderPaid`, `radiumbox:reconcile-handoff`.

**UNKNOWN / do not claim**

- The exact customer browser action (closed tab, network drop, etc.) unless separately verified in production logs.

---

## 5. Current RadiumBox recovery contract (Phase 1 — VERIFIED in this repo)

Scope: **radiumbox.com hardware** orders classified by `BusinessOrderId` as owner `radiumbox.com` with `hardware=true` (includes `RBP*`, `RDE*`). **VERIFIED** — `app/Support/BusinessOrderId.php`, `RadiumBoxPaymentConfirmationService::requiresBoxPaymentConfirmation()`.

### 5.1 Triggers

| Path | Mechanism | **Status** |
|------|-----------|------------|
| Immediate | `OrderPaid` event → `ConfirmRadiumBoxPaymentOnOrderPaid` listener | **VERIFIED** |
| Link-payment gap | `OrderPaid` dispatched after `linkPaymentToExistingOrder` commit | **VERIFIED** — `CashfreeWebhookProcessorService` |
| Scheduled / manual | `radiumbox:reconcile-handoff` (`--order-id=` supported) | **VERIFIED** — `ReconcileRadiumBoxHandoffCommand` |

Config gates: `radiumbox.payment_confirm.enabled`, `radiumbox.handoff_reconciliation.enabled`. **VERIFIED** — `config/radiumbox.php`.

### 5.2 Desk → Box request

| Field | Semantics |
|-------|-----------|
| HTTP | `POST {spoke_base}/api/integrations/v1/cashfree/confirm-payment` |
| Auth | Bearer token from `order_lookup.spokes.radiumbox_com.token` (falls back to `DESK_ORDER_API_TOKEN`) |
| Base URL | `order_lookup.spokes.radiumbox_com.base_url` or legacy `radiumbox.base_url` |
| Optional Host header | `order_lookup.spokes.radiumbox_com.host` (loopback hairpin pattern on KVM) **INFERRED** from `tools/config.sh` + client code |
| `gateway_order_id` | **Business order id** (`RBP94`), not Desk’s numeric Cashfree `gateway_order_id` from webhooks **VERIFIED** — `RadiumDesk-P-07-09-160` |
| `payment_id` | Desk `orders.cashfree_payment_id` when present (correlation hint; Box still verifies via Cashfree fetch) |
| `dry_run` | Optional; Box returns local order snapshot without mutating |

Implementation: `app/Services/RadiumBox/RadiumBoxPaymentConfirmationClient.php`.

### 5.3 Box-side confirmation (sibling repo)

Desk calls Box; Box runs **`PaidOrderFulfillmentService::confirmByFetching()`** — Cashfree server verify, amount/currency check, mark Paid, enqueue handoff. **VERIFIED** — companion doc and Box controller.

Response statuses used by Desk: `paid`, `already_paid`, `dry_run` (success); `not_found`, `not_paid` (retriable), `amount_mismatch`, connection errors. **VERIFIED** — client + tests `tests/Feature/RadiumBox/RadiumBoxPaymentConfirmationTest.php`.

### 5.4 Idempotency and replay

- Safe to retry Desk → Box confirm; Box returns `already_paid` when already Paid. **VERIFIED**
- Box handoff idempotency key pattern: `statutory:radiumbox_com:commerce_order:{source_id}`. **VERIFIED** — reliability doc
- Desk ingest idempotency: `Idempotency-Key` header on `POST /api/v1/channel-orders`. **VERIFIED** — channel ingest routes

### 5.5 Desk sync state (false-green prevention)

Desk `orders.radiumbox_sync_status` distinguishes enrichment vs handoff completion. **VERIFIED** — `RadiumBoxEnrichmentSyncStatus`, `RadiumBoxFulfilmentSyncGuard`.

Hardware must not reach `SYNCED` without a matching `commerce_orders` row. **VERIFIED** — tests in `RadiumBoxPaymentConfirmationTest`.

Column width: `RECONCILIATION_REQUIRED` requires ≥32 chars — migration `2026_09_14_150000_widen_radiumbox_sync_status_on_orders.php`. **VERIFIED**

### 5.6 Handoff → commerce progression

After Box marks Paid, existing Box **`DeskOutboxService`** enqueues **`desk_order_handoffs`**. Delivery to Desk uses existing HMAC ingest (not redesigned here). **VERIFIED** — Box docs `docs/cashfree-desk-confirm-payment-p-10-09-07.md`, Desk `POST /api/v1/channel-orders`.

Isolated delivery on Box: `desk:deliver-handoff {id}` (operational command; separate from Desk recovery trigger). **VERIFIED** — Box ledger entries P-07-09-09+.

### 5.7 Operational note: confirm timeout vs Box Cashfree fetch latency

**Classification:** operational retry/reconciliation concern — not a payment-architecture redesign.

Desk `radiumbox.payment_confirm.timeout_seconds` defaults to **15 seconds** (`config/radiumbox.php`). **VERIFIED**

Box recovery calls `PaidOrderFulfillmentService::confirmByFetching()`, which performs a live Cashfree fetch inside Box. That fetch can exceed Desk’s HTTP client timeout in some environments. **INFERRED** — architecture-gate review; not re-measured in this documentation prompt.

When Desk times out while Box is still verifying:

- Desk may mark `radiumbox_sync_status=RECONCILIATION_REQUIRED` and retry via `radiumbox:reconcile-handoff`;
- Box must not be treated as unpaid based on Desk timeout alone;
- do not manually mark Box Paid or insert handoffs.

Adjusting timeout values is an operational/deploy decision outside this architecture document.

---

## 6. Current vs future

### CURRENT / VERIFIED (Phase 1)

- RadiumBox-only Desk-triggered recovery via authenticated confirm-payment API.
- Desk reconciliation for paid hardware missing commerce (`radiumbox:reconcile-handoff`).
- Business-order-id as Box `gateway_order_id` for recovery.
- No universal multi-spoke confirm client in Desk beyond RadiumBox.

### INFERRED

- Production runs on Hostinger KVM `187.127.129.16` with Desk at `/var/www/radium-desk` and Box at `/var/www/radiumbox.com` (named-file overlay deploys; no git on production). **Source:** `tools/config.sh`, Box deploy ledger entries.

### FUTURE / TARGET (not implemented as generic Hub recovery)

- Unified Hub recovery orchestrator for rdservice.in, rdservice.net, radiumsign.com, RDServiceOnline.in.
- Shared payment ledger or cross-spoke DB coupling.
- Automatic generalization of RadiumBox confirm-payment to every spoke.

Each additional spoke requires **separate verification** and an explicit integration decision. **VERIFIED absence** for Desk→spoke confirm on rdservice.in/net — no matching client in this repo (see `config/order_lookup.php` spokes).

---

## 7. Non-goals / architecture boundaries

- Do **not** create direct cross-spoke database coupling.
- Do **not** assume every spoke uses the same payment/order implementation.
- Do **not** make Desk RadiumBox-only as a product — but do **not** copy RadiumBox recovery to other spokes without a verified contract.
- Do **not** replace a proven customer payment flow merely because a recovery path exists.
- Do **not** reroute Cashfree merchant webhooks broadly based solely on RBP94.
- Do **not** modify another project’s infrastructure without independent verification.
- Do **not** treat an **UNKNOWN** dependency as unused.
- Do **not** put secrets, tokens, or passwords in documentation or commits.

---

## 8. Future Cursor agent preflight

Any agent touching payment, order, or integration functionality **must**:

1. Read this document and the companion RadiumBox contract doc.
2. Read `docs/cursor-prompt-ledger.md` and use the next unused prompt ID for the task.
3. Verify repository path, branch, HEAD SHA, worktree cleanliness, and remote.
4. Before production: establish Project → Repo/HEAD → Branch → Production path → Server → Deploy mechanism → vhost → DB → DNS/Cloudflare → Integrations → Backup → Rollback. If any critical boundary is **UNKNOWN**: **STOP → INVESTIGATE → REPORT**.
5. Preserve project isolation and documented Hub/Spoke roles.
6. Preserve established payment source-of-truth and successful flows.
7. Verify identifiers and cross-project contracts before changing them.

If proposed implementation **conflicts** with this architecture:

**STOP → REPORT** with: conflicting requirement, current documented architecture, evidence, proposed change, decision required.

Do **not** silently choose a new architecture.

**Note:** Routine coding or test failures are not architecture conflicts — **VERIFY → FIX → RE-TEST**.

---

## 9. Production safety checklist (Desk)

| Item | Documented value | Class |
|------|------------------|-------|
| Repository | `radium-foundation/radium-desk` | **VERIFIED** |
| Production host | `187.127.129.16` | **VERIFIED** — `tools/config.sh` |
| Production app path | `/var/www/radium-desk` | **VERIFIED** — `tools/config.sh` |
| Production DB name | `radium_desk` | **VERIFIED** — multiple desk docs |
| Deploy mechanism | KVM rsync / named-file overlay (`tools/commands/deploy-kvm.sh` requires `main` + release gate) | **VERIFIED** |
| Public vhost | `desk.radiumbox.com` | **INFERRED** — docs |
| DNS/Cloudflare | **UNKNOWN** in this doc — verify before changes |
| Rollback | File backups under `/var/backups/radium-desk/` (overlay pattern) | **INFERRED** — recovery session docs |

---

## 10. Recovery principle (preferred architecture)

```
Verified payment success at Hub (where applicable)
  → Hub records/links payment on Desk order
  → Hub detects originating spoke order has not reached required paid/handoff state
  → Hub invokes spoke's verified server-side recovery/confirmation API
  → Spoke confirms payment using its existing Cashfree (or spoke) source-of-truth
  → Spoke marks local order Paid and runs existing outbox/handoff
  → Hub ingests channel-order handoff
  → Downstream commerce / fulfilment proceeds
```

This is a **recovery mechanism**, not permission to redesign the normal payment flow.

---

## 11. Architecture map

| Layer | RadiumBox (Phase 1) | Other spokes |
|-------|---------------------|--------------|
| **Hub** | Radium Desk | Radium Desk |
| **Spoke** | radiumbox.com | rdservice.in / .net / radiumsign.com / … |
| **Payment processor** | Cashfree | Cashfree (where configured) — **UNKNOWN** per spoke |
| **Local order owner** | Box `orders.ordercode` | Spoke-local — **VERIFIED** per repo boundaries |
| **Recovery responsibility (Phase 1)** | Desk triggers Box confirm-payment | **UNKNOWN** — no generic Desk client |

---

## 12. References (verified in this repository)

| Topic | Location |
|-------|----------|
| RBP94 reliability implementation | `docs/cashfree-box-desk-handoff-reliability-p-07-09-158.md` |
| RBP94 investigation | `docs/cursor-prompt-ledger.md` → `RadiumDesk-P-07-09-157` |
| Desk recovery service | `app/Services/RadiumBox/RadiumBoxPaymentConfirmationService.php` |
| Desk recovery client | `app/Services/RadiumBox/RadiumBoxPaymentConfirmationClient.php` |
| Reconcile command | `app/Console/Commands/ReconcileRadiumBoxHandoffCommand.php` |
| Business order routing | `app/Support/BusinessOrderId.php` |
| Spoke lookup config | `config/order_lookup.php` |
| Cashfree webhook | `app/Services/Cashfree/CashfreeWebhookProcessorService.php` |
| Channel ingest | `routes/api.php` → `POST /api/v1/channel-orders` |
| Central finance / hub vision | `docs/rd-central-finance-invoice-architecture.md` |
| RDService.net integration | `docs/rdservice-desk-order-api-integration.md` |
| Spoke shipping contract | `docs/desk-spoke-shipping-contract.md` (if present) |
| Tests | `tests/Feature/RadiumBox/RadiumBoxPaymentConfirmationTest.php` |
| Companion spoke contract | radiumbox.com → `docs/architecture/desk-payment-recovery-contract.md` |

---

## 13. Revision history

| Prompt | Date | Change |
|--------|------|--------|
| `RadiumDesk-P-07-09-162` | 2026-09-14 | Initial canonical Hub/Spoke payment/order/recovery source-of-truth |
| `RadiumDesk-P-07-09-163` | 2026-09-14 | Owner-verified RBP product/hardware policy; supersede P-07-09-154/156; operational timeout note; fix cross-repo links |
