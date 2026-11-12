# P-12-40 — PHPUnit full-suite entrypoint audit (pre-change)

**Base:** `482d615484e080c358eb9cf28818901f6b0cf9cf` (`origin/main` at worktree create)  
**Worktree:** `.worktrees/p12-40-phpunit-guard`  
**Wallet WIP:** `d94816153cd2c89f8175d29a1510b38b4c16df6b` — **not modified**

| Path | Full-suite risk | Notes |
| ---- | --------------- | ----- |
| `vendor/bin/phpunit` | **HIGH** | Unscoped default; **no guard** unless using `bin/phpunit-safe` |
| `composer.json` → `scripts.test` | **HIGH** | Runs `@php artisan test` (full Laravel test runner) |
| `phpunit.xml` | Config only | Defines Unit + Feature directories |
| `bin/phpunit-p1010/p1010-run-full-phpunit.sh` | **HIGH** (gated) | P-10-05 wrapper; `P1010_RUN_SUITE=1` full mode; separate lock/progress semantics |
| `bin/phpunit-p1010/p1010-run-simulations.sh` | Low | Simulation / stub paths |
| `.github/workflows/release-rip-ci.yml` | **Focused** | `tests/Unit/Release/`, `tests/Feature/DriverManagement/` |
| `.github/workflows/*-rip.yml` | **Focused** | Domain-scoped test paths |
| `.github/workflows/statutory-cancellation-phpunit.yml` | **Focused** | Multiple scoped invocations |
| `Makefile` | None | Not present |
| `.husky` / git hooks | None | Not found |
| `tools/` | None | RIP / backup scripts; no PHPUnit full suite |

**Post P-12-40:** `bin/phpunit-safe`, `scripts/phpunit-guard.php`, grant helper, dispatch workflow (not auto-run on PR).
