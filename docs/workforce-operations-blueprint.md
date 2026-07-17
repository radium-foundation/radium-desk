# Workforce Operations — Phase 1 Blueprint

**Version:** 1.0  
**Status:** Architecture & UX blueprint (no implementation)  
**Constitution alignment:** [Radium Constitution](radium-constitution.md) — Phase 2  
**Platform foundation:** Platform Engine Review P17-07-001  
**Audience:** Product, engineering, operations leadership  
**Last updated:** 2026-07-17

This document describes the complete **Workforce Operations** module from a product perspective. It is the authoritative blueprint for Phase 1 delivery. Implementation may proceed sprint-by-sprint within this architecture without revisiting foundational decisions.

**Constraints (non-negotiable):**

- Reuse existing Platform Engines — no parallel Timeline, Notification, Search, or Audit systems
- Business rules belong to **Workforce Operations** domain services
- Platform capabilities belong to **Platform Engines**
- Follow the [Radium Constitution](radium-constitution.md) Development Standards and Product Test

**Related existing foundations:**

| Area | Current state |
|------|----------------|
| Schedules | `TeamMemberWorkSchedule`, opt-in save flow — see [workforce-schedule-setup.md](workforce-schedule-setup.md) |
| Leave | `LeaveRequest` workflow with audit + notifications |
| Presence / attendance | `PresenceEngineService`, `WorkSession`, activity tracking |
| Eligibility gate | `WorkforceAuthorityService` (calendar + leave + presence + availability) |
| Team visibility | `TeamAvailabilityOverviewService`, operations dashboard rows |
| Performance | `TeamPerformanceMetricsService`, admin performance index |
| Holidays | `CompanyHoliday` admin UI |

Phase 1 **unifies and productizes** these foundations into **Workforce360** — it does not replace them.

---

## 1. Vision

### The problem in one sentence

Business owners and operations leads should know **who is available, who is absent, and whether the team can absorb more work** — without asking individuals or opening five admin screens.

### Workforce Operations vision

**Workforce Operations is the operational truth layer for people capacity.**

It answers:

| Question | Who asks |
|----------|----------|
| Who is on duty right now? | Owner, ops lead, assignment engine |
| Who is late, on leave, or unexpectedly away? | Ops lead |
| Can we take more customer work today? | Owner (60-second mobile test) |
| Why was this person not assigned? | Ops lead, agent (explainable) |
| How has this person contributed operationally? | Ops lead (coaching, not surveillance) |

Workforce Operations is **not HRMS**. It does not manage payroll, compensation, recruitment, or statutory HR compliance. It exists so **customer work flows to the right person at the right time** — and owners see workforce health alongside customer health.

### Constitution alignment

| Principle | Workforce expression |
|-----------|---------------------|
| **360 before CRUD** | Workforce360 is the home surface; forms serve the 360 |
| **Explain every score** | Workforce Score decomposes into plain-language factors |
| **Reuse engines** | All writes project through Audit; history through Timeline; alerts through Notification Authority |
| **Mobile First (60s)** | Owner landing shows team health + exceptions in one glance |
| **AI assists, humans decide** | Briefings recommend; leave approval and assignment overrides stay human |

---

## 2. Business Goals

### Primary goals (Phase 1)

1. **Owner visibility** — One mobile-friendly view of team availability, attendance exceptions, and capacity posture
2. **Operational capacity truth** — Assignment and scheduling decisions use one eligibility model (`WorkforceAuthorityService` extended, not forked)
3. **Self-service workforce hygiene** — Agents manage availability and leave; admins manage schedules and holidays without spreadsheets
4. **Accountability without overhead** — Attendance and leave events are audit-backed and timeline-visible automatically
5. **Foundation for Work360** — Workforce capacity links cleanly to who owns customer work

### Success signals

| Signal | Target direction |
|--------|------------------|
| Owner time to answer “can we handle more work?” | Under 60 seconds on mobile |
| Manual “who is working?” messages | Decrease week over week |
| Assignment to unavailable agents | Near zero (blocked with explainable reason) |
| Schedule coverage for assignment-tracked roles | Measurable % with saved schedules |
| Leave approval cycle time | Visible and improving |

### Non-success (avoid)

- Building a second team dashboard disconnected from assignment
- Opaque productivity rankings that erode trust
- Feature sprawl that duplicates operations dashboard without owner narrative

---

## 3. Scope

