# P-27-21 Workstream A — POS shipping e-Invoice (long-term fix)

**ChatGPT prompt ID:** P-27-21  
**Supersedes packaging:** none — ship **before** Workstream B  
**Target production case:** `INV-0767631` (statutory id **14910**, sale **109**) — recovery is **code deploy + authorized IRN retry**, not row rewrite.

## Branch

`candidate/p27-21-pos-shipping-einvoice` (from `candidate/p27-19-combined-pos-shipping-einvoice` / `0433c7e0`)

## Allowlist (11 application + test paths)

| Path | Role |
|------|------|
| `app/Services/StatutoryInvoice/PosRetailShippingStatutoryLine.php` | Persist shipping statutory line on mint |
| `app/Services/StatutoryInvoice/StatutoryInvoicePosShippingGstSnapshot.php` | Legacy header-only reconciliation |
| `app/Services/StatutoryInvoice/EInvoiceStoredGstGuard.php` | Guard: persisted line OR legacy snapshot |
| `app/Services/StatutoryInvoice/EInvoiceIrnPayloadMapper.php` | IRN items + assessable value incl. shipping |
| `app/Services/StatutoryInvoice/StatutoryInvoiceService.php` | Mint path + header taxable split |
| `app/Services/Customer360/EInvoiceAgentPresentation.php` | Agent copy for `missing_pos_shipping_hsn_sac` |
| `config/statutory_invoices.php` | `pos_retail_shipping` env keys (optional override) |
| `tests/Feature/Inventory/PosRetailShippingTest.php` | POS → statutory integration |
| `tests/Feature/StatutoryInvoice/Inv0767631LegacyPosShippingCompatibilityTest.php` | INV-0767631 shape regression |
| `tests/Feature/StatutoryInvoice/StatutoryInvoicePosShippingLineMintTest.php` | Persisted line mint |
| `tests/Unit/StatutoryInvoice/EInvoiceIrnPayloadMapperPosShippingTest.php` | Mapper unit |
| `tests/Unit/StatutoryInvoice/EInvoiceStoredGstGuardPosShippingTest.php` | Guard unit |

**Excluded:** `tests/bootstrap.php` (worktree autoload only), `docs/cursor-prompt-ledger.md`, wallet/unrelated WIP.

## GST treatment (approved for this RC)

- Shipping **visible** as its own line on customer PDF / statutory items (new mints) or synthetic IRN line (legacy header-only).
- Shipping **taxable value** included in goods-supply e-Invoice assessable total; GST rate = **max product line rate** on the invoice.
- HSN/SAC: optional `STATUTORY_POS_RETAIL_SHIPPING_HSN_SAC`; if empty, **incidental** = highest-rate product line HSN (e.g. `84716050` on INV-0767631). **No invented SAC/rates.**

## Production gates

- Finance/CA sign-off on incidental HSN policy for POS shipping (or set env override).
- Deploy allowlist only; verify RIP/backup; **then** Finance Hub IRN retry for **14910** (Owner approval).
- **Do not** SQL-edit invoice **14910** unless a separate remediation gate approves.
