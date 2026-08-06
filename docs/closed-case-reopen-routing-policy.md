# Closed Case Reopen — Ownership & Refund Routing Policy

**Date:** 2026-08-06  
**Status:** Implemented  
**Scope:** Closed-case reopen behaviour and assignment only  
**Out of scope:** Ready Queue eligibility engine (unchanged)

Related:

- [`docs/email-intake-phase1-1-closed-case-reopen.md`](./email-intake-phase1-1-closed-case-reopen.md) — Phase 1.1 reopen foundation  
- [`docs/sc26557-ready-queue-investigation.md`](./sc26557-ready-queue-investigation.md) — production trigger (own SENT echo reopened refund-closed SC26557)

---

## Objective

Reopen closed Service Cases when a **genuine customer** replies, with assignment based on **why the case was closed**. Never reopen on our own outbound mail.

Ready Queue continues to use the existing eligibility engine after reopen; this change does not alter Ready classification rules.

---

## Business rules

### Rule 1 — Own outbound emails (highest priority)

If the message is our own outbound communication, **never reopen** and **ignore completely** (`own_outbound`).

Treat as own outbound when **any** of the following is true:

| Signal | Detection |
|--------|-----------|
| Gmail `SENT` label | Label list contains `SENT` (case-insensitive) |
| From ∈ configured Radium mailboxes | Keys of `inbound_email.mailboxes` **+** `gmail.sync_mailboxes` **+** `reply.mailboxes` |
| OutgoingEmailMessage (provider id) | `provider_message_id` match |
| OutgoingEmailMessage (RFC Message-ID) | `rfc_message_id` match |

This fixes SC26557-class incidents (wallet-credit SENT from `mail@radiumbox.com`).

### Rule 2 — Refund completed

If a customer genuinely replies after a **refund-completed** case:

1. **Reopen** the same case (no duplicate).
2. **Assign to Refund Desk** (configured user; default **Shubhanshi** / `shubhanshi@radiumbox.com`).
3. **Do not** restore the previous owner.

Detection: linked `refund_requests` on the incident with terminal success status (`completed`, `closed`, or legacy `approved`).

Applies today for email; the reopen source enum already includes WhatsApp / Call / Manual / Internal Transfer for future channels.

### Rule 3 — Successfully completed service

If the case was closed as a **successful service** completion and the customer later replies:

1. **Reopen**.
2. **Assign to the last owner** (previous assignee, else sticky agent from close outcome).

Successful service means any of:

| Signal | Examples |
|--------|----------|
| Close reason | `issue_resolved`, `replacement_issued`, `payment_collected_offline`, `approved_by_admin` |
| Reference issued | `orders.transaction_id` filled **and** no terminal refund on the case |

### Rule 4 — Other close reasons

Keep current reopen eligibility and owner-restore behaviour:

| Close reason | Reopen? | Assignment |
|--------------|---------|------------|
| `customer_cancelled` | No | — |
| `duplicate_case` | No | — |
| Soft-deleted | No | — |
| Other / null outcome (no refund, no success signal) | Yes | Default routing → restore last owner when restorable |

---

## Decision matrix

```text
Inbound message
    │
    ├─ Own outbound? (SENT / From mailbox / OutgoingEmailMessage)
    │     └─ IGNORE — never reopen
    │
    ├─ No closed reopenable SC matched
    │     └─ Existing intake paths (active link / historical / smart route / auto-create)
    │
    └─ Closed SC matched + isReopenable
          │
          ├─ Successful service close?     → reopen · Assigned Because = Last Owner
          ├─ Terminal refund completed?    → reopen · Assigned Because = Refund Workflow → Shubhanshi
          └─ Else                          → reopen · Assigned Because = Default Routing → last owner
```

Priority among close signals: **successful service close outcome / reference issued** wins over refund when both could apply (so a later successful service close is not forced onto Refund Desk).

---

## Audit metadata

Event: `incoming_email.case_reopened` (plus `service_case.assigned` when ownership changes).

| Field | Values |
|-------|--------|
| **Reopened By** (`reopened_by` / `reopened_by_label`) | Customer Email · Customer WhatsApp · Customer Call · Manual · Internal Transfer |
| **Assigned Because** (`assigned_because` / `assigned_because_label`) | Refund Workflow · Last Owner · Manual · Default Routing |

