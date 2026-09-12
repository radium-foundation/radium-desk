# Legacy Cash production import + opening journal — RadiumDesk-P-07-09-279

**Date:** 2026-09-12  
**Implementation overlay:** `03b374526decf9474926b438c49dbf7cc0d8275c` (deployed P-07-09-278)  
**Gate:** Owner-authorized apply + exactly one opening journal.

---

## Fresh backup

| Item | Value |
|------|--------|
| Backup ID | `20260912T110731Z` |
| Outcome | `success` |
| Phase | `cloud_uploaded` |
| `upload.artifacts_verified` | `true` |
| DB artifact SHA-256 | `f8f7b617280e5d1de9bcd9133fd23b88c54c1f5fe04c65c1b6115563a88cb5b5` MATCH |
| Secrets artifact SHA-256 | `5bd0d9618ace278b2a57374c27f08634ba8e6cda17bce76bb8c990e6cf170251` MATCH |

Lock: outer `flock` as `ravi` on `/var/lock/radium-desk-backup.lock` + `BACKUP_SCHEDULE_SKIP_LOCK=true`.

---

## Migration

Path-scoped only:

`php artisan migrate --path=database/migrations/2026_09_09_220000_create_finance_legacy_cash_tables.php --force`

UPI migrations remain **Pending** (unchanged).

---

## Permission

`finance.legacy_cash.view` permission id **99** created; granted to `superadmin`, `admin`, `operations_admin` only. Agents denied.

---

## Source access

Minimum mechanism:

1. `GRANT SELECT` on `radiumbox_prod.expenses` and `radiumbox_prod.admins` to `radium_desk`@`127.0.0.1`
2. `LEGACY_RADIUMBOX_DB_*` env entries (same host/user as Desk DB, database `radiumbox_prod`)

No INSERT/UPDATE/DELETE on source. `radiumbox_prod.expenses` unchanged at **1,765** rows, max id **1773**.

---

## Import + opening

Pre-apply dry-run: **PASS** (exact approved totals).

Apply: `php artisan finance:import-legacy-cash --apply --connection=legacy_radiumbox`

Idempotent rerun: imported **0**, skipped **1765**.

Opening: `php artisan finance:import-legacy-cash --apply --post-opening --actor=1 --connection=legacy_radiumbox`

| Field | Value |
|-------|--------|
| Journal ID | **26845** |
| Journal no | **JRN-2026-26845** |
| Idempotency | `legacy:radiumbox_prod:opening:351014` |
| Memo | Opening balance — RadiumBox Admin legacy cash |
| Amount | ₹351014.00 |
| Entry date | 2026-09-04 |
| Actor | User **1** (Ravi) |
| Posted at | 2026-09-12 16:39:48 IST |
| Dr | GL **1000** Cash on Hand ₹351014.00 |
| Cr | GL **3000** Opening Balance Equity ₹351014.00 |

Per-expense journals (`legacy:radiumbox_prod:expenses:*`): **0**

Cash Book: **1** row unchanged (`CB-2026-000001` ₹50.00, `updated_at` 2026-08-25 16:18:08).

---

## Mappings

| Legacy Admin | Desk | Rows |
|--------------|------|------|
| 6 Rafaquat Rana | 19 | 600 |
| 4 Gunjan Kumar | 18 | 5 |

Unmapped (explicit): Admin 10 (883), 14 (193), 8 (76), 1 (6), 13 (2).

---

## UI

Internal kernel: Admin **200**, agent **403**, POST **405**. Page title, read-only banner, totals, Rafaquat/unmapped labels present.

---

## Rollback

Do not delete imported history to hide discrepancies. Use backup `20260912T110731Z` and documented restore if a material failure is discovered.
