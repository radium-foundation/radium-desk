# Changelog

## 4.0.39 — 2026-08-18 — Customer Waiting Audit Event Fix

- Fixed automation scheduler failures when clearing customer waiting on already-closed service cases caused by audit log event names exceeding database limits.

## 4.0.38 — 2026-08-18 — Historical Gmail Prune Memory Fix

- Fixed historical Gmail noise prune execute mode exhausting PHP memory by batching deletes using IDs only instead of loading full email payloads.

## 4.0.37 — 2026-08-18 — Historical Gmail Noise Prune (Dry-Run)

- Added a dry-run-first prune command for pre-July historical ignored Gmail noise, reusing the inspection safety predicate with explicit --execute required for deletion.

## 4.0.36 — 2026-08-18 — Historical Gmail Noise Inspection

- Added a read-only inspection command to identify pre-July historical ignored Gmail noise by received date, with explicit safety exclusions.

## 4.0.35 — 2026-08-18 — Database Retention Prune (Dry-Run)

- Added dry-run retention prune commands for expired cache rows and completed outbox events older than 14 days.
- Added read-only database retention inspection to review growth candidates before any cleanup.
- New webhook events no longer store duplicate raw request bodies alongside parsed payloads.
- Gmail promotional, social, spam, and trash messages are skipped earlier during email intake.

## 4.0.34 — 2026-08-14 — Dead-Letter Watchdog Alerts

- Stabilized dead-letter queue Telegram alerts so the same failed jobs no longer repeat after deploy or cache clears.
- Unified watchdog dead-letter messaging across Platform Health and legacy probe paths.

## 4.0.33 — 2026-08-13 — Dashboard Navigation

- Improved Email Intake KPI hover so the full breakdown is visible.
- Removed redundant “View all service cases” dashboard links.
- Removed Orders, Refunds, and Service Cases from the left sidebar.
- Routes, permissions, dashboard workflows, and existing functionality remain available.
- Ready Queue operation was not changed.

## 4.0.32 — 2026-08-13 — Bonvoice Intake Prevention

- Unmatched Bonvoice missed calls without customer IVR input no longer create service cases.
- Known customers with matched orders still get missed-call recovery cases without requiring IVR input.
- Valid DTMF and IVR menu selections continue to create enquiry cases for unknown callers.
- Suppressed missed-call intake is recorded in audit logs for operations visibility.

## 4.0.31 — 2026-08-13 — Ready Queue Refunded Exclusion

- Refunded cases no longer appear in the Ready Queue.
- Commercial service restoration and revoke refresh Ready Queue membership in realtime.

## 4.0.30 — 2026-08-12 — Dashboard Reliability & Payment Hardening

- Fixed dashboard snapshot cache growth that could prevent operators from logging in.
- Reduced dashboard CPU by caching queue classification and batching KPI broadcasts.
- Stopped unnecessary full dashboard reloads during healthy realtime heartbeat ticks.
- Ready Queue tab counts reconcile correctly after hybrid assignment and lifecycle events.
- Added a lightweight Ready Queue membership heartbeat to keep queue badges accurate without heavy polling.
- Ready Queue rows catch up when counts change without a matching row update.
- Re-clicking the active Ready Queue tab refreshes the case list without resetting pagination.
- Removed the duplicate Ready Queue heading when queue navigation tabs are visible.
- Total Active Cases and Refunds KPIs now refresh from lightweight count endpoints with stale-aware and manual refresh controls.
- Cashfree payments link to existing orders when legacy imports and webhooks race on mixed-case order IDs.
- Cashfree reprocess tooling correctly reports existing orders instead of false recovery candidates.
- Deferred Cashfree dashboard broadcasts are disabled by default to reduce post-payment CPU load.
- Customer 360 business timelines show cleaner, deduplicated milestones with fewer noisy duplicate events.
- Gmail inbound sync uses single-flight locking to prevent overlapping sync runs.
- Platform health dead-letter queue alerts use stable fingerprints to reduce repeated Telegram noise.
- Fixed legacy service request creation from global search and legacy order intake flows with clearer validation errors.

## 4.0.29 — 2026-08-09 — Navbar Alignment

