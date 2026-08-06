# SC28430 — Refund Completed (Wallet) vs Customer Wants Service

**Date:** 2026-08-06  
**Priority:** P0 production (read-only)  
**Status:** Facts proven · no code or production changes made  
**Timezone:** Asia/Kolkata (IST)  
**Canvas:** [`sc28430-refund-service-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/sc28430-refund-service-investigation.canvas.tsx)

**Wallet architecture (follow-up):** [`docs/finance-architecture-audit.md`](./finance-architecture-audit.md#rd-wallet-investigation-2026-08-06-sc28430) — RD Wallet is external (RadiumBox); Desk cannot reverse ₹617.

**Commercial block after wallet reverse:** see [§ Commercial block after external wallet reverse](#12-commercial-block-after-external-wallet-reverse) and [`docs/br-04-commercial-state.md`](./br-04-commercial-state.md#gap--service-restoration-after-external-wallet-reverse-2026-08-06).

**Constraints honoured:** no code changes · no production writes · no reopen · no second refund.

---

## Bottom line

Shipra **did** complete refund **REF-2026-000020** for order **RD3454444** on **19 Jul 2026, 11:41 PM**. Method was **wallet** (manual desk completion), **not** Cashfree bank/UPI reversal.

- Cashfree was **never** asked to refund this payment. There is **no** gateway refund ID, **no** refund webhook, **no** refund outbox.
- Money **did** land in the customer’s **RD Service wallet** (₹617). The customer confirmed this in email today.
- “Money has not reached me” means **bank account**, not wallet. Desk and Cashfree never promised a bank path for this refund.
- Customer now wants **service** instead. Commercial state on SC28430 / RD3454444 is **`refund_completed`** — paid service / Assign Ref are blocked.
- Safest action: **do not deliver service while wallet still holds ₹617**, and **do not refund again**. Force a single commercial choice: keep wallet (and optionally bank-withdraw wallet via finance ops) **or** reverse wallet credit then serve — never both.

---

## Identity

| Entity | Value |
|--------|-------|
| Investigated case | **SC28430** (`incidents.id` = 28501) |
| Case type | Missed Call Recovery (today) |
| Case status | `open` |
| Assignee now | **Avinash Jha** (user 2), manual reassign from Jayram |
| Order | **RD3454444** (`orders.id` = 13730) |
| Order status | `active` (not marked completed/cancelled) |
| Original service case | **SC13834** (`incidents.id` = 13928) — Cashfree intake; **closed** after refund |
| Customer | Suraj Kumar / Suraj Sharma · `7643082915` · `suraj7502492@gmail.com` |
| Payment | ₹617.00 UPI · Cashfree payment `6023342207` · bank ref `550331412195` |
| Refund | **REF-2026-000020** (`refund_requests.id` = 20) |
| Commercial state | **`refund_completed`** (blocks Assign Ref / paid service / paid appointment / charge) |

Related missed-call cases today (same phone/order): **SC28021** (closed), **SC28287** (closed), **SC28430** (open).

---

## 1. Complete timeline (IST)

| When | What | Who / system |
|------|------|----------------|
| **16 Jul 21:23** | UPI payment SUCCESS ₹617 — Cashfree `PAYMENT_SUCCESS_WEBHOOK` | Cashfree → Desk |
| **16 Jul 21:24** | Order RD3454444 + SC13834 created; payment automation | System (user 1) |
| **16 Jul 21:25** | RadiumBox enrichment; serial then `5411055`; model MIS 100 | System |
| **16 Jul 21:26** | SC13834 assigned to Shipra (shift admin) | System |
| **17 Jul morning** | Multiple answered-call attachments on SC13834 | System |
| **18 Jul 10:51** | Reassigned to Gaurav; remark “Review and close” | User 4 → Gaurav (7) |
| **18 Jul 12:52** | Refund **REF-2026-000020** requested — full ₹617, preferred **wallet**, reason “MFS100 DEVICE HAI” | Gaurav (7) |
| **18 Jul 12:53** | Remark: “RD3454444 - ADD IN WALLET RS- 617”; reassigned to Shipra | Gaurav |
| **18 Jul 13:26** | Shipra remark “Pls check”; reassigned to Jayram | Shipra → Jayram (5) |
| **19 Jul 23:41:10** | Refund **approved** → `pending_execution`, method **wallet** | Shipra (3) |
| **19 Jul 23:41:21** | Refund **completed** — provider **`manual`**, execution ref = REF-2026-000020, **no** gateway txn id | Shipra |
| **19 Jul 23:41:24** | Customer notify: email **sent**; WhatsApp **skipped** (template not configured); aggregate success true | System |
| **19 Jul 23:41:24–33** | SC13834 **closed**; refund **closed**; system remark refund close | Shipra / system |
| **~19 Jul 23:39** (per agent note) | Wallet credit claimed success — ref **#RD273105**, ₹617 | Ops note on SC28021 (Avinash, 06 Aug) |
| **06 Aug 13:15** | SC28021 missed-call recovery created → closed by Avinash with wallet-success note | System / Avinash |
| **06 Aug 15:41** | SC28287 missed-call recovery → closed “Refunded” | System / Avinash |
| **06 Aug 16:03** | Customer email: wallet has ₹617; wants **bank transfer** | Customer → support@ |
| **06 Aug 16:04** | Email intake: **ignored as spam** (Gmail SPAM label); not linked to any case | IRA / intake |
| **06 Aug 17:18** | **SC28430** created (missed call); assigned Jayram (support) | System |
| **06 Aug 17:57** | Jayram set serial **9868268**, model **MFS 110** on refunded order | Jayram |
| **06 Aug 17:58** | SC28430 reassigned to Avinash (“rd plz”) | Jayram → Avinash |
| **Now** | SC28430 open; commercial **Refund Completed**; customer wants service | — |

---

## 2. Refund record

| Field | Value |
|-------|-------|
| Refund Reference | **REF-2026-000020** |
| Gateway Refund ID | **None** (`execution_transaction_id` null, `refund_transaction_id` null) |
| Gateway Status | **N/A — gateway refund never initiated** |
| Desk Status | **`closed`** (terminal) |
| Refund Amount | **₹617.00** (full; no deductions) |
| Refund Method | **Wallet** (`customer_preferred_method` = wallet, `approved_refund_method` = wallet) |
| Execution provider | **`manual`** (`ManualRefundExecutor`) |
| Requested | 18 Jul 2026, 12:52 PM by **Gaurav Kumar** |
| Approved | 19 Jul 2026, 11:41:10 PM by **Shipra** |
| Completed | 19 Jul 2026, 11:41:21 PM by **Shipra** |
| Closed | 19 Jul 2026, 11:41:33 PM |
| Channels selected | email + whatsapp |
| Gateway response | **None** |
| Finance journal | **None** for `source_id=20` (journal path not posted for this early refund) |

Completion audit explicitly stores `"provider": "manual"`. Wallet path does **not** call Cashfree.

---

## 3. Cashfree

| Question | Answer |
|----------|--------|
| Was refund actually initiated on Cashfree? | **No** |
| Gateway accepted refund? | **N/A** |
| Gateway failed / pending? | **N/A** |
| Payment webhook received? | **Yes** — `PAYMENT_SUCCESS_WEBHOOK` only (log id 11971), processed |
| Refund webhook received? | **No** rows for this `cf_payment_id` |
| Refund webhook processed? | **N/A** |
| Outbox refund events? | **None** for REF-2026-000020 |
| Retry / errors on refund? | **None** (nothing to retry) |

Original payment facts (Cashfree):

- `cf_payment_id` = `6023342207`
- `gateway_order_id` = `6430267946`
- UPI id `7643082915-2@ybl`
- Amount ₹617, status SUCCESS

---

## 4. Customer payment / money location

| Question | Answer |
|----------|--------|
| Refund reached bank? | **No evidence — and no Cashfree refund was created** |
| Still pending at gateway? | **No** |
| Rejected / reversed at gateway? | **N/A** |
| Where is the money? | **RD Service wallet — ₹617** (customer-confirmed 06 Aug) |
| Desk belief | Refund **completed/closed** via wallet |
| Confidence | **High** that wallet credit exists; **high** that bank never received this refund |

Customer email (incoming message id **179340**, 06 Aug 16:03 IST):

> My RD Service payment was refunded to my RD Service wallet. My wallet balance is ₹617. I want this amount transferred back to my bank account.

That email was classified **spam** / status **ignored** and was **not** linked to SC28430.

---

## 5. Communications

### Emails

| Direction | When | Detail |
|-----------|------|--------|
| Outbound refund confirmation | 19 Jul 23:41 | Audit: email **sent successfully** (`refund_confirmation`); not found in `outgoing_email_messages` table (legacy/direct send path) |
| Inbound customer | 06 Aug 16:03 | Wallet ₹617 confirmed; asked for bank transfer — **ignored as spam** |

### WhatsApp

| When | Detail |
|------|--------|
| 19 Jul 23:41 | WhatsApp refund confirmation **Skipped — Template not configured** (`not_yet_configured`, counted success for aggregate) |
| Dispatches table | **No** `whatsapp_template_dispatches` rows for this phone/order |
| Interakt webhooks | **No** payload matches for `7643082915` |

### Internal notes / remarks

| When | Who | Note |
|------|-----|------|
| 18 Jul 10:51 | User 4 | “Review and close” |
| 18 Jul 12:53 | Gaurav | “RD3454444 - ADD IN WALLET RS- 617” |
| 18 Jul 13:26 | Shipra | “Pls check” |
| 19 Jul 23:41 | Shipra/system | “Service case closed after refund REF-2026-000020 was completed.” |
| 06 Aug 15:09 | Avinash | Wallet success note: Suraj Sharma, ₹617, ref **#RD273105**, credited to wallet |
| 06 Aug 16:04 | Avinash | SC28287 close: “Refunded” |
| 06 Aug 17:58 | Jayram | “rd plz” (reassign) |

### Appointments

None for these incidents / phone.

---

## 6. Current state

| Layer | State |
|-------|-------|
| **SC28430** | `open`, Missed Call Recovery, high priority, owner **Avinash Jha** |
| **SC13834** | `closed` (refund close) |
| **Order RD3454444** | `active`; serial **9868268**; model **MFS 110** (edited today by Jayram); product_name still “MIS 100” |
| **Refund** | `closed`, wallet, ₹617, completed by Shipra 19 Jul |
| **Payment (Cashfree)** | Original SUCCESS only; no refund object |
| **Wallet** | ₹617 credited (customer + ops note #RD273105) |
| **Appointment** | None |
| **Commercial** | `refund_completed` — blocked: Assign Service Reference, Paid Service, Paid Appointment, Charge Customer |
| **Ownership** | SC28430 → Avinash; order last updated by Jayram (serial/model) |

---

## 7. Decision analysis

| Question | Answer |
|----------|--------|
| Can the refund still be cancelled? | **Not in any meaningful gateway sense.** Desk status is already terminal (`closed`). There is no Cashfree refund to void. “Cancelling” in Desk would **not** automatically remove wallet ₹617. |
| Can service safely continue on this order? | **Not while wallet keeps ₹617.** Commercial engine already blocks paid commercial actions. Bypassing that would risk free service + retained refund. |
| Would customer get **both** service and refund? | **Yes**, if service is delivered/ref issued while wallet balance remains. |
| Would a second payment be required? | **Yes**, if customer keeps the wallet credit **and** wants a new paid service. **No** second Cashfree charge if wallet is reversed first and original payment is treated as still funding service (finance/ops decision — not an automatic Desk reopen). |
| Should Cashfree refund be run now? | **No.** That would send money to bank/UPI **on top of** existing wallet credit → double payout. |

---

## 8. Root cause

**Primary (financial reality):** Refund was executed as **wallet / manual**, not Cashfree original-payment reversal. Customer expected (or later asked for) **bank** receipt. Desk correctly shows refund completed; bank path was never used.

**Secondary (today’s friction):**

1. Customer still holds wallet ₹617 and is calling/emailing for next step (bank vs service).
2. Missed-call automation keeps opening recovery cases on a commercially closed order.
3. Customer’s bank-transfer email was **spam-ignored**, so Desk did not attach that intent to SC28430.
4. Ops began identity work (serial/model) on SC28430 despite commercial **Refund Completed** — unsafe if service delivery is attempted.

**Not the root cause:** Cashfree failure, pending gateway refund, or missing Shipra completion. Shipra’s completion is real for the **wallet** path.

---

## 9. Recommended business resolution (do not implement here)

**Safest default:** Treat this as **already refunded to wallet**. Do **not** refund again. Do **not** assign service reference / deliver paid service on RD3454444 until wallet ₹617 is resolved.

**Force one customer choice (documented on SC28430):**

### Option A — Customer keeps refund (wallet → bank if needed)

1. Confirm wallet balance ₹617 still present (#RD273105 / RadiumBox wallet tools).
2. If they want bank: process **wallet withdrawal / payout** as a **separate finance/wallet operation** — not a new Cashfree refund of payment `6023342207`.
3. Keep commercial closed; close SC28430 with clear note; stop service delivery on this order.

### Option B — Customer wants service instead

1. **Debit / reverse** the wallet credit ₹617 (ops/finance proof required).
2. Only after wallet reverse is confirmed: allow commercial path to serve on original paid order (policy exception / controlled override — Desk currently blocks this automatically).
3. Do **not** create a second Cashfree refund.
4. Do **not** take a second payment unless wallet reverse is refused and a **new** order is created.

### Option C — Keep wallet + buy service again

1. Leave wallet ₹617 as-is (or bank-withdraw later).
2. Create **new paid order** for service (second payment required).
3. Do not reuse RD3454444 commercially.

**Recommended pick if customer now prefers service:** **Option B** (reverse wallet, then serve) — only with finance proof. If wallet reverse is hard/slow and customer needs service urgently: **Option C** (new payment).

**Immediate ops hygiene (no code):**

- Reply on SC28430 acknowledging wallet credit and asking A/B/C.
- Un-ignore / note inbound email 179340 on the case (human handling — investigation did not change data).
- Do not issue Ref No on RD3454444 until choice is settled.

---

## 10. Risks by option

| Option | Risks |
|--------|-------|
| **A — Keep refund / wallet→bank** | Customer does not get service; wallet payout ops error could delay bank credit; repeated missed-call cases until closed clearly |
| **B — Reverse wallet + serve on RD3454444** | Wallet reverse fails or is incomplete → free service; Desk commercial block must be consciously overridden; serial just changed — identity risk |
| **C — New payment + keep wallet** | Customer pays twice if they later also get service on old order; confusion on which RD is active |
| **Deliver service now without wallet reverse** | **Highest risk** — customer gets **service + ₹617 refund** |
| **Cashfree refund now** | **Highest financial risk** — bank/UPI payout **plus** existing wallet = **double refund** |
| **Desk-only “cancel refund”** | Cosmetic; does not reclaim wallet; can falsely show order as commercially open |

---

## 11. Deliverable checklist

| # | Deliverable | Result |
|---|-------------|--------|
| 1 | Complete timeline | §1 |
| 2 | Root cause | §8 |
| 3 | Current financial state | Wallet ₹617 credited; Cashfree payment SUCCESS unreverted; desk refund closed |
| 4 | Current operational state | SC28430 open / Avinash; order active + commercially refund-completed; no appointment |
| 5 | Recommended resolution | §9 — choose A/B/C; default block service until wallet settled; never second Cashfree refund |
| 6 | Risks | §10 |

---

## 12. Commercial block after external wallet reverse

**Live (2026-08-06 evening IST):** After any external RadiumBox wallet debit, Desk still reports:

| Signal | Value |
|--------|-------|
| Commercial state | **`refund_completed`** |
| Blocks Assign Ref | **true** |
| Reason | “Commercially closed — Assign Service Reference is unavailable after a refund was completed.” |
| Driving refund | REF-2026-000020 (`id=20`, `status=closed`) |
| Order status | `active` — **not** the blocker |
| Case status | `open` — **not** the blocker |
| Business holds | none — **not** the blocker |

### Blocking field(s)

Not a single stored “commercial_state” column. Derived by:

`CommercialStateResolver::resolve()` → finds order/incident refund with status **`closed` / `completed` / `approved`** → `refundCompletedSnapshot()` → blocks `assign_service_reference`, `paid_service`, `paid_appointment`, `charge_customer`.

### Blocking service

| Role | Exact class / method |
|------|----------------------|
| Source of truth | `App\Services\Commercial\CommercialStateResolver::resolve()` |
| Public gate | `::blocks()` / `::ineligibilityReason()` |
| Assign Ref enforcement | `App\Services\OrderTransactionService::assertCommercialAllowsServiceReference()` ← `assignTransactionId()` |

### Existing override?

**Implemented (2026-08-06):** append-only `commercial_service_restorations` + permission `commercial.service.restore`. Ops Admin records Finance Verified + Wallet Reversed Externally on the C360 Commercial State card. Resolver returns `service_restored` for that order/refund pair only. Original `refund_requests` row is never modified. See [`docs/br-04-commercial-state.md`](./br-04-commercial-state.md#service-restoration-after-external-wallet-reverse-implemented-2026-08-06).

### Production steps for SC28430 (after Finance wallet debit)

1. Confirm RadiumBox wallet debit proof  
2. Open SC28430 Customer 360 → Commercial State → **Restore Commercial Service**  
3. Check Finance Verified + Wallet Reversed Externally; enter wallet reverse reference  
4. Confirm → commercial state becomes **Service Restored**  
5. Assign Reference / continue service

---

## Investigation method

Read-only production queries via SSH + `php artisan tinker` using `tools/config.sh` (`desk.radiumbox.com` / `radium-desk`).

Tables/models consulted: `incidents`, `orders`, `refund_requests`, `audit_logs`, `remarks`, `cashfree_webhook_logs`, `outbox_events`, `whatsapp_template_dispatches`, `outgoing_email_messages`, `incoming_email_messages`, `finance_journals`, `support_appointments`, `business_holds`, `interakt_webhook_logs`, `CommercialStateResolver`.

No writes. No Cashfree API mutations. No Desk UI actions.
