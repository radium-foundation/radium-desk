# Cashfree Webhook Operational Memory (Radium Desk Hub)

**Canonical status:** Permanent operational memory for Cursor agents working on Cashfree routing, payment events, Hub/Spoke recovery, and production safety.  
**Companion (spoke):** sibling repo `radiumbox.com` → `docs/architecture/cashfree-webhook-operational-memory.md`  
**Hub architecture:** `docs/architecture/hub-spoke-payment-order-recovery.md`  
**Forensic report:** `docs/cashfree-webhook-forensics-p-07-09-164.md`  
**Prompt:** `RadiumDesk-P-07-09-164`  
**Classification key:** **VERIFIED** = evidenced in code, committed docs, or read-only production inspection this prompt. **INFERRED** = consistent but not re-executed here. **UNKNOWN** = not established; do not assume.

---

## CURRENT VERIFIED PRODUCTION ARCHITECTURE

### Cashfree routing (live, Sep 2026)

| Path | Endpoint | Receiver | Status |
|------|----------|----------|--------|
| **Merchant account webhook** (`success payment`) | `POST https://desk.radiumbox.com/api/webhooks/cashfree` | **Radium Desk** | **VERIFIED** — configured in Cashfree Merchant Dashboard (`RadiumDesk-P-06-09-04`, 2026-09-06). Desk `cashfree_webhook_logs` total **69,232**; **595** in last 24h; latest processed **2026-09-14 17:07:48 IST**. |
| **Per-order `notify_url` (Box checkout)** | `POST https://radiumbox.com/api/payments/cashfree/webhook` | **radiumbox.com** | **VERIFIED** in code (`CaseFree::createOrder` → `CashfreePublicUrl::to(...)`). Endpoint live (unsigned POST → **401**). **VERIFIED unused in production evidence:** `radiumbox_prod.cashfree_webhook_events` row count **0** for all orders including RBP/RDE/RBX. |
| **Merchant account webhook → RadiumBox** | — | — | **NOT configured** — dashboard gate `RadiumDesk-P-06-09-04` shows active success-payment webhook is Desk only. Domain Health may list historical Box URLs at **Severe 0%**; those are not the active account webhook. |
| **Old Admin** | — | — | **NOT configured** — same gate. |

Cashfree does **not** appear to deliver a second account-level `PAYMENT_SUCCESS_WEBHOOK` to RadiumBox. RadiumBox product payments (`RBP*`) reach Desk through the **single merchant account webhook**, with `data.order.order_id` equal to the Box business order code (e.g. `RBP90`). **VERIFIED** — production payload sample for RBP90 webhook log id `68023`.

### Normal successful RBP payment flow (production-evidenced)

For control orders **RBP90, RBP98, RBP110, RBP120** (read-only production, 2026-09-14):

1. Customer pays on Cashfree (Box session; `order_id` = business code `RBP*`).
2. **Cashfree account webhook → Desk** — `cashfree_webhook_logs` processed within seconds; Desk support order created/updated (`order_id=RBP*`).
3. **Browser return → RadiumBox** — `GET /orders/payment/online/success?order_id=RBP*` → `PaymentController::PaymentSuccess` → `PaidOrderFulfillmentService::confirmByFetching()` marks Box **Paid** (~30–90s after payment).
4. **Box outbox** — `desk_order_handoffs` enqueued and delivered to Desk `POST /api/v1/channel-orders`.
5. **Desk commerce** — `commerce_orders` row created (`source_id=RBP*`), `radiumbox_sync_status=SYNCED`.

**VERIFIED:** all four control orders have Box `payment_status=Paid`, delivered handoffs, Desk commerce rows, and **zero** Box `cashfree_webhook_events`. Successful flow does **not** depend on Box webhook delivery.

### RBP94 flow (recovery case; read-only, not mutated)

| Stage | Timestamp (IST) | State |
|-------|-----------------|-------|
| Box order created | 2026-09-12 17:08:07 | `RBP94` / id `318703` |
| Desk Cashfree webhook processed | 2026-09-12 17:11:10 | Desk order `53615`, cf payment `6470331477` |
| **Gap** | 2026-09-12 → 2026-09-14 | Box remained unpaid; no handoff (pre-recovery state documented in `RadiumDesk-P-07-09-157`) |
| Box marked Paid (recovery) | 2026-09-14 15:05:48 | `orders.updated_at` |
| Handoff created / delivered | 2026-09-14 15:07:19 / 15:10:42 | handoff id `209` **delivered** |
| Desk commerce | present | commerce id `2801`, `source_id=RBP94`, `radiumbox_sync_status=SYNCED` |

