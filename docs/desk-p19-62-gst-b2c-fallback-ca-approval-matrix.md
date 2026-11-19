# P-19-62 — CA/Finance approval matrix (B2C fallback)

**ChatGPT ID:** P-19-62  
**Engineering baseline:** PR #365 + P-19-62 regressions  
**Statutory rollout:** **NOT READY** until rows below marked APPROVED with written evidence.

| Decision | Engineering proposal (informational) | Status | Evidence |
| --- | --- | --- | --- |
| Suspended recipient registration may be treated as B2C after authorized conversion | Eligible when `inactive_registration` + manual approval | **PENDING** | — |
| Cancelled recipient registration may be treated as B2C after authorized conversion | Same as Suspended (`inactive_registration`) | **PENDING** | — |
| Invalid GSTIN (provider `invalid_gstin_response`) may be treated as B2C | Eligible with manual approval; order retains submitted GSTIN | **PENDING** | — |
| Provisional registration treatment | Desk maps Provisional as **Active** → B2B; conversion **not** offered | **PENDING** | Confirm hold vs B2B mint |
| Customer confirmation required before conversion | Not implemented in code | **PENDING** | Process + UI if required |
| Approved invoice remark wording | Placeholder in `BuyerGstB2cConversionPolicy::defaultInvoiceRemark()` (masked GSTIN) | **PENDING** | Written approved text |
| Place of supply and tax treatment on converted B2C | Uses existing order POS / mint path | **PENDING** | CA sign-off |
| Cases requiring **hold** instead of conversion | Provider outage, mismatch, Provisional, incomplete snapshot | **PENDING** | Hold SOP |
| B2C fallback feature flag activation | `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED` default OFF | **PENDING** | Separate from migration auth |
| Buyer GSTIN verification flag activation | `STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED` default OFF | **PENDING** | Migration + UAT + matrix above |

**APPROVED count:** 0  
**Do not enable flags or deploy for statutory rollout without completing this matrix.**