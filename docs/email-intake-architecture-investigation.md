# Email Intake Architecture Investigation

**Date:** 2026-08-05  
**Priority:** P0 architecture (read-only)  
**Status:** Investigation complete · no code changes · no Canvas  
**Philosophy:** Desk is the work execution platform. Gmail remains the email client. Email is an intake channel — not a Gmail clone.

**Related prior docs (superseded for product direction by this report):**

- `docs/p-04-08-001-inbound-email-workflow-audit.md`
- `docs/p-04-08-003-inbound-email-to-service-case-plan.md`
- `docs/email-system-investigation.md` (ownership routing notes)
- `docs/p-04-08-006-service-case-email-workspace-phase1.md` / `…-phase2.md`
- `docs/communication-email-template-investigation.md`

---

## Executive verdict

Radium Desk already has a **real, tested Gmail History-API intake pipeline**: sync → ingest/dedupe → outbox → filter → match → classify → link / historical / needs-review / optional auto-create → assignment → notify → Customer360 thread + optional Gmail reply.

It is **not** a Gmail clone. That is aligned with product philosophy.

Gaps versus the desired Phase 1 intake workflow are concentrated in:

1. **No AI classification** — deterministic keywords + Gmail labels only.  
2. **No reopen of closed cases** — closed SCs are treated as “no active case.”  
3. **No round-robin** on sales/unknown support — Communication Intake primary/fallback only.  
4. **Ignore counters exist in DB but not as a dashboard product surface.**  
5. **Attachment download-in-Desk** exists — vision wants metadata + open original Gmail.  
6. **Feature flags default off** for intake, Gmail sync, auto-create, and reply — production readiness depends on env rollout, not missing code for the core sync path.

**Recommendation:** Keep the intake spine. Improve toward Email Intake Phase 1. Remove / freeze Gmail-clone tendencies and unused assignment contracts. Do not build a full mailbox UI.

---

## 1. What is already implemented?

### 1.1 Inbound pipeline (production-capable core)

```
Scheduler (every N min)
  → artisan inbound-email:sync-gmail
    → IncomingEmailGmailSyncService::sync()
      → GmailInboundEmailProvider::pullIncremental()   # History API + historyId cursor
        → GmailApiClient::listHistoryPage / getMessage
        → GmailMessageMapper::toNormalized
      → IncomingEmailIngestService::ingest()           # dedupe + persist
        → IncomingEmailOutboxWriter                     # email.inbound.process
  → outbox:process
    → IncomingEmailProcessorService::process()
        → Filter → Match → Classify → Link / Historical / NeedsReview / Auto-create
        → Priority boost → Assignment → NewEmailReceivedNotification
```

| Capability | Status |
|------------|--------|
| Gmail API OAuth (service account + DWD) | Implemented |
| History API incremental sync | Implemented |
| Per-mailbox `history_id` cursor | Implemented |
| No historical backfill on first enable / rebaseline | Implemented |
| Message-ID + provider message id dedupe | Implemented |
| Thread id persistence + thread→active SC match | Implemented |
| Labels → spam/promo/social ignore | Implemented |
| Bounce / OOO / newsletter / blocked sender filters | Implemented |
| Own-outbound echo suppression | Implemented |
| Outbox-driven processing + retries | Implemented |
| Sync resilience (locks, HTTP retry, stale 404 skip, failure rows) | Implemented |
| Admin sync-now / rebaseline / logs / failed messages | Implemented |
| Operations Gmail health widget | Implemented |

**Entry is polling only.** No Gmail `users.watch`, Pub/Sub push, IMAP, or inbound webhook.

### 1.2 Matching & case automation (implemented)

| Scenario | Today |
|----------|--------|
| Active SC on matched order (or thread) | Link email, boost priority, keep assignee or Communication Intake assign, notify owner |
| Order exists, no active SC | Default: `HistoricalCustomer`. Optional flag: auto-create SC + link + route |
| No order / unknown sender | Default: `NeedsReview` (often classified `possible_sales_lead`). Optional flag: create INQ order + SC + link + route |
| Finance / HR / Vendor keywords | Operational classification; **never** auto-create SC |
| Spam / promo / social / newsletter / bounce / OOO | `Ignored` + ignore-stat increment |

### 1.3 Outgoing (two separate systems)

| Path | Purpose | Mechanism |
|------|---------|-----------|
| **Notification email** | System → customer templates (serial, waiting, refund confirmation, etc.) | `NotificationDispatcher` → `EmailChannel` → `NotificationMailSender` → Laravel Mail |
| **Operational reply** | Agent replies in Customer360 to a linked inbound message | `OutgoingEmailReplyService` → Gmail API `sendRawMessage` (same thread) |

