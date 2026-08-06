# Email Intake — Disposition Workflow

**Date:** 2026-08-06  
**Status:** Implemented  
**Related:** [`docs/ira-learning-center-phase1.md`](ira-learning-center-phase1.md), [`docs/email-intake-phase1-production-audit.md`](email-intake-phase1-production-audit.md)

---

## Problem

Teach IRA ≠ complete operator work.

Assign and Docs previously annotated the message (and saved learning rules) but left it in `needs_review` forever. Operators thought the work was done; Needs Attention stayed stuck.

---

## Separation

| Concern | Purpose | Clears Needs Human? |
|---------|---------|---------------------|
| **Teaching** | Classification, Owner, Importance, Rule, Confidence | **No** (except legacy Ignore learning action) |
| **Disposition** | Finish the email | **Yes** (except Keep Pending) |

```
Operator reviews
    ↓
Teach (optional)
    ↓
Disposition (required)
    ↓
Completed
```

---

## Disposition options

| Disposition | Effect | Leaves Needs Human? |
|-------------|--------|---------------------|
| **Create Service Case** | Creates case (or reuses active), links email, assigns owner when provided / taught | Yes → `linked` |
| **Link Existing Case** | Resolves `SC#####`, links email | Yes → `linked` |
| **Ignore** | Ignore once / Always ignore sender / Always ignore domain | Yes → `ignored` |
| **Spam** | Mark spam + ignore | Yes → `ignored` |
| **Promotion** | Mark promotional + ignore | Yes → `ignored` |
| **Completed Automatically** (`auto_processed`) | Park as auto-handled ignore | Yes → `ignored` |
| **Keep Pending** | Reason required; stays in queue | **No** — only deliberate pending |

### Completed Automatically groups (Learning Center only)

Operator breakdown under the Completed Automatically tab — presentation filters only:

- System Notifications
- Auto Replies
- Own Outbound
- Bounces
- Duplicate Notifications

Does not change how mail is ignored or routed.

### Review Suggested (Learning Center only)

Queue tab for emails where IRA is uncertain (`ira_confidence < 45` or processing `failed`). Still appears in Needs Human. Does not change routing.

### Docs

Docs remains a **classification only**. It is never a final disposition. Operator must still Create Case, Link Case, Ignore, Spam, Promotion, Completed Automatically, or Keep Pending.

### Spam recovery

Assign / Create Case / Link Case from the Spam queue restores the message to **Needs Review** first (after operator confirmation). Human-owned mail must not remain in Spam.

---

## Teaching (unchanged purpose, purified)

Operator may teach without disposing:

- Owner (Assign)
- Classification — Support / Sales / Refund / Vendor / **Docs**
- Importance — Normal / High / Escalation
- Learning scope — This email / Same sender / Same domain / Same subject pattern / Always

Promotion / Spam / Completed Automatically are **not** teaching classifications in the UI anymore — they are dispositions.

---

## Create Service Case

1. Resolve customer-facing classification (Docs / non-operational → `unknown_customer`).
2. If `order_id` present → `createLinkAndRouteForOrder`.
3. Else → `createLinkAndRouteForUnknownCustomer` (INQ order + SC).
4. Assign owner from disposition form, else taught `learning_owner_user_id` / `suggested_assignee_user_id`.
5. Record disposition + leave Needs Human.

---

## Link Existing Case

1. Parse case reference (`SC27794`, `SC-27794`, etc.).
2. Link via `IncomingEmailLinkService`.
3. Route / assign as needed.
4. Record disposition + leave Needs Human.

---

## Ignore variants

| Variant | Rule | Scope |
|---------|------|-------|
| Ignore once | No persistent rule | This email |
| Always ignore sender | Ignore rule | Same sender |
| Always ignore domain | Ignore rule | Same domain |

---

## Keep Pending

Required reason:

- Waiting Customer  
- Need Manager  
- Need Order Number  
- Need Investigation  

Status stays `needs_review` / `failed`. Dashboard Needs Attention **still counts** these — they are deliberately pending disposition.

---

## Dashboard Needs Attention

