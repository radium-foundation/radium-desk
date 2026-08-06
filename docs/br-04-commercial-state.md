# BR-04 — Commercial State

**Status:** Implemented  
**Depends on:** BR-03 Context Transparency (foundation)  
**Last updated:** 2026-08-06

---

## Goal

An agent should immediately know whether commercial work is allowed on a case. Commercial posture is a first-class concept with one resolver and consistent UI + enforcement.

Priority (highest first):

1. **Refund Completed** (unless an active commercial service restoration exists for that order/refund)
2. **Refund Initiated**
3. **Case Closed**
4. **Service Restored** (active wallet-reverse attestation — commercial actions allowed)
5. **Open**

---

## Architecture

| Piece | Location |
|-------|----------|
| `CommercialState` enum | `app/Enums/CommercialState.php` |
| `CommercialAction` enum | `app/Enums/CommercialAction.php` |
| `CommercialStateSnapshot` | `app/Data/Commercial/CommercialStateSnapshot.php` |
| `CommercialStateResolver` | `app/Services/Commercial/CommercialStateResolver.php` |
| Presenter | `app/Support/Commercial/CommercialStatePresenter.php` |
| Feature flag | `config/commercial_state.php` → `COMMERCIAL_STATE_ENABLED` (default `true`) |
| C360 banner | `resources/views/components/c360/commercial-state.blade.php` |

**Do not scatter refund/close commercial rules.** UI and eligibility layers must consume `CommercialStateResolver` snapshots only.

Future states (Replacement Approved, Warranty Replacement, Exchange, Chargeback) should be added as new enum cases + resolver branches with explicit priority.

### Blocked actions

| State | Assign Ref No | Paid Service | Paid Appointment | Charge Customer |
|-------|---------------|--------------|------------------|-----------------|
| Open | allowed | allowed | allowed | allowed |
| Service Restored | allowed | allowed | allowed | allowed |
| Case Closed | not gated here* | not gated here* | not gated here* | not gated here* |
| Refund Initiated | blocked | blocked | blocked | allowed |
| Refund Completed | blocked | blocked | blocked | blocked |

\* Case Closed shows the green banner and keeps reopen available; other closed-case gates remain elsewhere (status services). Commercial State does not duplicate them.

### Enforcement consumers

- `CommunicationActionEligibilityService` — Buy RD Service / Buy Product
- `OrderTransactionService::assignTransactionId` — Assign Ref No / service reference
- `SupportAppointmentService::book` — Paid Appointment
- `Customer360OverflowMenuPresenter` — Schedule Appointment menu item
- Dashboard transaction cell — hides assign UI when blocked

---

## Surfaces

### Customer 360

Sticky **Commercial State** card at the top of the drawer (above tabs), always visible when a banner is required.

### Dashboard

- Completed rows: `✓ 23m` → **Resolved in 23m** (neutral/success styling)
- Refund initiated/completed: commercial badge on the case reference cell

---

## Feature flag

```env
COMMERCIAL_STATE_ENABLED=true
```

```php
config('commercial_state.enabled');
app(CommercialStateResolver::class)->enabled();
app(CommercialStateResolver::class)->forIncident($incident);
```

When disabled, banners and commercial gates are skipped (legacy behavior).

---

## Tests

- `tests/Unit/Commercial/CommercialStateResolverTest.php`
- `tests/Feature/Commercial/CommercialStateGoldenTest.php`

Verify: open allows everything; closed allows reopen; refund initiated/completed block commercial actions.

---

## Service restoration after external wallet reverse (implemented 2026-08-06)

**Case driver:** SC28430 / RD3454444 / REF-2026-000020  
**Goal:** After Finance reverses RD Wallet externally, Ops Admin can attest in Desk so commercial actions reopen — **without** mutating `refund_requests`.

### Blocking field (unchanged)

Derived `commercial_state = refund_completed` from terminal `refund_requests.status`. No stored commercial column. Order/case status and business holds are not the commercial gate.

### Implementation

| Piece | Location |
|-------|----------|
| Table | `commercial_service_restorations` (append-only; `revoked_at` soft-ends active row) |
| Model / service | `CommercialServiceRestoration`, `CommercialServiceRestorationService` |
| Permission | `commercial.service.restore` — Admin, Operations Admin, Super Admin |
| Resolver | `CommercialStateResolver::resolve()` → if completed refund + active restoration → `ServiceRestored` |
| UI | C360 Commercial State card — “Restore Commercial Service” (wallet + refund completed only) |
| Routes | `dashboard.service-cases.customer-360.commercial-service-restore` (+ revoke) |
| Audit | `commercial.service_restored`, `commercial.service_restoration_revoked` |

### Rules

- Wallet refunds only (`approved_refund_method = wallet`)
- Both checkboxes required: Finance Verified + Wallet Reversed Externally
- Wallet reversal reference required
- One active restoration per `(order_id, refund_request_id)` (app-enforced)
- Never edit `refund_requests`, payments, Cashfree, or wallet systems
- Revoke re-applies `refund_completed` block

### Tests

- `tests/Unit/Commercial/CommercialStateResolverTest.php`
- `tests/Feature/Commercial/CommercialStateGoldenTest.php`
- `tests/Feature/Commercial/CommercialServiceRestorationTest.php`
