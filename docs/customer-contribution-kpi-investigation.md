# Customer Contribution KPI — Phase 2 Investigation

**Date:** 2026-08-05  
**Type:** Design investigation only (no code changes, no Canvas)  
**Baseline:** [customer-touches-investigation.md](./customer-touches-investigation.md)  
**Audience:** Business owners, managers, future KPI / recognition framework  

---

## Bottom line

**Customer Touches rewards activity volume.** It treats a status flip the same as a resolution and a note-delete the same as a customer WhatsApp. That is useful for “how busy was the desk,” not for “who helped customers.”

**Recommended replacement / complement:** a **Customer Contribution Score (CCS)** that:

1. Scores **outcomes and customer-facing help** far above internal churn  
2. Caps / dampens spammy loops (notes, status thrash)  
3. Credits **ownership of resolution**, not assigner-only theatre  
4. Optionally layers an **Outside-Hours Contribution** badge/multiplier for WO / Holiday / Leave work  

Do **not** rename Customer Touches blindly — keep it as a raw activity diagnostic if useful, and introduce CCS as the recognition / rewards metric.

---

## Relationship to Phase 1 (baseline)

| Metric today | What it measures | Business fit |
|--------------|------------------|--------------|
| **Cases Worked** | Distinct cases touched today | Good coverage / ownership breadth signal |
| **Customer Touches** | Raw event count (status + notes ± delete + manual WA + email + calls) | Weak quality signal; easy to game |
| **My Performance “Completed”** | Closed/resolved with `updated_by` | Closer to outcomes — but period-based, not Team Activity |
| **My Performance “Customer replies”** | Session `communication_events_count` | Mislabelled; not customer replies |
| **Contribution Engine** (`workforce_contribution`) | Threshold packs; flag **off** by default | Qualification gate, not a live score |
| **Recognition packs** (`workforce_recognition`) | Weighted signals; flag **off** by default | Closest existing weight philosophy — reuse ideas |

Phase 1 proved: opening a case counts for nothing; email touches CT but not CW; assignment credits the **assigner**; automation WhatsApp is filtered; CT ≠ quality.

---

## First principles (business owner)

### Reward

| Intent | Example |
|--------|---------|
| Helping customers | Answered call, sent clear WhatsApp, replied by email |
| Solving problems | Case moved to Resolved / Closed with real ownership |
| Ownership | Agent who drove the case to outcome, not only who clicked Assign |
| Quality | One clean resolution > twenty status toggles |
| Voluntary contribution | Worked Sunday / holiday / leave and still delivered outcomes |

### Do not reward

| Anti-pattern | Why |
|--------------|-----|
| Random clicks / screen opens | No customer value (`service_case.viewed` exists, correctly excluded today) |
| Unnecessary status changes | Inflates CT today |
| Note spam / delete / re-add | Inflates CT today |
| Repeated edits / loops | Gaming |
| Pure assigner theatre | Assigning without solving |
| Automation attributed to humans | Inflated ego metrics |

---

## Signal inventory — every measurable human action

Sources searched across the product: `audit_logs` writers, Team Activity allowlists, `operations-kpi` effort map, Presence / WorkSession counters, Bonvoice, Contribution / Recognition engines, Attendance Extra / Leave / Holiday, Customer360 operator actions.

**Legend**

| Column | Meaning |
|--------|---------|
| Value | Business value if done genuinely |
| Abuse | Can humans inflate it cheaply? |
| Auto | Can automation / system create it? |
| Count? | Include in Contribution Score? |
| Weight | Suggested points (scale 0–10; see scoring model) |

### A. Service case lifecycle

