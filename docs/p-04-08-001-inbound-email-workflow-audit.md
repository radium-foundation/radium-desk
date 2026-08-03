# P[04-08]-001 — Inbound Email Workflow Audit

**Date:** 2026-08-04  
**Scope:** Read-only production workflow audit (no implementation)  
**Canvas:** [`p-04-08-001-inbound-email-workflow-audit.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p-04-08-001-inbound-email-workflow-audit.canvas.tsx)

---

## Verdict

Radium Desk already runs a complete **sync → ingest → filter → match → link/associate → notify** pipeline for Gmail inbound mail. The strong path is **known customer + active service case**: auto-link, Communication Intake ownership (or keep existing assignee), and in-app notification.

Gaps are concentrated on **unmatched / sales-lead / historical** paths: status is written (`NeedsReview`, `HistoricalCustomer`), but there is **no triage UI**, **no Telegram/email alerts for unmatched**, and capability-based routing (`IncomingEmailSupervisor`, `SalesLeadHandler`) exists only as **unused contract code**.

Do not redesign. Incrementally wire or surface what already exists.

---

## Phase 1 — End-to-end pipeline

```
Gmail API
  → SyncGmailInboundEmailCommand / scheduler (inbound-email:sync-gmail)
    → IncomingEmailGmailSyncService::sync()
      → GmailInboundEmailProvider::pullIncremental()
        → IncomingEmailIngestService::ingest()
            [Received | Ignored(own_outbound)]
          → Outbox event email.inbound.process
            → IncomingEmailProcessorService::process()
              → IncomingEmailFilterService::evaluate()
                  → Ignored (spam/promo/newsletter/…)
              → IncomingEmailCustomerMatcher::resolve()
              → IncomingEmailClassifierService::classifyOperational()
                  ├─ historical_customer → HistoricalAssociationService
                  ├─ no incident/order → NeedsReview (often PossibleSalesLead)
                  └─ active incident → IncomingEmailLinkService::link()
                        → ServiceCasePriorityService::applyInboundLinkBoost()
                        → IncomingEmailAssignmentService::routeLinkedEmail()
                        → NewEmailReceivedNotification (database)
```

| Stage | Primary class / entry | What it does today |
|-------|----------------------|--------------------|
| Gmail | Gmail API via `GmailApiClient` | History-id incremental pull |
| Sync | `SyncGmailInboundEmailCommand`, `IncomingEmailGmailSyncService` | Pull → ingest only (no match logic) |
| Ingest | `IncomingEmailIngestService::ingest` | Persist message; status `Received` or `Ignored` (own outbound); enqueue outbox |
| Filters | `IncomingEmailFilterService::evaluate` | Labels, blocked senders, bounce, autoresponder, newsletter |
| Classification | `IncomingEmailClassifierService` + `IncomingEmailClassification` | Filter reasons → Spam/Promotional/…; operational → PossibleSalesLead / Support / etc. |
| Customer / order match | `IncomingEmailCustomerMatcher::resolve` | Thread→active SC; else newest `Order` by `customer_email` |
| Historical | `IncomingEmailHistoricalAssociationService` | Status `HistoricalCustomer`; order linked; no SC |
| Incident linking | `IncomingEmailLinkService::link` | Link to existing active SC only (no auto-create) |
| Assignment | `IncomingEmailAssignmentService::routeLinkedEmail` | Keep assignee or Communication Intake primary/fallback |
| Notifications | `NewEmailReceivedNotification` | **Database channel only** after link |
| Dashboard / UI | C360 timeline, activity feeds, Gmail Health | Linked/Historical visible; NeedsReview not |
| Reply | `OutgoingEmailReplyService` + `OutgoingEmailReplyGate` | Requires `email.reply` + Linked/Historical |
| Close | Normal SC close services | No inbound-specific close; closed SC → next mail becomes Historical |

**Scheduler:** `bootstrap/app.php` — `inbound-email:sync-gmail` on interval; `outbox:process` every minute.

---

## Phase 2 — Current behaviour by scenario

### Scenario A — Existing customer, order matched, active service case

| Question | Today |
|----------|--------|
| Status | `Linked` |
| Who owns it? | Existing SC `assigned_to_user_id` if set; else Communication Intake primary → fallback (`IncomingEmailAssignmentService`) |
| Which incident? | Active operational SC on matched order (or thread-matched SC) |
| Which dashboard? | C360 timeline (Linked); assignee gets in-app notification → `incidents.show` |
| Who is notified? | SC assignee via `NewEmailReceivedNotification` (database). Optional `HighPriorityServiceCaseNotification` if priority boosted. **No Telegram.** |

### Scenario B — Existing customer, historical order (no active SC)

| Question | Today |
|----------|--------|
| Status | `HistoricalCustomer` |
| Ownership | None |
| Incident | None until manual **Create Service Case** (promotes via `IncomingEmailLinkService::promoteToServiceCase`) |
| UI | C360 timeline warning + Create Service Case |
| Notifications | **None** on associate |

### Scenario C — Existing customer, multiple orders

| Question | Today |
|----------|--------|
| Behaviour | Matcher picks **newest order by `id`** only (`IncomingEmailCustomerMatcher`) |
| Risk | Open SC on an older order is missed unless **thread_id** matches a prior linked message |
| Otherwise | Same as A or B depending on that newest order’s active SC |

### Scenario D — No customer found

| Question | Today |
|----------|--------|
| Status | `NeedsReview` |
| Classification | Typically `PossibleSalesLead` (`unknown_customer`) |
| Ownership / incident | None |
| UI | **No** Needs Review inbox; content API requires order/incident → effectively unreachable in C360 |
| Notifications | **None** (audit `incoming_email.needs_review` only) |

### Scenario E — Classified Possible Sales Lead

| Question | Today |
|----------|--------|
| Live path | Same as D for unknown customers; sales mailbox may classify matched mail as PossibleSalesLead but still links/needs-review via processor rules |
| SalesLeadHandler capability | Defined in `config/assignment_capabilities.php` + `EmailTriageAssignmentStrategy` — **not called** by `IncomingEmailProcessorService` |
| Lead ownership | **Missing** |

### Scenario F — Spam / Promotions / Newsletter / Forum

| Question | Today |
|----------|--------|
| Status | `Ignored` + classification (`Spam`, `Promotional`, `Newsletter`, `Marketing`, `Social`, …) |
| Ownership / notify | None |
| UI | Hidden from C360 visibility query (Linked + Historical only) |
| Forum | Enum/`Forum` classifier exist; Gmail `CATEGORY_FORUMS` **not** in default `ignored_labels` — partial |

---

## Phase 3 — Assignment investigation

| Question | Evidence-based answer |
|----------|----------------------|
| Which service performs assignment? | **Live:** `IncomingEmailAssignmentService::routeLinkedEmail`. **Unused by ingest:** `UniversalAssignmentEngine::assignForEmailClassification`, `EmailTriageAssignmentStrategy` |
| Capabilities used? | **Live: none.** Contract-only: `IncomingEmailSupervisor`, `SalesLeadHandler` (`AssignmentCapability` + `config/assignment_capabilities.php`) |
| Which queues exist? | General `AssignmentQueue` (Ready/Support/WaitingCustomer/Completed) for SCs — **not** email Needs-Review queues |
| Who becomes owner? | Existing SC assignee, else settings `assignment.communication_intake_primary_user_id` / `_fallback_user_id` |
| Automatic? | Yes for **Linked** unassigned cases. NeedsReview / Historical = **no auto-assign** |
| Manual review required? | Yes for NeedsReview and Historical (Create SC) |
| Notifications? | In-app DB to assignee after link |
| Telegram? | **No** for inbound (`NewEmailReceivedNotification::via` → `['database']`) |
| Unmatched alerts? | **No** — audit event only |

---

## Phase 4 — User responsibility / ownership matrix

| Actor | View Linked/Historical (via C360) | Reply (`email.reply`) | Auto-assigned Linked mail | NeedsReview triage | Gmail Health / sync ops |
|-------|-----------------------------------|------------------------|---------------------------|--------------------|-------------------------|
| Support Agent / Specialist / Coordinator | Yes (if can view order/SC) | **No** | Only if already SC assignee | No UI | No |
| Admin | Yes | **Yes** | Via Intake settings or existing assignee | No UI | With ops perms |
| Operations Admin | Yes | **Yes** | Same | No UI | Yes (`operations-dashboard.view`) |
| Super Admin | Yes | **Yes** | Same | No UI | Yes |
| Incoming Email Supervisor capability | Mapped in config | N/A | **Not used** by live ingest | Intended for UnknownEmail — unused | N/A |
| Sales Lead Handler capability | Mapped in config | N/A | **Not used** | Intended for SalesLead — unused | N/A |

**Routing rules in production today:** Communication Intake primary/fallback + “never reassign existing owner” (`IncomingEmailAssignmentService` docblock).

---

## Phase 5 — UI inventory

| Screen | Location | What it shows |
|--------|----------|---------------|
| Customer 360 timeline | `IncomingEmailTimelineEventSource`, C360 activity items | Linked + Historical emails; Read full; Create SC for historical |
| Incoming email modal | `incoming-email-modal.blade.php` + JS | Body, attachments, reply (if permitted) |
| Order → Create Service Case | `orders/service-cases/create.blade.php` | Prefill / link historical message id |
| Dashboard / team activity | `config/dashboard-activity.php`, team-activity config | Audit: linked, received, promoted, historical — **not** needs_review |
| Operations — Gmail Health | `admin/operations/partials/gmail-health.blade.php` | Sync health, Sync Now, Rebaseline — **not** message triage |
| Admin Gmail logs | `admin/gmail/logs` | Sync log tail |
| Admin failed messages | `admin/gmail/failed-messages` | Per-message fetch failures |
| Settings → Assignment | `settings/partials/assignment.blade.php` | Communication Intake primary/fallback only |
| Needs Review inbox | — | **Missing** |
| Lead ownership UI | — | **Missing** |

---

## Phase 6 — Gap analysis

| Feature | Already exists | Partially exists | Missing |
|---------|----------------|------------------|---------|
| Auto order routing | | Newest-order match + link to active SC + Intake assign | Multi-order picker; mailbox specialist routing |
| Historical routing | | `HistoricalCustomer` + manual Create SC promote | Auto-create / auto-assign historical |
| Sales lead routing | | Classification + NeedsReview status | Live use of SalesLeadHandler; lead owner |
| Needs Review queue | | Status + audit event | List UI / work queue / claim |
| Queue ownership | | SC ownership for Linked | Owner for NeedsReview / leads |
| SLA (email-specific) | | (SC SLA exists elsewhere) | Inbound email SLA timers |
| Escalation (email-specific) | | | Escalate NeedsReview / unanswered inbound |
| Agent notifications | | DB notif to SC assignee | Telegram/desktop; agents lack `email.reply` |
| Operations dashboard | | Gmail **sync** health | Message triage / queue ops |
| Queue statistics | | Ignore stats + sync day metrics | NeedsReview / lead counts UI |
| Lead ownership | | | Assign PossibleSalesLead |
| Manual reassignment | | Generic SC reassignment | Email-message / NeedsReview claim UI |
| Follow-up reminders | | | Unanswered inbound / NeedsReview reminders |

---

## Phase 7 — Recommendations (incremental only)

### High impact / low effort

1. **Needs Review list UI** — query `status=needs_review`; reuse existing content/auth patterns once order/incident attached or allow supervisor view by id.
2. **Notify on NeedsReview** — database (and optionally Telegram if channel already used elsewhere) to Incoming Email Supervisor capability users **or** configured Intake fallback — reuse `AssignmentCapability` mappings already in config.
3. **Grant `email.reply` to roles that own SCs** (or a narrower support reply role) so agents who receive “Open Communication” can reply.

### Medium

4. **Wire `EmailTriageAssignmentStrategy` / `assignForEmailClassification`** for NeedsReview + PossibleSalesLead only — capability scaffolding already exists; do not replace Communication Intake for Linked path.
5. **Multi-order safety** — if newest order has no active SC but an older order does, prefer order with active SC (or surface picker).
6. **Ops widget counters** — NeedsReview / Historical counts beside Gmail Health (reuse ignore/sync metrics style).

### Low priority

7. Add `CATEGORY_FORUMS` to ignored labels if desired.
8. Email-specific SLA / follow-up reminders (new timers — only after queue UI exists).
9. Telegram for linked-mail notifications (extend `NewEmailReceivedNotification::via` carefully).

---

## Deliverable matrices

### Current routing matrix

| Match outcome | Status | Auto-assign | Notify | UI surface |
|---------------|--------|-------------|--------|------------|
| Active SC | Linked | Keep owner / Intake | DB to assignee | C360 + notification |
| Order, no active SC | HistoricalCustomer | No | No | C360 + Create SC |
| No order | NeedsReview | No | No | Audit only |
| Filter ignore | Ignored | No | No | Hidden |

### Ownership matrix (Linked path)

| Condition | Owner |
|-----------|--------|
| SC already assigned | That user (notify only; never reassign) |
| SC unassigned | Communication Intake primary if available |
| Primary unavailable | Fallback if available |
| Both fail | Forced primary/fallback last resort (service logic) |

### Recommended implementation order

1. Needs Review UI  
2. Needs Review notification (reuse capability config)  
3. Reply permission alignment for SC owners  
4. Wire sales/unknown capability assignment for NeedsReview only  
5. Multi-order active-SC preference  
6. Ops queue stats  
7. Forum label / SLA / Telegram stretch goals  

---

## Key evidence files

- `app/Services/IncomingEmail/IncomingEmailGmailSyncService.php`
- `app/Services/IncomingEmail/IncomingEmailIngestService.php`
- `app/Services/IncomingEmail/IncomingEmailProcessorService.php`
- `app/Services/IncomingEmail/IncomingEmailCustomerMatcher.php`
- `app/Services/IncomingEmail/IncomingEmailAssignmentService.php`
- `app/Services/IncomingEmail/IncomingEmailFilterService.php`
- `app/Services/IncomingEmail/IncomingEmailClassifierService.php`
- `app/Support/Assignment/Strategies/EmailTriageAssignmentStrategy.php` (unused by ingest)
- `app/Notifications/NewEmailReceivedNotification.php`
- `config/assignment_capabilities.php`
- `tests/Feature/EmailCommunicationOwnershipRoutingTest.php`
- `tests/Feature/IncomingEmailIntakePhase1Test.php`
