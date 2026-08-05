# Email Intake Phase 1.3 — Smart Routing

**Date:** 2026-08-05  
**Priority:** P1  
**Type:** Rule-based routing + assignment  
**Source of truth (architecture):** [docs/email-intake-architecture-investigation.md](./email-intake-architecture-investigation.md)  
**Prior phases:** [Phase 1.1 reopen](./email-intake-phase1-1-closed-case-reopen.md) · [Phase 1.2 counters](./email-intake-phase1-2-dashboard-counters.md)  
**Canvas:** none

---

## Objective

Every **new actionable** inbound email reaches the correct team using **deterministic business rules**. No AI. No inbox UI.

Phase 1.3 only: smart routing + assignment + audit + timeline. No classification AI, attachment changes, or reply UI.

---

## Routing order

Evaluated after filter + matcher. Active/closed case paths (Phase 1.1) unchanged.

| # | Condition | Action | Assignment |
|---|-----------|--------|------------|
| 1 | Existing customer + order + active/closed SC | Link or reopen (Phase 1.1) | Existing owner |
| 2 | Existing customer + order + no SC | Create Service Case | Previous account owner → else Support RR |
| 3 | Refund enquiry | Create Refund SC | Refund team pool RR |
| 4 | Sales enquiry | Create Sales Lead SC | Sales pool RR |
| 5 | Support enquiry | Create Service SC | Support RR (existing engine) |
| 6 | Everything else | Needs Human (`needs_review`) | None |

Internal operational mail (Finance / HR / Vendor) never auto-creates — route 6.

---

## Rule detection (configurable)

All rules read from `config/inbound_email.php` + settings. No hardcoded addresses or keywords in code.

| Route | Signals |
|-------|---------|
| Refund | Classification keyword/mailbox + `routing.refund.*` env lists |
| Sales | Classification + `routing.sales.*` (mailbox channel, recipients, subject keywords, from aliases) |
| Support | Classification + `routing.support.*` |
| Unknown | No rule match → Needs Human |

With smart routing enabled, unknown senders no longer default to Possible Sales Lead — mailbox channel and keywords decide.

---

## Assignment infrastructure reused

| Pool | Mechanism |
|------|-----------|
| Support RR | `UniversalAssignmentEngine::assignForEmailClassification(NewSupportCase)` → `SupportQueueAssignmentStrategy` |
| Sales RR | `ServiceCaseAssignmentService::resolveAgentViaRoundRobinFromPool()` over `assignment.inbound_email_sales_round_robin_user_ids`; falls back to `SalesLeadHandler` capability |
| Refund team | Same pool RR over `assignment.inbound_email_refund_team_user_ids` |
| Previous owner | Latest incident assignee or close-outcome sticky agent on the order |

Separate cursor settings per pool — same round-robin algorithm, not a second engine.

Existing linked-case assignee is never stolen.

---

## Configuration

| Key | Purpose |
|-----|---------|
| `INBOUND_EMAIL_SMART_ROUTING_ENABLED` | Master switch (default false) |
| `INBOUND_EMAIL_ROUTING_*` env vars | Mailbox channels, recipients, keywords, aliases per route |
| `assignment.inbound_email_sales_round_robin_user_ids` | Comma-separated sales pool |
| `assignment.inbound_email_refund_team_user_ids` | Comma-separated refund pool |
| `assignment.inbound_email_*_round_robin_last_user_id` | Per-pool RR cursors |

---

## Audit

Event: `incoming_email.routed`

Records: route, reason, assignment source, round-robin user id, mailbox, message id, incident id.

Assignment events: `service_case.assigned` with methods such as `inbound_email_previous_account_owner`, `inbound_email_sales_round_robin`, `inbound_email_refund_team_round_robin`.

---

## Customer360 timeline

Linked email cards enriched via `IncomingEmailRoutingTimelinePresenter`:

- Context: “Email routed automatically to Support / Sales / Refund”
- Badges: routed team + assignee when available

---

## Safety

- Duplicate SC prevention via existing `ensureActiveForOrder` / email-candidate locks
- Active linked case ownership preserved
- Closed-case reopen unchanged (Phase 1.1)
- No Gmail API on routing path — uses ingested rows only

---

## Files

| File | Role |
|------|------|
| `app/Enums/IncomingEmailSmartRoute.php` | Route enum + labels |
| `app/Data/IncomingEmail/IncomingEmailRouteDecision.php` | Route decision DTO |
| `app/Services/IncomingEmail/IncomingEmailRoutingRulesService.php` | Deterministic rule evaluation |
| `app/Services/IncomingEmail/IncomingEmailSmartRoutingService.php` | Processor integration |
| `app/Services/IncomingEmail/IncomingEmailSmartRoutingAssignmentService.php` | Pool RR + audit + notify |
| `app/Services/IncomingEmail/IncomingEmailProcessorService.php` | Smart routing branch |
| `app/Services/IncomingEmail/IncomingEmailClassifierService.php` | Keyword-first when smart routing on |
| `app/Services/IncomingEmail/IncomingEmailServiceCaseCreateService.php` | `skipAssignment` + enabled when smart routing |
| `app/Services/ServiceCaseAssignmentService.php` | `resolveAgentViaRoundRobinFromPool()` |
| `app/Services/Timeline/IncomingEmailRoutingTimelinePresenter.php` | Timeline enrichment |
| `app/Services/Timeline/Sources/IncomingEmailTimelineEventSource.php` | Routing context on cards |
| `config/inbound_email.php` | Smart routing config |
| `tests/Feature/IncomingEmail/IncomingEmailSmartRoutingTest.php` | Feature coverage |

### Intentionally untouched

AI classification, attachment UX, reply UI, Gmail sync, dashboard counter logic (Phase 1.2).

---

## Tests

- Support routing + RR + notify
- Sales routing + RR cursor advance
- Refund routing + refund team assign
- Unknown → Needs Human
- Existing customer → previous owner
- Existing customer → support RR fallback
- Duplicate SC prevention
- Audit `incoming_email.routed`
- Timeline routing audit

---

## Flow

```
Processor (smart_routing_enabled)
  → matcher (active / closed unchanged)
  → classifier (keywords first for unknown)
  → IncomingEmailRoutingRulesService::decide()
       ├─ existing_customer_new_case → create SC → assign owner/RR
       ├─ refund / sales / support → create SC → team RR
       └─ needs_human → needs_review + audit (no assign)
  → incoming_email.routed audit
  → NewEmailReceivedNotification (when assigned)
  → Customer360 timeline badges
```

---

## Enable

```env
INBOUND_EMAIL_ENABLED=true
INBOUND_EMAIL_SMART_ROUTING_ENABLED=true
```

Configure pools in Settings (`assignment.inbound_email_*`) and optional routing keyword env vars.
