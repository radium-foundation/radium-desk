# SC26557 — Why is this case in Ready Queue?

**Date:** 2026-08-06  
**Priority:** P0 production (read-only)  
**Status:** Root cause proven · no code or production changes made  
**Prod HEAD:** `61de1e49`  
**Timezone:** Asia/Kolkata (IST)  
**Canvas:** [`sc26557-ready-queue-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/sc26557-ready-queue-investigation.canvas.tsx)

---

## Bottom line

SC26557 is in Ready Queue because it was **incorrectly reopened** at **2026-08-06 10:04:12 IST** by inbound-email automation that treated an **outbound Gmail SENT message** from `mail@radiumbox.com` (wallet-credit reply to the customer) as customer inbound mail.

After that reopen, Ready Queue classification is **mechanically correct**: status `open`, serial validated (`pass`), RadiumBox `SYNCED`, no active hold, order not transaction-locked → `isReadyForReferenceEntry() === true` → primary queue `action_required`.

**Business expectation:** the case **SHOULD NOT** be open or in Ready Queue — refund **REF-2026-000138** completed and closed the case the previous day.

**Root defect:** own-outbound echo detection does not cover the synced mailbox `mail@radiumbox.com`, so SENT mail from that address is processed as inbound and triggers Phase 1.1 closed-case reopen.

---

## Identity

| Entity | Value |
|--------|-------|
| Service case | **SC26557** (`incidents.id` = 26633) |
| Case status (now) | `open` |
| High priority | `true` (Cashfree-created; not set by this reopen) |
| Assignee (now) | Shipra (user 3) — roles: `admin`, `operations_admin` |
| Assignment origin | `manual` |
| Order | **RD3474549** (`orders.id` = 26072) |
| Order status | `active` |
| Serial / model | `8628029` / MFS 110 (`device_model_id` = 1) |
| Customer | DUSHMANTA KESHORI ROUT · 9903346319 · dkrout4@gmail.com |
| Refund | **REF-2026-000138** — status **`closed`** (wallet, ₹499) |
| Primary queue | `action_required` (Ready Queue) |
| In dashboard Ready bucket | **Yes** (among 35 Ready cases at investigation time) |
| SLA overlay | `overdue` (badge only; does not gate Ready membership) |
| Derived automation status | `assigned_to_admin` |

---

## Expected vs actual

| Question | Answer | Evidence |
|----------|--------|----------|
| Is SC26557 **currently** classified Ready? | **Yes** | `OperationsQueueClassifier::classify` → `action_required`; `DashboardSnapshotStore` Ready bucket contains id 26633 |
| Do Ready eligibility gates pass? | **Yes — all** | See gate table below |
| Should it be in Ready Queue **operationally**? | **No** | Refund completed + case closed 2026-08-05 17:28:12; reopen trigger was our own SENT mail |
| Is the Ready Queue engine buggy for this case? | **No** | Classifier correctly reflects post-reopen state |
| Where is the bug? | **Inbound email own-outbound + reopen** | Message 179234: `from=mail@radiumbox.com`, Gmail label `SENT`, classification `existing_customer`, then `incoming_email.case_reopened` |

---

## Ready Queue eligibility — matching rule

Ready membership is **computed**, not stored. Primary path:

```
DashboardSnapshotStore (operationally active incidents)
  → OperationsQueueClassifier::computeClassification()
       … after Completed / Hardware / BusinessHold / WaitingCustomer / Scheduled …
       → isReadyForReferenceEntry()
            → OperationQueue::ActionRequired  ("Ready Queue")
  → Admin visibility: ServiceCaseAssignmentService::isVisibleInAdminReadyQueue()
```

### Gate evaluation for SC26557 (live production)

| Condition (`ServiceCaseAssignmentEligibilityService::isReadyForReferenceEntry`) | Expected for Ready | Actual | Pass? |
|--------------------------------------------------------------------------------|--------------------|--------|-------|
| No active business hold | false hold | hold #103 cleared 2026-08-05 17:28:12 | Yes |
| `incident->isActive()` | true | status `open` | Yes |
| `incident->isPendingAdmin()` | true | `orders.transaction_id` null | Yes |
| Order ID filled | true | `RD3474549` | Yes |
| Not hardware / not inquiry | true | RD service order | Yes |
| Serial filled & not placeholder | true | `8628029` | Yes |
| Model identity present | true | `device_model_id=1`, MFS 110 | Yes |
| Serial validation severity | ≠ Fail | **`pass`** / status `valid` | Yes |
| RadiumBox enrichment sync | Synced or NotSynced | **`SYNCED`** | Yes |
| Admin Ready visibility | visible | assignee is admin (not support-queue ownership) → always visible | Yes |

**Matching rule that placed it in Ready Queue:** primary classification `isReadyForReferenceEntry` after reopen — not a cache ghost, not dual Scheduled overlay, not a manual queue column.

---

## Root cause (causal chain)

```text
2026-08-05 17:28  Refund REF-2026-000138 completed (wallet)
                  → RefundCaseCloseService closes SC26557
                  → case status = closed  (no ServiceCaseCloseOutcome row written)

2026-08-06 10:04  Operator/system sends wallet-credit reply from mail@radiumbox.com
                  → Gmail label SENT, thread_id = same as customer refund thread

2026-08-06 10:04  Gmail sync (INBOUND_EMAIL_GMAIL_MAILBOXES=mail@radiumbox.com)
                  ingests message id 179234

                  IncomingEmailIngestService::isOwnOutboundEcho():
                    • Not in OutgoingEmailMessage (manual Gmail send)
                    • from_email mail@radiumbox.com NOT in config inbound_email.mailboxes keys
                      (only support/service/refund/sales)
                    → NOT ignored → status=received → processed as inbound

2026-08-06 10:04  Matcher finds closed_incident SC26557 (thread/order)
                  IncomingEmailClosedCaseReopenService::isReopenable():
                    • closed ✓ · has order ✓
                    • latestCloseReason = null (refund close never wrote close outcome)
                    • not customer_cancelled / duplicate
                    → REOPENS case → status open, owner Shipra restored

2026-08-06 10:04+ Serial still valid, hold cleared, pending admin
                  → Ready Queue classifier returns action_required
                  → SC26557 appears in Ready Queue
```

### Config mismatch (smoking gun)

| Config | Production value |
|--------|------------------|
| `inbound_email.gmail.sync_mailboxes` | `["mail@radiumbox.com"]` |
| `inbound_email.mailboxes` keys (own-outbound allowlist) | `support@`, `service@`, `refund@`, `sales@` only |
| `from_email` of message 179234 | `mail@radiumbox.com` |
| `from_email` ∈ mailboxes keys? | **NO** |
| `OutgoingEmailMessage` match on provider/rfc id? | **0 rows** |
| Gmail labels | `["SENT"]` |

Own-outbound detection (`IncomingEmailIngestService::isOwnOutboundEcho`) only trusts:
1. Row in `outgoing_email_messages` by `provider_message_id`, or  
2. `from_email` ∈ keys of `inbound_email.mailboxes`

Synced mailbox `mail@radiumbox.com` is in neither path for this send → echo processed as customer mail.

### Secondary gap — refund closes are always reopenable

`IncomingEmailClosedCaseReopenService::isReopenable()` blocks only close reasons `customer_cancelled` and `duplicate_case` from `service_case_close_outcomes`.

`RefundCaseCloseService::closeLinkedCase()` closes via `ServiceCaseStatusService::updateStatus(Closed)` + system remark — it does **not** write a `ServiceCaseCloseOutcome`. For SC26557, `latestCloseReason = null`, so refund-closed cases remain reopenable by any matched “inbound” including own SENT echoes.

---

## Timeline (chronological, IST)

| Time | Event | Actor / source |
|------|-------|----------------|
| 2026-08-05 12:13:02 | Case + order created (Cashfree payment RD3474549); automation grace 60s; payment_received | System (Ira / Cashfree) |
| 12:13:19 | Waiting RadiumBox | Automation |
| 12:14:02 | RadiumBox verified; device model MFS 110 assigned | Automation |
| 12:14:03 | Validation failed → waiting manual correction (no serial yet) | Automation |
| 12:30:57–58 | WhatsApp + email `request_serial_number`; customer waiting started (`serial_number`) | Scheduler / missing-serial automation |
| 13:42:07 / 13:42:33 | Customer email: “Kindly refund money…” linked (msg **179102**, classification `refund`) | dkrout4@gmail.com → ingest |
| 13:49:25 | Auto-assigned to Vanshika (user 11); missed-call recovery merged | Automation |
| 13:51:41 | Missed call answered/attached | Automation |
| 13:56:17 | Serial `8628029` entered; waiting cleared; status `awaiting_product_details` → `open` | Gaurav Kumar (user 7) |
| 13:56:17–18 | Auto-reassigned to shift admin (user 2); `validation_passed` | Automation (actor stamped Gaurav/Ravi) |
| 13:58:33 | Refund REF-2026-000138 requested; business hold #103 activated | Gaurav Kumar |
| 13:59:00 | Manual reassign to Shipra (user 3); note “SR NO NOT UPDATE…” | Gaurav Kumar |
| 17:27:50 | Refund reviewed/approved (wallet) | Shipra |
| 17:28:12 | Refund executed + **closed**; hold cleared; case **closed**; remark refund_close | Shipra / RefundCaseCloseService |
| **2026-08-06 10:04:02** | **Outbound** wallet-credit email from Radium Box → customer (Gmail **SENT**) | mail@radiumbox.com |
| **10:04:12** | Message **179234** ingested + processed; case **reopened**; email linked (`existing_customer`) | Inbound email automation |
| 10:04:12+ | Case operationally active + Ready-eligible → appears in Ready Queue | Ready Queue classifier |

### Communications

| Channel | When | Detail |
|---------|------|--------|
| WhatsApp | 2026-08-05 12:30:57 | `request_serial_number` / template `support_schedule` — sent |
| Email (outbound notify) | 2026-08-05 12:30:58 | Same request-serial notification — sent |
| Email (customer inbound) | 2026-08-05 13:42 | Refund request — legitimately linked |
| Email (own SENT, mis-ingested) | 2026-08-06 10:04 | Wallet credit “Dear Sir, amount credited to Radium Wallet…” — **reopen trigger** |

### Manual actions (status / owner / priority / queue)

| When | User | Change |
|------|------|--------|
| 13:56:17 | Gaurav | Serial assigned (order); status promoted via validation path |
| 13:59:00 | Gaurav | Manual reassign → Shipra (`assignment_origin=manual`) |
| 17:28:12 | Shipra | Refund complete → case closed (via refund service, not workspace close form) |
| — | — | **No manual reopen.** Reopen was automation (`incoming_email.case_reopened`, user_id=1 system actor “Ravi”) |
| — | — | Queue is not a stored field; no manual queue edit exists |

---

## Automation checklist

| Mechanism | Involved? | Role |
|-----------|-----------|------|
| Workflow / identity validation | Earlier (13:56) | Put case into Ready the first time after serial entry |
| Grace / scheduler missing-serial | Earlier (12:30) | Customer waiting + notifications |
| RadiumBox enrichment | Earlier | Model sync; current status SYNCED |
| Gmail sync + ingest | **Yes — root** | Ingested SENT echo from `mail@radiumbox.com` |
| Closed-case reopen (Phase 1.1) | **Yes — root** | Reopened closed SC26557 |
| Ready Queue classifier / snapshot | **Yes — consequence** | Correctly buckets reopened active case |
| Queue refresh / Reverb | Indirect | Snapshot invalidated on reopen (`dashboardSnapshotStore->forget`) |
| Refund close | Prior day | Legitimate close before bad reopen |
| Background Ready backfill | No evidence | Not required; live eligibility is sufficient |

---

## Ready Queue engine notes

| Item | Finding |
|------|---------|
| Service | `ServiceCaseAssignmentEligibilityService::isReadyForReferenceEntry` + `OperationsQueueClassifier` |
| SQL load | Active incidents (`open` / `in_progress` / `awaiting_product_details`) via `DashboardSnapshotStore` |
| Cache | Request + short-TTL `OperatorDashboardCache` — membership recomputed from live row; reopen forgets snapshot |
| Snapshots | Not a stale snapshot of a closed case — case is truly `open` again |
| Admin filter | Visible (admin assignee; manual-support hide path N/A) |

---

## Evidence (production queries)

- `Incident` 26633 / `reference_no` SC26557 — status `open`, assignee 3, origin `manual`
- `AuditLog` 810208 — `service_case.status_changed` open→closed (2026-08-05 17:28:12, Shipra)
- `AuditLog` 819531 — `service_case.status_changed` closed→open (2026-08-06 10:04:12, system)
- `AuditLog` 819532 — `incoming_email.case_reopened` with `incoming_email_message_id=179234`
- `IncomingEmailMessage` 179234 — `from_email=mail@radiumbox.com`, labels `SENT`, snippet wallet-credit text
- Config: sync mailbox ≠ own-outbound mailbox keys (see table above)
- Live: `isReadyForReferenceEntry=true`, `in_dashboard_ready_bucket=true`

Investigation method: read-only SSH + `php artisan tinker` via `tools/config.sh` (`desk.radiumbox.com` / `radium-desk`). No writes.

---

## Files / services involved

| File | Role |
|------|------|
| `app/Services/IncomingEmail/IncomingEmailIngestService.php` | `isOwnOutboundEcho()` — failed to ignore SENT from `mail@` |
| `config/inbound_email.php` | `mailboxes` vs `gmail.sync_mailboxes` mismatch |
| `app/Services/IncomingEmail/IncomingEmailProcessorService.php` | Routes closed match → reopen |
| `app/Services/IncomingEmail/IncomingEmailClosedCaseReopenService.php` | Reopen eligibility + orchestration |
| `app/Services/RefundCaseCloseService.php` | Closes case without close-outcome reason |
| `app/Services/ServiceCaseStatusService.php` | `reopen()` / close status |
| `app/Services/ServiceCaseAssignmentEligibilityService.php` | Ready eligibility gates |
| `app/Services/Operations/OperationsQueueClassifier.php` | Queue bucket `action_required` |
| `app/Services/Dashboard/DashboardSnapshotStore.php` | Active incident load |
| `app/Services/Dashboard/DashboardSnapshot.php` | Ready bucket + admin visibility filter |
| `app/Services/ServiceCaseAssignmentService.php` | `isVisibleInAdminReadyQueue` |
| `docs/email-intake-phase1-1-closed-case-reopen.md` | Phase 1.1 reopen design (shipped 2026-08-05) |

---

## Recommended fix (do not implement in this investigation)

Priority order:

1. **Own-outbound coverage for sync mailboxes (P0)**  
   Treat `from_email` ∈ `inbound_email.gmail.sync_mailboxes` (and/or Gmail `SENT` label / From == mailbox) as `own_outbound` ignore in `IncomingEmailIngestService::isOwnOutboundEcho`.  
   Also consider adding `mail@radiumbox.com` to the own-outbound set used in production, or stop syncing SENT-heavy catch-all without that guard.

2. **Refund-close reopen policy (P0/P1)**  
   On refund-completed close, write a `ServiceCaseCloseOutcome` (or explicit reopen block) so refund-closed cases are not auto-reopened by email echoes. At minimum, ignore own-outbound so this specific path dies.

3. **Operational cleanup for SC26557**  
   After confirming no real customer follow-up is needed: re-close the case (with audit note referencing this investigation). Do **not** “fix” by hacking Ready Queue rules.

4. **Regression tests**  
   - Ingest from sync mailbox address with SENT label → ignored `own_outbound`, no reopen  
   - Refund-closed case + matched inbound echo → no reopen (once policy chosen)

---

## Risk assessment

| Risk | Level | Notes |
|------|-------|-------|
| Wrong Ready Queue membership for SC26557 | **High (localized)** | Wastes admin Ready attention; case already refunded |
| Class of bug: own SENT → reopen closed cases | **High (systemic)** | Any closed case on a thread where `mail@radiumbox.com` sends a reply can reopen if sync picks it up |
| Ready Queue classifier integrity | Low | Working as designed |
| Data loss / finance | Low | Refund already closed; reopen does not reverse wallet credit |
| Fix blast radius | Medium | Own-outbound expansion is low risk if scoped to sync/from mailbox; refund reopen policy needs product confirmation |

---

## Conclusion

SC26557 is in Ready Queue **because an outbound wallet-credit email from `mail@radiumbox.com` was ingested as inbound mail and reopened a refund-closed case**. Ready Queue then correctly classified the reopened, validated, hold-free case as `action_required`.

Fix the ingest/reopen path — not the Ready Queue eligibility rules.
