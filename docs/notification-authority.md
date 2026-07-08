# Notification Authority

This document describes the notification authority foundation introduced in P09-07-021. The authority layer is the single decision point that existing notification systems will migrate to over subsequent phases.

## Current mode: bridge only

The authority layer is **implemented but not wired** into most send paths yet. Existing behavior is unchanged:

- `IraCommunicationService` still owns live Telegram delivery.
- Laravel `database` notifications still power the in-app bell.
- `NotificationDispatcher` still handles customer-facing WhatsApp/email/desktop stubs.
- `TeamTelegramQuietRulesService` still gates team briefings independently.

The only safe delegation added in this phase is recipient resolution inside `IraCommunicationService`, which now calls `NotificationRecipientResolver` for owner and operational recipient queries. The underlying queries are identical.

## Components

### Enums

| Enum | Purpose |
|------|---------|
| `NotificationCategory` | What kind of alert: IVR, leave, finance, assignment, escalation, daily summary, system health |
| `NotificationChannelType` | How to deliver: telegram, desktop, email, whatsapp, in_app |
| `NotificationScheduleMode` | When to deliver: always, work_hours, extended_hours, custom |

### `NotificationAuthorityService`

Central gate with four public decision methods:

| Method | Responsibility |
|--------|----------------|
| `shouldDeliver()` | Final allow/deny for user + category + channel + time |
| `categoryEnabled()` | Org-level toggles from `settings` table |
| `channelEnabled()` | Platform channel toggles from `system_settings` |
| `userAllows()` | Per-user channel readiness (today: Telegram chat ID + boolean) |
| `scheduleAllows()` | Role-derived schedule windows via `WorkCalendarService` |

### `NotificationRecipientResolver`

Centralizes hardcoded recipient role maps:

| Method | Maps to |
|--------|---------|
| `ownerRecipients()` | Active superadmins |
| `operationalRecipients()` | Active operations admins + admins |
| `assignmentRecipient()` | Explicit assignee by user ID |
| `recipientsFor()` | Category → recipient set (bridge helper) |

## Category mapping

| Category | Existing sources today | Org toggle (`settings`) |
|----------|------------------------|-------------------------|
| `assignment` | Service case assigned/reassigned, Ira smart/manual assignment | `notifications.assignment_enabled` |
| `finance` | Transaction completed notifications | `notifications.transaction_enabled` |
| `escalation` | High-priority service case notifications, Ira risk alerts | `notifications.high_priority_enabled` |
| `ivr` | Bonvoice live call assist (in-app bell) | none (defaults open) |
| `leave_approvals` | not implemented yet | none (defaults open) |
| `daily_summary` | Ira daily/team briefings, future IVR day-end report | none (defaults open) |
| `system_health` | Ira integration failure, unusual backlog | none (defaults open) |

## Channel mapping

| Channel | Platform gate (`system_settings`) | User gate today |
|---------|-----------------------------------|-----------------|
| `in_app` | always enabled | always allowed |
| `telegram` | `notifications.telegram.enabled` | `telegram_notifications_enabled` + `telegram_chat_id` |
| `email` | `notifications.email.enabled` | user has email |
| `whatsapp` | `notifications.whatsapp.enabled` | always allowed |
| `desktop` | `notifications.desktop.enabled` | always allowed (browser permission not wired) |

## Schedule defaults (role-derived, no DB yet)

Until preference tables exist, schedule mode is inferred from role:

| Role | Default schedule |
|------|------------------|
| Superadmin | `always` |
| Operations admin / admin | `extended_hours` |
| Agents and support roles | `work_hours` |

Schedule evaluation uses `WorkCalendarService::todayStatusFor()`:

- **work_hours** — only `working`
- **extended_hours** — blocks leave, holiday, weekly off, outside hours; allows working, starts later, lunch, no schedule
- **always** — no calendar block
- **custom** — falls back to work hours until preference storage exists

## Future preference table plan (Phase 2+)

Planned tables (not created yet):

### `user_notification_preferences`

```
user_id, category, channel, enabled, schedule_mode, schedule_config, managed_by, updated_by
```

### `notification_role_defaults`

Seed matrix per role for admin-managed defaults applied on user create/role change.

### Migration path

1. Seed role defaults matching current inferred behavior.
2. Backfill from `users.telegram_notifications_enabled` into preference rows.
3. Replace `defaultScheduleModeFor()` with stored preferences.
4. Add admin UI (checkbox matrix) and profile self-service subset.
5. Audit preference changes via `audit_logs`.

## Wiring order (post-foundation)

Do not wire these until Phase 2+:

1. Leave approval Telegram + in-app alerts
2. IVR day-end Telegram summary
3. Browser desktop permission flow
4. Replacing `TeamTelegramQuietRulesService` with authority schedule checks

Recommended wiring sequence:

1. `ServiceCaseAssignmentService` → authority before `->notify()` and Ira Telegram
2. `IraCommunicationService::dispatch()` → authority before send
3. `BonvoiceLiveCallAssistService` → authority for IVR category
4. `NotificationPollController` / `live-notifications.js` → desktop preference + permission
5. New leave + IVR summary commands

## Files

```
app/Enums/NotificationCategory.php
app/Enums/NotificationScheduleMode.php
app/Enums/NotificationChannelType.php          (extended with in_app)
app/Services/Notifications/NotificationAuthorityService.php
app/Services/Notifications/NotificationRecipientResolver.php
tests/Unit/Notifications/NotificationAuthorityServiceTest.php
```