| Action / event | Value | Abuse | Auto | Count? | Weight | Notes |
|----------------|-------|-------|------|--------|--------|-------|
| View / open case (`service_case.viewed`) | None | Low | No | **No** | 0 | Correctly excluded today |
| Assign (`service_case.assigned`) as actor | Low–Med | Med | Yes (smart assign) | **Partial** | 1 | Cap 1/case/day; prefer assignee ownership later |
| Reassign | Low | Med | Rare | **Partial** | 1 | Same cap; do not reward ping-pong |
| Escalate | Med | Med | Possible | **Yes** | 2 | Genuine escalations help; cap 1/case/day |
| Unassign / deferred smart assign | None | — | Yes | **No** | 0 | System plumbing |
| Status → In Progress | Low | High | No | **Partial** | 1 | Cap transitions (see Status section) |
| Status → Awaiting Product Details | Low | Med | No | **Partial** | 1 | Waiting on customer/data |
| Status → **Resolved** | **High** | Med | Rare | **Yes** | **8** | Primary outcome |
| Status → **Closed** | **High** | Med | Yes (auto-close) | **Yes** | **5** | Lower than Resolve if Resolve already scored; see rules |
| Status → Reopen (Closed→Open) | Low–Med | High | No | **Partial** | 1 | Cap; reopen loops are gaming |
| Close exception | Med | Low | No | **Yes** | 2 | Exception path still work |
| Customer waiting started / auto-closed | None–Low | — | **Yes** | **No** (human score) | 0 | IRA / automation |
| Automation validation events | None | — | **Yes** | **No** | 0 | IRA KPI separately |

### B. Communication — WhatsApp

| Action | Value | Abuse | Auto | Count? | Weight | Notes |
|--------|-------|-------|------|--------|--------|-------|
| Manual template sent | **High** | Med | No | **Yes** | 3 | Already filtered `trigger_source=manual` |
| Automation / IRA / scheduler / webhook WA | None | — | **Yes** | **No** | 0 | Keep excluded |
| WhatsApp failed | None | — | Any | **No** | 0 | Attempt ≠ help |
| Quick-reply / conversation (if only template system today) | — | — | — | N/A | — | Product has template dispatch; free-form chat not a separate KPI event today |
| System WhatsApp remark | None | — | Yes | **No** | 0 | |

### C. Communication — Email

| Action | Value | Abuse | Auto | Count? | Weight | Notes |
|--------|-------|-------|------|--------|--------|-------|
| Outbound customer email (`notification.dispatched` with human actor, customer-facing) | **High** | Med | Possible | **Yes** | 3 | **Tighten:** exclude pure automation actors; prefer customer-target only |
| `communication_action.lifecycle` | Med–High | Med | Possible | **Partial** | 2 | Deduplicate with `notification.dispatched` for same action |
| `outgoing_email.sent` (reply service) | **High** | Low | No | **Yes** | 3 | Not in CT today — **should count** for CCS |
| `outgoing_email.failed` | None | — | — | **No** | 0 | |
| Inbound customer email received | None (for agent) | — | Webhook | **No** | 0 | Customer effort, not agent |
| Link inbound email | Low | Low | No | **Partial** | 1 | Housekeeping |
| Promote inbound → service case | Med | Low | No | **Yes** | 2 | Creates work unit |
| Email skipped | None | — | Yes | **No** | 0 | |

### D. Communication — Calls

| Action | Value | Abuse | Auto | Count? | Weight | Notes |
|--------|-------|-------|------|--------|--------|-------|
| Answered / completed inbound call | **High** | Low–Med | Webhook | **Yes** | 4 | Prefer answered statuses only |
| Connected talk ≥ N minutes (e.g. 60s) | **High** | Low | — | **Bonus +1** | +1 | Duration quality |
| Short ring / no-answer attributed | None–Low | Med | Yes | **No** | 0 | Do not count all Bonvoice legs |
| Missed call (agent) | None | — | Yes | **No** | 0 | |
| Outgoing click-to-call connected | **High** | Med | No | **Yes** | 3 | |
| Missed-call recovery create/merge | Low–Med | Low | Webhook | **Partial** | 1 | Cap |
| Transferred call | Med | Low | — | **Partial** | 2 | If detectable in payload later |

**Today’s CT counts every matched `call_id`** (including weak matches). CCS should require **answered/completed** (same statuses Team Activity Calls column already uses: `ANSWERED`, `COMPLETED`).

### E. Notes / remarks

| Action | Value | Abuse | Auto | Count? | Weight | Notes |
|--------|-------|-------|------|--------|--------|-------|
| Manual note created | Med | **High** | No | **Partial** | 1 | Cap per case/day (e.g. 3) |
| Manual note deleted | None–Neg | **High** | No | **No** (or −0) | 0 | Deleting must not earn points |
| Note edited (if audited later) | Low | High | No | **No** / Partial | 0–1 | Prefer ignore until quality signals exist |
| System remark | None | — | **Yes** | **No** | 0 | |