### In scope — Phase 1

| Capability | Description |
|------------|-------------|
| **Workforce360 (team)** | Operations lead / owner command view for live team health |
| **Workforce360 (individual)** | Per-person operational picture: schedule, presence, leave, workload, timeline |
| **Responsibilities model** | Config-driven operational responsibilities (not org departments) |
| **Skills model (v1)** | Lightweight skill tags for routing hints — not certification management |
| **Attendance visibility** | Session-based attendance derived from existing presence engine |
| **Leave operations** | Extend existing leave workflow with Workforce360 surfaces |
| **Workforce Score (v1)** | Explainable composite for individual and team posture |
| **Engine integrations** | Timeline, Audit, Notification, Search, AI, Automation hooks |
| **Policy requirements** | Document policy decisions Phase 1 needs (implementation may trail) |

### Phase 1 delivery slices (sprint-safe)

Sprints should ship vertical slices that respect engine boundaries:

| Slice | Outcome |
|-------|---------|
| **S1 — Workforce360 shell** | Team + individual layouts, health hero, navigation, permissions |
| **S2 — Live availability** | Real-time on-duty / unavailable with explainable block reasons |
| **S3 — Schedule & calendar** | Workforce360 schedule facet; admin schedule management UX unified |
| **S4 — Leave in 360** | Leave requests, approvals, and calendar impact in Workforce360 |
| **S5 — Attendance & timeline** | Session history, attendance exceptions, workforce timeline sources |
| **S6 — Responsibilities & skills** | Config model + UI for assignment routing inputs |
| **S7 — Workforce Score** | Explainable score on individual and team 360 |
| **S8 — Search & AI** | Workforce search provider + ops briefing workforce context |
| **S9 — Automations** | Late login, leave reminders, capacity alerts via Automation Engine |

Slices may merge or reorder; architecture does not.

---

## 4. Non-goals

Phase 1 explicitly does **not** include:

| Non-goal | Reason |
|----------|--------|
| Payroll, salary, or compensation | Constitution: not HRMS |
| Full HR lifecycle (hiring, PIP, promotions) | Out of operational scope |
| Biometric / geo attendance hardware | Presence is application-session based |
| Shift marketplace or shift bidding | Complexity without Phase 1 customer benefit |
| Surveillance-style screen monitoring | Violates coaching-oriented performance philosophy |
| Parallel notification stack for workforce | Use Notification Authority |
| Separate workforce audit table | Use Audit Engine |
| Custom timeline UI for workforce | Use unified Timeline Engine |
| Department hierarchy / org chart | Use Responsibilities instead |
| Skill certification exams or LMS | Skills are routing hints only in v1 |
| Workforce-owned customer communication | Routes through Communication Actions / Notification |

---

## 5. Business Objects

Workforce Operations owns projections on the **Workforce360** business object (constitution Part 3). Domain tables may exist, but the **object** is what users experience.

### Workforce360 — Team (aggregate)

| Facet | Phase 1 content |
|-------|----------------|
| **Identity** | “Operations Team” or configurable team name |
| **Health** | Team Workforce Score + exception counts |
| **Capacity** | On duty / expected on shift / on leave / unavailable counts |
| **Timeline** | Team-level events (holidays, notable absences, capacity alerts) |
| **Relationships** | Links to Operations360, open Work360 load |
| **Actions** | Approve leave, view member, broadcast ops note (future) |

### Workforce360 — Individual (User as workforce member)

| Facet | Phase 1 content |
|-------|----------------|
| **Identity** | Name, role label, responsibilities, skills |
| **Health** | Individual Workforce Score + today’s status chip |
| **Schedule** | Weekly pattern, today’s window, holiday/leave overlay |
| **Presence** | Current session, availability (Available / Busy / Offline), last activity |
| **Workload** | Open case count, appointment load (read from Work360) |
| **Timeline** | Schedule changes, leave, attendance exceptions, assignment events |
| **Relationships** | Owned Work360 items, linked Customer360 via work |
| **Actions** | Request leave, set availability (where permitted), view performance period |

### Supporting domain entities (not 360 surfaces)

| Entity | Role |
|--------|------|
| `TeamMemberWorkSchedule` | Source of truth for expected work pattern |
| `WorkSession` | Source of truth for attendance sessions |
| `LeaveRequest` | Source of truth for leave workflow state |
| `CompanyHoliday` | Company-wide non-working days |
| **Responsibility assignment** (new) | Maps user → operational responsibilities |
| **Skill assignment** (new) | Maps user → skill tags |

