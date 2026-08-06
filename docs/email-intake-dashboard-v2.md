# Email Intake Dashboard V2

**Date:** 2026-08-05 (Platform Email Operations added 2026-08-06)  
**Priority:** P1  
**Type:** Presentation improvement  
**Source of truth:** [docs/email-intake-architecture-investigation.md](./email-intake-architecture-investigation.md)  
**Prior phases:** [Phase 1.2 counters](./email-intake-phase1-2-dashboard-counters.md) · [Phase 1.3 smart routing](./email-intake-phase1-3-smart-routing.md)  
**Canvas:** none

---

## Platform Email Operations

Email Intake is treated as a **platform service**, monitored from **Administration → Platform** as a left-nav zone (same shell as Platform Health, Integration Health, Cashfree, etc.).

| Rule | Detail |
|------|--------|
| Entry point | Existing Platform page (`admin.platform.index`) |
| Zone key | `email_operations` |
| Not | A new dashboard, workspace, or Dashboard KPI expand |
| Design | Existing Platform spacing, typography, and card language |
| Load | Lazy zone refresh after first paint (Platform zone framework) |
| Cache | `platform:email-operations:overview` (Priority 3 TTL); invalidated with intake widget cache |

### Sections (trusted metrics only)

1. **Today’s Operations** — Received, Processed, Needs Human, New Cases, Linked, Ignored, Failures  
2. **Processing Pipeline** — compact Received → Filtered → Linked → New Case → Needs Human → Completed (no charts)  
3. **Case Creation** — Support / Sales / Refund / Vendor / Docs / Unknown (hide zero buckets)  
4. **Classification** — Support / Sales / Refund / Promotion / Spam / Docs / Completed Automatically / Needs Human / Review Suggested (uncertain IRA only; view filter)  
5. **Assignment Health** — Assigned, Pending, Fallback only when reliable; Ownership Preserved / Assignment Failures / Queue Growing are **not** shown without trusted signals  
6. **IRA Memory** — Used Today, New Memories, Top Memory, Average Confidence  
7. **Exceptions** — actionable only (Needs Human, Processing Failure, Fallback Used, Stuck Emails, Gmail sync delay)  
8. **Recent Activity** — latest intake events; click opens Learning Center, Gmail failures, or Customer 360

### Drill-downs (existing screens only)

| Metric | Destination |
|--------|-------------|
| Needs Human / Classification / Assignment | IRA Learning Center queues |
| New Cases / Case Creation | Active cases / dashboard workspace |
| Failures | Gmail Failed Messages (fallback: Needs Human queue) |
| Integration context | Platform Integration Health (`#platform-zone-integration_health`) |

Service: `PlatformEmailOperationsService` · Zone: `EmailOperationsZone` · Partial: `admin.platform.zones.partials.email-operations`.

---

## Objective

Replace the four floating implementation counters (📧 P S A) with **one operator-focused KPI card** that answers: *“What email work is waiting?”*

Presentation + attention categorization only. No Gmail, inbox, routing, or workflow changes.

The Dashboard **Email Intake** KPI remains the operator “work waiting” signal. **Platform → Email Operations** is the admin/ops health surface for the same intake pipeline.

---

## Before → After

| Before (Phase 1.2) | After (Dashboard V2) |
|--------------------|----------------------|
| Four separate pills (Needs Human, Promotional, Spam, Completed Automatically) | Single **Email Intake** KPI card |
| Implementation categories visible by default | **Needs Attention** total front and centre |
| Hidden at zero | Card always visible (zero state supported) |
| Per-queue click targets | Card click → needs-human queue |

Ignored mail (Promotions, Spam, Completed Automatically) moves to **hover breakdown only**.

---

## KPI card

```
+-----------------------------+
|       Email Intake          |
|            15               |
|      Needs Attention        |
+-----------------------------+
```

- Same KPI strip placement as Open / Overdue / Refunds / Customer Waiting
- Agent layout (email admins): beside Ready Queue chips
- Click anywhere → `/admin/incoming-emails?queue=needs_human`

### Severity accent (border only)

| Needs Attention | Accent |
|-----------------|--------|
| 0 | Normal |
| 1–5 | Blue |
| 6–15 | Amber |
| 16+ | Red |