### F. Refunds & payments

| Action | Value | Abuse | Auto | Count? | Weight | Notes |
|--------|-------|-------|------|--------|--------|-------|
| Refund requested | Med | Low | No | **Yes** | 2 | |
| Refund approved / rejected | **High** | Low | No | **Yes** | 4 | Decision ownership |
| Refund completed / closed | Med–High | Low | Possible notify | **Yes** | 3 | |
| Refund customer notified | Low | — | Job | **No** | 0 | System |
| Payment / Cashfree automation | None | — | **Yes** | **No** | 0 | IRA / finance automation |

### G. Orders, serial, identity, approvals

| Action | Value | Abuse | Auto | Count? | Weight | Notes |
|--------|-------|-------|------|--------|--------|-------|
| Serial assigned | Med–High | Med | No | **Yes** | 3 | Cap per case/day |
| Serial corrected by IRA | None | — | **Yes** | **No** | 0 | |
| Order / model / identity corrected (human) | Med | Med | Repair auto exists | **Yes** | 2 | Cap |
| Device-model assigned | Med | Med | No | **Yes** | 2 | Not in CT today — should count lightly |
| Customer details corrected | Med | Low | No | **Yes** | 2 | Customer360 action |
| Approval numbers submitted | Med | Med | No | **Yes** | 2 | Cap per case |
| Approval deleted | Low | Med | No | **No** | 0 | Don’t reward undo |
| Legacy import | None–Low | — | Batch | **No** | 0 | |
| Service reference / activation assign | High (Activation roles) | Med | No | Separate pack | — | Not Support CCS |

### H. Appointments & tasks

| Action | Value | Abuse | Auto | Count? | Weight | Notes |
|--------|-------|-------|------|--------|--------|-------|
| Support appointment booked / completed (agent-owned) | Med–High | Low | Smart assign | **Yes** | 3 | Use appointment status when agent owns outcome |
| Appointment repair / reopen (system) | None | — | Repair | **No** | 0 | |
| Knowledge articles | — | — | — | **N/A** | — | No write-side KPI path in codebase today |
| Tasks module | — | — | — | **N/A** | — | No dedicated Support task KPI events found |

### I. Customer360 / AI / ops chrome

| Action | Value | Abuse | Auto | Count? | Weight | Notes |
|--------|-------|-------|------|--------|--------|-------|
| AI suggestion viewed / copied / inserted | Low | High | No | **No** | 0 | Enablement, not contribution |
| RadiumBox manual sync | Low | Med | No | **No** | 0 | Hygiene |
| Availability change / login / logout | None | — | — | **No** | 0 | Attendance domain |
| Leave approve (as approver) | Low (mgmt) | Low | No | **No** for Support CCS | 0 | Manager metric, not customer help |

---

## Special investigations

### 1. Status changes — not all equal

Platform statuses: `open` → `in_progress` → `awaiting_product_details` → `resolved` → `closed` (+ reopen).

| Transition | Suggested weight | Rationale |
|------------|------------------|-----------|
| → In Progress | 1 | Acknowledging work |
| → Awaiting Product Details | 1 | Waiting state |
| → **Resolved** | **8** | Customer problem marked solved |
| → **Closed** after Resolve by same agent same day | **2** (or 0 if Resolve already scored) | Closing is often admin wrap-up |
| → **Closed** without prior Resolve | **5** | Still an outcome |
| Reopen | 1 (cap 1/case/day) | Prevent reopen→close farming |
| Any other flip | 1 with **hard cap** | See anti-gaming |

**Rule:** Per case per day, status points = `min(cap, sum(transition_weights))` with `cap ≈ 10` so thrashing cannot outscore a single clean resolution path.

**Today’s CT bug (business view):** every `service_case.status_changed` = +1 touch. Twenty flips = 20 touches. CCS must break that.

### 2. Internal notes

| Action | Count in CCS? | Weight |
|--------|---------------|--------|
| Added manual note | Yes, capped | 1 each, max 3 / case / day |
| Deleted note | **No** | 0 (today CT wrongly rewards deletes) |
| Edited note | No (until audited) | 0 |

Long notes are not more valuable without quality NLP — do not weight by length.

### 3. WhatsApp