Reply supports free text + preview/edit of selected notification templates. Preserves `In-Reply-To` / Gmail `threadId`. Feature-flagged (`INBOUND_EMAIL_REPLY_ENABLED`, default false). Mailbox allowlist.

### 1.4 Customer360

- Service-case email workspace modal + conversation thread  
- Live body fetch from Gmail (or legacy DB body if present)  
- Reply compose + template preview  
- Timeline sources: incoming + outgoing (sent)  
- Mark-thread-read  
- Polling refresh (~20s) — not realtime websocket for email

### 1.5 Health / admin

- Operations Gmail health partial  
- Sync metrics, cursor lag, OAuth status, failures today  
- Admin routes under `/admin/gmail/*`  
- Configuration health summary includes Gmail when enabled  

### 1.6 Permissions

| Capability | Control |
|------------|---------|
| Reply | `email.reply` (admin / ops admin / superadmin) **or** assigned SC owner |
| Read content / attachments | Incident/Order `view` via content controller |
| Learning Center / Email Intake (view) | `email-intake.view` |
| Learning Center teach / disposition | `email-intake.manage` |
| Gmail admin sync | `SystemSetting` update authorization (broad; separate from Learning Center) |

---

## 2. What is partially implemented?

| Area | What’s there | What’s missing |
|------|--------------|----------------|
| Auto-create SC from email | Full service + tests; processor wired | Flag default **off**; not the live default path |
| Ignore stats | `incoming_email_ignore_stats` increments | **No dashboard counters UI** for spam/promo/OOO |
| Classification | Deterministic labels + keywords | **Not AI**; weak sales vs support separation for unknowns |
| Assignment for new cases | Communication Intake primary/fallback | Vision wants **round-robin** for sales & unknown support |
| Closed-case handling | Becomes historical / optional new SC | Vision wants **reopen + raise priority + notify** |
| Attachments | Metadata stored; Desk can download via Gmail API | Vision: metadata only + **open Gmail**; no local store/preview |
| Reply | Pilot path + tests | Flag off by default; sync send; no retry UI; no outgoing attachments |
| Notification mail | Templates + channel | Synchronous; no dedicated mail queue/retry; master switch gaps |
| Health widget | Strong for first mailbox sync | Not multi-mailbox aggregate; ignores reply/notification transport health |
| Needs Review | Status written | No triage queue UI (intentionally superseded by SC creation plan) |
| Failed sync messages | Observable table + admin list | No replay/remediation action after cursor advance |

---

## 3. What is production ready?

Assuming env flags and Google DWD are correctly configured:

| Component | Readiness |
|-----------|-----------|
| Gmail History sync + cursor + resilience | **Production Ready** |
| Ingest dedupe + outbox processing | **Production Ready** |
| Filter ignored mail (spam/promo/bounce/OOO) | **Production Ready** |
| Link to active SC + keep owner + notify | **Production Ready** |
| Communication Intake primary/fallback | **Production Ready** (for that design) |
| Gmail admin health / sync-now / rebaseline | **Production Ready** (ops) |
| Customer360 thread view (live Gmail body) | **Production Ready** with Gmail retention dependency |
| Deterministic classifier | **Production Capable** (rules, not AI) |
| Auto-create SC path | **Production Capable** behind flag — not default |
| Gmail reply | **Pilot / Partial** — code ready, flag off, sync send |
| Notification templates (Laravel Mail) | **Production Ready** for system notifications (separate from intake) |

**Important:** Defaults in `config/inbound_email.php` are conservative (`enabled`, `gmail.enabled`, `auto_create_service_case`, `reply.enabled` all default false). “Code ready” ≠ “enabled in a given environment.”

---

## 4. What is obsolete / prototype / unused?

