# IRA Learning Center — Phase 1

**Date:** 2026-08-06  
**Priority:** P1  
**Type:** Review-and-teach workspace for Email Intake  
**Source of truth:** [docs/email-intake-architecture-investigation.md](./email-intake-architecture-investigation.md)  
**Related:** [Phase 1.3 smart routing](./email-intake-phase1-3-smart-routing.md) · [Dashboard V2](./email-intake-dashboard-v2.md)  
**Canvas:** none

---

## Objective

Transform Email Intake **Needs Human Action** into the first **IRA Learning Center**.

This is **not** a Gmail inbox. It is a review-and-teach workspace where operators:

1. Review IRA suggestions with explainability  
2. Confirm decisions (Assign / Classification / Importance / Ignore)  
3. Choose a learning scope  
4. Persist Learning Rules that run **before AI** on future mail  

AI never silently invents rules. IRA only suggests; operators confirm; rules are saved.

---

## Phase 1 scope

| In | Out |
|----|-----|
| Compact operator cards for Needs Human Action | Gmail clone / inbox UI |
| Learning actions + scopes | Attachments UX |
| Persistent Learning Rule model | Email composer / reply redesign |
| Rules execute before AI | Canvas |
| Explainability on every suggestion | Full AI classifier (Phase 2) |
| Bulk Assign / Ignore / Classification / Importance | |

---

## Operator cards

Each Needs Human Action card shows:

- Sender  
- Customer (or **Unknown Customer**)  
- Subject  
- Preview (2 lines)  
- IRA Decision  
- Confidence  
- Suggested Assignee  
- Reason  
- Expandable explainability (Why / Examples / Matched sender / Matched keyword / Previous operator confirmation / Rule confidence)

Internal statuses such as `needs_review` and `unknown_customer` are **never** exposed in the Learning Center UI.

Other Email Intake queues (Promotional / Spam / Completed Automatically) keep a simple table for ops inspection.

---

## Learning actions

Every card (and bulk toolbar) supports:

### Assign

Choose user → Apply → Scope

### Classification

Support · Sales · Refund · Vendor · Promotion · Spam · Completed Automatically  

Promotion / Spam / Completed Automatically also remove the item from Needs Human Action (ignored).

### Importance

Normal · High · Escalation

### Ignore

Ignore once · Always Ignore · Vendor Update · Newsletter · System Email  

“Ignore once” never persists a rule, even if a broader scope is selected.

### Learning scopes

| Scope | Persistent rule type |
|-------|----------------------|
| This email only | None |
| Same sender | Sender |
| Same domain | Sender Domain |
| Same subject pattern | Subject Pattern |
| Always | Mailbox |

---

## Learning Rules

Table: `incoming_email_learning_rules`

| Field | Purpose |
|-------|---------|
| `rule_type` | sender / sender_domain / subject_pattern / mailbox / keyword |
| `match_value` | Normalized match key |
| `decision_type` | assign / classification / importance / ignore |
| `decision_value` | Target user id / operator classification / importance / ignore action |
| `confidence` | 1–100 |
| `created_by` | Operator who taught the rule |
| `times_used` / `last_used_at` | Usage stats |
| `enabled` | Soft disable |

Unique key: `(rule_type, match_value, decision_type)` — re-teaching updates the decision.

### Execution order

```
Filter (spam/promo/system)
  → Learning Rules   ← BEFORE AI / deterministic classifier
  → Priority phrases
  → Matcher / Classifier / Smart routing / Needs Human
```

Ignore learning rules short-circuit processing. Classification / importance / assign overrides are preserved into the rest of the pipeline.

---

## Explainability contract

Every suggestion payload includes:

- `why`  
- `examples[]`  
- `matched_sender`  
- `matched_keyword`  
- `previous_operator_confirmation`  
- `rule_confidence`

Suggestions may come from:

1. Stored IRA fields on the message (after operator teaching or rule application)  
2. Matching Learning Rules  
3. Deterministic heuristics (no silent rule creation)

---

## Message enrichment

`incoming_email_messages` gains Learning Center columns:

- `importance`  
- `learning_owner_user_id`  
- `suggested_assignee_user_id`  
- `ira_decision` / `ira_confidence` / `ira_reason` / `ira_explanation`  
- `matched_learning_rule_id`

---

## Permissions

Unchanged from Email Intake admin: user can update `SystemSetting` and `inbound_email.enabled` is on.

---

## Routes

| Method | Path | Name |
|--------|------|------|
| GET | `/admin/incoming-emails` | `admin.incoming-emails.index` |
| POST | `/admin/incoming-emails/learning` | `admin.incoming-emails.learning.apply` |

---

## Files

| File | Role |
|------|------|
| `app/Models/IncomingEmailLearningRule.php` | Persistent rule model |
| `app/Services/IncomingEmail/IncomingEmailLearningRulesService.php` | Match / apply / upsert rules |
| `app/Services/IncomingEmail/IncomingEmailLearningActionService.php` | Operator teach actions + bulk |
| `app/Services/IncomingEmail/IncomingEmailLearningCenterPresenter.php` | Operator-facing card payloads |
| `app/Http/Controllers/IncomingEmailAdminController.php` | Index + applyLearning |
| `resources/views/admin/incoming-emails/partials/learning-center.blade.php` | Learning Center shell + bulk |
| `resources/views/admin/incoming-emails/partials/learning-card.blade.php` | Compact operator card |
| `resources/views/admin/incoming-emails/index.blade.php` (scripts) | Bulk select / action panels |
| `database/migrations/2026_08_06_120000_create_incoming_email_learning_rules_table.php` | Rules table |
| `database/migrations/2026_08_06_120100_add_learning_center_columns_to_incoming_email_messages_table.php` | Message enrichment |
| `tests/Feature/IncomingEmail/IncomingEmailLearningCenterPhase1Test.php` | Phase 1 coverage |

---

## Tests

- Needs Human renders operator cards without internal statuses  
- Assign + same sender → learning rule  
- Bulk ignore + domain rule  
- Classification teaching  
- Importance + subject pattern rule  
- Learning rules execute before intelligence (ignore short-circuit)  
- Ignore once does not persist a rule  

---

## Future Phase 2

1. **Real IRA AI suggestions** — LLM/ERA classify + assign proposals with the same explainability contract (still require operator confirm to save rules).  
2. **Keyword rule teaching UI** — explicit keyword capture beyond subject-pattern / mailbox scopes.  
3. **Promote taught route → Service Case** — Assign + Support/Sales/Refund can auto-create / reopen SC using Phase 1.1–1.3 spine.  
4. **Learning Rules admin browser** — shipped as [Administration → IRA Memory](ira-memory-foundation.md) (`/admin/ira-memory`): search, filter, view, enable/disable, edit, merge, soft delete, Test Memory.  
5. **Feedback loop metrics** — suggestion acceptance rate, rule precision, IRA calibration.  
6. **Multi-decision rule packs** — one confirmation writing assign + classification + importance together.  

---

## Enable

```env
INBOUND_EMAIL_ENABLED=true
```

Open `/admin/incoming-emails?queue=needs_human` (Email Intake KPI card continues to deep-link here).
