# Legacy Cash production overlay + STRICT dry-run — RadiumDesk-P-07-09-278

**Date:** 2026-09-12  
**Implementation:** `03b374526decf9474926b438c49dbf7cc0d8275c`  
**Mechanism:** named-file overlay (no `--delete`). Not `./tools/desk deploy`. Not merge to main. Not tagged.

---

## Why not full `desk deploy`

- Feature is not on `main` / not tagged.
- Unscoped `migrate --force` would also run three **Pending** UPI migrations.
- Production already has Purchasing overlays; replacing `routes/web.php` / `RolePermissionSeeder.php` wholesale from `03b37452` would drop those routes/permissions.

---

## Backup

| Item | Value |
|------|--------|
| ID | `20260912T095538Z` |
| Phase | `cloud_uploaded` |
| Outcome | `success` |
| Engine | MariaDB 11.8.8 |
| DB artifact SHA-256 | `f8f7b617280e5d1de9bcd9133fd23b88c54c1f5fe04c65c1b6115563a88cb5b5` **MATCH** |
| Secrets artifact SHA-256 | `5bd0d9618ace278b2a57374c27f08634ba8e6cda17bce76bb8c990e6cf170251` **MATCH** |
| Upload verified | `artifacts_verified: true` |
| Lock | Outer `flock` as `ravi` on `/var/lock/radium-desk-backup.lock` + `BACKUP_SCHEDULE_SKIP_LOCK=true` (root cannot open the ravi-owned lock file) |

---

## Overlay set

New files from `03b37452` (command, models, import services, contract, Legacy Cash view, migration **file only**).

Replaced after hash-match to origin/main / live production:

- `app/Services/Finance/OpeningBalanceService.php`
- `resources/views/finance/partials/workspace-nav.blade.php`
- `config/database.php`

Surgical patch (Purchasing-safe):

- `routes/web.php` — `LegacyCashController` import + GET `finance/legacy-cash`
- `database/seeders/RolePermissionSeeder.php` — `finance.legacy_cash.view` constant + module grant list

Rollback copies: `storage/app/private/overlays/p-07-09-278-20260912T095845Z`

Migration `2026_09_09_220000_create_finance_legacy_cash_tables` remains **Pending**. **Not executed.**

Seeder **not** run (`finance.legacy_cash.view` rows = 0).

---

## Dry-run

`php artisan finance:import-legacy-cash --json=/tmp/legacy-cash-prod-expenses.json`

JSON built by **SELECT-only** `mariadb` of `radiumbox_prod.expenses` ⟕ `admins`. App user `radium_desk` is **denied** SELECT on `radiumbox_prod.expenses`.

`--apply` and `--post-opening` were **not** passed.

Two consecutive dry-runs: identical output. Approved totals match: **yes**.

Temp JSON removed after the run.

---

## Next-gate prerequisites (import / opening-post)

1. Path-scoped migrate **only** `2026_09_09_220000_create_finance_legacy_cash_tables` (do not run UPI Pendings).
2. Seed `finance.legacy_cash.view` (RolePermissionSeeder path, not full unrelated seeds).
3. Source access for apply: GRANT SELECT on `radiumbox_prod.expenses` (+ `admins`) to the Desk DB user, **or** documented `--json` SELECT dump.
4. Explicit `--apply --post-opening --actor=` authorization.
