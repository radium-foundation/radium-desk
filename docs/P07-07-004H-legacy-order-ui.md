# P07-07-004H — Legacy Imported Order UI

Minimal UI for orders with `legacy_source` set (RadiumBox import). No badges.

## 1. Legacy visual indicator

**Scope:** Order ID display only.

- Apply class `legacy-imported-order-id` (muted amber text, no badge).
- Tooltip: `Legacy imported order • Imported by {agent} • {date}`

**Touch points:** Order workspace header/sticky bar, service case customer-order summary.

## 2. Copy interaction

**Scope:** Order ID and serial number where shown in workspace and case detail.

- **Serial number:** clickable copy (`x-copyable-identifier`) with toast `Serial number copied`.
- **Order ID:** navigates to order details via `x-order-identifier` (link where applicable). No copy.

**Implementation:** `x-copyable-identifier` (serial only), `x-order-identifier` (order display + legacy styling), `initCopyableIdentifiers()` in `app.js`.

## 3. Case detail metadata

Below customer-order summary fields, when `legacy_source` is set:

> Imported from legacy system by {agent}

Small muted text only.

## 4. Fulfillment protection (Assign Ref. No. only)

When `legacy_source` is set and service reference is not yet assigned:

- Intercept assign-ref submit (dashboard inline, batch modal, order workspace form).
- Modal copy:
  - *Legacy imported order. Verify customer, serial, invoice and eligibility.*
  - Checkbox: *Verified legacy order details*
- Audit event: `legacy_order.verified_for_fulfillment`

Non-imported legacy RD orders keep existing `legacy.verification_completed` flow.

## 5. Tests

- Legacy imported UI attributes and metadata on case show / order workspace
- Fulfillment verification required before assign ref on imported orders
- Normal and non-imported legacy orders unchanged
