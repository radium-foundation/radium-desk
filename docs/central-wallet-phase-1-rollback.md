# Central Wallet Phase 1 — Migration & Rollback

**Prompt ID:** `RadiumDesk-P-25-09-87`, `RadiumDesk-P-25-09-88`  
**Scope:** Desk hub foundation only (`central_wallet_*` tables)

## Migration

| Item | Detail |
|------|--------|
| File | `database/migrations/2026_09_28_100000_create_central_wallet_foundation_tables.php` |
| Tables | `central_wallets`, `central_wallet_account_links`, `central_wallet_ledger_entries`, `central_wallet_idempotency_records` (includes `response_body` JSON for full idempotent replay), `central_wallet_audit_events`, `central_wallet_reconciliation_runs`, `central_wallet_reconciliation_items` |
| Coupling | **Desk DB only** — no spoke schema changes |
| Data backfill | **None** — no production wallet migration |

## Rollback (pre-production / staging)

1. Set `CENTRAL_WALLET_ENABLED=false`, `CENTRAL_WALLET_API_ENABLED=false`, `CENTRAL_WALLET_RECONCILIATION_ENABLED=false`.
2. Redeploy prior Desk release (or leave flags off on current release).
3. If migration was applied in non-production only: `php artisan migrate:rollback --step=1` (drops `central_wallet_*` tables).
4. **No impact** on existing Desk wallet refund orchestration or spoke `users_wallet` tables when flags are OFF.

## Production safety (Phase 1)

- Feature flags default **OFF** in `config/central_wallet.php` and `.env.example`.
- No wiring from `WalletRefundExecutor` to Central Wallet in Phase 1.
- No customer-visible balance or cutover behavior.
