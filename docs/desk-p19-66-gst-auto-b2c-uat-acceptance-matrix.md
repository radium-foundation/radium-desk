# P-19-66 — GST automatic B2C UAT acceptance matrix

**Baseline code:** `main` @ **`dfe82ffcf17f2ad7a0cd360deba59fed319873aa`** (P-19-65)  
**Execution lanes:** Local PHPUnit + Http::fake (synthetic) · Live isolated UAT · Production  

Legend: **PASS** = evidenced in this task · **BLOCKED** = environment/approval gate · **NOT RUN** = out of scope or not executed

| # | Scenario | Fallback ON expected | Local regression | Live isolated UAT | Prod |
| --- | --- | --- | --- | --- | --- |
| 1 | Verified Active GSTIN | B2B mint with GSTIN | **PASS** (`CommerceOrderBuyerGstVerificationTest`, fallback active test) | **BLOCKED** | **NOT RUN** |
| 2 | Verified Provisional GSTIN | B2B, no conversion | **PASS** (`test_provisional_registration_stays_b2b`) | **BLOCKED** | **NOT RUN** |
| 3 | Suspended / Cancelled | Auto B2C, blank GSTIN, remark | **PASS** (`test_suspended_registration_converts_automatically`) | **BLOCKED** | **NOT RUN** |
| 4 | Invalid / incomplete GSTIN (local) | Auto B2C | **PASS** (`CommerceOrderBuyerGstAutoB2cConversionTest::test_local_invalid`) | **BLOCKED** | **NOT RUN** |
| 5 | Invalid provider response | Auto B2C | **PASS** (`test_invalid_gstin_response_converts_on_verify`) | **BLOCKED** | **NOT RUN** |
| 6 | State mismatch + verified Bill To | B2B when verified | **PASS** (`test_state_mismatch_resolves_with_verified_bill_to`) | **BLOCKED** | **NOT RUN** |
| 7 | Incomplete provider snapshot | Auto B2C | **INFERRED** (policy + eligibility) | **BLOCKED** | **NOT RUN** |
| 8 | Provider timeout | Auto B2C, technical remark | **PASS** (`test_provider_timeout_converts_automatically`) | **BLOCKED** | **NOT RUN** |
| 9 | Rate limit / 5xx | Auto B2C, technical remark | **PASS** (429/503 tests) | **BLOCKED** | **NOT RUN** |
| 10 | Malformed JSON response | Auto B2C, no false invalid claim | **PASS** (`test_malformed_provider_response`) | **BLOCKED** | **NOT RUN** |
| 11 | Provider error payload | Auto B2C | **PASS** (`test_provider_error_payload_converts_automatically`) | **BLOCKED** | **NOT RUN** |
| 12 | No GSTIN submitted | Native B2C | **INFERRED** (classification empty GSTIN path) | **BLOCKED** | **NOT RUN** |
| 13 | Fallback flag OFF | GST review hold (no auto row) | **PASS** (`test_fallback_disabled_preserves_gst_review_hold`) | **BLOCKED** | **NOT RUN** (prod flag OFF) |
| 14 | Duplicate ensure / approve | Single conversion row | **PASS** (idempotent tests + MariaDB **2/2**) | **BLOCKED** | **NOT RUN** |
| 15 | Post-mint conversion | Rejected | **PASS** (`test_b2c_conversion_rejected_after_persisted_statutory_invoice_mint`) | **BLOCKED** | **NOT RUN** |
| 16 | B2B mint after conversion | Blocked | **PASS** (`test_b2b_mint_request_blocked_after_authorized_conversion`) | **BLOCKED** | **NOT RUN** |
| 17 | Blank buyer GSTIN on invoice | `buyer_gstin` null | **PASS** (billing rule tests) | **BLOCKED** | **NOT RUN** |
| 18 | Footer remark masked in PDF path | Resolver + PDF smoke | **PASS** (`CommerceOrderBuyerGstB2cBillingRuleTest`) | **BLOCKED** (no human PDF UAT) | **NOT RUN** |
| 19 | IRN not queued for B2C | `b2c_not_eligible` | **PASS** (billing rule EInvoice test) | **BLOCKED** | **NOT RUN** |
| 20 | GST rate / POS unchanged | Same order mint inputs | **INFERRED** (no tax regression in suite; mint uses order totals) | **BLOCKED** | **NOT RUN** |

**Live isolated UAT blocker:** No Owner-verified isolated Desk UAT (URL, DB name, isolation proof, backup) re-validated on **2026-11-19** for GST flag-on mint journeys. Historical **`radium-desk-uat`** references exist in ledger but are **not** treated as current isolated GST UAT without re-verification.