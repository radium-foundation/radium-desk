# Email Intake Phase 1.2 — Dashboard Counters

**Date:** 2026-08-05  
**Priority:** P1  
**Type:** Presentation + navigation  
**Source of truth (architecture):** [docs/email-intake-architecture-investigation.md](./email-intake-architecture-investigation.md)  
**Canvas:** none

---

## Objective

Expose existing inbound-email queue signals on the Dashboard so operators see what needs attention — without building an inbox or Gmail clone.

Phase 1.2 only: counters + navigation. No AI, Round Robin, reply UI, or attachment changes.

---

## Counters shipped

| Counter | Source | Meaning |
|---------|--------|---------|
| 📧 Needs Human Action | `incoming_email_messages` with status `needs_review` or `failed` | Live queue requiring operator intervention |
| P Promotional | Sum of today's `incoming_email_ignore_stats` for `promotions`, `newsletter_or_marketing` | Promotional mail ignored automatically |
| S Spam | Sum of today's ignore stats for `spam`, `trash` | Spam ignored automatically |
| A Completed Automatically | Sum of today's ignore stats for `auto_responder`, `bounce_or_delivery_subsystem`, `known_system_email`, `own_outbound` | Emails completed automatically (auto-replies, bounces, system mail). Learning Center also shows presentation-only groups: System Notifications / Auto Replies / Own Outbound / Bounces / Duplicate Notifications |
| R Review Suggested | Live count of Needs Human mail with `ira_confidence < 45` or `status=failed` | Presentation-only focus queue — does not change routing; rows remain in Needs Human |

Counters with value **0 are hidden**.

Display examples: `📧 12`, `P²³`, `S⁵`, `A⁷` (superscript for P/S/A).

---

## Location

- **Admin layout:** Dashboard KPI strip beside Open / Overdue / Customer Waiting
- **Agent layout (email admins):** Service Cases toolbar beside Ready Queue chips

Minimal pill styling aligned with existing dashboard chips. Counters appear in one place per layout — not duplicated.

---

## Navigation

Clicking a counter opens **Email Intake** admin processing screen:

`GET /admin/incoming-emails?queue={needs_human|review_suggested|promotional|spam|automatic}`  
Optional Completed Automatically filter: `&sub={system_notifications|auto_replies|own_outbound|bounces|duplicate_notifications}`

Not an inbox — read-only queue table (received, from, subject, status, reason) with links to existing Gmail admin tools.

---

## Permissions

Visible only when:

- `inbound_email.enabled` is true, and
- User has `email-intake.view` (Learning Center / Email Intake access)

Default roles with access: Admin, Operations Admin, Support Agent, Support Specialist, Customer Coordinator, Super Admin. Users without `email-intake.view` do not see counters.

---

## Performance

- Needs Human: single indexed count on `incoming_email_messages.status`
- P / S / A: aggregate `SUM(count)` on `incoming_email_ignore_stats` for today by reason bucket
- No Gmail API calls on dashboard load

---

## Files

| File | Role |
|------|------|
| `app/Enums/IncomingEmailIntakeQueue.php` | Queue keys, labels, tooltips, reason buckets |
| `app/Services/IncomingEmail/IncomingEmailIntakeCounterService.php` | Counts + dashboard payload + admin query scopes |
| `app/Http/Controllers/IncomingEmailAdminController.php` | Filtered processing screen |
| `resources/views/admin/incoming-emails/index.blade.php` | Admin queue table |
| `resources/views/dashboard/partials/email-intake-counters.blade.php` | Dashboard pills |
| `resources/views/dashboard/partials/kpi-strip.blade.php` | KPI strip mount |
| `resources/views/dashboard/partials/recent-service-cases.blade.php` | Toolbar mount |
| `app/Services/DashboardService.php` | Passes counters in `statsFor()` |
| `resources/css/app.css` | Counter pill styles |
| `routes/web.php` | `admin.incoming-emails.index` |
| `tests/Feature/IncomingEmail/IncomingEmailIntakeDashboardCountersTest.php` | Feature coverage |

### Intentionally untouched

Gmail sync, classifier logic, processor routing, Customer360 email workspace, reply, attachments.

---

## Tests

- Counters hidden at zero
- Needs Human counter on dashboard when messages queued
- P/S/A from ignore stats
- Permission gate for agents
- Admin index filters by queue
- Ingested spam flows to spam counter + admin filter

---

## Flow

```
Dashboard load
  → IncomingEmailIntakeCounterService::visibleCounters()
       needs_review/failed COUNT
       ignore_stats SUM (today, by reason bucket)
  → Render pills (non-zero only)
  → Click → admin.incoming-emails.index?queue=…
```
