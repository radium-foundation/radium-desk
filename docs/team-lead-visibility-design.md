# Team Lead Visibility Design

**Status:** Design only — not implemented in P09-07-050.

This document prepares a future team-lead layer for users like Jayram without introducing hierarchy tables or assignment changes in the foundation phase.

## Problem

Agents see their own work after ownership visibility fixes, but there is no **team command** layer:

- No view of teammate workload or escalations
- No morning command digest for a lead
- No team-scoped queues on the main dashboard

Operations leadership (e.g. Shipra) has the Ops Control Center; agents do not.

## Proposed role: `team_lead`

| Aspect | Proposal |
|--------|------------|
| Slug | `team_lead` |
| Distinct from | `admin`, `operations_admin`, `agent` |
| Intent | Day-to-day command over a support pod, not platform administration |

### Permissions (draft)

| Permission | Purpose |
|------------|---------|
| `team.cases.view` | Read incidents assigned to team members |
| `team.workload.view` | Team workload cards / overload signals |
| `team.escalations.receive` | Telegram + in-app escalation CC for member cases |
| `team.performance.view` | Read-only team performance (subset of ops) |

**Explicitly not granted:** `operations-dashboard.view`, `users.manage`, `system-settings.manage`.

## Team membership model

Recommend an explicit membership table (future migration):

```
teams
  id, name, lead_user_id, is_active

team_members
  id, team_id, user_id, joined_at
```

**Why not `reports_to_user_id` alone:**

- One lead may cover multiple agents; agents should not inherit admin visibility
- Membership supports reassignment without org-chart coupling
- Escalation rules can target `team.lead_user_id` or all members of `team_id`

Alternative considered: `users.reports_to_user_id` — rejected for Phase 2+ because it does not model pods cleanly and blurs HR vs operational grouping.

## Visibility surfaces (future)

### Dashboard

- New view or filter: **Team Board** (scoped `action_required`, `waiting_customer`, `attention` for member user IDs)
- Personal **My Work** unchanged for agents who are also leads

### Telegram

- **Team command message** (~08:00): own load + team pressure + overnight escalations + missed appointments for members
- Distinct from agent `team_daily_briefing` (personal counts) and Shipra `ops_digest` (org-wide)

### Escalation CC rules (future)

| Trigger | Notify lead when |
|---------|------------------|
| Member `action_required` stale > 2h | Case assigned to team member |
| High-priority flag on member case | Member is assignee |
| Missed appointment | Member is assignee |

## Relationship to existing roles

| User | Today | With team lead |
|------|-------|----------------|
| Jayram (`agent`) | Own work only | Optional `team_lead` + membership → team board |
| Shipra (`admin` + `customer_coordinator`) | Full ops dashboard + ops digest | Unchanged |
| Agent on same team | Own work | Unchanged unless also promoted to lead |

## Migration path (later phases)

1. Add `team_lead` role and permissions to `RolePermissionSeeder`
2. Add `teams` / `team_members` tables + admin UI
3. Scope dashboard queries by `team_member` user IDs
4. Add `team-telegram:send-team-command` formatter
5. Wire escalation CC through `NotificationRecipientResolver`

## Non-goals

- Changing assignment logic or round-robin rules
- Replacing Ops Control Center for operations admins
- Auto-creating team membership from role alone
