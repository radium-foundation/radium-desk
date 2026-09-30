# Radium Desk UAT lane — Central Wallet reservation testing (P-30-09-16)

**Date:** 2026-09-30  
**Mode:** Isolated non-production infrastructure only. Production Desk unchanged.

## UAT lane summary

| Item | Value |
|------|--------|
| Application path | `/var/www/radium-desk-uat` |
| Hostname (internal) | `desk-uat.radiumbox.com` (HTTP Host header; no public DNS required) |
| Access | `curl -H "Host: desk-uat.radiumbox.com" http://127.0.0.1/...` from KVM8, or SSH tunnel |
| Database | `radium_desk_uat` (user `radium_desk_uat`) |
| Release deployed | `v4.0.168` (`013ec2c6`) |
| `APP_ENV` | `staging` |
| `CENTRAL_WALLET_RESERVATIONS_ENABLED` | `true` (UAT only) |
| Integration token | `/var/www/radium-desk-uat/.uat-integration-token` (not in git) |

## Production boundary (verified)

| Item | Production | UAT |
|------|------------|-----|
| App path | `/var/www/radium-desk` | `/var/www/radium-desk-uat` |
| Database | `radium_desk` | `radium_desk_uat` |
| Reservations flag | off / unset | `true` |
| Reservation rows | 0 | synthetic only |
| Ledger rows | 0 | synthetic only |

## Tooling

```bash
# Provision (from repo root, requires clean tree or use worktree)
./tools/commands/provision-desk-uat-kvm.sh --tag v4.0.168 --yes

# Validate reservation flows (no secrets printed)
./tools/commands/validate-desk-uat-kvm.sh

# Roll back UAT lane only
./tools/commands/rollback-desk-uat-kvm.sh
```

## RadiumBox beta wiring (future)

When RadiumBox beta UAT is ready, set **only on beta**:

- `CENTRAL_WALLET_API_BASE_URL=http://desk-uat.radiumbox.com` (via Host resolve / tunnel) or internal URL
- `CENTRAL_WALLET_INTEGRATION_TOKEN` = contents of UAT `.uat-integration-token` file on Desk server
- `CENTRAL_WALLET_CHECKOUT_*` UAT flags per radiumbox.com checkout design

Do **not** reuse production Desk token or production CWIDs.

## UAT-only hotfix

`v4.0.168` `WalletController` closure missing `$entryType` in `use (...)` — patched on UAT deploy only for external ledger credits. Production overlay unchanged.

## Rollback

`rollback-desk-uat-kvm.sh` removes vhost map, database, and `/var/www/radium-desk-uat`. OLS config backup: `httpd_config.conf.pre-desk-uat-*`.
