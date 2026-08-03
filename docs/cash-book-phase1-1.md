# Cash Book Phase 1.1 — UX Refinement Report

**Date:** 2026-08-03  
**Scope:** Phase 1.1 UX only — no Phase 2  
**Canvas:** [`cash-book-phase1-1.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/cash-book-phase1-1.canvas.tsx)

No attachments. No cash handover. No accounting redesign.

---

## 1. Root cause

Phase 1 made Cash Book usable for every role, but the form and ledger were not optimized for **sub-10-second entry**:

- “Category” was ambiguous (Income Source vs Expense Category).
- No place to capture who money came from / went to without stuffing Remark.
- “Cash In Hand” naming was less clear for ops than “Available Cash”.
- Ledger had no search or date/type filters, so finding a recent entry was slow.

Phase 1.1 is a **UX refinement** on the existing foundation — same journals, permissions, reverse/soft-delete, idempotency.

---

## 2. Files changed

### New

| Path | Role |
|------|------|
| `database/migrations/2026_08_03_172000_add_person_to_cash_book_entries_table.php` | Nullable `person` column |
| `app/Services/CashBook/CashBookLedgerQuery.php` | Search + period/type filters |
| `tests/Feature/CashBook/CashBookPhase11Test.php` | Phase 1.1 coverage |
| `docs/cash-book-phase1-1.md` | This report |
| Canvas `cash-book-phase1-1.canvas.tsx` | Interactive twin |

### Modified

| Path | Change |
|------|--------|
| `app/Models/CashBookEntry.php` | `person` fillable; field label helpers |
| `app/Services/CashBook/CashBookEntryService.php` | Persist optional person |
| `app/Services/CashBook/CashBookSummaryService.php` | `available_cash` (replaces cash_in_hand key) |
| `app/Http/Controllers/CashBook/CashBookController.php` | Filters → ledger query |
| `app/Http/Requests/CashBook/StoreCashBookEntryRequest.php` | Optional `person` |
| `app/Http/Requests/CashBook/UpdateCashBookEntryRequest.php` | Optional `person` |
| `resources/views/cash-book/index.blade.php` | Available Cash, search, filters, ledger columns |
| `resources/views/cash-book/partials/form.blade.php` | Renamed fields + Received From / Paid To |
| `resources/views/cash-book/create.blade.php` | Shorter copy |
| `tests/Feature/CashBook/CashBookPhase1Test.php` | Assert `available_cash` |

**Unchanged (by design):** `JournalPostingService`, permissions, reverse journals, soft delete, idempotency keys, Finance module UI.

---

## 3. Before / after UX

| Area | Before (1.0) | After (1.1) |
|------|--------------|-------------|
| Income field | Source / Category | **Income Source** |
| Expense field | Category | **Expense Category** |
| Person | Only in Remark | **Received From** / **Paid To** (optional) |
| Actor on form | Received By / Paid By (auto, clutter) | Removed from form; still **Created By** in ledger |
| Dashboard card | Cash In Hand | **Available Cash** |
| Formula | Income − Expense (− handover + back = 0) | Same formula; labels Handed Over / Received Back |
| Ledger | Time, Type, Amount, Category, Remark, Created By | + **Received From / Paid To**; clearer column titles |
| Search | None | Live search: remark, person, category, amount, reference |
| Filters | None | Today / Yesterday / This Week / This Month / Custom + Income / Expense |

### Entry form (kept small)

**Income:** Amount · Income Source · Received From · Remark · Date  
**Expense:** Amount · Expense Category · Paid To · Remark · Date

### Available Cash

```
Available Cash = Income − Expense − Handed Over + Received Back
```

Handed Over / Received Back remain **0** (Phase 2).

---

## 4. Production safety

1. **No accounting redesign** — still posts via `JournalPostingService` only.  
2. **Person is metadata** — does not change debit/credit accounts.  
3. **Permissions unchanged** — view/create for all roles; manage = Admin + Super Admin.  
4. **Reverse + soft delete + idempotency** unchanged.  
5. **Search/filters are read-only query layer** — no new money movement.  
6. **Explicit non-goals** — no bill upload, attachments, handover, bank deposit, settlement, wallet, GST, approval, reports.

---

## 5. Tests

| Suite | Result |
|-------|--------|
| `CashBookPhase1Test` | **7 passed** (regression) |
| `CashBookPhase11Test` | **8 passed** |

### Phase 1.1 coverage

| Test | Covers |
|------|--------|
| Income Received From | Optional person on income |
| Expense Paid To | Optional person on expense |
| Person optional | Empty → null |
| Dashboard Available Cash | Totals + UI labels + person in ledger |
| Live search | Remark, person, category label, amount, reference |
| Period + type filters | Today/yesterday/custom + income/expense |
| Permissions + journal regression | Agent forbidden edit; admin update; balanced journal |
| Create form labels | Income Source / Expense Category / Received From / Paid To |

---

## STOP

Phase 1.1 complete. No Phase 2.
