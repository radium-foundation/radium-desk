# P-19-62 — CA/Finance approval matrix (B2C fallback)

**ChatGPT ID:** P-19-62  
**Engineering baseline:** PR #365 + P-19-62 regressions  
**Statutory rollout:** **Business acceptance (P-20-12 ten-case matrix):** **10/10 APPROVED** via **Owner-designated Finance/CA** — see **`docs/desk-p20-13-gst-approval-matrix-validation.md`** (2026-11-20, task **P-20-13**). Rows below remain granular CA policy notes; they are **not** independent sign-offs unless separately evidenced.

**Production flag activation:** still **NOT AUTHORIZED** without a separate Owner instruction.

**Engineering note (P-19-65 / P-19-66):** When `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED=true`, eligible GSTIN failures **auto-convert** without manual approval. CA rows that say “manual approval” need **written updates** to approve the automatic runtime path — not a return to GSTIN-only invoice holds.

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