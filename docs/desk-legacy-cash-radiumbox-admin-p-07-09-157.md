# Legacy Cash — RadiumBox Admin (RadiumDesk-P-07-09-157)

**Date:** 2026-09-09  
**Branch:** `feat/legacy-cash-radiumbox-admin`  
**Status:** Implemented in Desk. Production import, opening journal, and deploy were **not** performed in this prompt.

Historical RadiumBox Admin cash is preserved as a separate, read-only **Legacy Cash** dataset. The live Desk Cash Book and GL do not receive the 1,765 historical rows. The approved closing balance becomes one identifiable operational opening journal.

---

## Source / accounting decision

| Item | Value |
|------|--------|
| Historical source | `radiumbox_prod.expenses` (SELECT-only) |
| Cutoff | **2026-09-04 21:25:51 IST** |
| Surviving rows | **1,765** |
| Credits | **675** / **₹1,66,47,589** |
| Debits | **1,090** / **₹1,62,96,575** |
| Historical net / operational opening | **₹3,51,014** |
| Hard-deleted IDs (not fabricated) | `1, 2, 22, 23, 24, 45, 123, 201` |

Do not silently classify, reverse, or reinterpret historical transactions. Four large debits (`157`, `876`, `999`, `1110`) remain facts and are flagged `needs_review` / UNKNOWN.

`orders_payment`, Admin invoices, wallets, and the 516k Admin `users` table are out of scope.

---

## Storage

Tables:

- `finance_legacy_cash_entries` — immutable historical rows
- `finance_legacy_cash_user_maps` — `old_admin_user_id → desk_user_id` (nullable)

Idempotency key:

`legacy:radiumbox_prod:expenses:{id}`

Each row preserves legacy source, database, table, original `expenses.id`, timestamps, amount (raw + decimal), credit/debit, `amount_type`, description, original Admin `created_by` and name, mapped Desk user when approved, import timestamp, import/review status.

---

## User mapping

Numeric Admin IDs are **not** treated as Desk IDs.

Approved mappings (resolved by unique Desk `users.name`, not hardcoded IDs):

| Old Admin ID | Old Admin name | Desk match rule | Production Desk ID (verified 2026-09-09, SELECT-only) |
|--------------|----------------|-----------------|------------------------------------------------------|
| 4 | Gunjan Kumar | exact `Gunjan Kumar` | **18** (inactive employee) |
| 6 | Rafaquat Rana | unique `Rafaquat` or `Rafaquat …` | **19** (`Rafaquat`, active employee) |

Do not create duplicate Desk users. If the name match is missing or ambiguous, the row stays **unmapped**.

Unmapped historical Admin cash users (preserve ID/name only): admin (1), Sushant (8), Avinash (10), Shipra (13), Dileep (14), and any other `created_by` present in source.

---

## Import

Artisan (default dry-run):

```bash
php artisan finance:import-legacy-cash
php artisan finance:import-legacy-cash --apply --connection=legacy_radiumbox
php artisan finance:import-legacy-cash --apply --post-opening --actor=EMAIL_OR_ID
```

Live SELECT uses the optional `legacy_radiumbox` connection (`LEGACY_RADIUMBOX_DB_*` in `.env`, documented in `.env.example`). Prefer a SELECT-only database user. Credentials are never printed by the command or UI.

`--json=` is for tests / offline fixtures. It does not replace the approved production source.

Reruns skip existing keys. Historical facts are not overwritten. Source `radiumbox_prod.expenses` is never updated or deleted.

---

## Opening balance

Existing `OpeningBalanceService` posts one journal:

- Amount: **₹3,51,014**
- Memo: **Opening balance — RadiumBox Admin legacy cash**
- Idempotency: `legacy:radiumbox_prod:opening:351014`
- Date: `2026-09-04`
- Dr GL 1000 / Cr GL 3000
- Not a Cash Book income row
- Does not use `opening:cash:{cashAccountId}`

The 1,765 Legacy Cash rows do not create journals. Operational Desk cash after cutover is the opening journal plus later Desk Finance/Cash Book activity.

**Separation:** Legacy Cash history ≠ operational Cash Book activity. Cash Ledger (GL) will show the opening journal. Cash Book `SUM(cash_book_entries)` will not include the opening journal by design.

Existing unrelated GL / Cash Book rows are not rewritten.

---

## UI

Finance tab **Legacy Cash** → **Legacy Cash — RadiumBox Admin**.

- Permission: `finance.legacy_cash.view` (granted with `finance.view`)
- GET-only
- Filters: search, type, review, mapped/unmapped, category, date range
- No credentials, no connection strings, no write actions

---

## Reconciliation result (contract)

Importer/tests assert:

- row count = 1,765
- credits = 675 / ₹1,66,47,589
- debits = 1,090 / ₹1,62,96,575
- net = ₹3,51,014
- excluded IDs absent
- rerun creates zero duplicates
- import creates zero Cash Book rows and zero per-expense journals

Production import against KVM `radiumbox_prod` was **not** executed in this prompt (no deploy).

---

## Rollback

1. Do not deploy this branch until the production import gate.
2. After a future import: delete `finance_legacy_cash_entries` / maps by source, and reverse or leave the single opening journal only if accounting approves. Do not touch `radiumbox_prod.expenses`.
3. Unique idempotency keys make a clean re-import possible after a table wipe.

---

## Remaining gate

Deploy, then run dry-run SELECT from `radiumbox_prod.expenses` on the Desk host, then `--apply --post-opening` with a named actor. Confirm production Desk user IDs 18/19 still match Gunjan Kumar / Rafaquat before apply.
