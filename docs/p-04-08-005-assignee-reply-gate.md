# P[04-08]-005 — Step 6: Service Case Assignee Reply

**Date:** 2026-08-04  
**Status:** Implemented  
**Canvas:** [`p-04-08-005-assignee-reply-gate.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p-04-08-005-assignee-reply-gate.canvas.tsx)  
**Prior:** [`docs/p-04-08-004-step4-branch-c.md`](p-04-08-004-step4-branch-c.md)

---

## Verdict

Assigned Service Case owners can reply to **Linked** inbound emails from Radium Desk without `email.reply`. Admin / Ops / SuperAdmin behaviour is unchanged. No new permissions. Gmail send pipeline, templates, audit, timeline, and Customer 360 are untouched.

---

## Objective

Allow the current Service Case assignee to reply without granting broad `email.reply` to all agents.

---

## Implementation

**File:** `app/Services/OutgoingEmail/OutgoingEmailReplyGate.php`

Reuse existing gate. Permission check becomes:

1. Reply feature enabled  
2. User has `email.reply` **OR** is the assignee of the Linked message’s Service Case  
3. Mailbox / Linked|Historical / thread / recipient / link checks (unchanged)  
4. Can view incident or order (unchanged)

Assignee exception rules:

| Rule | Detail |
|------|--------|
| Status | Message must be `Linked` |
| Incident | `incident_id` required |
| Owner | `incident.assigned_to_user_id === user.id` |
| Not granted | Historical-only, unassigned, or other agents’ cases |

No seeder changes. No new Spatie permissions.

---

## What was not changed

- Gmail sending / MIME / templates / outgoing pipeline  
- Auto-create Service Case flag / processor Branches B–C  
- Ingest, classifier, matcher, C360  
- Audit log writers / timeline sources  

---

## Files changed

| Path | Change |
|------|--------|
| `app/Services/OutgoingEmail/OutgoingEmailReplyGate.php` | Assignee exception |
| `tests/Feature/OutgoingEmailReplyTest.php` | SuperAdmin, assignee, other agent, unassigned cases |
| `docs/p-04-08-005-assignee-reply-gate.md` | This report |
| Canvas `p-04-08-005-assignee-reply-gate.canvas.tsx` | Paired UI |

---

## Test results

`php artisan test --filter=OutgoingEmailReplyTest` → **10 passed** (51 assertions)

| Case | Result |
|------|--------|
| Admin can reply | ✅ |
| Super Admin can reply | ✅ |
| Assigned SC owner can reply (no `email.reply`) | ✅ |
| Different support agent cannot reply | ✅ |
| Unassigned agent cannot reply | ✅ |
| Existing outgoing email tests | ✅ green |

Assignee reply also asserts `outgoing_email.sent` audit + outgoing timeline entry.

---

## Rollback notes

1. Revert `OutgoingEmailReplyGate.php` to require `email.reply` only (remove `isAssignedServiceCaseOwner`).  
2. Revert / drop the new reply tests if desired.  
3. No migrations, no permission seed changes, no config keys.  
4. Immediate effect after deploy of the revert — no data cleanup.

---

## Recommended deployment sequence (post–Step 6)

1. Deploy to production with `INBOUND_EMAIL_AUTO_CREATE_SERVICE_CASE=false`.  
2. Verify existing email flow is unaffected (Historical / NeedsReview / Linked + admin reply).  
3. Enable the auto-create feature flag.  
4. Send three test emails:  
   - Existing order with active Service Case  
   - Existing order without a Service Case  
   - New customer inquiry  
5. Confirm all three reach the correct Service Case and assigned owner; confirm assignee can reply from Desk.  
6. Remove the feature flag in a later cleanup release once the workflow has proven stable.

---

## Scope boundary

Step 6 only. No other features in this change set.
