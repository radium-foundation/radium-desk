# Desk statutory location numbering

**Project:** Radium Desk  
**Ledger:** RadiumDesk-P-05-09-08  
**Date:** 2026-09-05  
**Tree:** `/Users/ravi/RadiumWebsites/radium-desk-pos-release` `main`

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
