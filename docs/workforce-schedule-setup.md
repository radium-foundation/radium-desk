# Workforce Schedule Setup

Work schedules in Radium Desk are **opt-in**. Saving a schedule creates a row in `team_member_work_schedules`. Form defaults on the user edit page are for editing only until an admin clicks **Save Work Schedule**.

## Why schedules matter

| Feature | Requires saved schedule |
|---------|-------------------------|
| Team morning Telegram briefing (`team-telegram:send-daily-briefings`) | Yes — status must be `starts_later` within the pre-work window |
| Work calendar status (`no_schedule` vs `working`) | Yes |
| Late-login detection | Yes |
| Smart assignment eligibility when outside hours | Uses schedule when present |

Assignment eligibility treats **no schedule** as not calendar-blocked. Telegram briefings do **not** — they skip users without a saved schedule.

## Admin setup flow

1. Sign in as admin, operations admin, or superadmin (`workforce-calendar.manage`).
2. Open **Users** → edit a team member (agent, support specialist, customer coordinator, hardware team).
3. Review the **Work Schedule** card.
4. If the warning banner appears, the schedule is not saved yet.
5. Click **Save Work Schedule** to persist defaults or adjusted times.

There is no bulk schedule UI. Each support user needs an explicit save after migration or onboarding.

## Post-migration bootstrap (production)

After deploying workforce calendar tables, existing users will have **zero** schedule rows. This is expected.

**Do not** auto-seed or fake schedules in code. Admins should:

1. List active support-queue users (agents, support specialists, customer coordinators).
2. Open each user edit page.
3. Save work schedule once (defaults are acceptable).
4. Adjust times per person if needed.

Until this is done, team morning briefings will log `0 message(s) delivered` even though the scheduler runs.

## Briefing window

Configured in [`config/team_telegram.php`](../config/team_telegram.php):

- Scheduler runs every 15 minutes.
- Briefing sends only when calendar status is `starts_later`.
- Window: from `(work_start - minutes_before_work_start)` until `work_start` (default 60 minutes before).

## Related files

- Model: `app/Models/TeamMemberWorkSchedule.php`
- Service: `app/Services/Operations/TeamWorkScheduleService.php`
- Controller: `app/Http/Controllers/TeamWorkScheduleController.php`
- Quiet rules: `app/Services/Operations/TeamTelegramQuietRulesService.php`
