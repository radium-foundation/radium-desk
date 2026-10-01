# Refund 360 — RadiumBox Lane-1 Migration Preparation

**Prompt ID:** `RadiumDesk-P-30-10-26`  
**Date:** 2026-10-01  
**Mode:** Infrastructure + batch preparation — **no financial execution**

**Owner approval required for execution:** explicit gate reference (not assumed)

---

## Cohort

| Field | Value |
|---|---|
| refund_id | **360** |
| amount | **₹849** |
| desk_refund_reference | **REF-67363** |
| site | **radiumbox.com** |
| source_wallet_id | **2567** |
| local_user_id | **499465** |
| CWID | `5a3d0706-9f4b-4adc-b3d8-7cd134295404` |
| match_method | `desk_refund_reference` |

---

## Infrastructure delivered

### radiumbox.com
- Wallet migration lock / retire / status / restore APIs (parity with rdservice.in)
- Migrations: `wallet_migration_locks`, `source_system`, migration columns on `users_wallet`
- Feature flags default **OFF** (`CENTRAL_WALLET_MIGRATION_LOCK_ENABLED`, `CENTRAL_WALLET_MIGRATION_RETIREMENT_ENABLED`)

### Radium Desk
- `RoutingWalletMigrationSpokeClient` — routes by `source_site_code` (`rdservice.in`, `radiumbox.com`)
- `Refund360Migration*` manifest loader, journal import, batch gate, dry-run, orchestrator (refund **360** only)
- Immutable manifest SHA: `d04ce130e7c002fe441b1447fb8b7832e4e05a079b3c6af4f0ae39595c680967`

---

## Commands (preparation only)

```bash
php artisan central-wallet:refund360-migration-import --owner-authorized
php artisan central-wallet:refund360-migration-dry-run
```

---

## Production verification (read-only)

Box wallet **2567**: credit **₹849**, `desk_refund_reference=REF-67363`, user **499465**, status `success`.

**Financial execution:** NOT PERFORMED. Ledger remains **53 / ₹27,725**.

---

## Execution gate (when authorized)

Requires:
1. Owner approval reference for refund 360 batch
2. `CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED=true` (+ balance migration flag)
3. Box migration flags enabled for cutover window
4. Successful dry-run with `executable_batch_ready=true`
