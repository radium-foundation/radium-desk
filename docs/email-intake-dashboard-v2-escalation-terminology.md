# Email Intake Dashboard V2 — Escalation Terminology

**Date:** 2026-08-05  
**Priority:** P2  
**Type:** Presentation-only terminology  
**Source of truth:** [docs/email-intake-dashboard-v2.md](./email-intake-dashboard-v2.md)  
**Canvas:** none

---

## Objective

Rename the Dashboard V2 business category **Priority** → **Escalations** everywhere operators see it.

No business logic, routing, Gmail, or layout changes.

---

## Why

| Term | Problem |
|------|---------|
| Priority | Subjective — could mean any urgent mail |
| Escalations | Clear — consumer forum, legal notice, chargeback, CEO/media escalation, etc. require **management attention** |

---

## What changed (user-facing)

| Surface | Before | After |
|---------|--------|-------|
| KPI hover row | Priority | **Escalations** |
| Widget payload label | Priority | **Escalations** |

Needs Attention formula unchanged internally: `sales + orders + priority`.

---

## What did NOT change (backward compatibility)

| Item | Kept as-is | Reason |
|------|------------|--------|
| Enum case `IncomingEmailAttentionCategory::Priority` | ✓ | Internal key; no migration |
| Aggregate key `priority` in counts array | ✓ | Service contract |
| `IncomingEmailPriorityPhraseService` class name | ✓ | Avoid breaking DI / autoload references |
| `IncomingEmailPriorityMatch` DTO | ✓ | Internal type |
| Config `INBOUND_EMAIL_PRIORITY_PHRASES` | ✓ | Existing deployments |
| Config key `inbound_email.priority_phrases` | ✓ | Existing deployments |
| Audit event `incoming_email.priority_detected` | ✓ | Production audit history |
| Audit field `rule_source: config:inbound_email.priority_phrases` | ✓ | Audit consistency |

---

## Implementation

Single presentation change in enum label:

```php
// IncomingEmailAttentionCategory::label()
self::Priority => 'Escalations',
```

Dashboard hover reads labels via `IncomingEmailAttentionCategory::Priority->label()` — no blade or layout edits required.

---

## Hover (after)

```
Needs Attention
Sales ............. 10
Orders ............. 3
Escalations ........ 2
-------------------------
Promotions......... 26
Spam............... 12
Completed Automatically.......... 43
```

---

## Files

| File | Change |
|------|--------|
| `app/Enums/IncomingEmailAttentionCategory.php` | Display label only |
| `tests/Feature/IncomingEmail/IncomingEmailDashboardV2Test.php` | Assert **Escalations** in widget + HTML |

### Intentionally untouched

Routing, phrase matching logic, audit events, config env names, service class names, dashboard CSS/layout.

---

## Tests

- Widget hover label `Escalations`
- Dashboard HTML contains `Escalations`, not hover row `Priority`
- Escalation phrase detection + audit behaviour unchanged (`incoming_email.priority_detected`)

---

## Future (optional, not in scope)

If a later release needs full internal rename (`EscalationPhraseService`, `escalation_phrases`, audit event alias), ship with dual-read compatibility first.
