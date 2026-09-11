# Hardware serial correction — RDE318437/467/438 (P-07-09-227)

Prompt ID: `RadiumDesk-P-07-09-227`  
Branch: `feat/hardware-fulfilment-ui-ux`  
Before SHA: `920192a4aa1ba9857c5c947649df9bd9a8696e89`

## Problem

Three shipped Box hardware orders were fulfilled with incorrect scanned/allocated serials (operator/CLI passed wrong `--serials`). Invoices were issued without IRN; AWBs and shipments were already live.

| Order | Customer | Wrong serial | Correct physical serial |
|---|---|---:|---:|
| RDE318437 | B Madhu | 10564333 | 10553366 |
| RDE318467 | Deepak Kashyap | 10564239 | 10553300 |
| RDE318438 | palani selvam U | 10983282 | 11013986 |

## Pre-change verification (production read-only)

- Commerce + HF present for all three; state `shipped`.
- Statutory invoices `INV-671124/125/126`, status `issued`.
- IRN: `e_invoice_records.status=skipped` (no submitted IRN — in-place serial + PDF correction allowed).
- Wrong inventory serials: `sold`; correct serials: `available`, matching product IDs.
- AWBs unchanged: `77178172794`, `SF3415118984KAK`, `14112363526697`.

## Application workflow implemented

New service `HardwareFulfilmentSerialCorrectionService` + artisan `desk:correct-hardware-serial`:

1. Fail closed if IRN submitted.
2. Verify current allocated serial matches `--from`.
3. Verify `--to` exists, same product, available, not allocated elsewhere.
4. Release wrong serial (`restoreSerialFromSale` + `sale_cancel` movement).
5. Re-point `hardware_fulfilment_serials` row and mark correct serial sold.
6. Overwrite `metadata.invoice_serials` (audit in `metadata.serial_corrections`).
7. Regenerate statutory PDF via `StatutoryDocumentService::regenerateForHardwareSerialCorrection()`.
8. Preserve invoice financial fields, customer data, AWB/shipment.

No raw SQL mutations.

## Production correction (surgical overlay)

Deployed to `/var/www/radium-desk` (3 files + PDF regenerate helper). Backup: `storage/backups/p-07-09-227-20260911T044708Z/StatutoryDocumentService.php.pre`.

Commands (actor user id 1):

```bash
desk:correct-hardware-serial RDE318437 --from=10564333 --to=10553366 --actor=1
desk:correct-hardware-serial RDE318467 --from=10564239 --to=10553300 --actor=1
desk:correct-hardware-serial RDE318438 --from=10983282 --to=11013986 --actor=1
```

## Post-change verification (production)

| Order | HF serial | invoice_serials | Wrong inv status | Correct inv status | PDF |
|---|---|---|---|---|---|
| RDE318437 | 10553366 | [10553366] | available | sold | correct |
| RDE318467 | 10553300 | [10553300] | available | sold | correct |
| RDE318438 | 11013986 | [11013986] | available | sold | correct |

Financial values unchanged (e.g. RDE318437 invoice_value 2499.00; RDE318438 3799.00). AWBs unchanged.

## Permanent allocation guard

Audit of hardware fulfilment entry points:

- UI `AllocateHardwareFulfilmentSerialsRequest` requires `serials` array.
- `HardwareSerialAllocationService::normalizeSelections()` fails closed when serials missing/short.
- CLI `desk:fulfil-hardware --step=allocate` requires explicit `--serials` (`Stock is not auto-picked`).

**No automatic serial picker exists in the fulfilment path.** Wrong serials in this incident came from explicit operator/CLI serial arguments, not system inference.

Regression tests added: `HardwareFulfilmentSerialCorrectionTest` (correction workflow + isolated allocate without serials fails closed).

## Tests

- `tests/Feature/HardwareFulfilment/HardwareFulfilmentSerialCorrectionTest.php` — 5 passed
- `tests/Feature/HardwareFulfilment/HardwareFulfilmentP4AllocationTest.php` — 15 passed (existing allocation guards)
- Pint on dirty files — passed

## Deployment note

Full `deskd` release **not** performed. Only surgical production overlay for correction service + PDF regenerate helper.
