# Phase 12.1 — Production Stabilization Sprint

Architecture notes, root causes, and verification checklist for P02-07-018.

## Issue 1 — Customer 360 tabs stop working

### Root cause

Tab click handlers were bound directly to tab button nodes inside `initTabs()` on every drawer content load. Those closures captured stale DOM references. After partial DOM updates (workbench refresh, timeline pagination, or full drawer reload), listeners were either lost or pointed at detached nodes. The first Overview interaction appeared to work because it was the server-rendered default; Timeline and IRA AI tabs failed on subsequent clicks until a full refresh recreated the bindings.

Additionally, `customer360:refresh` registered a new `document` listener on every `initCustomer360Drawer()` call without removing prior handlers. In the SPA and in tests this caused multiple concurrent `loadContent()` runs on refresh, racing aborts and resetting tab state to the server default (Overview).

### Fix

- Bind **one delegated click handler** on the persistent `[data-customer-360-content-host]` element.
- Resolve tabs and panes from the live DOM on each activation (scoped to `[data-customer-360-content]`).
- Persist the active tab via `data-customer-360-active-tab` on the content host so `customer360:refresh` preserves operator context.
- Abort prior `customer360:refresh` document listeners when re-initializing the drawer (prevents duplicate reloads).
- Reset tab state when the drawer closes or when a different incident is opened.

### Files modified

- `resources/js/customer-360-drawer.js` — delegated tabs, explicit tab persistence attribute, scoped tab queries, single refresh listener via `AbortController`, stale workbench root fix after refresh
- `tests/js/customer-360-drawer.test.js`

---

## Issue 2 — Request Serial notification failure (WhatsApp)

### Root cause

`WhatsAppTemplateDispatcher` called `OutboxProcessorService::process(limit: 1)`, which dequeues the **oldest** pending outbox event globally. Under load, an unrelated event could be processed while the new WhatsApp dispatch remained `Pending`. The dispatcher then hit the `default` match arm and threw `RuntimeException: WhatsApp template dispatch did not complete.`, surfaced to operators as an unexpected channel failure.

### Fix

- Added `OutboxProcessorService::processAggregate()` to process the outbox event for a specific aggregate (`whatsapp_template_dispatch` + dispatch id).
- Replaced the thrown exception for non-terminal statuses with structured, retryable failure results.
- Added error logging with dispatch/incident context; exceptions no longer abort later channels (email still runs).

### Files modified

- `app/Services/Outbox/OutboxProcessorService.php`
- `app/Services/Interakt/WhatsAppTemplateDispatcher.php`

---

## Issue 3 — Email not sending / partial success

### Root cause

Email delivery requires **both** `notifications.email.enabled` (system setting) and `MAIL_ENABLED` (mail config). Failures were logged minimally and operator messages were generic (`Unable to send email notification.`), making transport vs configuration issues hard to distinguish. Partial success (WhatsApp failed, email succeeded) was already supported by `NotificationDispatchResult::fromResults()` but the summary headline did not distinguish partial delivery.

### Fix

- Improved operator messages when mail is disabled or transport fails (includes underlying error when available).
- Added transport failure logging in `NotificationMailSender`.
- Summary headline now reads **Notification sent with warnings** when any enabled channel fails but at least one channel succeeds.

### Files modified

- `app/Services/Notifications/Channels/EmailChannel.php`
- `app/Services/Notifications/NotificationMailSender.php`
- `app/Services/Notifications/NotificationDeliverySummaryFormatter.php`
- `tests/Unit/Notifications/EmailChannelTest.php`
- `tests/Unit/Notifications/NotificationDeliverySummaryFormatterTest.php`

### Configuration checklist

| Setting | Purpose |
|---------|---------|
| `notifications.email.enabled` | System setting — includes Email channel in dispatcher |
| `MAIL_ENABLED` | Laravel mail transport gate |
| `MAIL_MAILER` | Actual transport (smtp, ses, log, etc.) |

---

## Issue 4 — Bulk Assign Service Reference timeout

### Root cause

`assignTransactionIdToIncidents()` called `assignTransactionId()` once per unique order. Each call:

