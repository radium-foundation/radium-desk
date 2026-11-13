# P-13-06 — WS-10 canonical failure register reconciliation

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-06** |
| Cursor ledger ID | **RadiumDesk-P-13-06** |
| Claim artifact | `.artifacts/ledger-claim-RadiumDesk-P-13-06.md` |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` |
| Worktree | `.worktrees/p13-06-register-reconcile` → `feature/p13-06-ws10-register-reconcile` |
| Verified `origin/main` SHA (post-merge) | **`041c616bb086d4758475b1d0111f28c90c4d6f26`** (merge **#217**); reconcile evidence baseline **`b54b0a53`** |
| Wallet preservation | `feature/p18-33-unified-wallet-display` @ **`d94816153cd2c89f8175d29a1510b38b4c16df6b`** — **not modified** |
| Start | **2026-11-13 10:04:12 IST** |
| End | **2026-11-13 10:08:35 IST** (register + report); merge **2026-11-13 10:08:34 IST** |

---

## 1. Prechecks

| Check | Status |
| --- | --- |
| Repo root, remote `radium-foundation/radium-desk` | **PASSED** |
| `git fetch origin main` | **PASSED** |
| `origin/main` @ `b54b0a53` | **PASSED** |
| Testing policy `docs/RADIUMWEBSITES_TESTING_AND_VALIDATION_POLICY.md` v1.0 | **PASSED** |
| Prompt ledger read; **RadiumDesk-P-13-06** claimed | **PASSED** |
| Source reports P-13-14, P-13-15, P-13-16 present and complete (local `.artifacts/`, cited by P-13-16) | **PASSED** |
| P-13-05, P-13-07, P-13-08–13 on `origin/main` or worktree copies | **PASSED** |
| PR merge ancestry (#203–#216) | **PASSED** (per P-13-16 `git merge-base --is-ancestor`) |
| No other agent editing canonical CSV during worktree commit window | **PASSED** |
| Canonical artifacts | `.artifacts/P-12-32-matrix-reconciliation.csv`, `.artifacts/P-12-44-REMAINING-FAILURES.md` |

---

## 2. Evidence sources and merge SHAs used

| Report | Role |
| --- | --- |
| [P-13-07](.artifacts/P-13-07-REPORT.md) | Stale-register taxonomy, 35-row shard, line IDs |
| [P-13-16](.artifacts/P-13-16-REPORT.md) | Audited closure recommendations for 39 rows |
| [P-13-14](.artifacts/P-13-14-REPORT.md) | 7 dashboard rows @ `b54b0a53` |
| [P-13-15](.artifacts/P-13-15-REPORT.md) | 16 workspace rows @ `b54b0a53` |
| P-13-02–04, P-13-08–13 reports | Cluster focused PHPUnit evidence |
| [P-13-05](.artifacts/P-13-05-REPORT.md) | WS-03 ZipArchive harness |

| PR | Merge SHA | Used for rows |
| --- | --- | --- |
| #203 | `7fae635b` | 134 |
| #204 | `44db017d` | 126 |
| #206 | `f417adc9` | 161 |
| #182 (bootstrap) | `b79c6d7b` | 217 harness |
| #208 | `a99eda54` | 20, 164–169 |
| #209 | `700526c6` | 16–17, 21–23, 82, 162–163, 175 |
| #210 | `9da7f938` | 87–89, 185–187 |
| #211 | `f3eeabbd` | 218, 224–225 |
| #213 | `aaeb1935` | 127–128 |
| #215 | `749f96e1` | 18, 70, 102, 123, 176–177, 182–183 |
| #175 / P-12-37 | `2ca010b4` ancestry | 190, 192–197, 203–207, 209–212 (stale register + P-13-15 PASS) |

**P-13-06 supplemental re-verify on `b54b0a53`:** lines **134**, **126**, **161**, **217** — each **1/1 PASS** via `bin/phpunit-isolated` (~11s total).

---

## 3. Register before / after

| Classification | Before | After | Δ |
| --- | ---: | ---: | ---: |
| VERIFIED FIXED | 132 | **194** | +62 |
| STILL FAILING | 95 | **34** | −61 |
| ENVIRONMENT BLOCKED | 1 | **0** | −1 |
| **Total rows** | 228 | **228** | 0 |

### WS-10 STILL FAILING

| | Before | After |
| --- | ---: | ---: |
| WS-10 | 92 | **31** |
| WS-11 | 2 | **2** |
| WS-04 | 1 | **1** |

### Reconciled disposition of the 62 closures

| Category | Count |
| --- | ---: |
| Verified fixed via merged implementation + focused PHPUnit (P-13-02–13) | **39** |
| Stale register — verified PASS on current main (P-13-14 + P-13-15) | **23** |
| WS-03 harness reclassified from ENVIRONMENT BLOCKED (P-13-05) | **1** |

**Overlap resolution:** CSV line **161** `ServiceCaseAssignmentTest::test_dashboard_displays_owner_first_name` closed **once** as **P-13-04** / #206 (harness). P-13-03 #207 corrected a **non-matrix** `DashboardLiveRowVisibilityTest` only (**VERIFIED** P-13-07 §4).

---

## 4. P-13-09 order-identifier assertion

| Finding | Disposition |
| --- | --- |
| `Customer360DrawerTest` order-id vs `order-identifier` failures cited in P-13-09 report | **Pre-existing / unsharded** — not in matrix (**VERIFIED** P-13-16 grep) |
| Assigned row 82 closure | Inquiry **Type** row + search index — **distinct** from drawer order-id copy (**VERIFIED**) |
| Register impact | **No** matrix row closed for drawer order-id |

---

## 5. P-13-13 Vite / manifest validation status

| Check | Status |
| --- | --- |
| Cluster closure evidence | **VERIFIED** via `withoutVite()` + stale `assertSee` alignment (#215) |
| Release RIP CI on merge | PHPUnit + RIP scripts; **no** `npm run build` (**VERIFIED** workflow) |
| CI proof of SSR with real Vite manifest for these 8 rows | **UNKNOWN** on default PR checks |
| Register classification | **VERIFIED FIXED (test/harness)** — sufficient per policy; residual manifest-integration risk **INFERRED** low for copy-only assertions |

---

## 6. ZipArchive (P-13-05, line 217)

Reclassified **ENVIRONMENT BLOCKED → VERIFIED FIXED (harness)**. Root cause: missing `storage/app/tmp` before bootstrap (#182), not missing `ext-zip` (**VERIFIED** P-13-05). **1/1 PASS** on `b54b0a53` in this prompt + P-13-16 audit.

---

## 7. Remaining unresolved inventory (34 rows)

Full list: `.artifacts/P-12-44-REMAINING-FAILURES.md` @ `b54b0a53`.

| Workstream | Count | Notes |
| --- | ---: | --- |
| WS-10 | 31 | P-12-32 failure text until next focused re-run |
| WS-11 | 2 | UQC owner-decision unit tests — **not** WS-10 agents |
| WS-04 | 1 | Wallet execution — **protected** |

**Representative WS-10 lines still open:** 2–4 (administration — **UNKNOWN** post-#184), 80–81 (incoming email), 91 (POS workflow), 110–111 (support ownership), 115–116 (order identity), 135–136 (wallet-adjacent), 184 (waiting state), 202 (workspace remark), 213 (channel hash), 221–222 (team availability), others per register file.

**Candidate next parallel workstreams:** [.artifacts/P-13-06-NEXT-AGENT-QUEUE.md](.artifacts/P-13-06-NEXT-AGENT-QUEUE.md) (**P-13-17** through **P-13-24**).

---

## 8. Register behaviour

| Aspect | Status |
| --- | --- |
| Preserved | Original 228 test identifiers; `shared_cause` column; P-12-32 failure text on still-failing rows |
| Extended | Evidence + PR columns on 62 newly closed rows; P-13-06 baseline on `P-12-44` header |
| Changed | **62** rows → `VERIFIED FIXED`; line **217** no longer ENVIRONMENT BLOCKED |
| Removed | **None** — no row deletion or collapse |

---

## 9. Checks

| Check | Status |
| --- | --- |
| CSV syntax + 228 unique tests | **PASSED** |
| Classification totals = 228 | **PASSED** |
| Every closed row has evidence string | **PASSED** |
| No unresolved row silently removed | **PASSED** |
| Diff limited to register, ledger, artifacts | **PASSED** (reviewed) |
| P-13-02/03/04/05 focused re-run | **PASSED** |
| P-13-14/15 evidence accepted without re-run | **PASSED** (prior VERIFIED on same SHA) |
| P-13-08–13 closure via P-13-16 audit | **PASSED** |
| Full PHPUnit suite | **NOT RUN** |
| Application / test / config / DB / prod | **NOT RUN** |
| Production data / deploy | **NO — Not performed** |

---

## 10. Elapsed time by stage

| Stage | Elapsed |
| --- | ---: |
| Prechecks + fetch + report read | ~4 min |
| P-13-02/03/04/05 focused PHPUnit | ~0.2 min |
| CSV + `P-12-44` recompute + queue plan | ~3 min |
| Report + ledger + git (pending PR) | ~1 min |
| **Total (pre-PR)** | **~8 min** |

---

## 11. Git / PR / CI

| Field | Value |
| --- | --- |
| Branch | `feature/p13-06-ws10-register-reconcile` |
| Commit | `3888e950a1d1f42cc13cc30b938f955e1bdafe68` |
| PR | [#217](https://github.com/radium-foundation/radium-desk/pull/217) |
| CI | `rip` **PASS**, `release-rip` **PASS** (runs 43366748887 / 43366748905) |
| Verified merge SHA | **`041c616bb086d4758475b1d0111f28c90c4d6f26`** (merge PR #217) |

---

*End of P-13-06 report.*
