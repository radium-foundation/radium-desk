# AW- order daily Telegram summary (operations)

## Scope

Command: `operations:send-aw-order-daily-telegram`

Sends a **count-only** summary of orders whose Service Reference (`orders.transaction_id`) begins with `AW-`, for the **previous IST calendar day**, to **superadmin** Telegram destinations only.

Feature flag: `AW_ORDER_DAILY_TELEGRAM_ENABLED` (default **false**).

## Delivery semantics (Owner policy)

Delivery is **at-least-once**, **not** exactly-once.

| Outcome | Durable receipt | Next run |
|---------|-----------------|----------|
| Definite Telegram API failure (`ok: false`, validation error, disabled channel) | **No** | Retry may send again |
| Lock not acquired immediately (concurrent delivery for same user/date) | **No** | Retry may send after the other run completes (no in-process spin/wait) |
| HTTP timeout or transport exception before confirmation | **No** | Retry may send again; Telegram may have accepted the message → **possible duplicate** |
| Telegram HTTP success but receipt row insert fails | **No** | Retry may send again → **possible duplicate** |
| Telegram HTTP success and receipt row persisted | **Yes** | Routine repeat suppressed for that user and report date |

Telegram `sendMessage` does **not** support an application-level idempotency key. Suppression relies on the durable `operational_telegram_delivery_receipts` unique constraint `(delivery_kind, report_date, user_id)` plus a per-recipient cache lock during send.

## Logging

Operational logs include `report_date` and `user_id` only. Do not log bot tokens, chat IDs, or message bodies in production log aggregation.

## Schedule

`08:00` in `Asia/Kolkata` via `config('app.schedule_timezone')`. Scheduler overlap protection does not replace receipt idempotency.

## Superadmin recipients

Resolved only via `NotificationRecipientResolver::ownerRecipients()` with a live `superadmin` role check and Telegram readiness through `NotificationAuthorityService` (Ira bridge). No team broadcast or caller-supplied destinations.