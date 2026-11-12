# P-13-03 — WS-10 Ready queue live broadcast on manual assign

**Programme (IST):** 2026-11-13 01:08 → 01:25  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Worktree:** `.worktrees/p13-03-queue-integrity` → `feature/p13-03-queue-integrity`  
**Verification HEAD:** `origin/main` @ **`489fd91effb11ecc7a533fa1d3a14f54d9080cb1`** (post **#202** / P-13-01)  
**Wallet checkout:** `d9481615` — **verified, not modified**  
**Full PHPUnit:** **NOT RUN**  
**Production:** **NOT RUN**

---

## 1. Ledger / ownership

| Field | Value |
| --- | --- |
| Prompt | **P-13-03** |
| Ledger ID | **RadiumDesk-P-13-03** (claimed 2026-11-13 01:15 IST) |
| Claim artifact | `.artifacts/ledger-claim-RadiumDesk-P-13-03.md` |
| P-13-02 overlap | **Excluded** — RefundRequest dashboard KPI owned by `.worktrees/p13-02-refund-dashboard` |
| P-13-04 overlap | **Avoided** — no `DashboardServiceCasesTest` / stale-matrix work (candidate in P-13-04 worktree) |

**Canonical failure register:** `.artifacts/P-12-44-REMAINING-FAILURES.md` @ post-#201 baseline (**95** STILL FAILING, **92** WS-10).

---

## 2. Cluster selection

| Item | Detail |
| --- | --- |
| **Selected** | Ready queue **live refresh / broadcast integrity** — `QueueIntegrityLiveRefreshTest` |
| **Matrix row** | `Tests.Feature.QueueIntegrityLiveRefreshTest::test_manual_assignment_broadcasts_remove_from_ready_queue` |
| **Rationale** | Reproducible **FAIL** @ `489fd91e`; peer-admin dashboards must receive `ServiceCaseCreated` with `list_actions[action_required]=remove` when manual assign drops Admin Ready membership. Real **application** defect (not Vite harness). |

---

## 3. Reproduction evidence (pre-fix)

```text
QueueIntegrityLiveRefreshTest::test_manual_assignment_broadcasts_remove_from_ready_queue
→ The expected [App\Events\Dashboard\ServiceCaseCreated] event was not dispatched.
Cluster file: 7/8 PASS, 1 FAIL @ 489fd91e (duration ~13.8s)
```

**Classification:** **Application defect** — assignment path broadcast `serviceCaseAssigned()` only (gated on Hybrid Realtime **ASSIGNMENT**); no `broadcastRowUpdate` / `ServiceCaseCreated` fan-out for queue membership removal.

---

## 4. Root cause

`ServiceCaseAssignmentService::applyAssignment()` invoked `DashboardBroadcastService::serviceCaseAssigned()` for `service_case.reassigned` events. When Hybrid Realtime assignment is disabled (PHPUnit default), **no** dashboard row event is emitted. Queue integrity for peer admins relies on `serviceCaseQueueMembershipChanged()` → `ServiceCaseCreated` + `DashboardLiveRowVisibilityService::ACTION_REMOVE`.

Manual assign to agent already updated snapshot membership (`shouldRemoveFromAdminReadyQueue` === true) but did not notify other admins' live grids.

---

## 5. Change (minimal)

**File:** `app/Services/ServiceCaseAssignmentService.php`

After `serviceCaseAssigned()`, when `shouldRemoveFromAdminReadyQueue($freshIncident)`:

- Call `dashboardBroadcastService->serviceCaseQueueMembershipChanged($freshIncident, $actor)`.

Preserves existing hybrid assignment events when enabled; restores row-remove fan-out when assignment shortcut is off. No workflow/permission/KPI contract changes.

---

## 6. Tests executed

| Suite | Filter / scope | Result | Duration |
| --- | --- | --- | --- |
| `QueueIntegrityLiveRefreshTest` | full file (8 methods) | **8/8 PASS** | ~9.5s |
| `ServiceCaseAssignmentTest` | full file | **25/26 PASS** | ~26.4s |

**Note:** `ServiceCaseAssignmentTest::test_dashboard_displays_owner_first_name` **FAIL** on **baseline and post-fix** (empty Ready grid / harness; matrix STILL FAILING row — not introduced by P-13-03).

**Command:** `bash bin/phpunit-isolated` with Homebrew PHP (`bin/phpunit-isolated` default).

---

## 7. Git / PR

| Item | Value |
| --- | --- |
| Branch | `feature/p13-03-queue-integrity` |
| Commit | *(filled after push)* |
| PR | *(filled after create)* |
| Merge SHA | *(pending CI + merge)* |

Shared matrix CSV: **not updated** (per prompt).

---

## 8. Residual risks

- Peer admins may receive both `ServiceCasesAssigned` (when ASSIGNMENT hybrid on) and `ServiceCaseCreated` on ready-queue removal — intentional dual channel (hybrid + row list_actions).
- **91** WS-10 matrix rows remain STILL FAILING per register.
- `DashboardServiceCasesTest` ×6 rows reported **STALE/PASS** @ `b69ee268` (P-13-01) — reconcile separately, not P-13-03 scope.

---

## 9. Policy

| Item | Status |
| --- | --- |
| WS-04 / wallet | Excluded |
| `composer dump-autoload -o` | Not required (single PHP change) |
| Full suite | **NOT RUN** |
| Production deploy | **NOT RUN** |
