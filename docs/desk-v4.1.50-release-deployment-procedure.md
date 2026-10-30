# Desk v4.1.50 — release deployment procedure (preparation only)

**Target source:** `origin/main` @ **`564b603a`** (or release commit after CHANGELOG + this doc).  
**Production baseline:** **`v4.1.49`** / **`af77d628`**.  
**Owner decisions (P-30-91):** **`SERVICE_STATUTORY_INVOICE_AUTO_ISSUANCE_ENFORCE=true`**; per-order CLI approval for future rdservice.net automatic mint; **85** unlinked RN orders and **72** failed service-mint outbox rows **unchanged**.

This document is a **runbook for a separately authorized deploy**. Nothing here is executed by the P-30-91 preparation task.

## Scope

- Statutory auto-issuance gate (PR **#91**).
- MariaDB inventory sync log index naming fix in source (PR **#93** — no prod DDL if migration 211 already applied).
- Hardware SKU map **1506** unchanged on prod (verify seed dry-run only).

## Pre-deploy

1. **Fresh database backup** — run verified backup pipeline (see P-30-65 pattern); confirm upload/checksum before proceed.
2. **Pre-deployment RIP** — record and verify checkpoint against prod baseline **`af77d628`**; satisfy `RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT` if enabled.
3. **Clean release worktree** — detached or branch at release commit; `git status` clean; `validate_release_metadata` / tag **`v4.1.50`** on that commit (authorized release task only).
4. **`release:snapshot`** — local snapshot before `deploy-kvm.sh`; expect **`4.1.50`** in `storage/app/private/release.json` after deploy.

## Enforcement configuration

- Default in code: **`service_statutory_invoice.auto_issuance.enforce`** → **`true`** via **`SERVICE_STATUTORY_INVOICE_AUTO_ISSUANCE_ENFORCE`**.
- Confirm production `.env` does **not** set enforce to **`false`** unless Owner explicitly chooses phased rollout.
- **`channel_ingest.auto_issue_invoice`** must remain **`false`**.

## Migration and code ordering

**Pending migration (required once per environment):**

```bash
php artisan migrate --path=database/migrations/2026_10_30_120000_add_statutory_auto_issue_approval_to_commerce_orders_table.php
```

Verify nullable columns on `commerce_orders`:

- `statutory_auto_issue_approved_at`
- `statutory_auto_issue_approved_by` (FK `users`, nullable)

**`deploy-kvm.sh` order today:** rsync application → `composer install` → **`migrate --force`** → cache rebuild → **`kvm_restart_supervisor_worker`**.

**Rsync-before-migrate race:** New PHP is on disk before migrate completes; long-lived workers restart only after migrate. **Owner options** if zero tolerance:

- Enable **`php artisan down`** (or equivalent) for the deploy window until migrate + worker restart complete, **or**
- Run the statutory migration path **immediately after rsync** before traffic-bearing workers pick up new code (manual ordering in an authorized deploy task).

Additive migration is safe on **`af77d628`** app (old code ignores new columns). New code **requires** columns before policy reads approval fields.

**PR #93:** No production index rename; inventory table already present (P-30-86).

## Deploy execution (Owner-authorized only)

1. `deploy-kvm.sh --yes` from clean **`v4.1.50`** worktree (or documented override branch).
2. Confirm **`release.json`**: version **`4.1.50`**, build SHA matches tagged commit.
3. Confirm migration row **`2026_10_30_120000_add_statutory_auto_issue_approval_to_commerce_orders_table`**.
4. **`/up`**, login smoke, route cache OK.

## Post-deploy smoke (protected journeys)

| Journey | Expect |
|---------|--------|
| rdservice.in / RB service auto mint | Unchanged (no approval column required) |
| Finance Hub manual issuance | Unchanged; bypasses auto gate |
| rdservice.net pilot order (Owner-chosen) | Denied without approval → CLI approve → single automatic invoice |
| Hardware fulfilment / SKU **1506** | `desk:seed-radiumbox-hardware-sku-maps` dry-run → **1506** `unchanged` only |
| Statutory cancellation / IRN bundle | Spot-check or rely on CI bundle + ops watch |
| Failed RN service-mint outbox (**72**) | Remain **`failed`**; no bulk retry |

## Post-deploy RIP and rollback

1. Record **post-deployment RIP** with smoke evidence per desk release gate.
2. **App rollback:** redeploy **`v4.1.49`** / **`af77d628`**; approval columns remain (nullable) — compatible.
3. **Gate rollback without redeploy:** **`SERVICE_STATUTORY_INVOICE_AUTO_ISSUANCE_ENFORCE=false`** (config reload); does not remove columns or invoices.

## References

- `docs/desk-p30-59-statutory-auto-issuance-gate-release.md` — policy and CLI detail
- `CHANGELOG.md` — **4.1.50** section
- P-30-87 / P-30-88 / P-30-89 / P-30-90 — production readiness and outbox assessments
