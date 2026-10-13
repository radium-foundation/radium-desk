# Release candidate: Desk-first wallet refunds (v4.1.24-rc)

**Prompt:** `RadiumDesk-P-12-10-006`  
**Branch:** `feat/desk-first-wallet-refund-rc` (from `main` @ `da7f221b`)  
**Isolation:** Desk-first allowlist only — no Gmail/C360, no REF-67395 pilot migration bundle.

## Summary

When `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_ENABLED=true` (default **false**), wallet refunds credit **Desk Central Wallet** first, then record a **spoke audit** (RDIN default allowlist). Legacy spoke spendable credits remain when the flag is off.

## Configuration (defaults)

| Env | Default | Purpose |
|-----|---------|---------|
| `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_ENABLED` | `false` | Master gate |
| `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_SPOKES` | `rdservice.in` | Fail-closed spoke allowlist |

## Deploy

1. Merge PR to `main`; tag (e.g. `v4.1.24`) when authorized.
2. `desk deploy --yes` with flags **OFF**.
3. Post-deploy: legacy wallet refund smoke (RDIN).
4. Canary: see `docs/desk-first-wallet-refund-release-plan.md`.

## Rollback

1. Set `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_ENABLED=false`; `php artisan config:cache`.
2. New refunds use legacy spoke path.
3. In-flight `pending_execution` with Desk CW credit: complete via recovery or finance reversal before disabling flag.
4. Completed desk-first refunds (`execution_reference_no` = `CW:*`): revoke uses desk-first coordinator even if flag is off.

## Owner decisions before prod flag

- RDIN production spoke deploy verifying `central_wallet_ledger_entry_id` → non-spendable audit.
- Do **not** expand `CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_SPOKES` to net/box until spoke audit + revoke parity are verified.
