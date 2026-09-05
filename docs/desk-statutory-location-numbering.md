# Desk statutory location numbering

**Project:** Radium Desk  
**Ledger:** RadiumDesk-P-05-09-06  
**Date:** 2026-09-05  
**Tree:** `/Users/ravi/RadiumWebsites/radium-desk-pos-release` `main`

## Owner decision (finalized)

New statutory invoices from **2026-09-01**:

- Delhi continues at **`INV-07671`**
- Mumbai continues at **`INV-27671`**
- Each location increments independently
- Historical Old Admin invoice numbers are preserved and must not be reminted

These are Finance Hub GST numbers. They are not POS receipts.

## Desk location mapping

Only documented production Desk branch codes are mapped:

| Location | Branch code | Prefix | First sequence |
|---|---|---|---|
| Delhi | `DELHI-RETAIL` | `INV-07` | 671 |
| Mumbai | `MUMBAI` | `INV-27` | 671 |

No other branch is assigned a series. That is fail-closed, not a guess.

## Engine

`StatutoryLocationSeries` + location-scoped `invoice_sequences` rows. `lockForUpdate` is unchanged. Reprint / second issue returns the same allocated number.

POS `INV-{branch}-{calendar year}-{5-digit seq}` is unchanged.

Seller GSTIN, legal name, and address remain separate configuration and still fail closed when unset.

Production `.env` / live sequences were **not** written by this change.
