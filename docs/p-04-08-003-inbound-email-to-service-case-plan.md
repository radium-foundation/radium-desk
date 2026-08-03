# P[04-08]-003 — Convert Inbound Emails into Service Cases

**Date:** 2026-08-04  
**Status:** Plan only — no code changes yet  
**Supersedes:** [`docs/p-04-08-002-needs-review-workflow-plan.md`](p-04-08-002-needs-review-workflow-plan.md) (Needs Review queue — do not implement)  
**Baseline audit:** [`docs/p-04-08-001-inbound-email-workflow-audit.md`](p-04-08-001-inbound-email-workflow-audit.md)  
**Canvas:** [`p-04-08-003-inbound-email-to-service-case-plan.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p-04-08-003-inbound-email-to-service-case-plan.canvas.tsx)

---

## Verdict

Every actionable inbound email will end on a **Service Case (Incident)** — one operational queue. No Needs Review inbox, ownership columns, or parallel triage.

**Reuse verified:**

| Need | Existing API | Evidence |
|------|--------------|----------|
| Create SC on known order | `QuickServiceRequestService::createForOrder` | Used by Order Create SC + intake |
| Create SC without customer order | `CustomerIntakeService::createNewContact` → **INQ inquiry Order** + SC | Incidents **require** `order_id` (non-nullable FK) |
| Link email to SC | `IncomingEmailLinkService::link` | Live Linked path today |
| Assign + notify | `IncomingEmailAssignmentService::routeLinkedEmail` | Communication Intake; DB notify |
| Classification | `IncomingEmailClassifierService` | Map → `incidents.category` only |
| Assignment engine | Same Intake path as Linked (avoid double `assignOnCreate`) | Do not invent second ownership |

**Do not implement P[04-08]-002** (Needs Review UI/ownership/widget).

---

## Objective

| Rule | Meaning |
|------|---------|
| Actionable email → Service Case | Filtered/ignored mail stays Ignored |
| Never ownerless operational email | After link, `routeLinkedEmail` assigns or keeps owner |
| One queue | Service Cases only |

---

## Architecture diagram

```
Gmail Sync / Ingest / Filter  (unchanged)
        ↓
IncomingEmailProcessorService
        ↓
CustomerMatcher + Classifier  (reuse; optional multi-order preference later)
        ↓
    ┌─── active SC on order ──────────────────────────┐
    │  link → priority boost → routeLinkedEmail        │  Scenario 1
    │  (no new SC)                                     │
    ├─── order, no active SC ─────────────────────────┤
    │  createForOrder(Email, category)                 │  Scenario 2
    │  → link → boost → routeLinkedEmail               │
    └─── no order ────────────────────────────────────┤
       createNewContact(INQ Order + SC, category)      │  Scenario 3
       → link → boost → routeLinkedEmail               │
        ↓
C360 timeline / incidents.show / Desk reply
```

Ignored (spam/promo/newsletter) path unchanged — never creates a SC.

---

## Workflow by scenario

### 1. Existing linked order + active Service Case

**Today already does this.** Keep:

1. `IncomingEmailLinkService::link`
2. `ServiceCasePriorityService::applyInboundLinkBoost`
3. `IncomingEmailAssignmentService::routeLinkedEmail` (keep assignee or Intake; notify)

No new SC. Timeline + reply as today (after reply permission fix).

### 2. Order exists, no Service Case

**Replace** `IncomingEmailHistoricalAssociationService::associate` in the processor with:

1. `QuickServiceRequestService::createForOrder(..., source: Email, category: mapped, assignOnCreate: false)`
2. `IncomingEmailLinkService::link`
3. Priority boost + `routeLinkedEmail`

No manual Create SC step for new mail. Historical backlog rows can still use existing promote UI.

### 3. No matching order

**Replace** `markNeedsReview` with:

1. `CustomerIntakeService::createNewContact` (creates **INQ-*** Order + SC; `assignOnCreate: false`)
2. Map classification → intent/category
3. `link` + boost + `routeLinkedEmail`

**Critical constraint:** Incident cannot exist without Order. Inquiry Order is the existing product pattern (same as missed-call recovery).

---

## Classification → Service Case category

Reuse `IncomingEmailClassifierService`. **Do not** add a second classifier.

There is **no `IncidentType` enum**. “Type” = `incidents.category` string (+ `source = email`).

| `IncomingEmailClassification` | Suggested `category` | Intake intent (scenario 3) |
|------------------------------|----------------------|----------------------------|
| Support / ExistingCustomer | `Service` or `General` | GeneralSupport |
| Appointment | `Appointment` | GeneralSupport |
| Refund | `Refund` | Other → override category |
| PossibleSalesLead | `Sales Lead` | BuyDevice |
| FinanceAction | `Finance` | Other → override |
| VendorAction | `Vendor` | Other → override |
| HrAction | `HR` | Other → override |
| UnknownCustomer | `General Support` | GeneralSupport |

Note: classifier today often returns `PossibleSalesLead` when order is null — expect many Sales Lead inquiry cases unless keyword/mailbox mapping is tuned (document as known behaviour; optional soft follow-up).

---

## Assignment flow

```
createForOrder / createNewContact  (assignOnCreate: false)
  → link email to Incident
  → routeLinkedEmail
       ├─ existing assigned_to_user_id → notify only (never reassign)
       └─ unassigned → Communication Intake primary → fallback
            → NewEmailReceivedNotification (database)
```

| Reuse | Avoid |
|-------|--------|
| `IncomingEmailAssignmentService` | Second ownership model on email rows |
| Settings Intake primary/fallback | Needs Review claim/assign |
| Capability config (future sales routing) | Calling `EmailTriageAssignmentStrategy` without Incident (N/A once SC exists) |

Optional later: for Sales Lead / unknown, call `UniversalAssignmentEngine::assignForEmailClassification` **instead of** Intake — only if product wants capability owners; Phase 003 default = Intake for consistency with Linked path.

---

## Notifications

Continue existing flow after `routeLinkedEmail`:

- `NewEmailReceivedNotification` → `via(['database'])`
- Optional high-priority notify if boost applies

No Telegram. No Needs Review–specific notification class.

Newly created SCs also trigger existing `DashboardBroadcastService::serviceCaseCreated` from `createForOrder`.

---

## Reply

Assigned SC owner must reply from Desk without admin role.

**Change:** `OutgoingEmailReplyGate` — allow when message is `Linked` and `incident.assigned_to_user_id === user.id` (keep mailbox/thread/config checks).  
Optionally also grant `email.reply` to support roles — assignee exception is narrower and preferred first.

---

## Dashboard

- **Do not** add Needs Review widget.
- New SCs appear in existing Service Case lists, ops counters, activity feeds.
- Gmail Health remains sync health only.
- Ensure create broadcasts keep dashboards updated (already in `createForOrder`).

---

## Timeline

Keep `IncomingEmailTimelineEventSource` + C360. Linked emails attach via existing link table. No timeline redesign.

After scenario 2/3, status is `Linked` (not Historical/NeedsReview), so emails appear on the new SC/order timeline immediately.

---

## Preserve vs remove

### Preserve

Gmail sync, Gmail Health, ingest, filters, matcher core, classifier, C360, reply MIME path, audit events (`incoming_email.linked`, etc.), Communication Intake for Linked.

### Do not implement

Needs Review inbox, NR ownership columns, NR dashboard, NR notifications, separate triage queue, duplicate assignment logic, nullable `incidents.order_id`.

### Deprecate / bypass in processor

| Path | Action |
|------|--------|
| `markNeedsReview` | Stop calling for actionable mail |
| `HistoricalAssociationService::associate` | Stop calling from processor (keep class for backlog/manual) |
| P[04-08]-002 plan | Abandoned |

---

## Files changed (planned)

### New

- `app/Services/IncomingEmail/IncomingEmailServiceCaseCreateService.php` — orchestrate create + link + route for scenarios 2 & 3
- Classification→category mapper (private methods or small helper class)
- Feature tests for auto-create paths

### Modified

- `IncomingEmailProcessorService.php` — replace Historical / NeedsReview branches
- `OutgoingEmailReplyGate.php` — SC assignee reply
- `RolePermissionSeeder.php` — only if granting `email.reply` broadly (optional)
- Tests: `IncomingEmailIntakePhase1Test`, `EmailCommunicationOwnershipRoutingTest`, `OutgoingEmailReplyTest`

### Untouched

Gmail provider/sync, ingest core, filter service, C360 timeline sources, `IncomingEmailAssignmentService` rules for Linked, Gmail Health UI.

---

## Database changes

| Change | Required? |
|--------|-----------|
| Nullable `incidents.order_id` | **No** |
| Needs Review ownership columns | **No** |
| New type enum table | **No** — use `category` |
| Permissions | Optional seeder tweak for `email.reply` |

No schema migration required for the core workflow if inquiry Orders cover unknown customers.

---

## Migration notes

1. **Backlog:** Existing `NeedsReview` / `HistoricalCustomer` rows are not auto-converted in Phase 003 unless a one-shot artisan command is added (optional follow-up). New mail only.  
2. **Deploy:** Code-only change + optional permission seed; no table drops.  
3. **Feature flag (recommended):** `inbound_email.auto_create_service_case` — when false, keep current Historical/NeedsReview behaviour for safe rollback.

---

## Rollback notes

1. Flip feature flag off → processor restores Historical associate + markNeedsReview.  
2. Revert processor + create service + reply gate.  
3. Inquiry Orders/SCs created while flag was on remain valid operational data (do not auto-delete).  
4. No Needs Review schema to unwind.

---

## Test coverage

| Case | Expectation |
|------|-------------|
| Scenario 1 — active SC | Link only; no second SC; notify assignee |
| Scenario 2 — order, no SC | One new SC; Linked; Intake/owner notify |
| Scenario 3 — unknown | INQ Order + SC; Linked; notify |
| Ignored spam | Still Ignored; no SC |
| Duplicate email | No double create (ingest idempotency + locks) |
| Concurrent emails same order | Prefer lock on order; one SC when racing historical |
| Reply — SC assignee without admin | Allowed on Linked |
| Reply — unrelated agent | Forbidden |
| Sales Lead classification | Category `Sales Lead` on inquiry SC |

Extend: `IncomingEmailIntakePhase1Test`, `EmailCommunicationOwnershipRoutingTest`, new `IncomingEmailAutoCreateServiceCaseTest`.

---

## Implementation order

1. Feature flag + `IncomingEmailServiceCaseCreateService` (scenario 2 first)  
2. Wire processor scenario 2; keep NeedsReview temporarily for scenario 3  
3. Scenario 3 via `createNewContact` + category map  
4. Remove NeedsReview/Historical branches for actionable mail  
5. Reply gate assignee exception  
6. Tests + optional backlog command  

---

## Risks

| Risk | Mitigation |
|------|------------|
| Flood of Sales Lead INQs (classifier bias) | Monitor; tune mailbox/keyword mapping later |
| Finance/HR/Vendor into support Intake | Category labels + Intake owners; capability routing later |
| Double assignment | Always `assignOnCreate: false` then `routeLinkedEmail` |
| Multi-order miss | Optional matcher prefer active SC (from 001 audit) — small follow-up |
| Agents cannot reply today | Gate exception in same release |

---

## Success criteria

- Actionable inbound email always ends `Linked` to a Service Case with an owner (or Intake-assigned).  
- No new Needs Review UI or ownership model.  
- Ignored mail never creates SCs.  
- Desk reply works for assigned owners.  
- Sync, Health, C360, timeline preserved.
