# P-13-07 — WS-10 failure reconciliation and parallel workstreams

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-07** |
| Cursor ledger ID | **RadiumDesk-P-13-07** (claimed in this report only) |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` |
| Start (this refresh) | **2026-11-13 08:57:57 IST** |
| End (this refresh) | **2026-11-13 08:58:51 IST** |
| Elapsed (this refresh) | **0 minutes 54 seconds** |
| Prior pass | **2026-11-13 01:24:36 IST** → **2026-11-13 01:34:27 IST** (9 minutes 51 seconds) |
| Mode | Read-only planning. No fixes. |

Canonical ledger file `docs/cursor-prompt-ledger.md` on `origin/main` still ends at **RadiumDesk-P-13-04**. This prompt keeps **RadiumDesk-P-13-07**. **RadiumDesk-P-13-05** is claimed only in the local `.artifacts/P-13-05-REPORT.md` (WS-03 ZipArchive row) and is not on `origin/main`. **RadiumDesk-P-13-06** has no ledger row and no worktree. The ledger file was not appended.

## 0. Refresh at 08:57 IST

`git fetch origin main` at **2026-11-13 08:57:57 IST** returned the same tip: **`f358ab04a9a57623085f19c47c6e0045d9b0a7ea`**. No commit after PR #207. PR #203, #204, #205, #206, and #207 remain ancestors. The canonical CSV is still 228 rows: **132** VERIFIED FIXED, **95** STILL FAILING, **1** ENVIRONMENT BLOCKED; WS-10 STILL FAILING is still **92**.

No worktree `p13-05` through `p13-13` exists. No agent has claimed P-13-08 through P-13-13. P-13-05’s local report owns only CSV line 217 (WS-03) and does not overlap the clusters in section 6. Wallet HEAD is still `d9481615`.

The reconciliation and clusters below are unchanged from the 01:24 IST pass. PHPUnit was not re-run.

---

## 1. Repository prechecks

| Check | Result |
| --- | --- |
| Repo root | `/Users/ravi/RadiumWebsites/radium-desk` **PASSED** |
| Primary checkout | `feature/p18-33-unified-wallet-display` @ **`d94816153cd2c89f8175d29a1510b38b4c16df6b`** |
| Upstream | `origin/feature/p18-33-unified-wallet-display` |
| Remote | `github.com:radium-foundation/radium-desk` |
| Wallet dirty tree | Present before this prompt (modified and untracked wallet/refund files). **Not modified by P-13-07** |
| `git fetch origin main` | **PASSED** |
| Verified `origin/main` | **`f358ab04a9a57623085f19c47c6e0045d9b0a7ea`** (2026-11-13 01:19:56 +0530) — `Merge pull request #207 from radium-foundation/feature/p13-03-test-followup` |
| Cached `origin/main` before fetch | Already `f358ab04` |
| Local `main` ref | `6efee89e` (stale). Not used as baseline |
| Checkout of `main` | **NOT RUN** (would move the wallet worktree) |

No worktree is named `p13-05` through `p13-13` (re-checked 08:57 IST). Existing `p13-01` through `p13-04` worktrees point at merged branches. They do not own the clusters proposed below. **P-13-05** has a local read-only report for the WS-03 ZipArchive row only.

---

## 2. Policy

Read `docs/RADIUMWEBSITES_TESTING_AND_VALIDATION_POLICY.md` on the wallet checkout (same v1.0 text as the repo policy). This prompt is planning only: Level 1–4 execution is **NOT RUN**. A full PHPUnit run is **NOT RUN**. A merge is not treated as a fix.

---

## 3. Canonical register

Read from `origin/main` @ `f358ab04`. Not edited.

| Artifact | Role |
| --- | --- |
| `.artifacts/P-12-32-matrix-reconciliation.csv` | Canonical matrix. 228 rows |
| `.artifacts/P-12-44-REMAINING-FAILURES.md` | Canonical remaining-failure register, last recomputed through **P-13-01** @ `b69ee268` / post-#201 |

Register totals still stored on `origin/main`:

| Classification | Count |
| --- | ---: |
| VERIFIED FIXED | 132 |
| STILL FAILING | 95 |
| ENVIRONMENT BLOCKED | 1 |

