# Dashboard Issue Invoice fix — RDE318434 — RadiumDesk-P-07-09-115

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-115`  
**Mode:** Diagnose and fix the existing Dashboard Issue Invoice flow. No parcel. No Shiprocket.

P-07-09-113 and P-07-09-114 were already assigned on a dirty local ledger (isolated READY for RDE318434 / RDE318477). This ticket uses the next unused ID after origin `P-07-09-112`.

## Production before

Hardware fulfilment **12** / RDE318434 / commerce **738** / CO-000738:

- State `serials_allocated` (serial **10553584**, Delhi, sold)
- Paid, READY already done
- `statutory_invoice_id` null; no invoice by source
- Commerce line HSN 84716050, taxable 2117.80, tax 381.20, **gst_percentage null**
- Dashboard modal opened with the correct summary; Issue Invoice appeared to do nothing

Root cause (verified):

1. `mint()` casts a null GST rate to `0.0`, then `GstSplitService` rejects `0%` with tax > 0 (`GST rate is missing or invalid.`).
2. `bootstrap/app.php` `shouldRenderJsonWhen` did not include hardware fulfilment POSTs. The Dashboard fetch follows the ValidationException 302 to HTML 200 and treated it as success, closing the modal with no toast.

No invoice was created. Serial remains 10553584. Restricted orders untouched.

## Fix

- Render JSON for `inventory/hardware-fulfilments/*` AJAX/`Accept: application/json` exceptions.
- Derive GST % from existing taxable/tax when the Box line omitted `gst_percentage`, only if the amounts reconcile. Unreconciled tax still fails closed.
- Dashboard modal requires `payload.ok === true` and surfaces validation errors; invoice submit is single-flight.

## Not performed in the implementation commit

Parcel snapshot, Shiprocket, serial change, SQL invoice insert, payment/catalog edits, RDE318437/438/400/RIN/frozen seven.
