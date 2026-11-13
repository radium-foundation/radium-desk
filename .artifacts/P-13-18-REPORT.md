# P-13-18 — Operations support ownership routing (matrix rows 110–111)

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-18** |
| Cursor ledger ID | **RadiumDesk-P-13-18** |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` |
| Remote | `git@github.com:radium-foundation/radium-desk.git` |
| Worktree | `.worktrees/p13-18-ops-support-ownership` → `feature/p13-18-ops-support-ownership` |
| Verified `origin/main` (start) | **`1a930c83a839d3dab592e681b630ae70d988b92b`** |
| Start | **2026-11-13 10:30:00 IST** (session; worktree baseline aligned with `origin/main`) |
| End | **2026-11-13 10:36:00 IST** |

---

## Exact queue assignment (from `.artifacts/P-13-06-NEXT-AGENT-QUEUE.md`)

## P-13-18 — Operations support ownership routing

| Field | Value |
| --- | --- |
| Lines | **110**, **111** |
| Methods | `OperationsSupportOwnershipTest::test_invalid_serial_case_assigns_support_agent`, `test_offline_agent_skipped_for_invalid_serial_reassignment` |
| Root cause | **INFERRED** support-agent selection / offline skip policy |
| Ownership | `OperationsSupportOwnershipTest`, assignment/support routing services |
| Focused regression | `OperationsSupportOwnershipTest` (full class) |
| Dependencies | Does not edit ready-queue broadcast (P-13-03 closed) |
| Overlap risk | Medium with assignment KPI work — avoid `ServiceCaseAssignmentService` unless repro demands |
| Risk | Normal |
| Parallel worktree | **Yes** |

**Boundaries observed:** No canonical CSV/register edits; no unrelated `ServiceCaseAssignmentService` changes; wallet WIP untouched.

---

## Assigned register rows

| CSV line | Test identifier | P-12-32 outcome |
| ---: | --- | --- |
| **110** | `Tests.Feature.OperationsSupportOwnershipTest::test_invalid_serial_case_assigns_support_agent` | FAIL |
| **111** | `Tests.Feature.OperationsSupportOwnershipTest::test_offline_agent_skipped_for_invalid_serial_reassignment` | FAIL |

Source: `.artifacts/P-12-32-matrix-reconciliation.csv` on `origin/main` @ `1a930c83`. **Canonical register not edited** (queue assignment).

---

## Elapsed time by stage

| Stage | Elapsed |
| --- | --- |
| VERIFY (repo, queue, ledger claim, policy) | ~8 min (combined with INSPECT) |
| INSPECT / diagnose | ~15 min (incl. local autoload worktree isolation) |
| MODIFY | ~2 min |
| TEST (focused + class + neighbour) | ~2 min measured |
| REVIEW / REPORT / GIT | ~5 min |
| **Total (measured partial)** | **~32 min** (wall-clock session estimate) |

---

## Findings

| Item | Label |
| --- | --- |
| `passesValidationForOrder` false for invalid serial + product | **VERIFIED** |
| `activeSupportAgents` non-empty with online agent; offline agent excluded in row 111 | **VERIFIED** |
| `ServiceCaseAssignmentService::reassignToSupportAgentViaRoundRobin` alone reassigns correctly | **VERIFIED** |
| `SupportQueueAssignmentStrategy::assign(..., ValidationFailure)` left assignee on admin when incident already assigned | **VERIFIED** (on corrected autoload for this worktree) |
| Root cause | **VERIFIED** — **application defect**: `SupportQueueAssignmentStrategy::assign()` returned early when `assigned_to_user_id !== null` **before** handling `AssignmentTrigger::ValidationFailure`, so validation-failure routing never ran for admin-assigned incidents (eligibility path uses this strategy). |
| Row 110 classification | **Application defect** (fixed by strategy guard reorder) |
| Row 111 classification | **Application defect** (same; offline skip works once ValidationFailure path runs) |
| Stale register | **INFERRED** — failures were live on `1a930c83`, not matrix-only drift |
| Shared `vendor` autoload `baseDir` pointing at another worktree during diagnosis | **VERIFIED** environment — mis-attributed “strategy fix ineffective” until `composer dump-autoload` from this worktree; **not committed** |

---

## Behaviour

| | |
| --- | --- |
| Preserved | On-create / appointment / hardware / designated-assignee branches in `SupportQueueAssignmentStrategy`; round-robin and offline-skip logic in `ServiceCaseAssignmentService` |
| Extended | Nothing |
| Changed | `ValidationFailure` handling runs before the “already assigned” short-circuit |
| Removed | Nothing |

---

## Code change

**File:** `app/Support/Assignment/Strategies/SupportQueueAssignmentStrategy.php` — move `AssignmentTrigger::ValidationFailure` branch above `assigned_to_user_id !== null` early return.

No test file changes required (existing feature tests assert intended product behaviour).

---

## Focused validation

**Assigned methods:**

```bash
cd .worktrees/p13-18-ops-support-ownership
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated \
  --filter 'test_invalid_serial_case_assigns_support_agent|test_offline_agent_skipped_for_invalid_serial_reassignment' \
  tests/Feature/OperationsSupportOwnershipTest.php
```

| Result | Tests | Assertions | Duration | Exit |
| --- | ---: | ---: | ---: | ---: |
| **PASS** | 2/2 | 3 | 3489 ms | 0 |

**Focused regression (queue):**

```bash
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated tests/Feature/OperationsSupportOwnershipTest.php
```

| Result | Tests | Assertions | Duration | Exit |
| --- | ---: | ---: | ---: | ---: |
| **PASS** | 5/5 | 7 | 6112 ms | 0 |

**Neighbouring:**

```bash
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated tests/Feature/GraceExpiredValidationFailedAssignmentFallbackTest.php
```

| Result | Status |
| --- | --- |
| Neighbour run | **PASS** (json footer; duration in runner output) |

**Pint:** `vendor/bin/pint --dirty` — **PASS**

Full PHPUnit suite: **NOT RUN**

---

## Diff review

| Check | Status |
| --- | --- |
| Only assignment strategy + artifacts/ledger in commit scope | **PASSED** (1 app file) |
| No secrets / `.env` / customer data | **PASSED** |
| No canonical CSV / register edits | **PASSED** |
| Wallet `feature/p18-33-unified-wallet-display` @ `d9481615` | **VERIFIED** unchanged |
| Tests exercise validation-failure → support reassignment | **PASSED** |

---

## Git / PR / CI

| Field | Value |
| --- | --- |
| Branch | `feature/p13-18-ops-support-ownership` |
| Starting HEAD | `1a930c83a839d3dab592e681b630ae70d988b92b` |
| Commit | *(pending — see footer after push)* |
| PR | *(pending)* |
| Merge SHA | *(pending CI + merge)* |

Production / deployment: **NO — Not performed**

---

## Risks / follow-up

- **Medium overlap** with other assignment workstreams — future changes to `SupportQueueAssignmentStrategy` should keep ValidationFailure before assignee short-circuit.
- Canonical matrix rows **110–111** remain STILL FAILING until a separate register reconciliation pass (not in P-13-18 scope).
- Parallel worktrees sharing one `vendor/` autoload: run `composer dump-autoload` from the active worktree before local PHPUnit if `App\` resolves to the wrong tree (**environment**, not product).

---

*End of P-13-18 report (pre-PR footer).*