| Workstream inside STILL FAILING | Count |
| --- | ---: |
| WS-10 | 92 |
| WS-11 | 2 |
| WS-04 | 1 |

The `shared_cause` column repeats the test id. It is not an independent root-cause field. Failure text used below is from the P-12-32 per-class PHPUnit logs at **`27b03d19`** (`.worktrees/p12-32-matrix-reconcile/.artifacts/phpunit-full-*.log`), unless a later focused run superseded it.

---

## 4. PR merge and test evidence

All five SHAs are ancestors of `origin/main` `f358ab04`.

| PR | Merge SHA | What landed | Test evidence | CI |
| --- | --- | --- | --- | --- |
| [#203](https://github.com/radium-foundation/radium-desk/pull/203) | `7fae635b759eb5ca03a934a776ab91b7b8c7457c` | `tests/Feature/RefundRequestTest.php` + ledger | P-13-02 focused run: **25 tests, 140 assertions, PASS**. Command recorded in `.artifacts/P-13-02-REPORT.md` | `rip` SUCCESS, `release-rip` SUCCESS |
| [#205](https://github.com/radium-foundation/radium-desk/pull/205) | `3da26c0f9c716f524da4fa3890b3228abe09b5b9` | Docs report only (`.artifacts/P-13-02-REPORT.md`) | No application or test change | Not used as a fix |
| [#204](https://github.com/radium-foundation/radium-desk/pull/204) | `44db017d4a7d4203a5ace32b78de1ae007f144c7` (squash, not a merge commit) | `ServiceCaseAssignmentService::applyAssignment()` calls `serviceCaseQueueMembershipChanged()` when Admin Ready membership drops | P-13-03: `QueueIntegrityLiveRefreshTest` **8/8 PASS** | `rip` SUCCESS, `release-rip` SUCCESS |
| [#207](https://github.com/radium-foundation/radium-desk/pull/207) | `f358ab04a9a57623085f19c47c6e0045d9b0a7ea` | `DashboardLiveRowVisibilityTest::test_manual_assignment_broadcast_removes_row_from_ready_queue_for_other_admins` expects `ServiceCaseCreated` remove. Report text updated | P-13-03 report after follow-up: that file **6/6 PASS**. This test is **not** a STILL FAILING register row | `rip` SUCCESS, `release-rip` SUCCESS |
| [#206](https://github.com/radium-foundation/radium-desk/pull/206) | `f417adc9bfd27e38b92d5f30468eaa2255153470` | `ServiceCaseAssignmentTest::test_dashboard_displays_owner_first_name` fixture (validated serial `B47C11929`, enrichment sync, snapshot count guard) | P-13-04: owner method **1/1 PASS**; `ServiceCaseAssignmentTest` + `DashboardClassificationIndexTest` **31/31 PASS** | `rip` SUCCESS, `release-rip` SUCCESS |

`rip` / `release-rip` are CI workflow successes. They are not a full PHPUnit run and are not, by themselves, row-level proof. Row closure below uses the focused PHPUnit recorded in those reports.

P-13-07 did **not** re-execute those tests. **Full PHPUnit: NOT RUN.**

### Owner-display reconciliation (P-13-03 vs P-13-04 vs #207)

One register row, not three defects.

| Item | Evidence | Disposition |
| --- | --- | --- |
| CSV line **161** `ServiceCaseAssignmentTest::test_dashboard_displays_owner_first_name` | P-13-03 saw **FAIL** on baseline `489fd91e` and after the broadcast fix (empty Ready grid). P-13-04 classified it as a stale harness: serial `SN-OWNER-1` never entered `action_required`. Post-fix **PASS** (1/1 and 31/31) | **VERIFIED FIXED** by P-13-04 / #206. Stale test fixture |
| P-13-03 did not introduce that failure | Same failure on the pre-fix baseline | Not a regression from the broadcast change |
| #207 | Changes `DashboardLiveRowVisibilityTest` only. `assertNotDispatched(ServiceCaseCreated)` was stale against the new remove fan-out | Harness correction for a **different** test. Does **not** close row 161 |

---

## 5. Reconciled counts

The CSV and register on `origin/main` still say **92** WS-10 STILL FAILING. The counts below are analytical. They were not written back.

Mutually exclusive disposition of those **92** rows:

| Category | Count | Meaning |
| --- | ---: | --- |
| Verified fixed, stale test expectation | **2** | Rows 134 and 161. Focused PASS after #203 and #206 |
| Verified fixed, application defect | **1** | Row 126. Focused PASS after #204 |
| Stale register (ancestor PASS, not re-run on `f358ab04`) | **23** | Dashboard grid/index **7** and workspace close/resolve **16**. Register still says STILL FAILING |
| Unresolved | **66** | No pass evidence after the P-12-32 failure log, or the test file changed without a later pass record |
| Environment blocker inside WS-10 | **0** | — |
| **Sum** | **92** | — |

**Remaining unresolved WS-10 count: 66.**

The 23 stale-register rows are not open defects on the evidence below. They still need a focused re-run on `f358ab04` before anyone edits the CSV. This prompt did not re-run them.

### 5.1 Verified fixed by P-13-02, P-13-03, P-13-04

| CSV line | Test | Prompt | Class | Evidence |
| --- | --- | --- | --- | --- |
| 134 | `Tests.Feature.RefundRequestTest::test_dashboard_shows_refund_status_counts` | P-13-02 #203 | Stale expectation. Agent support-queue KPI omits the legacy Refunds label; the test still asserted `assertSee('Refunds')`. `withoutVite()` added | **VERIFIED** 25/25 PASS in P-13-02 report |
| 126 | `Tests.Feature.QueueIntegrityLiveRefreshTest::test_manual_assignment_broadcasts_remove_from_ready_queue` | P-13-03 #204 | Application defect. Manual assign updated snapshot membership and did not emit `ServiceCaseCreated` remove when hybrid assignment events are off | **VERIFIED** 8/8 PASS. P-12-32 message was `ServiceCaseCreated` not dispatched |
| 161 | `Tests.Feature.ServiceCaseAssignmentTest::test_dashboard_displays_owner_first_name` | P-13-04 #206 | Stale harness. Non-ready serial, so the People column never rendered | **VERIFIED** 1/1 and 31/31 PASS |

### 5.2 Stale register (do not assign as fix work)

**Dashboard SSR, PASS at `489fd91e` (ancestor of `f358ab04`).** P-13-01 recorded `DashboardServiceCasesTest` **6/6 PASS** @ `b69ee268`. P-13-04 re-recorded those six methods **PASS** @ `489fd91e`, and `DashboardClassificationIndexTest` **PASS** in the same 31/31 run. The only application diff after `489fd91e` is the assignment broadcast in #204. **INFERRED** still passing. **Not re-run on `f358ab04`.**

| CSV line | Test |
| --- | --- |
| 24 | `DashboardClassificationIndexTest::test_lean_load_skips_row_only_relations` |
| 40 | `DashboardServiceCasesTest::test_admin_dashboard_shows_bulk_selection_and_inline_transaction_controls` |
| 41 | `DashboardServiceCasesTest::test_completed_service_cases_always_show_within_sla` |
| 42 | `DashboardServiceCasesTest::test_dashboard_defaults_to_ready_queue_filter_aligned_with_queue` |
| 46 | `DashboardServiceCasesTest::test_dashboard_needs_attention_filter_shows_only_active_cases_with_missing_serial` |
| 48 | `DashboardServiceCasesTest::test_dashboard_shows_service_case_grid_columns` |
| 49 | `DashboardServiceCasesTest::test_dashboard_shows_sla_warning_and_overdue_for_pending_cases` |

**Workspace close/resolve, PASS at `2ca010b4` (ancestor of `f358ab04`).** P-12-37 report: `WorkspaceResolveCloseActionTest` + `WorkspaceActionDialogTest` **40/40 PASS** (`php -n` PHPUnit). Root cause recorded there: placeholder serials hit `ServiceCaseCloseRequirementService` and returned **422**. Fixtures now use verified MFS110 serial `6419897`. No later commit touches `CreatesWorkspaceServiceCaseFixtures` or `ServiceCaseCloseRequirementService`. The canonical CSV was never updated, including the P-13-01 recount. **Not re-run on `f358ab04`.**

CSV lines: **190, 192, 193, 194, 195, 196, 197, 203, 204, 205, 206, 207, 209, 210, 211, 212.**

`WorkspaceRemarkActionTest` (line 202) was not in that 40/40 run. It stays unresolved.

### 5.3 Excluded from WS-10 sharding

| Item | Why excluded |
| --- | --- |
| P-13-02, P-13-03, P-13-04 rows above | Completed |
| P-13-05 | Active. Sole ENVIRONMENT BLOCKED row, CSV line **217**, WS-03 `CaMonthlyReportXlsxPackageValidatorTest::test_validator_flags_minimal_package_missing_excel_required_parts` |
| WS-04 line **159** `OnlineReturnRefundAllocationExecutionTest::test_wallet_line_executes_via_radiumbox_client_with_idempotency` | OPM / wallet execution. Protected |
| WS-11 lines **226** and **227** `EInvoiceCatalogUqcReadinessTest` `test_p186_*` and `test_p187_*` | Not WS-10. Adjacent to the statutory cluster; do not edit from a WS-10 agent |
| Lines **135** and **136** `RefundExecutionReferenceGuardTest` and `RefundStatutoryAdjustmentTest` | Refund execution and credit-note money paths. Wallet-adjacent. Not proposed |
| Wallet branch `feature/p18-33-unified-wallet-display` @ `d9481615` | Protected dirty checkout |

### 5.4 Unresolved remainder (66)

Latest failure text is the P-12-32 log @ `27b03d19`, and the test file is unchanged through `f358ab04`, except the three rows in the next paragraph. That log is **not** a re-run on current `main`. Suspected causes in section 6 are **INFERRED** from that log plus unchanged files.

Changed after `27b03d19` with **no** later pass record (**UNKNOWN** whether they still fail):

| CSV line | Test | Later commit |
| --- | --- | --- |
| 2 | `AdministrationHomeTest::test_admin_can_access_administration_home` | `ae25c15f` (#184 sidebar) |
| 3 | `AdministrationHomeTest::test_administration_workspace_tabs_link_to_existing_routes` | same |
| 4 | `AgentDashboardOwnershipTest::test_agent_completed_classification_remains_available_without_navigation_tab` | `b3eb96c6` (P-12-35). P-12-32 message: `4` identical to `2` |

These three are left unassigned.

---

## 6. Proposed independent clusters

Six clusters. Each uses disjoint test files and disjoint likely ownership. None is owned by an active agent. Each is safe to run in its own worktree off `origin/main` `f358ab04`, in parallel, if it does not edit the shared CSV, the ledger, or wallet files.

Do not split a cluster across agents. Several methods in one cluster have different assertion text; the shared file/service boundary is what makes them one owner.

### P-13-08 — Statutory issuance residuals

| | |
| --- | --- |
| Rows | **7** unresolved WS-10 |
| Evidence level | **INFERRED** from P-12-32 @ `27b03d19`. Files unchanged since. **UNKNOWN** whether one policy change explains all seven |
| Risk | **High** (invoices, GST, customer statutory data) |
| Parallel worktree | **Yes**, as the only statutory owner |

| CSV line | Method | P-12-32 signature |
| --- | --- | --- |
| 20 | `Customer360StatutoryInvoiceActionsTest::test_customer_360_shows_failed_when_b2b_statutory_data_is_incomplete` | missing `Pending — Statutory data incomplete` |
| 164 | `EInvoiceIssuancePathTest::test_commerce_b2b_service_is_blocked_in_phase_a_and_queued_in_phase_b` | expected `issuance_policy_service_excluded` |
| 165 | `IrnInputSnapshotTest::test_commerce_ingest_preserves_valid_uqc_and_rejects_invalid` | `null` vs `NOS` |
| 166 | `IrnInputSnapshotTest::test_commerce_uqc_and_structured_billing_are_snapshotted_at_mint` | expected `NOS` |
| 167 | `RdServiceNetPhase1CleanTest::test_invalid_gstin_and_missing_statutory_data_fail_closed` | invalid GSTIN did not refuse mint |
| 168 | `ServiceGstSplitIssuanceTest::test_b2b_split_follows_seller_state_and_place_of_supply_not_billing_state` | expected GSTIN `27AAICP1128M1Z7` |
| 169 | `ServiceStatutoryInvoiceReconciliationBacklogTest::test_rdservice_net_ra_prefix_orders_are_reconciled` | count `0` identical to `1` |

**Likely ownership:** `app/Services/StatutoryInvoice/**`, UQC snapshot / issuance policy, GST split, RA reconciliation command, the C360 statutory-actions presenter used by line 20. **Not** the C360 drawer shell (P-13-09). **Not** WS-11 `EInvoiceCatalogUqcReadinessTest` (lines 226–227); read them only as a conflict check.

**Focused regression:** the seven methods, then the full classes listed above. **Not** the full suite.

**Conflicts:** wallet refund credit notes (lines 135–136), WS-04 line 159, WS-11 UQC tests. Do not edit those. If a fix requires a UQC owner decision, stop.

### P-13-09 — Customer 360 drawer and support-card markup

| | |
| --- | --- |
| Rows | **9** |
| Evidence level | **INFERRED** HTML/assert failures @ `27b03d19`. Line 22 is **UNKNOWN** (HTTP 500, may be a sync error rather than copy). Line 163 is **UNKNOWN** (`ServiceStatutoryInvoicePermanentFailureException` during status sync) |
| Risk | **Normal** (Customer 360 rendering). Line 163 must not become a statutory-policy edit |
| Parallel worktree | **Yes** |

| CSV line | Method | P-12-32 signature |
| --- | --- | --- |
| 16 | `BonvoiceWebhookTest::test_customer_360_health_card_shows_latest_call_status_and_relative_time` | missing `Last Call` |
| 17 | `BonvoiceWebhookTest::test_customer_360_timeline_tab_renders_ivr_call_event` | missing `bi-telephone` |
| 21 | `Customer360RadiumBoxSyncTest::test_customer_360_shows_last_synced_freshness_when_serial_exists` | missing `bi-check-circle` |
| 22 | `Customer360RadiumBoxSyncTest::test_customer_360_shows_synchronization_history` | HTTP 500 expected 200 |
| 23 | `Customer360RadiumBoxSyncTest::test_manual_radiumbox_sync_enriches_order_and_returns_refreshed_drawer` | missing `bi-check-circle` |
| 82 | `InquiryLifecyclePhase1Test::test_customer_360_shows_case_and_enquiry_type_for_inquiry` | C360 cockpit HTML mismatch |
| 162 | `ServiceCaseStatusSupportAppointmentSyncTest::test_closed_incident_does_not_expose_active_scheduled_support_card` | C360 cockpit HTML mismatch |
| 163 | `ServiceCaseStatusSupportAppointmentSyncTest::test_closed_incident_hides_scheduled_support_card_after_status_sync` | `ServiceStatutoryInvoicePermanentFailureException` |
| 175 | `SupportAppointmentBookingTest::test_customer_360_hides_support_appointments_section_when_none_exist` | C360 cockpit HTML mismatch |

**Likely ownership:** Customer 360 drawer/support-card Blade, Bonvoice health-card partials, RadiumBox sync drawer refresh, support-appointment visibility. **Not** statutory mint services. If line 163 reproduces only inside statutory issuance, stop and leave it for P-13-08.

**Focused regression:** the three feature files `BonvoiceWebhookTest`, `Customer360RadiumBoxSyncTest`, `InquiryLifecyclePhase1Test`, `ServiceCaseStatusSupportAppointmentSyncTest`, `SupportAppointmentBookingTest`.

**Conflicts:** P-13-08 statutory services; P-13-10 timeline card partials. Do not edit Interakt/WhatsApp persistence.

### P-13-10 — Interakt and WhatsApp timeline persistence

| | |
| --- | --- |
| Rows | **6** |
| Evidence level | **INFERRED** @ `27b03d19`. Null timeline rows and size `0` vs `1` look like persistence. Line 185 is an HTML assert on the same WhatsApp file, so it stays here |
| Risk | **Normal** |
| Parallel worktree | **Yes** |

| CSV line | Method | P-12-32 signature |
| --- | --- | --- |
| 87 | `InteraktWebhookTest::test_incoming_webhook_stores_message_and_timeline_event` | size 0 expected 1 |
| 88 | `InteraktWebhookTest::test_official_api_failed_webhook_persists_failure_metadata` | null |
| 89 | `InteraktWebhookTest::test_outgoing_template_send_and_status_webhooks_update_timeline` | null |
| 185 | `WhatsAppConversationFeatureTest::test_drawer_renders_open_in_interakt_link_without_message_text` | missing `unified-timeline-summary-fields` |
| 186 | `WhatsAppConversationFeatureTest::test_webhook_messages_produce_single_runtime_timeline_card` | size 0 expected 1 |
| 187 | `WhatsAppTemplateDispatcherTest::test_timeline_shows_whatsapp_template_sent_event_without_message_body` | null |

**Likely ownership:** Interakt webhook ingest, WhatsApp conversation timeline writer, template dispatcher timeline event. **Not** the C360 drawer shell owned by P-13-09.

**Focused regression:** `InteraktWebhookTest`, `WhatsAppConversationFeatureTest`, `WhatsAppTemplateDispatcherTest`.

**Conflicts:** P-13-09 Bonvoice C360 markup. Do not edit `customer-360-drawer-content` layout.

### P-13-11 — RBP and non-RIN hardware classification

| | |
| --- | --- |
| Rows | **3** |
| Evidence level | **INFERRED** @ `27b03d19`: `true` is `false` on all three. Files unchanged |
| Risk | **Normal** (inventory / fulfilment classification) |
| Parallel worktree | **Yes** |

| CSV line | Method |
| --- | --- |
| 218 | `HardwareFulfilmentPrefixGuardTest::test_rbp_never_opens_hardware_fulfilment_even_with_physical_lines` |
| 224 | `OrderProductOrderTest::test_non_rin_non_config_prefix_orders_are_not_hardware` |
| 225 | `OrderProductOrderTest::test_rbp_is_never_hardware_even_if_config_lists_rbp` |

**Likely ownership:** hardware prefix guard and order hardware-prefix classifier (`Order` product-prefix helpers, fulfilment prefix guard). Unit tests only.

**Focused regression:** those two unit classes in full.

**Conflicts:** none with P-13-08, P-13-09, P-13-10, P-13-12, or P-13-13 if this agent does not change RadiumBox backfill commands or dashboard snapshot eligibility.

### P-13-12 — RadiumBox backfill commands

| | |
| --- | --- |
| Rows | **2** |
| Evidence level | **INFERRED** @ `27b03d19` |
| Risk | **Normal** |
| Parallel worktree | **Yes**, with the eligibility constraint below |

| CSV line | Method | P-12-32 signature |
| --- | --- | --- |
| 127 | `BackfillRadiumBoxOrdersCommandTest::test_order_with_manual_serial_and_missing_device_model_is_skipped_when_device_model_assigned` | output missing `Orders already complete: 1` |
| 128 | `BackfillReadyQueueCommandTest::test_evaluates_eligibility_for_validated_unassigned_case` | unexpected `RadiumBoxOrderEnrichmentJob` |

**Likely ownership:** `BackfillRadiumBoxOrdersCommand`, `BackfillReadyQueueCommand`, and their tests.

**Focused regression:** `tests/Feature/RadiumBox/BackfillRadiumBoxOrdersCommandTest.php` and `BackfillReadyQueueCommandTest.php`.

**Conflicts:** P-12-49 `shouldMirrorInAdminReadyQueue()` and P-13-04 ready-queue eligibility are on `main`. Do not change dashboard snapshot membership rules to make the backfill test pass. If the failure is that eligibility rule, stop and report it.

### P-13-13 — Settings and sidebar label assertions

| | |
| --- | --- |
| Rows | **8** |
| Evidence level | **INFERRED** missing `assertSee` strings @ `27b03d19`. Files unchanged. Could be stale copy or a real nav regression. **UNKNOWN** which, until re-run |
| Risk | **Low** |
| Parallel worktree | **Yes** |

| CSV line | Method | Missing text @ P-12-32 |
| --- | --- | --- |
| 18 | `CashBookPhase1Test::test_cash_book_appears_in_sidebar_for_employee` | `Cash Book` |
| 70 | `HybridRealtimePhase3Test::test_system_settings_page_shows_phase_three_hybrid_features` | `Incoming Calls` |
| 102 | `MonthlyAttendanceMatrixTest::test_workspace_nav_links_leave_and_hides_unimplemented_tabs` | `Calendar` |
| 123 | `PlatformIdentityTest::test_changelog_page_shows_empty_state_when_release_notes_missing` | `Release notes for v4.0.2 are not available.` |
| 176 | `SystemSettingsTest::test_superadmin_can_view_notification_settings` | `System Settings` |
| 177 | `SystemSettingsTest::test_superadmin_can_view_settings` | `Assignment` |
| 182 | `TodoControllerTest::test_layout_exposes_todo_modal_entry_points` | `data-nav-key=` |
| 183 | `TodoControllerTest::test_sidebar_includes_todos_for_agent` | `data-nav-key=` |

**Likely ownership:** sidebar / workspace-nav Blade, system settings page, changelog empty state, Cash Book nav, To-do nav keys. Administration home (lines 2–3) is the same surface but the test file changed in #184 without a later pass record, so it is **not** in this cluster.

**Focused regression:** the six test classes named above.

**Conflicts:** do not edit Customer 360 drawer (P-13-09) or platform health snapshot code (closed in P-13-01).

### Not sharded

The other unresolved WS-10 rows are single methods or pairs with no demonstrated shared cause, or they sit on wallet/assignment code that just changed. Leaving them unassigned avoids fake partitions. Examples: operations support-ownership counts (lines 110–111), waiting-state no-op (184), team-availability query counts (221–222), order identity (115–116), channel ingest hash (213), inventory POS amount delta (91), incoming-email ingest (80–81). Re-run before assigning them.

Proposed clusters cover **7+9+6+3+2+8 = 35** of the 66 unresolved rows. **31** unresolved rows stay unassigned. Lines 2–4 stay unassigned as **UNKNOWN**.

---

## 7. Evidence gaps

| Gap | Effect |
| --- | --- |
| No PHPUnit executed in P-13-07 | Closures rely on prior focused runs. The 66 unresolved rows rely on logs from `27b03d19` |
| 23 stale-register rows not re-run on `f358ab04` | Do not edit the CSV until a focused re-run passes |
| Lines 2–4 changed after the failure log | Outcome **UNKNOWN** |
| P-12-32 `shared_cause` is the test name | Not a root-cause taxonomy |
| `rip` / `release-rip` | Workflow success only |
| Statutory cluster has seven different assertion texts | May be several defects. Kept as one owner so agents do not edit the same services |
| Line 163 may be statutory | Conflict rule is in P-13-09 |

---

## 8. Checks

| Check | Status |
| --- | --- |
| Repository, branch, HEAD, remote, worktrees | **PASSED** |
| `git fetch origin main` and SHA verification | **PASSED** (`f358ab04`) |
| Prompt ledger read from `origin/main` | **PASSED** |
| Ledger ID **RadiumDesk-P-13-07** claimed in this report | **PASSED** |
| Ledger file append | **NOT RUN** |
| Testing policy read | **PASSED** |
| Canonical register and CSV located on `origin/main` | **PASSED** |
| PR #203, #204, #205, #206, #207 ancestry | **PASSED** |
| Active-owner check for proposed clusters | **PASSED** (none at 08:57 IST). P-13-05 local report owns WS-03 only |
| Focused re-run of closed or stale rows on `f358ab04` | **NOT RUN** |
| Full PHPUnit | **NOT RUN** |
| Migrations / database changes | **NOT RUN** |
| Production access | **NOT RUN** |
| Application, test, config, or infrastructure edits | **NOT RUN** |
| Canonical CSV or register edits | **NOT RUN** |
| Commit, push, PR, merge, deploy | **NOT RUN** |

---

## 9. Preservation

No application, test, configuration, infrastructure, database, production, or shared-register changes were made.

Wallet checkout remains `feature/p18-33-unified-wallet-display` @ **`d94816153cd2c89f8175d29a1510b38b4c16df6b`**. Its pre-existing dirty tree was not edited. This report is a new untracked file `.artifacts/P-13-07-REPORT.md` and was not staged.

**NO — Not performed:** fixes, test edits, CSV/register edits, ledger append, full PHPUnit, focused PHPUnit, migrations, production access, wallet WIP edits, commits, pushes, pull requests, merges, deploys.
