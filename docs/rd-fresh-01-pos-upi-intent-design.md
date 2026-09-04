# RD-FRESH-01 — POS UPI intent + bank-verification implementation design

**Project:** Radium Desk  
**Ledger:** RadiumDesk-P-04-09-30  
**Date:** 2026-09-04  
**Baseline:** `v4.0.66` / `0392d549`  
**Status:** Implemented in RadiumDesk-P-04-09-31. Production bank/VPA setup and `pos.payments.verify` assignment remain a separate gate.

Cashfree is out of this path. A displayed QR is never payment confirmation.

---

## 1. Executive summary

UPI leaves the current “Complete sale now” path. Cash, Card, and Bank Transfer stay on `PosSaleService::completeSale` immediately.

UPI becomes:

1. Cashier builds the cart (`pos.sell`).
2. Cashier selects a **UPI-enabled** `finance_bank_accounts` row.
3. Desk persists a **`pos_payment_intents`** row (`pending`) with amount, receiving account, unique `tr`, sale idempotency key, and cart snapshot — **before** any QR is shown.
4. Desk builds a local `upi://pay?pa&pn&am&tr&cu=INR` string from configured VPA/payee + intent amount/`tr`.
5. Counter renders a QR from that **already persisted** URI.
6. Customer may pay the company’s bank. Desk does not poll or webhook.
7. A user with **`pos.payments.verify`** opens the intent, attests they checked the live bank, enters UTR, confirms.
8. One DB transaction: lock intent → mark `verified` → call existing `completeSale` once with `idempotency_key = intent.sale_idempotency_key` and `payment_reference = UTR` → set intent `completed` + `sale_id`.
9. Stock, internal receipt, and pooled `1100`/`4000` journal happen only inside that existing complete path.

No bank API. No screenshots. No second sales table. No `inventory_sales.status` of `unpaid`.

---

## 2. Current architecture constraints (VERIFIED)

| Constraint | Fact |
|------------|------|
| Sale statuses | `completed`, `cancelled`, `returned` only |
| Complete path | One transaction: sale + stock + receipt + journal |
| Payment fields | `payment_method`, nullable `payment_reference` (≤128, not unique) |
| Sale idempotency | Unique nullable `inventory_sales.idempotency_key` (80) |
| UPI today | Immediate tender label → Dr `1100` |
| Bank master | `finance_bank_accounts`: name, last_four, gl, is_active. **No VPA.** Production rows: **0** |
| QR / VPA / bank API | Absent |
| Cashfree | Webhooks → `orders`, not POS |
| Cancel/return | Stock restore + GL reverse, not UPI refund |
| Reservations | Existing serial hold: active / released / consumed; `completeSale(..., reservation)` |

**INFERRED:** Overloading `inventory_sales.status` with `pending` would either take stock too early or invent a sale that `completeSale` does not understand. A sibling intent table is safer.

---

## 3. Proposed data model

### 3.1 Reuse `finance_bank_accounts`

Master for “HDFC Mumbai / HDFC Delhi / IndusInd”. Do **not** create a second bank list.

Owner will add rows later (last four only in tickets). Do not invent numbers or VPAs here.

### 3.2 VPA/payee: related 1:1 profile (option B)

Do **not** put VPA on `finance_bank_accounts` itself. That table is also the expense/bank-ledger master. POS receive is a different use.

**`finance_bank_account_upi_profiles`** (1:1):

| Column | Type | Null | Purpose |
|--------|------|------|---------|
| `id` | bigint PK | no | |
| `bank_account_id` | FK `finance_bank_accounts` unique | no | Receiving account |
| `vpa` | string 128 | no | `pa` — owner-supplied later |
| `payee_name` | string 160 | no | `pn` — owner-supplied later |
| `is_enabled` | boolean default true | no | Hide from POS picker without disabling expense bank |
| timestamps | | | |

No full account number. VPA treated as sensitive in UI (show to `pos.sell` / verify only, never in public logs).

**Rejected A** (columns on bank): mixes expense master with UPI secrets.  
**Rejected C** (env/settings JSON): cannot do three accounts cleanly or FK from intents.

### 3.3 `pos_payment_intents` (required)

Minimum robust set:

