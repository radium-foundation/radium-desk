# SC28000 — Why is this case in Ready Queue after assignment?

**Date:** 2026-08-07  
**Priority:** P0 production (read-only)  
**Status:** Root cause proven · no code or production changes made  
**Prod HEAD:** `2c55a190`  
**Timezone:** Asia/Kolkata (IST)  
**Canvas:** [`sc28000-ready-queue-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/sc28000-ready-queue-investigation.canvas.tsx)

---

## Bottom line

**Expected behaviour — not a bug.**

SC28000 is visible in Admin Ready Queue because **Admin Ready Identity Republish** deliberately re-showed it after `service_case.automation.validation_passed` at **2026-08-07 12:56:35**, while **preserving Jayram’s manual support ownership**.

Assignment claim “Assigned By Dileep” is **false**. Production audit shows **Avinash Jha** manually reassigned the case to **Jayram Kumar** on **2026-08-06 14:10:46**. Dileep only viewed AI workbench suggestions later.

Commercial state is **Open**. Service Reference is not assigned (`transaction_id` null) → still pending admin / Ready-eligible.

---

## Identity

| Entity | Value |
|--------|-------|
| Service case | **SC28000** (`incidents.id` = 28072) |
| Case status (now) | `open` |
| High priority | `true` (Cashfree-created) |
| Assignee (now) | Jayram Kumar (user 5) — roles: `agent`, `support_specialist`, `customer_coordinator` |
| Assignment origin | `manual` |
| Who assigned Jayram | **Avinash Jha** (user 2, admin) — not Dileep |
| Order | **RD3476656** (`orders.id` = 27449) |
| Order status | `active` |
| Serial / model | `6540662` / MFS 110 (`device_model_id` = 1) |
| `transaction_id` | `null` |
| Commercial state | `open` (commercial work allowed; no refund) |
| Primary queue | `action_required` (Ready Queue) |
| In dashboard Ready bucket | **Yes** (among 96 Ready cases at investigation time) |
| RadiumBox sync | `SYNCED` |
| Validation | `pass` |

---

## Verdict matrix

| Question | Answer | Evidence |
|----------|--------|----------|
| Is SC28000 **currently** classified Ready? | **Yes** | `OperationsQueueClassifier` → `action_required` |
| Do Ready eligibility gates pass? | **Yes — all** | See gate table |
| Does Admin Ready visibility pass? | **Yes** | Manual support ownership + `validation_passed` after that ownership |
| Is this a cache / snapshot ghost? | **No** | Live DB + live classify + snapshot Ready bucket all agree |
| Should assignment alone remove it from Ready? | **No (current product rule)** | Hide only until post-ownership `validation_passed`; then republish for Service Reference |
| Bug or expected? | **Expected** | Matches `AdminReadyIdentityRepublishTest` / `isVisibleInAdminReadyQueue` |

---

## Exact root cause

```text
2026-08-06 14:10  Avinash manually assigns SC28000 → Jayram (origin=manual)
                  → hasManualSupportOwnership = true
                  → no validation_passed after that audit yet
                  → Admin Ready HIDDEN (correct)

2026-08-07 12:56  RadiumBox enrichment re-runs
                  → status awaiting_product_details → open
                  → ready_queue_owner_preserved (Jayram kept; Ready must not steal manual ownership)
                  → validation_passed (audit 859270) — first validation_passed on this case

                  isVisibleInAdminReadyQueue():
                    hasManualSupportOwnership? yes
                    isReadyForReferenceEntry? yes
                    hasIdentityValidationPassAfterManualSupportOwnership? yes
                  → Admin Ready REPUBLISHED (by design)

                  transaction_id still null → pending admin → stays Ready until Service Reference
```

### Matching rule

Ready membership is computed, not stored:

```
isReadyForReferenceEntry() → OperationQueue::ActionRequired
  + ServiceCaseAssignmentService::isVisibleInAdminReadyQueue()
      (manual support ownership hide → republish after later validation_passed)
```

Relevant implementation comments:

- `ServiceCaseAssignmentService::isVisibleInAdminReadyQueue` — “Manual support ownership hides Admin Ready until identity lifecycle records `validation_passed` AFTER that ownership began AND the case is still Ready-eligible for Service Reference.”
- `ready_queue_owner_preserved` — Ready must not overwrite human ownership.

---

## Ready Queue eligibility — live gates

| Condition | Expected for Ready | Actual | Pass? |
|-----------|--------------------|--------|-------|
| No active business hold | false hold | none | Yes |
| `incident->isActive()` | true | status `open` | Yes |
| `incident->isPendingAdmin()` | true | `transaction_id` null | Yes |
| Order ID filled | true | `RD3476656` | Yes |
| Not hardware / not inquiry | true | RD service | Yes |
| Serial + model | present | `6540662` / MFS 110 | Yes |
| Serial validation severity | ≠ Fail | `pass` | Yes |
| RadiumBox sync | Synced or NotSynced | `SYNCED` | Yes |
| Admin Ready visibility | visible | republish after validation_passed | Yes |

---

## Commercial state

| Field | Value |
|-------|-------|
| Resolver state | `open` |
| Presented label | Open |
| Summary | Commercial work is allowed |
| Refund | none |
| Blocks Assign Ref? | **No** |

Commercial state does **not** explain Ready membership here; it is consistent with allowing Service Reference work.

---

## Assignment state (correcting the incident report)

| Claim | Production fact |
|-------|-----------------|
| Assigned By Dileep | **False.** User 4 (Dileep Sen) has only `ai_workbench.suggestion_viewed` on this case (13:49–13:54 IST today). Zero assign/reassign audits by Dileep. |
| Assigned To Jayram | **True.** `assigned_to_user_id = 5`, `assignment_origin = manual` |
| Actual assigner | **Avinash Jha** at 2026-08-06 14:10:46 (`service_case.reassigned`, audit 831753) |

Likely confusion: Dileep opened the case in AI workbench while it was already in Ready Queue after today’s republish.

---

## Cache / snapshot / live payload

| Check | Result |
|-------|--------|
| DB status | `open` |
| Live `isReadyForReferenceEntry` | `true` |
| Live `isVisibleInAdminReadyQueue` | `true` |
| `shouldRemoveFromAdminReadyQueue` | `false` |
| `DashboardSnapshot` Ready bucket contains 28072 | **Yes** |
| Live `recentServiceCases(action_required)` for admin | **Contains SC28000** |
| Admin Ready assignee scope | Team-shared (`assigned_to` null) — cross-admin visibility intentional |

Not a stale cache row of a non-Ready case.

---

## Timeline (chronological, IST)

| Time | Event | Actor |
|------|-------|-------|
| 2026-08-06 13:07:20 | Case + order created (Cashfree); status `awaiting_product_details` | Automation |
| 13:08:02–03 | RadiumBox enrichment; serial `6540662`; model MFS 110; verified | Automation |
| 13:09:02 | Auto-assigned to Avinash (shift admin); origin `auto` | Automation |
| 14:10:46 | Manual reassign → Jayram; origin `manual` → Admin Ready **hidden** | Avinash Jha |
| 14:59:51 | Missed-call recovery merged | Automation |
| **2026-08-07 12:56:01** | RadiumBox enrichment re-started | Automation |
| **12:56:02** | Status `awaiting_product_details` → `open`; `ready_queue_owner_preserved` (Jayram kept) | Automation |
| **12:56:35** | `validation_passed` → Admin Ready **republished** | Automation |
| 12:56:59 | Enrichment completed (`service_history`) | Automation |
| 13:49–13:54 | AI workbench suggestion viewed (no assignment) | Dileep Sen |

---

## Visibility window

| Window | Admin Ready visible? | Why |
|--------|----------------------|-----|
| After Avinash auto-own (13:09–14:10) | Would be visible if Ready-eligible (admin assignee; no support-hide) | Admin assignee path |
| After manual → Jayram (14:10 → 12:56 next day) | **Hidden** | Manual support ownership, zero `validation_passed` after audit 831753 |
| After `validation_passed` (12:56 → now) | **Visible** | Intentional republish for Service Reference |

---

## Ruled out

| Hypothesis | Finding |
|------------|---------|
| Snapshot/cache ghost | Live + snapshot + DB agree |
| Commercial block | `open`, work allowed |
| Queue filter bug | Admin Ready is intentionally unscoped across admins |
| Assignment should permanently remove Ready | Product rule is temporary hide until later validation pass |
| Ready stole ownership from Jayram | Opposite — owner preserved |

---

## Secondary observation (not the Ready bug)

From create until 12:56 today the case remained `awaiting_product_details` with **no** `validation_passed` despite Aug 6 enrichment applying serial + model. Today’s enrichment/identity path finally promoted it and wrote `validation_passed`. That delay explains *when* republish happened; the Ready visibility after that event is still **by design**.

---

## Evidence (production queries)

- `Incident` 28072 / `SC28000` — status `open`, assignee 5, origin `manual`, `transaction_id` null on order 27449
- `AuditLog` 831753 — `service_case.reassigned` Avinash→Jayram (manual)
- `AuditLog` 859245 — status APD→open
- `AuditLog` 859246 — `ready_queue_owner_preserved`
- `AuditLog` 859270 — `service_case.automation.validation_passed` (only one; after manual ownership)
- CommercialStateResolver → `open`
- Live: `in_dashboard_ready_bucket=true`, `visible_admin_ready=true`

Investigation method: read-only SSH + `php artisan tinker` via `tools/config.sh` (`desk.radiumbox.com` / `radium-desk`). No writes.

---

## Files / services involved

| File | Role |
|------|------|
| `app/Services/ServiceCaseAssignmentService.php` | `hasManualSupportOwnership`, `isVisibleInAdminReadyQueue`, republish refresh |
| `app/Services/ServiceCaseAssignmentEligibilityService.php` | `isReadyForReferenceEntry` gates |
| `app/Services/Operations/OperationsQueueClassifier.php` | Queue bucket `action_required` |
| `app/Services/OrderIdentityLifecycleService.php` | Calls Admin Ready refresh after identity change |
| `app/Services/Dashboard/DashboardSnapshot.php` | Ready bucket + Admin Ready filter |
| `tests/Feature/AdminReadyIdentityRepublishTest.php` | Codifies this exact behaviour |
| `app/Services/Commercial/CommercialStateResolver.php` | Commercial state `open` |

---

## Operational note (no code change in this investigation)

If the business intent is “manual assign to support agent permanently removes Admin Ready until Service Reference,” that would be a **product rule change** — current shipped behaviour is the opposite after a later identity `validation_passed`. Leaving Ready is accomplished by assigning Service Reference (`transaction_id`), not by support ownership alone.

---

## Conclusion

SC28000 is in Ready Queue because it became Ready-eligible again today (`validation_passed` + open + no Service Ref), and Admin Ready Identity Republish correctly re-listed it while keeping Jayram as owner. **Expected behaviour.** Assignment was by Avinash, not Dileep.