| Kind | Count? |
|------|--------|
| Manual template | **Yes** (weight 3) |
| Automation / IRA / scheduler / webhook | **No** |
| Failed send | **No** |
| Conversation / free chat | Not separately measurable today — out of scope until product emits events |

### 4. Email

| Kind | Count? |
|------|--------|
| Human outbound to customer | **Yes** |
| Lifecycle duplicate of same send | Count **once** (dedupe by incident + minute + action key) |
| Inbound customer mail | **No** |
| Internal / skipped / failed | **No** |
| Promote to case | **Yes** (weight 2) |

**Gap vs today:** CT counts all `notification.dispatched` with no manual filter. CCS must require human actor and preferably customer-facing target.

### 5. Calls

| Kind | Count? |
|------|--------|
| Answered / completed | **Yes** (4) |
| Talk duration ≥ threshold | Small bonus |
| Missed / no-answer / ring-only | **No** |
| Every Bonvoice leg / duplicate call_id | Deduplicate (already partially done) |

---

## Proposed scoring model — Customer Contribution Score (CCS)

### Definition

```
CCS (agent, day) =
  Σ outcome_points          # resolve / close ownership
+ Σ customer_help_points    # answered calls, manual WA, human email
+ Σ enablement_points       # serial, refund decision, promote email, appointment
+ Σ capped_hygiene_points   # notes, assign, intermediate status (hard caps)
× outside_hours_multiplier  # optional; default 1.0 on working days
```

Then apply **anti-gaming dampeners** (below).

### Outcome ownership (critical)

Credit **Resolved / Closed** to the agent who performed the status change (`audit_logs.user_id` on that event) — aligned with My Performance `updated_by` spirit, not with assigner-only CW.

Optional later enhancement: split credit if assignee ≠ resolver (not required for v1).

### Suggested weight scale

| Tier | Points | Examples |
|------|--------|----------|
| Outcome | 5–8 | Resolved, Closed |
| Customer help | 3–4 | Call answered, manual WA, human email |
| Enablement | 2–3 | Serial, refund decision, appointment, promote |
| Hygiene | 1 | Note, assign, intermediate status |
| Noise | 0 | Views, deletes, automation, AI copy |

### Relationship to Cases Worked

Keep **Cases Worked** (distinct cases) as a **coverage** companion:

| Metric | Question answered |
|--------|-------------------|
| Cases Worked | How many different customers/cases did you touch? |
| CCS | How much real help / closure did you deliver? |

A healthy day: high Cases Worked **and** high CCS.  
A gamed day: high CT / high hygiene, low CCS.

---

## Outside-hours contribution (future layer)

### What already exists

| Signal | Source |
|--------|--------|
| Worked Weekly Off / Sunday / Holiday | Attendance `Extra` when sessions on non-working / holiday |
| Worked while On Leave | `is_on_leave` + sessions; status stays **OnLeave** (not Extra) |
| Post-shift OT seconds | `overtime_seconds` |
| Pre+post “XT” | Design only (`docs/team-activity-extra-time-indicator.md`) |
| Contribution / Recognition engines | Flag-gated; recognition already weights outcomes > remarks |

### Recommendation

Do **not** bake leave/holiday into raw CCS points on working days.

Add a separate visible layer:

| Layer | Rule | Display |
|-------|------|---------|
| **Outside-Hours Badge** | Day type ∈ {Weekly Off, Holiday, Leave} **and** CCS ≥ threshold (e.g. ≥ 15) | Badge: `WO` / `HOL` / `LV` |
| **Multiplier (optional v2)** | CCS_final = CCS × m | Suggested m: Weekly Off **1.5**, Holiday **1.5**, Leave **2.0** (highest — true voluntary) |
| **Working-day XT** | Pre/post shift extra time | Separate operational indicator (XT), **not** the same as Extra-day multiplier |

**Do not** give multiplier for “logged in 15 minutes on leave with zero outcomes.” Require outcome or customer-help points first.

Examples for handbook:

| Situation | Treatment |
|-----------|-----------|
| Sunday, closed 6 tickets (high CCS) | Badge + optional ×1.5 |
| Leave, 15 minutes, solved 4 customers | Badge LV + ×2.0 if CCS qualifies |
| Holiday, only status spam | No badge, multiplier N/A |

---

## Anti-gaming protections