| Column | Type | Null | Constraints |
|--------|------|------|-------------|
| `id` | bigint PK | no | |
| `public_ref` | string 32 | no | Unique human/search key, e.g. `UPI-20260904-000001` |
| `tr` | string 64 | no | Unique UPI `tr`; persisted **before** QR |
| `sale_idempotency_key` | string 80 | no | Unique; passed to `completeSale` |
| `status` | string 24 | no | See §4; index `(status, created_at)` |
| `branch_id` | FK branches | no | Selling branch |
| `receiving_bank_account_id` | FK bank accounts | no | |
| `upi_profile_id` | FK profiles | no | Snapshot of which VPA was used |
| `vpa_snapshot` | string 128 | no | Frozen at create (profile may change later) |
| `payee_name_snapshot` | string 160 | no | Frozen `pn` |
| `amount` | decimal(12,2) | no | Authoritative total to collect |
| `currency` | char 3 | no | `INR` |
| `upi_uri` | string 512 | no | Exact URI encoded in the QR |
| `cart_payload` | json | no | Branch, customer, lines, discounts, notes — enough to call `completeSale` |
| `customer_phone` | string 20 | no | Indexed for orphan search |
| `customer_name` | string 160 | no | Search |
| `reservation_id` | FK reservations | yes | Serial hold if used |
| `created_by` | FK users | no | Cashier |
| `utr` | string 64 | yes | Set on verify; unique where not null (**verified UPI only** via unique) |
| `verified_by` | FK users | yes | |
| `verified_at` | timestamp | yes | |
| `bank_checked_at` | timestamp | yes | Explicit “I checked the live bank” |
| `sale_id` | FK `inventory_sales` | yes | Unique where not null |
| `expires_at` | timestamp | yes | Owner duration |
| `abandoned_at` | timestamp | yes | |
| `abandon_reason` | string 500 | yes | |
| timestamps | | | |

Indexes: unique `tr`, unique `sale_idempotency_key`, unique `public_ref`, unique `utr` (nullable), unique `sale_id` (nullable), `(receiving_bank_account_id, created_at)`, `(customer_phone, created_at)`, `(branch_id, status)`.

**How one intent becomes one sale**

- `sale_id` starts null. `completeSale` is called only from `PosUpiVerificationService::confirm`.
- `idempotency_key` = `sale_idempotency_key` (already unique on sales).
- After success, `sale_id` set; unique prevents two sales on one intent.
- Retry after timeout: lock intent; if `completed` and `sale_id` set, return that sale (do not call complete again unless using the same idempotency key, which returns the existing sale — **VERIFIED** current behaviour).

Do **not** add unpaid rows to `inventory_sales`.

### 3.4 `inventory_sales` (additive, optional)

Keep `payment_reference`. On UPI complete, write UTR there (backward compatible).

Optional later (same migration or follow-up): nullable `receiving_bank_account_id`, nullable `upi_intent_id`. Not required if Finance can join `pos_payment_intents.sale_id`. **Recommend** nullable `upi_intent_id` unique on sales for receipt/joins.

Do not rename or drop `payment_reference`. Existing cancelled POS-000001 keeps its Cash reference.

### 3.5 Serial hold (recommended, uses existing engine)

If the cart has serialized lines: `reserveSerials` at intent create; store `reservation_id`; `completeSale(..., reservation)` on verify; `releaseReservation` on abandon/expiry.

If reserve fails, do not create a payable QR.

Quantity-only lines: no hold in v1 (**INFERRED** gap vs serials).

---

## 4. UPI state machine

**Intent status (SoT for unpaid UPI):**

| Status | Meaning | Stock | Sale row | Journal |
|--------|---------|-------|----------|---------|
| `pending` | QR may be shown; not paid in Desk | reserved serials only | none | none |
| `verified` | In-transaction only, immediately before/during complete | transitioning | being created | being posted |
| `completed` | `completeSale` succeeded | sold | `completed` | `pos_sale` posted |
| `abandoned` | Expired or staff abandon; no verify | reservation released | none | none |
| `cancelled` | Staff cancelled unpaid intent (same stock outcome as abandon) | released | none | none |

Terminal: `completed`, `abandoned`, `cancelled`.  
`verified` must not persist across requests if complete failed: roll back to `pending` in the same transaction (**INFERRED** implementation rule).

**`inventory_sales.status` unchanged:** only `completed` / `cancelled` / `returned` after a sale exists.

```
pending --verify+completeSale--> completed
pending --abandon/expire------> abandoned
pending --cancel unpaid-------> cancelled
completed --existing pos.cancel--> sale cancelled; intent stays completed (do not rewrite history)
```

