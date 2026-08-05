# SC23098 — Refund Completed But Case Not Auto-Closed

**Date:** 2026-08-05  
**Priority:** P0 production (read-only)  
**Status:** Root cause proven · no code or production changes made  
**Prod HEAD:** `bdb71fd6`  
**Timezone:** Asia/Kolkata (IST)

---

## Bottom line

Refund **REF-2026-000118** for **SC23098** / **RD3469260** was approved, executed (wallet), and marked **Completed**. Auto-close did **not** run.

**Exact root cause:** After completion, customer notification for `refund_confirmation` was attempted on WhatsApp only. Production has `INTERAKT_TEMPLATE_REFUND_CONFIRMATION_ENABLED=false` (template name null). WhatsApp returned **Skipped - Template not configured**. The dispatcher treated that as aggregate failure (`aggregate_success: false`). `RefundRequestService::complete()` then early-returned when `notifyCustomer() === false`, so `RefundCaseCloseService::closeLinkedCase()` was never called.

This is intentional coupling in code (covered by a regression test), triggered by configuration: WhatsApp-only channel + disabled refund confirmation template.

---

## Identity

| Entity | Value |
|--------|-------|
| Service case | **SC23098** (`incidents.id` = 23179) |
| Case status (now) | `awaiting_product_details` (not closed) |
| Assignee (now) | Jayram Kumar (user 5) |
| Order | **RD3469260** (`orders.id` = 22745) |
| Order status | `active` |
| Payment amount | ₹499.00 |
| Customer | Ashim Das · 7407284402 · ashim.shiba@gmail.com |
| Refund | **REF-2026-000118** (`refund_requests.id` = 118) |
| Refund terminal status | **`completed`** (not `closed`) |
| Refund method | Wallet (`approved_refund_method` = `wallet`) |
| Amount | ₹499.00 (full refund) |
| Channels selected | `["whatsapp"]` only |
| Active business hold | Hold #83 type `refund` — **still active** (`cleared_at` null) |

---

## Refund terminal state verification

| State | Result |
|-------|--------|
| Approved | **Yes** — 2026-08-02 23:51:08 by Shipra (user 3) → `pending_execution` |
| Processed / executed | **Yes** — 2026-08-02 23:51:19 by Shipra; provider `manual`; execution ref `REF-2026-000118` |
| Completed | **Yes** — status `completed`, `executed_at` set |
| Closed (refund workflow terminal) | **No** — `closed_at` null; no `refund.closed` audit |
| Paid (Cashfree/UTR) | N/A for wallet path — wallet method, no `execution_transaction_id` / `refund_transaction_id` |
| Failed | **No** |
| Cancelled / rejected | **No** |
| Partially refunded | **No** — full ₹499 |

**Exact terminal state today:** refund = **`completed`**; case = **`awaiting_product_details`**; refund hold = **active**.

---

## Auto-close rule — who closes the case?

| Question | Answer |
|----------|--------|
| Controller? | Entry: `RefundRequestController::complete` |
| Service? | `RefundRequestService::complete` then `RefundCaseCloseService::closeLinkedCase` |
| Listener / observer / cron / queue job / workflow / state machine? | **No** for case close |
| Finance event? | `RefundCompleted` → `PostRefundCompletedJournal` only (journal); does **not** close cases |

### Code path

```
POST /refunds/{refund}/complete
  → routes/web.php (refunds.complete)
  → RefundRequestController::complete()
  → RefundRequestService::complete()
       1. DB txn: status → Completed + refund.completed audit
       2. RefundCompleted::dispatch()  (finance journal listener)
       3. RefundNotificationService::notifyCustomer(...)
       4. if notifyCustomer === false → return early   ← SC23098 stopped here
       5. notifyRequesterOfDecision('completed')
       6. RefundCaseCloseService::closeLinkedCase()
            → clear refund business hold
            → system remark (REFUND_CLOSE)
            → ServiceCaseStatusService::updateStatus(Closed)
            → refund status → Closed + refund.closed audit
```

Key gate in `app/Services/RefundRequestService.php`:

```php
$customerNotified = $this->notificationService->notifyCustomer(...);

if ($customerNotified === false) {
    return $completed->fresh() ?? $completed;  // skips closeLinkedCase
}

$this->caseCloseService->closeLinkedCase($completed, $user, $request);
```

Return contract of `notifyCustomer` (`RefundNotificationService`):

| Return | Meaning | Case close? |
|--------|---------|-------------|
| `true` | notification delivered | Yes |
| `null` | skipped (empty channels) | Yes |
| `false` | required but failed | **No** |

Confirmed by `tests/Feature/RefundRequestTest.php::test_service_case_stays_open_when_customer_notification_fails`.

---

## Did auto-close trigger?

