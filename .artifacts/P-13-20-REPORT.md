# P-13-20 — Team availability overview query batching (matrix rows 221–222)

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-20** |
| Cursor ledger ID | **RadiumDesk-P-13-20** |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` |
| Remote | `git@github.com:radium-foundation/radium-desk.git` |
| Worktree | `.worktrees/p13-20-team-availability` → `feature/p13-20-team-availability` |
| Verified `origin/main` (start) | **`a3f6747842a887796d92b866300ca78d0c51ac5e`** |
| Start | **2026-11-13 11:05:00 IST** |
| End | **2026-11-13 11:12:00 IST** (estimate) |

---

## Exact queue assignment (from `.artifacts/P-13-06-NEXT-AGENT-QUEUE.md`)

## P-13-20 — Team availability overview query batching

| Field | Value |
| --- | --- |
| Lines | **221**, **222** |
| Methods | `TeamAvailabilityOverviewOptimizationTest::test_operational_roster_includes_all_tracked_members_with_batched_queries`, `test_overview_reuses_single_team_member_query_and_batched_sessions` |
| Focused regression | `TeamAvailabilityOverviewOptimizationTest` |
| Dependencies | Presence engine (line 124) separate — do not merge |

---

## Assigned register rows

| CSV line | Test identifier |
| ---: | --- |
| **221** | `Tests.Unit.Operations.TeamAvailabilityOverviewOptimizationTest::test_operational_roster_includes_all_tracked_members_with_batched_queries` |
| **222** | `Tests.Unit.Operations.TeamAvailabilityOverviewOptimizationTest::test_overview_reuses_single_team_member_query_and_batched_sessions` |

**Canonical register not edited.**

---

## Findings

| Row | Root cause | Classification | Label |
| --- | --- | --- | --- |
| **221** | Two batched `work_sessions` `whereIn` queries per roster build (`todaySessionSummariesFor` + `WorkingHoursTodayService::forUsers` open-session probe) | **Application defect** (N+1-style duplicate batch) | **VERIFIED** |
| **222** | Same duplicate batch on overview build | **Application defect** | **VERIFIED** |

**Pre-fix repro:** Expected **1** batched `work_sessions` `whereIn`, observed **2**.

---

## Behaviour

| | |
| --- | --- |
| Preserved | Overview/roster caching, session summary semantics, working-hours rollup logic |
| Extended | Request-scoped cache of today's sessions; optional open-session hint into `forUsers()` |
| Changed | Single batched load of today's `work_sessions` per team-member id set; summaries + open-session detection derived in memory |
| Removed | Redundant batched `work_sessions` query from overview path when hours are loaded |

---

## Focused validation

```bash
cd .worktrees/p13-20-team-availability
/opt/homebrew/bin/php -n /tmp/composer.phar dump-autoload -o
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated tests/Unit/Operations/TeamAvailabilityOverviewOptimizationTest.php
```

| Result | Tests | Assertions | Duration | Exit |
| --- | ---: | ---: | ---: | ---: |
| Pre-fix | 1/3 | — | 4064 ms | 1 |
| Post-fix | **3/3** | 12 | 4338 ms | 0 |

**Neighbour:** `tests/Feature/TeamAvailabilityTest.php` — **BLOCKED** for full class in worktree: **21/24 PASS**, **3 FAILED** (`ViteManifestNotFoundException`; harness / no `public/build` — not caused by P-13-20 diff).

Full PHPUnit suite: **NOT RUN**

---

## Git / PR / CI

| Field | Value |
| --- | --- |
| Branch | `feature/p13-20-team-availability` |
| Starting HEAD | `a3f6747842a887796d92b866300ca78d0c51ac5e` |
| Feature commit | `f6be6a4bfebe024de355bd5a09ef2a0acb72a8d3` |
| PR | [#225](https://github.com/radium-foundation/radium-desk/pull/225) |
| Pre-merge CI | `rip` + `release-rip` **SUCCESS** |
| Verified merge SHA | **`fee47b184957d68ba9a75f9151df871d80af1c0b`** |
| Post-merge `origin/main` | **`fee47b184957d68ba9a75f9151df871d80af1c0b`** |

Production / deployment: **NO — Not performed**

Wallet `feature/p18-33-unified-wallet-display` @ **`d9481615`**: **VERIFIED** unchanged.

---

*End of P-13-20 report.*
