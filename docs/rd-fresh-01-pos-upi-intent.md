# RD-FRESH-01 — POS UPI intent (implemented)

**Ledger:** RadiumDesk-P-04-09-31  
**Baseline:** `v4.0.66` / `0392d549` plus this implementation  
**Status:** Implemented and validated locally. Not deployed. No production bank/VPA data.

## Operator model

| Method | Behaviour |
|--------|-----------|
| Cash | Immediate `completeSale()` |
| Card | Immediate `completeSale()` |
| Bank Transfer | Immediate `completeSale()` |
| Cashfree | Existing non-POS `orders` path only. The POS Cashfree label remains an immediate tender if selected; this flow does not call Cashfree. |
| UPI | Persist intent → local QR → human bank check + UTR → `completeSale()` once |

A displayed QR is **not** payment confirmation.

## Stock hold

Pending UPI intents reserve **serials and quantity-only stock** through the existing reservation tables and `reserved_qty`. Concurrent Cash sales can only take remaining `available_qty`.

## Verification

Permission: `pos.payments.verify` (seeded, assigned to no role).  
Required: UTR, matching confirmed amount, and “I checked the live bank account for this credit.”  
No screenshots. No bank API. No UPI refund API.

## Finance

Unchanged: Cash → 1000/4000; UPI and other non-cash → pooled 1100/4000.  
`receiving_bank_account_id` is operational only.

## Expiry

`config/pos.php` → `upi_intent_expires_minutes` (env `POS_UPI_INTENT_EXPIRES_MINUTES`, default 60). Owner policy. Expired intents are abandoned lazily and release reservations. Rows are not deleted.

## QR rendering

The persisted `upi_uri` is the source of truth. The pending screen renders it with a browser-side `qrcode` bundle at `public/js/pos-upi-qr.js` (source `resources/js/pages/pos-upi-qr.js`). No PHP/GD image stack. Regenerating the QR for the same intent reuses the same URI/`tr`.

## Production setup (separate ticket)

1. Create the three receiving `finance_bank_accounts` rows with owner-supplied last four.  
2. Add enabled UPI profiles (VPA + payee name) for those accounts.  
3. Assign `pos.payments.verify` to the people the owner chooses.  
4. Do not deploy this work until that gate is approved.
