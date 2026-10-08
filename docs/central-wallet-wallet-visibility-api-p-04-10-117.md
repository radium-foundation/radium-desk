# Central Wallet wallet-visibility API (P-04-10-117)

## Problem

Customer-facing spokes (`rdservice.in`, `radiumbox.com`) call:

`GET /api/central-wallet/v1/wallet-visibility`

when `CENTRAL_WALLET_HISTORICAL_WALLET_VISIBILITY_ENABLED=true`. Production Desk returned **HTTP 404**, causing spoke balance reads to resolve as `state=unresolved` and the UI to show **"Not available"**, even when the authoritative Desk ledger held the correct balance (e.g. RD10575 / REF-67352 / ₹499).

## Architecture

```
Authenticated spoke (Bearer + X-Site-Code)
  → WalletVisibilityController
  → WalletVisibilityService (read-only)
  → identity: active account link OR verified credential (exactly one)
  → LedgerService::availableBalance()  (authoritative; no parallel calculator)
  → JSON visibility contract
```

Customer 360 continues to read Desk DB directly via `DeskCustomerCentralWalletResolver` + `LedgerService`. This API is the **spoke HTTP contract only**.

## Authentication

Same as other Central Wallet integration endpoints:

- `Authorization: Bearer {CENTRAL_WALLET_INTEGRATION_TOKEN}`
- `X-Site-Code` must match query `site_code`
- Correlation id via existing `central_wallet.correlation` middleware

## Request

Query parameters:

| Field | Required | Notes |
|-------|----------|-------|
| `site_code` | yes | Must match caller `X-Site-Code` |
| `local_user_id` | yes | Spoke authenticated user id |
| `email` | one of email/mobile | From spoke user record |
| `mobile` | one of email/mobile | Optional |
| `email_verified` | no | `0`/`1` — spoke attestation only |

## Response (200)

| Field | Meaning |
|-------|---------|
| `wallet_balance` / `available_balance` | From `LedgerService::availableBalance()` |
| `currency` | Desk config (`INR`) |
| `balance_status` | `verified` \| `unverified` \| `verification_required` |
| `balance_source` | `central_wallet` or `historical_wallet_refund` |
| `spendable` | `true` only for trusted verification + spoke email verified |
| `verification_required` | Display/spend gate hint for spoke UI |
| `display_label` / `display_hint` | UI copy |

## Identity security

1. **Account link path:** active Desk link for `(site_code, local_user_id)` AND attested email/mobile must match a **verified credential** on the linked Desk customer. Wrong email → **404** (fail-closed, no leak).
2. **Credential path:** exactly one verified email/mobile credential match when no account link exists.
3. Ambiguous identity → **409** `identity_ambiguous`.
4. No browser-supplied CWID. No unauthenticated lookup.

## Display vs spend

| Verification | balance_status | spendable | Customer action |
|--------------|----------------|-----------|-----------------|
| `owner_migration_cohort` | `unverified` | false | Connect Wallet ceremony for trusted spend |
| `verified_email` + spoke email verified | `verified` | true | Trusted balance read / checkout (if enabled) |
| Zero balance | `verification_required` | false | Connect / verify |

**Do not** add `owner_migration_cohort` to trusted methods without Owner approval.

## RD10575 fixture

- User `558781`, email `blg9950578359@gmail.com`
- Desk link #77, CWID `266700ea-…-8871`, method `owner_migration_cohort`
- Ledger #48, ₹499 posted, REF-67352
- Expected API: `balance_status=unverified`, `wallet_balance=499.00`, `spendable=false`

## Rollback

Remove route registration from `routes/central_wallet.php` and redeploy prior Desk tag. Spokes fail closed to `unresolved` / "Not available" (current production behaviour).

## Troubleshooting

| Symptom | Check |
|---------|-------|
| 503 `historical_wallet_visibility_disabled` | Desk `CENTRAL_WALLET_HISTORICAL_WALLET_VISIBILITY_ENABLED` |
| 404 `not_found` | Account link missing or email mismatch vs verified credential |
| 403 `site_mismatch` | Spoke `X-Site-Code` ≠ query `site_code` |
| C360 shows balance, customer UI not | Spoke flag on but Desk API not deployed |
