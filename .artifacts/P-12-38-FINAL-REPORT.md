# P-12-38 — Programme FINAL REPORT (round 2)

**Programme start (IST):** 2026-11-12 18:43:18 IST  
**Programme end (IST):** 2026-11-12 18:53:06 IST (measured at artifact close)  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk` only  
**Baseline:** `origin/main` @ **`762cf798`** (#185) — verified == `git rev-parse 762cf798` after `git fetch origin main`  
**Coordinator worktree:** `.worktrees/p12-38-coordinator` → `feature/p12-38-coordinator`  
**Wallet WIP:** untouched — see §10

---

## 1. Ledger ID

| Field | Value |
| --- | --- |
| Prompt | **P-12-38** |
| Ledger ID | **`RadiumDesk-P-12-38`** (**CLAIMED**) |
| Claim artifact | `.worktrees/p12-38-coordinator/.artifacts/ledger-claim-RadiumDesk-P-12-38.md` |
| Note | `docs/cursor-prompt-ledger.md` append deferred (coordinator branch uncommitted); claim file is authoritative for this run |

---

## 2. Agents launched

**Mode:** Coordinator implemented A–D directly in isolated worktrees (no separate Task subagents). **Reason:** shared `vendor/` autoload must be `composer dump-autoload` per worktree immediately before PHPUnit; parallel impl agents would stomp `vendor/composer/autoload_psr4.php` baseDir (observed pin to `p12-37-orders-workspace-close` until re-dump).

| Agent | Role | Branch | Impl |
| --- | --- | --- | --- |
| A | IRA Learning Center access / nav | `feature/p12-38-incoming-email-learning-center` | **DONE** (uncommitted) |
| B | Automation alert semantics | `feature/p12-38-intelligent-automation-alerts` | **DONE** (uncommitted) |
| C | POS statutory snapshot + PDF row | `feature/p12-38-pos-statutory-reference` | **PARTIAL / STOP** |
| D | CI Imagick parity | `feature/p12-38-ci-imagick-parity` | **MERGED** [PR #186](https://github.com/radium-foundation/radium-desk/pull/186) → `2594f61e` |
| E | Reviewer read-only | `feature/p12-38-reviewer-readonly` | **NOT RUN** (no PRs yet) |

---

## 3. Worktrees

| Worktree | Branch | HEAD |
| --- | --- | --- |
| `.worktrees/p12-38-coordinator` | `feature/p12-38-coordinator` | `762cf798` |
| `.worktrees/p12-38-incoming-email-learning-center` | `feature/p12-38-incoming-email-learning-center` | `762cf798` |
| `.worktrees/p12-38-intelligent-automation-alerts` | `feature/p12-38-intelligent-automation-alerts` | `762cf798` |
| `.worktrees/p12-38-pos-statutory-reference` | `feature/p12-38-pos-statutory-reference` | `762cf798` |
| `.worktrees/p12-38-ci-imagick-parity` | `feature/p12-38-ci-imagick-parity` | `762cf798` |
| `.worktrees/p12-38-reviewer` | `feature/p12-38-reviewer-readonly` | `762cf798` |

`vendor` → `../../vendor` symlink; **mandatory** `PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n …/composer dump-autoload -o` in each worktree before focused PHPUnit.

**Not touched:** wallet checkout `feature/p18-33-unified-wallet-display` @ `d9481615`; P-12-36/37 trees (read-only reference).

---

## 4. Ownership manifest

`.worktrees/p12-38-coordinator/.artifacts/P-12-38-OWNERSHIP-MANIFEST.md` — no overlapping `*Test.php` between A–D.

---

## 5. Matrix counts

See `.artifacts/P-12-38-MATRIX.md`.

| Metric | Value |
| --- | ---: |
| Rows in programme slice | **15** |
| **VERIFIED FIXED** (focused evidence) | **9** (5 + 4) |
| **PARTIAL** | **1** cluster (PosStatutory 10/14) |
| **STOP (Owner)** | **1** (Reference No. on UNPAID POS PDF) |
| **CI env row** | harness updated (D); local `php -n` unchanged by policy |

Approx. STILL FAILING @ main after hypothetical merges of A+B+D only: **~90** (not re-run full matrix).

---

## 6. Preflight evidence

| Check | Result |
| --- | --- |
| PR #182–#185 | **MERGED** on `main`; HEAD = `762cf798` |
| P-12-37 final report | Read `.worktrees/p12-37-coordinator/.artifacts/P-12-37-FINAL-REPORT.md` |
| Matrix CSV rows | Mapped lines 75–79, 83–86, 92–96, 170 in `P-12-32-matrix-reconciliation.csv` |
| Policy | `docs/RADIUMWEBSITES_TESTING_AND_VALIDATION_POLICY.md` — focused `php -n` profile |

---

## 7. Focused PHPUnit evidence

Profile: `PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n vendor/bin/phpunit` after per-worktree `composer dump-autoload -o`.

| Agent | Target | Result | Exit |
| --- | --- | --- | ---: |
| A | `IncomingEmailLearningCenterAccessTest` | **9/9 PASS** | 0 |
| B | `IntelligentAutomationAlertSemanticsTest` | **7/7 PASS** | 0 |
| C | `PosStatutorySnapshotTest` | **10/14** (4 FAIL, 1 ERR) | 1 |
| C | `StatutoryInvoicePdfCustomerFacingSkuTest` (+ imagick.so) | **3/4** Reference No. FAIL | 1 |
| D | (no PHPUnit — workflow YAML only) | N/A | — |

**Full suite:** **NO**  
**Deploy:** **NO**

---

## 8. Root causes / fixes (summary)

| Cluster | Fix |
| --- | --- |
| A | `NavigationContextResolver::sidebar()` adds `learning_center` destination with `title="Learning Center"` when `email-intake.view` |
| B | `ProductionWatchdogService`: unit-test isolation — automation-only critical alerts + ledger path; skip self-HTTP site probe in tests |
| C | `PosStatutorySnapshotTest::setUp()` + `withoutVite()` — fixes Vite 500 subset; remaining B2B statutory session validation needs product review |
| C PDF | **STOP** — `paymentColumns()` suppresses Reference No. when UNPAID; Owner decision on walk-in UPI intent labeling |
| D | All 6 `setup-php@v2` steps: `extensions: imagick, zip, gd, sqlite3, mbstring, xml, curl, intl` |

---

## 9. PRs

| PR | Status |
| --- | --- |
| [#186](https://github.com/radium-foundation/radium-desk/pull/186) | **MERGED** (squash) @ **`2594f61e854b98ffa8f3b703a8d001934b2b1fbe`** — rip + release-rip **pass** on head `69e4c01f` |
| A / B / C | **None opened** (uncommitted worktrees) |

---

## 10. Wallet WIP preservation

| Check | Value |
| --- | --- |
| Main checkout branch | `feature/p18-33-unified-wallet-display` |
| `rev-parse` | `d94816153cd2c89f8175d29a1510b38b4c16df6b` |
| Action | **None** (no reset/clean/stash/switch) |

---

## 11. Coordinator artifacts

| File |
| --- |
| `ledger-claim-RadiumDesk-P-12-38.md` |
| `P-12-38-OWNERSHIP-MANIFEST.md` |
| `P-12-38-MATRIX.md` |
| `P-12-38-FINAL-REPORT.md` |

---

## 12. Next steps

1. Commit + push A, B, D branches; open PRs; run rip / release-rip before merge.  
2. Agent C: Owner call on Reference No. + B2B POS session fields; then product/tests.  
3. Agent E review after PRs exist.  
4. Document shared-vendor `composer dump-autoload` ceremony in coordinator MANIFEST for parallel rounds.

**Programme status:** **PARTIAL COMPLETE** — 2 clusters green (A, B), CI harness (D); C blocked on statutory product semantics.
