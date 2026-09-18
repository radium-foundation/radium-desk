# RB* Service Statutory Invoicing — Implementation (P-18-09-32)

**Baseline:** v4.0.84 / `c6d55ab68d7645e8eaaccf66a03c29c20bfc489b`  
**Owner decision:** Radium Desk is the statutory invoice issuer for RadiumBox `RB*` **service** orders (not `RBP*` hardware).

## Architecture

### Flow

1. Desk support order (`orders.order_id` = `RB*`) triggers `StatutoryInvoiceService::issueFromSupportOrder()`.
2. For `RB*` only, `RadiumBoxServiceCommerceSnapshotService::ensureForSupportOrder()` fetches authoritative billing/tax from the RadiumBox storefront spoke (`GET /api/integrations/v1/rd-orders/{id}` → `data.service_commerce`).
3. Snapshot creates/reuses `commerce_orders` with:
   - `channel` = `radiumbox_com`
   - `source_type` = `commerce_order`
   - `source_id` = `RB*`
   - `idempotency_key` = `statutory:radiumbox_com:commerce_order:RB*`
   - `support_order_id` = Desk support order id
4. `issueFromSupportOrder()` → `commerceOrderForSupportOrder()` → `issueFromCommerceOrder()` → existing mint/GST/PDF/idempotency.

### Prefix routing (narrow)

| Prefix | Statutory commerce channel | Notes |
|--------|---------------------------|-------|
| `RD*` | `rdservice_in` | Unchanged |
| `RB*` (service) | `radiumbox_com` | New snapshot path; **not** hardware |
| `RBP*` / `RDE*` / hardware | `radiumbox_com` via ingest/fulfilment | Unchanged serial-allocation guard |

`BusinessOrderId::isRadiumBoxService()` matches longest-prefix `RB` service only (excludes `RBP`, `RBX`).

### RadiumBox API contract extension

`DeskOrderLookupService::orderEnvelope()` adds `data.service_commerce` from `order_rdservice` without requiring a billing `orders` row:

- billing state / POS, address, GSTIN, taxable/tax/total, GST%, catalog HSN/SAC, service description, serial metadata.

Backwards compatible: existing consumers ignore the new block.

### SAC policy

- Commerce snapshot stores catalog SAC **998314** from Box source (not silently replaced at ingest).
- Statutory mint uses `ServiceSacResolver`: `radiumbox_com` is in `service_sac.rd_service.channels`; description needles map service lines to **998313**.
- `amc.match_amcid` remains on `rdservice_in` / `rdservice_net` only so hardware `amcid` values on `radiumbox_com` are not rewritten.
- Hardware lines keep HSN via `shipping_line_kind = physical_merchandise` (unchanged).

### Idempotency

- **Snapshot:** unique `(channel, source_type, source_id)` + `idempotency_key`; `lockForUpdate()` on create; repeated `ensureForSupportOrder()` returns the same row.
- **Invoice:** existing commerce-order idempotency via `issueFromCommerceOrder()`.

### Fail-closed

Snapshot validation rejects missing/invalid billing state, address, tax fields, or tax arithmetic before commerce row creation.

## Production dependency

Both must deploy before RB222 repair:

1. **radiumbox.com** — `service_commerce` in lookup API  
2. **radium-desk** — snapshot + routing in this change set

## Non-goals (this task)

- No RB222 production repair, commerce snapshot, or invoice mint  
- No migration (existing `commerce_orders` schema sufficient)

## RB222 verified fixture (tests only)

Tamil Nadu B2C, taxable 507.63, tax 91.37, total 599.00, 18%, SAC 998313 at mint, IGST inter-state vs Delhi seller.

## Invoice presentation (P-18-09-33)

- **Logo:** `config('branding.logo')` → `public/brand/logo.png` (black raster, embedded by `SimplePdfRenderer`; not `radiumbox-logo-white.png`).
- **Optional add-ons:** `StatutoryInvoiceCommerceLinePresentation` excludes zero-value duration/callback placeholders (`RD Technical Support …`, `Not Required`) at mint/PDF; purchased express/AMC lines with material value remain.
- **Service descriptions:** commerce `description` is used for statutory lines; `variant=regular` no longer replaces the printed service text.