| Item | Classification | Notes |
|------|----------------|-------|
| `EmailAssignmentClassification` enum | Prototype / Future contract | Explicitly “Phase 1 defines the contract only” |
| `UniversalAssignmentEngine::assignForEmailClassification` | Unused | Not called by inbound processor |
| `EmailTriageAssignmentStrategy` | Unused dead path | Capability/SalesLead/Support RR — not wired to intake |
| `IncomingEmailSupervisor` / `SalesLeadHandler` capabilities | Partial unused | Exist in assignment capability config; not used by live email processor |
| Needs Review triage UI plans (`p-04-08-002`) | Obsolete plan | Superseded by “every actionable email → Service Case” |
| Communication Template Store tables | Removed | Drop migration exists; historical create migrations remain artifacts |
| Fixture inbound provider | Dev/test only | Not production |
| IMAP / webhook intake | Never built | Mentioned only in architecture blueprints |
| Gmail watch / Pub/Sub | Never built | Polling only |
| Forward / Draft / Compose-new / Reply-all | Never built | Correct under Email Intake philosophy |
| AI / ERA email classifier | Never built | Deterministic only |
| Desk attachment preview/viewer | Never built | Download endpoint exists (leans Gmail-clone) |

Temporary concern: `GmailAccessTokenService` contains explicitly marked temporary production diagnostics logging JWT claim metadata (not secrets) — should not remain indefinitely.

---

## 5. What should be removed (or frozen)?

Aligned with **Email Intake, not Gmail clone**:

| Action | Target | Why |
|--------|--------|-----|
| **Remove or gate** | Desk attachment binary download UX as primary path | Vision: metadata + open Gmail; avoid duplicating Gmail viewer |
| **Freeze / delete later** | Unused `EmailTriageAssignmentStrategy` wiring until a single assignment story is chosen | Two parallel assignment stories confuse Phase 1 |
| **Do not build** | Inbox UI, labels browser, search-all-mail, drafts, forward, reply-all, compose-new, signature editor, read receipts | Gmail’s job |
| **Do not resurrect** | Needs Review parallel queue UI | Conflicts with “one work queue = Service Cases” |
| **Clean later** | Temporary OAuth JWT diagnostics in token service | Ops noise / hygiene |
| **Keep but don’t expand** | Live Gmail body fetch in modal | Useful for work context; do not evolve into full MIME client |

---

## 6. Complete architecture map

### 6.1 Controllers / routes

| Route | Controller | Role |
|-------|------------|------|
| `POST /admin/gmail/sync-now` | `GmailAdminActionsController` | Manual sync |
| `POST /admin/gmail/rebaseline` | same | Reset history cursor |
| `GET /admin/gmail/logs` | same | Sync logs UI |
| `GET /admin/gmail/failed-messages` | same | Failure list |
| `GET …/incoming-email-messages/{id}/content` | `IncomingEmailContentController` | Live content |
| `…/reply-context`, `reply-preview`, `reply` | same | Operational reply |
| `…/attachments/{attachment}` | same | Attachment download |
| `…/email-thread`, `…/email-thread/read` | `Customer360Controller` | Thread + read state |

No inbound webhook in `routes/api.php`.

### 6.2 Models / migrations

- `IncomingEmailMessage`, `IncidentIncomingEmailLink`  
- `GmailMailboxSyncState`, `GmailSyncMessageFailure`  
- `IncomingEmailIgnoreStat`  
- `OutgoingEmailMessage`  

Migrations from `2026_07_18_*` through `2026_08_03_190000_add_email_phase1_reply_and_classification`.

### 6.3 Services (inbound)

Provider / Gmail: `GmailApiClient`, `GmailAccessTokenService`, `GmailMessageMapper`, `GmailInboundEmailProvider`, `GmailSyncMetricsService`, sync/ingest/outbox/processor/filter/classifier/matcher/link/historical/create/assign/live-content/conversation/workspace-read-state/preview-extractor/order-visibility.

### 6.4 Services (outbound)

`OutgoingEmailReplyService`, `OutgoingEmailReplyGate`, `OutgoingEmailMimeBuilder`, `OutgoingEmailTemplatePreviewService`  
Plus notification stack: `EmailChannel`, `NotificationMailSender`, `NotificationMailTemplateRegistry`, `NotificationMail`.

### 6.5 Jobs / events / observers

- **No dedicated inbound Laravel Job class** — outbox processor dispatches processing.  
- **No inbound Event/Listener/Observer** for case close.  
- Finance/notification events are unrelated to intake.

### 6.6 Scheduler / commands

- `inbound-email:sync-gmail` — `SyncGmailInboundEmailCommand`  
- `outbox:process` — processes `email.inbound.process` among other outbox types  

### 6.7 Config / permissions

- `config/inbound_email.php` — master switches, mailboxes, filters, Gmail, reply  
- Permission: `email.reply`  
- Settings: `assignment.communication_intake_primary_user_id`, `…_fallback_user_id`

### 6.8 UI assets