| Question | Answer |
|----------|--------|
| Did auto-close trigger? | **NO** |
| Why skipped? | Customer notification returned `false` after WhatsApp template skip; `closeLinkedCase` never invoked |
| Evidence of close attempt? | No `refund.closed` audit; no close remark; hold uncleared; case status unchanged after completion |

---

## Condition checklist

| Condition | SC23098 | Blocks close? |
|-----------|---------|---------------|
| Refund status `pending_execution` before complete | Met | — |
| Execution reference present | Met (`REF-2026-000118`) | — |
| Linked incident exists | Yes (23179) | — |
| Case already closed | No | Would short-circuit close only |
| Open case / awaiting_product_details | Yes | Not a close blocker in refund path |
| Case owner / pending engineer | Assignee existed | Not checked by refund close |
| Waiting customer | None | No |
| Attachments / notes | N/A | Not checked |
| Multiple refunds | One only | No |
| Manual hold (non-refund) | No — hold is refund hold #83 | Cleared only inside `closeLinkedCase` (never reached) |
| Escalation | No evidence | No |
| Feature flag for auto-close | None | — |
| WhatsApp refund template enabled | **`false`** | **Yes — caused notify failure** |
| Communication channels | `whatsapp` only | No email fallback attempted |
| Queue / job failure | N/A — sync HTTP path | No |
| Exception in `closeLinkedCase` | Never entered | No |

---

## Queue / jobs / schedule

| Check | Result |
|-------|--------|
| Auto-close mechanism | Synchronous in HTTP request — not queued |
| `failed_jobs` (refund-related) | 0 |
| `failed_jobs` total | 0 |
| `job_batches` | 0 |
| Pending `jobs` | 0 |
| Dead letter / retries / timeouts for this close | None — close code never ran |
| Cron / scheduled auto-close for refunds | None |

---

## Exact timeline (IST)

| Time | Event | Actor / evidence |
|------|-------|------------------|
| 2026-08-01 19:46:45 | Case SC23098 created; automation grace | system |
| 2026-08-01 19:48:01 | Assigned to Shipra (shift admin override) | user 1 |
| 2026-08-02 23:20:36 | Refund hold #83 activated on case | Jyotsana (10) |
| 2026-08-02 23:20:41 | REF-2026-000118 requested (`pending`), channels=`whatsapp` | Jyotsana (10) |
| 2026-08-02 23:20:42 | Approver agent notifications | audit `refund.agent_notified` |
| 2026-08-02 23:51:08 | Refund approved → `pending_execution`, method `wallet` | Shipra (3) |
| 2026-08-02 23:51:08 | `refund.execution_started` | Shipra (3) |
| 2026-08-02 23:51:19 | Refund **completed** (manual provider) | Shipra (3) · audit `refund.completed` |
| 2026-08-02 23:51:19 | `notification.dispatched` refund_confirmation — **aggregate_success=false**, message **Skipped - Template not configured**, channel whatsapp `not_yet_configured` | auditable Incident#23179 |
| 2026-08-02 23:51:19 | `refund.customer_notified` **success=false** | Shipra (3) |
| 2026-08-02 23:51:19 | **Auto-close skipped** (early return) | code path |
| — | No `refund.closed`, no status→closed, hold remains | proven by absence |
| 2026-08-03 09:15:14 | Case reassigned Jyotsana → Jayram | manual |
| 2026-08-05 | Still `awaiting_product_details`; refund still `completed` | live query |

---

## Audit trail (refund 118 — complete)

| Audit id | Event | success / notes |
|----------|-------|-----------------|
| 650461 | `refund.requested` | channels whatsapp |
| 650462 | `created` | pending |
| 650463 | `refund.agent_notified` | submitted |
| 650514 | `refund.approved` | → pending_execution / wallet |
| 650515 | `refund.execution_started` | |
| 650516 | `approved` (legacy) | |
| 650517 | `refund.completed` | completed |
| 650519 | `refund.customer_notified` | **success: false** |
| — | `refund.closed` | **MISSING** |

Incident notification audit **650518**:

```json
{
  "notification_type": "refund_confirmation",
  "source": "refund_workflow_complete",
  "aggregate_success": false,
  "aggregate_message": "Skipped - Template not configured",
  "channel_results": [
    {
      "channel": "whatsapp",
      "status": "not_yet_configured",
      "success": true,
      "message": "Skipped - Template not configured"
    }
  ]
}
```

Production config at investigation time:

| Config | Value |
|--------|-------|
| `interakt.templates.refund_confirmation.enabled` | **false** |
| `interakt.templates.refund_confirmation.name` | **null** |

No `whatsapp_template_dispatches` row for refund confirmation on this incident (skip happens before dispatch).

---

## Why notification failure becomes close skip

