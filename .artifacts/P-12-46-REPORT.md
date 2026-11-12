# P-12-46 — WS-11 TeamPerformance member-stats harness

**Programme (IST):** 2026-11-12  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Worktree:** `.worktrees/p12-46-next-cluster` → `feature/p12-46-next-cluster`  
**Verification HEAD:** `origin/main` @ **`7798499107bd7195210714a10dbe2229aa02a93a`** (post **#196**)  
**Wallet checkout:** `d9481615` — **verified, not modified**  
**Full PHPUnit:** **NOT RUN** (P-12-40 policy)

---

## 1. Ledger

| Field | Value |
| --- | --- |
| Prompt | **P-12-46** |
| Ledger ID | **RadiumDesk-P-12-46** (**CLAIMED**) |
| Claim artifact | `.artifacts/ledger-claim-RadiumDesk-P-12-46.md` |
| Scope | Close **P-12-45** on main; matrix reconcile; **one** WS-11 cluster remediation |

---

## 2. Repository state @ verify

| Item | Value |
| --- | --- |
| Reported pre-P12-45 `origin/main` | `49775d89bba9201a3b4d50ab4aee05e727c51262` |
| Current `origin/main` | **`77984991`** (fetched 2026-11-12) |
| P-12-46 worktree | created from `origin/main` @ `77984991` |
| Wallet worktree | `feature/p18-33-unified-wallet-display` @ **`d9481615`** — **verified, not modified** |
| `feature/p12-45-statutory-pdf-logo` | **not touched** (merged) |

---

## 3. PR #196 / P-12-45 closeout

| Field | Value |
| --- | --- |
| `gh pr view 196` | **state: MERGED** |
| `mergedAt` | `2026-11-12T16:45:27Z` |
| `mergeCommit.oid` | **`7798499107bd7195210714a10dbe2229aa02a93a`** |
| `headRefName` | `feature/p12-45-statutory-pdf-logo` |
| Checks | **rip** SUCCESS · **release-rip** SUCCESS |
| Ancestry | `git merge-base --is-ancestor 77984991 origin/main` → **OK** |
| Handler `99019cb9` | **not duplicated** (logo fix lives in merged `SimplePdfRenderer` only) |

**P-12-45 programme:** **CLOSED** on `main` via #196. Four matrix logo rows reconciled to **VERIFIED FIXED** (evidence P-12-45 branch matrix CSV).

---

## 4. Merge SHA order on `main` (newest first, sampled)

1. **`77984991`** — #196 P-12-45 statutory PDF logo layout  
2. **`49775d89`** — tip before P-12-45 integration (owner-reported baseline)  
3. **`d709e856`** — #194 P-12-42 (ancestor)

---

## 5. Matrix reconciliation (post #196)

| Metric | Pre-P12-46 (P-12-44 register) | Post reconcile |
| --- | ---: | ---: |
| VERIFIED FIXED | 109 | **115** (+4 logo #196, +2 WS-11) |
| STILL FAILING | 118 | **112** |
| ENVIRONMENT BLOCKED | 1 | **1** |
| Rows | 228 | 228 |

**Logo rows (#196):**

| Test | PR | Classification |
| --- | --- | --- |
| `StatutoryInvoicePdfRegressionContractTest::test_contract_non_fitting_small_serial_set_is_all_or_nothing_to_annexure` | #196 | VERIFIED FIXED |
| `…test_contract_small_serial_set_renders_inline_only_when_measured_block_fits` | #196 | VERIFIED FIXED |
| `StatutoryInvoicePdfSerialCapacityTest::test_ten_or_fewer_serials_use_main_page_only` | #196 | VERIFIED FIXED |
| `…test_multipage_invoice_moves_serials_to_annexure_when_product_table_fills_page_one` | #196 | VERIFIED FIXED |

Artifact: `.artifacts/P-12-32-matrix-reconciliation.csv`  
Register: `.artifacts/P-12-44-REMAINING-FAILURES.md`

---

## 6. Cluster selection (one)

| Priority tier | Choice |
| --- | --- |
| Rule | customer-facing > payments > auth > ops; avoid wallet/WS-04 without Owner; no overlap with open prompt worktrees |
| **Selected** | **WS-11 `TeamPerformanceIntelligenceTest::test_team_member_sees_only_own_stats`** |
| Rationale | Smallest **independent** cluster per P-12-32 priority #1; agent-facing “My Performance” (customer-work metrics); **2** stale matrix rows; no wallet/payment; distinct from P-12-45 statutory worktree |
| Deferred | WS-10 dashboard bulk (109 rows); WS-04 OPM tail (owner gate) |

---

## 7. Root cause

| Item | Detail |
| --- | --- |
| Surface | `assertDontSee('99')` failure on otherwise **200** My Performance page |
| Mechanism | Footer **Build** short SHA (`77984991`) contains substring **`99`**; unrelated to peer agent `communication_events_count` |
| Classification | **TEST HARNESS** false positive (P-12-34 closed sessions + `withoutVite()` already on `main`; IRA weekly copy fixed in **#178**) |
| Not | App data leak (page shows **7** under Customer replies only) |

---

## 8. Reproduction

```bash
cd .worktrees/p12-46-next-cluster
./bin/phpunit-isolated --filter test_team_member_sees_only_own_stats \
  tests/Feature/TeamPerformanceIntelligenceTest.php
```

**Pre-fix @ `77984991`:** FAIL — HTML includes `Build 77984991` → matches forbidden `99`.

---

## 9. Remediation

| File | Change |
| --- | --- |
| `tests/Feature/TeamPerformanceIntelligenceTest.php` | Peer fixture uses distinctive count **4242**; assertions scoped to **Customer replies** + `assertDontSee('4242')` |

**No application code.** Does not modify wallet worktree or P-12-45 code paths.

---

## 10. Post-fix validation (`bin/phpunit-isolated`)

| Suite | Result |
| --- | --- |
| `TeamPerformanceIntelligenceTest` | **13/13 PASS** (~15.5s) |
| `TeamTelegramWorkAssistantTest` (WS-11 regression) | **8/8 PASS** (~9.6s) |
| Full suite | **NOT RUN** |

---

## 11. Matrix row updates (P-12-46)

| Test | Before | After | Evidence |
| --- | --- | --- | --- |
| `test_team_member_sees_only_own_stats` | STILL FAILING | **VERIFIED FIXED** | P-12-46 phpunit-isolated @ `77984991` |
| `test_ira_generates_contextual_insights` | STILL FAILING (stale) | **VERIFIED FIXED** | **#178** / PASS on `77984991` (reconcile only) |

---

## 12. PR / CI

| Item | Value |
| --- | --- |
| Branch | `feature/p12-46-next-cluster` |
| PR | **[#197](https://github.com/radium-foundation/radium-desk/pull/197)** @ `66eecd4c` |
| Expected checks | rip + release-rip |

---

## 13. Policy / wallet

| Item | Status |
| --- | --- |
| `docs/PHPUNIT_FULL_SUITE_AUTHORIZATION.md` | Honored — no full suite |
| Wallet `d9481615` | Read-only verify only |
| `bin/phpunit-safe` / `bin/phpunit-isolated` | Used for validation |

---

## 14. Residual risks / backlog

- **WS-11:** 2 matrix rows remain STILL FAILING (non–TeamPerformance; see register).
- **WS-10:** 109 rows — next largest bucket; needs split-by-class programme.
- **Build footer:** Any `assertDontSee` on short digit strings may collide with `Build {sha}` — prefer scoped selectors or distinctive fixture values.
- **Imagick / statutory non-logo:** Rows in register unchanged post #196.

---

## 15. Coordinator summary

- **#196 MERGED** — P-12-45 **closed**; logo matrix **4/4** reconciled; merge SHA on `main`.
- **P-12-46** claimed; matrix **115 / 112 / 1**; remaining-failures register refreshed @ `77984991`.
- **One cluster** remediated: TeamPerformance member isolation test (**test-only**).
- Deliverables on branch: this report, matrix CSV, ledger row, code fix.
- **Blockers:** none (programme proceeded after #196 merge).