---

## 6. User Roles

Roles define **permissions and default surfaces** — not the operational routing model (see Responsibilities).

| Role | Workforce360 access | Primary actions |
|------|---------------------|-----------------|
| **Owner / Super Admin** | Full team + all individuals | 60-second health view, approve leave, configure holidays |
| **Admin** | Full team + all individuals | Schedule management, leave approval, team oversight |
| **Operations Admin** | Full team + all individuals | Same operational scope as Admin for workforce |
| **Support Agent** | Self Workforce360 + team summary (read-only) | Request leave, set availability, view own attendance |
| **Support Specialist** | Same as agent | Same |
| **Customer Coordinator** | Same as agent | Same |
| **Escalation Specialist** | Same as agent + escalation workload visible | Same |
| **Hardware Team** | Self + limited team summary | Schedule/leave; hardware routing responsibility |

### Permission themes (product language)

| Permission theme | Capabilities |
|------------------|--------------|
| `workforce.view` | See team Workforce360 |
| `workforce.view.member` | See any individual Workforce360 |
| `workforce.manage.schedule` | Edit schedules, holidays |
| `workforce.review.leave` | Approve/reject leave |
| `workforce.view.performance` | See performance metrics (existing admin performance) |
| `workforce.self` | Own 360, leave, availability |

Exact permission keys are an implementation detail; roles above map to existing `RolePermissionSeeder` patterns.

---

## 7. Responsibilities instead of Departments

### Philosophy

Radium Desk routes **work**, not **org charts**.  
“Departments” (Sales, Support, Hardware) create static boxes. **Responsibilities** describe what operational work a person can receive **right now**.

A person may hold multiple responsibilities. Responsibilities change without restructuring a department tree.

### Responsibility definition

A **Responsibility** is a config-declared operational capability:

| Field | Example |
|-------|---------|
| `key` | `normal_support_pool` |
| `label` | Normal Support Queue |
| `description` | Standard service case assignment pool |
| `assignment_pool` | true / false |
| `routing_hints` | queue keys, order prefixes |
| `default_roles` | roles auto-granted on onboarding |

### Phase 1 responsibilities (initial set)

| Key | Purpose | Maps from today |
|-----|---------|-----------------|
| `normal_support_pool` | Round-robin / smart assignment | `SUPPORT_TEAM_ROLES` |
| `escalation_ownership` | Escalation cases | Escalation specialist role |
| `hardware_routing` | RDE hardware orders | Hardware team + prefix rule |
| `appointment_support` | Support appointment assignment | Appointment smart assignment |
| `operations_oversight` | Receives ops alerts, leave approvals | Admin / operations admin |

### Assignment integration

```
Assignment request
    → Domain rule (service case type, order prefix, escalation state)
    → Filter candidates by Responsibility
    → Filter by WorkforceAuthorityService (calendar, leave, presence)
    → SmartAssignmentService scoring
    → applyAssignment()
```

**Rule:** Responsibilities live in Workforce domain config + user assignments. The Assignment Engine executes selection; it does not embed responsibility business rules inline.

### UX

- Individual Workforce360 shows **Responsibilities** as chips (not “Department: Support”)
- Team Workforce360 may filter by responsibility
- Admin assigns responsibilities on user edit (alongside role)

---

## 8. Skills Model

### Philosophy

Skills are **routing hints**, not HR qualifications. Phase 1 optimizes for “who can handle this type of work?” — not certification tracking.

### Skill definition

| Field | Example |
|-------|---------|
| `key` | `whatsapp_flows` |
| `label` | WhatsApp Flows |
| `category` | `channel`, `product`, `language` |
| `description` | Can handle Meta WhatsApp Flow escalations |

### Phase 1 skill categories

| Category | Examples |
|----------|----------|
| **Language** | `hindi`, `english` |
| **Channel** | `whatsapp`, `phone`, `email` |
| **Product** | `rd_service`, `hardware_rde` |
| **Process** | `refunds`, `serial_correction` |

### Skill assignment

- Admin assigns skills to users (multi-select)
- Self-declared skills are **not** allowed in Phase 1 (trust model)
- Skills appear on Individual Workforce360

### Assignment integration (v1 — soft)

Skills **influence** `SmartAssignmentService` scoring as tie-breakers — they do not hard-block assignment unless paired with an explicit policy (future Policy Engine).