| Attack | Protection |
|--------|------------|
| 50 notes on one case | Cap notes at 3 points / case / day |
| 20 status flips | Cap status points / case / day (≈10); Resolved/Closed use transition weights, not +1 each flip |
| Note create→delete loops | Deletes score 0; optionally ignore creates deleted within T minutes |
| Assign ping-pong | Cap assign/reassign 1 / case / day |
| Reopen→close farming | Cap reopen; require minimum dwell time before second Close scores |
| Automation attributed to agent | Exclude automation actors; WhatsApp non-manual; system remarks |
| Short call spam | Count answered/completed only; optional min duration |
| Duplicate email lifecycle | Dedupe by action key / incident / minute |
| Opening screens | Remain weight 0 |
| Bulk meaningless serial edits | Cap serial / order edits per case / day |

**Golden rule for managers:**

> **5 genuine resolutions must always outscore 50 notes or 20 status toggles.**

With suggested weights: 5 × Resolve (8) = **40**.  
50 notes capped at 3/case across many cases still cannot dominate if case caps + outcome weights hold.  
20 status flips on one case capped at ~10 << one Resolve.

---

## KPI alternatives compared

| Name | Pros | Cons | Verdict |
|------|------|------|---------|
| **Customer Contribution Score (CCS)** | Clear owner language; weighted; anti-game; complements Cases Worked | Needs calibration | **Primary recommendation** |
| Customer Value Score | Emphasizes value | “Value” sounds financial | Alias OK |
| Operational Impact Score | Broader | Dilutes customer focus | Secondary for ops roles |
| Support Effectiveness | Manager-friendly | Vague without formula | Use as dashboard title over CCS |
| Customer Assistance Score | Soft language | Underplays closures | Soft rename only |
| Keep Customer Touches only | Already built | Rewards spam | **Keep as diagnostic, not rewards** |

**Recommended portfolio**

1. **Cases Worked** — breadth (keep)  
2. **Customer Contribution Score** — quality / impact (new)  
3. **Customer Touches** — optional raw activity (deprecate for recognition)  
4. **Outside-Hours badge / multiplier** — voluntary contribution (phase after CCS)

---

## Worked examples (≥ 20)

Weights used: Resolve 8, Close-after-resolve 2, Close-only 5, Call answered 4, Manual WA 3, Human email 3, Note 1 (cap 3/case), Intermediate status 1 (cap), Assign 1.

| # | Scenario | Approx CCS | Reads as |
|---|----------|------------|----------|
| 1 | Worked 2 cases, resolved both | 16 | Excellent |
| 2 | Worked 1 case, 20 status updates, never resolved | ≤10 (cap) | Busy, low impact |
| 3 | Sunday, closed 6 tickets (close-only) | 30 ×1.5 ≈ 45 | Star voluntary day |
| 4 | Leave, 15 min, solved 4 customers (resolve) | 32 ×2.0 ≈ 64 | Exceptional |
| 5 | 1 case: note + WA + resolve + close | 1+3+8+2 = 14 | Solid single case |
| 6 | 1 case: 15 notes only | 3 (cap) | Note spam failed |
| 7 | Assign 10 cases, no other work | 10 | Low value breadth |
| 8 | Assign 10 + resolve 2 | 10+16 = 26 | Better — ownership shows |
| 9 | 8 answered calls, no tickets | 32 | Phone hero day |
| 10 | 8 missed/ring-only calls | 0 | No CCS |
| 11 | 10 auto WhatsApps attributed in feed | 0 | Correctly ignored |
| 12 | 10 manual WhatsApps on 3 cases | 30 | High help volume |
| 13 | Email reply ×5 (human) | 15 | Good written support |
| 14 | Customer sent 5 emails (inbound only) | 0 | Not agent credit |
| 15 | Refund approved ×2 | 8 | Decision ownership |
| 16 | Serial fixed on 3 cases | 9 | Enablement |
| 17 | Reopen×close loop ×5 on one case | ~few points after caps | Gaming blocked |
| 18 | Holiday Extra day, 1 login, 0 outcomes | 0 (+ no badge) | Presence ≠ contribution |
| 19 | Holiday, 3 resolves + 2 calls | 24+8=32 ×1.5 ≈ 48 | Recognise |
| 20 | Agent A assigns; Agent B resolves | A:1, B:8 | Credit follows solve |
| 21 | Promote email→case + resolve | 2+8 = 10 | End-to-end ownership |
| 22 | Delete 20 notes | 0 | No reward for undo |
| 23 | AI copy/insert ×50 | 0 | Not contribution |
| 24 | Working day, early login only (XT) | 0 CCS; XT separate | Time ≠ contribution |
| 25 | Mixed: 3 resolves, 2 calls, 4 notes (2 cases), 1 assign | 24+8+3+1 ≈ 36 | Strong day |

