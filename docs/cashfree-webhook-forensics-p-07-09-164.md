# Cashfree Webhook Forensics — Read-Only Production Investigation

**Prompt:** `RadiumDesk-P-07-09-164`  
**Date:** 2026-09-14  
**Type:** Read-only. No Cashfree config, code, DB writes, deploy, or payment mutation.

**Permanent memory:** `docs/architecture/cashfree-webhook-operational-memory.md`

---

## Scope

Establish current Cashfree merchant webhook routing and compare against RBP control orders **RBP90, RBP94, RBP98, RBP110, RBP120**.

---

## Methods

- Repository routes, controllers, `CaseFree::createOrder` notify URLs
- Committed docs (`P-06-09-03`, `P-06-09-04`, `P-07-09-157`, `P-07-09-158`)
- Read-only SSH to KVM `187.127.129.16` (`deskvps`) — `artisan tinker` SELECT only
- Public HTTP probes (unsigned POST; no secrets)

---

## Findings

### 1. Configured Cashfree endpoints

| Type | URL | Active config evidence |
|------|-----|------------------------|
| Account webhook | `https://desk.radiumbox.com/api/webhooks/cashfree` | `RadiumDesk-P-06-09-04` dashboard screenshots; Desk logs active |
| Per-order notify (Box) | `https://radiumbox.com/api/payments/cashfree/webhook` | `CaseFree.php`, live GET/POST probe (401 unsigned) |
| Account webhook (Box) | Not configured | Dashboard gate |

### 2. Production payment-event receivers

| Receiver | Receives `PAYMENT_SUCCESS_WEBHOOK`? | Evidence |
|----------|-------------------------------------|----------|
| **Radium Desk** | **Yes** (account webhook) | 69,232 logs; RBP* payloads with `order_id=RBP*` |
| **RadiumBox** | **No stored events** | `cashfree_webhook_events` count **0** (all time) |
| **Other** | N/A | Single merchant account webhook slot at Desk |

### 3. RBP order comparison (production)

| Order | Box Paid | Box WH events | Handoff | Desk WH | Desk commerce | Notes |
|-------|----------|---------------|---------|---------|---------------|-------|
| RBP90 | Yes | 0 | delivered id 180 | processed 68023 | CO 2217 validated | Normal ~2 min return path |
| RBP94 | Yes | 0 | delivered id 209 | processed 68179 | CO 2801 validated | Gap 12–14 Sep; recovered 14 Sep |
| RBP98 | Yes | 0 | delivered id 183 | processed 68194 | CO 2308 invoiced | Normal path |
| RBP110 | Yes | 0 | delivered id 192 | processed 68347 | CO 2389 invoiced | Normal path |
| RBP120 | Yes | 0 | delivered id 201 | processed 68684 | CO 2564 invoiced | Normal path |

### 4. Architecture intent

**VERIFIED current production pattern:**

```
Cashfree PAYMENT_SUCCESS
  ├─ account webhook ──► Radium Desk (always for merchant MID)
  └─ (per-order notify to Box — configured but 0 stored events)

RadiumBox Paid + handoff (success path):
  browser return ──► PaymentSuccess ──► confirmByFetching ──► handoff ──► Desk ingest

RadiumBox Paid + handoff (recovery path):
  Desk OrderPaid ──► confirm-payment API ──► confirmByFetching ──► handoff ──► Desk ingest
```

### 5. Duplicate webhook risk

- **Account-level duplicate (Desk + Box):** Not currently configured. Cashfree dashboard shows single account webhook to Desk.
- **Per-order notify + browser return:** Both call `confirmByFetching` — idempotent. Handoff uses idempotency keys. **Low duplicate-commerce risk** if notify begins working.

### 6. Is routing change required?

**No** — for current successful RBP traffic. Normal orders complete via browser return; Desk account webhook provides Hub visibility; RBP94 class gaps have deployed recovery.

**Optional future investigation (owner decision):** why per-order Box `notify_url` has zero stored events.

---

## Recommendation

**NO CHANGE REQUIRED** for Cashfree merchant account webhook routing.

Do **not** move account webhook to RadiumBox — would break Desk ingest for RDService and support-order flows verified since Jun 2026.

---

## Non-actions

| Action | Performed? |
|--------|------------|
| Cashfree configuration changed | NO |
| Webhook routing changed | NO |
| Application code changed | NO |
| Database changed | NO |
| RBP94 mutation | NO |
| Deploy / merge | NO |
| Secrets exposed | NO |
