# Desk v4.1.51 — statutory coverage reconciliation release procedure

**Release scope:** PR **#95** only — read-only **`desk:reconcile-statutory-coverage`** and **`StatutoryCoverage/*`** (no wallet, no rd.in deploy, no invoice mint changes).

**Production baseline (verified P-31-18):** **`v4.1.50`** / build **`3ece0364bb8a1d4194e1fbecdab3209bab2e9462`** @ `/var/www/radium-desk` (`storage/app/private/release.json`).

**Target source (after release prep commit + Owner-approved tag):** annotated tag **`v4.1.51`** on release commit (parent includes **`c9ff9919`** merge of PR #95).

This document is a **runbook for a separately authorized deploy**. Nothing here is executed by the P-31-19 preparation task.

---

## Prohibited during deploy and dry-run

- **No** `--apply` on reconciliation (command does not define `--apply`).
- **No** statutory invoice issuance, cancellation, regeneration, or backdating.
- **No** commerce ingestion, resubmission, backfill, or outbox replay.
- **No** IRP / e-invoice provider calls as part of reconciliation.
- **No** wallet or Central Wallet configuration changes in this release scope.

Dry-run performs **Desk SELECTs** and **read-only spoke HTTP GETs** only.

---

## Pre-deploy

1. **Owner authorization** for Desk application deploy (**`deskd`** / `deploy-kvm.sh`) — distinct from dry-run authorization.
2. **Fresh database backup** — verified pipeline (`bin/backup-run.sh`, `/var/backups/radium-desk`); confirm checksum before proceed.
3. **Pre-deployment RIP** — capture + verify against **current** production build **`3ece0364`** per `docs/rip-release-checkpoint.md` when `RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1`. Checkpoint ID recorded in ledger before deploy.
4. **Clean release worktree** — branch **`release/p31-19-v4151-statutory-coverage`** (or tag detachment @ **`v4.1.51`**); `git status` clean; **`CHANGELOG.md`** **4.1.51** section present; annotated tag **`v4.1.51`** on release commit (**after Owner approves release notes** — not before).
5. **Migrations:** **None** for v4.1.51 — confirm `php artisan migrate:status` unchanged for new paths (no pending PR #95 migrations).

---

## Deploy execution (Owner-authorized only)

1. Deploy from clean **`v4.1.51`** worktree (`desk deploy --yes` / documented KVM script).
2. Confirm **`storage/app/private/release.json`**: version **`4.1.51`**, build SHA matches tagged release commit.
3. Confirm command registered:

   ```bash
   php artisan list | grep reconcile-statutory
   ```

4. **`composer dump-autoload -o`** if deploy script does not already (standard Desk deploy includes `composer install`).
5. Config cache rebuild; supervisor/worker restart per existing deploy script.
6. **`/up`**, login smoke, route cache OK.

---

## Post-deploy smoke (protected journeys)

| Check | Expect |
|-------|--------|
| Existing statutory mint / Finance Hub manual issue | **Unchanged** (this release does not alter mint engine) |
| rdservice.net auto-issuance gate (v4.1.50) | **Unchanged** |
| **`desk:reconcile-statutory-coverage`** (smoke window) | Exits **0**; prints read-only report; **no** DB row changes |
| Channel ingest **`auto_issue_invoice`** | Remains **`false`** |
| Wallet / refund paths | **Unchanged** (out of scope) |

---

## Post-deploy RIP and rollback

1. Record **post-deployment RIP** with smoke evidence; commit suffix = deployed release build SHA.
2. **App rollback:** redeploy **`v4.1.50`** / **`3ece0364`** (prior annotated tag). No migration rollback required.
3. Remove or ignore reconciliation CLI on rolled-back build (command absent on **`3ece0364`**).

---

## October 2026 dry-run (separate Owner-authorized ops step)

**Timezone:** **`Asia/Kolkata`** (default via `STATUTORY_COVERAGE_TIMEZONE` or `--timezone=Asia/Kolkata`).

**Full window (may be slow or timeout on RadiumBox — see below):**

```bash
cd /var/www/radium-desk
php artisan desk:reconcile-statutory-coverage \
  --from="2026-10-01 00:00:00" \
  --to="2026-10-31 23:59:59" \
  --timezone=Asia/Kolkata \
  --json
```

**Bounded pagination / channel strategy (recommended first production trial):**

1. **RadiumBox only** — smaller HTTP surface; verify tokens and latency:

   ```bash
   php artisan desk:reconcile-statutory-coverage \
     --channel=radiumbox_com \
     --from="2026-10-01 00:00:00" \
     --to="2026-10-31 23:59:59" \
     --timezone=Asia/Kolkata \
     --per-page=100 \
     --page=1 \
     --json
   ```

   Repeat with `--page=2`, … until `report_complete` / pagination metadata indicates done (or accept partial report reason in output).

2. **rdservice.in** — run only after **rdservice.in** production exposes **`GET /api/integrations/v1/statutory-obligations`** (commit **`cc71abea`** or successor — **separate rd.in deploy**, not v4.1.51):

   ```bash
   php artisan desk:reconcile-statutory-coverage \
     --channel=rdservice_in \
     --from="2026-10-01 00:00:00" \
     --to="2026-10-31 23:59:59" \
     --timezone=Asia/Kolkata \
     --per-page=500 \
     --json
   ```

3. **rdservice.net** — source obligation list path is **`null`** in config; reconcile uses Desk-derived population only (`source_population_complete` may be **no**).

**RadiumBox timeout risk (VERIFIED P-30-141 / P-31-05 class):** Public full-month obligation fetch may **524** (~125s). Prefer origin loopback or **`--per-page=1`** / low **`per_page`** pagination for trials; do not increase Desk HTTP client timeout without Owner gate.

**Optional exception detail (still read-only):**

```bash
php artisan desk:reconcile-statutory-coverage \
  --channel=radiumbox_com \
  --from="2026-10-01 00:00:00" \
  --to="2026-10-31 23:59:59" \
  --timezone=Asia/Kolkata \
  --exceptions \
  --max-exceptions=200 \
  --json
```

---

## Cross-spoke dependency (not in v4.1.51 deploy)

| Dependency | Owner | Notes |
|------------|-------|-------|
| **rdservice.in** obligation API | Separate rd.in release | Without prod API, rd.in **`source_population_complete`** stays incomplete or fail-closed |
| **radiumbox.com** obligation API | Already on prod (P-31-04/13) | Desk **`order_lookup.spokes.radiumbox_com`** token must match Box |
| Full cross-spoke October closure report | After Desk **+** rd.in API | Not a single deploy artifact |

---

## References

- `CHANGELOG.md` — **4.1.51**
- `docs/rip-release-checkpoint.md` — RIP lifecycle
- `docs/release-workflow.md` — tag/changelog guard
- **P-31-18** — production vs **`c9ff9919`** preflight
- **PR #95** merge commit **`c9ff9919`**