- `resources/views/admin/operations/partials/gmail-health.blade.php`  
- `resources/views/admin/gmail/*.blade.php`  
- `resources/views/customer-360/partials/service-case-email-modal.blade.php`  
- `resources/views/customer-360/partials/incoming-email-modal.blade.php`  
- `resources/js/service-case-email-workspace.js`, `incoming-email-modal.js`  
- Notification Blade templates under `resources/views/emails/notifications/`

### 6.9 Assignment dead contracts

- `app/Enums/Assignment/EmailAssignmentClassification.php`  
- `app/Support/Assignment/Strategies/EmailTriageAssignmentStrategy.php`  
- `UniversalAssignmentEngine::assignForEmailClassification`

---

## 7. Incoming email deep dive

| Topic | Finding |
|-------|---------|
| Entry | Gmail History API pull on schedule (default every 1 minute when enabled) |
| Webhook / Push | **None** |
| Polling | **Yes** — sole production intake |
| historyId | Stored per mailbox in `gmail_mailbox_sync_states` |
| MessageId | RFC Message-ID normalized + unique; also Gmail provider id |
| Dedupe | Global Message-ID then (provider, provider_message_id) |
| Threading | `thread_id`; prior linked message in thread can resolve active SC |
| Attachments | Metadata in `raw_payload`; binary fetched live from Gmail |
| Labels | Persisted; SPAM/TRASH/CATEGORY_PROMOTIONS/CATEGORY_SOCIAL ignored |
| Search | No Desk-side mailbox search product |
| Sync | Incremental only; expired history → rebaseline without backfill |

---

## 8. Outgoing email deep dive

| Feature | Status |
|---------|--------|
| Templates (system notifications) | Implemented (12+ Blade notification templates) |
| Sending (system) | Laravel Mail via `EmailChannel` |
| Reply (agent, Gmail API) | Implemented behind flag |
| Reply-All | **Absent** |
| Forward | **Absent** |
| Draft | **Absent** |
| Signature | **Absent** (Gmail handles) |
| Tracking | Audit + `OutgoingEmailMessage` status; notification channel audits |
| Delivery failures | Recorded; Gmail HTTP retries; no agent resend queue |
| Queue | Outgoing reply is **synchronous**; notification mail is **synchronous** despite `Queueable` trait on mailable |

---

## 9. Current automation vs desired Phase 1

| Desired Phase 1 behavior | Current behavior | Gap |
|--------------------------|------------------|-----|
| Email arrives in Gmail | Yes (external) | — |
| AI classifies | Keyword + label rules | **Missing AI** |
| Existing order + existing case → reopen if closed, raise priority, notify assignee | Active case only: link + boost + notify. **Closed not reopened** | **Reopen missing** |
| Existing order + no case → create SC | Optional flag; else Historical | Flag / default |
| Sales enquiry → sales ticket + RR | PossibleSalesLead → NeedsReview or INQ+SC with Intake assign | **No sales RR; weak sales ticket type** |
| Unknown support → support ticket + RR | Same Intake primary/fallback | **No RR** |
| Spam → dashboard counter only | Ignored + DB stat | **Counter UI missing** |
| Promotional → dashboard counter only | Ignored + DB stat | **Counter UI missing** |
| Auto replies → dashboard counter only | Ignored + DB stat | **Counter UI missing** |
| C360 reply with templates; store conversation | Reply + thread + outgoing rows | Flag/pilot; good direction |
| Attachments: metadata + open Gmail | Metadata + **Desk download** | **Open-Gmail missing; download is extra** |

---

## 10. AI

| Question | Answer |
|----------|--------|
| Existing AI email classification? | **No** |
| Prompting for inbound? | **No** |
| ERA integration for email? | **No** |
| What exists? | `IncomingEmailClassifierService` — deterministic |
| Partially built AI? | **None found** for email |

Assignment-side “future email classification” enums/strategies are stubs, not AI.

---

## 11. Customer360 integration

| Surface | Status |
|---------|--------|
| Timeline (inbound linked/historical) | Yes |
| Timeline (outbound sent) | Yes |
| Conversation workspace | Yes |
| Orders / incidents context | Via linked `order_id` / `incident_id` |
| Refunds | Not special-cased in email UI; refund mailbox maps classification |
| Attachments | Listed; downloadable in Desk |
| Open original in Gmail | **Not implemented** |

---

## 12. Attachments

