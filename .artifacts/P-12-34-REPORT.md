# P-12-34 — TeamPerformanceIntelligenceTest (2 matrix rows)

| Field | Value |
| --- | --- |
| Prompt | **RadiumDesk-P-12-34** (claimed) |
| Repository | `radium-foundation/radium-desk` |
| Worktree | `.worktrees/p12-34-team-performance-intelligence` |
| Base | **`origin/main`** @ **`c4d843c8`** (#177) |
| Branch | `feature/p12-34-team-performance-intelligence` |
| Wallet protected | `feature/p18-33-unified-wallet-display` @ `d9481615` — **not modified** |
| Policy | No full suite; no deploy |

## Root causes (separate)

### `test_team_member_sees_only_own_stats`

| Item | Detail |
| --- | --- |
| Surface | PHPUnit `Error: Call to a member function all() on array` on `assertOk()` |
| Unmasked | **302** → `login`; `TrackTeamMemberActivity` **force-logout** for Away presence |
| Mechanism | Factory `WorkSession` rows had **`logout_at` null** with `login_at` 09:00 while `Carbon::setTestNow` 10:00 → **60m** idle > **15m** away timeout → legitimate middleware redirect |
| Mask | Redirect + session `errors` shape triggers Laravel test context injection (`TestResponseAssert`) |
| Fix | **Harness / test data only:** close sessions (`logout_at` + `WorkSessionEndReason::ManualLogout`); add **`withoutVite()`** in `setUp` for blade routes (P-12-29 pattern) |

### `test_ira_generates_contextual_insights`

| Item | Detail |
| --- | --- |
| Surface | `Failed asserting that false is true` (weekly insight substring) |
| Root | Test expected legacy copy **`handled … customer follow-ups`**; `IraPerformanceInsightsService` ships **`logged … customer touches this week`** (aligned with UI “Customer replies” / owner intel) |
| Fix | **Test assertion sync** to current product copy (still asserts threshold insight for 12 comms @ weekly threshold 10) |

## Modify

| File | Change |
| --- | --- |
| `tests/Feature/TeamPerformanceIntelligenceTest.php` | `withoutVite()` in `setUp`; closed work sessions in member isolation test; IRA weekly message substring |

**No application code changes.**

## PHPUnit (focused)

Commands: `PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n vendor/bin/phpunit …`

| Target | Result |
| --- | --- |
| `TeamPerformanceIntelligenceTest` (13) | **13/13 PASS** (~14.0s) |
| `test_team_member_sees_only_own_stats` | **PASS** |
| `test_ira_generates_contextual_insights` | **PASS** |
| `TeamTelegramWorkAssistantTest` | **8/8 PASS** |
| `OrderTransactionTest` | **19/19 PASS** |
| `SupportAppointmentTelegramReminderTest` | **13/13 PASS** |
| `IdentityWaitingAutoClearTest` | **6/6 PASS** |

Full suite: **NOT RUN**

## Matrix update (P-12-32 evidence)

| Metric | Before | After |
| --- | ---: | ---: |
| `TeamPerformanceIntelligenceTest` failing matrix rows | 2 | **0** |
| P-12-32 **STILL FAILING** total (approx.) | 134 | **132** (−2) |

## CI / RIP

| Phase | Workflow | Run URL | `headSha` | Conclusion |
| --- | --- | --- | --- | --- |
| Pre-merge (PR **#178**) | rip | [43213328309](https://github.com/radium-foundation/radium-desk/actions/runs/43213328309) | `94b34d2b` | **success** (~40s) |
| Pre-merge (PR **#178**) | release-rip | [43213328195](https://github.com/radium-foundation/radium-desk/actions/runs/43213328195) | `94b34d2b` | **success** (~36s) |
| Post-merge (`main`) | rip | [43213546091](https://github.com/radium-foundation/radium-desk/actions/runs/43213546091) | `97720bc2` | **success** |
| Post-merge (`main`) | release-rip | [43213546145](https://github.com/radium-foundation/radium-desk/actions/runs/43213546145) | `97720bc2` | **success** |

Merged **[PR #178](https://github.com/radium-foundation/radium-desk/pull/178)** → **`97720bc2`**.

## Coordinator summary

- **2/2** targeted WS-11 matrix rows remediated with **test-only** diff on **`c4d843c8`**.
- Force-logout redirect was **correct product behavior**; test data implied stale open sessions.
- IRA weekly assertion updated to **`customer touches`** copy — not a metrics regression.
- Wallet **`d9481615`** preserved; no full suite / deploy.