**VERIFIED:** RBP94 was recovered without mutating payment rows in this prompt. Recovery aligns with deployed Desk → Box `confirm-payment` path (`ConfirmRadiumBoxPaymentOnOrderPaid`, `payment_confirm_enabled=true`, `handoff_reconciliation_enabled=true` on production Desk).

### Recovery flow (additive; Phase 1)

When Desk has a verified Cashfree payment but Box has not reached Paid + handoff:

1. Desk `OrderPaid` → `POST {box}/api/integrations/v1/cashfree/confirm-payment` (business order id, not Desk numeric `gateway_order_id`).
2. Box `PaidOrderFulfillmentService::confirmByFetching()` — Cashfree server verify, mark Paid, enqueue handoff.
3. Box outbox → Desk channel ingest (existing HMAC).
4. Safety net: `radiumbox:reconcile-handoff`.

**VERIFIED deployed on production KVM** (`187.127.129.16`): Box `CashfreePaymentConfirmController` route present; Desk listener + service present. See `docs/architecture/hub-spoke-payment-order-recovery.md` §5.

### Hub / Spoke roles

| Role | Application |
|------|-------------|
| **Hub** | Radium Desk — account Cashfree webhook ingest, support orders, commerce/statutory/hardware coordination |
| **Spoke (RadiumBox)** | radiumbox.com — local order/payment truth via Cashfree fetch; handoff enqueue |
| **Other spokes** | rdservice.in, rdservice.net, radiumsign.com, RDServiceOnline.in — **independent** unless separately verified |

### RBP product / hardware policy

**OWNER-VERIFIED** (`RadiumDesk-P-07-09-163`): all `RBP*` orders are RadiumBox product/hardware orders in Desk. Supersedes `P-07-09-154` / `P-07-09-156`.

### Authentication contract (Desk ↔ Box recovery)

| Item | Value |
|------|-------|
| Endpoint | `POST /api/integrations/v1/cashfree/confirm-payment` |
| Auth | `Authorization: Bearer {DESK_ORDER_API_TOKEN}` |
| `gateway_order_id` | Box business order code (`RBP94`), **not** Desk numeric Cashfree id |

Never document token values.

### Deployment boundaries

| Project | Production path | DB | Host |
|---------|-----------------|-----|------|
| Radium Desk | `/var/www/radium-desk` | `radium_desk` | `187.127.129.16` |
| RadiumBox | `/var/www/radiumbox.com` | `radiumbox_prod` | same KVM |

Deploy: named-file rsync overlay (no git on server). Desk requires `main` + release gate (`tools/commands/deploy-kvm.sh`).

### Backup / rollback mechanism

| Type | Path / mechanism |
|------|------------------|
| **Desk DB + secrets** | `/var/backups/radium-desk/runs/<backup_id>/` — see `docs/backup-runbook.md` |
| **Desk deploy overlay (required)** | `/var/backups/radium-desk/overlays/<prompt-id>-<UTC-timestamp>/` — owner policy; VERIFY → BACKUP → VERIFY BACKUP → DEPLOY → VERIFY → ROLLBACK IF REQUIRED |
| **Historical Desk overlays** | Some prompts used `/var/www/radium-desk/storage/app/private/overlays/` — preserve if present; new Desk deploys use `/var/backups/radium-desk/overlays/` per owner policy |
| **Box deploy rollback** | `/var/backups/radiumbox-prod/` pre-overlay files (per Box ledger) |

Do not weaken backup permissions or use another project's backup directory.

### Known production paths

- Desk webhook: `routes/api.php` → `CashfreeWebhookController` → `CashfreeWebhookProcessorService`
- Box browser success: `PaymentController::PaymentSuccess` → `confirmByFetching`
- Box webhook (dormant): `routes/api.php` → `Payment/CashfreeWebhookController`
- Box recovery API: `Api/Integrations/CashfreePaymentConfirmController`
- Desk channel ingest: `POST /api/v1/channel-orders`

### Known safety constraints

- Do not reroute Cashfree account webhook based solely on RBP94.
- Do not enable duplicate account webhooks without owner decision.
- RBP94 recovery is additive, not a replacement for normal Box paid flow.
- Generic multi-spoke Hub recovery: **NOT IMPLEMENTED**.
- No cross-spoke DB coupling.
- No secrets in docs/commits.
- Routine test failures: FIX → RE-TEST (do not escalate to owner).

---

## FAILURE ISOLATION

### If Cashfree → Desk is temporarily unavailable

