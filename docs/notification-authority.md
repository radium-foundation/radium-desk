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
| `daily_summary` | Ira daily/team briefings, planned Daily Operations Summary | none (defaults open) |
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

## Daily Operations Summary (planned)

The `daily_summary` category will deliver a single **Daily Operations Summary** — not a separate IVR-only day-end Telegram report. IVR metrics are one section inside a broader operational digest.

This is architecture documentation only. The following are **not implemented yet**:

- command
- formatter
- scheduler
- database tables
- UI

### Delivery authority

All Daily Operations Summary sends must pass through `NotificationAuthorityService::shouldDeliver()` before dispatch:

1. `categoryEnabled(NotificationCategory::DailySummary)`
2. `channelEnabled()` for the chosen channel (typically `telegram` or `in_app`)
3. `userAllows()` for the recipient
4. `scheduleAllows()` for the recipient at the scheduled send time

Recipient resolution uses `NotificationRecipientResolver` (owners, operational users, or explicit assignees depending on rollout scope).

### Command (planned)

```
operations:send-daily-summary
```

Scheduled by role cohort:

| Recipient cohort | Send time | Purpose |
|------------------|-----------|---------|
| Owner / superadmin | **00:00** (midnight) | Full-day close summary for platform owners |
| Shipra / configured operations users | **00:00** (midnight) | Operations leadership end-of-day digest |
| Normal team users | **18:30** | End-of-shift summary for agents and support staff |

The scheduler evaluates each recipient individually via `NotificationAuthorityService` at the configured time. Users outside their allowed schedule window are skipped even if the command runs.

### Formatter (planned)

```
DailyOperationsSummaryFormatter
```

Produces a single plain-text (Telegram) or structured (in-app) message with these sections:

| Section | Content |
|---------|---------|
| Service case summary | Open/closed counts, queue breakdown, notable cases |
| Assignment performance | Assignments, reassignments, smart assignment outcomes |
| SLA / risk | Overdue, at-risk, and high-priority exposure |
| IVR calls and agent performance | Call volume, answered/missed rates, per-agent stats (from Bonvoice analytics) |
| Missing serial automation | Pending/completed missing-serial automation status |
| Hardware routing | Hardware team routing and backlog signals |
| Team attendance / on-duty | Present, away, offline, and on-duty workforce snapshot |
| Leave requests and upcoming leaves | Pending approvals, approved leave today, upcoming leave windows |
| Finance income/expense summary | Transaction and refund activity for the period |
| IRA recommendations | IRA operational recommendations and risk highlights |

Existing Ira briefing formatters (`IraBriefingFormatter`, `TeamWorkBriefingFormatter`) may be reused or composed inside `DailyOperationsSummaryFormatter` rather than replaced outright during migration.

### Relationship to current briefings

Today, Ira sends separate daily briefing and team daily briefing Telegram messages. The Daily Operations Summary is the **target unified digest** under `daily_summary`. Migration plan:

1. Implement `DailyOperationsSummaryFormatter` and `operations:send-daily-summary`.
2. Gate delivery through `NotificationAuthorityService`.
3. Run new command alongside existing Ira briefing commands during transition.
4. Deprecate standalone Ira daily/team briefing commands once parity is verified.

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
2. Daily Operations Summary command and formatter
3. Browser desktop permission flow
4. Replacing `TeamTelegramQuietRulesService` with authority schedule checks

Recommended wiring sequence:

1. `ServiceCaseAssignmentService` → authority before `->notify()` and Ira Telegram
2. `IraCommunicationService::dispatch()` → authority before send
3. `BonvoiceLiveCallAssistService` → authority for IVR category
4. `NotificationPollController` / `live-notifications.js` → desktop preference + permission
5. `operations:send-daily-summary` → `DailyOperationsSummaryFormatter` + `NotificationAuthorityService`
6. New leave approval notification events

## Files

```
app/Enums/NotificationCategory.php
app/Enums/NotificationScheduleMode.php
app/Enums/NotificationChannelType.php          (extended with in_app)
app/Services/Notifications/NotificationAuthorityService.php
app/Services/Notifications/NotificationRecipientResolver.php
tests/Unit/Notifications/NotificationAuthorityServiceTest.php
```
