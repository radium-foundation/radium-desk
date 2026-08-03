# P[04-08]-004 — Step 1: Architecture & Reuse

**Date:** 2026-08-04  
**Status:** Architecture freeze — **no code in this step**  
**Approved direction:** [`docs/p-04-08-003-inbound-email-to-service-case-plan.md`](p-04-08-003-inbound-email-to-service-case-plan.md)  
**Canvas:** [`p-04-08-004-step1-architecture-reuse.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p-04-08-004-step1-architecture-reuse.canvas.tsx)

---

## Verdict

Reuse is confirmed. Implementation will extend `IncomingEmailProcessorService` only for **customer-facing** actionable mail when `inbound_email.auto_create_service_case` is true.

| Mail class | Auto-create Service Case? |
|------------|---------------------------|
| Customer (matched order / sales / support / refund / unknown customer inquiry) | **Yes** (flag on) |
| Internal operational (`FinanceAction`, `HrAction`, `VendorAction`) | **No** — park without SC (keep today’s NeedsReview / Historical behaviour) |
| System / bounce / spam / promo / newsletter | **No** — remain Ignored (filter) |

No duplicate assignment: always `assignOnCreate: false` then `IncomingEmailAssignmentService::routeLinkedEmail`. Timeline, C360, Gmail Health, notifications unchanged in shape.

---

## 1. Service reuse verification

| Service | Role in Step 1+ | Reuse verdict |
|---------|-----------------|---------------|
| `QuickServiceRequestService::createForOrder` | Scenario: order exists, no active SC | **Reuse** — create SC on matched Order; `source=Email`; `assignOnCreate=false` |
| `CustomerIntakeService::createNewContact` | Scenario: customer email, no order | **Reuse** — creates INQ Order + SC (Incident requires `order_id`) |
| `IncomingEmailLinkService::link` | All SC-bound paths | **Reuse** — after create or existing SC; do not use `promoteToServiceCase` for auto path |
| `IncomingEmailAssignmentService::routeLinkedEmail` | After every successful link | **Reuse** — keep owner or Communication Intake; DB notify |
| `IncomingEmailProcessorService` | **Branch point** | **Extend** — only change Historical / NeedsReview customer branches when flag on |
| `OutgoingEmailReplyGate` | Desk reply for assignees | **Extend later step** — assignee exception; not required for architecture freeze |
| `IncomingEmailClassifierService` | Map → category; detect internal ops | **Reuse** — no second classifier |
| `IncomingEmailCustomerMatcher` | Order / SC resolution | **Reuse** as-is in Step 1 (multi-order preference optional later) |
| `IncomingEmailHistoricalAssociationService` | Internal-ops with order, or flag off | **Keep** for non–auto-create paths |
| `IncomingEmailFilterService` | System/spam ignore | **Unchanged** |

**Not reused for ownership:** `EmailTriageAssignmentStrategy` / `assignForEmailClassification` — Incident-oriented contract; Linked path stays on Communication Intake.

---

## 2. Processor branch design

After filter (non-ignored) → match → `classifyOperational`:

```
if ! config('inbound_email.auto_create_service_case')
    → TODAY: Historical associate | NeedsReview | Link+route
    return

if classification ∈ {FinanceAction, HrAction, VendorAction}   // internal operational
    → TODAY park: Historical if order else NeedsReview
    → do NOT create Service Case
    return

if match.incident !== null                                    // order + active SC
    → link → boost → routeLinkedEmail
    return

if match.order !== null                                       // order, no SC
    → createForOrder(...) → link → boost → routeLinkedEmail
    return

// customer email without order (sales/unknown/support inquiry)
→ createNewContact(...) → link → boost → routeLinkedEmail
```

### Branch matrix

| Branch | Condition | Action when flag **on** | Action when flag **off** |
|--------|-----------|-------------------------|--------------------------|
| A — Active SC | `match.incident` set | Link + route (same as today) | Same |
| B — Order, no SC | `historical_customer` | **createForOrder** + link + route | Historical associate |
| C — Customer, no order | no order / unknown | **createNewContact** + link + route | NeedsReview |
| D — Internal ops | Finance / HR / Vendor class | Historical or NeedsReview (**no SC**) | Same as today |
| E — System / spam / etc. | Filter ignored | Ignored | Ignored |

### Internal operational detection

Already produced by `IncomingEmailClassifierService::fromKeywords` when an order match exists:

- `VendorAction` — invoice / vendor / PO keywords  
- `FinanceAction` — GST / AP / remittance keywords  
- `HrAction` — HR / payroll / leave keywords  

**System** mail is usually `known_system_email` via filter → Ignored before classify.

**Gap to note:** when `order` is null, `classifyOperational` returns `PossibleSalesLead` **before** keyword checks — Finance/HR/Vendor keywords on unmatched senders will look like sales leads. Step 1 accepts this; optional later: run keywords before the unknown-customer short-circuit so internal ops without order also skip SC create.

---

## 3. Design constraints (locked)

1. **Customer emails → Service Cases** when flag on (branches A–C).  
2. **Internal operational → no SC yet** (branch D).  
3. **No duplicate assignment** — never `assignOnCreate: true` on auto-create; always `routeLinkedEmail`.  
4. **Preserve** timeline (`IncomingEmailTimelineEventSource`), notifications (`NewEmailReceivedNotification`), Customer 360, Gmail Health.  
5. **No Needs Review UI** (P[04-08]-002 abandoned). NeedsReview status may still be written for internal-ops / flag-off only.  
6. **Incident always has Order** — unknown customers use INQ inquiry Orders.

---

## 4. Feature flag

```php
// config/inbound_email.php
'auto_create_service_case' => filter_var(
    env('INBOUND_EMAIL_AUTO_CREATE_SERVICE_CASE', false),
    FILTER_VALIDATE_BOOLEAN
),
```

| Key | Default |
|-----|---------|
| `inbound_email.auto_create_service_case` | **`false`** |
| Env | `INBOUND_EMAIL_AUTO_CREATE_SERVICE_CASE=false` |

When false: zero behaviour change vs production today.

---

## 5. Final implementation sequence

| Step | Work | Coding? |
|------|------|---------|
| **1 (this)** | Architecture & reuse freeze | **Done — approved** |
| **2** | Config flag + `IncomingEmailServiceCaseCreateService` + category mapper | **Done** — see [`p-04-08-004-step2-create-orchestrator.md`](p-04-08-004-step2-create-orchestrator.md) |
| **3** | Processor: branch B (order, no SC) behind flag | **Done** — see [`p-04-08-004-step3-branch-b.md`](p-04-08-004-step3-branch-b.md) |
| **4** | Processor: branch C (no order) behind flag | **Done** — see [`p-04-08-004-step4-branch-c.md`](p-04-08-004-step4-branch-c.md) |
| **5** | Explicit branch D (internal ops skip create) | Pending |
| **6** | `OutgoingEmailReplyGate` assignee exception | Pending |
| **7** | Tests (A–E matrix) + docs | Pending |
| **8** | Enable flag in staging → production | Ops |

Wait for approval after each coding step if required by process; at minimum wait after Step 1.

---

## 6. Files to modify (later steps — not now)

| File | Change |
|------|--------|
| `config/inbound_email.php` | Add `auto_create_service_case` |
| `.env.example` | Document env key |
| **New** `IncomingEmailServiceCaseCreateService.php` | Orchestrate createForOrder / createNewContact + link + route |
| `IncomingEmailProcessorService.php` | Flag-gated branches B/C/D |
| Small category mapper (helper or private) | Classification → `incidents.category` / `NewContactIntent` |
| `OutgoingEmailReplyGate.php` | SC assignee reply (Step 6) |
| Tests | Intake / ownership / new auto-create feature test |

**Do not modify:** Gmail sync/provider, ingest core, filter, Gmail Health UI, C360 timeline sources, Communication Intake settings logic inside `IncomingEmailAssignmentService`.

---

## 7. Risks

| Risk | Mitigation |
|------|------------|
| Flag on floods Sales Lead INQs | Default false; staging soak; classifier already biases unknown → PossibleSalesLead |
| Internal ops misclassified as sales (no-order keyword gap) | Document; optional keyword-before-unknown fix in Step 5 |
| Double assignment | `assignOnCreate: false` always |
| Concurrent emails create two SCs on same order | Order `lockForUpdate` in create service |
| Historical backlog unchanged | Flag only affects newly processed messages |
| Reply still blocked for agents until Step 6 | Ship gate with create or immediately after |

---

## 8. Rollback plan

1. Set `INBOUND_EMAIL_AUTO_CREATE_SERVICE_CASE=false` (or config cache clear) — instant revert to Historical / NeedsReview.  
2. No schema migration to reverse for core flow.  
3. Inquiry Orders/SCs created while flag was on remain valid; do not bulk-delete.  
4. Code revert of processor + create service if needed; Linked path (branch A) untouched either way.

---

## Approval gate

**Stop here.** No file modifications until Step 1 is approved.

Next after approval: Step 2 — add flag + create orchestrator (still default `false`).