Email reopen path today always sets **Reopened By = Customer Email**.

Assignment methods:

| Method | When |
|--------|------|
| `inbound_email_reopen_refund_desk` | Refund Workflow |
| `inbound_email_reopen_previous_owner` | Last Owner / Default Routing (when assignee changes) |

Timeline presentation shows **Reopened By** and **Assigned Because** on the reopen email card when present.

---

## Files changed

| File | Change |
|------|--------|
| `app/Services/IncomingEmail/IncomingEmailIngestService.php` | Harden `isOwnOutboundEcho` (SENT, all mailboxes, RFC match) |
| `app/Services/IncomingEmail/IncomingEmailClosedCaseReopenService.php` | Close-reason routing + refund desk assignment + audit fields |
| `app/Enums/ServiceCaseReopenSource.php` | **New** — Reopened By |
| `app/Enums/ServiceCaseReopenAssignmentReason.php` | **New** — Assigned Because |
| `config/inbound_email.php` | `reopen.refund_desk_user_email` (default Shubhanshi) |
| `app/Services/Timeline/IncomingEmailReopenTimelinePresenter.php` | Display reopen/assignment labels; index refund-desk assigns |
| `app/Services/Timeline/Sources/IncomingEmailTimelineEventSource.php` | Pass reopen audit into display fields |
| `tests/Feature/IncomingEmail/IncomingEmailClosedCaseReopenTest.php` | Coverage for all rules |
| `docs/closed-case-reopen-routing-policy.md` | This document |

**Not changed:** Ready Queue classifier, eligibility service, dashboard snapshot, assignment strategies for Ready pickup.

---

## Migration impact

| Item | Impact |
|------|--------|
| Database migrations | **None** |
| Config | Optional env `INBOUND_EMAIL_REFUND_DESK_USER_EMAIL` (falls back to escalation L1 / `shubhanshi@radiumbox.com`) |
| Existing closed cases | No backfill required; routing evaluated at reopen time from refunds / close outcomes / `transaction_id` |
| Production ops | Ensure Shubhanshi user exists and is active with the configured email |

---

## Configuration

```env
# Optional override (default: SERVICE_CASE_ESCALATION_LEVEL_1_EMAIL or shubhanshi@radiumbox.com)
INBOUND_EMAIL_REFUND_DESK_USER_EMAIL=shubhanshi@radiumbox.com

# Sync mailboxes are now included in own-outbound From matching
INBOUND_EMAIL_GMAIL_MAILBOXES=mail@radiumbox.com
```

---

## Test results

Command:

```bash
php artisan test --filter='IncomingEmailClosedCaseReopenTest|OutgoingEmailReplyTest::test_own_outbound|IncomingEmailReopenTimelinePresentationTest|IncomingEmailIntakePhase1Test::test_known_customer_with_closed'
```

Result: **16 passed** (124 assertions).

| Check | Test |
|-------|------|
| Own SENT never reopens | `test_own_sent_label_never_reopens_closed_case` |
| Sync mailbox From never reopens | `test_sync_mailbox_from_address_never_reopens_closed_case` |
| Outgoing RFC never reopens | `test_outgoing_rfc_message_id_never_reopens_closed_case` |
| Provider-id own outbound (existing) | `OutgoingEmailReplyTest::test_own_outbound_echo_is_ignored_and_not_linked` |
| Refund-completed reply reopens | `test_refund_completed_customer_reply_reopens_and_assigns_to_refund_desk` |
| Refund reply → Shubhanshi | same |
| Successful service → last owner | `test_successful_service_close_reply_assigns_to_last_owner` |
| Reference issued → last owner | `test_reference_number_issued_close_routes_to_last_owner` |
| Non-refund default behaviour | `test_closed_case_reopens_restores_owner_raises_priority_and_notifies` |
| Cancelled / duplicate unchanged | existing cancelled / duplicate tests |
| Audit trail | reopen + refund assignment audits assert `reopened_by` / `assigned_because` |
| Ready Queue | No Ready Queue files modified |

---

## Operational note for SC26557

After deploy, SC26557 (if still incorrectly open from the SENT echo) should be reviewed and closed manually if no genuine customer follow-up exists. New SENT echoes from `mail@` will no longer reopen refund-closed cases; genuine customer replies after refund will reopen to Shubhanshi.