A completed intent is not “un-completed.” Desk cancel is a **sale** cancel.

---

## 5. QR architecture

**URI (server-built, persisted on the intent):**

`upi://pay?pa={vpa}&pn={urlencode(payee)}&am={amount 2dp}&tr={tr}&cu=INR`

- `pa` / `pn`: from profile snapshots on the intent.  
- `am`: intent `amount` = the same total `completeSale` will compute from `cart_payload` (compute once at intent create; reject verify if a recomputed total differs).  
- `tr`: unique, persisted first.  
- Optional `tn`: short `public_ref` only if length-safe — **INFERRED**, omit in v1 if URI length is a risk.

**UPI-app honouring of `am`/`tr`:** **UNKNOWN**. Treat QR as an instruction. Confirmation is still bank+UTR.

**Renderer:** persist `upi_uri` on the server. **Browser-side QR** from that string on the pending screen (e.g. a small MIT/Apache QR JS library chosen at implement time).  

- Server is SoT for the URI (session death does not invent a new `tr`).  
- Avoid PHP image/GD packages unless printing a PNG later.  
- Do not install anything in this ticket.

**Cashfree:** not used.

Regenerating a QR for the **same** pending intent must reuse the same `upi_uri` / `tr`. Never mint a second `tr` for one intent.

---

## 6. Authorization

New permission: **`pos.payments.verify`**. Seed on no role until the owner assigns. Do not assign Avinash / Sushant / Shipra here.

| Action | Permission |
|--------|------------|
| Build cart, choose UPI, choose receiving account, create intent, display QR | `pos.sell` + branch scope |
| Re-open own/branch pending intent, reprint same QR | `pos.sell` + branch scope |
| Enter UTR, attest bank checked, confirm | **`pos.payments.verify`** + branch scope |
| Abandon / cancel **unpaid** intent | `pos.sell` (own/branch) **or** `pos.payments.verify` **or** `pos.cancel` |
| Cancel / return **completed** sale | existing `pos.cancel` |

Hardware may create QR and must not verify unless the owner grants `pos.payments.verify`.

Finance `finance.view` is **not** used as the verify gate.

---

## 7. Verification UX (not implemented)

**Pending screen (cashier):** amount, receiving bank **name + last four**, `public_ref`, `tr`, QR, customer name/phone, “Waiting for bank verification — QR is not proof of payment.” Link: “Open in verify queue.”

**Verify action (authorized):**

- Same facts as above.  
- UTR field (required, trimmed, case-folded).  
- Checkbox required: “I checked the live bank account for this credit.” Sets `bank_checked_at`.  
- Confirm. No screenshot upload.

**Queue / orphan search** (`pos.payments.verify`): filters `public_ref`, `tr`, UTR, amount, receiving account, date, customer phone/name, status `pending`.

If customer has not paid: leave `pending` or abandon. Do not complete.

Receipt after complete: internal `INV-…`, method UPI, receiving bank name + last four, UTR, `public_ref`. Not a GST invoice.

---

## 8. Finance

Unchanged posting:

- UPI complete → Dr `1100` / Cr `4000` (non-`cash`).  
- `receiving_bank_account_id` on the intent (and optional sale FK) is the **operational** which-bank answer.  
- Do not add `1101`/`1102`/`1103` in v1.

Cancel/return of a completed UPI sale: existing reversing journal. UI warning: **“This does not send money back on UPI. Refund the customer in the bank if required.”**

---

## 9. Failure / orphan handling

| Event | Design |
|-------|--------|
| Scan, no pay | Stay `pending` until expiry → `abandoned` + release reservation |
| Wrong amount in bank | **Do not complete.** Owner rule: retry / abandon / adjust cart as a **new** intent. No silent under/over. |
| Cannot verify | Stay `pending`; money is an orphan bank credit |
| UTR missing | Validation fail |
| Duplicate UTR | Unique on `utr`; reject |
| Browser die after pay | Intent already in DB; search queue |
| Cancel after verified complete | Sale cancel/return only; intent remains `completed` |
| Paid, never verified | Manual find by amount/account/time/`tr`; then verify |

No automatic bank reconciliation. No screenshots.

**Expiry duration:** OWNER (suggest 60 minutes unpaid, not coded as policy).

---

## 10. Concurrency / idempotency

