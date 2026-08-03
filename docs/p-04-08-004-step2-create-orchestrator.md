# P[04-08]-004 — Step 2: Create Orchestrator

**Date:** 2026-08-04  
**Status:** Implemented — **not wired into processor**  
**Depends on:** [`docs/p-04-08-004-step1-architecture-reuse.md`](p-04-08-004-step1-architecture-reuse.md)  
**Canvas:** [`p-04-08-004-step2-create-orchestrator.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p-04-08-004-step2-create-orchestrator.canvas.tsx)

---

## Verdict

Step 2 delivers the feature flag (default **false**) and the auto-create orchestrator. Production behaviour is unchanged: `IncomingEmailProcessorService` is untouched.

---

## Delivered

### Feature flag

| Key | Env | Default |
|-----|-----|---------|
| `inbound_email.auto_create_service_case` | `INBOUND_EMAIL_AUTO_CREATE_SERVICE_CASE` | **`false`** |

Documented in `config/inbound_email.php` and `.env.example`.

### New services

| Class | Role |
|-------|------|
| `IncomingEmailServiceCaseCategoryMapper` | Classification → category / `NewContactIntent`; rejects Finance/HR/Vendor + non-operational |
| `IncomingEmailServiceCaseCreateService` | Idempotent ensure SC for order / unknown customer; optional link + boost + route helpers |

### Adjustments applied

1. **INQ numbering** — unknown path uses `CustomerIntakeService::createNewContact` → `Order::inquiryOrderIdFromReference` → `INQ-{SC reference}` (e.g. `INQ-SC00042`). Asserted after create.
2. **Source** — every auto-created SC uses existing `IncidentSource::Email` (`incidents.source`). No schema change.
3. **Concurrency** — order path: `Order` `lockForUpdate` → recheck active SC → create only if none. Unknown path: cache lock on from-email + order locks + recheck.

### Assignment

Create paths always use `assignOnCreate: false`. `createLinkAndRoute*` then call existing `routeLinkedEmail` (no duplicate ownership logic).

---

## Not in this step

- Processor branches B/C/D (Steps 3–5)
- `OutgoingEmailReplyGate` assignee exception (Step 6)
- Enabling the flag in any environment

---

## Files

| Path | Change |
|------|--------|
| `config/inbound_email.php` | Flag |
| `.env.example` | Env key |
| `app/Services/IncomingEmail/IncomingEmailServiceCaseCategoryMapper.php` | New |
| `app/Services/IncomingEmail/IncomingEmailServiceCaseCreateService.php` | New |
| `tests/Unit/IncomingEmail/IncomingEmailServiceCaseCategoryMapperTest.php` | New |
| `tests/Feature/IncomingEmail/IncomingEmailServiceCaseCreateTest.php` | New |

**Untouched:** `IncomingEmailProcessorService`, Gmail sync/health, C360, timeline, notifications wiring.

---

## Tests

- Mapper: customer-facing maps; internal ops rejected; spam rejected
- Create for order: Email source, unassigned, idempotent serial + nested calls
- Unknown: `INQ-` order, Email source, Sales Lead category, reuse by email
- Link+route helper: Linked status + Intake assignment after create
- Flag default false

---

## Next (Step 3)

Wire processor branch B (order, no SC) behind the flag — call `createLinkAndRouteForOrder` instead of Historical associate when enabled.
