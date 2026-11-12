# P-12-41 — FINAL REPORT (IRA Learning Center access cluster)

**Programme start (IST):** 2026-11-12 21:27:00 IST  
**Programme end (IST):** _(fill on closeout)_  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk` only  
**Baseline:** `origin/main` @ **`482d615484e080c358eb9cf28818901f6b0cf9cf`**  
**Worktree:** `.worktrees/p12-41-incoming-email-learning-center` → `feature/p12-41-incoming-email-learning-center`

---

## 1. Ledger

| Field | Value |
| --- | --- |
| Prompt | **P-12-41** |
| Ledger ID | **`RadiumDesk-P-12-41`** (**CLAIMED**) |
| Claim artifact | `.artifacts/ledger-claim-RadiumDesk-P-12-41.md` |

---

## 2. VERIFY summary

| Check | Result |
| --- | --- |
| P-12-37 final report | Read `.worktrees/p12-37-coordinator/.artifacts/P-12-37-FINAL-REPORT.md` (pre–#188 baseline) |
| P-12-38 final report | Agent A **9/9** on `IncomingEmailLearningCenterAccessTest`; merged **#188** **`304c5aa6`** |
| P-12-40 worktree | `.worktrees/p12-40-phpunit-guard` @ **`482d6154`** (phpunit guard audit; unrelated to this cluster) |
| Cluster unresolved? | **No** on current `main` — matrix rows stale vs P-12-32 snapshot |
| Ownership | `.worktrees/p12-38-incoming-email-learning-center` exists (prior Agent A); P-12-41 isolated tree, no file conflicts |
| Wallet WIP | `feature/p18-33-unified-wallet-display` @ **`d9481615`** untouched |

---

## 3. Root cause

Missing **Learning Center** sidebar destination for roles with default `email-intake.view` / `email-intake.manage`. Tests failed on `title="Learning Center"` in dashboard sidebar HTML (not on permission seeding).

---

## 4. Changes (this prompt)

| Item | Notes |
| --- | --- |
| Product code | **None** — fix already on `main` via #188 |
| CI | **NEW** `.github/workflows/incoming-email-learning-center-rip.yml` — permanent access + phase-1 regression |
| Ledger | Append `RadiumDesk-P-12-41` row |

---

## 5. Focused PHPUnit (mandated `php -n`, IST 2026-11-12)

| Target | Result | Log |
| --- | --- | --- |
| `IncomingEmailLearningCenterAccessTest` | **9/9 PASS** | `.artifacts/phpunit-incoming-email-learning-center-access-20261112T213036.log` |
| `IncomingEmailLearningCenter*` (filter) | **18/18 PASS** | (same session) |

**Full suite:** **NO**

---

## 6. Matrix delta

See `.artifacts/P-12-41-MATRIX.md` — **5/5** P-12-32 rows **VERIFIED FIXED** on `main`.

---

## 7. PR / CI / merge

_(fill after PR merge)_
