# Central Statutory Engine Readiness Audit

**Project:** Radium Desk  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Prompt ID:** RadiumDesk-P-01-09-18  
**Date:** 2026-09-01  
**Type:** Read-only architecture / readiness audit. No application-code change, no production DB, no migrations, no invoice mint, no sequence increment, no credit notes, no ingest enablement, no deploy, no push.  
**Canvas:** [`central-statutory-engine-readiness-audit.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/central-statutory-engine-readiness-audit.canvas.tsx)

Classification used throughout:

| Label | Meaning |
|-------|---------|
| **A — Desk source** | Present in this repository’s current PHP/schema/tests |
| **B — Planned / documented** | Architecture or sibling-project docs only; not implemented here |
| **C — Production-enabled** | Live Desk production can execute it |
| **D — UNKNOWN / CA / business** | Code cannot safely decide |
| **VERIFIED** | Read from this worktree, Git, or already-cited sibling docs |
| **INFERRED** | Consistent with verified facts; not re-executed |
| **UNKNOWN** | Not inspectable from this repo without production/CA input |

This audit does **not** decide whether Desk or a separate Finance service is the final statutory issuer. It records what Desk currently implements, what is only planned, what is production-enabled, and what remains a business/CA decision.

---

## Inspect (this ticket)

| Item | Value | Class |
|------|-------|-------|
| Repository path | `/Users/ravi/RadiumWebsites/radium-desk` (`git rev-parse --show-toplevel`) | VERIFIED |
| Prompt ledger | `docs/cursor-prompt-ledger.md` (no `docs/cursor-prompt-log.md`) | VERIFIED |
| Next unused ID | **RadiumDesk-P-01-09-18** (ledger last row P-01-09-17) | VERIFIED |
| Branch | `feat/rd-fresh-01-inventory-pos` | VERIFIED |
| HEAD before | `43f1284bef80002866db7303bf5e9f96beb49d33` | VERIFIED |
| Worktree | Clean; up to date with `origin/feat/rd-fresh-01-inventory-pos` | VERIFIED |
| Owner GitHub remote | `origin` = `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Other worktree | `/Users/ravi/RadiumWebsites/radium-service-desk-deploy` at `21fc11c5` (`main`) | VERIFIED |
| `origin/main` | `21fc11c5fe33f594f4f1a690c3a09fbde101a293` (P-31-08-14 docs). Statutory/ingest commits **not** ancestors of `origin/main` | VERIFIED |
| Latest Git tag | `v4.0.64` on `0d734f85` | VERIFIED |
| Production DB this ticket | **not connected** | UNKNOWN by design |
| Other projects | Read-only citation of Admin Gate 0 and rdservice.net investigation; **not modified** | VERIFIED |

Relevant commits on this branch (not on `main`):

| Commit | Subject |
|--------|---------|
| `8b82efe3` | docs: record central finance and statutory invoice architecture (P-01-09-14) |
| `845e2831` | Add a Desk statutory invoice foundation that stays unset until CA numbering (P-01-09-15) |
| `f8175975` | Add HMAC channel order ingest that stores commerce orders without minting (P-01-09-16) |
| `43f1284b` | Add date-filtered accountant month-end reports without enabling statutory numbering (P-01-09-17) |

---

## 1. Executive finding

Desk **source** already contains a central statutory invoice **foundation** and a channel-order **ingest** foundation. Both are **fail-closed**. They are **not** a live statutory engine that multiple channels can safely use.

| Question | Answer | Class |
|----------|--------|-------|
| Does Desk source implement mint/allocate? | **Yes**, `StatutoryInvoiceService::mint` + `StatutoryInvoiceNumberingService::allocate` | A, VERIFIED |
| Does that path run in production? | **No.** Legal series/format env empty; feature branch not on `main`/`v4.0.64` | C = no, VERIFIED source + INFERRED deploy |
| Does ingest mint an invoice? | **No.** Hard-false `auto_issue_invoice`; enabling it **rejects** ingest | A, VERIFIED |
| Does mint post finance journals? | **No.** Hard-false `post_finance_journals`; enabling it **rejects** mint | A, VERIFIED |
| Does POS complete mint a GST invoice? | **No.** Internal `INV-{branch}-{year}-{seq}` only; auto-issue flag fails closed | A, VERIFIED |
| Can Admin still allocate GST numbers for `ordertype=rdservice` and `radiumsign`? | **Yes in Admin source**, shared `radium_branch.rd_no` | Sibling VERIFIED (Admin-P-01-09-01). Not re-run here |
| Is Desk vs a separate Finance service decided? | **No.** Cross-project docs conflict. This audit does not resolve it | D |

**Placement conflict (do not resolve):**

- **This repo (P-01-09-14/15):** target is one statutory engine **inside Desk**; channels never allocate.
- **Sibling rdservice.net P-01-09-01 / Admin-P-31-08-09:** “Desk stays operations/read; it must not mint invoices.” Finance is described as the tax writer (`POST /v1/tax-documents/invoices`).

What Desk **currently implements** is a mint engine that **refuses to mint** until CA series + format are set, and an ingest API that **refuses to mint even if series is set**. That is A (source), not C (production), and not a placement decision.

Multiple channels **cannot** safely use this as a live issuer until: placement is decided, Admin allocate paths for those channels are disabled, legal series is set, GST split/place-of-supply policy exists, historical/paid-uninvoiced cutover is designed, and production enablement is explicit.

---

## 2. Current Desk statutory engine

### 2.1 What exists (A)

| Concern | Implementation | Files |
|---------|----------------|-------|
| Tables | `invoice_sequences`, `statutory_invoices`, `invoice_sequence_allocations`, `statutory_invoice_items`, `e_invoice_records`; nullable `inventory_sales.statutory_invoice_id` | `database/migrations/2026_09_01_160000_create_statutory_invoice_foundation_tables.php` |
| Models | `StatutoryInvoice`, `StatutoryInvoiceItem`, `InvoiceSequence`, `InvoiceSequenceAllocation`, `EInvoiceRecord` | `app/Models/` |
| Enums | Channel, source type, document type (`tax_invoice` / `credit_note` / `debit_note`), status (`issued` / `cancelled`) | `app/Enums/StatutoryInvoice*.php` |
| Mint | `StatutoryInvoiceService::mint` — lookup by `(channel, source_type, source_id)`, allocate, insert issued invoice + lines, link POS sale | `app/Services/StatutoryInvoice/StatutoryInvoiceService.php` |
| POS projection | `issueFromPosSale` copies completed sale lines (HSN from product; **no** CGST/SGST/IGST) | same |
| Cancel | `cancel()` sets status cancelled; **keeps number**; **no** credit-note document, **no** IRN cancel, **no** GL reverse | same |
| Numbering | `allocate()`: fail if series/format empty; `lockForUpdate` on existing sequence row; increment `current_value`; format; append-only allocation | `StatutoryInvoiceNumberingService.php` |
| Format | Tokens `{series}` `{seq}` `{seq:N}` `{gstin}` `{fy}`; refuses POS pattern `INV-[A-Z0-9]+-\d{4}-\d{5}` | `StatutoryInvoiceNumberFormatter.php` |
| Config | Env series/format/GSTIN/FY empty by default; `post_finance_journals` and `auto_issue_on_pos_complete` hard-false | `config/statutory_invoices.php`, `.env.example` |
| Accountant UI | GET invoice register + CSV; GET month-end reports | `StatutoryInvoiceController`, `StatutoryInvoiceReportController` |
| Immutability | Posted invoice fields cannot be mutated; invoices cannot be deleted; cancelled cannot be reopened | `StatutoryInvoice` model boot |
| IRN adapter | Interface + `NullEInvoiceGateway` (`provider = none`); bound in `AppServiceProvider` | `EInvoiceGateway.php`, `NullEInvoiceGateway.php` |

### 2.2 Capability vs request list

| Requested capability | Desk source | Notes |
|----------------------|-------------|-------|
| Statutory invoice tables/models | A | Additive migration; not on `main` |
| Sequence allocation | A | Fail-closed until CA series+format |
| Invoice numbering | A | Template only; legal string unset |
| Invoice document creation | A | Mint creates **issued** rows immediately |
| Invoice lines | A | Frozen at mint |
| GST calculation | **Partial A** | Totals **sum caller-supplied** line tax. No exclusive-rate engine, no rounding policy |
| CGST/SGST/IGST | **Storage only** | Null unless caller provides. POS issue leaves them **null** (test `test_pos_internal_receipt_cannot_become_the_statutory_number`) |
| Place of supply | **Storage only** | Column nullable; not derived from GSTIN/state |
| Seller GSTIN | **Storage only** | From mint request / POS branch `gstin` (optional) |
| Buyer GSTIN | **Storage only** | From request / POS customer |
| HSN/SAC | **Storage only** | POS copies `product.hsn_code`; ingest stores if sent. Not required to mint |
| Invoice cancellation | **Partial A** | Status + reason; number kept. Not a GST credit note |
| Credit notes | B | Enum exists; **no** `credit_notes` tables, **no** CN mint, **no** CN series |
| Invoice UUID | **No UUID** | Bigint `id` + string `idempotency_key` `statutory:{channel}:{source_type}:{source_id}` |
| Invoice status/state | A | `issued` / `cancelled` only. Architecture **draft** (unnumbered) is **B**, not implemented |
| FY handling | **Config hook only** | `STATUTORY_INVOICE_FINANCIAL_YEAR` empty; `{fy}` token refuses if unset. No 1-Apr reset job |
| Legal series configuration | **Unset** | `STATUTORY_INVOICE_SERIES_CODE` / `NUMBER_FORMAT` empty in `.env.example` |
| PDF/document generation | **Missing** | No `pdf_path` column; no statutory PDF. POS Blade `resources/views/pos/sales/invoice.blade.php` is the **internal** receipt |
| IRN/e-invoice integration | **Stub only** | Table exists; gateway not called from mint/cancel |
| GSP adapter | **Null only** | No media.radiumbox.com / NIC client |
| Payment reference | A | Columns on invoice; not a `payments` / allocation table |
| Source/channel order relationship | A | `channel`, `source_type`, `source_id`, `source_order_id`, optional `inventory_sale_id` / `support_order_id`. Commerce order has nullable `statutory_invoice_id` (never set by ingest) |

### 2.3 What is only planned (B)

From `docs/rd-central-finance-invoice-architecture.md` (P-01-09-14), **not** in schema/code:

- Draft unnumbered invoices
- `commerce_payments` / `payment_allocations`
- `credit_notes` / `debit_notes` tables and numbering
- Tax GL accounts, invoice journals, period lock
- Real GSP / IRN submit on issue
- Historical Admin import
- HTTP mint API for channels (ingest explicitly does not call mint)
- POS auto-mint after eligibility
- Invoice-on-payment vs dispatch policy engine

### 2.4 Tests (A)

| Test | Covers |
|------|--------|
| `tests/Feature/StatutoryInvoice/StatutoryInvoiceServiceTest.php` | Monotonic numbers, unset series fail-closed, idempotent mint, channel isolation, outer-txn rollback of allocation, immutability, POS receipt ≠ GST number, POS format rejection, cancel keeps number, journal flag fail-closed |
| `tests/Unit/StatutoryInvoice/StatutoryInvoiceNumberFormatterTest.php` | `{series}-{seq:5}`; `{fy}` refuses when unset |
| `tests/Feature/StatutoryInvoice/StatutoryInvoiceMysqlConcurrencyTest.php` | Two-process InnoDB: distinct sources → distinct numbers; same source → one invoice; no `statutory_invoice` journals. Skips unless disposable `INVENTORY_POS_MYSQL_*` harness |
| `tests/Feature/StatutoryInvoice/StatutoryInvoiceAuthorizationTest.php` | Accountant GET/CSV; 405 on POST/DELETE invoices; hardware POS 403 |
| `tests/Feature/StatutoryInvoice/AccountantMonthEndReportTest.php` | Date-filtered register/lines/GST/sales/collections/cancelled/channel-orders |
| `tests/Feature/Inventory/PosSaleServiceTest.php` | `auto_issue_on_pos_complete=true` fails closed; complete does not mint |

`mint()` is **not** called from HTTP controllers or POS complete. Production callers of mint: **none** in `app/` besides the service itself. POS complete comments that statutory mint is a later explicit call.

---

## 3. Current channel-ingest engine

### 3.1 Endpoint and auth (A)

| Item | Value |
|------|-------|
| Route | `POST /api/v1/channel-orders` (`routes/api.php` → `ChannelOrderIngestController::store`) |
| Name | `api.v1.channel-orders.store` |
| Session auth | **None.** HMAC only |
| Headers | `X-Desk-Channel`, `X-Desk-Timestamp` (unix seconds), `X-Desk-Signature` (hex HMAC-SHA256 of `{timestamp}{rawBody}`) |
| Optional | `Idempotency-Key` **must equal** `statutory:{channel}:{source_type}:{source_id}` |
| Secrets | Per-channel env; empty → 401. Must not reuse `DESK_ORDER_API_TOKEN` / Cashfree / BonVoice (documented) |
| Replay window | `CHANNEL_INGEST_REPLAY_WINDOW_SECONDS` default 300 |
| Desk POS | Rejected as HTTP channel (401) |
| Payload vs header | Payload `channel` must match authenticated channel |

Secrets (empty in `.env.example`):

| Channel | Env |
|---------|-----|
| rdservice.in | `CHANNEL_INGEST_SECRET_RDSERVICE_IN` |
| radiumbox.com | `CHANNEL_INGEST_SECRET_RADIUMBOX_COM` |
| rdservice.net | `CHANNEL_INGEST_SECRET_RDSERVICE_NET` |
| radiumsign.com | `CHANNEL_INGEST_SECRET_RADIUMSIGN_COM` |
| future | `CHANNEL_INGEST_SECRET_FUTURE` |

### 3.2 Stored fields (A)

Tables: `commerce_orders`, `commerce_order_items`, `channel_ingest_attempts` (`database/migrations/2026_09_01_180000_create_channel_order_ingest_tables.php`).

| Field | Stored? |
|-------|---------|
| `source_system` | **No column of that name.** Channel enum stored as `commerce_orders.channel` |
| `source_order_id` | Yes, nullable; also unique business key `(channel, source_type, source_id)` |
| Payment reference / provider / method | Yes, nullable |
| Customer/buyer | Name, phone, email, GSTIN, billing/shipping address |
| Line items | Description, qty, unit price, optional SKU/variant/HSN/SAC/tax split |
| Tax information | Stored if sent; **not invented** |
| Invoice eligibility | `invoice_eligible` boolean + `status_reason` |
| `statutory_invoice_id` | Column exists; ingest **asserts it stays null** |

Status written by ingest: `validated` (accepted, not eligible) or `invoice_pending` (eligible, not minted). Enum also has `received` / `eligible` / `invoiced` / `rejected` / `failed` — ingest does not write `invoiced`.

Eligibility (all required; missing fields listed in `status_reason`):

1. `payment_status === paid`
2. seller GSTIN present
3. place of supply present
4. HSN/SAC on **every** line

CGST/SGST/IGST are **not** required for eligibility.

### 3.3 Idempotency / retry (A)

- Same source + same payload hash → **200 duplicate**, same `order_no`
- Same source + different payload → **409 conflict**
- New source → **201**
- Unique `(channel, source_type, source_id)` and unique `idempotency_key`
- UniqueConstraint race returns the existing order if hash matches
- Outer transaction rollback rolls back the commerce order (tested)
- Client retries with a **new timestamp** (new HMAC) and same body
- Attempt log: `channel_ingest_attempts` (accepted / duplicate / rejected / unauthorized / replay / conflict / failed)

### 3.4 Does ingest mint or post journals?

**No.** `ChannelIngestService` never calls `StatutoryInvoiceService`. `assertMustNotAutoMint()` throws if `channel_ingest.auto_issue_invoice` is true. `assertNoFinanceOrInvoiceSideEffects` counts `statutory_invoices` for that source and `finance_journals` with `source_type=commerce_order` and throws if either exists.

`CHANNEL_INGEST_CUTOVER_APPROVED` is **config-only**. It is **not read** by `ChannelIngestService` (only set false in tests). VERIFIED unused.

### 3.5 Production enablement of ingest

| Check | Result |
|-------|--------|
| Source on `main` / `v4.0.64` | No |
| Secrets in `.env.example` | Empty → 401 if deployed as-is |
| Auto-issue | Hard false |
| Tests | `ChannelOrderIngestTest`, `ChannelIngestAuthenticatorTest`, `ChannelIngestMysqlConcurrencyTest` |

---

## 4. Numbering architecture

### 4.1 Desk statutory (A, fail-closed)

```
sequence_key = document_type|series_code|gstin_or_*|fy_or_*
  → ensure row exists (create current_value=0; unique key recovers races)
  → SELECT … FOR UPDATE that row
  → current_value + 1
  → format template
  → insert invoice_sequence_allocations (unique allocated_number, unique idempotency_key)
  → insert statutory_invoices (unique invoice_number, unique idempotency_key)
```

Rules implemented:

- Unset series **or** unset format → ValidationException (no row, no increment)
- `{gstin}` / `{fy}` in template with empty config → refuse
- POS internal pattern rejected as statutory number
- Allocation `allocated_number` / `seq_int` / `sequence_id` / `idempotency_key` immutable; `invoice_id` may be filled after insert
- Cancelled invoices keep `invoice_number`

**Not chosen (D):** legal prefix, whether series is per GSTIN, whether FY resets, credit-note series, continuation of Admin `INV67` / `IND67` / `INS`.

### 4.2 Desk POS internal (A, live on this branch only)

`INV-{branchCode}-{calendarYear}-{5-digit seq}` via `inventory_branches.invoice_sequence` `lockForUpdate` inside `PosSaleService::completeSale`. Unique `inventory_sales.invoice_number`. **Not** GST. Calendar year in the string; sequence does **not** reset on 1 Jan (prior VERIFIED).

### 4.3 Admin (sibling, not this repo)

`rd_slug` + `rd_no+1` for `ordertype=rdservice` **and** `radiumsign`; shared counter; **no** `lockForUpdate`; GET allocate; `previous_invoice=1` can mint a second number. VERIFIED in Admin-P-01-09-01. Live `rd_no` values **UNKNOWN** (not queried).

---

## 5. GST / tax architecture

| Layer | Behaviour | Class |
|-------|-----------|-------|
| POS sale | Exclusive `unit_price` × qty − line discount; `tax = round(taxable × gst% / 100, 2)`; single `tax` bucket; header discount after tax (₹100+18%−₹10 = ₹108, tax still ₹18) | A, VERIFIED prior POS tests |
| Statutory mint | Sums `taxable_value`, `tax_total`, `line_total`; CGST/SGST/IGST **only if every provided line has that field**; otherwise header CGST/SGST/IGST stay **null**. Rounding stored as `0`. No place-of-supply vs seller-GSTIN comparison | A |
| Channel ingest | Does not calculate tax. Stores supplied numbers; missing tax → still accepted, not eligible if HSN/seller GSTIN/POS missing | A |
| Reports | GST CSV/summary uses classified split when present; unclassified tax is a **separate** column; cancelled invoices excluded from GST totals | A |
| CGST/SGST/IGST **engine** | **Absent** | B |
| TCS / cess / shipping 18% reverse GST | **Absent** (deliberately not cloned) | D whether required |
| Seller GSTIN completeness | Branch `gstin` optional; Bihar clone of Delhi is a **data** defect from prior Admin snapshot | D / prior VERIFIED snapshot |

There is **no** class that decides intra- vs inter-state supply. That remains D.

---

## 6. Finance integration

### 6.1 What posts journals today (A)

| Event | Service | Journal | GST payable? |
|-------|---------|---------|--------------|
| POS complete | `PosSaleJournalService::postForSale` fail-closed | Dr 1000 cash or 1100 bank / Cr 4000 revenue `pos_sale:{sale_id}` | No — tax is inside 4000 |
| POS cancel/return | `PosSaleJournalService::reverseForSale` | `pos_sale:reverse:{sale}:{journal}` Cash Book pattern | No |
| Cashfree paid support order | `OrderPaymentJournalService` | Dr 1100 / Cr 4000 `order_payment:{order_id}` | No |
| Statutory mint | **Forbidden** | `finance_journal_id` forced null; asserts zero `source_type=statutory_invoice` journals | n/a |
| Channel ingest | **Forbidden** | Asserts zero `source_type=commerce_order` journals | n/a |
| Statutory cancel | **No journal** | Status only | n/a |
| GST credit note | **None** | | n/a |

Chart of accounts (`FinanceChartOfAccountsSeeder`): `1000` cash, `1100` bank clearing, `4000` sales. **No** CGST/SGST/IGST payable codes.

### 6.2 Double-count risk

If invoice GL were enabled **while** POS/`order_payment` still credit 4000 for the same supply, revenue (and tax-in-income) would **double**. The foundation **refuses mint** when `post_finance_journals` is true rather than posting. That is a guard, not a recognition policy.

Recognition on collection vs on invoice remains **D**. Until that is decided, enabling invoice journals is a technical blocker.

Statutory cancel does **not** reverse the POS `pos_sale` journal. POS cancel does **not** create a GST credit note. Those are separate paths.

---

## 7. IRN / GSP architecture

| Piece | Status |
|-------|--------|
| `e_invoice_records` table | A — unique `invoice_id`, unique nullable `irn` |
| `EInvoiceGateway` | A — `submit` / `cancel` |
| Bound implementation | `NullEInvoiceGateway` only (`AppServiceProvider`) |
| Called on mint | **No** |
| Called on statutory cancel | **No** |
| NIC JSON builder | **No** |
| media.radiumbox.com client | **No** (explicitly not hard-wired) |
| GSP credentials in Desk env | **None** in `.env.example` |
| Production GSP | UNKNOWN / not configured in this repo |

Admin IRN remains `GET https://media.radiumbox.com/api/einvoice/{id}/{branch}/einvoice` when GSTIN + `envoice=1` (sibling VERIFIED). That is **not** Desk.

---

## 8. Idempotency / concurrency safety

| Control | Present? | Evidence |
|---------|----------|----------|
| DB unique invoice number | Yes | `statutory_invoices.invoice_number` unique |
| DB unique allocation number | Yes | `invoice_sequence_allocations.allocated_number` unique |
| DB unique mint key | Yes | `idempotency_key` unique on invoices **and** allocations |
| Unique source | Yes | `(channel, source_type, source_id)` |
| Unique POS link | Yes | `statutory_invoices.inventory_sale_id` unique |
| `lockForUpdate` on existing sequence | Yes | `StatutoryInvoiceNumberingService::allocate` |
| No `FOR UPDATE` on missing sequence | Yes | Create first; unique recovers | Lesson from POS P-01-09-09 |
| Idempotent re-mint | Yes | Return existing; UniqueConstraint fallback |
| Retry after success | Safe | Same key returns same number; sequence not incremented again (sqlite tests) |
| Failed outer txn | Rolls back allocation | `test_failed_outer_transaction_rolls_back_number_allocation` |
| Failed format after increment | Same DB txn → rollback | INFERRED from wrapping transaction |
| Two-process MySQL mint | Test exists | Skips without disposable MariaDB harness — result on this machine **UNKNOWN** unless harness is up |
| Two-process ingest | Test exists | Same skip gate; asserts **one** order and **zero** invoices |
| Draft vs issued | Draft **not implemented** — allocate and insert issued in one mint |

Gaps (not fatal for a disabled engine, blockers for go-live):

- No UUID issuance key distinct from the string idempotency key (sibling contracts mention document UUID).
- `CHANNEL_INGEST_CUTOVER_APPROVED` unused.
- Mint does not require HSN / seller GSTIN / place of supply (ingest eligibility is stricter than mint).
- Concurrent distinct sources: designed to serialize on the sequence row; MySQL proof is harness-gated.

---

## 9. Admin dual-issuer risk

From Admin Gate 0 (`/Users/ravi/RadiumWebsites/Admin/docs/ADMIN-GATE-0-RDSERVICE-RADIUMSIGN-INVOICE-AUDIT.md`, Admin-P-01-09-01). **Not re-executed. Admin not modified.**

Admin **source can allocate** statutory numbers for:

- `orders.ordertype=rdservice`
- `orders.ordertype=radiumsign`

Both consume **`radium_branch.rd_no`** (plus `rd_slug` prefix).

Whether Admin is the **live** issuer for every new rdservice.net / radiumsign.com checkout is **UNKNOWN** (Admin DB vs storefront DBs not proven this ticket). Capability in source is **VERIFIED**.

If Desk (or any central engine) starts `next()` **while** any of the following still allocate for the same supply, that is a **dual-issuer incident**.

### 9.1 Required Admin disable / prevention boundary (identify only — do not disable)

Must prevent for **new** rdservice.net / radiumsign supplies before any central `next()`:

1. `App\Helper\RdServiceInvoice::genrate` (RD list HTTP + `ProcessRdData`)
2. `ProcessRdData` drain of existing `jobs` (would mass-allocate)
3. `OrderStatusController::Invoice` on Completed (RD, null GSTIN)
4. `RdserviceStatusController::ChangeStatus` `rd==1`
5. `GenrateInvoice::Invoice` GET for `ordertype=rdservice` **or** `radiumsign`, including `previous_invoice=1`
6. `CreditNoteController::Store` for those ordertypes (CRN allocate)
7. Keep storefront `PaidOrderController::Invoice` (INS) dead; do not enable rdservice.net WIP `IND07`

Print/PDF GET may remain as **readers** of already-issued numbers.

**Channel-specific gate is required:** auto paths (1)–(4) do **not** filter `order_rdservice.website`. A global kill also stops auto-invoice for rdservice.in / radiumbox.com RD rows on the same Admin tables. Exact filter design is **B**, not implemented, not this repo.

Desk must **not** call Admin `GenrateInvoice`. Current Desk source does not.

### 9.2 Shared counter implication

rdservice + radiumsign share one `rd_no`. Freezing the counter for one channel without the other still leaves the other channel able to increment the **same** series. Cutover of “Desk becomes issuer for both” vs “only net” vs “only Sign” is **D**.

---

## 10. Historical / cutover handling

**Desk has no historical import** (A = missing). No registration of Admin `invoice.invoice` / `orders.invoicecode` into `statutory_invoices`.

| Scenario | Desk today | Required before Desk (or any central engine) is issuer |
|----------|------------|--------------------------------------------------------|
| Already-invoiced (`invoicecode` set) | Not in Desk | Import/register **as-is**; **no** `next()`; quarantine if `invoicecode` ≠ `invoice.invoice` or duplicate strings |
| Paid + uninvoiced | Not in Desk (except support `orders` for Cashfree path, which is **not** a GST document) | Policy D: issue-on-payment vs dispatch; then **first** issue via the chosen engine only, with Admin generate already off |
| Refunded / cancelled without INV | n/a | No INV; if INV existed, CRN from the chosen engine only |
| POS `INV-*` | Internal receipts on this branch | Must never be imported as GST numbers |
| Cutover timestamp | `CHANNEL_INGEST_CUTOVER_APPROVED` unused | Logical freeze: Admin allocate off for channel → drain paid+null → enable handoff → never dual-run |
| Channel handoff | HMAC ingest exists; no site posts to it | After idempotent paid fulfill, POST `{channel, source_type, source_id, lines, parties, totals}` with matching Idempotency-Key. Sites remain local cart/payment. **Other repos — not this ticket** |
| Duplicate protection | Desk unique source key **after** ingest; does not know Admin `rd_no` | Freeze Admin increment for that channel; unique `(source_system, source_order_id)` at the chosen engine; never enable IND07 |

Paid-but-uninvoiced **counts** on Admin / rdservice_net_prod / radiumsign_prod: **UNKNOWN** this ticket (not queried). Prior sibling snapshots reported paid rows with null `invoicecode` — INFERRED still a live ops gap.

---

## 11. Production enablement state

From **repository documentation and Git only**. Production DB was **not** queried. No in-repo documented safe read-only Desk production SQL path was used.

| Question | Finding | Class |
|----------|---------|-------|
| Statutory tables deployed to production | Feature branch **not** on `origin/main`; `v4.0.64` predates migrations. **INFERRED not deployed.** Cannot prove a rogue migrate | INFERRED / UNKNOWN live DB |
| Migrations deployed | Same | INFERRED no |
| Channel ingest enabled | Secrets empty in example; ingest code not on `main`; empty secret → 401 | VERIFIED source; INFERRED prod |
| Production invoice issuance | Series/format empty; mint throws; POS never auto-mints; ingest never mints | VERIFIED source fail-closed |
| Production sequence configuration | `.env.example` empty; no committed production series | VERIFIED unset in repo. Live `.env` on KVM **UNKNOWN** (not read) |
| Production GSP configuration | No GSP env keys | VERIFIED absent in repo |
| Accountant role in production | Seeder exists on this branch only | INFERRED not in v4.0.64 |

`C — Production-enabled` is **false** for mint, ingest, IRN, invoice GL, and (INFERRED) schema.

---

## 12. VERIFIED / INFERRED / UNKNOWN matrix

| Finding | Class |
|---------|-------|
| Desk implements mint + numbering + unique constraints + fail-closed flags | VERIFIED |
| Ingest stores commerce orders without minting or journals | VERIFIED |
| Legal series unset in repo config | VERIFIED |
| POS complete does not mint statutory invoices | VERIFIED |
| Statutory mint does not post GL | VERIFIED |
| Null GSP is the only bound adapter; not invoked | VERIFIED |
| Credit-note **documents** not implemented | VERIFIED |
| CGST/SGST/IGST not calculated | VERIFIED |
| `cutover_approved` unused by ingest service | VERIFIED |
| Statutory/ingest commits not on `origin/main` | VERIFIED |
| Production schema lacks these tables | INFERRED (git); live DB UNKNOWN |
| Admin can allocate for rdservice + radiumsign on shared `rd_no` | VERIFIED sibling Admin-P-01-09-01 |
| Admin is live issuer for every new net/Sign checkout | UNKNOWN (sibling) |
| Live `rd_no` / GSTIN / paid-uninvoiced counts | UNKNOWN |
| MySQL two-process mint/ingest on this host | UNKNOWN unless harness env is set (tests skip) |
| Desk vs Finance-service placement | UNKNOWN / unresolved docs |
| Meaning of Admin prefix `67` | UNKNOWN |
| Correct Bihar GSTIN | UNKNOWN (prior snapshot copies Delhi) |
| GSP provider for a future engine | UNKNOWN |

Four-way map (A/B/C/D) for the engine as a whole:

| | A source | B planned | C production | D CA/business |
|-|----------|-----------|--------------|---------------|
| Mint engine | Yes, fail-closed | Draft, CN, PDF, IRN, tax GL | No | Series, FY, GSTIN scope |
| Channel ingest | Yes, fail-closed | Site clients, callbacks, auto-mint | No | Cutover date, payload completeness |
| GST split | Columns | Split engine | No | Place of supply, rounding, TCS |
| Finance | Guards against invoice GL | Invoice journals + tax accounts | POS/Cashfree collection journals only (this branch) | Recognition timing |
| IRN | Table + null adapter | Real GSP | No | Provider, B2C threshold |
| Historical | — | Import design in docs | No | What to import vs leave |

---

## 13. CA / business decisions still required

Code cannot safely decide:

1. **Who is the statutory issuer** — Desk vs a separate Finance service (do not resolve in implementation tickets until owner/architecture chooses).
2. **Legal invoice prefix/series** (do not copy Admin `INV67`/`IND67`/`INS` or POS `INV-{branch}-…` without CA).
3. **FY reset** vs continuous numbering; FY token string.
4. **Per-GSTIN series** vs one pan-India series (`gstin_scope`).
5. **Invoice-on-payment vs dispatch/fulfillment/Completed** (eligibility timing).
6. **Revenue recognition** — keep collection journals vs move to invoice; how to avoid double-count.
7. **Credit-note numbering** and whether debit notes are used.
8. **GSP / IRN policy** (provider, B2B vs B2C threshold, cancel IRN).
9. **Seller GSTIN mapping** per location (especially Bihar vs Delhi clone; warehouse empty GSTIN).
10. **Legal name, registered address, signatory** for PDF / NIC SellerDtls.
11. **Place of supply** for goods vs RD digital service vs POS walk-in.
12. **Rounding and header-discount presentation** on the GST invoice.
13. **Historical paid-without-invoice treatment** — issue now vs leave; who issues.
14. **Historical Admin invoice import** into the chosen engine (read-only registration).
15. **Cutover date** and which channels move together (net / Sign / in / box / POS).
16. **Shipping tax / TCS / cess**.
17. **UPI vs bank clearing** GL.
18. **Accountant identity / MFA**.
19. **Whether INS historical strings are statutory or internal**.
20. **Timezone for tax period** (app timezone vs IST).

Do not invent answers for these.

---

## 14. Exact technical blockers

Blockers **before multiple channels can safely use a Desk mint engine** — listed as facts, **not** as a decision that Desk should be the issuer:

1. **Placement unresolved** — enabling Desk mint while a Finance service is also intended would create two central issuers.
2. **Admin allocate still capable** for rdservice + radiumsign on shared `rd_no`; no channel-specific disable in Admin (other repo).
3. **Legal series/format unset** — `StatutoryInvoiceNumberingService::isConfigured()` is false; mint throws.
4. **No GST split / place-of-supply engine** — mint can issue unclassified tax; POS issue leaves CGST/SGST/IGST null.
5. **Mint eligibility weaker than ingest** — mint does not require paid / seller GSTIN / HSN / place of supply.
6. **No credit-note document path** — cancel is status-only; Admin CRN still the only GST CN writer (sibling).
7. **No statutory PDF**.
8. **No real GSP**; IRN never submitted.
9. **Invoice GL forbidden** until recognition policy; POS still posts gross to 4000.
10. **No historical register** — CA cannot close a mixed Admin/Desk month from Desk.
11. **Ingest not on production** and secrets empty; sites do not POST this API (other repos).
12. **`cutover_approved` unused** — no code-level cutover gate beyond empty secrets + auto-issue false.
13. **Schema not on `main`** — production migrate would be a separate, explicit release after changelog/CA.
14. **Commerce payments / allocations missing** — payment reference is a string, not a reconcilable payment entity.
15. **Draft invoices not implemented** — any successful mint consumes a number immediately.

None of these were “fixed” in this ticket.

---

## 15. Recommended next investigation

Investigation only. Do not implement, migrate, mint, disable Admin, or deploy.

1. **Owner/architecture decision record** — Desk mint vs separate Finance tax-document service. Capture a single SoT so channel tickets stop citing both. Do not code until written.
2. **If a documented safe read-only Admin production SELECT exists:** counts of `ordertype=rdservice` / `radiumsign`; `invoicecode` null vs set; whether net/Sign rows live on Admin’s DB; unique indexes on `invoice.invoice`; **do not** copy `rd_no` values into git; **do not** open `/admin/home` or RD list in a way that triggers `RdServiceInvoice::genrate`.
3. **Channel-specific Admin disable design** (Admin repo, still no implement) for Gate 0 §10, including `website=rdservice.net` vs global RD auto.
4. **CA numbering workshop** using this audit’s §13 list — series, FY, GSTIN scope, CN, recognition, place of supply.
5. **Paid-uninvoiced inventory** (counts + policy) once a safe DB path exists — not “issue them from Desk.”
6. **Handoff contract alignment** — Desk ingest payload vs sibling `{source_system, source_order_id, document UUID}` so a future issuer (Desk or Finance) has one key.

---

## Safety record (P-01-09-18)

| Action | Done? |
|--------|-------|
| Modify application code | No |
| Modify production DB | No |
| Run migrations | No |
| Generate invoices / allocate numbers / increment sequences | No |
| Issue credit notes | No |
| Enable channel ingest / production invoicing | No |
| Deploy / push / DNS / secrets | No |
| Modify Admin, RadiumSign, rdservice.net, rdservice.in, other projects | No (read-only citation) |
| Query production | No |
| Commit secrets | No |

---

## References

- `docs/rd-central-finance-invoice-architecture.md` (P-01-09-14–17)
- `docs/rdservice-successful-orders-invoice-path-investigation.md` (P-31-08-14)
- `docs/rd-fresh-01-pos-finance-gap-audit.md`
- Admin Gate 0: `/Users/ravi/RadiumWebsites/Admin/docs/ADMIN-GATE-0-RDSERVICE-RADIUMSIGN-INVOICE-AUDIT.md`
- rdservice.net: `/Users/ravi/RadiumWebsites/rdservice.net/docs/RDSERVICE-NET-INVOICE-TO-CENTRAL-ENGINE-INVESTIGATION.md`
- Canvas: [`central-statutory-engine-readiness-audit.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/central-statutory-engine-readiness-audit.canvas.tsx)