| Priority | Factor |
|----------|--------|
| 1 | Workforce eligibility (hard gate) |
| 2 | Responsibility match (hard gate for specialized queues) |
| 3 | Workload / availability score |
| 4 | Skill match (soft boost) |

### Non-goals

- Skill exams, expiry dates, or compliance tracking
- Skill-based pay

---

## 9. Attendance Model

### Source of truth

**Attendance is derived from `WorkSession` records** produced by `PresenceEngineService` — not a separate clock-in spreadsheet.

### Session lifecycle (existing, productized)

```
Login / first activity
    → startSession()
    → WorkSession (open)
Activity ticks / away detection
    → recordActivity() / tickSession()
Logout / away timeout
    → closeSession(reason)
    → WorkSession (closed, durations computed)
```

### Attendance states (per day, per person)

| State | Meaning | Owner-visible label |
|-------|---------|---------------------|
| `scheduled_off` | Weekly off or holiday | Off day |
| `on_leave` | Approved leave | On leave |
| `not_started` | Shift started, no session | Not logged in |
| `on_time` | Session started within grace | On time |
| `late` | Session started after grace | Late |
| `active` | In session, not away | Working |
| `away` | Session open, away timeout pending or active | Away |
| `completed` | Session closed normally | Day complete |
| `extra` | Session on non-scheduled day | Extra working |

### Grace and expectations

| Setting | Source | Default behavior |
|---------|--------|------------------|
| Work window | `TeamMemberWorkSchedule` | Per-user start/end, lunch, breaks |
| Late grace | config `workforce_calendar.late_grace_minutes` (new) | Product default: 10 minutes |
| Away timeout | `presence.away_timeout_minutes` | Existing |
| No schedule | — | Attendance tracked; assignment not calendar-blocked |

### Attendance exceptions (timeline-worthy)

- Late login
- Away timeout during shift
- Extra working day (session on off day)
- Manual logout during core hours
- Session without schedule (informational)

### UX principles

- Show **today first**, period history second
- Never reduce a person to a single “productivity number” on the attendance card
- Attendance card links to explainable day breakdown (existing `TeamPerformanceMetricsService` pattern)

---

## 10. Leave Model

### Source of truth

`LeaveRequest` with statuses: **Pending → Approved | Rejected**.

### Workflow (extend existing)

```
Agent submits leave (reason, date range)
    → Audit: leave.submitted
    → Notify approvers (Notification Authority)
Approver approves/rejects (review notes required)
    → Audit: leave.approved | leave.rejected
    → Notify requester
    → WorkCalendarService reflects approved leave
    → WorkforceAuthority blocks assignment for date range
```

### Leave types (Phase 1)

Phase 1 uses a **single leave type** (operational leave). Category expansion (sick, casual) is Phase 2+.

### Business rules (domain)

| Rule | Behavior |
|------|----------|
| Overlapping pending requests | Reject with validation message |
| Retroactive leave | Allowed only within configurable window (default: 2 days) |
| Approval required | Operations admin, admin, or superadmin |
| Review notes | Required on approve and reject |
| Partial day leave | Out of scope Phase 1 (full days only) |

### Workforce360 presentation

| Surface | Content |
|---------|---------|
| Individual 360 | Current/upcoming leave, pending request status, submit action |
| Team 360 | On leave today, pending approvals count, approval queue |
| Timeline | Submitted, approved, rejected events |
| Owner mobile | “3 on leave · 2 approvals pending” in health strip |

### Calendar interaction

Approved leave overrides schedule for assignment eligibility. Leave does not delete schedule — both coexist with leave taking precedence.

---

## 11. Workforce Score

### Philosophy

Constitution: **Explain every score.**  
Workforce Score is a **posture indicator**, not a hidden HR rating.

### Two scores

| Score | Scope | Question answered |
|-------|-------|-------------------|
| **Team Workforce Score** | Team 360 | Is the team structurally ready for work today? |
| **Individual Workforce Score** | Person 360 | Is this person operationally available and effective today? |

### Team Workforce Score (v1) — components

| Component | Weight | Source |
|-----------|--------|--------|
| Coverage | 40% | % of expected-on-shift members who are on duty |
| Leave impact | 20% | On-leave vs planned capacity |
| Attendance exceptions | 20% | Late + away timeouts vs on-shift headcount |
| Workload balance | 20% | Gini or max/min open case spread across on-duty pool |

