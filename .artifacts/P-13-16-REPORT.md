# P-13-16 — Merged implementation evidence audit (read-only)

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-16** |
| Cursor ledger ID | **RadiumDesk-P-13-16** (claimed in this report only; `docs/cursor-prompt-ledger.md` on `origin/main` ends at **RadiumDesk-P-13-13**) |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` |
| Audit worktree | `.worktrees/p13-16-audit` @ detached **`b54b0a53c26f1f123cf37921306929c0fce44543`** (`origin/main`) |
| Primary checkout (verify-only) | `feature/p18-33-unified-wallet-display` @ **`d94816153cd2c89f8175d29a1510b38b4c16df6b`** |
| Known historical baseline | **`f358ab04a9a57623085f19c47c6e0045d9b0a7ea`** — **VERIFIED** ancestor of current `origin/main` |
| Start | **2026-11-13 10:01:43 IST** |
| End | **2026-11-13 10:18:22 IST** |
| Elapsed | **16 minutes 39 seconds** |
| Mode | Read-only audit. No register, application, or test edits. |

---

## 1. Prechecks

| Check | Status | Evidence label |
| --- | --- | --- |
| Repo root, remote `radium-foundation/radium-desk` | **PASSED** | **VERIFIED** |
| `git fetch origin main` | **PASSED** | **VERIFIED** |
| Verified `origin/main` SHA | **`b54b0a53c26f1f123cf37921306929c0fce44543`** — merge **#216** `docs/p13-13-report-merge` (2026-11-13) | **VERIFIED** |
| Isolated worktree `.worktrees/p13-16-audit` | Created @ `origin/main`; clean detached HEAD | **VERIFIED** |
| Testing policy | `docs/RADIUMWEBSITES_TESTING_AND_VALIDATION_POLICY.md` v1.0 on audit worktree | **PASSED** |
| Prompt ledger | Read `docs/cursor-prompt-ledger.md` through **P-13-13** | **PASSED** |
| Prior reports | P-13-02 … P-13-13, P-13-07, P-13-05 (local wallet copy for P-13-05) | **PASSED** |
| Active agent on this artifact | No `P-13-16` report or ledger row before this write | **PASSED** |
| Wallet preservation | `feature/p18-33-unified-wallet-display` @ **`d9481615`**; primary checkout dirty tree not touched by this prompt | **VERIFIED** |
| Canonical CSV / `P-12-44` / ledger file edits | None in audit worktree working tree | **VERIFIED** |

References: [P-13-07](.artifacts/P-13-07-REPORT.md) cluster shard; per-prompt completion reports under `.artifacts/P-13-0*-REPORT.md` on `origin/main`.

---

## 2. PR and merge verification

All listed merge commits are **ancestors** of `b54b0a53` (**VERIFIED** via `git merge-base --is-ancestor`). GitHub `gh pr view` merge metadata matches local.

| PR | Type | Merge commit (full SHA) | Primary implementation commit(s) | Changed scope (summary) |
| ---: | --- | --- | --- | --- |
| [#203](https://github.com/radium-foundation/radium-desk/pull/203) | fix + tests | `7fae635b759eb5ca03a934a776ab91b7b8c7457c` | `e4e5f267` | `RefundRequestTest` agent KPI expectations + `withoutVite()` |
| [#205](https://github.com/radium-foundation/radium-desk/pull/205) | docs | `3da26c0f9c716f524da4fa3890b3228abe09b5b9` | — | `.artifacts/P-13-02-REPORT.md` only |
| [#204](https://github.com/radium-foundation/radium-desk/pull/204) | app fix | `44db017d4a7d4203a5ace32b78de1ae007f144c7` | `a7e3798a` (squash) | `ServiceCaseAssignmentService` ready-queue broadcast |
| [#206](https://github.com/radium-foundation/radium-desk/pull/206) | test fix | `f417adc9bfd27e38b92d5f30468eaa2255153470` | `06942ef4` | `ServiceCaseAssignmentTest` ready-eligible fixture |
| [#207](https://github.com/radium-foundation/radium-desk/pull/207) | test fix | `f358ab04a9a57623085f19c47c6e0045d9b0a7ea` | `810b97af` | `DashboardLiveRowVisibilityTest` (not a matrix row) |
| [#208](https://github.com/radium-foundation/radium-desk/pull/208) | app + tests | `a99eda54bd4bb3af3c93bfcae6352f5293344953` | `8214403c`, `c7fcc1c5` | Statutory issuance cluster (7 rows) |
| [#209](https://github.com/radium-foundation/radium-desk/pull/209) | app + tests | `700526c68056f0dde1864de60b3671257dae83f5` | `03e5e825` | Customer 360 drawer cluster (9 rows) |
| [#210](https://github.com/radium-foundation/radium-desk/pull/210) | app | `9da7f93863116ce5792ccdf2c7ec9525ea2eec76` | `fb23d8cb` | Interakt / WhatsApp timeline (6 rows) |
| [#211](https://github.com/radium-foundation/radium-desk/pull/211) | app | `f3eeabbd377572bd7cec5a4b22b375422084c801` | `f9cee8ba` | RBP desk fulfilment exclusion (3 rows) |
| [#212](https://github.com/radium-foundation/radium-desk/pull/212) | docs | `3fd6975c5376d37f1806de0c20490b44d31614da` | — | P-13-11 report merge SHA |
| [#213](https://github.com/radium-foundation/radium-desk/pull/213) | app | `aaeb19354a63b62be5c444fbd670a761f72f8802` | `10aac3e3` | RadiumBox backfill commands (2 rows) |
| [#215](https://github.com/radium-foundation/radium-desk/pull/215) | test-only | `749f96e1f674ebb14dcca66d99214b9e56e9ae3c` | `d3555ce4` | Sidebar/settings label assertions (8 rows) |
| [#214](https://github.com/radium-foundation/radium-desk/pull/214) | docs | `a7df720b606aea90e479d171c211d4857b61bdab` | — | P-13-12 report merge SHA |
| [#216](https://github.com/radium-foundation/radium-desk/pull/216) | docs | `b54b0a53c26f1f123cf37921306929c0fce44543` | `32298d5f` | P-13-13 report merge SHA finalize |

**CI:** Cluster reports cite **Release RIP** workflow success on merge commits. That workflow runs PHPUnit subsets and RIP shell tests only (**VERIFIED** from `.github/workflows/release-rip-ci.yml`); it does **not** run `npm run build` (**VERIFIED**). Separate **Statutory cancellation PHPUnit** workflow includes `npm ci && npm run build` (**VERIFIED**); not invoked for every P-13 PR by default (**INFERRED**).

---

## 3. Double-counting and shard integrity

P-13-07 assigned **35** WS-10 rows across P-13-08 … P-13-13 with **disjoint** CSV line sets. Union of audited cluster lines:

`20, 164–169, 16–17, 21–23, 82, 162–163, 175, 87–89, 185–187, 218, 224–225, 127–128, 18, 70, 102, 123, 176–177, 182–183`

**Count = 35. No duplicate line appears in more than one cluster** (**VERIFIED** against shard tables in P-13-07 and cluster reports).

Earlier fixes add matrix lines **134, 126, 161** (not in the 35-row shard). **P-13-05** owns CSV line **217** (WS-03) exclusively per P-13-07.

**#207** touches `DashboardLiveRowVisibilityTest` only; it is **not** a canonical matrix row (**VERIFIED** P-13-07 §4).

---

## 4. Row-by-row evidence classification

Legend:

- **M+T** — merged code or test fix with focused PHPUnit evidence in completion report (or ledger).
- **T+T** — test-only correction with focused evidence.
- **H+T** — harness / fixture / `withoutVite()` with focused evidence.
- **A+T** — application defect fix with focused evidence.

Register recommendation assumes prior P-12-32 failure text; this audit does **not** edit the CSV.

### 4.1 P-13-02, P-13-03, P-13-04 (3 rows)

| Line | Test method | Merged via | Evidence | Classification | Register recommendation |
| ---: | --- | --- | --- | --- | --- |
| 134 | `RefundRequestTest::test_dashboard_shows_refund_status_counts` | #203 | P-13-02: **25/25 PASS** (140 assertions) @ merge `7fae635b` | **H+T** / stale KPI expectation | **VERIFIED FIXED** — reclassify from STILL FAILING |
| 126 | `QueueIntegrityLiveRefreshTest::test_manual_assignment_broadcasts_remove_from_ready_queue` | #204 | P-13-03: **8/8 PASS** on file | **A+T** | **VERIFIED FIXED** |
| 161 | `ServiceCaseAssignmentTest::test_dashboard_displays_owner_first_name` | #206 | P-13-04: **1/1** + **31/31 PASS**; P-13-04 report §7 | **H+T** (fixture eligibility) | **VERIFIED FIXED** |

**Not re-run on `b54b0a53` in P-13-16** (**NOT RUN**). Closure rests on merged reports + ancestry (**INFERRED** still passing on current `main`).

**Artifact gap:** `.artifacts/P-13-04-REPORT.md` on `origin/main` still shows pending PR footer; merge **`f417adc9`** and focused results in report body supersede footer (**VERIFIED** git + §7).

### 4.2 P-13-08 — statutory issuance (7 rows)

| Line | Test method | Fix nature | Report evidence | Label |
| ---: | --- | --- | --- | --- |
| 20 | `Customer360StatutoryInvoiceActionsTest::test_customer_360_shows_failed_when_b2b_statutory_data_is_incomplete` | App — `StatutoryBillingAddressComposer` IRP line2 guard | Cluster **8/8** + composer unit tests | **A+T** |
| 164 | `EInvoiceIssuancePathTest::test_commerce_b2b_service_is_blocked_in_phase_a_and_queued_in_phase_b` | Test harness — GSTIN/POS fixtures |同上 | **T+T** |
| 165 | `IrnInputSnapshotTest::test_commerce_ingest_preserves_valid_uqc_and_rejects_invalid` | App — channel ingest UQC | **11/11** `IrnInputSnapshotTest` | **A+T** |
| 166 | `IrnInputSnapshotTest::test_commerce_uqc_and_structured_billing_are_snapshotted_at_mint` | App — UQC persist |同上 | **A+T** |
| 167 | `RdServiceNetPhase1CleanTest::test_invalid_gstin_and_missing_statutory_data_fail_closed` | Test harness — B2C downgrade contract | Cluster filter | **T+T** |
| 168 | `ServiceGstSplitIssuanceTest::test_b2b_split_follows_seller_state_and_place_of_supply_not_billing_state` | Test harness — fixture alignment | Cluster filter | **T+T** |
| 169 | `ServiceStatutoryInvoiceReconciliationBacklogTest::test_rdservice_net_ra_prefix_orders_are_reconciled` | Test harness — auto-issue approval seed | Cluster filter | **T+T** |

**Register recommendation (all 7):** **VERIFIED FIXED** @ #208 merge `a99eda54`.

**Artifact gap:** `.artifacts/P-13-08-REPORT.md` still lists merge SHA as pending; git and #208 close the gap (**VERIFIED**). No P-13-16 re-run (**NOT RUN**).

### 4.3 P-13-09 — Customer 360 (9 rows)

| Line | Test method | Fix nature | Report evidence | Label |
| ---: | --- | --- | --- | --- |
| 16 | `BonvoiceWebhookTest::test_customer_360_health_card_shows_latest_call_status_and_relative_time` | App — Last Call row | **9/9** cluster PASS | **A+T** |
| 17 | `BonvoiceWebhookTest::test_customer_360_timeline_tab_renders_ivr_call_event` | App — `bi-telephone` milestone |同上 | **A+T** |
| 21 | `Customer360RadiumBoxSyncTest::test_customer_360_shows_last_synced_freshness_when_serial_exists` | App — sync chip markup |同上 | **A+T** |
| 22 | `Customer360RadiumBoxSyncTest::test_customer_360_shows_synchronization_history` | App + harness — `Queue::fake()`; heading copy |同上 | **A+T** + **H+T** |
| 23 | `Customer360RadiumBoxSyncTest::test_manual_radiumbox_sync_enriches_order_and_returns_refreshed_drawer` | App — check icon markup |同上 | **A+T** |
| 82 | `InquiryLifecyclePhase1Test::test_customer_360_shows_case_and_enquiry_type_for_inquiry` | App — Type row; **order id removed from search index** |同上 | **A+T** |
| 162 | `ServiceCaseStatusSupportAppointmentSyncTest::test_closed_incident_does_not_expose_active_scheduled_support_card` | App — hide empty support section |同上 | **A+T** |
| 163 | `ServiceCaseStatusSupportAppointmentSyncTest::test_closed_incident_hides_scheduled_support_card_after_status_sync` | App — same; statutory exception **not reproduced** post-fix |同上 | **A+T** |
| 175 | `SupportAppointmentBookingTest::test_customer_360_hides_support_appointments_section_when_none_exist` | App — omit section |同上 | **A+T** |

**Register recommendation (all 9):** **VERIFIED FIXED** @ #209 merge `700526c6`.

**P-13-09 order-id / order-identifier finding (out of shard):**

| Topic | Finding | Label |
| --- | --- | --- |
| Assigned row for `Customer360DrawerTest` order-id vs `order-identifier` link | **None** — `Customer360DrawerTest` does not appear in `P-12-32-matrix-reconciliation.csv` on `origin/main` (grep **VERIFIED**) | **VERIFIED** |
| P-13-09 report “additional regressions” | `Customer360DrawerTest` **2 failures** cited as **pre-existing** (assertion vs `order-identifier` link + Vite on dashboard host) | **VERIFIED** (report text) |
| Row 82 closure | Fixed inquiry **Type** display and **removed internal order id from `data-c360-search-index`**; distinct from drawer order-id copy tests | **VERIFIED** |
| Whether order-id assertion ambiguity closes any assigned row | **No** — does not close an assigned matrix row; treat as **residual / unsharded** test hygiene | **VERIFIED** |

**Residual risk:** Full `Customer360RadiumBoxSyncTest` **19/22 PASS** in report — 3 failures attributed to Vite/manifest in worktree (**INFERRED**), not assigned cluster methods (**VERIFIED** report). No P-13-16 re-run (**NOT RUN**).

**Artifact gap:** P-13-09 report merge footer still “Pending”; #209 merge **`700526c6`** (**VERIFIED** git).

### 4.4 P-13-10 — Interakt / WhatsApp (6 rows)

| Line | Test method | Fix nature | Report evidence | Label |
| ---: | --- | --- | --- | --- |
| 87–89 | `InteraktWebhookTest` (3 methods) | App — operator timeline visibility | **6/6** cluster + **33/33** regression | **A+T** |
| 185–187 | `WhatsAppConversationFeatureTest` (1), `WhatsAppTemplateDispatcherTest` (1) | App — unified timeline summary fields |同上 | **A+T** |

**Register recommendation (all 6):** **VERIFIED FIXED** @ #210 merge `9da7f938`.

**Artifact gap:** Report merge footer pending; git confirms merge (**VERIFIED**). Live messaging / production **NOT RUN** (report attestation **VERIFIED**).

### 4.5 P-13-11 — RBP hardware (3 rows)

| Line | Test method | Fix nature | Report evidence | Label |
| ---: | --- | --- | --- | --- |
| 218 | `HardwareFulfilmentPrefixGuardTest::test_rbp_never_opens_hardware_fulfilment_even_with_physical_lines` | App — `HardwareFulfilmentEligibility` excludes RBP | **3/3** cluster + **16/16** regressions | **A+T** |
| 224 | `OrderProductOrderTest::test_non_rin_non_config_prefix_orders_are_not_hardware` | App — `Order::isHardwareOrderId` |同上 | **A+T** |
| 225 | `OrderProductOrderTest::test_rbp_is_never_hardware_even_if_config_lists_rbp` | App — RBP exclusion |同上 | **A+T** |

**RBP product-prefix vs desk fulfilment split in tests** (**VERIFIED** on `b54b0a53`):

- `BusinessOrderIdTest::test_radiumbox_hardware_prefixes_include_rbp` still asserts `BusinessOrderId::isHardwareByPrefix('RBP1')` **true** (product metadata).
- Cluster + `BusinessOrderIdTest` regression **16/16 PASS** per report — desk fulfilment / `Order::isHardwareOrderId` **false** for RBP.

**Register recommendation (all 3):** **VERIFIED FIXED** @ #211 merge `f3eeabbd`.

### 4.6 P-13-12 — RadiumBox backfill (2 rows)

| Line | Test method | Fix nature | Report evidence | Label |
| ---: | --- | --- | --- | --- |
| 127 | `BackfillRadiumBoxOrdersCommandTest::test_order_with_manual_serial_and_missing_device_model_is_skipped_when_device_model_assigned` | App — `isMissingDeviceEnrichment()` for `already_complete` | **2/2** cluster + **30/30** class regression | **A+T** |
| 128 | `BackfillReadyQueueCommandTest::test_evaluates_eligibility_for_validated_unassigned_case` | App — no enrichment job when only non-device gaps |同上 | **A+T** |

**Backfill boundary** (**VERIFIED** code on `main`): `BackfillRadiumBoxOrdersCommand` and `BackfillReadyQueueCommand` gate on `Order::isMissingDeviceEnrichment()`; `needsEnrichment()` unchanged for jobs/ingest per report.

**Live backfill / production data:** **NO — Not performed** (report **VERIFIED**). P-13-16 did not run artisan backfill commands (**NOT RUN**).

**Register recommendation (both):** **VERIFIED FIXED** @ #213 merge `aaeb1935`.

### 4.7 P-13-13 — sidebar / settings labels (8 rows)

| Line | Test method | Fix nature | Report evidence | Label |
| ---: | --- | --- | --- | --- |
| 18, 70, 102, 123, 176, 177, 182, 183 | See P-13-13 report | **Test-only** — `withoutVite()` + stale `assertSee` / nav keys | **8/8** cluster + **69/69** six-class regression | **T+T** / **H+T** |

**Product labels:** Report states **no** Blade/permission production changes (**VERIFIED** diff `d3555ce4` — tests + report only).

**Vite / manifest integration:**

| Check | Result | Label |
| --- | --- | --- |
| Cluster evidence uses `withoutVite()` in six test classes | **VERIFIED** per P-13-13 report |
| Release RIP CI on #215 | PHPUnit + RIP scripts; **no** `npm run build` | **VERIFIED** workflow file |
| Repo workflow with `npm run build` on PR path | Only `statutory-cancellation-phpunit.yml` (dispatch/branch-specific) | **VERIFIED** |
| Whether CI proves SSR with real Vite manifest for these 8 rows | **UNKNOWN** on default PR checks; deploy docs still require local `npm run build` | **INFERRED** gap for manifest-backed SSR proof |
| Risk to register closure | Low for **stale copy** assertions; **residual** if production nav regressed without test catching (tests aligned to current product) | **INFERRED** |

**Register recommendation (all 8):** **VERIFIED FIXED** (test correction) @ #215 merge `749f96e1`.

---

## 5. P-13-05 ZipArchive (CSV line 217, WS-03)

| Item | Evidence | Label |
| --- | --- | --- |
| Register status on `origin/main` @ `f358ab04` | **ENVIRONMENT BLOCKED** (sole env-blocked row) | **VERIFIED** P-13-05 / P-13-07 |
| Root cause | Missing `storage/app/tmp` before `tests/bootstrap.php` (#182); not missing `ext-zip` | **VERIFIED** P-13-05 |
| Prior focused pass | P-13-05 @ `f358ab04`: **1/1 PASS**, 2 assertions | **VERIFIED** P-13-05 report |
| P-13-16 on `b54b0a53` worktree | `bin/phpunit-isolated --filter test_validator_flags_minimal_package_missing_excel_required_parts` → **passed**, 2 assertions, 188 ms (launcher parse warning unchanged) | **VERIFIED** |
| Application code change for Zip row | None required; harness on `main` | **VERIFIED** |

**Register recommendation:** Reclassify line **217** from **ENVIRONMENT BLOCKED** to **VERIFIED FIXED (harness)**.

---

## 6. Duplicated, ambiguous, or unsupported closure claims

| Claim | Audit finding | Disposition |
| --- | --- | --- |
| “Merge alone proves row fixed” | All 38 WS-10 audited rows have **focused PHPUnit counts** in cluster or P-13-02/03/04 reports except where P-13-16 explicitly **NOT RUN** re-verify on `b54b0a53` for rows 134/126/161 | **Supported with INFERRED current-main** for early fixes |
| P-13-08/09/10 report merge SHA “Pending” | Merges #208–#210 landed; artifacts partially stale | **INFERRED** closure from git; update artifacts in a docs-only prompt optional |
| P-13-09 closes `Customer360DrawerTest` order-id failures | **Unsupported** for matrix — not assigned | **Do not count** toward 35-row shard |
| P-13-13 proves Vite-integrated SSR | `withoutVite()` used throughout | **Insufficient for manifest integration**; sufficient for stale-copy correction |
| P-12-37 workspace 16-row stale register (P-13-07 §5.2) | **Out of scope** for this audit’s 38+1 rows; still **NOT RUN** on `b54b0a53` | **Stale register candidate** until focused rerun |
| Full WS-10 remaining count | CSV on `main` still reflects pre-cluster recount | **NOT UPDATED** (by design) |

---

## 7. Checks summary

| Check | Status |
| --- | --- |
| Repository / worktree / `origin/main` SHA | **PASSED** |
| PR #203–#207, #208–#211, #213, #215 ancestry | **PASSED** |
| Report PR #205, #212, #214, #216 (docs) | **PASSED** (evidence-only) |
| Shard double-count | **PASSED** |
| Row-level focused evidence reviewed | **PASSED** |
| P-13-05 ZipArchive on current `main` | **PASSED** (minimal focused rerun) |
| P-13-02/03/04 on `b54b0a53` | **NOT RUN** |
| Full PHPUnit suite | **NOT RUN** |
| Register / CSV / ledger edits | **NOT RUN** |
| Application / test edits | **NOT RUN** |
| Production / deploy / backfill / live integrations | **NOT RUN** |

---

## 8. Preservation and attestation

| Item | Status |
| --- | --- |
| Canonical `.artifacts/P-12-32-matrix-reconciliation.csv` | **Unchanged** |
| `.artifacts/P-12-44-REMAINING-FAILURES.md` | **Unchanged** |
| `docs/cursor-prompt-ledger.md` | **Unchanged** (P-13-16 claimed here only) |
| Application / test / config on primary checkout | **Unchanged** by P-13-16 |
| Wallet branch `feature/p18-33-unified-wallet-display` @ **`d9481615`** | **Preserved** |

**NO — Not performed:** register edits, commits, PRs, merges, deployment, production changes, live backfill, statutory issuance, WS-04 OPM work.

---

## 9. Canonical register disposition summary (audited rows only)

| Group | Lines | Recommended CSV disposition |
| --- | --- | --- |
| P-13-02 | 134 | VERIFIED FIXED (test/harness) |
| P-13-03 | 126 | VERIFIED FIXED (application) |
| P-13-04 | 161 | VERIFIED FIXED (test harness) |
| P-13-08 | 20, 164–169 | VERIFIED FIXED (mixed app + test) |
| P-13-09 | 16–17, 21–23, 82, 162–163, 175 | VERIFIED FIXED (application + harness) |
| P-13-10 | 87–89, 185–187 | VERIFIED FIXED (application) |
| P-13-11 | 218, 224–225 | VERIFIED FIXED (application) |
| P-13-12 | 127–128 | VERIFIED FIXED (application) |
| P-13-13 | 18, 70, 102, 123, 176–177, 182–183 | VERIFIED FIXED (test-only) |
| P-13-05 | 217 | VERIFIED FIXED (harness); was ENVIRONMENT BLOCKED |

**Total audited rows:** **39** (38 WS-10 shard + 1 WS-03).

After a register update pass (not this prompt), analytical WS-10 STILL FAILING should drop by **38** from the P-13-07 baseline of **92** unresolved shard rows, plus reconcile P-13-07 **stale-register 23** and **31 unassigned** separately (**INFERRED** arithmetic; CSV not recomputed here).

---

*End of P-13-16 report.*
