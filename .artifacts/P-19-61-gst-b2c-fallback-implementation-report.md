# P-19-61 — Controlled B2C fallback for failed GSTIN verification

**ChatGPT ID:** P-19-61  
**Cursor ledger ID:** `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef`  
**Radium desk ledger row:** `RadiumDesk-P-19-61`  
**Report time:** 2026-11-19T20:08:00+05:30 (duration ~UNKNOWN for full prompt; implementation tests ~2.4s)

## VERIFIED

- Base branch **`feature/p19-61-gst-b2c-fallback`** from **`origin/main`** @ **`7edf904bb`** (**v4.1.101** deploy HEAD per P-19-56).
- Existing verification path: mismatch → review when flag on; legacy auto-B2C only when verification **off**.
- Pending prod migration **`2026_11_19_120000_create_commerce_order_buyer_gst_verifications_table`** unchanged on prod (P-19-57).

## INFERRED

- New migration **`2026_11_19_130000_create_commerce_order_buyer_gst_b2c_conversions_table`** must run after verification table in all environments.

## UNKNOWN

- PR/CI merge SHA at report time (see git section after push).

## Risk and reason

**HIGH** — statutory B2B/B2C classification, recipient GSTIN on mint, audit evidence. Code does **not** constitute CA/Finance approval.

## Changes

| Category | Detail |
| --- | --- |
| **Preserved** | Active verified B2B path; provider failure → review only; submitted GSTIN on order |
| **Extended** | `ServiceStatutoryGstB2bClassification`, `CommerceBuyerGstMintGate`, `ServiceStatutoryGstIssuanceDecision`, config |
| **Changed** | Mint gate allows B2C after approved conversion; blocks B2B mint with GSTIN on request |
| **Removed** | Nothing |

## Tests

| Command | Result | Exit |
| --- | --- | --- |
| `phpunit tests/Feature/StatutoryInvoice/CommerceOrderBuyerGstB2cFallbackTest.php tests/Feature/StatutoryInvoice/CommerceOrderBuyerGstVerificationTest.php` | **15/15 PASS** | **0** |

Environment: local worktree, SQLite `RefreshDatabase`, Homebrew PHP **8.5.10**, `buyer_gstin_verification.enabled=true`, `b2c_fallback.enabled=true` in tests only.

## NO — Not performed

- Production migration  
- Feature activation (`STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED` / `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED`)  
- Deployment  
- Invoice operations on **23163** / **RBP2295**  
- WhiteBooks production calls  
- Production data / configuration changes  

## CA/Finance outstanding

See `docs/desk-p19-61-gst-b2c-fallback-ca-approval.md`.