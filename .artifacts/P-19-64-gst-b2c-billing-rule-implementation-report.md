# P-19-64 — Final GSTIN failure-to-B2C billing rule

**ChatGPT ID:** P-19-64  
**Cursor ledger ID:** `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef`  
**Desk ledger row:** `RadiumDesk-P-19-64`  
**Baseline:** `origin/main` @ `35ed62843` (PR #367)  
**Start:** 2026-11-19T21:05:00+05:30 (approx.)  
**End:** 2026-11-19T21:25:00+05:30 (approx.)

## VERIFIED

- Approved B2C path: `buyerGstinForMint=null`, order `buyer_gstin` retained, conversion audit preserved.
- `EInvoiceEligibility` returns `b2c_not_eligible` when `buyer_gstin` blank — no B2B IRN queue.
- PDF `footerRemark` on payload for authorized conversion invoices; `StatutoryInvoiceB2cFooterRemarkResolver` uses conversion `invoice_remark` (masked).
- `attachIssuedStatutoryInvoice` links `statutory_invoice_id` on conversion after mint.
- MariaDB concurrency **re-run** after changes: **2/2 PASS**, **42** assertions, ~**134s** (local disposable DB).

## INFERRED

- GST rate/POS unchanged: mint uses existing line totals and `place_of_supply_state` from commerce order.

## UNKNOWN

- CI MariaDB concurrency (not provisioned on CI in this session).

## Email-log confirmation

**GAP documented** — `docs/desk-p19-64-gst-b2c-email-log-gap.md`. No email sent; no confirmation claimed.

## Tests (focused)

| Suite | Result | Exit | Duration |
| --- | --- | --- | --- |
| `CommerceOrderBuyerGstB2cBillingRuleTest` + fallback + verification | **28/28 PASS** | 0 | ~3s |
| `BuyerGstB2cConversionMysqlConcurrencyTest` | **2/2 PASS** | 0 | ~134s |

## Migration impact (not applied prod)

- `2026_11_19_140000_add_statutory_invoice_id_to_commerce_order_buyer_gst_b2c_conversions_table`

## Flags

Both remain default **OFF** — no production activation.

## NO — Not performed

Production migration · deployment · backup · WhiteBooks prod · invoice **23163**/**RBP2295** · customer email send · full PHPUnit