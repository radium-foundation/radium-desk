# P-20-14 — Missing GSTIN regression closure (case #4)

| Field | Value |
| --- | --- |
| Task | **P-20-14** |
| Cursor ledger | `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef` |
| Desk ledger row | **RadiumDesk-P-20-14** |
| Risk | **HIGH** — GST statutory classification, B2B/B2C, audit trail |
| Start (IST) | **2026-11-20 09:08:00** (user task timestamp; session resume) |
| End (IST) | **2026-11-20 09:13:31** |
| Duration | **~5 min** wall (+ **~166 s** new class; **~150 s** buyer-GST bundle; **~3 s** receipt tests) |

## Objective outcome

| Item | Status |
| --- | --- |
| Case **#4** dedicated regression | **PASSED** — new permanent test class |
| Production code change | **None** — behaviour already correct (native B2C path) |
| Ten-case business approval | **Unchanged** — **10/10 APPROVED** (Owner P-20-13) |
| Production rollout activation | **NOT AUTHORIZED** — stop gate honored |

## Case #4 engineering evidence

| | Before (P-20-13) | After (P-20-14) |
| --- | --- | --- |
| Classification | **INFERRED** | **VERIFIED** |
| Evidence | Blank-GSTIN consistency only | `CommerceOrderMissingBuyerGstinNativeB2cTest` **7/7** |

## Ten-case matrix (engineering rollup)

| # | Case | Business | Engineering |
| --- | --- | --- | --- |
| 1 | Verified active B2B | APPROVED | VERIFIED |
| 2 | Inactive | APPROVED | VERIFIED |
| 3 | Invalid GSTIN | APPROVED | VERIFIED |
| 4 | Missing GSTIN | APPROVED | **VERIFIED** |
| 5 | Provider timeout / failure | APPROVED | VERIFIED |
| 6 | Product B2C conversion | APPROVED | VERIFIED |
| 7 | Service B2C conversion | APPROVED | VERIFIED |
| 8 | State mismatch | APPROVED | VERIFIED |
| 9 | Duplicate / concurrent | APPROVED | VERIFIED |
| 10 | Receipt publication | APPROVED | VERIFIED (publisher) |

**Engineering:** **10 VERIFIED / 0 INFERRED / 0 UNKNOWN** (business column unchanged).

## Changes ledger

| Class | Items |
| --- | --- |
| **Preserved** | GST/B2B/B2C production behaviour; Owner approval record; prod flags OFF |
| **Extended** | `CommerceOrderMissingBuyerGstinNativeB2cTest`; matrix doc amendment; this artifact |
| **Changed** | Case **#4** engineering label only |
| **Removed** | None |

## Focused tests

| Suite / filter | Result |
| --- | --- |
| `CommerceOrderMissingBuyerGstinNativeB2cTest` | **7/7 PASS** |
| `CommerceOrderBuyerGstProductB2cIssuanceTest` | **10/10 PASS** |
| Buyer GST bundle (`CommerceOrderBuyerGst|BuyerGstB2c`) | **43/43 PASS** |
| `RadiumBoxStatutoryReceiptPublicationTest` | **20/20 PASS** |
| `BuyerGstB2cConversionMysqlConcurrencyTest` | **NOT RUN** — no new concurrency surface; case **#9** evidenced in P-20-08/P-20-13 |
| Full PHPUnit suite | **NOT RUN** (policy) |

### New test assertions (case #4)

- Missing buyer GSTIN → native B2C mint (product + service)
- No `commerce_order_buyer_gst_b2c_conversions` or verification rows
- Invoice `buyer_gstin` blank; order submitted fields auditable on order model
- GST rate, taxable value, place of supply preserved
- E-invoice / IRN path not entered (`EInvoiceRecord` absent or not success)
- Repeat mint idempotent (single issued invoice)
- Fallback **disabled** → same native B2C (no hold from empty GSTIN)
- Valid GSTIN present → B2B path unchanged (with flags ON in test harness only)
- `Http::fake()` — no live provider calls

## Production identity (read-only — **not re-SSH in P-20-14**)

Relies on P-20-13 / P-20-10 evidence; **no production mutation** in P-20-14.

| Check | Value |
| --- | --- |
| Release | **v4.1.102** / **`339e3dc99`** (prod deploy baseline) |
| `main` (pre-PR) | **`27f3f424b`** |
| GST verification / B2C fallback | **OFF** (env keys absent) |
| Receipt publisher | **on**, allowlist **8** |
| PRE-RIP | `RIP-201126-0836-radium-desk-predeploy-p20-09-gst-product-b2c-7edf904` |
| POST-RIP | `RIP-201126-0843-radium-desk-post-p20-10-gst-schema-deploy-339e3dc` |
| Backup | `20261120T025830Z` |

## Production actions

| Action | Status |
| --- | --- |
| Deployment | **NO — Not performed** |
| Flag activation | **NO — Not performed** |
| Production minting | **NO — Not performed** |
| Live provider calls | **NO — Not performed** |
| IRN generation | **NO — Not performed** |
| Receipt publication expansion | **NO — Not performed** |

## Git / PR

| Item | Value |
| --- | --- |
| Branch | `feature/p20-14-missing-gstin-regression` |
| Target | `main` |
| PR | *(fill after create)* |
| Merge SHA | *(fill after merge)* |

## Remaining risks and next gate

- **Protected production journeys** with flags **ON** remain **NOT RUN**.
- **Known-good on production** is **not claimed**.
- **Next gate:** explicit Owner instruction for `STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED` and `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED` on production only.