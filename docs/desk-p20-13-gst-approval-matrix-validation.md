# P-20-13 — GST approval matrix validation (Owner-designated Finance/CA)

**ChatGPT task:** P-20-13  
**Cursor ledger:** `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef`  
**Authoritative matrix:** `.artifacts/P-20-12-controlled-gst-rollout-preparation.md` (ten-case table)  
**Production baseline:** **v4.1.102** / **`339e3dc99`** (unchanged by this task)

## Owner approval record

| Field | Value |
| --- | --- |
| **Owner approval** | **APPROVED** |
| **Finance/CA matrix (Owner-designated)** | **10 / 10 APPROVED** |
| **Approval source** | Owner explicit instruction in ChatGPT project conversation (task **P-20-13**, 2026-11-20) |
| **Scope** | Expected business behaviours for cases **#1–#10** in the P-20-12 rollout matrix |
| **Independent Finance signatory** | **Not recorded** — approval is Owner designation only; no fabricated names or signatures |
| **What this approval does not authorize** | Production flag activation, live provider calls, invoice minting, IRN, receipt publication expansion, deploy, or schema change |

## Rollout readiness (engineering)

| Gate | Status |
| --- | --- |
| Production schema + code | **READY** |
| Owner/CA business acceptance (10 cases) | **APPROVED** (above) |
| Protected production journeys (flags ON) | **NOT RUN** — separate activation instruction required |
| Production **known-good** | **Not claimed** |

---

## Ten-case matrix — business approval vs engineering evidence

Business column: **APPROVED** (Owner P-20-13).  
Engineering: **VERIFIED** = focused PHPUnit and/or cited UAT; **INFERRED** = design/consistency without live prod; **UNKNOWN** = not evidenced.

| # | Case | Business (Owner) | Engineering | Primary evidence |
| --- | --- | --- | --- | --- |
| 1 | Verified active B2B | **APPROVED** | **VERIFIED** | `CommerceOrderBuyerGstB2cFallbackTest::test_verified_active_registration_stays_b2b_without_conversion`; product B2B preservation tests |
| 2 | Inactive (Suspended/Cancelled) | **APPROVED** | **VERIFIED** | `test_suspended_registration_converts_automatically_without_manual_approval` |
| 3 | Invalid GSTIN | **APPROVED** | **VERIFIED** | `test_invalid_gstin_response_converts_on_verify_without_manual_approval`; `CommerceOrderBuyerGstAutoB2cConversionTest::test_local_invalid_gstin_converts_without_verification_record`; hold path when fallback OFF **VERIFIED** |
| 4 | Missing GSTIN | **APPROVED** | **VERIFIED** | **P-20-14:** `CommerceOrderMissingBuyerGstinNativeB2cTest` (**7/7**); product + service native B2C, blank invoice GSTIN, no conversion/verification rows, IRN skipped, idempotent mint, fallback OFF unchanged, valid GSTIN B2B preserved |
| 5 | Provider timeout / technical failure | **APPROVED** | **VERIFIED** | `test_provider_timeout_converts_automatically_with_technical_remark`; `test_provider_5xx_converts_automatically`; `test_malformed_provider_response_converts_automatically_without_false_invalid_claim`; `CommerceOrderBuyerGstVerificationTest::test_provider_timeout_keeps_pending_without_treating_gstin_valid` (fallback OFF) |
| 6 | Product B2C conversion | **APPROVED** | **VERIFIED** | `CommerceOrderBuyerGstProductB2cIssuanceTest` (**10/10**); P-20-05 UAT order **20** (prior artifact) |
| 7 | Service B2C conversion | **APPROVED** | **VERIFIED** | `test_service_auto_b2c_mint_remains_unchanged`; P-20-01 UAT service order (prior artifact) |
| 8 | State mismatch (verified Bill To) | **APPROVED** | **VERIFIED** | `CommerceOrderBuyerGstVerificationTest::test_state_mismatch_resolves_with_verified_bill_to_without_touching_ship_to` |
| 9 | Duplicate / concurrent requests | **APPROVED** | **VERIFIED** | Idempotent tests; `BuyerGstB2cConversionMysqlConcurrencyTest` **2/2**; `StatutoryCommerceMintMysqlConcurrencyTest` **5/5** (P-20-08) |
| 10 | Receipt publication pending/retry | **APPROVED** | **VERIFIED** (publisher) | `RadiumBoxStatutoryReceiptPublicationTest` **20/20**; prod allowlist **8** IDs unchanged — **no** prod publish in this task |

### Reconciliation notes

- **P-20-14 engineering closure:** Case **#4** upgraded **INFERRED → VERIFIED** via dedicated regression class above; **business approval unchanged** (Owner P-20-13).
- **Production protected journeys (flags ON):** still **NOT RUN** — separate activation instruction required.
- **Case #10** business approval does **not** authorise new allowlist IDs or live HTTP publication.
- Prior **`docs/desk-p19-62-gst-b2c-fallback-ca-approval-matrix.md`** row-level **PENDING** entries are **superseded for business acceptance** by this Owner record for the **P-20-12 ten-case set** only; detailed CA policy rows there may still guide remark wording SOP.

---

## Remaining gates (explicit)

Separate Owner instruction still required for:

1. `STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED=true` on production  
2. `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED=true` on production  
3. Production verification HTTP / mint / IRN / receipt expansion  

**Production mutations in P-20-13:** **NO — Not performed.**