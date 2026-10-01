# Historical Wallet Visibility layer (P-30-10-35)

**Date:** 2026-10-01  
**Project:** Radium Desk  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Branch:** `feat/direct-ledger-debit-gate`  
**Prompt ID:** `RadiumDesk-P-30-10-35`

## Objective

Unified read-only **Historical Wallet Visibility** for post-2026-07-15 wallet refunds. Display is **not** financial settlement — no CW credits, spoke debits, refund mutations, or execution flag changes.

## Architecture

Single orchestrator: **`HistoricalWalletVisibilityService`**

Resolution order:
1. **Verified** — trusted account link → Desk Central Wallet `spendableBalance` (`balance_status: verified`, `spendable: true`)
2. **Provisional CW** — verified-email credential without trusted link → CW balance, unverified display
3. **E-1** — `E1ProvisionalDisplayService` (168 cohort, `site + local_user_id`)
4. **Historical 220** — `HistoricalCohortProvisionalBalanceService` (legacy manifest path)
5. **E-2** — `E2ProvisionalDisplayService` (52 cohort, `site + order_email_hash`); runs when E-1/220 return `source_reconciliation_required` or not found

**Double-count prevention:** `ReconciledHistoricalRefundFilter` excludes `reconciled` migration journal rows and protected refund **300**.

## API

| Endpoint | Method | Middleware |
|----------|--------|------------|
| `/api/central-wallet/v1/wallet-visibility` | GET | `central_wallet.historical_wallet_visibility` |
| `/api/central-wallet/v1/customer-identity/provisional-resolve` | POST | `central_wallet.provisional_identity` (enhanced with unified fields) |

### Unified response fields

```json
{
  "wallet_balance": "499.00",
  "currency": "INR",
  "balance_status": "unverified",
  "balance_source": "historical_wallet_refund",
  "spendable": false,
  "verification_required": true,
  "display_label": "Wallet Balance — Unverified",
  "display_hint": "Verify your account to use this balance."
}
```

Checkout/redemption: unchanged — `TrustedFinancialAuthorizationGate` requires trusted account link; unverified display cannot authorize reservations.

## Config

| Key | Env var | Default |
|-----|---------|---------|
| `historical_wallet_visibility.enabled` | `CENTRAL_WALLET_HISTORICAL_WALLET_VISIBILITY_ENABLED` | `false` |
| `historical_wallet_visibility.protected_refund_ids` | — | `[300]` |

Existing E-1/E-2/provisional flags remain required for cohort paths.

## Population visibility (design)

| Cohort | Count | Display |
|--------|-------|---------|
| E-1 | 168 / ₹92,811 | Unverified when safely associated via E-1 manifest |
| E-2 | 52 / ₹34,517 | Unverified via email-hash manifest |
| Migrated | 54 / ₹28,574 | Verified via CW ledger only (no historical duplicate) |
| Class-B | 14 / ₹7,810 | Not displayed without safe association manifest |
| Class-C | 3 / ₹1,497 | Not displayed (ownership unresolved) |
| Refund 300 | ₹499 | Protected — never displayed |

## Tests

`HistoricalWalletVisibilityTest` + E-1/E-2/provisional/identity regression: **67/67 PASS**.

## Production deployment (pending authorization)

Minimum surgical overlay:
- New Application services + controller + middleware
- Updated `ProvisionalIdentityResolveService`, cohort loaders, config, routes, provider
- Enable `CENTRAL_WALLET_HISTORICAL_WALLET_VISIBILITY_ENABLED=true` when authorized
- E-1 display also requires `CENTRAL_WALLET_IDENTITY_REQUIRED_COHORT_PROVISIONAL_DISPLAY_ENABLED` **or** E-1 verification path (E1 manifest)

**No financial execution flags may be enabled for visibility.**
