# Finance Module Architecture Audit

**Date:** 2026-08-03  
**Scope:** Investigation only — no implementation  
**Codebase:** `radium-service-desk`  
**Canvas:** [`finance-architecture-audit.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/finance-architecture-audit.canvas.tsx)

---

## Verdict

The Finance module has a **production-capable double-entry ledger core** (journals, balanced posting, idempotency, cash/bank ledgers, expense posting, Cashfree → order → GL, refund → GL). Around that core, much of the Finance workspace is still **Phase 1 scaffolding**: dashboard, customer payments, daily closing, and vendor areas are stubs. **Wallet, settlement, financial reports, GST GL, and exports do not exist.**

Safest next work is to **surface existing GL data** (dashboard, payments list, trial balance / P&L) and tighten permissions — not to build new money-movement systems yet.

---

## Maturity Scores (0–10)

| Domain | Score | Rationale |
|--------|------:|-----------|
| Payments | **7** | Cashfree webhook → Order → `OrderPaid` → GL is solid and tested. Finance “Customer Payments” tab is a stub. No non-Cashfree payment capture in Finance. |
| Wallet | **1** | Refund method enum/label only. No balance table, credit/debit, or reconciliation. Executor is always manual. |
| Ledger | **7** | Real double-entry engine, COA, cash/bank movement UIs, expense + payment + refund + cash-opening sources. No reversals, no sub-ledgers, unused source types. |
| Reports | **1** | No P&L, Trial Balance, Balance Sheet, Day Book, Cash Book, or Outstanding. Cash/Bank screens are movement lists only. |
| Reconciliation | **6** | Cashfree payment integrity + CLI + ops health + auto-recovery are real. No wallet recon, no bank-statement recon, no GL-vs-provider recon UI in Finance. |
| Accounting | **5** | COA + journals + cutover gate + opening cash. Missing full books, GST in GL, AR/AP, settlement, reversing entries. |

**Overall:** ~**4.5 / 10** as a complete finance product; ~**7 / 10** as a ledger foundation for the flows that already post.

---

## Architecture (current)

```
Cashfree webhook
    ↓
Order (cashfree_payment_id, payment_amount)
    ↓
OrderPaid event
    ↓
OrderPaymentJournalService
    ↓
JournalPostingService  ←── Expense post
    ↑                      ←── Opening cash
RefundCompleted            ←── (refund complete)
    ↓
RefundJournalService
    ↓
finance_journals + finance_journal_lines
    ↓
AccountBalanceReadModel → Cash Ledger / Bank Ledger / Journal Audit
    ↓
Reports: MISSING
```

**Desired chain from brief:** Cashfree → Payment → Wallet → Refund → Ledger → Reports  
**Actual:** Cashfree → Order payment → Ledger; Refund (manual) → Ledger; Wallet and Reports are gaps.

---

## Section-by-Section Status

Status vocabulary: **Implemented** · **Partial** · **Stub** · **Unused** · **Dead** · **Duplicate** · **Placeholder** · **Missing**

### Finance Dashboard

| Layer | Finding |
|-------|---------|
| Status | **Stub** |
| Route | `GET /finance/dashboard` → `finance.dashboard` |
| Controller | `Finance\DashboardController` — hardcodes five widgets as `—` |
| View | `resources/views/finance/dashboard.blade.php` |
| Notes | Balances already exist via `AccountBalanceReadModel` / cash-bank summarizers but are not wired. Explicit Phase 1 placeholder messaging. |

### Wallet

| Layer | Finding |
|-------|---------|
| Status | **Missing** in Desk (label-only **placeholder**); real RD Wallet is **external** (RadiumBox admin) |
| Evidence | `ApprovedRefundMethod::Wallet`; refund execute form “wallet ref” copy; `ManualRefundExecutor` handles all methods |
| Missing in Desk | Balance store, ledger, top-up/debit APIs, reconciliation, ownership of refund clearing for wallet method |
| Deep dive | [RD Wallet investigation (2026-08-06)](#rd-wallet-investigation-2026-08-06-sc28430) below |

### Payments (Customer)

| Layer | Finding |
|-------|---------|
| Status | Finance UI **Stub**; inbound Cashfree path **Implemented** |
| Finance route | `GET /finance/payments` → placeholder view |
| Real path | `POST /api/webhooks/cashfree` → processor → Order → journal |
| Duplicate | Order workspace payments tab shows order-level payment fields; Finance payments tab does not reuse that data |

### Cash Ledger

| Layer | Finding |
|-------|---------|
| Status | **Implemented** |
| Route | `GET /finance/cash` |
| Stack | `CashLedgerController` → `LedgerAccountMovementReadModel` → journal lines for cash GL accounts |
| Gap | Not a full Cash Book (no opening/closing day structure, no physical count) |

### Expense Ledger

| Layer | Finding |
|-------|---------|
| Status | **Partial** (covered by Expenses + GL, no dedicated “Expense Ledger” screen) |
| Stack | Full expense CRUD + post → Dr expense GL / Cr cash or bank GL |

### Income Ledger

| Layer | Finding |
|-------|---------|
| Status | **Missing** |
| Implicit | Account `4000` Sales / RD Income credited by order payment journals only |

### Journal / Double Entry

| Layer | Finding |
|-------|---------|
| Status | **Implemented** (engine + audit UI) |
| Core | `JournalPostingService` — balance check, line rules, unique `idempotency_key`, `lockForUpdate` |
| Audit UI | Settings → Journals (`finance.settings.journals`) |
| Placeholder sources | Enum cases `manual_adjustment`, `cash_deposit`, `bank_transfer` — **unused** in production flows |
| Dead | `OpeningBalanceService::postAccountOpening()` — **no caller** |

### Refund Ledger

| Layer | Finding |
|-------|---------|
| Status | Workflow **Implemented** outside Finance; Finance ledger screen **Missing** |
| Path | `/refunds` → complete → `RefundCompleted` → Dr refund expense / Cr bank clearing |
| Note | Always credits bank clearing regardless of refund method (wallet/cash/UPI) — ownership issue |

### Vendor Ledger / Vendor Payments / Vendor Master

| Layer | Finding |
|-------|---------|
| Status | **Stub** / **Placeholder** |
| Routes | `finance.vendor-payments.index`, `finance.settings.vendor-master` |
| Views | Shared `finance/partials/placeholder.blade.php` |

### Customer Ledger

| Layer | Finding |
|-------|---------|
| Status | **Missing** — no AR sub-ledger, no customer balance model |

### Settlement

| Layer | Finding |
|-------|---------|
| Status | **Missing** |

### Cashfree Reconciliation

| Layer | Finding |
|-------|---------|
| Status | **Implemented** (ops/CLI, not Finance UI) |
| Stack | `CashfreePaymentIntegrityService`, `cashfree:reconcile`, auto-recover commands, ops health, webhook explorer |
| Gap | Not exposed inside Finance workspace; does not reconcile GL balances vs Cashfree settlements |

### Wallet Reconciliation

| Layer | Finding |
|-------|---------|
| Status | **Missing** |

### Reports

| Report | Status |
|--------|--------|
| Profit & Loss | **Missing** |
| Trial Balance | **Missing** |
| Balance Sheet | **Missing** |
| Day Book | **Missing** |
| Cash Book | **Missing** (cash ledger ≠ cash book) |
| Outstanding | **Missing** |

Data needed for Trial Balance / simple P&L already exists in `finance_journal_lines` + account types.

### GST Readiness

| Layer | Finding |
|-------|---------|
| Status | **Partial** outside Finance only |
| Evidence | `config/refunds.php` `gst_rate`; `RefundCalculationService` |
| Missing | Tax codes on COA, taxable journals, GST reports, invoice tax GL |

### Exports

| Layer | Finding |
|-------|---------|
| Status | **Missing** for Finance |
| Note | CSV exists for RadiumBox order reconciliation only — unrelated to GL |

### Daily Closing

| Layer | Finding |
|-------|---------|
| Status | **Stub** (`finance.closings.index` → placeholder) |

### Chart of Accounts / Settings / Opening Balances

| Area | Status |
|------|--------|
| COA CRUD | **Implemented** |
| Cash/Bank/Payment method/Category masters | **Implemented** |
| Financial preferences + cutover | **Implemented** |
| Cash opening balance | **Implemented** |
| Arbitrary GL opening | **Dead** (`postAccountOpening` unused) |

---

## Trace Inventory

### Routes (Finance workspace)

| Name | Method | URL | Status |
|------|--------|-----|--------|
| `finance.dashboard` | GET | `/finance/dashboard` | Stub |
| `finance.payments.index` | GET | `/finance/payments` | Stub |
| `finance.expenses.*` | CRUD + post | `/finance/expenses…` | Live |
| `finance.cash.index` | GET | `/finance/cash` | Live |
| `finance.bank.index` | GET | `/finance/bank` | Live |
| `finance.closings.index` | GET | `/finance/closings` | Stub |
| `finance.vendor-payments.index` | GET | `/finance/vendor-payments` | Stub |
| `finance.settings.*` | masters + journals + prefs + opening | `/finance/settings/*` | Mostly live; vendor master stub |

Refunds and Cashfree live outside the Finance prefix (`/refunds`, `/api/webhooks/cashfree`, `/cashfree/webhook-explorer`).

### Controllers

**Live:** `ExpenseController`, `CashLedgerController`, `BankLedgerController`, `SettingsController` (except vendor master), cash/bank/payment-method/expense-category controllers  

**Stub:** `DashboardController`, `CustomerPaymentController`, `DailyClosingController`, `VendorPaymentController`

**Adjacent:** `RefundRequestController`, `CashfreeWebhookController`, `CashfreeWebhookLogController`

### Views

Under `resources/views/finance/` — live expenses/cash/bank/settings; stubs share `partials/placeholder.blade.php`. Refund and Cashfree explorer views are separate trees.

### Services (no Repository layer)

| Service | Role | Status |
|---------|------|--------|
| `JournalPostingService` | Double-entry post | Core |
| `OrderPaymentJournalService` | Payment → GL | Live |
| `RefundJournalService` | Refund → GL | Live |
| `FinanceExpenseService` | Expense lifecycle | Live |
| `OpeningBalanceService` | Opening journals | Partial |
| `FinanceSettingsService` | Cutover + default GL codes | Live |
| `FinanceMasterDataService` | Masters | Live |
| `AccountBalanceReadModel` | Cached balances | Live |
| `LedgerAccountMovementReadModel` | Cash/bank screens | Live |
| `CashfreePaymentIntegrityService` | Reconcile Cashfree vs orders | Live |
| `ManualRefundExecutor` | All refund methods | Manual only |

### Models / Tables

`finance_accounts`, `finance_journals`, `finance_journal_lines`, `finance_expenses`, `finance_expense_categories`, `finance_payment_methods`, `finance_cash_accounts`, `finance_bank_accounts`, `finance_settings`, plus `refund_requests`, `cashfree_webhook_logs`, order payment columns.

### Observers / Events / Policies

| Type | Finding |
|------|---------|
| Observers | **None** for Finance models |
| Events | `Finance\OrderPaid`, `Finance\RefundCompleted` |
| Listeners | `PostOrderPaidJournal`, `PostRefundCompletedJournal` |
| Policies | **No** `FinancePolicy`; refunds use `RefundRequestPolicy` |
| Permissions | Spatie `finance.view` + tab `.view` perms; **no** separate create/post/manage |

### Seeders / Factories / Tests

| Item | Status |
|------|--------|
| `FinanceMasterDataSeeder` | Live |
| `FinanceChartOfAccountsSeeder` | Live (hardcoded GL codes) |
| Finance factories | **Missing** |
| Feature tests | Foundation access, master data, expenses, ledger integration, journal posting unit tests — solid for core |
| Cashfree / refund tests | Extensive, adjacent |

---

## Financial Movement Matrix

| Movement | Source | Destination | Ledger entries | Audit | Rollback | Idempotency | Duplicate protection |
|----------|--------|-------------|----------------|-------|----------|-------------|----------------------|
| Cashfree payment | Cashfree webhook / Order | Revenue (Cr `4000`) via Bank Clearing (Dr `1100`) | `order_payment` journal | Webhook log + journal `posted_by`/`posted_at` + journal audit UI | **None** (immutable post; listener swallows errors) | `order_payment:{order_id}` | Unique idempotency key + CF payment id on order |
| Expense post | `FinanceExpense` draft | Dr category GL / Cr cash or bank GL | `expense` journal | Expense `posted_by` + journal | **None** (draft→posted immutable; no reverse) | `expense:{id}` | Status lock + idempotency |
| Refund complete | `RefundRequest` | Dr refunds (`5100`) / Cr bank clearing | `refund` journal | Refund workflow + journal | **None** (workflow states; no GL reverse) | `refund:{id}` | Idempotency + refund status machine |
| Cash opening | Settings UI | Dr cash GL / Cr opening equity | `opening_balance` | Journal audit | **None** | `opening:cash:{id}` | One opening per cash account key |
| GL opening | Service only | Dr/Cr account vs equity | `opening_balance` | Would audit if used | N/A | `opening:account:{id}` | **Dead path** — unused |
| Wallet credit/debit | — | — | — | — | — | — | **Missing** |
| Settlement | — | — | — | — | — | — | **Missing** |
| Vendor payment | — | — | — | — | — | — | **Stub UI only** |

### Movement notes

1. **No reversing journals / void API** — posted journals are application-immutable; DB still allows cascade delete of lines if a journal row is deleted (`cascadeOnDelete`).
2. **Order paid listener fails soft** — payment succeeds even if journal posting throws (logged only). Risk of Order without GL.
3. **Refund method ignored in GL** — wallet/UPI/cash still credit bank clearing.
4. **Cutover gate** — `FinanceSettingsService::shouldPostForDate()` can skip journals silently (expense still marks posted with `journal_id = null`).

---

## Findings: Gaps & Risks

### Missing

- Income / Customer / Vendor / Refund ledger UIs  
- All standard reports + exports  
- Wallet + wallet reconciliation + settlement  
- GST in GL  
- Journal reversal / correcting entries  
- Production flows for `cash_deposit`, `bank_transfer`, `manual_adjustment`  
- Finance mutate permissions (create/post/settings write)

### Hardcoded values

- COA codes in `FinanceChartOfAccountsSeeder` (`1000`, `1100`, `3000`, `4000`, `5100`, `6001–6005`, `6099`)  
- Refund GST rate default `0.18` and cancellation charges in `config/refunds.php`  
- Dashboard widget labels/values  

### Duplicate / near-duplicate logic

- `OrderPaymentJournalService` ≈ `RefundJournalService` structure  
- Repeated FinanceAccess middleware in every Finance controller  
- Customer payment truth lives on `orders` + Cashfree; Finance payments tab duplicates the concept as empty shell  

### Incorrect ownership

- Refunds module owns money-out UX; Finance only receives a journal side-effect  
- Refund GL always uses bank clearing  
- Dashboard owns “Cash in Hand / Bank Balance” labels but not the read models that already compute them  

### Performance

- `CashfreePaymentIntegrityService::classifyFailedWebhooks()` loads **all** failed webhook rows into memory  
- Balance cache TTL 120s with per-account invalidation — fine for small COA; full Trial Balance will need a bulk SUM strategy  
- Ledger screens paginate (50) — OK  

### Security

- `finance.expenses.view` authorizes create, update, and post (same for settings mutations under `finance.settings.view`)  
- Expense receipts stored on **`public`** disk  
- No model-level guard preventing journal deletion  
- Webhook signature verification exists (good); Finance has no policy class  

---

## What Is Production-Ready Today

1. Double-entry posting with balance enforcement and idempotency  
2. Chart of accounts + master data + preferences/cutover  
3. Expense draft → post → journal  
4. Cashfree collection → order → revenue journal  
5. Refund completion → refund expense journal  
6. Cash and bank movement ledgers + journal audit screens  
7. Cashfree integrity reconciliation (ops/CLI) + recovery tooling  
8. Permission-gated Finance hub (view-level)  

## What Is Partial

1. Opening balances (cash only in UI)  
2. GST (refund calc only)  
3. Refunds (ops workflow yes; Finance ledger/reporting no)  
4. Payments (pipeline yes; Finance list UI no)  
5. Permissions (view yes; mutate granularity no)  

## What Is Stub / Placeholder

Dashboard, Customer Payments, Daily Closing, Vendor Payments, Vendor Master, shared placeholder partial, platform finance overview placeholder messaging.

## What Is Dead / Unused

- `OpeningBalanceService::postAccountOpening()`  
- Journal source types: manual adjustment, cash deposit, bank transfer (enum + tests only)  

---

## Implementation Roadmap

Priorities: production value, lowest risk, highest business impact. **Do not invent wallet/settlement before reporting on existing journals.**

### P1 — Surface & harden what already posts

| # | Item | Why | Risk |
|---|------|-----|------|
| 1 | Wire Finance Dashboard to live cash/bank balances + today’s collection/expense from journals | Immediate ops value; data already exists | Low |
| 2 | Replace Customer Payments stub with read-only list of paid Orders (Cashfree) + link to journal when present | Closes stub → truth gap | Low |
| 3 | Trial Balance + simple P&L from `finance_journal_lines` + account types | Highest accounting value on current data | Low–medium |
| 4 | Split mutate permissions (`finance.expenses.create/post`, settings write) from view | Security | Low |
| 5 | Detect/repair Order-without-journal (listener soft-fail); alert or backfill command | Money integrity | Medium |
| 6 | Journal immutability hardening (no hard delete; optional reverse-entry later) | Audit integrity | Low |

### P2 — Complete operational money loops

| # | Item | Why | Risk |
|---|------|-----|------|
| 1 | Refund Ledger screen in Finance (filter `source_type=refund`) | Ops visibility without new posting | Low |
| 2 | Income / revenue movement view (account 4000 / order_payment) | Complements cash/bank | Low |
| 3 | Method-aware refund clearing (wallet vs bank vs cash) — **design carefully** | Fixes incorrect ownership | Medium |
| 4 | Manual journal / cash deposit / bank transfer posting flows | Uses existing enum placeholders | Medium |
| 5 | Daily closing (cash count vs GL) | Control | Medium |
| 6 | CSV exports for journals, cash/bank, trial balance | Compliance / accountant handoff | Low |
| 7 | Expense receipt private disk + authorized download | Security | Low |

### P3 — Expand into full accounting product

| # | Item | Why | Risk |
|---|------|-----|------|
| 1 | Balance Sheet, Day Book, Cash Book, Outstanding | Full books | Medium |
| 2 | Customer AR ledger + Outstanding | Collections | High (new domain) |
| 3 | Vendor master, vendor payments, vendor/AP ledger | Payables | High |
| 4 | Wallet balances + wallet reconciliation | Only if business requires stored-value | High |
| 5 | Settlement workflow + Cashfree settlement↔GL recon | Treasury | High |
| 6 | GST tax codes / taxable lines / GST reports | Compliance | High |
| 7 | Automated Cashfree refund execution (replace manual-only) | Reduce ops error | High |
| 8 | Reversing journals + period lock | Accounting controls | Medium |

---

## Recommended Sequence (one line)

**Dashboard + payments list → Trial Balance/P&L → permission split → refund/income ledgers → exports → daily closing → only then wallet/vendors/GST/settlement.**

---

## RD Wallet investigation (2026-08-06, SC28430)

**Scope:** Read-only — Desk codebase + production DB. No code changes. No production writes. No wallet credit/debit/reverse performed.  
**Trigger:** Can Finance safely reverse the ₹617 wallet refund for **REF-2026-000020** / **RD3454444** so service can continue on **SC28430**?  
**Related case report:** [`docs/sc28430-refund-service-investigation.md`](./sc28430-refund-service-investigation.md)

### Verdict

| Question | Answer |
|----------|--------|
| Is RD Wallet implemented in Desk? | **No** |
| Ledger type in Desk for customer wallet? | **Does not exist** (N/A) |
| Where does wallet money live? | **External — RadiumBox admin** (`https://admin.radiumbox.com`), outside this repo’s API surface |
| Can Desk reverse the ₹617 credit? | **No** — no wallet debit/reverse/adjustment API or UI |
| Does Desk store transaction `#RD273105`? | **No** — only a free-text remark on SC28021; refund row stores `execution_reference_no = REF-2026-000020` |
| Safe to reverse for SC28430? | **Only via RadiumBox wallet tools**, after live balance verify; then Desk commercial exception for service. Desk alone cannot do it. |

---

### 1. Wallet architecture (Desk)

```
Customer pays (Cashfree)
    ↓
Order + Service Case
    ↓
Refund requested (method = wallet)     ← Desk enum only
    ↓
Ops credits RD Wallet OUTSIDE Desk     ← RadiumBox admin (not integrated)
    ↓
Desk ManualRefundExecutor.complete()   ← stores REF ref; provider=manual
    ↓
Optional: RefundJournalService         ← Desk GL (bank clearing), not wallet ledger
    ↓
CommercialState = refund_completed     ← blocks paid service / Assign Ref
```

**What Desk has (wallet-adjacent only):**

| Layer | Exists? | Location |
|-------|---------|----------|
| Wallet tables | **No** | Production `SHOW TABLES` → zero `wallet*` tables |
| Wallet models | **No** | — |
| Wallet services / controllers / routes | **No** | No `/finance/wallet` |
| Wallet APIs | **No** | RadiumBox client = `GET /api/search/order` only |
| Refund method label | **Yes** | `ApprovedRefundMethod::Wallet`, `CustomerPreferredRefundMethod::Wallet`, `config/refunds.php` |
| Manual complete | **Yes** | `ManualRefundExecutor` — requires reference or txn id string; **no external call** |
| Finance GL on refund | **Yes** (generic) | `RefundJournalService` → Dr 5100 / Cr 1100 bank clearing — **ignores method** |
| Wallet reconciliation | **No** | — |

**RadiumBox integration in Desk (non-wallet):**

| Item | Value |
|------|-------|
| Base URL | `https://admin.radiumbox.com` |
| Enabled (prod) | `true` |
| HTTP methods used | Order enrichment search only |
| Wallet env vars | None |

---

### 2. Database design

#### Desk (this product)

| Domain | Tables | Role vs wallet |
|--------|--------|----------------|
| Refund workflow | `refund_requests` | Stores `approved_refund_method=wallet`, execution strings — **not** a wallet ledger |
| Finance GL | `finance_accounts`, `finance_journals`, `finance_journal_lines` | Company double-entry books — **not** customer stored-value |
| Cash book | `cash_book_entries` (+ journal links) | Physical cash — has **journal reverse** on edit/delete |
| Customer wallet | — | **Missing** |

#### External RD Wallet

Not in Desk schema. Ops references like `#RD273105` are RadiumBox-side identifiers. Desk cannot query balance, list ledger lines, or post debit via current integration.

---

### 3. Ledger implementation — which model?

| System | Model | Notes |
|--------|-------|-------|
| **RD customer wallet** | **Unknown / external (D — something else from Desk’s POV)** | Not A/B/C inside Desk — **no implementation**. Ops treat credits as RadiumBox wallet movements (likely a ledger there, but **not observable** from Desk code/API). |
| **Desk Finance GL** | **A + cached read (hybrid)** | Immutable journals + lines; `AccountBalanceReadModel` = `SUM(debit/credit)` with cache prefix `finance:account-balance:` |
| **Desk refund “wallet” path** | **Manual attestation** | Completing a wallet refund does **not** post a wallet ledger row; it only marks `refund_requests` completed/closed |

**Preferred accounting method if wallet is ever built in Desk:** append-only ledger (**A**): credit + compensating debit (or reversing entry), never mutate historical rows — same pattern as `CashBookEntryService::reverseCurrentJournal()`.

---

### 4. Wallet credit for REF-2026-000020 / RD273105

#### Desk refund row (`refund_requests.id = 20`)

| Field | Value |
|-------|-------|
| Refund reference | **REF-2026-000020** |
| Amount | ₹617.00 |
| Method | `wallet` |
| Desk status | `closed` |
| Execution reference | **REF-2026-000020** (not RD273105) |
| Execution / refund transaction id | **null** |
| Execution remarks | **null** |
| Provider (audit) | `manual` |
| Executed by | Shipra (user 3) |
| Executed at | 2026-07-19 23:41:21 IST |
| Desk finance journal | **None** (`source_type=refund`, `source_id=20` empty — refund pre-dates ledger foundation 2026-08-02) |

#### External wallet reference `#RD273105`

| Field | Value |
|-------|-------|
| Transaction ID in Desk | **Not stored** |
| Where found | Remark #17519 on **SC28021** (2026-08-06 15:09 IST), author Avinash — workspace close note |
| Claimed content | “Wallet Refund for Order ID RD3454444 with reference #RD273105, Amount ₹617 credited to the wallet” / success ~19 Jul 23:39 |
| Linked in `refund_requests` | **No** |
| Verifiable via Desk/RadiumBox API from this app | **No** |

**Implication:** Desk can prove “refund marked completed as wallet.” It **cannot** prove live wallet ledger state for `#RD273105` without a human check in RadiumBox admin (or a future API).

Production pattern: **119** completed/closed wallet refunds; recent samples all set `execution_reference_no` to the Desk `REF-…` number and leave gateway/wallet txn ids null — external wallet refs are routinely **not** persisted on the refund row.

---

### 5. How “balance” is calculated (by system)

| System | Calculation |
|--------|-------------|
| RD Wallet balance | **Outside Desk** — unknown formula here (likely RadiumBox ledger sum or stored balance). Desk has **zero** wallet balance cache/table. |
| Desk GL account balance | Real-time sum of journal lines (debit/credit by account type), optionally **cached** (`AccountBalanceReadModel`) |
| Desk refund “already refunded” | Sum of non-rejected refund request amounts on the order (`countsTowardAlreadyRefunded`) — workflow math, not wallet |

---

### 6. Reverse capability — does it already exist?

| Search term | In Desk? | Where |
|-------------|----------|-------|
| Wallet reversal | **No** | — |
| Wallet debit | **No** | — |
| Wallet adjustment / correction | **No** | — |
| Wallet rollback / compensation | **No** | — |
| Negative wallet transaction | **No** | — |
| Admin / Finance wallet tools | **No** | Finance wallet UI missing |
| Refund undo / reopen reverse credit | **No** | Completed/closed refunds are terminal |
| Cash book journal reverse | **Yes** | `CashBookEntryService::reverseCurrentJournal()` — **not wallet** |
| `FinanceJournalSourceType::ManualAdjustment` | Enum only | **Unused** in production flows |
| RadiumBox wallet reverse API | **Not integrated** | Client has order search only |

**Every Desk “usage” of wallet today:**

1. Enums + `config/refunds.php` labels  
2. Refund create/approve/complete UI (method = wallet)  
3. `ManualRefundExecutor` (manual ref attestation)  
4. Tests asserting wallet method on refunds  
5. Investigation/docs mentioning external wallet tools  
6. Nav icon `bi-wallet2` (cosmetic)  

**No module has performed an automated wallet reversal, adjustment, or manual debit inside Desk.**

---

### 7. Accounting preference for reversing +617

| Approach | Preferred? | Notes |
|----------|------------|-------|
| Mutate / delete the +617 history row | **No** | Breaks audit; Desk GL forbids mutating posted journals |
| Post compensating **−617** (debit) linked to original credit | **Yes** | Append-only; matches Cash Book reverse pattern and proper ledger practice |
| Desk GL reversing journal for REF-20 | N/A today | No original refund journal was posted for id 20 |
| Desk “cancel refund” status flip | **Insufficient** | Does not move RadiumBox wallet money; can falsely open commercial path |

---

### 8. SC28430 — safest operational sequence (if Finance wants service)

Desk **cannot** execute steps 2–3. Those are RadiumBox/finance ops.

```
1. Verify wallet (RadiumBox admin)
      · Open customer wallet for phone 7643082915 / email suraj7502492@gmail.com
      · Locate credit #RD273105 (or equivalent) for RD3454444 / ₹617
      · Confirm available balance ≥ ₹617 and credit not already spent/withdrawn
      ↓
2. Reverse wallet (RadiumBox admin only)
      · Post compensating debit / reverse ₹617 against that credit
      · Capture proof: screenshot + txn id + new balance (= prior − 617)
      · Paste proof on SC28430 remark (human)
      ↓
3. Do NOT Cashfree-refund payment 6023342207
      · Do NOT complete a second Desk refund
      ↓
4. Commercial state (Desk policy exception)
      · Order remains commercially refund_completed until product allows override
      · Only after wallet reverse proof: authorized override / controlled path
        to allow Assign Ref / paid service on RD3454444
      · Document who approved the exception
      ↓
5. Reference Number → continue service on SC28430 / RD3454444
      · Assign Ref only after steps 1–4
      · Keep SC13834 historical; work the open recovery case
```

**If wallet balance &lt; ₹617 or credit already spent:** stop. Do not serve on original payment without a **new** payment (or customer accepts reduced/no service). Do not invent a Desk reverse.

**If reverse is slow and customer needs service now:** new paid order (keep wallet as refund) — see SC28430 Option C.

---

### 9. Risk analysis

| Scenario | Effect |
|----------|--------|
| Wallet already spent / withdrawn | Reverse fails or overdraws; serving on RD3454444 = **free service + spent refund** |
| Partial balance (&lt; ₹617) | Full reverse impossible; need finance decision (partial debit + top-up payment, or new order) |
| Multiple refunds on same customer/order | Desk already blocks double refund via `countsTowardAlreadyRefunded`; wallet side may have multiple credits — reverse the **specific** #RD273105 credit only |
| Concurrent adjustments | Race between bank withdrawal and reverse → confirm balance under lock/process in RadiumBox; serialize ops |
| Desk-only status change without RadiumBox debit | Commercial opens while wallet still funded → **service + refund** |
| Cashfree refund after wallet credit | **Double payout** (wallet + bank) |
| Relying on remark #RD273105 without RadiumBox verify | Remark is attestation, not a ledger row — may be wrong/stale |

---

### 10. Deliverables checklist

| # | Item | Result |
|---|------|--------|
| 1 | Wallet architecture | Desk = method label + manual complete; money movement = external RadiumBox |
| 2 | Database design | No wallet tables in Desk; refund_requests holds method/status only |
| 3 | Ledger implementation | RD Wallet not in Desk; Desk GL is separate double-entry (hybrid cached sums) |
| 4 | Existing reverse capability | None for wallet; Cash Book journal reverse only |
| 5 | Whether reversal already exists | **No** in Desk; must be RadiumBox ops |
| 6 | Safest SC28430 procedure | §8 — verify → RadiumBox reverse → proof → commercial exception → Ref/service |
| 7 | Recommendation | **Do not reverse from Desk.** Reverse only in RadiumBox after balance check. Prefer compensating −617. Until then, do not Assign Ref / serve on RD3454444. |

---

### Recommendation (SC28430)

1. Treat Desk as **source of truth for refund workflow status**, not for wallet money.  
2. Treat RadiumBox admin as **source of truth for wallet balance/ledger**.  
3. Safe path to continue service: **RadiumBox debit ₹617 (compensating entry) → proof on SC28430 → then commercial exception → Assign Ref.**  
4. Do **not** build an emergency Desk wallet reverse for this case; do **not** mutate history; do **not** Cashfree-refund again.  
5. Product gap remains P3: Wallet balances + reconciliation (+ API) only if business requires Desk-owned stored-value.

---

## Stop

Investigation complete (including 2026-08-06 RD Wallet / SC28430 addendum). No code was implemented. No production wallet transactions were created or reversed.
