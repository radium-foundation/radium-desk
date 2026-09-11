# P-243 POS + invoice daily operator workflow

Prompt ID: `RadiumDesk-P-07-09-243`  
Branch: `feat/irn-foundation-phase-a`  
Before SHA: `3a0c0de8`

## Implemented

1. **PDF low-ink** — stroke-only cards; table header and TOTAL are hairlines + navy text, not filled bars.
2. **BILL TO / SHIP TO** — always printed. Distinct commerce shipping prints the shipping address. POS and any invoice without a distinct ship-to prints **Same**. Shipping is never invented.
3. **Serials** — page 1 holds numbered serials 1–50. Annexure A is remainder only (51+).
4. **Stamp / IRN** — Authorized Signatory + stamp stay on the right at the same top Y as the e-Invoice Verification block.
5. **Payment PDF** — prints Payment Status (Paid when a method is stored) and Mode of Payment. Does not print invoice date as Payment Date. Unpaid complete-sale accounting was not added.
6. **GSTIN** — lookup/search return normalized master GSTIN; counter applies it on click and re-applies after autofill. Blank POS GSTIN does not wipe `inventory_customers.gstin`. Form GSTIN is the sale snapshot (B2C switch does not copy master GSTIN onto the sale).
7. **UQC** — IRN remains fail-closed. Product form already requires an explicit UQC for IRN. Remaining accessory SKUs were not bulk-assigned.
8. **Round off** — nearest rupee on POS sale total and new statutory `invoice_value`. GST/tax unchanged. NIC `RndOffAmt` added from stored rounding.
9. **Order ID** — still `POS-%06d`. RP1 is not a Desk convention.
10. **Services** — not enabled. SAC for warranty/AMC/RD on POS hardware is not established.
11. **Serial scan** — `GET /pos/serials/match`, Enter adds, form Enter no longer completes the sale, visible reject reasons.
12. **Operator IRN flash** — sale completion and sale show distinguish generated / pending / blocked / B2C N/A.

## STOP (not implemented)

- Unpaid Bank Transfer that skips finance collection (no AR model).
- Changing historical `POS-%06d` order IDs / adopting RP1 without a Desk scheme.
- POS bundled Manufacturer Warranty / Extended Warranty / AMC / RD Service lines (SAC not established).
- Assigning UQC to RFID packs, ribbons, cleaning kits, or the PKG-UI test SKU.
- New production sale.
- Historical invoice/PDF/payment mutation.

## Production ABC MOBILE MART (read-only)

- Customer id 360, phone 8279573885, **master GSTIN `09ANQPA2385P1ZB`**.
- Billing profile present (imported last Admin POS address). Empty GSTIN field was UI/snapshot, not a missing master value.
