# P[04-08]-004 — Step 3: Processor Branch B

**Date:** 2026-08-04  
**Status:** Implemented  
**Depends on:** [`docs/p-04-08-004-step2-create-orchestrator.md`](p-04-08-004-step2-create-orchestrator.md)  
**Canvas:** [`p-04-08-004-step3-branch-b.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p-04-08-004-step3-branch-b.canvas.tsx)

---

## Verdict

Branch B (order exists, no active Service Case) is wired behind `inbound_email.auto_create_service_case` (default **false**). Flag off = Historical association unchanged. Flag on = create SC + link + `routeLinkedEmail` via the Step 2 orchestrator.

---

## Behaviour

| Condition | Flag OFF | Flag ON |
|-----------|----------|---------|
| Order + no active SC, customer-facing | Historical | Create SC + link + route |
| Order + no active SC, Finance/HR/Vendor | Historical | Historical (no SC yet) |
| Active SC (Branch A) | Link + route | Unchanged |
| No order (Branch C) | NeedsReview | NeedsReview (not yet) |

---

## Verification

1. One email → one SC (when flag on)
2. Reprocess Linked message → no duplicate SC
3. Two emails same order → one active SC, both Linked
4. Existing active-SC link path unchanged with flag on
5. Flag off → Historical (existing Phase 1 tests still pass)

---

## Files

| Path | Change |
|------|--------|
| `IncomingEmailProcessorService.php` | Branch B flag gate + orchestrator call |
| `tests/Feature/IncomingEmail/IncomingEmailAutoCreateBranchBTest.php` | New |

**Untouched:** Branch C, reply gate, Gmail sync/ingest/matcher core, C360.

---

## Next (Step 4)

Wire Branch C (customer email, no order) behind the same flag.