**Output:** 0–100 with band: **Healthy** (80+), **Watch** (60–79), **At Risk** (&lt;60).

**Explanation panel (required):**

> “Team score 72 (Watch). Coverage 85%. 2 members late. 1 away timeout. Workload uneven: Jayram 18 open, team median 9.”

### Individual Workforce Score (v1) — components

| Component | Weight | Source |
|-----------|--------|--------|
| Availability truth | 35% | Matches schedule + presence + leave |
| Attendance quality | 25% | On-time login, away time within allowance |
| Workload sustainability | 25% | Open cases vs team median |
| Reliability signals | 15% | SLA compliance on owned work (period) |

**Today vs period:** Individual 360 hero shows **today’s operational posture**; period score lives under Performance tab.

### Score must never

- Be the sole input to compensation
- Hide component breakdown
- Update without user-visible data behind it

---

## 12. Workforce360 Layout

Workforce360 follows constitution **360 before CRUD** and the [Customer360 workspace design language](customer360-workspace-modal-design-system.md) — operator-first, mobile-collapsible.

### Entry points

| Entry | User |
|-------|------|
| `/workforce` or Operations nav → **Team** | Owner, ops lead |
| `/workforce/{user}` | Individual view (permission-gated) |
| `/my-workforce` | Self view (agent) |
| Operations dashboard → member row → Workforce360 | Ops lead |
| Global search → person | Anyone with permission |

### Team Workforce360 — layout

```
┌─────────────────────────────────────────────────────────┐
│  Team Workforce                          [Today ▼]      │
├─────────────────────────────────────────────────────────┤
│  HERO: Team Workforce Score 72 (Watch)                  │
│  On duty 6 · On shift 8 · On leave 2 · Pending leave 1  │
│  [Explain score]                                        │
├─────────────────────────────────────────────────────────┤
│  CAPACITY STRIP (mobile: 2x2 grid)                      │
│  [On Duty] [Unavailable] [On Leave] [Exceptions]        │
├─────────────────────────────────────────────────────────┤
│  MEMBER LIST (filter: responsibility · availability)    │
│  Name · Status chip · Open cases · Score · →            │
├─────────────────────────────────────────────────────────┤
│  TABS: Overview | Timeline | Leave Queue | Holidays     │
└─────────────────────────────────────────────────────────┘
```

**60-second owner story:** Hero score + four capacity numbers + exception row. No scrolling required on mobile.

### Individual Workforce360 — layout

```
┌─────────────────────────────────────────────────────────┐
│  ← Team    Jayram                    [Available ▼]      │
├─────────────────────────────────────────────────────────┤
│  HERO: Today — On duty · On time · 12 open cases        │
│  Individual score 81 (Healthy) [Explain]                │
├─────────────────────────────────────────────────────────┤
│  CONTEXT STRIP                                          │
│  Agent · normal_support_pool · Skills: hindi, refunds   │
├─────────────────────────────────────────────────────────┤
│  TABS:                                                  │
│  Overview | Schedule | Attendance | Leave | Workload    │
│           | Timeline | Performance                      │
├─────────────────────────────────────────────────────────┤
│  (tab content)                                          │
└─────────────────────────────────────────────────────────┘
```

### Overview tab (individual)

| Block | Content |
|-------|---------|
| Today schedule | Window, lunch, break allowance |
| Presence | Session start, active/away, expected end |
| Block reasons | If not assignable — enumerated from `blockReasons()` |
| Quick actions | Request leave, link to My Performance |

### Workload tab

Read-only projection from Work360: open cases, appointments today, recent resolutions — links into existing workspaces.

### Workspace actions (Phase 1)

Use **Workspace Engine** for modals:

| Action | Modal |
|--------|-------|
| Request leave | Compact form (dates, reason) |
| Approve/reject leave | Review notes required |
| Edit schedule | Admin only; existing schedule fields |
| Set availability | Available / Busy (where role permits) |

---

## 13. Timeline Integration

### Principle

Workforce history is **projected** through the Timeline Engine — not a bespoke log UI.

### New timeline event sources (register in `Customer360TimelineSourceRegistry` pattern)

Create **`WorkforceTimelineSourceRegistry`** or extend the existing registry with a **workforce scope** — architecture decision at implementation time, but **one TimelineService pipeline**.