| Risk | Protection |
|------|------------|
| Double-click Verify | `lockForUpdate` on intent; if `completed`, return existing sale |
| Two verifiers | Same row lock; second sees `completed` or `verified-in-flight` |
| HTTP timeout after commit | Client retry with same intent id; idempotent complete via `sale_idempotency_key` |
| Duplicate UTR | Unique `utr` |
| Two sales | Unique `sale_id` + unique sale idempotency key |
| Verify after abandon | Status must be `pending` else reject |
| Cancel unpaid vs verify | Lock; one wins |
| CompleteSale finance fail | Transaction rolls back; intent stays `pending`; stock reservation remains |

UI: once-submit on verify (same pattern as current cancel forms).

---

## 11. Migration plan (not executed)

**Migration 1 — `finance_bank_account_upi_profiles`**  
Additive. Empty. `down()` drops table.

**Migration 2 — `pos_payment_intents`**  
Additive FKs as in §3.3. Empty. `down()` drops table.

**Migration 3 — optional `inventory_sales.upi_intent_id`** nullable unique FK. Existing sales (including cancelled POS-000001) stay null. `down()` drops column.

**Seeder:** `pos.payments.verify` permission **created**, **granted to no role**.

**Not in these migrations:** creating HDFC/IndusInd rows or VPAs.

Rollback: reverse migrations only if no completed intents exist; otherwise do not drop in production without a data plan.

---

## 12. Test plan (later; not written now)

- URI: `pa`/`pn`/`am`/`tr`/`cu` from snapshots; amount matches cart total.  
- `tr` and `sale_idempotency_key` unique; QR regenerate does not change `tr`.  
- Receiving account without enabled profile rejected.  
- `pos.sell` can create intent; cannot verify.  
- `pos.payments.verify` required for confirm; other users 403.  
- UTR required; duplicate UTR rejected.  
- Verify + complete: one sale, one journal `1100`/`4000`, serials sold, reservation consumed.  
- Second verify: same `sale_id`, no second journal (idempotent).  
- Abandon: reservation released, no sale.  
- Recover by `public_ref` after new session.  
- Complete after abandon rejected.  
- Cancel completed UPI sale: stock back, reverse journal, no Cashfree/UPI API.  
- Cash / Card / Bank Transfer HTTP still immediate `completeSale` (no intent).  
- POS never calls Cashfree clients.  
- Amount recompute mismatch blocks verify.

---

## 13. Owner decisions still required

1. Exact VPA and payee name per HDFC Mumbai / HDFC Delhi / IndusInd.  
2. Last-four (and legal names) when creating `finance_bank_accounts` rows.  
3. Who receives `pos.payments.verify` (not assumed Shipra / hardware).  
4. Amount mismatch: always block vs allow a new intent only.  
5. Duplicate UTR scope (design default: all verified UPI UTRs).  
6. Pooled `1100` vs later split GLs (design default: pooled).  
7. Intent expiry minutes.  
8. Who hunts orphan credits in the bank.  
9. Who refunds UPI after Desk cancel/return.  
10. Whether cashiers may abandon unpaid intents.

---

## 14. Risks

- UPI apps may ignore `am`/`tr` (**UNKNOWN**) → wrong-amount orphans.  
- Quantity SKUs not reserved in v1.  
- VPA leakage in logs if implementers dump `upi_uri`.  
- Hardware + verify on the same person weakens control if owner grants both.  
- Empty `finance_bank_accounts` blocks go-live until owner data exists.  
- Treating QR display as paid would violate this design.

---

## 15. Next ticket sequence (implementation, after owner review)

1. Additive migrations + `pos.payments.verify` (unassigned).  
2. Domain: enum, models, `PosUpiIntentService` (create/abandon), `PosUpiVerificationService` (confirm → `completeSale`).  
3. URI builder (no Cashfree).  
4. Counter: UPI → account picker → pending + QR from persisted URI.  
5. Verify queue + confirm form (checkbox + UTR).  
6. Receipt: bank last-four + UTR.  
7. Warning on cancel/return of UPI sales.  
8. Tests in §12.  
9. **Stop** before production VPAs/bank rows unless the owner supplies them in that ticket.

Do not implement Cashfree collect. Do not call banks. Do not store screenshots.

---

## Verdict

**IMPLEMENTATION DESIGN COMPLETE — READY FOR OWNER REVIEW**
