# Regression Integration Policy (RIP) — Desk ↔ RadiumBox availability sync

**Scope:** Feature-flagged Desk push and RadiumBox ingest/enforcement for **owner-mapped** `radiumbox_com` SKUs only. Paired repositories: `radium-desk` and `radiumbox.com`.

**This policy does not authorize production deploy, migrations, or flag enablement by itself.**

## What RIP is

RIP is the minimum automated and procedural gate before:

- deploying availability-sync code to Desk or RadiumBox, or
- enabling `RADIUMBOX_AVAILABILITY_SYNC_ENABLED`, `DESK_AVAILABILITY_INGEST_ENABLED`, or `DESK_AVAILABILITY_ENFORCEMENT_ENABLED`.

Unit and feature tests **do not** substitute for a live two-system staging run with flags on, real queue workers, and verified snapshots.

## Pass criteria

| Result | Meaning |
|--------|---------|
| **Pass** | All RIP PHPUnit tests green; Pint `--test` on scoped paths green; no skipped required tests. |
| **Conditional** | Local pass but GitHub Actions workflow **absent**, **not yet run**, or **failed** — do not treat as release-ready until CI is green or blockers documented. |
| **Fail** | Any required test or Pint failure — **STOP → FIX → RE-RUN** affected checks before merge or deploy. Do not weaken or delete tests to pass. |

Report **skipped** tests and **environment failures** (PHP extension, disk, missing `vendor`) explicitly. Report **absent CI** as absent, not passing.

## Desk — required checks

### PHPUnit (RIP suite)

```bash
php artisan test --filter='ChannelSkuMapAvailabilitySyncTest|InventoryAvailabilitySyncTest|InventoryStockServiceTest|BackfillRadiumboxCatalogAvailabilityCommandTest'
```

Covers: post-commit sync dispatch, HTTP payload to Box (faked), stock adjustments, channel map hooks, backfill command.

### Pint (scoped)

```bash
./vendor/bin/pint --test \
  app/Services/RadiumBox/RadiumBoxCatalogAvailabilityClient.php \
  app/Services/RadiumBox/RadiumBoxCatalogAvailabilitySyncException.php \
  app/Services/Inventory/InventoryAvailabilitySyncService.php \
  app/Jobs/SyncStorefrontAvailabilityJob.php \
  app/Console/Commands/BackfillRadiumboxCatalogAvailabilityCommand.php \
  tests/Feature/Inventory
```

### CI

Workflow: `.github/workflows/availability-sync-rip.yml` on pull requests and pushes to `main`.

## RadiumBox — required checks (paired)

See `docs/desk-radiumbox-availability-regression-integration-policy.md` in **radiumbox.com** for ingest, mapped purchase enforcement (including insufficient quantity), legacy out-of-stock, and checkout/payment regression suites.

Desk changes that alter the integration contract must be validated against **both** repositories’ RIP suites before deploy.

## When broader suites are required

- Changes to **shared commerce paths** (cart, checkout, Cashfree, wallet handoff) outside availability scope: run RadiumBox `ProductPurchaseAvailabilityTest` and `CheckoutPaymentDeskTest` (included in Box RIP).
- Changes to **Desk inventory core** beyond availability hooks: run full `tests/Feature/Inventory` or owner-directed inventory regression.
- **Before first enforcement in an environment:** follow rollout in `docs/radiumbox-catalog-availability-sync.md` (Desk) and `docs/desk-availability-sync-p18-80.md` (Box); run **staging** ingest + backfill + snapshot verification; optional manual purchase checks for mapped SKUs.

## STOP → FIX → RE-RUN

On any RIP failure: fix the regression in a scoped follow-up change, re-run the full Desk RIP suite (and Box RIP if the contract changed), then re-run CI. Do not enable flags or deploy until RIP passes locally and CI (when configured) is green.