| Source | Events projected |
|--------|------------------|
| `WorkforceScheduleTimelineEventSource` | Schedule created/updated |
| `WorkforceLeaveTimelineEventSource` | leave.submitted, leave.approved, leave.rejected |
| `WorkforceAttendanceTimelineEventSource` | Late login, away timeout, extra working |
| `WorkforceAssignmentTimelineEventSource` | assigned, reassigned (from audit) |
| `WorkforceAvailabilityTimelineEventSource` | Availability status changes |

### Event taxonomy (audit-backed)

Use **namespaced events** (feeds Audit Event Registry direction):

| Event | Namespace |
|-------|-----------|
| `workforce.schedule.updated` | workforce |
| `workforce.leave.submitted` | workforce |
| `workforce.attendance.late_login` | workforce |
| `workforce.availability.changed` | workforce |
| `workforce.assignment.assigned` | workforce |

Migrate existing `leave.*` events to namespaced form incrementally; do not dual-write indefinitely.

### Surfaces

| Surface | Timeline scope |
|---------|----------------|
| Individual Workforce360 | Person-scoped |
| Team Workforce360 | Aggregated team events (last 7 days default) |
| Customer360 | Assignment events already missing — Phase 1 adds workforce assignment projection to C360 where person touches customer work |

### Presentation

Reuse `<x-timeline-renderer>` and unified timeline JS. Workforce-specific card variants via `TimelineEventType` enum extension — not a parallel blade stack.

---

## 14. Audit Integration

### Principle

Every workforce mutation flows through **`AuditLogService`** (directly or via `WorkforceAuditService` wrapper).

### WorkforceAuditService (domain wrapper — planned)

Thin named API over audit writes:

| Method | Event |
|--------|-------|
| `recordScheduleChange()` | `workforce.schedule.updated` |
| `recordLeaveSubmitted()` | `workforce.leave.submitted` |
| `recordLeaveDecision()` | `workforce.leave.approved` / `.rejected` |
| `recordAvailabilityChange()` | `workforce.availability.changed` |
| `recordResponsibilityChange()` | `workforce.responsibility.updated` |
| `recordSkillChange()` | `workforce.skill.updated` |

Attendance session events may be **system-audited** at lower verbosity (batch or threshold) to avoid audit noise — policy decision:

| Event | Audit strategy |
|-------|----------------|
| Late login | Always audit |
| Away timeout | Always audit |
| Routine activity tick | Do not audit |
| Session start/end | Configurable; default audit end + late start only |

### Read paths

- Timeline sources read audit logs
- Performance metrics read `WorkSession` + audit for quality
- No third “workforce history service”

---

## 15. Notification Integration

### Principle

All workforce notifications route through **`NotificationAuthorityService`** — no fifth stack.

### Notification catalog (Phase 1)

| Trigger | Recipients | Channel (priority) |
|---------|------------|-------------------|
| Leave submitted | Ops approvers | In-app bell + Telegram (ops) |
| Leave decided | Requester | In-app bell |
| Late login (optional automation) | Ops lead | Telegram digest |
| Away timeout during shift | Ops lead | Telegram (threshold: N per day) |
| Team capacity at risk | Owner / ops lead | Morning briefing inclusion |
| Schedule not saved reminder | Admin | Weekly digest |

### Recipient resolution

Extend **`NotificationRecipientResolver`** with workforce recipient roles:

- `operations_leadership` (existing pattern from leave)
- `workforce_owner` (superadmin / owner)
- `self` (requester)

### Templates

Workforce notifications use the Automation Engine registry pattern where messages are repeatable — ad-hoc Telegram strings migrate to registered notification types over time.

### Agent experience

Agents receive **decision notifications** (leave approved) and **shift reminders** — not surveillance alerts about their own away state (coaching belongs in 1:1 / Performance tab, not push spam).

---

## 16. AI Integration

### Principle

AI **assists** workforce operations — it does not approve leave or reassign work silently.

### WorkforceContextBuilder (planned)

Follows `IncidentAIContextBuilder` pattern under `AIService::buildBundle()`:

| Context block | Content |
|---------------|---------|
| `team_posture` | Team Workforce Score components |
| `capacity` | On duty / on leave / exceptions |
| `member_snapshot` | Individual block reasons, workload |
| `period_signals` | Attendance trends, SLA rollups |
| `pending_actions` | Leave approvals, unscheduled members |

### AI surfaces (Phase 1)