1. `WhatsAppChannel::send` → `isTemplateConfigured` fails → `skippedTemplateResult` (`success: true`, `status: not_yet_configured`).
2. `NotificationResult::countsTowardSuccess()` is false for skipped results.
3. `NotificationDispatchResult::fromResults` with only skipped channels → `success: false`.
4. `RefundNotificationService::notifyCustomer` returns that boolean (`false`).
5. `RefundRequestService::complete` treats `false` as hard stop before close.

Empty channels would return `null` and **still close**. A configured-but-disabled WhatsApp-only selection returns `false` and **blocks close**. That asymmetry is the defect surface.

---

## Root cause classification

| Category | Applies? |
|----------|----------|
| Bug | **Yes** — case close incorrectly coupled to customer notification success; skipped/unconfigured channel is treated as failure |
| Configuration | **Yes** — refund confirmation WhatsApp template disabled / unnamed in production |
| Business rule | Partially — product test encodes “stay open on notify failure”; operationally wrong when payout already completed |
| Data issue | No (links, amounts, statuses consistent) |
| Race condition | No |
| Manual intervention blocking close | No |
| Queue / automation failure | No |
| Unexpected state | Refund left in `completed` limbo (never promoted to `closed`) |

**Primary root cause:** notification-gated close + WhatsApp refund template not configured + WhatsApp-only channel selection.

---

## Production impact

Search: `refund_requests.status = completed` with linked incident status ≠ `closed`.

| Count | Cases |
|-------|-------|
| **2** stuck open cases | **SC23098** (REF-118), **SC23667** (REF-119) |

Both twins share:

- status `completed` (not `closed`)
- channels `["whatsapp"]`
- case status `awaiting_product_details`
- `refund.customer_notified` success=false
- `notification.dispatched` message **Skipped - Template not configured**

Also: refund id **1** (`REF-2026-000001`) is `completed` with notify fail, but `incident_id` is null — no open case impact.

Healthy baseline: **132** refunds in status `closed` vs **3** still `completed`.

---

## Events / notifications / activity

| Signal | Present? |
|--------|----------|
| `RefundCompleted` event | Dispatched (after DB commit) |
| Finance journal listener | Registered; separate from case close |
| Customer WhatsApp refund confirmation | Attempted → skipped (not configured) |
| Agent “completed” notification to requester | **Skipped** (same early return before `notifyRequesterOfDecision`) |
| System close remark | Absent |
| Queue jobs | None for this flow |

---

## Recommended minimal safe fix (do not implement here)

**Preferred code fix (minimal, production-safe):**

1. In `RefundRequestService::complete()`, always call `closeLinkedCase()` after payout completion, independent of `notifyCustomer` result.
2. Keep customer notification best-effort (log failure; do not block close).
3. Optionally still notify requester after close.
4. Treat all-channels-skipped / `not_yet_configured` as `null` (skip) inside `notifyCustomer` if notification remains gated anywhere else.

**Ops / config (optional complementary):**

- Enable and configure `INTERAKT_TEMPLATE_REFUND_CONFIRMATION_*` if WhatsApp refund confirmation is desired.
- Until code ships: manually close **SC23098** and **SC23667** via the refund close path (or clear refund hold + close case + mark refund `closed`) with audit notes.

**Data repair for the 2 stuck cases:** one-time admin action to run the close path (or invoke `RefundCaseCloseService::closeLinkedCase` under supervision) after confirming wallet payout is accepted.

**Do not:** mass-retry queue jobs (none exist); do not delete hold rows without audit; do not re-complete the refund.

---

## Files involved

| File | Role |
|------|------|
| `routes/web.php` | `POST /refunds/{refund}/complete` |
| `app/Http/Controllers/RefundRequestController.php` | `complete()` |
| `app/Services/RefundRequestService.php` | completion + notify gate + close call |
| `app/Services/RefundNotificationService.php` | `notifyCustomer()` |
| `app/Services/Notifications/NotificationDispatcher.php` | channel dispatch |
| `app/Data/NotificationDispatchResult.php` | aggregate success |
| `app/Data/NotificationResult.php` | skip / `countsTowardSuccess` |
| `app/Services/Notifications/Channels/WhatsAppChannel.php` | template-not-configured skip |
| `config/interakt.php` | `refund_confirmation` template flag |
| `app/Services/RefundCaseCloseService.php` | hold clear + case close + refund→closed |
| `app/Events/Finance/RefundCompleted.php` | finance only |
| `app/Listeners/Finance/PostRefundCompletedJournal.php` | finance only |
| `tests/Feature/RefundRequestTest.php` | documents current notify-failure keeps case open |

---

## Investigation method

Read-only production queries via SSH + `php artisan tinker` using `tools/config.sh`. No code changes. No Canvas. No production writes.