| Spoke / system | Impact |
|----------------|--------|
| **radiumbox.com (RBP/RDE checkout)** | **Partially isolated.** Browser return → Box `confirmByFetching` can still mark Paid and enqueue handoff if customer completes return URL. Desk will **not** receive account webhook payment events (no new support orders from webhook, no `OrderPaid`-driven Box recovery for Desk-initiated link-payment scenarios). |
| **rdservice.in** | **Per-order notify** to `https://rdservice.in/cashfree/notify` is separate from Desk account webhook. **INFERRED** largely isolated for spoke-local payment confirmation; Desk enrichment from account webhook may lag. **UNKNOWN** full blast radius without per-order forensic replay. |
| **rdservice.net** | Same pattern: per-order `https://rdservice.net/cashfree/notify`. |
| **radiumsign.com** | **UNKNOWN** — not inspected this prompt. |
| **RDServiceOnline.in** | **UNKNOWN** — not inspected. |

**Synchronous dependency:** Desk account webhook is **not** on the critical path for successful RadiumBox browser-return payments. It **is** on the critical path for Desk-side payment recording and Desk-triggered Box recovery.

### If Cashfree → Box (`notify_url`) is unavailable

| System | Impact |
|--------|--------|
| **Current production** | **No observed impact** — `cashfree_webhook_events` is empty; successful RBP/RDE orders complete via browser return. |
| **Desk** | Unaffected — uses account webhook to Desk, not Box. |
| **Duplicate risk if fixed** | If per-order notify begins working while browser return also runs, Box `confirmByFetching` is idempotent (`already_paid`) — **low duplicate-paid risk**; handoff idempotency keys prevent duplicate commerce. |

---

## OWNER DECISIONS (do not re-ask)

| Decision | Prompt / date | Summary |
|----------|---------------|---------|
| Desk is Hub/coordinator | `RadiumDesk-P-07-09-162` | Hub/spoke architecture |
| All `RBP*` = hardware/product in Desk | `RadiumDesk-P-07-09-163` | Supersedes P-07-09-154/156 |
| Cashfree account webhook → Desk | `RadiumDesk-P-06-09-04` | Merchant dashboard PASS |
| Box remains payment SoT via Cashfree fetch | `RadiumDesk-P-07-09-158` | Recovery does not bypass Box verify |
| RBP94 recovery additive only | `RadiumDesk-P-07-09-157`–`160` | Do not replace normal flow |
| Desk deploy overlay backup path | Owner policy (this prompt) | `/var/backups/radium-desk/overlays/<prompt-id>-<UTC-timestamp>/` |
| Deploy sequence | Owner policy | VERIFY → BACKUP → VERIFY BACKUP → DEPLOY → VERIFY → ROLLBACK IF REQUIRED |

---

## FUTURE / NOT IMPLEMENTED

- Generic Hub recovery for rdservice.in / .net / radiumsign.com / RDServiceOnline.in
- Cross-spoke shared payment ledger
- Replacing Box Cashfree fetch with Desk-asserted paid flags
- Automatic reroute of merchant account webhook to RadiumBox

---

## UNKNOWN / REQUIRES VERIFICATION

| Item | Notes |
|------|-------|
| Cashfree Merchant Dashboard **today** (2026-09-14) | Last direct dashboard verification: **2026-09-06** (`P-06-09-04`). Ongoing delivery **INFERRED** from Desk log volume. Re-verify before any routing change. |
| Why per-order `notify_url` → Box stores **zero** webhook events | Endpoint live; possible delivery failure, signature mismatch, or Cashfree not invoking per-order notify when account webhook is Desk. **OWNER DECISION** if investigation desired. |
| RDServiceOnline.in Cashfree routing | Not inspected |
| radiumsign.com Cashfree routing | Not inspected |
| Exact customer action for RBP94 gap | UNKNOWN |

---

## Future Cursor Agent Preflight — Do Not Re-Ask Established Decisions

Before touching payment, Cashfree, order sync, Desk/Spoke integrations, production deploy, rollback, DB migrations, or cross-project auth:

1. Read this document + `hub-spoke-payment-order-recovery.md` + Box companion operational memory.
2. Read `docs/cursor-prompt-ledger.md`; assign next unused prompt ID.
3. Verify repo, branch, HEAD, production path, DB, and integration boundaries.
4. If task matches documented architecture → **proceed without owner reconfirmation**.
5. If task needs new routing, destructive prod change, or conflicts with this doc → **STOP → REPORT → ASK**.

---

## Revision history

| Prompt | Date | Change |
|--------|------|--------|
| `RadiumDesk-P-07-09-164` | 2026-09-14 | Cashfree webhook forensics + permanent operational memory (read-only production evidence) |