No aggressive background fills.

---

## Needs Attention formula

**Needs Attention = Sales + Orders + Priority**

Does **not** include Promotions, Spam, or Completed Automatically (those appear in hover only).

### Business categories

| Category | Meaning | Detection |
|----------|---------|-----------|
| **Priority** | High-risk operational email | Configurable phrase rules (first match wins) |
| **Sales** | New purchase enquiries | `possible_sales_lead`, sales mailbox/rules |
| **Orders** | Existing customer / order email | `order_id`, customer classifications, known order email match |

Mutually exclusive buckets; default unmatched → Sales.

---

## Hover breakdown

```
Needs Attention
Sales ............. 10
Orders ............. 3
Priority ........... 2
-------------------------
Promotions......... 26
Spam............... 12
Completed Automatically.......... 43
```

Promotions / Spam / Completed Automatically reuse today's `incoming_email_ignore_stats` aggregates (unchanged from Phase 1.2).

---

## Priority phrase engine

- Config: `INBOUND_EMAIL_PRIORITY_PHRASES` (comma-separated env → `config/inbound_email.priority_phrases`)
- **No hardcoded keywords in code**
- Scans subject, from, preview (case-insensitive substring match)
- Audit event: `incoming_email.priority_detected` — idempotent per message

Recorded fields: matched phrase, matched rule, rule source, mailbox, message id.

---

## Permissions

Unchanged from Phase 1.2: visible when `inbound_email.enabled` and user can update `SystemSetting`.

---

## Performance

- Needs-attention backlog: single query on `needs_review` / `failed` statuses
- Order email lookup: one batched `whereIn` on customer emails
- Ignore stats: existing daily SUM aggregates
- No Gmail API

---

## Files

| File | Role |
|------|------|
| `app/Enums/IncomingEmailAttentionCategory.php` | Sales / Orders / Priority |
| `app/Services/IncomingEmail/IncomingEmailPriorityPhraseService.php` | Phrase rules + audit |
| `app/Services/IncomingEmail/IncomingEmailAttentionCategoryService.php` | Backlog categorization + counts |
| `app/Services/IncomingEmail/IncomingEmailIntakeCounterService.php` | `dashboardWidget()` payload + Platform cache invalidation |
| `resources/views/dashboard/partials/email-intake-kpi-card.blade.php` | KPI card + hover |
| `resources/views/dashboard/partials/kpi-strip.blade.php` | Admin mount |
| `resources/views/dashboard/partials/recent-service-cases.blade.php` | Agent mount |
| `app/Services/DashboardService.php` | `email_intake_widget` in stats |
| `resources/css/app.css` | Card + severity + hover styles; Platform Email Operations KPI accents |
| `config/inbound_email.php` | `priority_phrases` |
| `tests/Feature/IncomingEmail/IncomingEmailDashboardV2Test.php` | V2 coverage |
| `tests/Unit/IncomingEmail/IncomingEmailPriorityPhraseServiceTest.php` | Phrase unit tests |
| `app/Enums/PlatformZoneId.php` | `EmailOperations` zone id |
| `app/Services/Platform/PlatformEmailOperationsService.php` | Trusted Platform aggregates |
| `app/Services/Platform/Zones/EmailOperationsZone.php` | Platform left-nav zone |
| `resources/views/admin/platform/zones/partials/email-operations.blade.php` | Zone HTML |
| `tests/Feature/Platform/EmailOperationsZoneTest.php` | Platform zone coverage |

Removed: `resources/views/dashboard/partials/email-intake-counters.blade.php`

### Intentionally untouched

Gmail sync, smart routing processor, admin queue filters, reply UI, attachments. No separate Email Operations product area or Dashboard slide-down workspace.

---

## Tests

- Needs Attention = Sales + Orders + Priority
- Hover ignored counts
- Priority phrase detection + audit
- Sales / Orders categorization
- Zero state + severity thresholds
- Permissions
- Navigation to needs-human queue
- KPI card replaces old floating counters

---

## Configure priority phrases

```env
INBOUND_EMAIL_PRIORITY_PHRASES="consumer forum,legal notice,court,chargeback,RBI complaint"
```

Leave empty to disable priority phrase matching until configured.