| Surface | AI output |
|---------|-----------|
| Team Workforce360 | “Morning capacity brief” — 3 sentences + exceptions |
| Individual 360 | “Why not assignable?” plain language from `blockReasons()` |
| Owner Telegram briefing | Extend IRA owner report workforce section (existing `IraOwnerReportData.attendance`) |
| Operations advisor | Capacity risk callout when Team Score &lt; 60 |

### Guardrails

| Allowed | Not allowed |
|---------|-------------|
| Summarize attendance exceptions | Auto-approve leave |
| Suggest who is most available | Auto-assign without policy |
| Explain scores | Generate punitive “performance warnings” |
| Draft leave review note | Send customer communication |

### Provider

Use unified `AIProvider` / `IraReasoningProvider` convergence path — Workforce context is another builder, not a second AI pipeline.

---

## 17. Search Integration

### Principle

Find people through **`GlobalSearchService`** + new **`WorkforceGlobalSearchProvider`**.

### Searchable entities (Phase 1)

| Entity | Fields |
|--------|--------|
| Team member | Name, email, role label |
| Skill | Skill label |
| Responsibility | Responsibility label |

### Result shape

`GlobalSearchResult` with link to Individual Workforce360 (or user admin if no permission).

### Future (Phase 2+)

- Search by availability state (“available agents”)
- Search by skill + responsibility combo

### Non-goal

Duplicate name search in dashboard filters — dashboard quick actions link to global search.

---

## 18. Automation Opportunities

Use **Automation Engine** (registry + eligibility + lifecycle) — not one-off cron messages.

### Phase 1 automation candidates

| Automation key | Trigger | Action |
|----------------|---------|--------|
| `workforce_late_login_alert` | Session start + `on_time_login = false` | Notify ops lead (deduped per person per day) |
| `workforce_away_escalation` | Away timeout during shift | Notify ops lead if &gt; threshold |
| `workforce_leave_reminder` | Pending leave &gt; 24h | Remind approvers |
| `workforce_schedule_activation_nudge` | Active user, no saved schedule &gt; 7 days | Notify admin |
| `workforce_capacity_digest` | Weekday morning | Owner briefing via existing team telegram pipeline |
| `workforce_unassigned_pool_warning` | On-duty count &lt; configured minimum | Ops alert |

### Eligibility pattern

```
Generic gates (Automation Engine)
    → WorkforceAutomationEligibilityService
        → has saved schedule? role tracked? quiet hours?
    → Execute notification via Notification Authority
    → Audit + timeline projection
```

### Idempotency

Each automation carries idempotency key: `{automation_key}:{user_id}:{date}` to prevent alert storms.

---

## 19. Policy Engine Requirements

Policy Engine is **future architecture** (constitution Part 4) — Workforce Phase 1 **documents policies** even if initially implemented as domain service checks.

### Policies Workforce Operations needs

| Policy key | Decision | Inputs | Output |
|------------|----------|--------|--------|
| `assignment.eligibility` | Can user receive assignment? | User, timestamp, case context | allow/deny + reasons[] |
| `leave.approval` | Can reviewer decide? | Reviewer, leave request | allow/deny |
| `leave.retroactive` | Is retroactive leave allowed? | Start date, today | allow/deny |
| `attendance.late_grace` | Is login on time? | Schedule, login time | on_time / late |
| `automation.quiet_hours` | Should automation fire? | Time, recipient | allow/deny |
| `availability.self_service` | Can user set Busy? | Role | allow/deny |
| `score.visibility` | Can viewer see individual score? | Viewer, target | allow/deny |

### Phase 1 implementation posture

| Policy | Phase 1 home |
|--------|--------------|
| Assignment eligibility | `WorkforceAuthorityService` (existing) |
| Leave approval | `LeaveRequestService` + policy class |
| Late grace | `WorkCalendarService` config |
| Automation quiet hours | `TeamTelegramQuietRulesService` (existing) |
| Score visibility | Laravel policy / permission |

### Migration path

Extract repeated `if` chains into `WorkforcePolicyService` with structured `PolicyDecision` DTO:

```
{ allowed: bool, reasons: string[], policy_key: string }
```

When Policy Engine exists, `WorkforcePolicyService` becomes a policy pack — **call sites unchanged**.

### Explainability requirement

Every `deny` must map to a **stable reason code** already suitable for UI and AI:

- `calendar_blocked`
- `approved_leave`
- `not_present`
- `availability_offline`
- `not_assignment_pool`

(Aligns with existing `WorkforceAuthorityService::blockReasons()`.)

---

## 20. Future Roadmap

### Phase 1 (this blueprint)

Workforce360 team + individual, responsibilities, skills v1, score, engine integrations, automation catalog.

### Phase 2 — Workforce depth

| Theme | Deliverables |
|-------|--------------|
| Scheduling | Shift templates, bulk schedule activation, coverage planner |
| Leave | Half-day leave, leave types, team calendar view |
| Skills | Skill-based routing hard gates for specialized queues |
| Policy | Formal Policy Engine pack for workforce |
| Mobile | Owner widget / PWA strip for team health |

### Phase 3 — Work360 integration

| Theme | Deliverables |
|-------|--------------|
| Unified work | Work items linked to workforce capacity in one view |
| Assignment history | Full assignment changelog in C360 + Workforce360 |
| Capacity planning | “Hours of capacity vs queue depth” forecast |

### Phase 4 — Operations Intelligence

| Theme | Deliverables |
|-------|--------------|
| Operations360 | Team command merges queue + workforce health |
| Predictive | AI forecasts backlog risk from attendance patterns |
| Coaching | Performance insights framed as coaching cards |

### Phase 5 — CEO Mode

| Theme | Deliverables |
|-------|--------------|
| Owner surface | 60-second business health: customers + workforce + money friction |
| Exception-only push | Owner notified on structural risk, not every late login |

### Technical debt paydown (incremental, during Phase 1–2)

Aligned with P17-07-001 migration priorities:

1. Register workforce audit events in central registry
2. Register workforce timeline sources (no parallel activity services)
3. Route all workforce notifications through authority
4. Extract shared assignment candidate pool before adding responsibility filters
5. Ship `WorkforceGlobalSearchProvider` as search extensibility proof
6. Add `WorkforceContextBuilder` under `AIService`

---

## Appendix A — Platform Engine Contract Summary

| Engine | Workforce Phase 1 usage |
|--------|---------------------------|
| **Audit** | All mutations; `WorkforceAuditService` wrapper |
| **Timeline** | New workforce event sources; unified renderer |
| **Notification** | Authority-owned alerts and leave notifications |
| **Automation** | Late login, reminders, capacity digests |
| **Assignment** | `WorkforceAuthorityService` gate + responsibilities filter |
| **Search** | `WorkforceGlobalSearchProvider` |
| **AI** | `WorkforceContextBuilder` + briefing extensions |
| **Knowledge** | Playbooks for workforce ops (future) |
| **Workspace** | Leave, schedule, availability modals |
| **Outbox** | Reliable Telegram delivery for ops alerts |
| **Policy** (future) | Documented requirements; domain service until engine lands |

---

## Appendix B — Development Standards Checklist

Before any Workforce Phase 1 story ships, confirm:

| # | Question | Workforce answer pattern |
|---|----------|--------------------------|
| 1 | Operational problem? | e.g. “Owner cannot see leave impact on capacity” |
| 2 | Business object? | Workforce360 (team or individual) |
| 3 | Engine reuse? | Named engine + extension point |
| 4 | Business vs platform? | Rules in domain service; projection in engines |
| 5 | 10,000 customers? | No per-user cron storms; indexed queries; idempotent automations |
| 6 | 60-second mobile? | Hero shows score + capacity + exceptions |

---

## Appendix C — Product Test

Every Workforce Phase 1 feature must improve at least one:

| Pillar | Example features |
|--------|------------------|
| **Customer Experience** | Fewer assignments to absent agents; faster coverage when someone goes on leave |
| **Employee Efficiency** | Self-service leave; clear schedule; less “are you working?” messaging |
| **Owner Visibility** | Team Workforce Score; mobile health strip; explainable capacity |

---

## Appendix D — Glossary

| Term | Definition |
|------|------------|
| **On duty** | `WorkforceAuthorityService::isOnDuty()` — calendar allows, not on leave, present, availability Available/Busy |
| **On shift** | Scheduled to be working now (`isOnScheduledShift`) |
| **Responsibility** | Operational routing capability — not department |
| **Skill** | Soft routing hint — not certification |
| **Workforce Score** | Explainable composite posture indicator |
| **Work session** | Attendance record from presence engine |

---

## Document History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-17 | Initial Workforce Operations Phase 1 Blueprint |
