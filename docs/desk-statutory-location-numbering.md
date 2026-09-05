# Desk statutory location numbering

**Project:** Radium Desk  
**Ledger:** RadiumDesk-P-05-09-08, updated RadiumDesk-P-05-09-12 / P-05-09-13  
**Date:** 2026-09-05  
**Tree:** `/Users/ravi/RadiumWebsites/radium-desk-pos-release` `main`

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

```text
INV-{GST_STATE_CODE}{FY_CODE}{RUNNING_SERIAL}
```

| Token | Meaning |
|---|---|
| `GST_STATE_CODE` | Issuer GST state: Delhi `07`, Mumbai `27` |
| `FY_CODE` | Last digit of FY start year + last digit of FY end year |
| `RUNNING_SERIAL` | Per issuer + FY serial starting at `1`. Not zero-padded. Resets to `1` on 1 April. |

The 4-digit `{GST_STATE_CODE}{FY_CODE}` prefix is fixed for that issuer for the whole financial year. Only the running serial increments.

Do **not** treat the number as `INV-0767` plus a separate `0`/`67` seed, and do **not** initialize `current_value` to `7670` or `27670`.

## FY examples

| FY | Code | Delhi serial 1 | Mumbai serial 1 |
|---|---|---|---|
| 2026–27 | `67` | `INV-07671` | `INV-27671` |
| 2027–28 | `78` | `INV-07781` | `INV-27781` |

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

`Service → B2B/B2C → customer state → Billing issuer`

B2B is a valid customer GSTIN. B2C is no customer GSTIN.

| Kind | Customer location | Billing issuer |
|---|---|---|
| B2B | Maharashtra (GSTIN state `27`) | Mumbai |
| B2B | Any other known Indian GST state | Delhi |
| B2C | Any state | Delhi |

Commerce lines whose HSN/SAC starts with `99` are services. Other classifiable numeric HSN codes are products. Mixed product/service invoices fail closed.

## Billing issuer vs Place of Supply

The service rule chooses the **billing issuer only**.

Customer state and Place of Supply stay on the invoice as supplied. A Maharashtra B2B service billed from Mumbai still keeps Place of Supply = Maharashtra. A B2C service billed from Delhi still keeps the customer's Place of Supply.

## Historical invoices

Historical Admin numbers are not reminted or rewritten. Issued Desk `invoice_number` values are immutable.

Production sequence rows are **not** initialized by this change.
