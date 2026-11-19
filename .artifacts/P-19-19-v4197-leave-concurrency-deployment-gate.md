# P-19-19 — Leave concurrency regressions and deployment gate (v4.1.97)

| Field | Value |
|---|---|
| Global ID | P-19-19 |
| Cursor ledger ID | **RadiumDesk-P-19-19** |
| Risk | **HIGH** |
| Start | 2026-11-19 **~10:42 IST** (per prompt) |
| End | 2026-11-19 **~11:35 IST** |
| Duration | **~53 min** (implementation + MariaDB runs; aggregate PHPUnit wall **~550s** on harness) |
| Baseline tag | **`v4.1.96`** @ **`a016195a0`** (immutable) |
| Fix branch | **`fix/p19-19-leave-concurrency`** (worktree `.worktrees/p19-19-leave-concurrency`) |
| **Production deploy / migration / env / backup / RIP** | **NO — Not performed** |

---

## Executive summary

- **VERIFIED:** Disposable **MariaDB 11.8.8** (`127.0.0.1:33118`, DB `radium_desk_leave_policy_concurrency_test`) concurrency regressions **PASS** after coverage/locking fixes and harness correction (Composer `App\` autoload must target this worktree).
- **VERIFIED:** SQLite amendment/coverage/triad/controls suites **PASS** in same focused bundle (**22/22**).
- **BLOCKER cleared:** Wrong-worktree Composer `baseDir` caused workers to run **stale** `LeaveCoverageGuard` (no enforcement) — fixed via `composer dump-autoload` in worktree; documented in bootstrap comment.
- **Release:** **`v4.1.96`** stays immutable; ship concurrency fixes as **`v4.1.97`** on merge commit (patch bump; **no** migration delta vs **v4.1.96**).

---

## Code changes (summary)

| Area | Change |
|---|---|
| `LeaveRequestService::approve` | `READ COMMITTED` before transaction; lock coverage group rows before leave row; `assertCoverageAllows(..., countPendingPeerLeaves: false)` |
| `LeaveCoverageGuard` | Optional `countPendingPeerLeaves`; approve path ignores peer **pending** (submit still counts pending+approved) |
| `LeaveRequestAmendmentService` | `READ COMMITTED` before approve / HR `manageDateChange` transactions |
| Tests | `LeavePolicyMysqlConcurrencyTest` (admin pair, triad race, duplicate approve, monthly cap race); `LeavePolicyAmendmentCoverageTest`; CLI prepare/worker harness |

---

## Test evidence

| Suite | Engine | Result | Exit |
|---|---|---:|---|
| `LeavePolicyMysqlConcurrencyTest` (5) | MariaDB **11.8.8** disposable | **PASS** | **0** |
| `LeavePolicyControlsTest` | SQLite | **PASS** | **0** |
| `LeavePolicyTriadCoverageTest` | SQLite | **PASS** | **0** |
| `LeavePolicyAmendmentCoverageTest` | SQLite | **PASS** | **0** |
| **Focused total** | **22 tests** | **22 PASS** | **0** |
| Full PHPUnit | — | **NOT RUN** | — |

**MariaDB start (local disposable — not prod):**

```bash
/opt/homebrew/opt/mariadb@11.8/bin/mariadbd --no-defaults \
  --datadir=/tmp/p1540_disposable_mariadb --port=33118 \
  --bind-address=127.0.0.1 --socket=/tmp/p1540-leave.sock ...
```

**Harness env:** `LEAVE_POLICY_MYSQL_PORT=33118`, `LEAVE_POLICY_MYSQL_USERNAME=rd_leave_policy_test`, `LEAVE_POLICY_MYSQL_PASSWORD=rd_leave_policy_test_pw`.

---

## Git / PR status (at artifact time)

| Item | Status |
|---|---|
| Branch | `fix/p19-19-leave-concurrency` from `a016195a0` |
| Primary clone WIP | **Preserved** (`feature/p14-38-desk-sole-payment-authority`) |
| PR | **Pending** (push + open #353 or next) |
| Merge to `main` | **Pending** CI + Owner merge policy |
| Tag `v4.1.97` | **Pending** merge SHA |

---

## Production preflight (prepared only — **DO NOT EXECUTE** without Owner)

### Release identity

| Check | Command / value |
|---|---|
| Required tag after merge | **`v4.1.97`** (or merge SHA if tag skipped) |
| Immutable prior release | **`v4.1.96`** @ **`a016195a0`** |
| Prod today (read-only P-18/P-19) | **`4.1.94`** / **`b6b39f081`** |

### Configuration (deploy-time on prod host)

```bash
WORKFORCE_LEAVE_COVERAGE_TRIAD_EMAILS=shubhanshi@radiumbox.com,abhinavjharadiumbox@gmail.com,kanchan@radiumbox.com
WORKFORCE_LEAVE_ADMIN_PAIR_LEAVE_EMAILS=dileep@radiumbox.com,avinash@radiumbox.com
WORKFORCE_LEAVE_AUTO_APPROVAL_ENABLED=false
```

Confirm **`leave_policy.auto_approval.enabled`** and balance ledger gate remain **disabled**.

### PRE-RIP (fresh — stale vs `b6b39f081` per P-19-18)

```bash
./tools/commands/capture-release-recovery-checkpoint.sh \
  --change pre-p19-19-v4197 \
  --git-sha b6b39f08 \
  --status deployed

php artisan rip:verify-checkpoint storage/app/private/release-checkpoints/<CHECKPOINT_ID>
php artisan rip:verify-pre-deploy-gate storage/app/private/release-checkpoints/<CHECKPOINT_ID> \
  --production-build=b6b39f081 --checkpoint-id=<CHECKPOINT_ID>
```

### Encrypted DB backup

Per `docs/rip-release-checkpoint.md` — fresh encrypted backup immediately before migration/deploy; verify checksum/restorability drill **NOT RUN** in P-19-19.

### Migration

```bash
DEPLOY_RUN_MIGRATIONS=1   # deploy script / Owner runbook
# or: php artisan migrate --force
```

Rollback: migration `down()` drops policy columns (data loss); reverting PHP to **v4.1.94** without `down()` leaves compatible orphan columns.

### Deploy + health

Standard KVM8 Desk deploy @ tag **`v4.1.97`**; `config:clear` / `config:cache`; verify `storage/app/private/release.json` version/build.

### Smoke (authenticated)

1. Triad: two approved same day → third submit blocked; manual exception path for review.
2. Admin pair: Dileep approved → Avinash same day submit blocked.
3. Concurrent approvals (spot): operational review only (MariaDB harness proves mechanics).
4. Amendment date change → policy effective dates refresh attendance window.
5. Payroll locked month → approve/amend blocked.

### POST-RIP

Capture post-deploy checkpoint per P-19-11 pattern after successful smoke.

---

## Findings ledger

| ID | Finding | Class |
|---|---|---|
| F-01 | Workers used wrong Composer `baseDir` → coverage never enforced in MariaDB harness | **VERIFIED** (fixed) |
| F-02 | Approve with peer pending counted both admins → impossible concurrent fixture | **VERIFIED** (`countPendingPeerLeaves: false` on approve) |
| F-03 | `v4.1.96` has no concurrency patch | **VERIFIED** → **v4.1.97** |
| F-04 | Prod PRE-RIP / backup freshness for **`b6b39f081` → merge SHA** | **UNKNOWN** / **NOT RUN** |
| F-05 | Prod MariaDB production isolation under real load | **INFERRED** from disposable InnoDB tests only |

---

## Hard stops (this task)

- Production-changing actions: **STOPPED** (per authorization).
- Full PHPUnit: **NOT RUN** (Owner-controlled).