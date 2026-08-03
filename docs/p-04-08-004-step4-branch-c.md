# P[04-08]-004 — Step 4: Processor Branch C

**Date:** 2026-08-04  
**Status:** Implemented  
**Depends on:** [`docs/p-04-08-004-step3-branch-b.md`](p-04-08-004-step3-branch-b.md)  
**Canvas:** [`p-04-08-004-step4-branch-c.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p-04-08-004-step4-branch-c.canvas.tsx)

---

## Verdict

Branch C (customer email, no matching order) is wired behind `inbound_email.auto_create_service_case` (default **false**).

Flag off → NeedsReview (production today).  
Flag on → INQ Order + Email-sourced Service Case + link + `routeLinkedEmail`.

**Phase 1 customer email automation is functionally complete** and remains disabled by default.

---

## Behaviour

| Case | Flag OFF | Flag ON |
|------|----------|---------|
| No matching order (customer) | NeedsReview | INQ + SC + link + route |
| Finance / HR / Vendor | NeedsReview / Historical | No SC (park as today) |
| Spam / promo / system | Ignored | Ignored |
| Branch A / B | Prior steps | Unchanged |

Shared gate: `shouldAutoCreateCustomerServiceCase()` — flag on + operational + not internal.

---

## Verification

1. Unknown customer → exactly one `INQ-` Order  
2. Exactly one Email-sourced Service Case  
3. Reprocess Linked → nothing new  
4. Two emails same from-address → one INQ + one SC, both Linked  
5. Unmatched support-mailbox sender → same Branch C path  
6. Flag OFF → NeedsReview identical to before  

38 related tests passed (B + C + Phase 1 + Step 2 create).

---

## Files

| Path | Change |
|------|--------|
| `IncomingEmailProcessorService.php` | Branch C + shared auto-create gate |
| `IncomingEmailAutoCreateBranchCTest.php` | New |
| `IncomingEmailAutoCreateBranchBTest.php` | Unknown path now expects auto-create when flag on |

**Untouched:** Reply gate, Gmail sync/ingest/classifier/matcher, C360.

---

## Still deferred

| Item | Step |
|------|------|
| Explicit Branch D polish / keyword-before-unknown | Optional / Step 5 |
| `OutgoingEmailReplyGate` assignee exception | Step 6 |
| Enable flag in staging → prod | Step 8 (ops) |

---

## Rollback

Set `INBOUND_EMAIL_AUTO_CREATE_SERVICE_CASE=false` — Branches B and C revert to Historical / NeedsReview.
