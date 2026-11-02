# Statutory billing-link remediation — readiness and execution (P-02-46 / P-02-47)

**Prompt IDs:** P-02-46 (evidence), P-02-47 (execution package)  
**Problem class:** **MISSING BILLING LINK** — Desk statutory issued, Box `order_rdservice` paid at Cashfree, **no** Box `orders` row → C5 lookup `orders_id=null`, `payment_confirmed=false`.

**Preserved classification (do not edit):** `docs/c5-statutory-backlog-classification-p-02-40.md`  
**Cohort (P-02-40 / P-02-46 refresh):** **42** MISSING BILLING LINK (₹28,695); **1** excluded (**pub 48** / **17246** / **RB1382**); **8** delivered; **43** awaiting.

---

## Root cause (verified — RB1258 pilot)

| Layer | RB1258 evidence |
|--------|-----------------|
| Cashfree `RB1258` | **`order_status=PAID`**, **₹497** |
| Box `order_rdservice` **3512842** | `paid_amount=497`, **`status=Pending`**, **0** linked `orders` |
| Box `desk_order_handoffs` | **None** for `RB1258` |
| Desk commerce **17483** | `payment_reference=**6882034738**`, paid **497** |
| Desk invoice **16566** / **INV-6714835** | `issued`, pub **`awaiting_activation`** |

**Cause:** **`PaidOrderFulfillmentService`** / **`RadiumBoxRdCommerceOrderCreator::createOrGetPaidOrder`** never completed after payment; Desk mint used **`service_commerce`** without Box billing `orders.id`. C5 requires billing correlation (`DeskOrderLookupService`).

**Repair mechanism (canonical):** `POST /api/integrations/v1/cashfree/confirm-payment` with Cashfree re-verify — **not** manual `orders` INSERT.

Code references (RadiumBox `origin/main`):

- `app/Services/Payments/PaidOrderFulfillmentService.php` → `markPaid` → `createOrGetPaidOrder`
- `app/Services/Payments/RadiumBoxRdCommerceOrderCreator.php` — idempotent on `rdservice_order_id`
- `app/Services/Desk/DeskOutboxService.php` — handoff idempotency `statutory:radiumbox_com:commerce_order:{RB*}`

---

## Box backup and restore drill

### Pre-RIP backup

```bash
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
OUT="/var/backups/radiumbox-prod/radiumbox_prod-pre-statutory-remediation-${STAMP}.sql.gz"
mysqldump -h127.0.0.1 -u"$DBU" -p"$DBP" --single-transaction --routines --triggers "$DBN" | gzip -c > "$OUT"
gzip -t "$OUT" && sha256sum "$OUT" | tee "${OUT}.sha256"
```

Desk: `/var/backups/radium-desk/backup-run.sh` → confirm **`cloud_uploaded`**.

### Elevated restore drill (P-02-46 **PASS**)

```bash
DRILL="radiumbox_prod_drill_${STAMP}"
sudo mysql -e "CREATE DATABASE \`${DRILL}\` ..."
gunzip -c "$OUT" | sudo mysql "$DRILL"
# verify counts + sample RB1258 row
sudo mysql -e "DROP DATABASE \`${DRILL}\`;"
```

App DB user **cannot** CREATE drill DB (P-02-43); use **root/sudo** for drills only.

---

## RB1258 pilot execution checklist

**Pilot IDs:** **RB1258**, Desk inv **16566**, **INV-6714835**, **₹497**, Box RD **3512842**, Cashfree payment ref **6882034738**.

### Phase 0 — Stop conditions (NO-GO)

- [ ] Any existing Box `orders.rdservice_order_id=3512842`
- [ ] Finance has **not** confirmed **6882034738** / ₹497 on Cashfree reports
- [ ] Fresh Box gzip + Desk backup not completed
- [ ] Owner has **not** authorized **`confirm-payment`** replay (not SQL backfill)
- [ ] Attempt to publish C5 in same session (forbidden — repair only)

### Phase 1 — Pre-RIP read-only

