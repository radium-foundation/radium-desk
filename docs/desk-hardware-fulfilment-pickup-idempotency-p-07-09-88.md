# Hardware fulfilment pickup idempotency — RadiumDesk-P-07-09-88

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-88`  
**Mode:** Investigation + pickup state/idempotency correction. No live Shiprocket pickup. No RDE318421 write. No deploy in this ticket unless performed separately.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-87**. This ticket: **P-07-09-88**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Root cause

Desk Request Pickup persists `shipments.pickup_requested_at` only when the gateway returns `status === 'requested'`.

Shiprocket `POST /courier/generate/pickup` for RDE318421 returned **HTTP 400** with top-level `message` **Already in Pickup Queue**. Desk formatted that as `HTTP 400 — Already in Pickup Queue.`, treated it as a fatal rejection, and left local pickup as `Not requested`. The show page therefore kept an actionable **Request Pickup** button.

**Mark Ready for Pickup did not call Shiprocket.** It only writes `hardware_fulfilments.ready_for_pickup_at`. The previous ready gate was AWB + label-applied photo only, so Ready could run before pickup.

---

## RDE318421 production sequence (read-only)

| Fact | Value | Class |
|------|-------|-------|
| Fulfilment | `1` / `RDE318421` / `awb_assigned` | VERIFIED |
| Local shipment | `1` / `HW-RDE318421` / `awb_assigned` | VERIFIED |
| Provider order / shipment | `1572506854` / `1568724940` | VERIFIED |
| Courier / AWB | `Delhivery_Surface (15084)` / `284931178067631` | VERIFIED |
| Label | generated `2026-09-08 16:56:08` (user 1 Ravi) | VERIFIED |
| Package photos | before `17:50:15`, label-applied `17:50:30` (user 2 Avinash) | VERIFIED |
| Ready for pickup | `2026-09-08 17:54:42` (user 1) | VERIFIED |
| Local `pickup_requested_at` | `NULL` | VERIFIED |
| Pickup fulfilment/shipment events | none | VERIFIED |
| Manifest | not generated | VERIFIED |
| Shipment `last_error` | `NULL` (failed pickup not stored) | VERIFIED |
| Laravel log body for this 400 | absent | VERIFIED |
| HTTP 400 source | provider, via `HttpShiprocketGateway::errorMessage` | VERIFIED |
| How provider first entered the queue | not in Desk events; AWB/label Desk calls do not request pickup | UNKNOWN — live GET not made |

Ready-for-Pickup event payload: `hardware_ready_for_pickup` / `result=ready`. No provider call.

---

## Provider `Already in Pickup Queue` semantics

| Evidence | Class |
|----------|-------|
| Operator/UI string `HTTP 400 — Already in Pickup Queue.` matches Desk `HTTP {status} — {message\|error\|msg}` | VERIFIED |
| Official helpsheet: generate pickup after AWB, then generate manifest | VERIFIED |
| Official order status **12** = Pickup Queue | VERIFIED (Postman order filters) |
| A dedicated numeric error code besides HTTP 400 on this response | UNKNOWN — not present in the operator-visible string; body not logged |
| HTTP 400 alone means queued | NO — other 400s exist (`AWB not assigned`) |
| HTTP 400 **and** exact message `Already in Pickup Queue` on `message`/`error`/`msg` | VERIFIED as the Desk-parsed production shape; treated as already-queued reconciliation |

---

## Fix

1. Gateway inspects pickup HTTP 400 JSON instead of collapsing it to a generic rejection.
2. Exact HTTP 400 + `Already in Pickup Queue` → `already_requested` / `alreadyQueued`.
3. Documents service persists local pickup for `requested` and `already_requested`, records `pickup_reconciled_already_queued` on reconcile, and skips the provider when `pickup_requested_at` is already set.
4. Ready for Pickup now requires pickup requested + AWB + label-applied photo.
5. Manifest still requires pickup. No batch/automatic pickup.

Show/controller/classifier pickup-first wording was already present on `a12cde1c` (P-07-09-87) from the dirty worktree. This ticket supplies the service/DTO/tests those views call.

---

## Tests

119 passed: operational workflow (including new pickup idempotency cases), work queue, navigation, shipment UI, P5/P6, show readiness, courier, gateway, stepper, classifier.

Pint + `php -l` on changed PHP: passed.

---

## Not performed

- New Shiprocket pickup POST: **NO — Not performed.**
- New Shiprocket create/AWB/label/manifest: **NO — Not performed.**
- RDE318421 data write: **NO — Not performed.**
- Live provider GET/track: **NO — Not performed.** (would be needed to prove *how* the queue was first entered)
- Push / `.env` / migrate / batch pickup: **NO — Not performed.**
- New live pickup POST after overlay: **NO — Not performed.**

---

## Named-file overlay (same ticket)

| Item | Value |
|------|-------|
| Mechanism | named-file copy of `96d141b0` (6 files). Not `deskd`. |
| Server | `srv1910783` `/var/www/radium-desk` |
| Backup | `/var/www/radium-desk/storage/app/private/overlays/p-07-09-88-20260908T123725Z` |
| Hash verify | 6/6 MATCH `96d141b0` |
| `php -l` | clean |
| Recache | `optimize:clear` + `optimize` |
| `/up` | 200 |
| Show unauth | 302 |
| RDE318421 after overlay | unchanged: pickup `NULL`, ready `2026-09-08 17:54:42`, AWB `284931178067631`, provider shipment `1568724940` |

Show/controller were **not** overlaid (they still show Request Pickup until the next authorized click persists local pickup).

Rollback: restore the 5 replaced files from the backup; delete `HardwarePickupRequestOutcome.php`; `optimize:clear` + `optimize`. Rollback was **not** required.
