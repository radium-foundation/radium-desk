# P[04-08]-002 — Needs Review Workflow (Phase 1) Implementation Plan

> **Superseded by [`P[04-08]-003`](p-04-08-003-inbound-email-to-service-case-plan.md).**  
> Do **not** implement Needs Review inbox/ownership/widget. Inbound actionable
> mail will auto-create or link Service Cases instead.

**Date:** 2026-08-04  
**Status:** Abandoned — replaced by Service Case–centric plan  
**Prerequisite audit:** [`docs/p-04-08-001-inbound-email-workflow-audit.md`](p-04-08-001-inbound-email-workflow-audit.md)  
**Canvas:** [`p-04-08-002-needs-review-workflow-plan.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p-04-08-002-needs-review-workflow-plan.canvas.tsx)

---

## Verdict

Phase 1 can be delivered **incrementally on top of the existing inbound pipeline**. Status `NeedsReview`, classifications, and audit event `incoming_email.needs_review` already exist. Missing pieces are ownership columns, triage UI, notifications, multi-order matcher preference, and SC-owner reply scoping.

**Do not** redesign Gmail sync, ingest, or Linked-path Communication Intake assignment.  
**Do not** call `EmailTriageAssignmentStrategy` as-is for claim (it assigns Incidents). Reuse `AssignmentCapabilityResolver` / capability checks only.

---

## Scope checklist (in vs out)

| In Phase 1 | Out of scope |
|------------|--------------|
| Needs Review inbox + filters | AI routing |
| Claim / Assign / Reassign (message owner) | Telegram |
| Resolution actions + audit | SLA / escalations |
| In-app notify + dashboard counter | CRM / Outlook |
| Matcher: active SC → newest → historical | Analytics / workload balancing |
| SC assignee reply from Desk | Redesign of sync/ingest/matching core |

---

## Architecture (reuse)

```
Existing: Sync → Ingest → Processor → markNeedsReview (status only)
Phase 1 adds:
  markNeedsReview
    → auto-owner via AssignmentCapabilityResolver (optional but recommended)
    → IncomingEmailNeedsReviewNotification (database)
  Admin Needs Review UI
    → IncomingEmailNeedsReviewService (claim/assign/classify/resolve/link)
    → extend LinkService for NeedsReview → Linked / Create SC
  Matcher preference change (CustomerMatcher only)
  ReplyGate: allow SC assignee (Linked) without blanket agent reply on all mail
  Ops widget counts
```

---

## 1. Needs Review Inbox

### Route / controller / views

| Item | Proposal |
|------|----------|
| Index | `GET /admin/incoming-emails/needs-review` → `admin.incoming-emails.needs-review.index` |
| Show | `GET /admin/incoming-emails/needs-review/{incomingEmailMessage}` |
| Controller | `app/Http/Controllers/IncomingEmailNeedsReviewController.php` |
| Views | `resources/views/admin/incoming-emails/needs-review/{index,show}.blade.php` |
| Auth | New permissions (below); mirror Gmail admin cluster in `routes/web.php` |

### Columns shown

| Column | Source |
|--------|--------|
| Subject | `subject` |
| From | `from_email` / `from_name` |
| Received | `received_at` |
| Classification | `classification` |
| Current owner | **new** `needs_review_owner_user_id` → user name |
| Existing customer | `order_id` present and/or classification `existing_customer` / known order |
| Possible sales lead | `classification === possible_sales_lead` |

### Filters

| Filter | Query |
|--------|--------|
| All | `status = needs_review` and unresolved |
| Unassigned | `needs_review_owner_user_id` null |
| Assigned to Me | owner = auth id |
| Existing Customer | `order_id` not null OR classification existing_customer |
| Possible Sales Lead | classification = `possible_sales_lead` |
| Spam Suspected | classification = `spam` (rare while still NeedsReview; also allow ignore_reason spam heuristics if any) |

---

## 2. Ownership

### Database (new migration)

`incoming_email_messages`:

| Column | Type |
|--------|------|
| `needs_review_owner_user_id` | nullable FK → users |
| `claimed_at` | nullable timestamp |
| `needs_review_assigned_by_user_id` | nullable FK → users |
| `needs_review_resolution` | nullable string(64) |
| `needs_review_resolved_at` | nullable timestamp |
| `needs_review_resolved_by_user_id` | nullable FK → users |
| `needs_review_resolution_note` | nullable text |

Indexes: `(status, needs_review_owner_user_id, received_at)`, `(status, claimed_at)`.

### Service

New `app/Services/IncomingEmail/IncomingEmailNeedsReviewService.php`:

- `claim(message, actor)` — conditional update where owner is null (race-safe)
- `assign(message, actor, targetUser)` — reassign
- `autoOwnOnNeedsReview(message)` — use `AssignmentCapabilityResolver`:
  - `PossibleSalesLead` → `SalesLeadHandler`
  - else → `IncomingEmailSupervisor`
- Audits: `incoming_email.needs_review_claimed`, `_assigned`

### Capability reuse (not a second system)

| Reuse | Do not reuse as-is |
|-------|---------------------|
| `AssignmentCapability::IncomingEmailSupervisor` / `SalesLeadHandler` | `EmailTriageAssignmentStrategy` (Incident-only) |
| `AssignmentCapabilityResolver` | Replacing Communication Intake for Linked path |
| Settings keys already in `config/assignment_capabilities.php` | Inventing new queue enums |

Expose supervisor/sales-handler user IDs in Settings Assignment UI (keys exist; UI incomplete).

---

## 3. Resolution actions

| Action | Behaviour | Status after | Classification | Audit |
|--------|-----------|--------------|----------------|-------|
| Link to existing order | Set `order_id`; if order has active SC → `IncomingEmailLinkService::link`; else remain/promote path | Linked or Historical | keep / ExistingCustomer | `incoming_email.linked` or historical |
| Create Service Case | Extend promote/link to accept NeedsReview + order (today Historical-only) | Linked | ExistingCustomer | `incoming_email.promoted_to_service_case` |
| Mark Sales Lead | Set classification PossibleSalesLead; keep NeedsReview until owned/resolved | NeedsReview | PossibleSalesLead | `incoming_email.needs_review_classified` |
| Mark Spam | Classification Spam → status Ignored | Ignored | Spam | classified + ignored |
| Ignore | Status Ignored; reason note | Ignored | OtherIgnored or keep | ignored / resolved |
| Forward to Finance | Classification FinanceAction; keep owner | NeedsReview (until handoff resolved) | FinanceAction | classified |
| Forward to HR | Classification HrAction | NeedsReview | HrAction | classified |
| Forward to Vendor | Classification VendorAction | NeedsReview | VendorAction | classified |

All actions set resolution fields when closing the Needs Review item (`needs_review_resolution`, `_resolved_at`, `_resolved_by`).

**Link/promote change:** extend `IncomingEmailLinkService::promoteToServiceCase` (and/or helper) to allow `NeedsReview` when `order_id` matches — without breaking Historical callers.

---

## 4. Notifications

| When | What |
|------|------|
| New NeedsReview after process | `IncomingEmailNeedsReviewNotification` → `via(['database'])` only |
| Recipients | Auto-owner if set; else eligible users for IncomingEmailSupervisor (and SalesLeadHandler for sales) |
| URL | Needs Review show page |
| Telegram | **Not in Phase 1** |

Hook in `IncomingEmailProcessorService::markNeedsReview` after persist (backward compatible: if no capability user configured, still write status; notify best-effort).

Dashboard counter: Ops Gmail Health partial or dedicated compact widget (see §5).

---

## 5. Dashboard widget

Compact Needs Review widget (Operations dashboard section, beside Gmail Health):

| Metric | Query |
|--------|-------|
| Total | open NeedsReview (unresolved) |
| Unassigned | owner null |
| Assigned | owner not null |
| Today’s arrivals | `received_at` today + NeedsReview |

Service: extend `OperationsGmailHealthService` **or** small `OperationsNeedsReviewHealthService` returned in same ops bundle. Link to inbox.

Also register `incoming_email.needs_review` (+ claim/assign events) in `config/dashboard-activity.php` / team-activity if desired.

---

## 6. Improve matching

**File only:** `IncomingEmailCustomerMatcher::resolve`

Preference order after thread match:

1. Any order for email candidates with an **operationally active** SC → prefer that (newest among actives)
2. Else **newest** order overall
3. If newest has no active SC → `historical_customer` (existing)

Do **not** change ingest/sync. Add regression tests for multi-order + active SC on older order.

---

## 7. Reply permission

**File:** `OutgoingEmailReplyGate`

Preserve `email.reply` for admin/ops/superadmin.

**Add** narrow exception: allow reply when:

- message status is `Linked`, and
- `$message->incident?->assigned_to_user_id === $user->id`, and
- existing mailbox / thread / from checks still pass

Alternatively (or additionally): grant `email.reply` to support roles in seeder — still keep gate checks so they cannot reply to unrelated NeedsReview without link.

**NeedsReview:** remain non-replyable until Linked/Historical (resolution first).

---

## 8. Permissions

| Permission | Purpose | Roles (proposed) |
|------------|---------|------------------|
| `incoming-emails.needs-review.view` | Inbox / show | Admin, Ops Admin, Superadmin |
| `incoming-emails.needs-review.claim` | Claim | same (+ later agents if auto-owned) |
| `incoming-emails.needs-review.assign` | Assign / reassign | Admin, Ops Admin, Superadmin |
| `incoming-emails.needs-review.resolve` | Ignore / spam / resolve | same |
| `incoming-emails.needs-review.classify` | Sales/Finance/HR/Vendor | same |
| `email.reply` | Existing | Keep admin-tier; gate exception for SC assignee |

Seeder: `RolePermissionSeeder` constants + grants. Policy or controller authorize methods.

---

## Files changed (planned)

### New

- Migration `…_add_needs_review_ownership_to_incoming_email_messages.php`
- `IncomingEmailNeedsReviewService.php`
- `IncomingEmailNeedsReviewNotification.php`
- `IncomingEmailNeedsReviewController.php` (+ FormRequests)
- Views `admin/incoming-emails/needs-review/*`
- `tests/Feature/IncomingEmailNeedsReviewWorkflowTest.php`
- Matcher multi-order test cases

### Modified

- `IncomingEmailMessage` model
- `IncomingEmailProcessorService` (notify + auto-own)
- `IncomingEmailCustomerMatcher`
- `IncomingEmailLinkService` (+ maybe `IncomingEmailServiceCaseLinkService`)
- `OutgoingEmailReplyGate`
- `routes/web.php`
- `RolePermissionSeeder`
- Ops health service + blade (counters)
- Settings assignment UI + seeder for supervisor/sales user IDs
- `IncomingEmailContentController` authorize for NR viewers
- Activity config (optional)

### Untouched (by design)

- Gmail sync / provider pull algorithm
- Linked-path `IncomingEmailAssignmentService` Communication Intake rules
- Filter service core
- Telegram / SLA

---

## Database changes

1. Ownership + resolution columns on `incoming_email_messages` (above).  
2. No new tables required for Phase 1.  
3. Spatie permission rows via seeder (and deploy seed).

---

## Test coverage (planned)

| Area | Tests |
|------|-------|
| Inbox auth + filters | Feature NeedsReviewWorkflowTest |
| Claim race / assign | Feature |
| Classify spam → Ignored | Feature |
| Link / Create SC from NeedsReview | Feature (extend promote tests) |
| Notify on markNeedsReview | Feature + Notification fake |
| Matcher active-SC preference | Feature/Unit |
| Reply gate SC assignee | OutgoingEmailReplyTest |
| Permissions matrix | Seeder / HTTP forbidden cases |
| Widget counts | Ops health test if extended |

---

## Implementation order (production-safe)

| Step | Work | Risk mitigation |
|------|------|-----------------|
| 1 | Migration + model | Idempotent nullable columns |
| 2 | Permissions + empty inbox UI | View-only first |
| 3 | Notify + auto-own on markNeedsReview | Soft-fail if no capability user |
| 4 | Claim / Assign / Reassign | Conditional UPDATE for claim |
| 5 | Resolution actions + audits | Explicit resolution enum strings |
| 6 | Link / Create SC for NeedsReview | Extend promote; keep Historical API |
| 7 | Matcher preference | Tests before ship |
| 8 | Reply gate assignee exception | Linked-only |
| 9 | Dashboard widget | Indexed counts |

---

## Rollback notes

1. Feature-flag optional: `inbound_email.needs_review_workflow_enabled` (recommended) wraps UI routes + auto-own/notify so production can disable without migrate down.  
2. Migration down: drop new columns only.  
3. Seeder: remove new permissions on rollback branch.  
4. Matcher/reply changes revert independently of ownership columns.  
5. Existing Linked / Historical / Ignored behaviour must remain if flag off.

---

## Open decisions (confirm before coding)

1. Auto-own on arrival vs leave Unassigned until Claim (plan recommends auto-own when capability user configured).  
2. Grant `email.reply` to support roles vs assignee-only gate exception (plan recommends **assignee exception** first — safer).  
3. Forward to Finance/HR/Vendor: remain in NeedsReview with classification only, or also set resolution = `forwarded_*` and remove from open queue (plan: classify + optional resolve with note).

---

## Success criteria

- Unmatched inbound appears in Needs Review inbox with owner (or Unassigned filter).  
- Claim/Assign/Reassign audited.  
- Resolution actions update status/classification + audit.  
- In-app notification on new NeedsReview; ops counter updates.  
- Multi-order prefers active SC.  
- SC assignees can reply on Linked mail they own.  
- No Telegram; no sync/ingest redesign.