- [ ] Cashfree fetch **`RB1258`**: `PAID`, amount **497**
- [ ] Desk invoice **16566** still `issued`, amount **497**
- [ ] Lookup **RB1258**: `orders_id=null`, `payment_confirmed=false`
- [ ] No Box receipt for **16566** / **RB1258** yet

### Phase 2 — Execute repair (Box, once)

```http
POST /api/integrations/v1/cashfree/confirm-payment
Authorization: Bearer <integration token>
Content-Type: application/json

{
  "gateway_order_id": "RB1258",
  "payment_id": "6882034738"
}
```

**Expected outcome:** `outcome=paid` or `already_paid`; **`first_paid`** documented.

**Side effects (Owner-approved):**

- New Box **`orders`** + **`order_details`** + **`order_history`**
- **`order_rdservice.status` → `Completed`**
- **Email + SMS** on first create (`sendNotifications=true` when no prior billing order)
- **`desk_order_handoffs`** row; Desk ingest should be **idempotent** on existing commerce **17483** (`statutory:radiumbox_com:commerce_order:RB1258`)

**Stop if:** `not_paid`, `amount_mismatch`, `not_found`, or duplicate conflicting `orders` rows.

### Phase 3 — Post-repair verification (read-only)

- [ ] Box `orders` linked to **3512842**, `payment_status=Paid`, total **497**
- [ ] Lookup **RB1258**: **`orders_id>0`**, **`payment_confirmed=true`**
- [ ] Billing total vs Desk **16566**: **Δ0 paise** (₹0.05 tolerance)
- [ ] Desk commerce **17483** not corrupted (read-only row compare)
- [ ] **Do not** enqueue statutory publication in this checklist

### Phase 4 — Separate C5 prompt

Publication remains gated: publisher allowlist, eligibility, Owner publish authorization.

---

## Per-invoice eligibility gate (remaining **41** after pilot)

Apply to each row in P-02-40 **MISSING BILLING LINK** table **except**:

| Exclude always | Reason |
|----------------|--------|
| **RB1382** / inv **17246** / pub **48** | Owner **INTENTIONALLY EXCLUDED / DEFERRED** |

**Per invoice gate (all must pass):**

1. Classification still **MISSING BILLING LINK** on fresh lookup (`orders_id` null).
2. Desk statutory **`issued`**; publication **`awaiting_activation`** (not delivered).
3. Cashfree fetch for gateway id **`{source_id}`** (usually **RB***): **`PAID`**, amount matches Desk `invoice_value` within **₹0.05**.
4. Desk `commerce_orders.payment_reference` present → use as `payment_id` when Cashfree aligns.
5. Box **`order_rdservice`** exists; **`orders`** count for `rdservice_order_id` = **0**.
6. No duplicate Box statutory receipt already stored for that invoice id.
7. Owner wave authorization (batch size **5–10** recommended after RB1258 pilot).

**Batch procedure:**

1. Fresh backup (Desk + Box) per wave.
2. For each approved id: **`confirm-payment`** only — **no** bulk SQL.
3. Stop wave on first hard failure; reconcile before continuing.
4. **Never** include **RB1382** until separate Owner disposition.

---

## Automated tests (regression)

| Suite | Role |
|--------|------|
| `tests/Feature/Integrations/CashfreePaymentConfirmTest.php` | confirm-payment API + handoff |
| `tests/Feature/CheckoutPaymentDeskTest.php` | outbox idempotency keys |
| `tests/Feature/RdServiceCashfreeNamespaceTest.php` | RD Cashfree + fulfillment outcomes |
| Desk `RadiumBoxStatutoryReceiptPublishAllowlistTest` | **16566/RB1258** blocked until eligible |

---

## Owner / finance decisions

1. Approve **`confirm-payment`** replay for historical PAID RD orders (not INSERT-only backfill).
2. Accept **RD Completed**, synthetic **order_history**, customer **notifications** on first create.
3. Separate approval for **C5 publication** after billing link repair.
4. **RB1382** exclusion until explicit reopen.

**NO — Not performed by this document:** production repair, publication, queue operations, config changes.
