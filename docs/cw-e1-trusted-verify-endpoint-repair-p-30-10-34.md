# E-1 trusted verification endpoint repair (P-30-10-34)

**Date:** 2026-10-01  
**Project:** Radium Desk  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Branch:** `feat/direct-ledger-debit-gate`  
**Source SHA:** `795b6ab1`  
**Production:** KVM8 `ravi@187.127.129.16` `/var/www/radium-desk` (`desk.radiumbox.com`)

## Objective

Repair `POST /api/central-wallet/v1/customer-identity/resolve` so trusted E-1 customer verification is production-ready. **Non-financial repair only** — no CW credits, spoke debits, refund mutations, or execution flag changes.

## Root cause

P-30-10-32 deployed E-1 service hooks and manifest infrastructure but the surgical production overlay omitted two route dependencies already present in the repository:

1. `CustomerIdentityController.php` — route registered in `routes/central_wallet.php` but class file absent → HTTP 500.
2. `EnsureCentralWalletCustomerIdentityEnabled.php` — middleware alias registered in `CentralWalletServiceProvider.php` but class file absent → HTTP 500 after controller deploy.

`CustomerIdentityResolveService`, `CustomerFoundationFromCeremonyService`, `E1VerificationDestinationService`, and `CentralWalletServiceProvider` middleware alias were already on production and compatible with repo `795b6ab1`.

## Minimum deployment set

| File | Action |
|------|--------|
| `app/CentralWallet/Infrastructure/Http/Controllers/CustomerIdentityController.php` | Deployed from `795b6ab1` |
| `app/CentralWallet/Infrastructure/Http/Middleware/EnsureCentralWalletCustomerIdentityEnabled.php` | Deployed from `795b6ab1` |

No route, provider, or financial configuration changes required.

## Production backup

`/var/www/radium-desk/storage/app/private/deploy-backups-p30-10-34/`

- `CustomerIdentityController.php.deployed-from-795b6ab1`
- `EnsureCentralWalletCustomerIdentityEnabled.php.deployed-from-795b6ab1`

(Controller/middleware were absent pre-deploy; backups are deploy artifacts for rollback reference.)

## Endpoint validation (post-deploy)

| Probe | Expected | Result |
|-------|----------|--------|
| `GET /health` (authenticated) | 200 | **200** `{"status":"ok","service":"central_wallet"}` |
| `POST /customer-identity/resolve` empty body (auth) | 422 validation | **422** (idempotency_key, site_code, local_user_id, identity required) |
| `POST /customer-identity/resolve` no auth | 401 | **401** |
| `POST /customer-identity/resolve` invalid token | 401 | **401** |
| `POST /customer-identity/provisional-resolve` empty (auth) | 422 | **422** (E-2 regression check) |

No real customer OTP, Google credential, or production test identity submitted.

## E-1 / E-2 regression

| Metric | Before | After |
|--------|--------|-------|
| E-1 population | 168 / ₹92,811 | **168 / ₹92,811** |
| E-1 destination-ready | 0 | **0** |
| Ledger | 54 / ₹28,574 | **54 / ₹28,574** |
| New ledger entries | — | **0** |
| Spoke wallet mutations | — | **0** |
| Refund mutations | — | **0** |

E-2 audit: 52 / ₹34,517 all `UNVERIFIED`; Lane 4 execution **OFF**; provisional-resolve unchanged.

## Financial flags (verified)

- `CENTRAL_WALLET_E1_IDENTITY_MIGRATION_VERIFICATION_ENABLED=true`
- `CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED=false`
- `CENTRAL_WALLET_BALANCE_MIGRATION_EXECUTION_ENABLED=false`
- `CENTRAL_WALLET_E2_HISTORICAL_SETTLEMENT_EXECUTION_ENABLED=false`

## Tests (local, repo `795b6ab1`)

PHPUnit **25/25 PASS**: `E1VerificationPathTest`, `E1DestinationReadinessManifestTest`, `E2DestinationReadinessManifestTest`, `CentralWalletCustomerIdentityTest`.

## Rollback

Restore absence of deployed files (delete overlay copies) or replace from backup directory; run `php artisan config:clear`. Keep all financial execution flags **OFF**.

## Next gate

Real E-1 customer trusted verification (email OTP / Google) when a customer initiates identity — **not** simulated in this prompt. No financial settlement until destination-ready cohort exists and owner authorizes execution.