| Topic | Finding |
|-------|---------|
| Storage | Metadata only in Desk DB |
| Full body | Live Gmail fetch |
| Download | `IncomingEmailContentController::downloadAttachment` streams from Gmail |
| Preview | No dedicated previewer |
| Size limits / AV / allowlist | **Not found** |
| Retention | Depends on Gmail retention; Desk does not keep binaries |

**Philosophy fit:** Metadata-only storage is correct. Streaming download into Desk is the main Gmail-clone drift.

---

## 13. Health / monitoring

| Signal | Present? |
|--------|----------|
| Gmail sync health widget | Yes |
| API / OAuth status | Yes (per first mailbox) |
| Cursor lag | Yes |
| Processed / failed / skipped today | Yes |
| Failed message list | Yes |
| Ignore-reason dashboard counters | **DB only — no product UI** |
| Outgoing reply failure backlog | Weak / not in health widget |
| Laravel notification mail health | Separate; not Gmail widget |

---

## 14. Permissions summary

| Action | Who |
|--------|-----|
| Read linked email content | Users who can view related Incident/Order |
| Reply | `email.reply` roles **or** SC assignee |
| Learning Center view | `email-intake.view` (Admin, Ops Admin, Support roles by default) |
| Learning Center teach / disposition | `email-intake.manage` |
| Assign (intake) | System automation via Intake settings |
| Delete email | **No Desk delete product** (Gmail retains authority) |
| View attachments | Same as read content (+ download endpoint) |
| Admin sync | System settings updaters (not Learning Center) |

---

## 15. Feature readiness matrix

| Feature | Classification |
|---------|----------------|
| Gmail History sync | Production Ready |
| Ingest + dedupe + outbox | Production Ready |
| Ignore filters | Production Ready |
| Link active SC + notify | Production Ready |
| Communication Intake ownership | Production Ready |
| Auto-create SC | Partial (flagged) |
| Historical association | Production Ready |
| NeedsReview status | Partial (no triage UI; intentional) |
| Deterministic classifier | Production Capable |
| AI classifier | Absent |
| Reopen closed SC | Absent |
| Round-robin sales/support | Absent (unused strategy exists) |
| Ignore dashboard counters | Partial (data only) |
| C360 thread + read state | Production Ready / Partial realtime |
| Gmail reply + templates | Pilot / Partial |
| Notification mail templates | Production Ready (system channel) |
| Attachment Desk download | Partial / misaligned with vision |
| Forward/draft/compose/inbox | Absent (correct) |
| EmailTriageAssignmentStrategy | Dead / Prototype |
| Pub/Sub watch | Absent |

---

## 16. Compare against product vision

**Target:** Email Intake · **Not** Gmail Clone.

| Feature family | Verdict | Recommendation |
|----------------|---------|----------------|
| Gmail sync intake | Fits vision | **Keep** |
| Outbox processing | Fits | **Keep** |
| Filter spam/promo/OOO | Fits | **Keep** + **Improve** counters UI |
| Link to active work | Fits | **Keep** |
| Auto-create SC | Fits | **Improve** (default-on for Phase 1 after AI/routing) |
| Communication Intake assign | Fits interim | **Improve** toward RR where vision requires |
| Deterministic classify | Partial fit | **Improve** → AI classify |
| C360 reply templates | Fits (work execution) | **Keep** / harden |
| Live body in modal | Borderline | **Keep minimal**; don’t expand |
| Attachment download in Desk | Clone drift | **Remove/replace** with open-in-Gmail |
| Inbox / search / labels UI | Clone | **Never build** |
| Draft/forward/reply-all | Clone | **Never build** |
| Unused triage strategies | Noise | **Remove or wire once** — don’t leave two truths |
| Needs Review inbox | Parallel queue | **Never build** |

---

## 17. Phase 1 — Minimal Email Intake workflow (design only)

Design target (no implementation in this investigation):

```
Gmail (source of truth mailbox)
        ↓
Desk sync (History API) — keep
        ↓
AI classify  ← NEW
        ↓
┌─ Existing order + open/active case
│     link · raise priority · notify assignee
├─ Existing order + closed case
│     REOPEN · raise priority · notify assignee   ← NEW
├─ Existing order + no case
│     create Service Case · assign
├─ Sales enquiry
│     create sales ticket · round robin            ← NEW routing
├─ Unknown support
│     create support ticket · round robin          ← NEW routing
├─ Spam / Promotional / Auto-reply
│     ignore · dashboard counter only              ← NEW UI
└─ C360
      reply via templates · send via Gmail API
      store conversation rows
      attachments: metadata + open Gmail message   ← CHANGE
```

### Phase 1 principles