---

## Manager view — what you should see immediately

From CCS alone, a manager should answer:

1. **Who helped customers today?** (high CCS)  
2. **Who was busy but ineffective?** (high CT / high Cases Worked, low CCS)  
3. **Who closed problems?** (outcome share of CCS)  
4. **Who contributed outside roster?** (Outside-Hours badge)  

### Fit for HR processes

| Use | Fit | Guidance |
|-----|-----|----------|
| Recognition / shout-outs | **Yes** | Daily/weekly CCS leaders |
| Monthly awards | **Yes** | Sum CCS + Outside-Hours days |
| Performance review | **Yes** | Trend CCS vs Cases Worked; never CT alone |
| Promotion | **Partial** | CCS + quality audits + SLA; not sole input |
| Rewards / Extra day / Comp-off | **Yes** | Align with existing Recognition bands (`config/workforce_recognition.php`) which already weight resolve/close > remarks |

### One-screen manager story

```
Priya   Cases Worked 12   CCS 48   ● Strong
Rahul   Cases Worked 18   CCS 11   ● Busy / low impact
Asha    Cases Worked  6   CCS 40   WO ● Sunday hero
```

---

## Implementation guidance (recommendation only — do not build yet)

1. **Reuse** audit_logs + Bonvoice + existing manual filters from Phase 1.  
2. **Reuse weight philosophy** from `config/workforce_recognition.php` support pack (resolve/close high; remarks/status low).  
3. **Do not** mutate attendance, payroll, or Customer Touches formula until CCS is calibrated in shadow mode.  
4. Shadow-run CCS beside CT for 2–4 weeks; compare leaderboards.  
5. Only then replace CT in recognition / Team Activity emphasis.

### Shadow-mode success criteria

- Top CT agents who only thrash statuses fall in CCS rank  
- Resolvers and callers rise  
- Sunday/Leave heroes become visible via badge  
- No payroll / attendance regressions (CCS is presentation/scoring only)

---

## Explicit non-goals

- Not a rewrite of Cases Worked  
- Not payroll OT  
- Not Extra-day attendance mutation  
- Not counting AI workbench clicks  
- Not counting customer inbound messages as agent credit  
- Not implementing in this phase  

---

## Decision summary

| Decision | Recommendation |
|----------|----------------|
| Keep Cases Worked? | **Yes** — breadth |
| Keep Customer Touches for rewards? | **No** — diagnostic only |
| Primary new KPI | **Customer Contribution Score (CCS)** |
| Status weights equal? | **No** — Resolved ≫ intermediate |
| Note deletes count? | **No** |
| Auto WhatsApp count? | **No** |
| All calls count? | **No** — answered/completed only |
| Outside-hours | Badge first; multiplier v2 |
| Anti-gaming | Per-case caps + outcome dominance |

---

## References

| Doc / code | Role |
|------------|------|
| [customer-touches-investigation.md](./customer-touches-investigation.md) | Phase 1 baseline |
| [team-activity-extra-time-indicator.md](./team-activity-extra-time-indicator.md) | XT (outside shift window) |
| [team-activity-overtime-investigation.md](./team-activity-overtime-investigation.md) | Payroll OT ≠ early login |
| `config/operations-kpi.php` | Current CT event map |
| `config/dashboard-team-activity.php` | CW allowlist |
| `config/workforce_recognition.php` | Existing outcome-heavy weights |
| `config/workforce_contribution.php` | Qualification thresholds (flag off) |
| `app/Services/Operations/SupportActivityMetricsService.php` | Current CT math |
| `app/Services/Workforce/Extra/ExtraQualificationEngine.php` | Extra day policy |
| `app/Services/Workforce/Recognition/RecognitionCandidateDetector.php` | WO/Holiday candidates |

---

*End of Phase 2 investigation. Suitable as the foundation for a future KPI framework and agent/manager handbook sections on contribution vs activity.*
