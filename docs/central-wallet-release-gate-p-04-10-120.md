# Central Wallet Production Release Gate (P-04-10-120)

**THIS GATE DOES NOT AUTHORIZE DEPLOYMENT.** Deployment remains a separate Owner approval step.

## Purpose

Prevent RD10575-class failures by validating the full Central Wallet dependency chain **before and after** any future Owner-approved production deployment.

## Invoke

```bash
# Desk provider
php artisan central-wallet:verify-release-gate --phase=pre
php artisan central-wallet:verify-release-gate --phase=post --json

# Each spoke (consumer)
php artisan central-wallet:verify-release-gate --phase=pre
php artisan central-wallet:verify-release-gate --phase=post --json
```

Shell helper (Desk):

```bash
tools/commands/central-wallet-release-gate.sh pre
tools/commands/central-wallet-release-gate.sh post
```

Cross-project orchestration (run on each host after deploy):

1. Desk @ `/var/www/radium-desk`
2. rdservice.in @ `/var/www/rdservice.in`
3. radiumbox.com @ `/var/www/radiumbox.com`
4. rdservice.net @ `/var/www/rdservice.net.prod`

## Gate sections

| Section | Pre-deploy | Post-deploy |
|---|---|---|
| Contract v1 compatibility | PASS required | PASS required |
| Required routes (wallet-visibility) | PASS required | PASS required |
| Runtime manifest | WARN if missing | **FAIL if missing** |
| Deployment drift / file hashes | WARN overlay drift | **FAIL on hash mismatch** |
| Authentication probe | BLOCKED if fixture missing | BLOCKED if fixture missing |
| Semantic invariants | PASS required | PASS required |
| Account-link variance | WARN if not configured | BLOCKED if not configured |
| Synthetic wallet probe | BLOCKED if fixture missing | BLOCKED if fixture missing |

## Fixture configuration (required for auth/synthetic on post-deploy)

```env
CENTRAL_WALLET_RELEASE_GATE_PROBE_BASE_URL=https://desk.radiumbox.com
CENTRAL_WALLET_RELEASE_GATE_PROBE_TOKEN=...
CENTRAL_WALLET_RELEASE_GATE_PROBE_SITE_CODE=rdservice.in
CENTRAL_WALLET_RELEASE_GATE_PROBE_LOCAL_USER_ID=...
CENTRAL_WALLET_RELEASE_GATE_PROBE_EMAIL=...
```

Optional account-link variance snapshot (SELECT-only):

```env
CENTRAL_WALLET_RELEASE_GATE_RECONCILIATION_ENABLED=true
CENTRAL_WALLET_RELEASE_GATE_DESK_ENV_PATH=/var/www/radium-desk/.env
CENTRAL_WALLET_RELEASE_GATE_SPOKE_ENV_PATH=/var/www/rdservice.in/.env
```

## PASS / FAIL / BLOCKED

- **PASS** — all hard checks satisfied
- **FAIL** — security, contract, route, drift, or identity conflict
- **BLOCKED** — fixture/reconciliation not configured (not a false PASS)

Account-link variance alone (missing migration/canonical local links) = **NON-BLOCKING**.

Wallet ID mismatch / spoke-only / identity conflict = **FAIL**.

## Rollback trigger

Owner should consider rollback when post-deploy gate reports:

- `required_routes_registered` FAIL (missing wallet-visibility)
- `runtime_file_hashes` FAIL
- authentication/site_mismatch FAIL
- account-link `wallet_id_mismatch` or `identity_mismatch`

## Owner approval boundary

This gate **reports only**. It does not deploy, rollback, alter flags, create links, or mutate wallets.
