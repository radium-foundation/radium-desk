# Wallet vs OPM refund accounting (RadiumDesk-P-04-10-28)

**Status:** IMPLEMENTED (code + tests). **CONFIGURATION REQUIRED** before wallet journal posting in environments missing GL 2100. **OWNER/CA APPROVAL REQUIRED** before enabling statutory adjustment in production.

Prior investigation: `RadiumDesk-P-04-10-27`.

## VERIFIED — prior behavior

- All refund methods posted **Dr 5100 Customer Refunds / Cr 1100 Bank Clearing**.
- Wallet refunds do not move external cash; OPM refunds are manually attested.
- Credit Notes are statutory cancellation artifacts, not generic refund documents.

## IMPLEMENTED — wallet accounting

Wallet refund (`approved_refund_method = wallet`):

```text
Dr 5100 Customer Refunds (expense)
    Cr 2100 Customer Wallet Liability (liability)
```

- Classification uses **approved refund method**, not `execution_reference_no`.
- Central Wallet (`CW:{id}`), rdservice.in, and RadiumBox wallet credits share the same journal treatment.
- Fail-closed if wallet liability account is missing, inactive, non-liability, or equals bank clearing.

## IMPLEMENTED — OPM accounting

External payment reversal methods (`cashfree`, `bank_transfer`, `upi`, `other`):

```text
Dr 5100 Customer Refunds
    Cr 1100 Bank / Payment Clearing
```

## IMPLEMENTED — Customer Wallet Liability GL

| Field | Value |
|-------|-------|
| Code | **2100** |
| Name | Customer Wallet Liability |
| Type | Liability |
| Setting | `default_wallet_liability_account_code` |

Migration: `2026_10_05_120000_add_customer_wallet_liability_finance_account.php`  
Seeder: `FinanceChartOfAccountsSeeder::CODE_WALLET_LIABILITY`

## IMPLEMENTED — statutory adjustment rollout safety

Config (`config/refunds.php`):

- `REFUNDS_STATUTORY_ADJUSTMENT_ENABLED` — default **false**
- `REFUNDS_STATUTORY_ADJUSTMENT_ACTIVATED_AT` — ISO-8601 datetime; **required** for new-refund-only eligibility when enabled

When enabled without `activated_at`, all adjustments skip with `statutory_adjustment_not_activated`.  
Refunds completed before `activated_at` skip with `refund_before_statutory_adjustment_activation`.

**Do not enable production flag without setting `activated_at` to deployment time.**

## VERIFIED — partial refunds

- Partial refunds remain **excluded** from automatic statutory adjustment (`partial_refund`).
- Partial Wallet/OPM refunds still receive correct method-aware finance journals.

## VERIFIED — OPM evidence

- `ManualRefundExecutor` records manual attestation only.
- Desk does **not** verify Cashfree/provider API reversal (NOT IMPLEMENTED).

## NOT MODIFIED — historical cohorts

| Cohort | Count | Protection |
|--------|------:|------------|
| Invoiced refunds (issued invoice unchanged) | 87 | No auto statutory adjustment without activation + eligibility |
| Missing pre-ledger journals | 116 | No backfill command added or run |
| Existing journals | 257 | No reversal |
| Credit Notes | 1 | Unchanged |

## UAT matrix (pre-production enablement)

| # | Scenario | Expected |
|---|----------|----------|
| 1 | Wallet full, no invoice | Wallet liability journal; no bank clearing; no CN |
| 2 | Wallet full + B2C invoice (flag ON, post-activation) | Wallet liability; invoice cancel; no CN |
| 3 | Wallet full + B2B IRN ≤24h | Wallet liability; IRN cancel + invoice cancel |
| 4 | Wallet full + B2B IRN >24h | Wallet liability; CN path |
| 5 | Wallet partial | Wallet liability; no statutory adjustment |
| 6 | OPM full | Bank clearing; no wallet liability |
| 7 | OPM partial | Bank clearing; no statutory adjustment |
| 8 | Duplicate RefundCompleted | One journal (`refund:{id}`) |
| 9 | Duplicate statutory adjustment | Idempotent orchestrator/CN keys |
| 10 | Historical refund (pre-activation) | No statutory adjustment |

## Production deployment checklist

1. Deploy code + run migration (adds GL 2100 + default setting).
2. Verify Finance → Settings → Financial Preferences shows **2100 Customer Wallet Liability**.
3. Complete wallet refund UAT (TEST 1, 5, 8).
4. Complete OPM refund UAT (TEST 6, 7).
5. Leave `REFUNDS_STATUTORY_ADJUSTMENT_ENABLED=false` until UAT + CA sign-off.
6. When approved: set `REFUNDS_STATUTORY_ADJUSTMENT_ACTIVATED_AT` to enablement timestamp, then enable flag.
7. Monitor `refund_statutory_adjustments` and statutory cancellations.

## Rollback

- Revert application deploy.
- **Warning:** Wallet refunds posted after deploy with liability journals cannot be safely rolled back by migration alone; reversing entries require separate Owner/CA authorization.
