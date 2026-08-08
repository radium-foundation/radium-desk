# Changelog

## 4.0.17 — 2026-08-08 — RadiumBox Recover-Sync Scan Optimization

- Reduced unnecessary RadiumBox recover-sync candidate scanning by filtering out orders already beyond automatic recovery limits.
- Preserved stale-PENDING handling and the existing recovery, retry, enrichment, and scheduler behavior.

## 4.0.16 — 2026-08-08 — Cashfree Scoped Outbox Processing

- Scoped Cashfree deferred outbox processing to the payment incident instead of draining unrelated global pending jobs.
- Preserved processing of the payment's three deferred operations, dashboard broadcast, enrichment, and the global cron safety net.

## 4.0.15 — 2026-08-08 — Scheduler Automation Pending Limit

- Limited automation-pending grace processing per scheduler light-tick so large expired backlogs cannot run unbounded in one minute.
- Preserved Ready Queue unassigned pickup behavior after grace processing.

## 4.0.14 — 2026-08-08 — IRA Cashfree Cache-Read Briefing

- Made IRA Operations Live Cashfree health highlights cache-read-only so normal briefing never rebuilds Cashfree integrity.
- Preserved on-demand Cashfree health widget rebuild behavior for full/health surfaces.

## 4.0.13 — 2026-08-08 — BonVoice Outgoing Retirement

- Retired Desk-initiated BonVoice click-to-call and outbound live-status functionality.
- Preserved incoming BonVoice IVR, call events, live-assist, missed-call recovery, history, and reprocessing.
- Scoped incoming BonVoice webhook outbox processing to its own aggregate to prevent unrelated outbox work from running during webhook requests.

## 4.0.12 — 2026-08-08 — Ready Queue Incremental Updates

- Added incremental Ready Queue count updates for proven case additions and removals without triggering unnecessary full count reconciliation.
- Added membership-state protection to prevent duplicate or stale Ready Queue count changes across queue switches and authoritative count refreshes.
- Preserved existing Ably row updates and absolute reconciliation as the safety mechanism.

## 4.0.11 — 2026-08-08 — Ready Queue Reconcile Performance

- Optimized Ready Queue KPI reconciliation to return counts and KPI data without rebuilding Ready Queue rows.
- Reduced unnecessary database queries, PHP processing, and response payload during event-driven dashboard reconciliation.
- Preserved existing Ready Queue row updates, Ably events, heartbeat behavior, and full dashboard refresh behavior.

## 4.0.10 — 2026-08-08 — Dashboard Broadcast Performance

- Removed synchronous per-recipient KPI rebuilding from Operations dashboard service-case broadcasts.
- Preserved row and SLA broadcasts while allowing clients to reconcile KPIs through the existing dashboard refresh mechanism.
- Reduced dashboard broadcast processing from 25–38 seconds to approximately 200ms in the local 12-viewer benchmark.

## 4.0.9 — 2026-08-07 — Cashfree-First Enrichment

- Implemented Cashfree-first enrichment for paid orders, using webhook order tags to complete eligible orders without RadiumBox lookup.
- Added automatic fallback to RadiumBox enrichment only when required order data is incomplete.
- Reduced unnecessary queue jobs and external API calls while preserving existing manual sync, recovery, and legacy repair workflows.

## 4.0.8 — 2026-08-07 — Operations Live Query Efficiency

- Eliminated repeated waiting-state and order database queries during Operations Live team performance evaluation by batching operational queries and eager-loading related data.
- Optimized team performance quality scans to remove N+1 query patterns while preserving existing dashboard behavior and business logic.
- Improved cold Operations Live performance through more efficient database access with no UI or functional changes.

## 4.0.7 — 2026-08-07 — Operations Live Performance

- Optimized Operations Live full refresh to load only the bundles required for requested dashboard sections instead of rebuilding all bundles.
- Reduced unnecessary Platform Health processing during full refresh by skipping unused payment and integration diagnostics while preserving on-demand health endpoints.
- Reduced unnecessary bundle execution and SQL workload during full refresh with no UI or business logic changes.

## 4.0.6 — 2026-08-07 — Performance Hardening & Production Reliability

### Infrastructure

- OPcache max file size corrected so large PHP files are eligible for caching again
- LiteSpeed and PHP worker configuration audited against production CPU load

### Performance