1. Desk never becomes the mailbox.  
2. Every actionable email ends on a Service Case (or sales ticket that is still a Desk work item).  
3. Ignored classes never create work — only counters.  
4. Gmail owns MIME, labels, attachment viewing, search.  
5. Reuse: sync, ingest, filter, link, reply, health — do not rewrite the spine.

### Map to existing code

| Phase 1 step | Reuse | Build |
|--------------|-------|-------|
| Arrive / sync | Existing Gmail sync | — |
| Classify | Filter + classifier hooks | AI classifier service |
| Active case | `IncomingEmailLinkService` + priority + notify | — |
| Closed case | Matcher today skips closed | Reopen path in matcher/processor |
| No case | `IncomingEmailServiceCaseCreateService` | Enable + harden |
| Sales / unknown RR | Create services + category mapper | Replace Intake-only assign for those branches with RR |
| Spam/promo/OOO counters | `IncomingEmailIgnoreStat` | Dashboard widgets |
| C360 reply | `OutgoingEmailReplyService` | Enable carefully; open-Gmail link |
| Attachments | Metadata already | Drop primary download UX; add Gmail deep link |

---

## 18. Missing features backlog

### Immediate (Phase 1 blockers)

| Item | Why |
|------|-----|
| AI classification (or stronger rules + human override) | Vision step 2; keyword rules misroute sales/support |
| Reopen closed SC on inbound | Vision step 3; today creates historical/new instead |
| Dashboard counters for spam / promo / auto-reply | Vision steps 7–9; data exists, UI does not |
| Open-in-Gmail for message/attachments | Vision attachments; prevents clone drift |
| Decide assignment: Intake vs RR for new sales/support | Vision steps 5–6 conflict with current Intake-only design |

### Next

| Item | Why |
|------|-----|
| Enable auto-create by default after reopen+classify | Completes “every actionable email → case” |
| Multi-mailbox health aggregation | Ops correctness |
| Failed-message replay | Resilience after cursor skip |
| Reply send retry / failure UX | Pilot → production |
| Telegram/push for new email (optional) | Today in-app DB notification only |

### Future

| Item | Why |
|------|-----|
| Gmail Pub/Sub push | Lower latency than 1-min poll — optional |
| Richer AI (intent, language, urgency) | After basic classify works |
| VIP / SLA boost rules | Business overlay on intake |
| Separate sales work type beyond SC category | If Sales Lead must leave Service Case model |

### Never build

| Item | Why |
|------|-----|
| Full Gmail inbox in Desk | Violates philosophy |
| Label manager / mailbox search / folders | Gmail’s job |
| Drafts, forward, reply-all, compose-new, signatures | Gmail’s job |
| Local attachment CDN/preview suite | Duplicate Gmail; security/retention cost |
| Needs Review parallel inbox | Two queues; superseded |
| IMAP dual-stack while Gmail DWD works | Complexity without product value |

---

## 19. Answers to the six objective questions

| # | Question | Answer |
|---|----------|--------|
| 1 | What is already implemented? | Full Gmail pull intake, filter, match, link, optional auto-create, Intake assignment, C360 thread, flagged reply, admin health, system notification mail |
| 2 | What is partially implemented? | Auto-create (flag), ignore counters (no UI), reply (pilot), closed-case handling, attachment UX, multi-mailbox health |
| 3 | What is production ready? | Sync/ingest/filter/link/notify/health when flags+DWD configured; system notification mail |
| 4 | What is obsolete? | Needs Review inbox plan; unused email triage assignment contracts; dropped communication template store |
| 5 | What should be removed? | Gmail-clone attachment viewer path; unused dual assignment strategies (or wire one); temp OAuth diagnostics |
| 6 | What should be Phase 1? | Minimal Email Intake: AI classify → reopen/link/create → RR for sales/unknown → counters for noise → C360 template reply → attachment metadata + open Gmail |

---

## 20. Risk notes (investigation only)

- Enabling `auto_create_service_case` without reopen logic will **multiply cases** after closure instead of reopening.  
- Enabling reply requires Workspace DWD `gmail.send` + mailbox allowlist + `email.reply`.  
- Live content depends on Gmail retention; deleted Gmail messages cannot be shown.  
- Classifier treating unmatched mail as `PossibleSalesLead` by default can misfile pure support unknowns.

---

## Investigation method

Repository-wide static analysis of controllers, services, models, migrations, config, routes, scheduler, UI, tests, and prior email docs. No production queries. No code changes. No Canvas.
