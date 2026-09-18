# Radium Desk — rdservice.net channel contract alignment

**Ticket:** RadiumDesk-P-18-09-52  
**Companion:** RDServiceNet-P-18-09-02  
**Date:** 2026-09-18

Documents the verified Desk-side contract consumed by rdservice.net Phase 1 ingest. No production changes in this ticket.

## Statutory numbering (authoritative)

Desk production uses location series:

- Delhi B2C: `INV-67{serial}`
- Delhi B2B: `INV-0767{serial}`
- Mumbai: `INV-2767{serial}`

Not `IND07` / `IND27`.

## Secret configuration (not provisioned in this ticket)

Both sides must configure:

`CHANNEL_INGEST_SECRET_RDSERVICE_NET`

Empty secret → HTTP 401. Never commit values.

## Write-back

Desk does not push `invoice_number` to rdservice.net in current code. See companion net doc for unresolved Option A/B.

## Tests added

`tests/Feature/StatutoryInvoice/RdServiceNetPayloadContractAlignmentTest.php` — aligned payload ingest eligibility, B2B RN60/RN68 shapes, callback suppression, Dadra state normalization acceptance.

## GST rounding

rdservice.net sends authoritative `tax_total` / `taxable_value` with `gst_percentage` derived from those amounts. Exclusive lines may differ from `round(taxable × rate, 2)` by up to one paisa (same tolerance pattern as RadiumBox RB* service). Mint eligibility and issuance apply `exclusivePaisaTolerance = 1` for channel `rdservice_net`.