Counts messages in `needs_review` / `failed` (Sales / Orders / Escalations buckets).

Because terminal dispositions change status to `linked` or `ignored`, the tile equals **pending disposition** (including Keep Pending). Teaching alone no longer freezes the count.

Widget cache is cleared on every disposition (`forgetDashboardWidgetCache`).

---

## Audit

| Event | When |
|-------|------|
| `incoming_email.learning_action` | Teaching (assign / classification / importance / ignore) |
| `incoming_email.learning_rule_saved` | Persistent rule upsert |
| `incoming_email.disposition` | Every disposition — includes `completed`, `completed_at`, `completed_by`, disposition, reason |
| `incoming_email.ignored` / `incoming_email.linked` | Side effects of terminal dispositions |

Columns on `incoming_email_messages`:

- `disposition`
- `disposition_reason` (Keep Pending)
- `disposed_at`
- `disposed_by_user_id`

---

## UI

Learning Center Needs Human queue:

1. **Teach** toolbar — Owner / Classification / Importance + scope → `POST …/learning`
2. **Dispose** toolbar — Create / Link / Ignore / Spam / Promotion / Completed Automatically / Keep Pending → `POST …/disposition`

Row ⋯ menu exposes both teaching and disposition actions.

---

## Permissions

| Action | Permission |
|--------|------------|
| View Learning Center | `email-intake.view` |
| Teach / disposition POST | `email-intake.manage` |

Default grants: Admin, Operations Admin, Support Agent, Support Specialist, Customer Coordinator, Super Admin. Authorization checks permissions only (never role names).

## Routes

| Method | Route | Name | Permission |
|--------|-------|------|------------|
| GET | `/admin/incoming-emails` | `admin.incoming-emails.index` | `email-intake.view` |
| POST | `/admin/incoming-emails/learning` | `admin.incoming-emails.learning.apply` | `email-intake.manage` |
| POST | `/admin/incoming-emails/disposition` | `admin.incoming-emails.disposition.apply` | `email-intake.manage` |

---

## Key files

| File | Role |
|------|------|
| `app/Enums/IncomingEmailDisposition.php` | Disposition enum |
| `app/Enums/IncomingEmailIntakeQueue.php` | Needs Human / Review Suggested / Completed Automatically queues |
| `app/Enums/IncomingEmailAutomaticSubcategory.php` | Completed Automatically operator groups |
| `app/Enums/IncomingEmailKeepPendingReason.php` | Keep Pending reasons |
| `app/Enums/IncomingEmailIgnoreDispositionVariant.php` | Ignore once / sender / domain |
| `app/Services/IncomingEmail/IncomingEmailDispositionService.php` | Disposition orchestration |
| `app/Services/IncomingEmail/IncomingEmailIntakeCounterService.php` | Queue counts + automatic subcategory breakdown |
| `app/Services/IncomingEmail/IncomingEmailLearningActionService.php` | Teaching only (classification no longer ignores) |
| `app/Http/Controllers/IncomingEmailAdminController.php` | `applyLearning` + `applyDisposition` |
| `database/migrations/2026_08_06_130000_add_disposition_columns_to_incoming_email_messages_table.php` | Columns |
| `tests/Feature/IncomingEmail/IncomingEmailDispositionWorkflowTest.php` | Coverage |

---

## Tests verified

- Assign + Create Case → linked, owner assigned, Needs Human −1  
- Docs + Link Case → linked; Docs rule saved; teaching alone did not clear  
- Ignore / Spam / Promotion / Completed Automatically → ignored + disposition set  
- Keep Pending → reason required; stays in Needs Human; shown in UI  
- Dashboard widget updates immediately after disposition  
- Learning rules unaffected for teach-only Docs / Assign  

---

## Ops note (existing backlog)

Production ids `178723`, `178727`, `178731` can now be cleared with:

- Amazon Andon → Ignore (always domain) or Spam  
- Shopify orphan → Ignore once (or Link if a case exists)  
- Aditya Sharma → Create Service Case (with owner) or Link Existing Case  

No reprocess required once disposition is applied.
