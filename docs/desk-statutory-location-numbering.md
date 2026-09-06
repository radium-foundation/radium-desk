# Desk statutory location numbering

**Project:** Radium Desk  
**Ledger:** RadiumDesk-P-05-09-08, updated RadiumDesk-P-05-09-12 / P-05-09-13  
**Date:** 2026-09-05  
**Tree:** `/Users/ravi/RadiumWebsites/radium-desk-pos-release` `main`

> **Owner service-policy correction (RadiumDesk-P-06-09-24), implemented RadiumDesk-P-06-09-31:** Non-Maharashtra B2C service invoices use isolated Delhi B2C `INV-671…` (`location:delhi_b2c`). Maharashtra B2C and all Mumbai service invoices use `INV-27671…`. Delhi B2B and Delhi products remain `INV-07671…` (`location:delhi`). FY 2027–28 Delhi B2C numbering is UNKNOWN and fails closed. See `docs/desk-service-statutory-series-policy.md`.

## Legal seller

- Legal entity: **Phil Technologies (P) Limited**
- Brand: **Radium**
- The company has **4 GST registrations**. This rollout covers **Delhi and Mumbai only**. The other 2 registrations stay out of scope.

Seller GSTIN, registered address, and seller state are **issuer-specific**. They are not a single global `STATUTORY_INVOICE_GSTIN_SCOPE` value.

| Field | Scope | Production key |
|---|---|---|
| Legal name | Company | `STATUTORY_INVOICE_LEGAL_NAME` |
| Delhi GSTIN | Delhi issuer | `STATUTORY_INVOICE_DELHI_GSTIN` |
| Mumbai GSTIN | Mumbai issuer | `STATUTORY_INVOICE_MUMBAI_GSTIN` |
| Delhi registered address | Delhi issuer | `STATUTORY_INVOICE_DELHI_ADDRESS` |
| Mumbai registered address | Mumbai issuer | `STATUTORY_INVOICE_MUMBAI_ADDRESS` |
| Delhi seller state | Delhi issuer | `STATUTORY_INVOICE_DELHI_STATE` (or derived from GSTIN `07`) |
| Mumbai seller state | Mumbai issuer | `STATUTORY_INVOICE_MUMBAI_STATE` (or derived from GSTIN `27`) |

The Owner-supplied `DELHI-RETAIL` / `MUMBAI` inventory branch GSTINs are the in-scope Delhi and Mumbai registrations.

Owner-supplied registered addresses (RadiumDesk-P-05-09-13):

| Issuer | Registered address |
|---|---|
| Delhi | 1312, Hemkunt Chambers, Nehru Place, New Delhi 110019 |
| Mumbai | G40, Harmony Mall, Link Road, Goregaon, Mumbai 400104 |

These are the config defaults when the address env keys are empty. Env can still override. Test suites continue to use test-only address strings.

Mint fails closed if the resolved issuer has no valid GSTIN, the GSTIN state does not match Delhi `07` / Mumbai `27`, legal name is empty, or the issuer address is empty. Unknown branches and the other GST registrations fail closed. Place of Supply does not choose the issuer or seller GSTIN.

## Invoice formula

Location series (Delhi B2B / products, and all Mumbai):

```text
INV-{GST_STATE_CODE}{FY_CODE}{RUNNING_SERIAL}
```

Isolated Delhi B2C service series (FY 2026–27 only):

```text
INV-{FY_CODE}{RUNNING_SERIAL}
```

| Token | Meaning |
|---|---|
| `GST_STATE_CODE` | Issuer GST state: Delhi `07`, Mumbai `27`. Omitted on Delhi B2C `INV-671…`. |
| `FY_CODE` | Last digit of FY start year + last digit of FY end year |
| `RUNNING_SERIAL` | Per sequence key + FY serial starting at `1`. Not zero-padded. Resets to `1` on 1 April. |

The 4-digit `{GST_STATE_CODE}{FY_CODE}` prefix is fixed for that issuer for the whole financial year. Only the running serial increments. Delhi B2C uses prefix `INV-67` and must not consume `location:delhi`.

Do **not** treat the number as `INV-0767` plus a separate `0`/`67` seed, and do **not** initialize `current_value` to `7670` or `27670`.

## FY examples

| FY | Code | Delhi B2B / product serial 1 | Delhi B2C serial 1 | Mumbai serial 1 |
|---|---|---|---|---|
| 2026–27 | `67` | `INV-07671` | `INV-671` | `INV-27671` |
| 2027–28 | `78` | `INV-07781` | UNKNOWN — fail closed | `INV-27781` |

Further Delhi FY26–27 examples: serial 2 `INV-07672`, serial 5 `INV-07675`, serial 999 `INV-0767999`, serial 1000 `INV-07671000`.

Indian FY starts 1 April. `2026-09-01` is FY 2026–27.

## Product issuer

`Product → Branch → Billing issuer`

| Branch code | Issuer | FY26–27 prefix |
|---|---|---|
| `DELHI-RETAIL` | Delhi | `0767` |
| `MUMBAI` | Mumbai | `2767` |

Customer state does not move a product invoice. A Maharashtra customer buying from `DELHI-RETAIL` is still billed from Delhi.

## Service issuer

`Service → B2B GSTIN state or B2C billing_state → Billing issuer / series`

B2B is a valid 15-character customer GSTIN. B2C is a null/empty GSTIN plus a recognised `commerce_orders.billing_state`. Invalid non-empty GSTIN fails closed.

| Kind | Location source | Billing issuer | FY 2026–27 series |
|---|---|---|---|
| B2B | GSTIN state `27` | Mumbai | `INV-27671…` (shared with Mumbai B2C) |
| B2B | GSTIN state ≠ `27` | Delhi B2B | `INV-07671…` |
| B2C | `billing_state` = Maharashtra | Mumbai | `INV-27671…` |
| B2C | recognised non-Maharashtra `billing_state` | Delhi B2C | `INV-671…` |

Commerce lines whose HSN/SAC starts with `99` are services. Other classifiable numeric HSN codes are products. Mixed product/service invoices fail closed. Missing `gst_percentage` (or other required line tax fields) fails closed before allocation.

## Billing issuer vs Place of Supply

The service rule chooses the **billing issuer only**.

Customer state and Place of Supply stay on the invoice as supplied. A Maharashtra B2B service billed from Mumbai still keeps Place of Supply = Maharashtra. Place of Supply never chooses the issuer and cannot substitute for missing B2C `billing_state`. B2B issuer follows the GSTIN state even when `billing_state` conflicts.

## Historical invoices

Historical Admin numbers are not reminted or rewritten. Issued Desk `invoice_number` values are immutable.

Production sequence rows are **not** initialized by this change.