- Anchored notifications, To-Dos, and the profile menu to the far-right of the topbar.
- Constrained search width so it no longer crowds or runs into Customer 360 when the drawer is open.

## 4.0.28 — 2026-08-09 — Dashboard To-Do Layout

- Tightened the dashboard To-Do KPI card so it matches other compact KPI cards.
- Improved topbar breathing room between search, notifications, To-Dos, and the user menu.
- Cleaned Recent Customers chip spacing without changing Customer 360 behavior.

## 4.0.27 — 2026-08-09 — Contextual To-Do Modal

- Open To-Dos from the navbar, sidebar, and dashboard without leaving the current page.
- Create, edit, complete, reopen, cancel, and assign To-Dos inside a centered modal.
- Escape and stacking stay correct when Customer 360 is also open.

## 4.0.26 — 2026-08-09 — To-Dos and Reminders

- Added personal and assigned To-Dos with priority, due dates, completion, and cancel support.
- Added optional reminders that fire into the existing notification center with deep links.
- Added a minute scheduler that safely dispatches due reminders without duplicate notifications.

## 4.0.25 — 2026-08-09 — Dashboard Queue Snapshot Performance

- Operations and dashboard queue/SLA counts now reuse precomputed metrics from the active-incident snapshot cache, avoiding repeated case classification on cache hits.
- Snapshot cache default TTL increased from 20 to 30 seconds (still capped at 30) for better alignment with dashboard refresh cadence.

## 4.0.24 — 2026-08-09 — Outbox Cashfree Claim Guard

- Prevented the global outbox processor from stealing Cashfree deferred jobs while a payment's scoped drain is in flight.
- Preserved Interakt, email, and other unrelated outbox processing, plus cron recovery for true leftovers.

## 4.0.23 — 2026-08-09 — Cashfree Missed Webhook Batch Heal

- Added a Cashfree missed-webhook batch heal command for allowlisted paid orders that did not receive webhook delivery.
- Defaults to dry-run preview; writes only when `--execute` is explicitly passed.
- Recovers through the existing Cashfree webhook processor pipeline using synthetic PAYMENT_SUCCESS logs.

## 4.0.22 — 2026-08-09 — Missing Serial Scheduler Performance

- Ran missing-serial automation in the background so schedule:run is no longer blocked during outreach runs.
- Reduced skip-heavy batch work by filtering to due request, reminder, and escalation windows in SQL instead of scanning not-yet-due candidates in PHP.
- Removed duplicate eligibility checks on the skip path while preserving existing timing rules, prioritization, and customer messaging behavior.

## 4.0.21 — 2026-08-09 — Cashfree Health & Performance

- Reduced evening health report CPU by using scalar Cashfree reconciliation instead of a full payment reconcile scan.
- Optimized Platform Cashfree health warm refresh to reuse the operations cache and avoid expensive probe work on cache miss.
- Batched Ready Queue admin audit visibility queries to reduce repeated database lookups.
- Removed live slow-count queries from dashboard snapshot refresh.
- Added scheduler timing telemetry to improve CPU spike attribution in production logs.

## 4.0.20 — 2026-08-09 — Cashfree Paid-Without Discovery

- Reduced Cashfree paid-without-order integrity CPU by checking only unmatched payment candidates instead of scanning the full webhook history.
- Preserved reconcile completeness, assessment rules, and handling of payments with a missing payment-id column.

## 4.0.19 — 2026-08-09 — Automation Snapshot Hydration

- Reduced Automation Snapshot full-rebuild CPU cost by loading only the incident, order, and assignee columns required for dashboard health and queue classification.
- Preserved snapshot payload semantics, quiet-reconcile skip behavior, and periodic full-rebuild safety nets.

## 4.0.18 — 2026-08-09 — CPU Optimization Batch

- Reduced Cashfree integrity CPU cost by deduplicating alert calculation, narrowing hydrate queries, and bounding missing-order recovery discovery per run.
- Staggered overnight scheduler workloads across the hour to cut clock-aligned CPU spikes without skipping required recovery or reconciliation jobs.
- Skipped full Automation Snapshot rebuilds during quiet reconciliation when nothing changed, while preserving dirty-state and periodic full-rebuild safety nets.

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
