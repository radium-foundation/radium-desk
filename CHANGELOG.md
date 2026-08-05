# Changelog

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
