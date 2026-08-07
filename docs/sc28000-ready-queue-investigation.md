# SC28000 — Why is this case in Ready Queue after assignment?

**Date:** 2026-08-07  
**Priority:** P0 production  
**Status:** Investigation complete · **business rule change implemented** (not a bug fix)  
**Investigation prod HEAD:** `2c55a190`  
**Timezone:** Asia/Kolkata (IST)  
**Canvas:** [`sc28000-ready-queue-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/sc28000-ready-queue-investigation.canvas.tsx)

---

## Bottom line

**Investigation:** Behaviour matched then-current code (Admin Ready Identity Republish), but the **business rule was wrong** and caused production loss — another admin could Assign Service Reference while the support engineer was intentionally waiting on the customer.

**Business changes (shipped in follow-ups):**

1. **SC28000 protection:** Background identity validation / RadiumBox / enrichment / commercial sync **must not** republish manually owned incidents into Admin Ready.
2. **Meaningful human identity edit:** If a support engineer **actually changes** serial and/or device model, the case **may** return to Admin Ready automatically (owner + origin preserved) when Service Reference is still missing.

Assignment claim “Assigned By Dileep” is **false**. Production audit shows **Avinash Jha** manually reassigned the case to **Jayram Kumar** on **2026-08-06 14:10:46**.

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
| Should assignment alone remove it from Ready? | **Yes while manual support ownership** | Stays hidden until meaningful human serial/model edit (or explicit return/requeue) |
| Matched then-current code? | **Yes** | Old `validation_passed` republish path |
| Business rule correct? | **No** | Caused production loss → rule changed |

---

## Exact root cause (investigation)

```text
2026-08-06 14:10  Avinash manually assigns SC28000 → Jayram (origin=manual)
                  → hasManualSupportOwnership = true
                  → no validation_passed after that audit yet
                  → Admin Ready HIDDEN (correct)

2026-08-07 12:56  RadiumBox enrichment re-runs
                  → status awaiting_product_details → open
                  → ready_queue_owner_preserved (Jayram kept)
                  → validation_passed (audit 859270)

                  OLD isVisibleInAdminReadyQueue():
                    hasManualSupportOwnership? yes
                    isReadyForReferenceEntry? yes
                    hasIdentityValidationPassAfterManualSupportOwnership? yes
                  → Admin Ready REPUBLISHED  ← wrong for business
```

### Matching rule (before change)

```
isReadyForReferenceEntry() → ActionRequired
  + isVisibleInAdminReadyQueue()
      (manual support ownership hide → republish after later validation_passed)
```

### Matching rule (after business change)

```
isReadyForReferenceEntry() → ActionRequired (internal eligibility unchanged)
  + isVisibleInAdminReadyQueue()
      hasManualSupportOwnership? → always HIDDEN
      else → visible (auto-assigned Ready unchanged)
```

Identity / enrichment / commercial sync / background jobs may still call
`refreshAdminReadyMembershipAfterIdentityValidation` (snapshot forget + broadcast)
but visibility stays **false** while manual support ownership exists.

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
| Admin Ready visibility (old rule) | visible after validation_passed | true (republish) | Old: Yes |
| Admin Ready after RadiumBox (new) | hidden | false | Protected |
| Admin Ready after engineer serial/model edit | visible if Ready-eligible | via manual_identity_ready_republish | Allowed |

---

## Business rule change — deliverable

### Root cause (for the loss)

Admin Ready Identity Republish treated post-ownership `validation_passed` (including RadiumBox enrichment) as permission to re-list the case for Service Reference, even though a support engineer still owned it manually and was waiting on the customer.

### Final workflow

```text
Admin assigns Jayram (manual support ownership)
  → leaves Admin Ready

Jayram waiting for customer
  → RadiumBox / auto validation / background sync
  → stay HIDDEN (SC28000)

Customer provides serial/model
Jayram edits serial and/or model (meaningful change)
  → identity validation runs
  → owner + origin preserved
  → if Service Ref missing + Ready-eligible
  → REAPPEAR in Admin Ready
```

### Before / after

| Step | Old (loss) | After SC28000 block | After meaningful-edit enhancement |
|------|------------|---------------------|-----------------------------------|
| Admin → support engineer | Leaves Ready | Leaves Ready | Leaves Ready |
| RadiumBox / auto validation | **Republishes** | Stays hidden | Stays hidden |
| Engineer changes serial/model | Republished (via validation_passed) | Stayed hidden | **Republishes** (intentional) |
| Same serial/model saved again | Could refresh | Hidden | No-op — stays hidden |
| Customer name/phone/email edit | N/A | Hidden | Stays hidden |
| Auto-assigned Ready + validation | Visible | Visible | Visible |
| Assign Service Reference | Removes Ready | Removes Ready | Removes Ready |

### Meaningful vs not

| Change | Republish? |
|--------|------------|
| blank → serial / serial A → B | Yes (human sources only) |
| blank → model / model A → B | Yes (human sources only) |
| Same serial or same model saved again | No |
| Customer name / phone / email / address | No |
| RadiumBox enrichment / auto validation / background sync / commercial refresh | No |

Human allowlisted sources: `manual_serial_entry`, `device_model_assigned`, `order_admin_edit`.  
Not allowlisted: `radiumbox_enrichment`, `device_model_bulk_assigned`, `identity_repair`, etc.

### Files changed

| File | Change |
|------|--------|
| `app/Services/ServiceCaseAssignmentService.php` | Visibility requires `manual_identity_ready_republish` after manual support ownership; records that event for human identity edits only |
| `app/Services/OrderIdentityLifecycleService.php` | On meaningful human serial/model change → record republish audit; then refresh overlay |
| `app/Services/OrderSerialService.php` | No-op when serial unchanged (no lifecycle / no republish) |
| `app/Services/OrderDeviceModelService.php` | No-op when model unchanged; pass `deviceModelChanged` |
| `app/Http/Controllers/OrderController.php` | Pass `deviceModelChanged` separately from product_name-only edits |
| `tests/Feature/AdminReadyIdentityRepublishTest.php` | SC28000 RadiumBox block + blank/A→B/unchanged/customer-info regressions |
| `tests/Feature/ManualAgentOwnershipWorkflowTest.php` | Human serial/model correction republishes Admin Ready |
| `docs/sc28000-ready-queue-investigation.md` | This document |
| Canvas `sc28000-ready-queue-investigation.canvas.tsx` | Updated workflow |

No DB schema changes. No UI in this phase.

### Republish paths reviewed

| Path | Role |
|------|------|
| `OrderSerialService` / `OrderDeviceModelService` / `order_admin_edit` | Human meaningful change → may record `manual_identity_ready_republish` |
| `radiumbox_enrichment` / other background sources | Never record republish event |
| `isVisibleInAdminReadyQueue` | Manual support ownership → need Ready-eligible **and** republish audit after ownership |
| Classifier `isReadyForReferenceEntry` | Unchanged (internal eligibility / Service Ref gate) |

### Regression coverage

- `test_sc28000_radiumbox_auto_update_does_not_republish_under_manual_ownership`
- `test_blank_to_serial_republishes_under_manual_ownership`
- `test_serial_a_to_serial_b_republishes_under_manual_ownership`
- `test_blank_to_model_republishes_under_manual_ownership`
- `test_model_a_to_model_b_republishes_under_manual_ownership`
- `test_unchanged_serial_does_not_republish_under_manual_ownership`
- `test_unchanged_model_does_not_republish_under_manual_ownership`
- `test_customer_info_edit_does_not_republish_under_manual_ownership`
- `test_auto_assigned_incident_still_appears_in_admin_ready_after_validation`

### Rollback notes

1. Revert the service/controller/test/doc changes (or revert the release commit/tag).
2. No migrations to roll back.
3. After full revert to pre-SC28000: old `validation_passed` republish returns (including RadiumBox).
4. After revert of only the meaningful-edit enhancement: manual ownership stays permanently hidden from Admin Ready until explicit human return/requeue (phase-1 SC28000 behaviour).

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
| After `validation_passed` (12:56 → investigation) | **Visible (old rule)** | Old intentional republish — now blocked by business change |

---

## Ruled out

| Hypothesis | Finding |
|------------|---------|
| Snapshot/cache ghost | Live + snapshot + DB agree |
| Commercial block | `open`, work allowed |
| Queue filter bug | Admin Ready is intentionally unscoped across admins |
| Assignment should permanently remove Ready | Hide under manual support ownership until meaningful human serial/model edit (or explicit return) |
| Ready stole ownership from Jayram | Opposite — owner preserved |

---

## Secondary observation

From create until 12:56 the case remained `awaiting_product_details` with **no** `validation_passed` despite Aug 6 enrichment applying serial + model. Today’s enrichment/identity path finally promoted it and wrote `validation_passed`. That delay explains *when* the old republish fired.

---

## Evidence (production queries — investigation time)

- `Incident` 28072 / `SC28000` — status `open`, assignee 5, origin `manual`, `transaction_id` null on order 27449
- `AuditLog` 831753 — `service_case.reassigned` Avinash→Jayram (manual)
- `AuditLog` 859245 — status APD→open
- `AuditLog` 859246 — `ready_queue_owner_preserved`
- `AuditLog` 859270 — `service_case.automation.validation_passed` (only one; after manual ownership)
- CommercialStateResolver → `open`
- Live at investigation: `in_dashboard_ready_bucket=true`, `visible_admin_ready=true` (old rule)

Investigation method: read-only SSH + `php artisan tinker` via `tools/config.sh`. No production writes during investigation.

---

## Conclusion

SC28000 was in Ready Queue because old Admin Ready Identity Republish re-listed it after background `validation_passed` while Jayram still held manual support ownership. **Business rules now:** (1) automation never republishes under manual support ownership; (2) a support engineer’s meaningful serial/model edit may return the case to Admin Ready with ownership preserved when Service Reference is still missing.