- Driver Guide batch sends now process in configurable chunks to reduce long queue monopolization
- Scheduler cadence consolidated to cut unnecessary background wake-ups
- Automation snapshots refresh incrementally with event-driven invalidation
- Assign Reference batch work coalesces side effects and Driver Guide dispatch

### Reliability

- Platform Health heartbeat file no longer tracked in Git, preventing deploy pull failures
- Dashboard KPI zero-display regression investigated and root-caused
- Production CPU spikes attributed across HTTP workers, queues, and request paths
- Redis migration readiness assessed against current cache and queue usage
- Operations Live architecture mapped for safe follow-on optimization

## 4.0.5 — 2026-08-06 — Platform Email Operations

- Platform adds an Email Operations section for inbound email health, pipeline, exceptions, and recent activity
- Email Operations metrics open existing Learning Center, case, and Gmail failure screens — no duplicate tools
- IRA Learning Center row expand always shows subject and preview, with retry for extra details
- Working a Spam email (assign, create case, or link) returns it to Needs Review instead of staying in Spam
- Auto Processed renamed to Completed Automatically for operators, with clearer Handled By / Result columns
- Completed Automatically shows grouped breakdown: System Notifications, Auto Replies, Own Outbound, Bounces, Duplicate Notifications
- Review Suggested queue surfaces emails IRA is uncertain about without changing routing

## 4.0.4 — 2026-08-05 — Email Intake, Dashboard Performance & Reliability

### ✨ New

- Inbound email automatically reopens eligible closed service cases on the same order instead of creating duplicates
- Smart routing for new actionable email sends Support, Sales, and Refund enquiries to the right team with round-robin assignment
- Email Intake KPI card on the dashboard showing Needs Attention total with Sales, Orders, and Escalations breakdown on hover

### ⚡ Performance

- Faster dashboard loads with shared caching for active service case snapshots
- Team Activity panel defers roster loading until expand, improving dashboard first paint
- Faster Team Activity refresh when supervisors expand the roster
- Email Intake dashboard widget cached for smoother KPI strip updates
- Live dashboard polls patch KPIs and case rows in place when unchanged, reducing flicker and tooltip resets

### 💳 Payments

- Cashfree payment webhooks validate the configured automation user before creating paid orders
- Platform Health and Operations show Cashfree webhook secret, system user, queue, and outbox status
- Failed payment webhooks record a clear error when the automation user is missing or inactive

### 🛠 Improvements

- Customer 360 timeline shows one unified card when email reopens a closed case, with action chips and collapsed technical details
- Email Intake dashboard labels Escalations instead of Priority in the attention breakdown
- Email Intake KPI hover tooltip displays the full attention and ignored-mail breakdown again
- Customer 360 IRA Overview uses clearer agent language — RO overdue labels, Case Delay, Assigned To, and simplified status chips
- Completed refunds always close the linked service case and clear refund holds even when customer notification is unavailable

### 🐞 Fixes

- Refund completion no longer leaves service cases open when WhatsApp or email confirmation cannot be sent
- Cashfree paid orders no longer fail silently when the system automation user is misconfigured

## 4.0.3 — 2026-07-29 — Context Transparency & Commercial State

- BR-03 Context Transparency foundation
- ContextScope enum, ContextBadge, and Customer360 card catalog
- Context transparency feature flag and presenter scope metadata
- BR-02 and BR-03 documentation
- BR-04 Commercial State
- CommercialStateResolver and CommercialStateSnapshot
- Sticky Commercial State card in Customer 360
- Dashboard commercial badges and resolved-duration status label
- Commercial workflow guards for service reference, paid service, paid appointment, and charge customer
- BR-04 documentation
- Context transparency and commercial state tests

## 4.0.2 — 2026-07-28 — Deployment Tag Synchronization

- Fetch Git tags during deployment before writing the release snapshot

## 4.0.1 — 2026-07-28 — Team Activity & Customer 360 Call Intelligence

- Fix Customer 360 call status presentation for missed IVR calls
- IVR call summaries in Customer 360 communication intelligence (answered, no answer, busy, failed, and related statuses)
- BonVoice call timeline event source alignment for IVR call status handling
- Team-wide inbound IVR calls received today in the Team Activity panel
- Team Activity call metrics inbound direction filter consolidation
- Team Activity agent row and panel display updates for team-wide IVR totals
- Team Activity call metrics and Customer 360 call intelligence tests

## 4.0.0 — 2026-07-26 — P09 Workforce Platform Update

- Workforce availability intelligence
- Role management improvements
- Better assignment accuracy
- IVR foundation improvements