- Ran its own DB transaction, audit, case closure, and notification fan-out.
- Broadcast `transactionAssigned` per incident, and each broadcast invoked `kpisUpdated()` (full user query + Reverb fan-out).

For ~21 orders this produced O(n²) broadcast work and exceeded browser HTTP timeouts.

### Performance measurements (local, SQLite, 21 incidents)

| Metric | Before fix (estimated) | After fix (measured) |
|--------|------------------------|----------------------|
| `DashboardBroadcastService::transactionAssigned()` calls | 21 (one per order) | 0 during loop |
| `DashboardBroadcastService::transactionsAssigned()` calls | 0 | 1 |
| `DashboardBroadcastService::kpisUpdated()` calls | 21 | 1 |
| Typical request duration | >30s (browser timeout) | Sub-second in feature tests |

### Fix

- Added optional `$broadcast` flag to `assignTransactionId()` (default `true` for single-order paths).
- Bulk path assigns with `broadcast: false`, then calls `DashboardBroadcastService::transactionsAssigned()` once.
- `transactionsAssigned()` now broadcasts row updates for all incidents, calls `kpisUpdated()` **once**, then SLA updates.

### Files modified

- `app/Services/OrderTransactionService.php`
- `app/Services/DashboardBroadcastService.php`

---

## Issue 5 — Live updates audit (documentation only)

### Pages using Reverb today

| Surface | Mechanism | Config / entry |
|---------|-----------|----------------|
| Dashboard service cases | Laravel Echo → Reverb private `dashboard.{userId}` | `DASHBOARD_LIVE_MODE=reverb\|auto`, `resources/js/live-dashboard-reverb.js` |
| Dashboard KPI strip | `.DashboardKpisUpdated` event | Same channel |
| Notification bell | Laravel Echo → Reverb private `notifications.{userId}` | `resources/js/live-notifications.js` (bell HTML via Reverb; poll supplements unread fetch) |

### Pages still polling

| Surface | Interval | File |
|---------|----------|------|
| Dashboard (fallback) | 30s default | `resources/js/live-dashboard.js` when `live_mode=poll` or Reverb disconnect in `auto` |
| Operations dashboard | 30s default | `resources/js/operations-dashboard.js` |
| Notification unread poll | 20s default | `resources/js/live-notifications.js` |

### Migration plan (not implemented in this sprint)

1. **Operations dashboard** — add Reverb channel (e.g. `operations.{userId}`) mirroring dashboard partial-update payloads; keep poll as `auto` fallback.
2. **Notification poll** — reduce poll to Reverb-only badge push; retain poll only for reconnect/backfill.
3. **Config** — extend `config/dashboard.php` live_mode pattern to operations (`OPERATIONS_LIVE_MODE`).
4. **Rollout** — ship behind `auto` mode in staging, compare Reverb vs poll latency, then default production to `reverb`.

---

## Tests executed

```bash
npm run test -- tests/js/customer-360-drawer.test.js
php artisan test --filter=NotificationDeliverySummaryFormatterTest
php artisan test --filter=EmailChannelTest
php artisan test --filter=WhatsAppTemplateDispatcherTest
php artisan test --filter=WorkspaceBatchTransactionActionTest
php artisan test --filter=OrderTransactionTest
php artisan test --filter=NotificationDispatchHardeningTest
```

## Manual verification checklist

- [ ] Open Customer 360 → switch Overview / Timeline / IRA AI repeatedly
- [ ] Refresh IRA AI workbench → tabs still switch
- [ ] Paginate timeline → tabs still switch
- [ ] Close and reopen drawer → tabs work; different incident resets to Overview
- [ ] Request Serial with WhatsApp + email enabled → partial failure shows warning toast, waiting state starts if any channel succeeds
- [ ] Request Serial with only email enabled → email sends, success toast
- [ ] Bulk assign 20+ service references → completes within HTTP timeout
- [ ] Dashboard live rows update via Reverb when `DASHBOARD_LIVE_MODE=reverb`

## Backward compatibility

- Single-order transaction assign behaviour unchanged (`broadcast` defaults to `true`).
- WhatsApp outbox retry semantics preserved; synchronous path now targets the correct aggregate.
- No API route or response schema changes.
- Polling remains default fallback when Reverb is unavailable.
