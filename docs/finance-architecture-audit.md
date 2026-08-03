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
| Status | **Missing** (label-only **placeholder**) |
| Evidence | `ApprovedRefundMethod::Wallet`; refund execute form “wallet ref” copy; `ManualRefundExecutor` handles all methods |
| Missing | Balance store, ledger, top-up/debit APIs, reconciliation, ownership of refund clearing for wallet method |

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

## Stop

Investigation complete. No code was implemented as part of this audit.
