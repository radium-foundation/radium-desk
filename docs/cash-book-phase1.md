# Cash Book Phase 1 — Implementation Report

**Date:** 2026-08-03  
**Scope:** Operations Cash Book Phase 1 only  
**Canvas:** [`cash-book-phase1.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/cash-book-phase1.canvas.tsx)

No Phase 2. No attachments. No handover. No Finance redesign.

---

## 1. Root cause

The Finance module already had a production-capable double-entry engine (`JournalPostingService`), cash GL (`1000`), and revenue/expense accounts — but **no simple operational UI** for every team member to record day-to-day cash income and expense.

Finance workspace access is limited (`finance.view` → Admin / Operations Admin / Super Admin). Floor staff (Employee, Agent) could not record tea, courier, walk-in cash sales, etc. The Finance “Daily Closing” tab was a placeholder; Cash Ledger was a read-only GL movement list — not a Cash Book.

**Gap filled:** an Operations Cash Book that posts through the existing ledger foundation without duplicating accounting logic.

---

## 2. Files changed

### New

| Path | Role |
|------|------|
| `app/Enums/CashBookEntryType.php` | income / expense |
| `app/Enums/CashBookIncomeSource.php` | Income sources |
| `app/Enums/CashBookExpenseCategory.php` | Expense categories + GL code map |
| `app/Models/CashBookEntry.php` | Entry model (soft deletes) |
| `app/Services/CashBook/CashBookEntryService.php` | Create / update / delete + journal post/reverse |
| `app/Services/CashBook/CashBookSummaryService.php` | Dashboard totals + Cash In Hand |
| `app/Services/CashBook/CashBookReferenceService.php` | `CB-YYYY-######` numbers |
| `app/Support/CashBook/CashBookAccess.php` | Permission helpers |
| `app/Policies/CashBookEntryPolicy.php` | view / create / update / delete |
| `app/Http/Controllers/CashBook/CashBookController.php` | Index, create, store, edit, update, destroy |
| `app/Http/Requests/CashBook/StoreCashBookEntryRequest.php` | Validation + authorize create |
| `app/Http/Requests/CashBook/UpdateCashBookEntryRequest.php` | Validation + authorize manage |
| `database/migrations/2026_08_03_170000_create_cash_book_entries_table.php` | `cash_book_entries` |
| `resources/views/cash-book/index.blade.php` | Dashboard cards + ledger |
| `resources/views/cash-book/create.blade.php` | Add Entry |
| `resources/views/cash-book/edit.blade.php` | Admin edit |
| `resources/views/cash-book/partials/form.blade.php` | Shared form (Income/Expense) |
| `tests/Feature/CashBook/CashBookPhase1Test.php` | Feature coverage |
| `docs/cash-book-phase1.md` | This report |
| Canvas `cash-book-phase1.canvas.tsx` | Interactive twin |

### Modified

| Path | Change |
|------|--------|
| `app/Enums/FinanceJournalSourceType.php` | Added `CashBook` |
| `database/seeders/RolePermissionSeeder.php` | `cashbook.view` / `create` / `manage` |
| `routes/web.php` | `/cash-book/*` routes |
| `app/Support/Navigation/NavigationContextResolver.php` | Operations → Cash Book sidebar item |

---

## 3. Database usage

### Table: `cash_book_entries`

| Column | Purpose |
|--------|---------|
| `entry_no` | Unique `CB-YYYY-######` |
| `type` | `income` \| `expense` |
| `amount` | Decimal(14,2) |
| `category` | Income source or expense category value |
| `remark` | Free text |
| `entry_date` | Editable date |
| `created_by` | Received By / Paid By (auto) |
| `updated_by` | Admin edit actor |
| `journal_id` | FK → `finance_journals` (nullable if cutover skips) |
| `deleted_by` / `deleted_at` | Soft delete |

### Existing Finance tables (unchanged schema)

- `finance_journals` / `finance_journal_lines` — posted via `JournalPostingService`
- `finance_accounts` — cash `1000`, revenue `4000`, expense `6001`/`6002`/`6005`/`6099`
- `finance_settings` — default cash + revenue codes, cutover gate

### Cash In Hand (Phase 1)

```
Cash In Hand = All Income − All Expense − Cash Handed Over + Cash Received Back
```

Handed Over / Received Back are **always 0** in Phase 1 (reserved for Phase 2). Soft-deleted entries are excluded.

---

## 4. Before / after flow

### Before

```
Floor staff → no place to record cash in/out
Finance → stubs / admin-only expenses
GL engine → unused for walk-in cash ops
```

### After

```
Any authenticated team member
    ↓
Operations → Cash Book → + Add Entry
    ↓
Income or Expense form
    ↓
CashBookEntryService::create()
    ↓
JournalPostingService::post()   ← existing foundation
    ↓
finance_journals + lines
    ↓
Dashboard cards + newest-first ledger
```

**Income journal:** Dr Cash (`1000`) / Cr Sales Income (`4000`)  
**Expense journal:** Dr mapped expense GL / Cr Cash (`1000`)

**Admin edit:** reverse prior journal → update entry → post new journal  
**Admin delete:** reverse prior journal → soft delete

---

## 5. Why production safe

1. **No Finance redesign** — separate Operations module; Finance tabs untouched.  
2. **Reuses `JournalPostingService`** — balance checks, idempotency keys, lockForUpdate.  
3. **Idempotency** — `cashbook:{id}` on create; `cashbook:reverse:{id}:{journalId}` on reverse.  
4. **Soft delete + reversing journals** — no hard-delete of posted lines; audit trail preserved.  
5. **Cutover respected** — `shouldPostForDate()` can skip GL while still saving the ops entry.  
6. **Least privilege for mutations** — everyone creates/views; only Admin + Super Admin edit/delete (`cashbook.manage`). Operations Admin does **not** get manage.  
7. **Validation** — amount &gt; 0, type-specific category enums, required remark/date.  
8. **Phase 1 exclusions honored** — no attachments, GST, wallet, settlement, reports, bank transfer, handover, approval, recurring.

---

## 6. Tests

File: `tests/Feature/CashBook/CashBookPhase1Test.php` — **7 passed**

| Test | Covers |
|------|--------|
| `test_agent_can_create_income_entry_and_journal_posts` | Income entry + balanced Cash Book journal |
| `test_employee_can_create_expense_entry_and_journal_posts` | Expense entry + journal |
| `test_cash_in_hand_calculation` | Today’s totals + Cash In Hand formula |
| `test_permission_matrix_view_create_manage` | Agent/Employee view+create; Agent forbidden edit/delete; Admin edit+delete |
| `test_validation_rejects_invalid_payload` | amount/category/remark/date |
| `test_admin_edit_reverses_and_reposts_journal` | Reverse + new journal |
| `test_cash_book_appears_in_sidebar_for_employee` | Nav visibility |

---

## Permissions matrix

| Role | View | Create | Edit/Delete |
|------|:----:|:------:|:-----------:|
| Employee | ✓ | ✓ | ✗ |
| Agent (+ other support roles) | ✓ | ✓ | ✗ |
| Operations Admin | ✓ | ✓ | ✗ |
| Admin | ✓ | ✓ | ✓ |
| Super Admin | ✓ | ✓ | ✓ |

Permissions: `cashbook.view`, `cashbook.create`, `cashbook.manage`

---

## UI fields (Phase 1)

**Dashboard cards:** Today's Income · Today's Expense · Cash In Hand  

**Add Entry:** Type (Income/Expense) → Amount · Source/Category · Remark · Received/Paid By (auto) · Date (auto, editable)

**Ledger columns:** Time · Type · Amount · Category · Remark · Created By · (Admin Actions)

---

## STOP

Phase 1 complete. No Phase 2. No attachments. No handover.
