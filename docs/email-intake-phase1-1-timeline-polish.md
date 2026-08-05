# Email Intake Phase 1.1 — Timeline Presentation Polish

**Date:** 2026-08-05  
**Priority:** P1  
**Type:** Presentation-only  
**Source of truth (behaviour):** [docs/email-intake-phase1-1-closed-case-reopen.md](./email-intake-phase1-1-closed-case-reopen.md)  
**Canvas:** none

---

## Objective

Improve Customer360 business-timeline presentation when an inbound email reopens a closed Service Case.

No business logic, Gmail sync, matching, or reopen orchestration changes.

---

## Problem

Operators saw two noisy cards for one customer action:

1. **Customer replied by email**
2. **Internal note added** — `Service case reopened by inbound email <Message-ID>`

Raw Message-IDs and related identifiers were visible in the primary timeline surface.

---

## Required UX (shipped)

One unified timeline event:

| Element | Content |
|---------|---------|
| Title | Customer replied by email (envelope icon) |
| Body | This email automatically reopened the previously closed Service Case. |
| Display | Subject, Sender, Received, Preview |
| Action chips | Only actions that occurred — e.g. ✓ Case Reopened, ✓ Priority Raised, ✓ Assigned to \<Agent\> |
| Technical | Message ID / Thread ID / Gmail Message ID / Mailbox behind **Technical Details** (collapsed) |

The separate **Internal note added** card is hidden for inbound-email reopen remarks.

Backend audit events and the system remark remain unchanged; only operator presentation filters them.

---

## Presentation rules

| Signal | Presentation |
|--------|----------------|
| `incoming_email.case_reopened` audit for the message | Unified email card (`storyKey = incoming_email_case_reopened`) |
| System remark body contains `Service case reopened by inbound email` | Hidden from operator timeline |
| Status change `closed` → `open` | Not emitted as a separate lifecycle milestone (covered by email card) |
| Assignment with `assignment_method = inbound_email_reopen_previous_owner` | Not a separate “Assigned to…” card; may appear as a chip on the email card when that assignment audit exists |
| Priority | ✓ Priority Raised when RD case is high priority after reopen (inquiry orders excluded) |

Technical identifiers never appear in primary display fields.

---

## Files

| File | Role |
|------|------|
| `app/Services/Timeline/IncomingEmailReopenTimelinePresenter.php` | Display / technical / badge helpers |
| `app/Services/Timeline/Sources/IncomingEmailTimelineEventSource.php` | Enrich email events; move IDs to technical fields |
| `app/Services/Timeline/Customer360OperatorTimelinePresentation.php` | Hide inbound-email reopen remarks |
| `app/Services/Timeline/Sources/ServiceCaseLifecycleTimelineEventSource.php` | Skip closed→open status milestones |
| `app/Services/OrderActivityTimelineService.php` | Skip reopen-owner assignment activity entries |
| `app/Data/TimelineEvent.php` | `technicalFields`, `actionBadges` |
| `app/Data/Timeline/BusinessTimelineItem.php` | Unified presentation fields |
| `app/Services/Timeline/BusinessTimelineComposer.php` | Map unified email cards |
| `app/Support/Timeline/BusinessTimelineTitlePresenter.php` | Title without trailing period for email replies |
| `resources/views/components/c360/business-timeline-item.blade.php` | Unified card UI |
| `resources/views/components/c360/activity-item.blade.php` | Technical Details on nested raw events |
| `resources/css/app.css` | Minimal badge / field styles |
| `tests/Feature/IncomingEmail/IncomingEmailReopenTimelinePresentationTest.php` | Feature coverage |

### Intentionally untouched

`IncomingEmailClosedCaseReopenService`, Gmail sync, matcher, processor reopen path, audit event names/payload contracts (except reading existing audits for presentation).

---

## Tests

`IncomingEmailReopenTimelinePresentationTest` verifies:

- Single unified reopen card
- No duplicate “Internal note added” card
- Subject / Sender / Preview / reopen body visible
- Case Reopened chip visible
- Technical Details present and collapsed by default
- Backend reopen remark still stored

---

## Flow (presentation)

```
Operator timeline events
  → hide inbound-email reopen remark
  → skip closed→open status card / reopen assignment activity card
  → incoming email with case_reopened audit
       → title + reopen body + display fields + action chips
       → technical IDs behind Technical Details
```
